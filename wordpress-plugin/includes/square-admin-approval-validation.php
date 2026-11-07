<?php

/** Read-only preflight validation for an administrator's Square approval. */
class Egov_Law_Monitor_Square_Admin_Approval_Validation_Service {
	private const STANDARD_AMOUNT = 500;
	private const STANDARD_CURRENCY = 'JPY';
	private $wpdb;
	private $initial_inspection;
	private $recurring_inspection;
	private $review_repository;
	private $current_inspection;
	private $current_initial_contract_id;

	public function __construct( $wpdb, $initial_inspection, $recurring_inspection, Egov_Law_Monitor_Square_Admin_Review_Repository $review_repository ) {
		$this->wpdb = $wpdb;
		$this->initial_inspection = $initial_inspection;
		$this->recurring_inspection = $recurring_inspection;
		$this->review_repository = $review_repository;
	}

	/** Validate an initial payment and period candidate for an Attempt. */
	public function validate_initial_approval( $attempt_id ) {
		$this->current_inspection = null;
		$this->current_initial_contract_id = null;
		if ( ! is_int( $attempt_id ) || $attempt_id < 1 ) {
			return new WP_Error( 'square_approval_attempt_id_invalid', '申込情報を確認できません。' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'square_approval_validation_forbidden', '承認候補を確認する権限がありません。' );
		}
		$attempt = $this->get_attempt( $attempt_id );
		if ( is_wp_error( $attempt ) || ! is_array( $attempt ) ) {
			return is_wp_error( $attempt ) ? $attempt : new WP_Error( 'square_approval_attempt_not_found', '申込情報が見つかりません。' );
		}
		if ( ! $this->valid_attempt( $attempt ) ) {
			return $this->result( 'initial', $attempt_id, null, false, 'attempt_identity_or_state_invalid' );
		}
		$contracts = $this->get_contracts_for_attempt( $attempt['attempt_uuid'] );
		if ( is_wp_error( $contracts ) ) {
			return $contracts;
		}
		if ( count( $contracts ) > 1 ) {
			return $this->result( 'initial', $attempt_id, null, false, 'attempt_contract_not_unique' );
		}
		$contract = empty( $contracts ) ? null : $contracts[0];
		if ( is_array( $contract ) ) {
			$this->current_initial_contract_id = (int) ( $contract['id'] ?? 0 );
			if ( ! $this->contract_matches_attempt( $contract, $attempt ) ) {
				return $this->result( 'initial', $attempt_id, null, false, 'local_contract_identity_mismatch' );
			}
		}
		if ( ! is_object( $this->initial_inspection ) || ! is_callable( array( $this->initial_inspection, 'inspect_attempt' ) ) ) {
			return $this->result( 'initial', $attempt_id, null, false, 'initial_inspection_unavailable' );
		}
		try {
			$inspection = $this->initial_inspection->inspect_attempt( $attempt_id );
		} catch ( Throwable $error ) {
			return $this->result( 'initial', $attempt_id, null, false, 'initial_inspection_failed' );
		}
		if ( is_wp_error( $inspection ) || ! is_array( $inspection ) ) {
			return $this->result( 'initial', $attempt_id, null, false, 'initial_inspection_failed' );
		}
		$this->current_inspection = $inspection;

		$reloaded = $this->get_attempt( $attempt_id );
		if ( is_wp_error( $reloaded ) ) {
			return $reloaded;
		}
		if ( ! is_array( $reloaded ) || ! $this->same_initial_attempt( $attempt, $reloaded ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'attempt_changed_during_validation' );
		}
		$reloaded_contracts = $this->get_contracts_for_attempt( $attempt['attempt_uuid'] );
		if ( is_wp_error( $reloaded_contracts ) ) {
			return $reloaded_contracts;
		}
		if ( count( $reloaded_contracts ) !== count( $contracts ) || ( ! empty( $contracts ) && ( ! is_array( $reloaded_contracts[0] ) || ! $this->same_contract_snapshot( $contract, $reloaded_contracts[0] ) ) ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'attempt_contract_changed_during_validation' );
		}

		$attempt_reviews = $this->review_repository->get_reviews_for_attempt( (string) $attempt['attempt_uuid'], 200 );
		if ( is_wp_error( $attempt_reviews ) ) {
			return new WP_Error( 'square_approval_review_history_unavailable', '承認履歴を確認できません。' );
		}
		if ( 200 <= count( $attempt_reviews ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'attempt_review_history_incomplete' );
		}
		foreach ( $attempt_reviews as $review ) {
			if ( in_array( $review['action_type'] ?? null, array( 'approve', 'correct' ), true ) ) {
				return $this->result( 'initial', $attempt_id, $inspection, false, 'initial_payment_already_approved' );
			}
		}

		$payment = $inspection['initial_payment'] ?? array();
		if ( 'review_required' !== ( $inspection['status'] ?? null ) || 'payment_and_period_candidate_require_administrator_approval' !== ( $inspection['reason_code'] ?? null ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, $this->inspection_reason( $inspection, 'initial_inspection_not_approvable' ) );
		}
		$payment_id = $payment['payment_id'] ?? null;
		if ( ! is_array( $payment ) || empty( $payment['confirmed'] ) || 'COMPLETED' !== ( $payment['payment_status'] ?? null ) || 'none_reported_at_lookup' !== ( $payment['refund_status'] ?? null ) || self::STANDARD_AMOUNT !== ( $payment['amount'] ?? null ) || self::STANDARD_CURRENCY !== ( $payment['currency'] ?? null ) || ! $this->valid_square_id( $payment_id, 192 ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'initial_payment_evidence_not_confirmed' );
		}
		$prior_payment_approval = $this->has_prior_payment_approval( $attempt['environment'], $attempt['merchant_id'], $payment_id );
		if ( is_wp_error( $prior_payment_approval ) ) {
			return $prior_payment_approval;
		}
		if ( $prior_payment_approval ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'payment_already_approved' );
		}

