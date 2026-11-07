<?php

/** Read-only initial Checkout/Subscription reconciliation for administrator review. */
class Egov_Law_Monitor_Square_Initial_Billing_Period_Inspection_Service {
	private const MAX_SUBSCRIPTION_CANDIDATES = 20;
	private const MAX_INVOICES_TO_INSPECT = 20;

	private $wpdb;
	private $repository;
	private $client;
	private $initial_payment_reader;
	private $candidate_service;

	public function __construct( $wpdb, Egov_Law_Monitor_Checkout_Repository $repository, Egov_Law_Monitor_Square_Api_Client $client, Egov_Law_Monitor_Square_Initial_Payment_Read_Service $initial_payment_reader, $candidate_service = null ) {
		$this->wpdb = $wpdb;
		$this->repository = $repository;
		$this->client = $client;
		$this->initial_payment_reader = $initial_payment_reader;
		$this->candidate_service = $candidate_service instanceof Egov_Law_Monitor_Square_Billing_Period_Candidate_Service ? $candidate_service : new Egov_Law_Monitor_Square_Billing_Period_Candidate_Service();
	}

	/** Inspect one locally stored Checkout Attempt without modifying WordPress data. */
	public function inspect_attempt( $attempt_id ) {
		if ( ! is_int( $attempt_id ) || $attempt_id < 1 ) {
			return new WP_Error( 'square_initial_period_attempt_id_invalid', '申込情報を確認できません。' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'square_initial_period_inspection_forbidden', '決済情報を確認する権限がありません。' );
		}
		$attempt = $this->repository->get_attempt( $attempt_id );
		if ( is_wp_error( $attempt ) ) {
			return new WP_Error( 'square_initial_period_attempt_database_unavailable', '申込情報を取得できません。' );
		}
		if ( ! is_array( $attempt ) ) {
			return new WP_Error( 'square_initial_period_attempt_not_found', '申込情報が見つかりません。' );
		}
		$scope = $this->client->get_checkout_scope();
		if ( is_wp_error( $scope ) ) {
			return $this->result( 'review_required', 'square_configuration_invalid', $attempt );
		}
		$identity_error = $this->validate_attempt( $attempt, $scope );
		if ( null !== $identity_error ) {
			return $this->result( 'review_required', $identity_error, $attempt );
		}

		$initial = $this->initial_payment_reader->inspect_attempt( $attempt_id );
		if ( is_wp_error( $initial ) ) {
			return new WP_Error( 'square_initial_payment_read_unavailable', '初回決済情報を確認できません。' );
		}
		$payment_evidence = is_array( $initial['payment_evidence'] ?? null ) ? $initial['payment_evidence'] : array();
		if ( empty( $payment_evidence['confirmed'] ) ) {
			$status = 'square_api_error' === ( $initial['status'] ?? null ) ? 'square_api_error' : ( 'payment_pending' === ( $initial['status'] ?? null ) ? 'payment_pending' : 'review_required' );
			return $this->result( $status, $initial['reason_code'] ?? 'initial_payment_not_confirmed', $attempt, $payment_evidence );
		}
		$payment_id = $payment_evidence['payment_id'] ?? null;
		if ( ! is_string( $payment_id ) || '' === $payment_id ) {
			return $this->result( 'review_required', 'initial_payment_id_missing', $attempt, $payment_evidence );
		}

		$order = $this->client->retrieve_order( (string) $attempt['square_order_id'] );
		if ( is_wp_error( $order ) ) {
			return $this->api_failure( 'initial_order_retrieval_failed', $attempt, $payment_evidence );
		}
		$payment = $this->client->get_payment( $payment_id );
		if ( is_wp_error( $payment ) ) {
			return $this->api_failure( 'initial_payment_retrieval_failed', $attempt, $payment_evidence );
		}
		$price = $this->client->get_standard_plan_price();
		if ( ! $this->valid_checkout_payment( $attempt, $scope, $order, $payment, $payment_id, $price ) ) {
			return $this->result( 'review_required', 'initial_order_payment_identity_or_amount_mismatch', $attempt, $payment_evidence, null, null, $order, $payment );
		}
		if ( 'unknown' === ( $payment_evidence['refund_status'] ?? 'unknown' ) || 'detected' === ( $payment_evidence['refund_status'] ?? 'unknown' ) ) {
			return $this->result( 'review_required', 'initial_payment_refund_requires_review', $attempt, $payment_evidence, null, null, $order, $payment );
		}

		$contracts = $this->find_attempt_contracts( $attempt );
		if ( is_wp_error( $contracts ) ) {
			return new WP_Error( 'square_initial_period_contract_lookup_unavailable', '契約情報を確認できません。' );
		}
		if ( 1 < count( $contracts ) ) {
			return $this->result( 'review_required', 'attempt_contract_not_unique', $attempt, $payment_evidence, array( 'status' => 'ambiguous', 'candidate_count' => count( $contracts ) ), null, $order, $payment );
		}

		$customer_id = $payment['customer_id'] ?? null;
		if ( ! is_string( $customer_id ) || '' === $customer_id ) {
			return $this->result( 'review_required', 'initial_payment_customer_id_unavailable', $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'reason_code' => 'customer_id_unavailable' ), null, $order, $payment );
		}

