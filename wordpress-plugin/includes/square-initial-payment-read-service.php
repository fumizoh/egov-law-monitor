<?php

/** Read-only administrator inspection of a Checkout Attempt's initial payment. */
class Egov_Law_Monitor_Square_Initial_Payment_Read_Service {
	private $wpdb;
	private $repository;
	private $client;
	private $payment_confirmation;
	private $subscription_matcher;

	public function __construct( $wpdb, Egov_Law_Monitor_Checkout_Repository $repository, Egov_Law_Monitor_Square_Api_Client $client, Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service $payment_confirmation, Egov_Law_Monitor_Square_Subscription_Attempt_Matcher $subscription_matcher ) {
		$this->wpdb                 = $wpdb;
		$this->repository           = $repository;
		$this->client               = $client;
		$this->payment_confirmation = $payment_confirmation;
		$this->subscription_matcher = $subscription_matcher;
	}

	/**
	 * Inspect an attempt by its local database ID. This does not mutate any data.
	 *
	 * @param int $attempt_id Local checkout-attempt primary key.
	 * @return array|WP_Error Safe inspection result.
	 */
	public function inspect_attempt( $attempt_id ) {
		if ( ! is_int( $attempt_id ) || $attempt_id < 1 ) {
			return new WP_Error( 'square_initial_attempt_id_invalid', '申込情報を確認できません。' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'square_initial_inspection_forbidden', '決済情報を確認する権限がありません。' );
		}

		$attempt = $this->repository->get_attempt( $attempt_id );
		if ( is_wp_error( $attempt ) ) {
			return new WP_Error( 'square_initial_attempt_database_unavailable', '申込情報を取得できません。' );
		}
		if ( ! is_array( $attempt ) ) {
			return new WP_Error( 'square_initial_attempt_not_found', '申込情報が見つかりません。' );
		}
		$identity = $this->validate_attempt( $attempt );
		if ( is_wp_error( $identity ) ) {
			return $this->result( 'reconciliation_required', $identity->get_error_code(), $attempt );
		}

		$payment_result = $this->payment_confirmation->confirm_attempt_payment( $attempt, (int) $attempt['user_id'] );
		if ( is_wp_error( $payment_result ) ) {
			return new WP_Error( 'square_initial_payment_confirmation_unavailable', '初回決済情報を確認できません。' );
		}
		if ( ! is_array( $payment_result ) || empty( $payment_result['status'] ) ) {
			return $this->result( 'reconciliation_required', 'payment_confirmation_invalid', $attempt );
		}
		if ( 'square_api_error' === $payment_result['status'] ) {
			return $this->result( 'square_api_error', $payment_result['reason_code'] ?? 'payment_api_unavailable', $attempt, $payment_result );
		}
		if ( 'payment_pending' === $payment_result['status'] ) {
			return $this->result( 'payment_pending', $payment_result['reason_code'] ?? 'payment_not_completed', $attempt, $payment_result );
		}
		if ( 'payment_confirmed' !== $payment_result['status'] ) {
			return $this->result( 'reconciliation_required', $payment_result['reason_code'] ?? 'payment_requires_review', $attempt, $payment_result );
		}
		if ( ! empty( $attempt['square_customer_id'] ) && isset( $payment_result['customer_id'] ) && ! hash_equals( (string) $attempt['square_customer_id'], (string) $payment_result['customer_id'] ) ) {
			return $this->result( 'reconciliation_required', 'attempt_customer_mismatch', $attempt, $payment_result );
		}

		// A completed charge remains evidence even if refund fields are unavailable;
		// it is never sufficient to approve access without review.
		$payment = $this->client->get_payment( $payment_result['payment_id'] );
		if ( is_wp_error( $payment ) ) {
			return $this->result( 'square_api_error', 'refund_status_retrieval_failed', $attempt, $payment_result, null, null, 'unknown' );
		}
		$price = $this->client->get_standard_plan_price();
		if (
			! is_array( $payment )
			|| ! isset( $payment['id'], $payment['order_id'], $payment['location_id'], $payment['status'] )
			|| ! is_string( $payment['id'] )
			|| ! is_string( $payment['order_id'] )
			|| ! is_string( $payment['location_id'] )
			|| ! is_string( $payment['status'] )
			|| ! hash_equals( (string) $payment_result['payment_id'], $payment['id'] )
			|| ! hash_equals( (string) $attempt['square_order_id'], $payment['order_id'] )
			|| ! hash_equals( (string) $attempt['square_location_id'], $payment['location_id'] )
			|| 'COMPLETED' !== $payment['status']
			|| ! $this->money_matches_price( $payment['amount_money'] ?? null, $price )
			|| ! $this->money_matches_price( $payment['total_money'] ?? null, $price )
		) {
			return $this->result( 'reconciliation_required', 'payment_snapshot_mismatch', $attempt, $payment_result, null, $payment );
		}
		$refund_status = $this->refund_status( $payment );
		if ( 'detected' === $refund_status ) {
			return $this->result( 'reconciliation_required', 'refund_suspected', $attempt, $payment_result, null, $payment, $refund_status );
		}
		if ( 'none_reported_at_lookup' !== $refund_status ) {
			return $this->result( 'reconciliation_required', 'refund_status_unavailable', $attempt, $payment_result, null, $payment, $refund_status );
		}

		$subscription = $this->inspect_subscription_link( $attempt, $identity['scope'] );
		if ( is_wp_error( $subscription ) ) {
			return $this->result( 'square_api_error', 'subscription_lookup_failed', $attempt, $payment_result, null, $payment, $refund_status );
		}
		$subscription_confirmed = is_array( $subscription ) && 'subscription_matched' === ( $subscription['status'] ?? '' );
		$reason = $subscription_confirmed ? 'billing_period_provenance_unavailable' : 'payment_confirmed_subscription_unlinked';
		$status = is_array( $subscription ) && 'square_api_error' === ( $subscription['status'] ?? '' ) ? 'square_api_error' : 'reconciliation_required';
		return $this->result(
			$status,
			$reason,
			$attempt,
			$payment_result,
			$subscription,
			$payment,
			$refund_status
		);
	}

