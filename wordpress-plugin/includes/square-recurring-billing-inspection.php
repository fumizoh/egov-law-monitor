<?php

/** Read-only reconciliation of new recurring invoices for a stored Square contract. */
class Egov_Law_Monitor_Square_Recurring_Billing_Inspection_Service {
	private $wpdb;
	private $client;
	private $recurring_reader;
	private $review_repository;
	private $candidate_service;

	public function __construct( $wpdb, Egov_Law_Monitor_Square_Api_Client $client, $recurring_reader = null, $review_repository = null, $candidate_service = null ) {
		$this->wpdb              = $wpdb;
		$this->client            = $client;
		$this->recurring_reader  = $recurring_reader instanceof Egov_Law_Monitor_Square_Recurring_Payment_Read_Service ? $recurring_reader : new Egov_Law_Monitor_Square_Recurring_Payment_Read_Service( $wpdb, $client );
		$this->review_repository = $review_repository instanceof Egov_Law_Monitor_Square_Admin_Review_Repository ? $review_repository : new Egov_Law_Monitor_Square_Admin_Review_Repository( $wpdb );
		$this->candidate_service = $candidate_service instanceof Egov_Law_Monitor_Square_Billing_Period_Candidate_Service ? $candidate_service : new Egov_Law_Monitor_Square_Billing_Period_Candidate_Service();
	}

	/** Inspect invoices after the latest approved invoice, without changing local state. */
	public function inspect_contract( $contract_id ) {
		if ( ! is_int( $contract_id ) || $contract_id < 1 ) {
			return new WP_Error( 'square_contract_id_invalid', '契約情報を確認できません。' );
		}
		$scope = $this->client->get_checkout_scope();
		if ( is_wp_error( $scope ) ) {
			return new WP_Error( 'square_configuration_invalid', 'Squareの設定を確認できません。' );
		}
		$table = $this->wpdb->prefix . 'egov_law_subscriptions';
		$this->wpdb->last_error = '';
		$contract = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $contract_id ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を取得できません。' );
		}
		if ( ! is_array( $contract ) ) {
			return new WP_Error( 'square_contract_not_found', '契約情報が見つかりません。' );
		}
		if ( 'standard' !== ( $contract['plan_code'] ?? null ) || ! ctype_digit( (string) ( $contract['user_id'] ?? '' ) ) || (int) $contract['user_id'] < 1 || ! get_userdata( (int) $contract['user_id'] ) ) {
			return $this->result( 'review_required', 'contract_identity_invalid', $contract );
		}
		foreach ( array( 'square_subscription_id', 'square_customer_id', 'square_location_id', 'square_plan_variation_id', 'merchant_id', 'environment' ) as $field ) {
			$local_field = 'square_location_id' === $field ? 'square_location_id' : $field;
			if ( ! isset( $contract[ $local_field ] ) || ! is_string( $contract[ $local_field ] ) || '' === $contract[ $local_field ] ) {
				return $this->result( 'review_required', 'contract_data_missing', $contract );
			}
		}
		if ( $scope['environment'] !== $contract['environment'] || $scope['merchant_id'] !== $contract['merchant_id'] || $scope['location_id'] !== $contract['square_location_id'] || $scope['subscription_plan_variation_id'] !== $contract['square_plan_variation_id'] ) {
			return $this->result( 'review_required', 'configured_scope_mismatch', $contract );
		}

		$reviews = $this->review_repository->get_reviews_for_contract( $contract_id, 200 );
		if ( is_wp_error( $reviews ) ) {
			return new WP_Error( 'square_review_history_unavailable', '承認履歴を確認できません。' );
		}
		$baseline = $this->latest_approved_review( $reviews, $contract );
		if ( null === $baseline ) {
			return $this->result( 'review_required', 'approved_invoice_baseline_missing', $contract );
		}