		$contract = empty( $contracts ) ? null : $contracts[0];
		if ( null !== $contract ) {
			$contract_error = $this->validate_contract( $contract, $attempt, $scope, $customer_id );
			if ( null !== $contract_error ) {
				return $this->result( 'review_required', $contract_error, $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'reason_code' => $contract_error ), null, $order, $payment );
			}
			$subscription_ids = array( $contract['square_subscription_id'] );
			$subscription_source = 'local_contract_attempt_uuid';
		} else {
			$search = $this->client->search_subscriptions_by_customer_location( $customer_id, $scope['location_id'], self::MAX_SUBSCRIPTION_CANDIDATES );
			if ( is_wp_error( $search ) ) {
				return $this->api_failure( 'subscription_search_failed', $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'reason_code' => 'subscription_search_failed' ), $order, $payment );
			}
			if ( empty( $search['complete'] ) ) {
				return $this->result( 'review_required', 'subscription_search_incomplete', $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'candidate_count' => $search['candidate_count'], 'reason_code' => 'subscription_search_incomplete' ), null, $order, $payment );
			}
			$subscription_ids = array();
			foreach ( $search['candidates'] as $candidate ) {
				$subscription_ids[] = $candidate['id'];
			}
			$subscription_source = 'square_customer_location_candidates';
		}

		$linked = array();
		$inspection_cache = array();
		$invoice_inspections = 0;
		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = $this->client->retrieve_subscription( $subscription_id, true );
			if ( is_wp_error( $subscription ) ) {
				return $this->api_failure( 'subscription_retrieval_failed', $attempt, $payment_evidence, null, $order, $payment );
			}
			$subscription_error = $this->validate_subscription_identity( $subscription, $subscription_id, $attempt, $scope, $customer_id );
			if ( null !== $subscription_error ) {
				if ( null !== $contract ) {
					return $this->result( 'review_required', $subscription_error, $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'reason_code' => $subscription_error ), null, $order, $payment );
				}
				continue;
			}
			$inspection_cache[ $subscription_id ] = array( 'subscription' => $subscription );
			$invoice_ids = $subscription['invoice_ids'] ?? null;
			if ( ! is_array( $invoice_ids ) || empty( $invoice_ids ) || count( $invoice_ids ) > 50 ) {
				continue;
			}
			$seen_invoice_ids = array();
			foreach ( $invoice_ids as $invoice_id ) {
				if ( $invoice_inspections >= self::MAX_INVOICES_TO_INSPECT ) {
					return $this->result( 'review_required', 'subscription_invoice_inspection_limit_reached', $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'candidate_count' => count( $subscription_ids ), 'checked_invoice_count' => $invoice_inspections, 'reason_code' => 'subscription_invoice_inspection_limit_reached' ), null, $order, $payment );
				}
				if ( ! is_string( $invoice_id ) || '' === $invoice_id || isset( $seen_invoice_ids[ $invoice_id ] ) ) {
					return $this->result( 'review_required', 'subscription_invoice_identifiers_invalid', $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'reason_code' => 'subscription_invoice_identifiers_invalid' ), null, $order, $payment );
				}
				$seen_invoice_ids[ $invoice_id ] = true;
				++$invoice_inspections;
				$invoice = $this->client->get_invoice( $invoice_id );
				if ( is_wp_error( $invoice ) ) {
					return $this->api_failure( 'subscription_invoice_retrieval_failed', $attempt, $payment_evidence, null, $order, $payment );
				}
				if ( ! isset( $invoice['id'], $invoice['subscription_id'], $invoice['location_id'], $invoice['order_id'] ) || $invoice['id'] !== $invoice_id || $invoice['subscription_id'] !== $subscription_id || $invoice['location_id'] !== $scope['location_id'] ) {
					return $this->result( 'review_required', 'initial_invoice_identity_mismatch', $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'reason_code' => 'initial_invoice_identity_mismatch' ), null, $order, $payment );
				}
				$invoice_order = $this->client->retrieve_order( $invoice['order_id'] );
				if ( is_wp_error( $invoice_order ) ) {
					return $this->api_failure( 'initial_invoice_order_retrieval_failed', $attempt, $payment_evidence, null, $order, $payment );
				}
				if ( ! isset( $invoice_order['id'], $invoice_order['location_id'] ) || $invoice_order['id'] !== $invoice['order_id'] || $invoice_order['location_id'] !== $scope['location_id'] ) {
					return $this->result( 'review_required', 'initial_invoice_order_identity_mismatch', $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'reason_code' => 'initial_invoice_order_identity_mismatch' ), null, $order, $payment );
				}
				if ( isset( $invoice_order['reference_id'] ) && is_string( $invoice_order['reference_id'] ) && hash_equals( (string) $attempt['attempt_uuid'], $invoice_order['reference_id'] ) ) {
					$linked[] = array( 'subscription_id' => $subscription_id, 'subscription' => $subscription, 'invoice' => $invoice, 'invoice_order' => $invoice_order );
				}
			}
		}

		if ( null !== $contract && empty( $inspection_cache[ $contract['square_subscription_id'] ] ) ) {
			return $this->result( 'review_required', 'subscription_invoice_relationship_unavailable', $attempt, $payment_evidence, array( 'status' => 'confirmed_local_contract', 'subscription_id' => $contract['square_subscription_id'], 'reason_code' => 'subscription_invoice_relationship_unavailable' ), null, $order, $payment );
		}
		if ( 1 !== count( $linked ) ) {
			$linked_subscription_ids = array_unique( array_column( $linked, 'subscription_id' ) );
			$reason = empty( $linked ) ? 'checkout_attempt_subscription_identifier_chain_unproven' : ( 1 === count( $linked_subscription_ids ) ? 'multiple_initial_subscription_invoices_or_candidates' : 'multiple_subscription_candidates_match_attempt' );
			return $this->result( 'review_required', $reason, $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'candidate_count' => count( $subscription_ids ), 'matched_invoice_count' => count( $linked ), 'source' => $subscription_source, 'reason_code' => $reason ), null, $order, $payment );
		}
		$match = $linked[0];
		if ( null !== $contract && ! hash_equals( (string) $contract['square_subscription_id'], $match['subscription_id'] ) ) {
			return $this->result( 'review_required', 'local_contract_subscription_mismatch', $attempt, $payment_evidence, array( 'status' => 'unconfirmed', 'reason_code' => 'local_contract_subscription_mismatch' ), null, $order, $payment );
		}
		$subscription = $match['subscription'];
		$invoice = $match['invoice'];
		$invoice_order = $match['invoice_order'];
		$link_method = null === $contract ? 'attempt_reference_chain' : 'local_contract_and_attempt_reference_chain';
		if ( count( $subscription['invoice_ids'] ) !== 1 ) {
			return $this->result( 'review_required', 'multiple_subscription_invoices_require_review', $attempt, $payment_evidence, array( 'status' => 'confirmed', 'method' => $link_method, 'subscription_id' => $subscription['id'], 'source' => $subscription_source ), null, $order, $payment, $invoice, array( 'verified' => false, 'reason_code' => 'multiple_subscription_invoices_require_review' ) );
		}
		$invoice_payment = $this->verify_initial_invoice_payment( $invoice, $invoice_order, $attempt, $payment, $payment_id, $price );

		$conditions = $this->verify_billing_conditions( $subscription, $attempt, $scope );
		if ( is_wp_error( $conditions ) ) {
			return $this->api_failure( $conditions->get_error_code(), $attempt, $payment_evidence, array( 'status' => 'confirmed', 'method' => $link_method, 'subscription_id' => $subscription['id'], 'source' => $subscription_source ), $order, $payment, $invoice, $invoice_payment );
		}
		if ( 'review_required' === $conditions['status'] ) {
			return $this->result( 'review_required', $conditions['reason_code'], $attempt, $payment_evidence, array( 'status' => 'confirmed', 'method' => $link_method, 'subscription_id' => $subscription['id'], 'source' => $subscription_source, 'candidate_count' => count( $subscription_ids ) ), $conditions, $order, $payment, $invoice, $invoice_payment );
		}

		$candidate = $this->candidate_service->calculate( array(
			'period_type' => 'initial',
			'subscription' => array( 'start_date' => $subscription['start_date'] ?? null, 'timezone' => $subscription['timezone'] ?? null, 'monthly_billing_anchor_date' => $subscription['monthly_billing_anchor_date'] ?? null, 'plan_variation_id' => $subscription['plan_variation_id'] ?? null ),
			'plan_variation' => array( 'id' => $conditions['variation']['id'], 'cadence' => $conditions['variation']['phases'][0]['cadence'] ?? null ),
			'phase_change_state' => 'none',
			'schedule_change_state' => 'none',
			'anchor_change_state' => 'none',
		) );
		if ( ! is_array( $invoice_payment ) || empty( $invoice_payment['verified'] ) ) {
			return $this->result( 'review_required', $invoice_payment['reason_code'] ?? 'initial_invoice_payment_unverified', $attempt, $payment_evidence, array( 'status' => 'confirmed', 'method' => $link_method, 'subscription_id' => $subscription['id'], 'source' => $subscription_source ), $conditions, $order, $payment, $invoice, $invoice_payment, $candidate );
		}
		if ( 'candidate' !== ( $candidate['status'] ?? null ) ) {
			return $this->result( 'review_required', $candidate['reason_code'] ?? 'billing_period_candidate_unavailable', $attempt, $payment_evidence, array( 'status' => 'confirmed', 'method' => $link_method, 'subscription_id' => $subscription['id'], 'source' => $subscription_source ), $conditions, $order, $payment, $invoice, $invoice_payment, $candidate );
		}
		return $this->result( 'review_required', 'payment_and_period_candidate_require_administrator_approval', $attempt, $payment_evidence, array( 'status' => 'confirmed', 'method' => $link_method, 'subscription_id' => $subscription['id'], 'source' => $subscription_source, 'candidate_count' => count( $subscription_ids ) ), $conditions, $order, $payment, $invoice, $invoice_payment, $candidate );
	}

	private function validate_attempt( array $attempt, array $scope ) {
		foreach ( array( 'id', 'user_id', 'attempt_uuid', 'square_order_id', 'environment', 'merchant_id', 'square_location_id', 'subscription_plan_variation_id', 'status' ) as $field ) {
			if ( ! isset( $attempt[ $field ] ) || ! is_scalar( $attempt[ $field ] ) || '' === (string) $attempt[ $field ] ) {
				return 'attempt_identity_missing';
			}
		}
		if ( ! preg_match( '/\\A[1-9][0-9]*\\z/', (string) $attempt['user_id'] ) || ! get_userdata( (int) $attempt['user_id'] ) || ! is_string( $attempt['attempt_uuid'] ) || ! preg_match( '/\\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\\z/i', $attempt['attempt_uuid'] ) ) {
			return 'attempt_identity_invalid';
		}
		if ( ! in_array( $attempt['status'], array( 'pending', 'reconciliation_required' ), true ) ) {
			return 'attempt_state_not_inspectable';
		}
		foreach ( array( 'environment' => 'environment', 'merchant_id' => 'merchant_id', 'square_location_id' => 'location_id', 'subscription_plan_variation_id' => 'subscription_plan_variation_id' ) as $attempt_field => $scope_field ) {
			if ( ! isset( $scope[ $scope_field ] ) || ! is_string( $scope[ $scope_field ] ) || ! is_string( $attempt[ $attempt_field ] ) || ! hash_equals( $scope[ $scope_field ], $attempt[ $attempt_field ] ) ) {
				return 'attempt_square_scope_mismatch';
			}
		}
		return null;
	}

	private function find_attempt_contracts( array $attempt ) {
		$table = $this->wpdb->prefix . 'egov_law_subscriptions';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE checkout_attempt_uuid = %s", $attempt['attempt_uuid'] ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_initial_period_contract_lookup_failed' );
		}
		return $rows;
	}

	private function validate_contract( array $contract, array $attempt, array $scope, $customer_id ) {
		$expected = array(
			'user_id' => (string) $attempt['user_id'],
			'environment' => $scope['environment'],
			'merchant_id' => $scope['merchant_id'],
			'square_location_id' => $scope['location_id'],
			'square_plan_variation_id' => $scope['subscription_plan_variation_id'],
			'checkout_attempt_uuid' => $attempt['attempt_uuid'],
			'square_customer_id' => $customer_id,
		);
		if ( 'standard' !== ( $contract['plan_code'] ?? null ) ) {
			return 'attempt_contract_plan_mismatch';
		}
		foreach ( $expected as $field => $value ) {
			if ( ! isset( $contract[ $field ] ) || ! is_scalar( $contract[ $field ] ) || ! hash_equals( (string) $value, (string) $contract[ $field ] ) ) {
				return 'attempt_contract_identity_mismatch';
			}
		}
		if ( empty( $contract['square_subscription_id'] ) || ! is_string( $contract['square_subscription_id'] ) ) {
			return 'attempt_contract_subscription_id_missing';
		}
		return null;
	}

	private function validate_subscription_identity( array $subscription, $subscription_id, array $attempt, array $scope, $customer_id ) {
		$expected = array(
			'id' => $subscription_id,
			'location_id' => $scope['location_id'],
			'customer_id' => $customer_id,
			'plan_variation_id' => $scope['subscription_plan_variation_id'],
		);
		foreach ( $expected as $field => $value ) {
			if ( ! isset( $subscription[ $field ] ) || ! is_string( $subscription[ $field ] ) || ! hash_equals( $value, $subscription[ $field ] ) ) {
				return 'subscription_' . $field . '_mismatch';
			}
		}
		if ( ! isset( $attempt['subscription_plan_variation_id'] ) || ! hash_equals( (string) $attempt['subscription_plan_variation_id'], $subscription['plan_variation_id'] ) ) {
			return 'attempt_subscription_plan_mismatch';
		}
		return null;
	}

	private function valid_checkout_payment( array $attempt, array $scope, $order, $payment, $payment_id, array $price ) {
		return is_array( $order ) && isset( $order['id'], $order['reference_id'], $order['location_id'], $order['tenders'], $order['total_money']['amount'], $order['total_money']['currency'] )
			&& $order['id'] === $attempt['square_order_id'] && $order['reference_id'] === $attempt['attempt_uuid'] && $order['location_id'] === $scope['location_id']
			&& is_array( $order['tenders'] ) && 1 === count( $order['tenders'] ) && isset( $order['tenders'][0]['payment_id'] ) && $order['tenders'][0]['payment_id'] === $payment_id
			&& $this->money_matches( array( 'amount' => $order['total_money']['amount'], 'currency' => $order['total_money']['currency'] ), $price )
			&& is_array( $payment ) && isset( $payment['id'], $payment['order_id'], $payment['location_id'], $payment['status'], $payment['amount_money'], $payment['total_money'] )
			&& $payment['id'] === $payment_id && $payment['order_id'] === $attempt['square_order_id'] && $payment['location_id'] === $scope['location_id'] && 'COMPLETED' === $payment['status']
			&& $this->money_matches( $payment['amount_money'], $price ) && $this->money_matches( $payment['total_money'], $price );
	}

	private function verify_billing_conditions( array $subscription, array $attempt, array $scope ) {
		if ( empty( $subscription['status_known'] ) || 'ACTIVE' !== ( $subscription['status'] ?? null ) || ! empty( $subscription['canceled_date'] ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'subscription_status_requires_review' );
		}
		if ( empty( $subscription['actions_retrieved'] ) || ! is_array( $subscription['actions'] ?? null ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'subscription_actions_unavailable' );
		}
		if ( ! empty( $subscription['actions'] ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'subscription_actions_pending' );
		}
		$variation = $this->client->retrieve_subscription_plan_variation( $subscription['plan_variation_id'] );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}
		$price = $this->client->get_standard_plan_price();
		if ( ! isset( $variation['id'] ) || $variation['id'] !== $subscription['plan_variation_id'] || empty( $variation['phases_available'] ) || empty( $variation['single_monthly_phase'] ) || ! empty( $variation['phases'][0]['periods'] ) || ! $this->money_matches( $variation['phases'][0]['recurring_price_money'] ?? null, $price ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'subscription_plan_phase_or_price_unconfirmed' );
		}
		if ( empty( $subscription['timezone'] ) || ! is_string( $subscription['timezone'] ) || ! isset( $subscription['monthly_billing_anchor_date'] ) || ! is_int( $subscription['monthly_billing_anchor_date'] ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'subscription_timezone_or_anchor_missing' );
		}
		$events = $this->client->list_subscription_events( $subscription['id'], 500 );
		if ( is_wp_error( $events ) ) {
			return $events;
		}
		if ( empty( $events['complete'] ) || ! is_array( $events['events'] ?? null ) ) {
			return array( 'status' => 'review_required', 'reason_code' => 'subscription_events_incomplete', 'events' => $events );
		}
		$starts = 0;
		foreach ( $events['events'] as $event ) {
			if ( empty( $event['event_type_known'] ) || ! is_string( $event['subscription_event_type'] ?? null ) ) {
				return array( 'status' => 'review_required', 'reason_code' => 'subscription_event_type_unknown', 'events' => $events );
			}
			if ( 'START_SUBSCRIPTION' === $event['subscription_event_type'] ) {
				++$starts;
				if ( ( $subscription['start_date'] ?? null ) !== ( $event['effective_date'] ?? null ) ) {
					return array( 'status' => 'review_required', 'reason_code' => 'subscription_start_event_mismatch', 'events' => $events );
				}
			} elseif ( in_array( $event['subscription_event_type'], array( 'PLAN_CHANGE', 'BILLING_ANCHOR_DATE_CHANGED', 'STOP_SUBSCRIPTION', 'DEACTIVATE_SUBSCRIPTION', 'PAUSE_SUBSCRIPTION', 'RESUME_SUBSCRIPTION' ), true ) ) {
				return array( 'status' => 'review_required', 'reason_code' => 'subscription_history_contains_change', 'events' => $events );
			}
		}
		if ( 1 !== $starts ) {
			return array( 'status' => 'review_required', 'reason_code' => 'subscription_start_event_unconfirmed', 'events' => $events );
		}
		return array(
			'status' => 'confirmed',
			'reason_code' => 'subscription_terms_and_schedule_verified',
			'subscription' => array( 'id' => $subscription['id'], 'status' => $subscription['status'], 'start_date' => $subscription['start_date'] ?? null, 'timezone' => $subscription['timezone'] ?? null, 'monthly_billing_anchor_date' => $subscription['monthly_billing_anchor_date'] ?? null, 'plan_variation_id' => $subscription['plan_variation_id'] ),
			'variation' => $variation,
			'events' => $events,
		);
	}

	private function verify_initial_invoice_payment( array $invoice, array $invoice_order, array $attempt, array $payment, $payment_id, array $price ) {
		if ( 'PAID' !== ( $invoice['status'] ?? null ) || 'paid' !== ( $invoice['payment_state'] ?? null ) || ! $this->money_matches( $invoice['requested_amount_money'] ?? null, $price ) || ! $this->money_matches( $invoice['completed_amount_money'] ?? null, $price ) ) {
			return array( 'verified' => false, 'reason_code' => 'initial_invoice_not_fully_paid' );
		}
		$tenders = $invoice_order['tenders'] ?? null;
		if ( ! is_array( $tenders ) || 1 !== count( $tenders ) || ! isset( $tenders[0]['payment_id'] ) ) {
			return array( 'verified' => false, 'reason_code' => 'initial_invoice_payment_not_unique' );
		}
		if ( $invoice_order['id'] !== $attempt['square_order_id'] || $tenders[0]['payment_id'] !== $payment_id || $payment['order_id'] !== $invoice_order['id'] ) {
			return array( 'verified' => false, 'reason_code' => 'initial_invoice_payment_link_unproven' );
		}
		return array( 'verified' => true, 'reason_code' => 'initial_invoice_and_checkout_payment_identical', 'invoice_id' => $invoice['id'], 'order_id' => $invoice_order['id'], 'payment_id' => $payment_id );
	}

	private function money_matches( $money, array $price ) {
		return is_array( $money ) && isset( $money['amount'], $money['currency'] ) && is_int( $money['amount'] ) && $money['amount'] === $price['amount'] && is_string( $money['currency'] ) && $money['currency'] === $price['currency'];
	}

	private function api_failure( $reason, array $attempt, array $payment_evidence, $subscription_link = null, $order = null, $payment = null, $invoice = null, $invoice_payment = null ) {
		return $this->result( 'square_api_error', $reason, $attempt, $payment_evidence, $subscription_link, null, $order, $payment, $invoice, $invoice_payment );
	}

	private function result( $status, $reason, array $attempt, array $payment_evidence = array(), $subscription_link = null, $conditions = null, $order = null, $payment = null, $invoice = null, $invoice_payment = null, $period_candidate = null ) {
		return array(
			'status' => $status,
			'reason_code' => $reason,
			'review_status' => 'payment_pending' === $status ? 'awaiting_payment' : ( 'square_api_error' === $status ? 'retrieval_failed' : 'required' ),
			'initial_payment' => array(
				'confirmed' => ! empty( $payment_evidence['confirmed'] ),
				'status' => $payment_evidence['status'] ?? 'not_confirmed',
				'payment_id' => $payment_evidence['payment_id'] ?? ( is_array( $payment ) ? ( $payment['id'] ?? null ) : null ),
				'order_id' => $payment_evidence['order_id'] ?? ( $attempt['square_order_id'] ?? null ),
				'amount' => $payment_evidence['amount'] ?? null,
				'currency' => $payment_evidence['currency'] ?? null,
				'refund_status' => $payment_evidence['refund_status'] ?? 'unknown',
				'payment_status' => is_array( $payment ) ? ( $payment['status'] ?? null ) : null,
			),
			'subscription_link' => is_array( $subscription_link ) ? $subscription_link : array( 'status' => 'unconfirmed', 'reason_code' => 'subscription_not_linked' ),
			'invoice_payment' => is_array( $invoice_payment ) ? $invoice_payment : array( 'verified' => false, 'reason_code' => 'initial_invoice_not_verified' ),
			'billing_conditions' => is_array( $conditions ) ? $conditions : array( 'status' => 'unconfirmed', 'reason_code' => 'subscription_terms_not_checked' ),
			'initial_invoice' => is_array( $invoice ) ? array( 'id' => $invoice['id'] ?? null, 'order_id' => $invoice['order_id'] ?? null, 'status' => $invoice['status'] ?? null, 'payment_state' => $invoice['payment_state'] ?? null ) : null,
			'checkout_order' => is_array( $order ) ? array( 'id' => $order['id'] ?? null, 'reference_id_matches_attempt' => ( $order['reference_id'] ?? null ) === ( $attempt['attempt_uuid'] ?? null ), 'total_money' => $order['total_money'] ?? null ) : null,
			'period_candidate' => is_array( $period_candidate ) ? $period_candidate : array( 'status' => 'review_required', 'reason_code' => 'candidate_not_calculated', 'billing_period' => null, 'automatic_approval_candidate' => false ),
			'automatic_approval_candidate' => false,
		);
	}
}