	private function validate_attempt( array $attempt ) {
		$required = array( 'id', 'user_id', 'attempt_uuid', 'square_order_id', 'environment', 'merchant_id', 'square_location_id', 'subscription_plan_variation_id', 'status' );
		foreach ( $required as $field ) {
			if ( ! isset( $attempt[ $field ] ) || ! is_scalar( $attempt[ $field ] ) || '' === (string) $attempt[ $field ] ) {
				return new WP_Error( 'attempt_identity_missing' );
			}
		}
		if ( ! preg_match( '/\A[1-9][0-9]*\z/', (string) $attempt['user_id'] ) || ! get_userdata( (int) $attempt['user_id'] ) ) {
			return new WP_Error( 'attempt_user_invalid' );
		}
		if ( ! preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/i', (string) $attempt['attempt_uuid'] ) ) {
			return new WP_Error( 'attempt_uuid_invalid' );
		}
		if ( ! in_array( $attempt['status'], array( 'pending', 'reconciliation_required' ), true ) ) {
			return new WP_Error( 'attempt_state_not_confirmable' );
		}
		$scope = $this->client->get_checkout_scope();
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		foreach ( array( 'environment' => 'environment', 'merchant_id' => 'merchant_id', 'square_location_id' => 'location_id', 'subscription_plan_variation_id' => 'subscription_plan_variation_id' ) as $field => $scope_field ) {
			if ( ! isset( $scope[ $scope_field ] ) || ! is_string( $scope[ $scope_field ] ) || ! hash_equals( $scope[ $scope_field ], (string) $attempt[ $field ] ) ) {
				return new WP_Error( 'attempt_scope_mismatch' );
			}
		}
		return array( 'scope' => $scope );
	}