		$subscription_id = (string) $contract['square_subscription_id'];
		$subscription = $this->client->retrieve_subscription( $subscription_id, true );
		if ( is_wp_error( $subscription ) ) {
			return $this->api_error( $subscription, 'subscription', $contract );
		}
		foreach ( array(
			'id' => $subscription_id,
			'location_id' => (string) $contract['square_location_id'],
			'customer_id' => (string) $contract['square_customer_id'],
			'plan_variation_id' => (string) $contract['square_plan_variation_id'],
		) as $key => $expected ) {
			if ( ! isset( $subscription[ $key ] ) || ! is_string( $subscription[ $key ] ) || ! hash_equals( $expected, $subscription[ $key ] ) ) {
				return $this->result( 'review_required', 'subscription_' . $key . '_mismatch', $contract, $subscription );
			}
		}
		if ( 'ACTIVE' !== $subscription['status'] || ! empty( $subscription['canceled_date'] ) ) {
			return $this->result( 'review_required', 'subscription_status_requires_review', $contract, $subscription );
		}
		if ( empty( $subscription['status_known'] ) || empty( $subscription['actions_retrieved'] ) || ! isset( $subscription['actions'] ) || ! is_array( $subscription['actions'] ) ) {
			return $this->result( 'review_required', 'subscription_state_or_actions_incomplete', $contract, $subscription );
		}
		if ( ! isset( $subscription['invoice_ids'] ) || ! is_array( $subscription['invoice_ids'] ) ) {
			return $this->result( 'review_required', 'invoice_list_unavailable', $contract, $subscription );
		}
		$invoice_ids = $subscription['invoice_ids'];
		if ( empty( $invoice_ids ) ) {
			return $this->result( 'payment_pending', 'invoice_not_available', $contract, $subscription, null, $baseline, null, null, array(), null, 'awaiting_payment' );
		}
		if ( count( array_unique( $invoice_ids ) ) !== count( $invoice_ids ) ) {
			return $this->result( 'review_required', 'invoice_id_duplicate', $contract, $subscription );
		}
		$baseline_index = array_search( $baseline['square_invoice_id'], $invoice_ids, true );
		if ( false === $baseline_index ) {
			return $this->result( 'review_required', 'approved_invoice_not_in_subscription_list', $contract, $subscription, null, $baseline );
		}

		$variation = $this->client->retrieve_subscription_plan_variation( (string) $contract['square_plan_variation_id'] );
		if ( is_wp_error( $variation ) ) {
			return $this->api_error( $variation, 'variation', $contract, $subscription );
		}
		$price = $this->client->get_standard_plan_price();
		if ( ! isset( $variation['id'] ) || ! hash_equals( (string) $contract['square_plan_variation_id'], (string) $variation['id'] ) || empty( $variation['phases_available'] ) || empty( $variation['single_monthly_phase'] ) || ! empty( $variation['phases'][0]['periods'] ) || ! isset( $variation['phases'][0]['recurring_price_money'] ) || ! $this->money_is( $variation['phases'][0]['recurring_price_money'], $price ) ) {
			return $this->result( 'review_required', 'variation_phase_or_price_unconfirmed', $contract, $subscription, $variation );
		}

		$events = $this->client->list_subscription_events( $subscription_id, 500 );
		if ( is_wp_error( $events ) ) {
			return $this->api_error( $events, 'events', $contract, $subscription, $variation );
		}
		$schedule = $this->evaluate_schedule( $subscription, $events );
		if ( 'none' !== $schedule['state'] ) {
			return $this->result( 'review_required', $schedule['reason'], $contract, $subscription, $variation, null, $schedule, $events );
		}

		$new_invoice_ids = array_reverse( array_slice( $invoice_ids, 0, $baseline_index ) );
		$invoice_results = array();
		$latest_read = null;
		if ( ! empty( $new_invoice_ids ) && $new_invoice_ids[ count( $new_invoice_ids ) - 1 ] === $invoice_ids[0] ) {
			$latest_read = $this->recurring_reader->inspect_contract( $contract_id );
			if ( is_wp_error( $latest_read ) ) {
				return new WP_Error( 'square_recurring_payment_read_unavailable', '継続決済情報を確認できません。' );
			}
			if ( 'square_api_error' === ( $latest_read['status'] ?? null ) ) {
				return $this->result( 'square_api_error', 'latest_invoice_cross_check_failed', $contract, $subscription, $variation, $baseline, $schedule, $events );
			}
		}
		foreach ( $new_invoice_ids as $invoice_id ) {
			$invoice_result = $this->inspect_invoice( $invoice_id, $subscription, $contract, $price );
			if ( is_wp_error( $invoice_result ) ) {
				return $this->api_error( $invoice_result, 'invoice_payment', $contract, $subscription, $variation, $new_invoice_ids, count( $invoice_results ), $invoice_results, $events, $baseline, $schedule, $baseline_index );
			}
			$invoice_results[] = $invoice_result;
		}

