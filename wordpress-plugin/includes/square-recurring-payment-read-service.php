<?php

/** Read-only inspection of the latest recurring invoice for a stored contract. */
class Egov_Law_Monitor_Square_Recurring_Payment_Read_Service {
	private $wpdb;
	private $client;

	public function __construct( $wpdb, Egov_Law_Monitor_Square_Api_Client $client ) {
		$this->wpdb   = $wpdb;
		$this->client = $client;
	}

	/**
	 * Inspect the most recent Square invoice for a contract loaded by local row ID.
	 * This method never writes to WordPress tables and never authorizes a permission change.
	 *
	 * @param int $contract_id Local egov_law_subscriptions primary key, not a Square ID.
	 * @return array|WP_Error Safe inspection result or a local/configuration error.
	 */
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
		$contract = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $contract_id ),
			ARRAY_A
		);
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を取得できません。' );
		}
		if ( ! is_array( $contract ) ) {
			return new WP_Error( 'square_contract_not_found', '契約情報が見つかりません。' );
		}

		$contract_fields = array( 'user_id', 'environment', 'merchant_id', 'square_location_id', 'square_customer_id', 'square_subscription_id', 'square_plan_variation_id', 'plan_code' );
		foreach ( $contract_fields as $field ) {
			if ( ! isset( $contract[ $field ] ) || '' === (string) $contract[ $field ] ) {
				return $this->result( 'reconciliation_required', 'contract_data_missing', $contract );
			}
		}
		if ( 'standard' !== $contract['plan_code'] || ! ctype_digit( (string) $contract['user_id'] ) || (int) $contract['user_id'] < 1 || ! get_userdata( (int) $contract['user_id'] ) ) {
			return $this->result( 'reconciliation_required', 'contract_identity_invalid', $contract );
		}
		if ( ! $this->same( $scope['environment'], $contract['environment'] ) || ! $this->same( $scope['merchant_id'], $contract['merchant_id'] ) || ! $this->same( $scope['location_id'], $contract['square_location_id'] ) || ! $this->same( $scope['subscription_plan_variation_id'], $contract['square_plan_variation_id'] ) ) {
			return $this->result( 'reconciliation_required', 'configured_scope_mismatch', $contract );
		}

		$subscription = $this->client->retrieve_subscription( (string) $contract['square_subscription_id'] );
		if ( is_wp_error( $subscription ) ) {
			return $this->api_result( $subscription, $contract, 'subscription' );
		}
		foreach (
			array(
				'id'                => (string) $contract['square_subscription_id'],
				'location_id'       => (string) $contract['square_location_id'],
				'plan_variation_id' => (string) $contract['square_plan_variation_id'],
				'customer_id'       => (string) $contract['square_customer_id'],
			) as $field => $expected
		) {
			if ( ! isset( $subscription[ $field ] ) || ! is_string( $subscription[ $field ] ) || ! $this->same( $expected, $subscription[ $field ] ) ) {
				return $this->result( 'reconciliation_required', 'subscription_' . $field . '_mismatch', $contract, $subscription );
			}
		}

		if ( ! array_key_exists( 'invoice_ids', $subscription ) || ! is_array( $subscription['invoice_ids'] ) ) {
			return $this->result( 'reconciliation_required', 'invoice_list_unavailable', $contract, $subscription );
		}
		$invoice_ids = $subscription['invoice_ids'];
		if ( empty( $invoice_ids ) ) {
			return $this->result( 'payment_pending', 'invoice_not_available', $contract, $subscription );
		}
		$invoice_id = $invoice_ids[0]; // Square documents Subscription.invoice_ids newest first.
		if ( ! is_string( $invoice_id ) || '' === $invoice_id ) {
			return $this->result( 'reconciliation_required', 'invoice_id_invalid', $contract, $subscription );
		}
		$invoice = $this->client->get_invoice( $invoice_id );
		if ( is_wp_error( $invoice ) ) {
			return $this->api_result( $invoice, $contract, 'invoice', $subscription, null, null, $invoice_id );
		}
		if ( ! $this->same( $invoice_id, $invoice['id'] ) || ! isset( $invoice['subscription_id'] ) || ! $this->same( (string) $contract['square_subscription_id'], $invoice['subscription_id'] ) || ! $this->same( (string) $contract['square_location_id'], $invoice['location_id'] ) ) {
			return $this->result( 'reconciliation_required', 'invoice_identity_mismatch', $contract, $subscription, $invoice );
		}

		$invoice_local = $this->find_local_invoice( $contract, $invoice_id );
		if ( is_wp_error( $invoice_local ) ) {
			return $this->result( 'reconciliation_required', 'local_invoice_conflict', $contract, $subscription, $invoice );
		}
		$period = $this->period_from_local_invoice( $invoice_local );

		$invoice_state = isset( $invoice['payment_state'] ) && is_string( $invoice['payment_state'] ) ? $invoice['payment_state'] : 'unknown';
		if ( in_array( $invoice_state, array( 'planned', 'scheduled', 'unpaid', 'processing' ), true ) ) {
			return $this->result( 'payment_pending', 'invoice_' . $invoice_state, $contract, $subscription, $invoice, null, $period );
		}
		if ( 'invoice_failed' === $invoice_state ) {
			return $this->result( 'payment_failed', 'failure_timestamp_unavailable', $contract, $subscription, $invoice, null, $period, 'failed' );
		}
		if ( 'paid' !== $invoice_state ) {
			return $this->result( 'reconciliation_required', 'invoice_state_not_confirmable', $contract, $subscription, $invoice, null, $period );
		}
		if ( ! is_array( $invoice['payment_requests'] ?? null ) || 1 !== count( $invoice['payment_requests'] ) ) {
			return $this->result( 'reconciliation_required', 'multiple_or_missing_payment_requests', $contract, $subscription, $invoice, null, $period );
		}
		$price = $this->client->get_standard_plan_price();
		if ( ! $this->money_is( $invoice['requested_amount_money'] ?? null, $price ) || ! $this->money_is( $invoice['completed_amount_money'] ?? null, $price ) ) {
			return $this->result( 'reconciliation_required', 'invoice_amount_or_currency_mismatch', $contract, $subscription, $invoice, null, $period );
		}
		$order = $this->client->retrieve_order( (string) $invoice['order_id'] );
		if ( is_wp_error( $order ) ) {
			return $this->api_result( $order, $contract, 'order', $subscription, $invoice, $period, (string) $invoice['order_id'] );
		}
		if ( ! $this->same( (string) $invoice['order_id'], $order['id'] ) || ! $this->same( (string) $contract['square_location_id'], $order['location_id'] ) ) {
			return $this->result( 'reconciliation_required', 'order_identity_mismatch', $contract, $subscription, $invoice, null, $period );
		}
		if ( 'COMPLETED' !== $order['state'] ) {
			return $this->result( 'reconciliation_required', 'order_not_completed', $contract, $subscription, $invoice, null, $period );
		}
		if ( ! $this->money_is( $order['total_money'] ?? null, $price ) ) {
			return $this->result( 'reconciliation_required', 'order_amount_or_currency_mismatch', $contract, $subscription, $invoice, null, $period );
		}
		if ( ! is_array( $order['tenders'] ?? null ) || 1 !== count( $order['tenders'] ) || empty( $order['tenders'][0]['payment_id'] ) ) {
			return $this->result( 'reconciliation_required', 'payment_id_missing_or_multiple', $contract, $subscription, $invoice, null, $period );
		}
		$payment_id = $order['tenders'][0]['payment_id'];
		$payment = $this->client->get_payment( $payment_id );
		if ( is_wp_error( $payment ) ) {
			return $this->api_result( $payment, $contract, 'payment', $subscription, $invoice, $period, $payment_id );
		}
		if ( ! $this->same( $payment_id, $payment['id'] ) || ! $this->same( (string) $invoice['order_id'], $payment['order_id'] ) || ! $this->same( (string) $contract['square_location_id'], $payment['location_id'] ) || ( isset( $payment['customer_id'] ) && ! $this->same( (string) $contract['square_customer_id'], $payment['customer_id'] ) ) ) {
			return $this->result( 'reconciliation_required', 'payment_identity_mismatch', $contract, $subscription, $invoice, $payment, $period );
		}
		if ( is_array( $invoice_local ) && ! empty( $invoice_local['square_payment_id'] ) && ! $this->same( (string) $invoice_local['square_payment_id'], $payment_id ) ) {
			return $this->result( 'reconciliation_required', 'stored_payment_id_mismatch', $contract, $subscription, $invoice, $payment, $period );
		}
		if ( 'FAILED' === $payment['status'] ) {
			return $this->result( 'payment_failed', 'failure_timestamp_unavailable', $contract, $subscription, $invoice, $payment, $period, 'failed' );
		}
		if ( 'COMPLETED' !== $payment['status'] ) {
			return $this->result( 'payment_pending', 'payment_not_completed', $contract, $subscription, $invoice, $payment, $period );
		}
		if ( ! $this->money_is( $payment['amount_money'] ?? null, $price ) || ! $this->money_is( $payment['total_money'] ?? null, $price ) ) {
			return $this->result( 'reconciliation_required', 'payment_amount_or_currency_mismatch', $contract, $subscription, $invoice, $payment, $period );
		}
		$refund_status = 'unknown';
		if ( array_key_exists( 'refund_ids', $payment ) && array_key_exists( 'refunded_money', $payment ) && isset( $payment['refunded_money']['amount'], $payment['refunded_money']['currency'] ) && 'JPY' === $payment['refunded_money']['currency'] ) {
			$refund_status = empty( $payment['refund_ids'] ) && 0 === $payment['refunded_money']['amount'] ? 'none_reported_at_lookup' : 'detected';
		}
		$payment_evidence = 'confirmed';
		if ( isset( $payment['refunded_money']['currency'] ) && 'JPY' !== $payment['refunded_money']['currency'] ) {
			return $this->result( 'reconciliation_required', 'refund_currency_mismatch', $contract, $subscription, $invoice, $payment, $period, $payment_evidence, 'unknown' );
		}
		if ( ! empty( $payment['refund_ids'] ) || ( isset( $payment['refunded_money']['amount'] ) && $payment['refunded_money']['amount'] > 0 ) ) {
			return $this->result( 'reconciliation_required', 'refund_detected', $contract, $subscription, $invoice, $payment, $period, $payment_evidence, 'detected' );
		}
		if ( 'unknown' === $refund_status ) {
			return $this->result( 'reconciliation_required', 'refund_status_unavailable', $contract, $subscription, $invoice, $payment, $period, $payment_evidence, $refund_status );
		}
		if ( ! empty( $subscription['canceled_date'] ) || ! in_array( $subscription['status'], array( 'ACTIVE', 'PENDING' ), true ) ) {
			return $this->result( 'reconciliation_required', 'subscription_cancellation_or_status_requires_review', $contract, $subscription, $invoice, $payment, $period, $payment_evidence, $refund_status );
		}
		if ( empty( $period['verified'] ) || null === $period['start'] || null === $period['end'] ) {
			return $this->result( 'reconciliation_required', 'billing_period_provenance_unavailable', $contract, $subscription, $invoice, $payment, $period, $payment_evidence, $refund_status );
		}

		return $this->result( 'payment_confirmed', 'invoice_order_payment_verified', $contract, $subscription, $invoice, $payment, $period, $payment_evidence, $refund_status );
	}

	private function find_local_invoice( $contract, $invoice_id ) {
		$table = $this->wpdb->prefix . 'egov_law_subscription_invoices';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE subscription_id = %d AND user_id = %d AND environment = %s AND merchant_id = %s AND square_invoice_id = %s", (int) $contract['id'], (int) $contract['user_id'], $contract['environment'], $contract['merchant_id'], $invoice_id ),
			ARRAY_A
		);
		if ( '' !== (string) $this->wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'square_contract_database_unavailable', '請求情報を取得できません。' );
		}
		if ( count( $rows ) > 1 ) {
			return new WP_Error( 'square_contract_invoice_conflict', '請求情報を照合できません。' );
		}
		return empty( $rows ) ? null : $rows[0];
	}

	private function period_from_local_invoice( $invoice ) {
		$period = array( 'start' => null, 'end' => null, 'verified' => false, 'evidence_code' => 'period_provenance_not_recorded' );
		if ( ! is_array( $invoice ) ) {
			return $period;
		}
		foreach ( array( 'billing_period_start' => 'start', 'billing_period_end' => 'end' ) as $column => $key ) {
			if ( ! empty( $invoice[ $column ] ) && '0000-00-00 00:00:00' !== $invoice[ $column ] ) {
				$period[ $key ] = (string) $invoice[ $column ];
			}
		}
		return $period;
	}

	private function money_is( $money, $expected ) {
		return is_array( $money ) && isset( $money['amount'], $money['currency'] ) && is_int( $money['amount'] ) && $money['amount'] === $expected['amount'] && is_string( $money['currency'] ) && $money['currency'] === $expected['currency'];
	}

	private function same( $expected, $actual ) {
		return is_string( $expected ) && is_string( $actual ) && '' !== $expected && hash_equals( $expected, $actual );
	}

	private function api_result( $error, $contract, $stage, $subscription = null, $invoice = null, $period = null, $resource_id = null ) {
		if ( 'invoice' === $stage && null === $invoice && is_string( $resource_id ) ) {
			$invoice = array( 'id' => $resource_id );
		}
		if ( 'payment' === $stage && is_string( $resource_id ) ) {
			$payment = array( 'id' => $resource_id );
		} else {
			$payment = null;
		}
		if ( 'square_resource_not_found' === $error->get_error_code() ) {
			return $this->result( 'reconciliation_required', $stage . '_not_found', $contract, $subscription, $invoice, $payment, $period );
		}
		if ( in_array( $error->get_error_code(), array( 'square_resource_response_invalid', 'square_subscription_response_invalid', 'square_invoice_response_invalid', 'square_order_response_invalid', 'square_payment_response_invalid' ), true ) ) {
			return $this->result( 'reconciliation_required', $stage . '_response_invalid', $contract, $subscription, $invoice, $payment, $period );
		}
		return $this->result( 'square_api_error', $stage . '_retrieval_failed', $contract, $subscription, $invoice, $payment, $period );
	}

	private function result( $status, $reason, $contract, $subscription = null, $invoice = null, $payment = null, $period = null, $payment_evidence = 'not_confirmed', $refund_status = 'unknown' ) {
		$price = $this->client->get_standard_plan_price();
		$invoice_ids = is_array( $subscription ) && isset( $subscription['invoice_ids'] ) && is_array( $subscription['invoice_ids'] ) ? $subscription['invoice_ids'] : null;
		$invoice_total = is_array( $invoice_ids ) ? count( $invoice_ids ) : null;
		$requested_invoice_count = is_array( $invoice ) && ! empty( $invoice['id'] ) ? 1 : 0;
		$checked_invoice_count = is_array( $invoice ) && isset( $invoice['status'] ) ? 1 : 0;
		return array(
			'status' => $status,
			'reason_code' => $reason,
			'payment_evidence' => array(
				'status' => $payment_evidence,
				'confirmed' => 'confirmed' === $payment_evidence,
				'refund_status' => $refund_status,
			),
			'review_status' => 'payment_pending' === $status ? 'awaiting_payment' : 'required',
			'invoice_scope' => array(
				'selection' => 'latest_invoice_only',
				'invoice_list_completeness' => 'not_independently_verified',
				'contract_wide_payment_status_confirmed' => false,
				'invoice_total_count' => $invoice_total,
				'requested_invoice_count' => $requested_invoice_count,
				'checked_invoice_count' => $checked_invoice_count,
				'unchecked_invoice_count' => null === $invoice_total ? null : max( 0, $invoice_total - $checked_invoice_count ),
			),
			'automatic_approval_candidate' => false,
			'failure_at' => null,
			'contract' => array(
				'id' => isset( $contract['id'] ) ? (int) $contract['id'] : null,
				'user_id' => isset( $contract['user_id'] ) ? (int) $contract['user_id'] : null,
				'environment' => $contract['environment'] ?? null,
				'merchant_id' => $contract['merchant_id'] ?? null,
				'location_id' => $contract['square_location_id'] ?? null,
				'customer_id' => $contract['square_customer_id'] ?? null,
				'subscription_id' => $contract['square_subscription_id'] ?? null,
				'plan_variation_id' => $contract['square_plan_variation_id'] ?? null,
				'internal_status' => $contract['internal_status'] ?? null,
			),
			'subscription' => is_array( $subscription ) ? array( 'id' => $subscription['id'] ?? null, 'status' => $subscription['status'] ?? null ) : null,
			'invoice' => is_array( $invoice ) ? array(
				'id' => $invoice['id'] ?? null,
				'order_id' => $invoice['order_id'] ?? null,
				'status' => $invoice['status'] ?? null,
				'payment_state' => $invoice['payment_state'] ?? null,
				'amount' => $invoice['requested_amount_money']['amount'] ?? null,
				'completed_amount' => $invoice['completed_amount_money']['amount'] ?? null,
				'currency' => $invoice['requested_amount_money']['currency'] ?? null,
				'due_at' => $this->invoice_due_date( $invoice ),
			) : null,
			'payment' => is_array( $payment ) ? array(
				'id' => $payment['id'] ?? null,
				'status' => $payment['status'] ?? null,
				'amount' => $payment['amount_money']['amount'] ?? null,
				'total_amount' => $payment['total_money']['amount'] ?? null,
				'currency' => $payment['amount_money']['currency'] ?? null,
				'created_at' => $payment['created_at'] ?? null,
				'refunded_amount' => $payment['refunded_money']['amount'] ?? null,
			) : null,
			'billing_period' => is_array( $period ) ? $period : array( 'start' => null, 'end' => null, 'verified' => false, 'evidence_code' => 'period_provenance_not_recorded' ),
			'expected_price' => array( 'amount' => $price['amount'], 'currency' => $price['currency'] ),
		);
	}

	private function invoice_due_date( $invoice ) {
		if ( empty( $invoice['payment_requests'] ) || ! is_array( $invoice['payment_requests'] ) || 1 !== count( $invoice['payment_requests'] ) ) {
			return null;
		}
		return $invoice['payment_requests'][0]['due_date'] ?? null;
	}
}