	/** Find only an already-persisted exact Attempt link; never search by Customer ID. */
	private function inspect_subscription_link( array $attempt, array $scope ) {
		$table = $this->wpdb->prefix . 'egov_law_subscriptions';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE checkout_attempt_uuid = %s", $attempt['attempt_uuid'] ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'subscription_lookup_failed' );
		}
		if ( empty( $rows ) ) {
			return null;
		}
		if ( 1 !== count( $rows ) || ! is_array( $rows[0] ) ) {
			return array( 'status' => 'reconciliation_required', 'reason_code' => 'subscription_link_not_unique' );
		}
		$row = $rows[0];
		$expected = array(
			'user_id' => (string) $attempt['user_id'],
			'environment' => $scope['environment'],
			'merchant_id' => $scope['merchant_id'],
			'square_location_id' => $scope['location_id'],
			'square_plan_variation_id' => $scope['subscription_plan_variation_id'],
			'checkout_attempt_uuid' => $attempt['attempt_uuid'],
		);
		foreach ( $expected as $field => $value ) {
			if ( ! isset( $row[ $field ] ) || ! hash_equals( (string) $value, (string) $row[ $field ] ) ) {
				return array( 'status' => 'reconciliation_required', 'reason_code' => 'subscription_link_identity_mismatch' );
			}
		}
		if ( empty( $row['square_subscription_id'] ) || empty( $row['square_customer_id'] ) ) {
			return array( 'status' => 'reconciliation_required', 'reason_code' => 'subscription_link_incomplete' );
		}
		$match = $this->subscription_matcher->match_subscription( (string) $row['square_subscription_id'] );
		if ( is_wp_error( $match ) ) {
			return $match;
		}
		if ( ! is_array( $match ) || 'subscription_matched' !== ( $match['status'] ?? '' ) ) {
			return $match;
		}
		$exact = array(
			'user_id' => (int) $attempt['user_id'],
			'attempt_uuid' => $attempt['attempt_uuid'],
			'order_id' => $attempt['square_order_id'],
			'environment' => $scope['environment'],
			'merchant_id' => $scope['merchant_id'],
			'location_id' => $scope['location_id'],
			'customer_id' => $row['square_customer_id'],
			'plan_variation_id' => $scope['subscription_plan_variation_id'],
		);
		foreach ( $exact as $field => $value ) {
			if ( ! isset( $match[ $field ] ) || ( 'user_id' === $field ? (int) $match[ $field ] !== $value : ! is_string( $match[ $field ] ) || ! hash_equals( (string) $value, $match[ $field ] ) ) ) {
				return array( 'status' => 'reconciliation_required', 'reason_code' => 'subscription_match_identity_mismatch' );
			}
		}
		if ( ! hash_equals( (string) $row['square_subscription_id'], (string) $match['subscription_id'] ) ) {
			return array( 'status' => 'reconciliation_required', 'reason_code' => 'subscription_id_mismatch' );
		}
		return $match;
	}

	private function refund_status( $payment ) {
		if ( ! is_array( $payment ) || ! array_key_exists( 'refund_ids', $payment ) || ! array_key_exists( 'refunded_money', $payment ) ) {
			return 'unknown';
		}
		$money = $payment['refunded_money'];
		if ( ! is_array( $money ) || ! isset( $money['amount'], $money['currency'] ) || ! is_int( $money['amount'] ) || ! is_string( $money['currency'] ) ) {
			return 'unknown';
		}
		if ( ! empty( $payment['refund_ids'] ) || 0 < $money['amount'] ) {
			return 'detected';
		}
		return 0 === $money['amount'] && 'JPY' === $money['currency'] ? 'none_reported_at_lookup' : 'unknown';
	}

	private function money_matches_price( $money, array $price ) {
		return is_array( $money )
			&& isset( $money['amount'], $money['currency'] )
			&& is_int( $money['amount'] )
			&& $money['amount'] === $price['amount']
			&& is_string( $money['currency'] )
			&& hash_equals( $price['currency'], $money['currency'] );
	}

	private function result( $status, $reason, array $attempt, $payment_result = null, $subscription_match = null, $payment = null, $refund_status = 'unknown' ) {
		$payment_confirmed = is_array( $payment_result ) && 'payment_confirmed' === ( $payment_result['status'] ?? '' );
		$matched = is_array( $subscription_match ) && 'subscription_matched' === ( $subscription_match['status'] ?? '' );
		$payment_snapshot = is_array( $payment ) ? $payment : array();
		return array(
			'status' => $status,
			'reason_code' => $reason,
			'payment_evidence' => array(
				'status' => $payment_confirmed ? 'confirmed' : ( 'payment_pending' === $status ? 'pending' : 'not_confirmed' ),
				'confirmed' => $payment_confirmed,
				'refund_status' => $refund_status,
				'payment_id' => $payment_result['payment_id'] ?? null,
				'order_id' => $payment_result['order_id'] ?? ( $attempt['square_order_id'] ?? null ),
				'amount' => $payment_result['amount'] ?? null,
				'currency' => $payment_result['currency'] ?? null,
				'payment_status' => $payment_snapshot['status'] ?? null,
			),
			'review_status' => 'payment_pending' === $status ? 'awaiting_payment' : ( 'square_api_error' === $status ? 'retrieval_failed' : 'required' ),
			'invoice_scope' => array(
				'selection' => 'initial_checkout_order_only',
				'invoice_total_count' => null,
				'checked_invoice_count' => $matched ? 1 : 0,
				'unchecked_invoice_count' => null,
				'invoice_list_completeness' => 'not_independently_verified',
				'invoice_id' => $matched ? ( $subscription_match['invoice_id'] ?? null ) : null,
				'contract_subscription_link_confirmed' => $matched,
				'initial_payment_checked' => $payment_confirmed,
			),
			'subscription_link' => array(
				'status' => $matched ? 'confirmed' : 'unconfirmed',
				'subscription_id' => $matched ? ( $subscription_match['subscription_id'] ?? null ) : null,
				'reason_code' => $matched ? 'exact_checkout_invoice_order_attempt_match' : ( $subscription_match['reason_code'] ?? 'subscription_not_persisted_for_attempt' ),
			),
			'billing_period' => array(
				'start' => null,
				'end' => null,
				'verified' => false,
				'evidence_code' => 'period_provenance_not_recorded',
			),
			'automatic_approval_candidate' => false,
		);
	}
}