		$all_payments_confirmed = ! empty( $invoice_results );
		foreach ( $invoice_results as $invoice_result ) {
			if ( 'confirmed' !== ( $invoice_result['payment_evidence'] ?? null ) ) {
				$all_payments_confirmed = false;
			}
		}
		if ( is_array( $latest_read ) && ! empty( $latest_read['invoice']['id'] ) && $latest_read['invoice']['id'] === $invoice_ids[0] ) {
			// The established M-2A reader is an independent cross-check; it never decides the period.
			$latest_index = count( $invoice_results ) - 1;
			if ( $latest_index >= 0 && $invoice_results[ $latest_index ]['invoice']['id'] === $invoice_ids[0] && 'confirmed' === $invoice_results[ $latest_index ]['payment_evidence'] && empty( $latest_read['payment_evidence']['confirmed'] ) ) {
				$invoice_results[ $latest_index ]['status'] = 'review_required';
				$invoice_results[ $latest_index ]['reason_code'] = 'latest_invoice_cross_check_not_confirmed';
				$invoice_results[ $latest_index ]['payment_evidence'] = 'not_confirmed';
				$all_payments_confirmed = false;
			}
		}
		$candidate = null;
		if ( 1 === count( $invoice_results ) && 'confirmed' === $invoice_results[0]['payment_evidence'] ) {
			$candidate = $this->calculate_candidate( $subscription, $variation, $schedule, $baseline );
		}
	$status = $all_payments_confirmed ? ( 1 === count( $invoice_results ) ? 'payment_confirmed' : 'review_required' ) : ( empty( $invoice_results ) ? 'payment_pending' : ( 1 === count( $invoice_results ) && in_array( $invoice_results[0]['status'], array( 'payment_pending', 'payment_failed' ), true ) ? $invoice_results[0]['status'] : 'review_required' ) );
	$reason = 'payment_confirmed' === $status ? ( is_array( $candidate ) && 'candidate' === $candidate['status'] ? 'payment_verified_period_candidate_available' : ( $candidate['reason_code'] ?? 'payment_verified_period_review_required' ) ) : ( empty( $invoice_results ) ? 'no_unapproved_invoice' : ( 1 < count( $invoice_results ) ? ( $all_payments_confirmed ? 'multiple_unapproved_invoices_verified_individually' : 'multiple_unapproved_invoices' ) : ( $invoice_results[0]['reason_code'] ?? 'invoice_review_required' ) ) );
		$review_status = 'payment_pending' === $status || empty( $invoice_results ) ? 'awaiting_payment' : 'required';
		return $this->result( $status, $reason, $contract, $subscription, $variation, $baseline, $schedule, $events, $invoice_results, $candidate, $review_status, $baseline_index );
	}

	private function latest_approved_review( array $reviews, array $contract ) {
		foreach ( $reviews as $review ) {
			if ( ! is_array( $review ) || ! in_array( $review['action_type'] ?? null, array( 'approve', 'correct' ), true ) || empty( $review['square_invoice_id'] ) || empty( $review['approved_period_start'] ) || empty( $review['approved_period_end'] ) ) {
				continue;
			}
			if ( (int) ( $review['contract_id'] ?? 0 ) !== (int) $contract['id'] || (int) ( $review['user_id'] ?? 0 ) !== (int) $contract['user_id'] || ( $review['environment'] ?? null ) !== $contract['environment'] || ( $review['merchant_id'] ?? null ) !== $contract['merchant_id'] ) {
				return null;
			}
			return $review;
		}
		return null;
	}

	private function evaluate_schedule( array $subscription, $events ) {
		if ( ! is_array( $events ) || empty( $events['complete'] ) || ! is_array( $events['events'] ?? null ) ) {
			return array( 'state' => 'unknown', 'reason' => 'subscription_events_incomplete' );
		}
		if ( ! empty( $subscription['actions'] ) ) {
			return array( 'state' => 'changed', 'reason' => 'subscription_actions_pending' );
		}
		$starts = 0;
		foreach ( $events['events'] as $event ) {
			$type = $event['subscription_event_type'] ?? null;
			if ( empty( $event['event_type_known'] ) || ! is_string( $type ) ) {
				return array( 'state' => 'unknown', 'reason' => 'subscription_event_type_unknown' );
			}
			if ( 'START_SUBSCRIPTION' === $type ) {
				++$starts;
				if ( ! isset( $subscription['start_date'] ) || $event['effective_date'] !== $subscription['start_date'] ) {
					return array( 'state' => 'changed', 'reason' => 'subscription_start_event_mismatch' );
				}
				continue;
			}
			if ( in_array( $type, array( 'PLAN_CHANGE', 'BILLING_ANCHOR_DATE_CHANGED', 'STOP_SUBSCRIPTION', 'DEACTIVATE_SUBSCRIPTION', 'PAUSE_SUBSCRIPTION', 'RESUME_SUBSCRIPTION' ), true ) ) {
				return array( 'state' => 'changed', 'reason' => 'subscription_history_contains_change' );
			}
		}
		if ( 1 !== $starts || empty( $subscription['timezone'] ) || empty( $subscription['monthly_billing_anchor_date'] ) ) {
			return array( 'state' => 'unknown', 'reason' => 'subscription_schedule_provenance_incomplete' );
		}
		return array( 'state' => 'none', 'reason' => 'actions_and_complete_event_history_show_no_schedule_changes' );
	}

	private function inspect_invoice( $invoice_id, array $subscription, array $contract, array $price ) {
		$invoice = $this->client->get_invoice( $invoice_id );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}
		if ( ! isset( $invoice['id'], $invoice['subscription_id'], $invoice['location_id'], $invoice['order_id'] ) || $invoice['id'] !== $invoice_id || $invoice['subscription_id'] !== $subscription['id'] || $invoice['location_id'] !== $contract['square_location_id'] ) {
			return array( 'status' => 'review_required', 'reason_code' => 'invoice_identity_mismatch', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ) );
		}
		$invoice_table = $this->wpdb->prefix . 'egov_law_subscription_invoices';
		$this->wpdb->last_error = '';
		$local_rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE subscription_id = %d AND user_id = %d AND environment = %s AND merchant_id = %s AND square_invoice_id = %s", (int) $contract['id'], (int) $contract['user_id'], $contract['environment'], $contract['merchant_id'], $invoice_id ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error || ! is_array( $local_rows ) || count( $local_rows ) > 1 ) {
			return array( 'status' => 'review_required', 'reason_code' => 'local_invoice_lookup_conflict', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ) );
		}
		if ( 'invoice_failed' === ( $invoice['payment_state'] ?? '' ) ) {
			return array( 'status' => 'payment_failed', 'reason_code' => 'square_invoice_failed', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => null );
		}
		if ( in_array( $invoice['payment_state'] ?? '', array( 'planned', 'scheduled', 'unpaid', 'processing' ), true ) ) {
			return array( 'status' => 'payment_pending', 'reason_code' => 'invoice_not_paid', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => null );
		}
		if ( 'paid' !== ( $invoice['payment_state'] ?? null ) || ! is_array( $invoice['payment_requests'] ?? null ) || 1 !== count( $invoice['payment_requests'] ) || ! $this->money_is( $invoice['requested_amount_money'] ?? null, $price ) || ! $this->money_is( $invoice['completed_amount_money'] ?? null, $price ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'invoice_amount_state_or_payment_request_unconfirmed', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => null );
		}
		$order = $this->client->retrieve_order( $invoice['order_id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( $order['id'] !== $invoice['order_id'] || $order['location_id'] !== $contract['square_location_id'] || 'COMPLETED' !== $order['state'] || ! $this->money_is( $order['total_money'] ?? null, $price ) || ! is_array( $order['tenders'] ?? null ) || 1 !== count( $order['tenders'] ) || empty( $order['tenders'][0]['payment_id'] ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'order_not_single_completed_payment', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => null );
		}
		$payment_id = $order['tenders'][0]['payment_id'];
		$payment = $this->client->get_payment( $payment_id );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		if ( $payment['id'] !== $payment_id || $payment['order_id'] !== $invoice['order_id'] || $payment['location_id'] !== $contract['square_location_id'] || ( isset( $payment['customer_id'] ) && $payment['customer_id'] !== $contract['square_customer_id'] ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'payment_identity_mismatch', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ) );
		}
		if ( ! empty( $local_rows[0]['square_payment_id'] ) && $local_rows[0]['square_payment_id'] !== $payment_id ) {
			return array( 'status' => 'review_required', 'reason_code' => 'stored_payment_id_mismatch', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ) );
		}
		if ( 'FAILED' === $payment['status'] ) {
			return array( 'status' => 'payment_failed', 'reason_code' => 'square_payment_failed', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ) );
		}
		if ( 'COMPLETED' !== $payment['status'] ) {
			return array( 'status' => 'payment_pending', 'reason_code' => 'payment_not_completed', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ) );
		}
		if ( ! $this->money_is( $payment['amount_money'] ?? null, $price ) || ! $this->money_is( $payment['total_money'] ?? null, $price ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'payment_amount_or_currency_mismatch', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ) );
		}
		$refunded_money = $payment['refunded_money'] ?? null;
		if ( ! array_key_exists( 'refund_ids', $payment ) || ! is_array( $payment['refund_ids'] ) || ! is_array( $refunded_money ) || ! isset( $refunded_money['amount'], $refunded_money['currency'] ) || ! is_int( $refunded_money['amount'] ) || $refunded_money['amount'] < 0 || 'JPY' !== $refunded_money['currency'] ) {
			return array( 'status' => 'review_required', 'reason_code' => 'refund_state_unavailable', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ) );
		}
		if ( ! empty( $payment['refund_ids'] ) || 0 !== $refunded_money['amount'] ) {
			$refunds = array();
			foreach ( $payment['refund_ids'] as $refund_id ) {
				$refund = $this->client->get_payment_refund( $refund_id );
				if ( is_wp_error( $refund ) ) {
					return $refund;
				}
				if ( $refund['payment_id'] !== $payment_id || ! $refund['status_known'] ) {
					return array( 'status' => 'review_required', 'reason_code' => 'refund_identity_or_status_unknown', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ) );
				}
				$refunds[] = array( 'id' => $refund['id'], 'status' => $refund['status'] );
			}
			return array( 'status' => 'review_required', 'reason_code' => 'refund_detected', 'payment_evidence' => 'not_confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ), 'refunds' => $refunds );
		}
		return array( 'status' => 'payment_confirmed', 'reason_code' => 'invoice_order_payment_verified', 'payment_evidence' => 'confirmed', 'invoice' => $this->invoice_summary( $invoice ), 'payment' => $this->payment_summary( $payment ) );
	}

	private function calculate_candidate( array $subscription, array $variation, array $schedule, array $baseline ) {
		$start = $this->approved_midnight_date( $baseline['approved_period_start'] ?? null );
		$end = $this->approved_midnight_date( $baseline['approved_period_end'] ?? null );
		$approved_timezone = $baseline['subscription_timezone'] ?? $baseline['timezone'] ?? null;
		$approved_anchor = $baseline['monthly_billing_anchor_date'] ?? null;
		if ( is_string( $approved_anchor ) && preg_match( '/\A(?:[1-9]|[12][0-9]|3[01])\z/', $approved_anchor ) ) {
			$approved_anchor = (int) $approved_anchor;
		}
		if ( null === $start || null === $end || ! is_string( $approved_timezone ) || '' === $approved_timezone || ! is_int( $approved_anchor ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'approved_period_schedule_provenance_missing', 'billing_period' => null, 'automatic_approval_candidate' => false );
		}
		if ( $approved_timezone !== ( $subscription['timezone'] ?? null ) || $approved_anchor !== ( $subscription['monthly_billing_anchor_date'] ?? null ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'approved_period_schedule_mismatch', 'billing_period' => null, 'automatic_approval_candidate' => false );
		}
		$candidate = $this->candidate_service->calculate( array(
			'period_type' => 'renewal',
			'subscription' => array( 'start_date' => $subscription['start_date'] ?? null, 'timezone' => $subscription['timezone'], 'monthly_billing_anchor_date' => $subscription['monthly_billing_anchor_date'], 'plan_variation_id' => $subscription['plan_variation_id'] ),
			'plan_variation' => array( 'id' => $variation['id'], 'cadence' => $variation['phases'][0]['cadence'] ?? null ),
			'phase_change_state' => $schedule['state'],
			'schedule_change_state' => $schedule['state'],
			'anchor_change_state' => $schedule['state'],
			'previous_approved_period' => array( 'approved' => true, 'start_date' => $start, 'end_exclusive_date' => $end, 'timezone' => $approved_timezone, 'monthly_billing_anchor_date' => $approved_anchor ),
		) );
		$candidate['automatic_approval_candidate'] = false;
		return $candidate;
	}

	private function approved_midnight_date( $datetime ) {
		if ( ! is_string( $datetime ) || ! preg_match( '/\A(\d{4}-\d{2}-\d{2}) 00:00:00\z/', $datetime, $match ) ) {
			return null;
		}
		$parts = explode( '-', $match[1] );
		return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ? $match[1] : null;
	}

	private function money_is( $money, $expected ) {
		return is_array( $money ) && isset( $money['amount'], $money['currency'] ) && is_int( $money['amount'] ) && $money['amount'] === $expected['amount'] && is_string( $money['currency'] ) && $money['currency'] === $expected['currency'];
	}

	private function invoice_summary( array $invoice ) {
		return array( 'id' => $invoice['id'] ?? null, 'order_id' => $invoice['order_id'] ?? null, 'status' => $invoice['status'] ?? null, 'payment_state' => $invoice['payment_state'] ?? null, 'amount' => $invoice['requested_amount_money']['amount'] ?? null, 'completed_amount' => $invoice['completed_amount_money']['amount'] ?? null, 'currency' => $invoice['requested_amount_money']['currency'] ?? null, 'version' => $invoice['version'] ?? null );
	}

	private function payment_summary( array $payment ) {
		return array( 'id' => $payment['id'] ?? null, 'status' => $payment['status'] ?? null, 'amount' => $payment['amount_money']['amount'] ?? null, 'total_amount' => $payment['total_money']['amount'] ?? null, 'currency' => $payment['amount_money']['currency'] ?? null );
	}

	private function api_error( WP_Error $error, $stage, array $contract, $subscription = null, $variation = null, $invoice_ids = array(), $checked = 0, $invoices = array(), $events = null, $baseline = null, $schedule = null, $baseline_index = null ) {
		$invalid_codes = array( 'square_resource_not_found', 'square_resource_response_invalid', 'square_subscription_response_invalid', 'square_catalog_variation_response_invalid', 'square_subscription_events_response_invalid', 'square_subscription_events_cursor_invalid', 'square_invoice_response_invalid', 'square_order_response_invalid', 'square_payment_response_invalid', 'square_refund_response_invalid' );
		$status = in_array( $error->get_error_code(), $invalid_codes, true ) ? 'review_required' : 'square_api_error';
		return $this->result( $status, $stage . '_retrieval_failed', $contract, $subscription, $variation, $baseline, $schedule, $events, $invoices, null, 'required', $baseline_index );
	}

	private function result( $status, $reason, array $contract, $subscription = null, $variation = null, $baseline = null, $schedule = null, $events = null, $invoice_results = array(), $candidate = null, $review_status = 'required', $baseline_index = null ) {
		$invoice_ids = is_array( $subscription ) && is_array( $subscription['invoice_ids'] ?? null ) ? $subscription['invoice_ids'] : array();
		$requested_invoice_count = is_int( $baseline_index ) ? $baseline_index : count( $invoice_results );
		$confirmed = ! empty( $invoice_results ) && in_array( $status, array( 'payment_confirmed', 'review_required' ), true ) && count( $invoice_results ) === $requested_invoice_count;
		$has_pending = false;
		$has_failed = false;
		foreach ( $invoice_results as $invoice_result ) {
			if ( 'confirmed' !== ( $invoice_result['payment_evidence'] ?? null ) ) {
				$confirmed = false;
			}
			$has_pending = $has_pending || 'payment_pending' === ( $invoice_result['status'] ?? null );
			$has_failed = $has_failed || 'payment_failed' === ( $invoice_result['status'] ?? null );
		}
		$evidence_status = $confirmed ? 'confirmed' : ( $has_pending && $has_failed ? 'mixed' : ( $has_pending ? 'pending' : ( $has_failed ? 'failed' : 'not_confirmed' ) ) );
		return array(
			'status' => $status,
			'reason_code' => $reason,
			'payment_evidence' => array( 'status' => $evidence_status, 'confirmed' => $confirmed, 'invoice_results' => $invoice_results ),
			'review_status' => $review_status,
			'automatic_approval_candidate' => false,
			'invoice_scope' => array(
				'selection' => 'all_invoice_ids_newer_than_last_approved_invoice',
				'baseline_invoice_id' => is_array( $baseline ) ? ( $baseline['square_invoice_id'] ?? null ) : null,
				'invoice_list_completeness' => is_array( $subscription ) && is_array( $subscription['invoice_ids'] ?? null ) ? 'square_subscription_invoice_ids_field_newest_first' : 'unknown',
				'invoice_total_count' => is_array( $subscription ) && is_array( $subscription['invoice_ids'] ?? null ) ? count( $subscription['invoice_ids'] ) : null,
				'requested_invoice_count' => $requested_invoice_count,
				'checked_invoice_count' => count( $invoice_results ),
				'unchecked_invoice_count' => max( 0, $requested_invoice_count - count( $invoice_results ) ),
				'contract_wide_payment_status_confirmed' => false,
			),
			'contract' => array( 'id' => (int) ( $contract['id'] ?? 0 ), 'user_id' => (int) ( $contract['user_id'] ?? 0 ), 'environment' => $contract['environment'] ?? null, 'merchant_id' => $contract['merchant_id'] ?? null, 'location_id' => $contract['square_location_id'] ?? null, 'customer_id' => $contract['square_customer_id'] ?? null, 'subscription_id' => $contract['square_subscription_id'] ?? null, 'plan_variation_id' => $contract['square_plan_variation_id'] ?? null ),
			'subscription' => is_array( $subscription ) ? array( 'id' => $subscription['id'] ?? null, 'status' => $subscription['status'] ?? null, 'version' => $subscription['version'] ?? null, 'start_date' => $subscription['start_date'] ?? null, 'timezone' => $subscription['timezone'] ?? null, 'monthly_billing_anchor_date' => $subscription['monthly_billing_anchor_date'] ?? null ) : null,
			'variation' => is_array( $variation ) ? array( 'id' => $variation['id'] ?? null, 'phases' => $variation['phases'] ?? array(), 'single_monthly_phase' => $variation['single_monthly_phase'] ?? false ) : null,
			'schedule_review' => is_array( $schedule ) ? $schedule : null,
			'event_scope' => is_array( $events ) ? array( 'complete' => $events['complete'] ?? false, 'reason_code' => $events['reason_code'] ?? null, 'page_count' => $events['page_count'] ?? null, 'retrieved_event_count' => $events['retrieved_event_count'] ?? null, 'coverage_start_date' => $events['coverage_start_date'] ?? null, 'coverage_end_date' => $events['coverage_end_date'] ?? null ) : null,
			'previous_approval' => is_array( $baseline ) ? array( 'review_id' => (int) $baseline['id'], 'invoice_id' => $baseline['square_invoice_id'], 'approved_period_start' => $baseline['approved_period_start'], 'approved_period_end_exclusive' => $baseline['approved_period_end'] ) : null,
			'period_candidate' => is_array( $candidate ) ? $candidate : array( 'status' => 'review_required', 'reason_code' => 'candidate_not_calculated', 'billing_period' => null, 'automatic_approval_candidate' => false ),
			'expected_price' => $this->client->get_standard_plan_price(),
		);
	}
}