		$link = $inspection['subscription_link'] ?? array();
		$method = $link['method'] ?? null;
		$subscription_id = $link['subscription_id'] ?? null;
		if ( 'confirmed' !== ( $link['status'] ?? null ) || ! in_array( $method, array( 'attempt_reference_chain', 'local_contract_and_attempt_reference_chain' ), true ) || ! $this->valid_square_id( $subscription_id, 255 ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'attempt_subscription_link_unproven' );
		}
		if ( empty( $inspection['checkout_order']['reference_id_matches_attempt'] ) || ( $inspection['initial_payment']['order_id'] ?? null ) !== $attempt['square_order_id'] ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'attempt_order_reference_mismatch' );
		}
		if ( empty( $inspection['invoice_payment']['verified'] ) || ( $inspection['invoice_payment']['payment_id'] ?? null ) !== $payment_id || ( $inspection['initial_invoice']['id'] ?? null ) !== ( $inspection['invoice_payment']['invoice_id'] ?? null ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, $inspection['invoice_payment']['reason_code'] ?? 'initial_invoice_payment_unverified' );
		}
		$conditions = $inspection['billing_conditions'] ?? array();
		if ( 'confirmed' !== ( $conditions['status'] ?? null ) || ! is_array( $conditions['subscription'] ?? null ) || ! is_array( $conditions['variation'] ?? null ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, $conditions['reason_code'] ?? 'subscription_billing_conditions_unconfirmed' );
		}
		$candidate = $this->validate_candidate( $inspection['period_candidate'] ?? null, 'initial', $conditions['subscription']['timezone'] ?? null, $conditions['subscription']['monthly_billing_anchor_date'] ?? null );
		if ( is_wp_error( $candidate ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, $candidate->get_error_code() );
		}

		if ( is_array( $contract ) && ! $this->contract_matches_initial( $contract, $attempt, $subscription_id ) ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'local_contract_identity_mismatch' );
		}
		if ( null === $contract && 'attempt_reference_chain' !== $method ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'local_contract_missing_for_link_method' );
		}
		$conflict = $this->has_other_current_contract( (int) $attempt['user_id'], $attempt['environment'], null );
		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}
		if ( $conflict ) {
			return $this->result( 'initial', $attempt_id, $inspection, false, 'another_current_contract_exists' );
		}
		return $this->result( 'initial', $attempt_id, $inspection, true, 'initial_payment_and_period_candidate_verified', array(
			'local_attempt_id' => $attempt_id,
			'local_contract_id' => is_array( $contract ) ? (int) $contract['id'] : null,
			'contract_creation_candidate' => null === $contract,
			'subscription_id' => $subscription_id,
			'payment_id' => $payment_id,
			'invoice_id' => $inspection['initial_invoice']['id'],
			'period_candidate' => $candidate,
			'timezone' => $conditions['subscription']['timezone'],
			'monthly_billing_anchor_date' => $conditions['subscription']['monthly_billing_anchor_date'],
		) );
	}

	/** Validate one recurring payment and period candidate for a local contract. */
	public function validate_recurring_approval( $contract_id ) {
		$this->current_inspection = null;
		if ( ! is_int( $contract_id ) || $contract_id < 1 ) {
			return new WP_Error( 'square_approval_contract_id_invalid', '契約情報を確認できません。' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'square_approval_validation_forbidden', '承認候補を確認する権限がありません。' );
		}
		$contract = $this->get_contract( $contract_id );
		if ( is_wp_error( $contract ) || ! is_array( $contract ) ) {
			return is_wp_error( $contract ) ? $contract : new WP_Error( 'square_approval_contract_not_found', '契約情報が見つかりません。' );
		}
		if ( ! $this->valid_contract( $contract ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'contract_identity_or_state_invalid' );
		}
		if ( ! is_object( $this->recurring_inspection ) || ! is_callable( array( $this->recurring_inspection, 'inspect_contract' ) ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'recurring_inspection_unavailable' );
		}
		try {
			$inspection = $this->recurring_inspection->inspect_contract( $contract_id );
		} catch ( Throwable $error ) {
			return $this->result( 'recurring', null, $contract_id, false, 'recurring_inspection_failed' );
		}
		if ( is_wp_error( $inspection ) || ! is_array( $inspection ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'recurring_inspection_failed' );
		}
		$this->current_inspection = $inspection;
		$reloaded = $this->get_contract( $contract_id );
		if ( is_wp_error( $reloaded ) ) {
			return $reloaded;
		}
		if ( ! is_array( $reloaded ) || ! $this->same_contract_snapshot( $contract, $reloaded ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'contract_changed_during_validation' );
		}

		$reviews = $this->review_repository->get_reviews_for_contract( $contract_id, 200 );
		if ( is_wp_error( $reviews ) ) {
			return new WP_Error( 'square_approval_review_history_unavailable', '承認履歴を確認できません。' );
		}
		if ( 200 <= count( $reviews ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'contract_review_history_incomplete' );
		}
		if ( 'payment_confirmed' !== ( $inspection['status'] ?? null ) || empty( $inspection['payment_evidence']['confirmed'] ) ) {
			return $this->result( 'recurring', null, $contract_id, false, $this->inspection_reason( $inspection, 'recurring_payment_not_confirmed' ) );
		}
		if ( ! $this->recurring_inspection_matches_contract( $inspection, $contract ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'square_contract_identity_mismatch' );
		}
		if ( 'none' !== ( $inspection['schedule_review']['state'] ?? null ) || empty( $inspection['event_scope']['complete'] ) || empty( $inspection['variation']['single_monthly_phase'] ) ) {
			return $this->result( 'recurring', null, $contract_id, false, $this->inspection_reason( $inspection, 'subscription_schedule_or_catalog_unverified' ) );
		}
		$invoice_results = $inspection['payment_evidence']['invoice_results'] ?? null;
		$scope = $inspection['invoice_scope'] ?? array();
		if ( ! is_array( $invoice_results ) || 1 !== count( $invoice_results ) || 1 !== ( $scope['requested_invoice_count'] ?? null ) || 1 !== ( $scope['checked_invoice_count'] ?? null ) || 0 !== ( $scope['unchecked_invoice_count'] ?? null ) || 'square_subscription_invoice_ids_field_newest_first' !== ( $scope['invoice_list_completeness'] ?? null ) || empty( $inspection['event_scope']['complete'] ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'invoice_or_event_scope_incomplete' );
		}
		$invoice_result = $invoice_results[0];
		if ( 'confirmed' !== ( $invoice_result['payment_evidence'] ?? null ) || 'payment_confirmed' !== ( $invoice_result['status'] ?? null ) ) {
			return $this->result( 'recurring', null, $contract_id, false, $invoice_result['reason_code'] ?? 'invoice_payment_not_confirmed' );
		}
		$invoice = $invoice_result['invoice'] ?? array();
		$payment = $invoice_result['payment'] ?? array();
		if ( 'PAID' !== ( $invoice['status'] ?? null ) || 'paid' !== ( $invoice['payment_state'] ?? null ) || self::STANDARD_AMOUNT !== ( $invoice['amount'] ?? null ) || self::STANDARD_AMOUNT !== ( $invoice['completed_amount'] ?? null ) || self::STANDARD_CURRENCY !== ( $invoice['currency'] ?? null ) || 'COMPLETED' !== ( $payment['status'] ?? null ) || self::STANDARD_AMOUNT !== ( $payment['amount'] ?? null ) || self::STANDARD_AMOUNT !== ( $payment['total_amount'] ?? null ) || self::STANDARD_CURRENCY !== ( $payment['currency'] ?? null ) || ! $this->valid_square_id( $invoice['id'] ?? null, 255 ) || ! $this->valid_square_id( $payment['id'] ?? null, 192 ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'invoice_payment_identity_or_amount_unconfirmed' );
		}
		$prior_payment_approval = $this->has_prior_payment_approval( $contract['environment'], $contract['merchant_id'], $payment['id'] );
		if ( is_wp_error( $prior_payment_approval ) ) {
			return $prior_payment_approval;
		}
		if ( $prior_payment_approval ) {
			return $this->result( 'recurring', null, $contract_id, false, 'payment_already_approved' );
		}
		$duplicate_invoice = $this->has_duplicate_invoice_approval( $contract['environment'], $contract['merchant_id'], $invoice['id'] );
		if ( is_wp_error( $duplicate_invoice ) ) {
			return $duplicate_invoice;
		}
		if ( $duplicate_invoice ) {
			return $this->result( 'recurring', null, $contract_id, false, 'invoice_already_approved_or_held' );
		}

		$baseline = $inspection['previous_approval'] ?? null;
		$latest_approval = $this->latest_contract_approval( $reviews, $contract );
		if ( ! is_array( $baseline ) || ! is_array( $latest_approval ) || (int) ( $baseline['review_id'] ?? 0 ) !== (int) $latest_approval['id'] || ( $baseline['invoice_id'] ?? null ) !== ( $latest_approval['square_invoice_id'] ?? null ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'latest_approved_invoice_baseline_mismatch' );
		}
		if ( ! $this->approval_matches_contract_period( $latest_approval, $contract ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'approved_period_and_contract_mismatch' );
		}
		if ( $this->is_admin_suspended( $reviews ) ) {
			return $this->result( 'recurring', null, $contract_id, false, 'administrator_suspended' );
		}
		$conflict = $this->has_other_current_contract( (int) $contract['user_id'], $contract['environment'], $contract_id );
		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}
		if ( $conflict ) {
			return $this->result( 'recurring', null, $contract_id, false, 'another_current_contract_exists' );
		}

		$candidate = $this->validate_candidate( $inspection['period_candidate'] ?? null, 'renewal', $inspection['subscription']['timezone'] ?? null, $inspection['subscription']['monthly_billing_anchor_date'] ?? null );
		if ( is_wp_error( $candidate ) ) {
			return $this->result( 'recurring', null, $contract_id, false, $candidate->get_error_code() );
		}
		$previous_end_date = substr( (string) ( $latest_approval['approved_period_end'] ?? '' ), 0, 10 );
		if ( $candidate['start_date'] !== $previous_end_date ) {
			return $this->result( 'recurring', null, $contract_id, false, 'renewal_period_start_not_contiguous' );
		}
		if ( ( $latest_approval['subscription_timezone'] ?? null ) !== $candidate['timezone'] ) {
			return $this->result( 'recurring', null, $contract_id, false, 'renewal_timezone_differs_from_approved_period' );
		}
		if ( (int) ( $latest_approval['monthly_billing_anchor_date'] ?? 0 ) !== $candidate['monthly_billing_anchor_date'] ) {
			return $this->result( 'recurring', null, $contract_id, false, 'renewal_anchor_differs_from_approved_period' );
		}
		return $this->result( 'recurring', null, $contract_id, true, 'recurring_payment_and_period_candidate_verified', array(
			'local_contract_id' => $contract_id,
			'subscription_id' => $contract['square_subscription_id'],
			'invoice_id' => $invoice['id'],
			'payment_id' => $payment['id'],
			'previous_review_id' => (int) $latest_approval['id'],
			'period_candidate' => $candidate,
			'timezone' => $candidate['timezone'],
			'monthly_billing_anchor_date' => $candidate['monthly_billing_anchor_date'],
		) );
	}

	private function result( $kind, $attempt_id, $contract_id, $eligible, $reason, array $verified = array() ) {
		// Older internal call sites passed the inspection as argument three. Do
		// not expose that array as a local contract ID; reduce it to safe fields.
		if ( is_array( $contract_id ) ) {
			$this->current_inspection = $contract_id;
			$contract_id = null;
		}
		if ( 'initial' === $kind && null === $contract_id && $this->current_initial_contract_id ) {
			$contract_id = $this->current_initial_contract_id;
		}
		$summary = $this->summarize_inspection( is_array( $this->current_inspection ) ? $this->current_inspection : array() );
		return array(
			'target_type' => $kind,
			'local_attempt_id' => $attempt_id,
			'local_contract_id' => $contract_id,
			'verification_status' => $eligible ? 'validation_passed' : 'review_required',
			'inspection_status' => $summary['status'],
			'approval_eligible' => (bool) $eligible,
			'approved' => false,
			'automatic_approval_candidate' => false,
			'reason_codes' => $eligible ? array() : array( (string) $reason ),
			'payment_evidence' => $verified['payment_evidence'] ?? $summary['payment_evidence'],
			'subscription_link' => $verified['subscription_link'] ?? $summary['subscription_link'],
			'invoice_verification' => $verified['invoice_verification'] ?? $summary['invoice_verification'],
			'period_candidate' => $verified['period_candidate'] ?? $summary['period_candidate'],
			'subscription_verification' => $verified['subscription_verification'] ?? $summary['subscription_verification'],
			'evidence_gaps' => $summary['evidence_gaps'],
			'timezone' => $verified['timezone'] ?? $summary['timezone'],
			'monthly_billing_anchor_date' => $verified['monthly_billing_anchor_date'] ?? $summary['monthly_billing_anchor_date'],
			'contract_creation_candidate' => $verified['contract_creation_candidate'] ?? false,
			'requires_revalidation_in_write_transaction' => true,
		);
	}

	/** Return only the non-sensitive evidence needed by the admin preflight. */
	private function summarize_inspection( array $inspection ) {
		$initial = $inspection['initial_payment'] ?? array();
		$recurring = $inspection['payment_evidence'] ?? array();
		$invoice_results = is_array( $recurring['invoice_results'] ?? null ) ? $recurring['invoice_results'] : array();
		$invoice_result = 1 === count( $invoice_results ) && is_array( $invoice_results[0] ) ? $invoice_results[0] : array();
		$payment_evidence = array(
			'confirmed' => ! empty( $initial['confirmed'] ) || ! empty( $recurring['confirmed'] ),
			'status' => $initial['payment_status'] ?? ( $recurring['status'] ?? null ),
			'refund_status' => $initial['refund_status'] ?? ( ! empty( $recurring['confirmed'] ) ? 'none_reported_by_recurring_inspection' : 'unknown' ),
			'payment_id' => $initial['payment_id'] ?? ( $invoice_result['payment']['id'] ?? null ),
			'order_id' => $initial['order_id'] ?? ( $invoice_result['invoice']['order_id'] ?? null ),
			'invoice_id' => $initial['invoice_id'] ?? ( $invoice_result['invoice']['id'] ?? null ),
			'amount' => $initial['amount'] ?? ( $invoice_result['payment']['total_amount'] ?? null ),
			'currency' => $initial['currency'] ?? ( $invoice_result['payment']['currency'] ?? null ),
			'refund_detected' => ! empty( $initial['refund_detected'] ) || 'detected' === ( $initial['refund_status'] ?? null ) || 'refund_detected' === ( $invoice_result['reason_code'] ?? null ),
		);
		$link = $inspection['subscription_link'] ?? array();
		$conditions = $inspection['billing_conditions'] ?? array();
		$subscription = $conditions['subscription'] ?? ( $inspection['subscription'] ?? array() );
		$variation = $conditions['variation'] ?? ( $inspection['variation'] ?? array() );
		$scope = $inspection['invoice_scope'] ?? array();
		$period = $inspection['period_candidate'] ?? array();
		$subscription_link = array(
			'status' => $link['status'] ?? ( isset( $subscription['id'] ) ? 'identified_from_local_contract' : null ),
			'method' => $link['method'] ?? null,
			'subscription_id' => $link['subscription_id'] ?? ( $subscription['id'] ?? null ),
		);
		$invoice_verification = array(
			'verified' => ! empty( $inspection['invoice_payment']['verified'] ) || ( ! empty( $invoice_result ) && 'confirmed' === ( $invoice_result['payment_evidence'] ?? null ) ),
			'invoice_id' => $inspection['invoice_payment']['invoice_id'] ?? ( $invoice_result['invoice']['id'] ?? null ),
			'payment_id' => $inspection['invoice_payment']['payment_id'] ?? ( $invoice_result['payment']['id'] ?? null ),
			'order_id' => $inspection['invoice_payment']['order_id'] ?? ( $invoice_result['invoice']['order_id'] ?? null ),
			'scope' => array(
				'selection' => $scope['selection'] ?? null,
				'baseline_invoice_id' => $scope['baseline_invoice_id'] ?? null,
				'invoice_total_count' => $scope['invoice_total_count'] ?? null,
				'requested_invoice_count' => $scope['requested_invoice_count'] ?? null,
				'checked_invoice_count' => $scope['checked_invoice_count'] ?? null,
				'unchecked_invoice_count' => $scope['unchecked_invoice_count'] ?? null,
				'complete' => ! empty( $inspection['event_scope']['complete'] ) && 'square_subscription_invoice_ids_field_newest_first' === ( $scope['invoice_list_completeness'] ?? null ) && ( $scope['requested_invoice_count'] ?? null ) === ( $scope['checked_invoice_count'] ?? null ) && 0 === ( $scope['unchecked_invoice_count'] ?? null ),
			),
		);
		$gaps = array();
		foreach ( array( $inspection, $initial, $link, $inspection['invoice_payment'] ?? array(), $conditions, $period, $invoice_result ) as $source ) {
			if ( is_array( $source ) && is_string( $source['reason_code'] ?? null ) && ! in_array( $source['reason_code'], array( 'payment_and_period_candidate_require_administrator_approval', 'monthly_anchor_candidate', 'initial_invoice_and_checkout_payment_identical', 'invoice_order_payment_verified', 'payment_verified_period_candidate_available' ), true ) && preg_match( '/\A[a-z0-9_]{1,100}\z/', $source['reason_code'] ) ) {
				$gaps[] = $source['reason_code'];
			}
		}
		return array(
			'status' => $inspection['status'] ?? null,
			'payment_evidence' => $payment_evidence,
			'subscription_link' => $subscription_link,
			'invoice_verification' => $invoice_verification,
			'period_candidate' => is_array( $period ) ? $period : null,
			'subscription_verification' => array(
				'status' => $conditions['status'] ?? ( $inspection['status'] ?? null ),
				'subscription_id' => $subscription['id'] ?? null,
				'plan_variation_id' => $variation['id'] ?? ( $subscription['plan_variation_id'] ?? null ),
				'variation_confirmed' => ! empty( $conditions['confirmed'] ) || ! empty( $variation['single_monthly_phase'] ),
				'schedule_review' => $inspection['schedule_review'] ?? null,
				'event_scope' => $inspection['event_scope'] ?? null,
			),
			'evidence_gaps' => array_values( array_unique( $gaps ) ),
			'timezone' => $subscription['timezone'] ?? ( $period['billing_period']['timezone'] ?? null ),
			'monthly_billing_anchor_date' => $subscription['monthly_billing_anchor_date'] ?? null,
		);
	}

	/** Re-check the normalized scope returned by the recurring read service. */
	private function recurring_inspection_matches_contract( array $inspection, array $contract ) {
		$identity = $inspection['contract'] ?? null;
		$subscription = $inspection['subscription'] ?? null;
		$variation = $inspection['variation'] ?? null;
		if ( ! is_array( $identity ) || ! is_array( $subscription ) || ! is_array( $variation ) ) {
			return false;
		}
		$expected = array(
			'id' => (string) $contract['id'],
			'user_id' => (string) $contract['user_id'],
			'environment' => (string) $contract['environment'],
			'merchant_id' => (string) $contract['merchant_id'],
			'location_id' => (string) $contract['square_location_id'],
			'customer_id' => (string) $contract['square_customer_id'],
			'subscription_id' => (string) $contract['square_subscription_id'],
			'plan_variation_id' => (string) $contract['square_plan_variation_id'],
		);
		foreach ( $expected as $key => $value ) {
			if ( ! isset( $identity[ $key ] ) || ! is_scalar( $identity[ $key ] ) || ! hash_equals( $value, (string) $identity[ $key ] ) ) {
				return false;
			}
		}
		return isset( $subscription['id'], $variation['id'] )
			&& is_string( $subscription['id'] ) && hash_equals( $contract['square_subscription_id'], $subscription['id'] )
			&& is_string( $variation['id'] ) && hash_equals( $contract['square_plan_variation_id'], $variation['id'] );
	}

	private function get_attempt( $id ) {
		$table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_approval_database_unavailable', '申込情報を確認できません。' );
		}
		return $row;
	}

	private function get_contract( $id ) {
		$table = $this->wpdb->prefix . 'egov_law_subscriptions';
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_approval_database_unavailable', '契約情報を確認できません。' );
		}
		return $row;
	}

	private function get_contracts_for_attempt( $uuid ) {
		$table = $this->wpdb->prefix . 'egov_law_subscriptions';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE checkout_attempt_uuid = %s ORDER BY id ASC", $uuid ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_approval_database_unavailable', '申込に関連する契約を確認できません。' );
		}
		return $rows;
	}

	private function valid_attempt( array $attempt ) {
		return isset( $attempt['id'], $attempt['user_id'], $attempt['attempt_uuid'], $attempt['square_order_id'], $attempt['environment'], $attempt['merchant_id'], $attempt['square_location_id'], $attempt['subscription_plan_variation_id'], $attempt['status'], $attempt['plan_code'] )
			&& (int) $attempt['id'] > 0 && (int) $attempt['user_id'] > 0 && get_userdata( (int) $attempt['user_id'] )
			&& 'standard' === $attempt['plan_code'] && in_array( $attempt['status'], array( 'pending', 'reconciliation_required' ), true )
			&& is_string( $attempt['attempt_uuid'] ) && (bool) preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/i', $attempt['attempt_uuid'] )
			&& is_string( $attempt['square_order_id'] ) && '' !== $attempt['square_order_id']
			&& in_array( $attempt['environment'], array( 'sandbox', 'production' ), true ) && is_string( $attempt['merchant_id'] ) && '' !== $attempt['merchant_id'] && is_string( $attempt['square_location_id'] ) && '' !== $attempt['square_location_id'] && is_string( $attempt['subscription_plan_variation_id'] ) && '' !== $attempt['subscription_plan_variation_id'];
	}

	private function valid_contract( array $contract ) {
		if ( ! isset( $contract['id'], $contract['user_id'], $contract['environment'], $contract['merchant_id'], $contract['square_location_id'], $contract['square_customer_id'], $contract['square_subscription_id'], $contract['square_plan_variation_id'], $contract['plan_code'], $contract['internal_status'], $contract['square_status'], $contract['current_slot'], $contract['checkout_attempt_uuid'], $contract['current_period_start'], $contract['current_period_end'] ) ) {
			return false;
		}
		foreach ( array( 'merchant_id', 'square_location_id', 'square_customer_id', 'square_subscription_id', 'square_plan_variation_id' ) as $field ) {
			if ( ! is_string( $contract[ $field ] ) || '' === $contract[ $field ] ) {
				return false;
			}
		}
		return (int) $contract['id'] > 0 && (int) $contract['user_id'] > 0 && get_userdata( (int) $contract['user_id'] ) && 'standard' === $contract['plan_code']
			&& in_array( $contract['environment'], array( 'sandbox', 'production' ), true ) && in_array( $contract['internal_status'], array( 'active', 'pending', 'cancel_scheduled' ), true ) && 1 === (int) $contract['current_slot']
			&& 'unknown' !== strtolower( (string) $contract['square_status'] ) && (bool) preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/i', (string) $contract['checkout_attempt_uuid'] );
	}

	private function same_initial_attempt( array $a, array $b ) {
		foreach ( array( 'id', 'user_id', 'attempt_uuid', 'square_order_id', 'environment', 'merchant_id', 'square_location_id', 'subscription_plan_variation_id', 'status', 'plan_code' ) as $field ) {
			if ( ! array_key_exists( $field, $a ) || ! array_key_exists( $field, $b ) || (string) $a[ $field ] !== (string) $b[ $field ] ) {
				return false;
			}
		}
		if ( array_key_exists( 'square_customer_id', $a ) !== array_key_exists( 'square_customer_id', $b ) || ( array_key_exists( 'square_customer_id', $a ) && (string) $a['square_customer_id'] !== (string) $b['square_customer_id'] ) ) {
			return false;
		}
		return true;
	}

	private function same_contract_snapshot( array $a, array $b ) {
		foreach ( array( 'id', 'user_id', 'environment', 'merchant_id', 'square_location_id', 'square_customer_id', 'square_subscription_id', 'square_plan_variation_id', 'plan_code', 'internal_status', 'square_status', 'current_slot', 'checkout_attempt_uuid', 'current_period_start', 'current_period_end' ) as $field ) {
			if ( ! array_key_exists( $field, $a ) || ! array_key_exists( $field, $b ) || (string) $a[ $field ] !== (string) $b[ $field ] ) {
				return false;
			}
		}
		return true;
	}

	private function contract_matches_initial( array $contract, array $attempt, $subscription_id ) {
		if ( ! $this->contract_matches_attempt( $contract, $attempt ) ) {
			return false;
		}
		return isset( $contract['square_subscription_id'] ) && hash_equals( (string) $subscription_id, (string) $contract['square_subscription_id'] );
	}

	private function contract_matches_attempt( array $contract, array $attempt ) {
		$expected = array(
			'user_id' => (string) $attempt['user_id'], 'environment' => $attempt['environment'], 'merchant_id' => $attempt['merchant_id'],
			'square_location_id' => $attempt['square_location_id'], 'square_plan_variation_id' => $attempt['subscription_plan_variation_id'],
			'checkout_attempt_uuid' => $attempt['attempt_uuid'],
		);
		foreach ( $expected as $key => $value ) {
			if ( ! isset( $contract[ $key ] ) || ! hash_equals( (string) $value, (string) $contract[ $key ] ) ) {
				return false;
			}
		}
		if ( ! isset( $contract['square_customer_id'] ) || ! is_string( $contract['square_customer_id'] ) || '' === $contract['square_customer_id'] ) {
			return false;
		}
		if ( ! empty( $attempt['square_customer_id'] ) && ( ! is_string( $attempt['square_customer_id'] ) || ! hash_equals( $attempt['square_customer_id'], $contract['square_customer_id'] ) ) ) {
			return false;
		}
		return 'standard' === ( $contract['plan_code'] ?? null ) && 'pending' === ( $contract['internal_status'] ?? null ) && array_key_exists( 'current_slot', $contract ) && null === $contract['current_slot'];
	}

	private function has_other_current_contract( $user_id, $environment, $exclude_contract_id ) {
		$table = $this->wpdb->prefix . 'egov_law_subscriptions';
		$sql = "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND environment = %s AND current_slot = 1";
		$args = array( $user_id, $environment );
		if ( null !== $exclude_contract_id ) {
			$sql .= ' AND id <> %d';
			$args[] = $exclude_contract_id;
		}
		$this->wpdb->last_error = '';
		$count = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $args ) );
		if ( '' !== (string) $this->wpdb->last_error || null === $count || false === $count ) {
			return new WP_Error( 'square_approval_database_unavailable', '現行契約を確認できません。' );
		}
		return (int) $count > 0;
	}

	private function has_prior_payment_approval( $environment, $merchant_id, $payment_id ) {
		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		$this->wpdb->last_error = '';
		$id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$table} WHERE environment = %s AND merchant_id = %s AND square_payment_id = %s AND action_type IN ('approve','correct') LIMIT 1", $environment, $merchant_id, $payment_id ) );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_approval_database_unavailable', '決済の承認履歴を確認できません。' );
		}
		return null !== $id;
	}

	private function has_duplicate_invoice_approval( $environment, $merchant_id, $invoice_id ) {
		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		$this->wpdb->last_error = '';
		$id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$table} WHERE environment = %s AND merchant_id = %s AND square_invoice_id = %s AND action_type IN ('approve','correct','hold') LIMIT 1", $environment, $merchant_id, $invoice_id ) );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_approval_database_unavailable', '請求の承認履歴を確認できません。' );
		}
		return null !== $id;
	}

	private function latest_contract_approval( array $reviews, array $contract ) {
		foreach ( $reviews as $review ) {
			if ( ! in_array( $review['action_type'] ?? null, array( 'approve', 'correct' ), true ) ) {
				continue;
			}
			if ( (int) ( $review['contract_id'] ?? 0 ) !== (int) $contract['id'] || (int) ( $review['user_id'] ?? 0 ) !== (int) $contract['user_id'] || ( $review['environment'] ?? null ) !== $contract['environment'] || ( $review['merchant_id'] ?? null ) !== $contract['merchant_id'] ) {
				return null;
			}
			return $review;
		}
		return null;
	}

	private function approval_matches_contract_period( array $review, array $contract ) {
		if ( ! $this->valid_timezone( $review['subscription_timezone'] ?? null ) || ! $this->valid_local_boundary( $review['approved_period_start'] ?? null ) || ! $this->valid_local_boundary( $review['approved_period_end'] ?? null ) ) {
			return false;
		}
		$timezone = new DateTimeZone( $review['subscription_timezone'] );
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $review['approved_period_start'], $timezone );
		$end = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $review['approved_period_end'], $timezone );
		if ( false === $start || false === $end || $start >= $end || ! isset( $review['monthly_billing_anchor_date'] ) || (int) $review['monthly_billing_anchor_date'] < 1 || (int) $review['monthly_billing_anchor_date'] > 31 ) {
			return false;
		}
		return $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) === $contract['current_period_start']
			&& $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) === $contract['current_period_end'];
	}

	private function is_admin_suspended( array $reviews ) {
		foreach ( $reviews as $review ) {
			if ( in_array( $review['action_type'] ?? null, array( 'suspend', 'resume' ), true ) ) {
				return 'suspend' === $review['action_type'];
			}
		}
		return false;
	}

	private function validate_candidate( $candidate, $type, $expected_timezone, $expected_anchor ) {
		if ( ! is_array( $candidate ) || 'candidate' !== ( $candidate['status'] ?? null ) || $type !== ( $candidate['period_type'] ?? null ) || ! is_array( $candidate['billing_period'] ?? null ) ) {
			return new WP_Error( is_array( $candidate ) && is_string( $candidate['reason_code'] ?? null ) ? $candidate['reason_code'] : 'billing_period_candidate_unavailable' );
		}
		$period = $candidate['billing_period'];
		foreach ( array( 'start_date', 'end_exclusive_date', 'next_start_date', 'timezone', 'interval' ) as $key ) {
			if ( ! isset( $period[ $key ] ) || ! is_string( $period[ $key ] ) ) {
				return new WP_Error( 'billing_period_candidate_incomplete' );
			}
		}
		if ( 'half_open' !== $period['interval'] || ! $this->valid_date( $period['start_date'] ) || ! $this->valid_date( $period['end_exclusive_date'] ) || ! $this->valid_date( $period['next_start_date'] ) || $period['start_date'] >= $period['end_exclusive_date'] || $period['next_start_date'] !== $period['end_exclusive_date'] || ! $this->valid_timezone( $period['timezone'] ) || $period['timezone'] !== $expected_timezone || ! is_int( $expected_anchor ) || $expected_anchor < 1 || $expected_anchor > 31 ) {
			return new WP_Error( 'billing_period_candidate_invalid' );
		}
		return array(
			'status' => 'candidate',
			'period_type' => $type,
			'start_date' => $period['start_date'],
			'end_date_inclusive' => $period['end_date_inclusive'] ?? null,
			'end_exclusive_date' => $period['end_exclusive_date'],
			'next_start_date' => $period['next_start_date'],
			'timezone' => $period['timezone'],
			'monthly_billing_anchor_date' => $expected_anchor,
			'basis' => is_array( $candidate['basis'] ?? null ) ? $candidate['basis'] : array(),
			'payment_period_confirmed' => false,
		);
	}

	private function inspection_reason( array $inspection, $fallback ) {
		$reason = $inspection['reason_code'] ?? null;
		return is_string( $reason ) && preg_match( '/\A[a-z0-9_]{1,100}\z/', $reason ) ? $reason : $fallback;
	}

	private function valid_local_boundary( $value ) {
		return is_string( $value ) && preg_match( '/\A\d{4}-\d{2}-\d{2} 00:00:00\z/', $value ) && $this->valid_sql_datetime( $value );
	}

	private function valid_sql_datetime( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );
		$errors = DateTimeImmutable::getLastErrors();
		return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function valid_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $match ) ) {
			return false;
		}
		return checkdate( (int) $match[2], (int) $match[3], (int) $match[1] );
	}

	private function valid_timezone( $value ) {
		return is_string( $value ) && ( 'UTC' === $value || in_array( $value, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true ) );
	}

	private function valid_square_id( $value, $max_length ) {
		return is_string( $value ) && strlen( $value ) <= $max_length && (bool) preg_match( '/\A[A-Za-z0-9._:-]{1,' . (int) $max_length . '}\z/', $value );
	}
}
