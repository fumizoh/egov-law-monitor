<?php

/** Atomically apply an explicit administrator approval after fresh Square validation. */
class Egov_Law_Monitor_Square_Admin_Approval_Service {
	private const PRICE = 500;
	private const CURRENCY = 'JPY';
	private $wpdb;
	private $validation_service;
	private $review_repository;

	public function __construct( $wpdb, Egov_Law_Monitor_Square_Admin_Approval_Validation_Service $validation_service, Egov_Law_Monitor_Square_Admin_Review_Repository $review_repository ) {
		$this->wpdb = $wpdb;
		$this->validation_service = $validation_service;
		$this->review_repository = $review_repository;
	}

	/** Approve a freshly verified initial payment. */
	public function approve_initial( $attempt_id, $admin_user_id, $admin_note = null ) {
		if ( ! $this->authorized( $attempt_id, $admin_user_id ) ) {
			return new WP_Error( 'square_admin_approval_forbidden', '承認する権限がありません。' );
		}
		$before = $this->read_attempt( $attempt_id );
		if ( is_wp_error( $before ) || ! is_array( $before ) ) {
			return is_wp_error( $before ) ? $before : new WP_Error( 'square_admin_approval_attempt_not_found', '申込情報が見つかりません。' );
		}
		try {
			$validation = $this->validation_service->validate_initial_approval( $attempt_id );
		} catch ( Throwable $error ) {
			return new WP_Error( 'square_admin_approval_validation_failed', '最新の決済情報を確認できません。' );
		}
		if ( is_wp_error( $validation ) ) {
			return new WP_Error( 'square_admin_approval_validation_failed', '最新の決済情報を確認できません。', array( 'source_code' => $validation->get_error_code() ) );
		}
		if ( ! is_array( $validation ) || empty( $validation['approval_eligible'] ) || ! empty( $validation['approved'] ) ) {
			return $this->not_eligible( $validation );
		}
		if ( (int) ( $validation['local_attempt_id'] ?? 0 ) !== $attempt_id ) {
			return new WP_Error( 'square_admin_approval_local_state_changed', '検証対象の申込が一致しません。' );
		}
		$context = $this->prepare_context( $validation, 'initial' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$engines = $this->require_innodb( array( $this->wpdb->users, $this->attempts_table(), $this->subscriptions_table(), $this->invoices_table(), $this->reviews_table() ) );
		if ( is_wp_error( $engines ) ) {
			return $engines;
		}
		return $this->persist_initial( $before, $context, $admin_user_id, $admin_note );
	}

	/** Approve one freshly verified recurring invoice for a current local contract. */
	public function approve_recurring( $contract_id, $admin_user_id, $admin_note = null ) {
		if ( ! $this->authorized( $contract_id, $admin_user_id ) ) {
			return new WP_Error( 'square_admin_approval_forbidden', '承認する権限がありません。' );
		}
		$before = $this->read_contract( $contract_id );
		if ( is_wp_error( $before ) || ! is_array( $before ) ) {
			return is_wp_error( $before ) ? $before : new WP_Error( 'square_admin_approval_contract_not_found', '契約情報が見つかりません。' );
		}
		$invoice_snapshot = $this->read_contract_invoices( $contract_id );
		if ( is_wp_error( $invoice_snapshot ) ) {
			return $invoice_snapshot;
		}
		try {
			$validation = $this->validation_service->validate_recurring_approval( $contract_id );
		} catch ( Throwable $error ) {
			return new WP_Error( 'square_admin_approval_validation_failed', '最新の決済情報を確認できません。' );
		}
		if ( is_wp_error( $validation ) ) {
			return new WP_Error( 'square_admin_approval_validation_failed', '最新の決済情報を確認できません。', array( 'source_code' => $validation->get_error_code() ) );
		}
		if ( ! is_array( $validation ) || empty( $validation['approval_eligible'] ) || ! empty( $validation['approved'] ) ) {
			return $this->not_eligible( $validation );
		}
		if ( (int) ( $validation['local_contract_id'] ?? 0 ) !== $contract_id ) {
			return new WP_Error( 'square_admin_approval_local_state_changed', '検証対象の契約が一致しません。' );
		}
		$context = $this->prepare_context( $validation, 'recurring' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$engines = $this->require_innodb( array( $this->wpdb->users, $this->attempts_table(), $this->subscriptions_table(), $this->invoices_table(), $this->reviews_table() ) );
		if ( is_wp_error( $engines ) ) {
			return $engines;
		}
		return $this->persist_recurring( $before, $invoice_snapshot, $context, $admin_user_id, $admin_note );
	}

	/** Reconcile a COMMIT-uncertain result using only the exact local row IDs returned with that error. */
	public function reconcile_commit_outcome( $target, $admin_user_id ) {
		if ( ! is_array( $target ) || ! in_array( $target['type'] ?? null, array( 'initial', 'recurring' ), true ) || ! is_int( $target['local_id'] ?? null ) || $target['local_id'] < 1 || ! is_int( $target['contract_id'] ?? null ) || $target['contract_id'] < 1 || ! is_int( $target['invoice_row_id'] ?? null ) || $target['invoice_row_id'] < 1 || ! is_int( $target['review_id'] ?? null ) || $target['review_id'] < 1 || ! $this->authorized( $target['local_id'], $admin_user_id ) ) {
			return new WP_Error( 'square_admin_approval_forbidden', '照合する権限または対象情報がありません。' );
		}
		$review = $this->read_review( $target['review_id'] );
		$contract = $this->read_contract( $target['contract_id'] );
		$invoice = $this->read_row_by_id( $this->invoices_table(), $target['invoice_row_id'] );
		if ( is_wp_error( $review ) || is_wp_error( $contract ) || is_wp_error( $invoice ) ) {
			return new WP_Error( 'square_admin_approval_reconciliation_unavailable', '承認結果を再照合できません。' );
		}
		if ( null === $review ) {
			if ( is_array( $contract ) && is_array( $invoice ) && $this->target_rows_look_committed( $target, $contract, $invoice ) ) {
				return array( 'status' => 'inconsistent', 'reason_code' => 'approval_review_missing_for_updated_rows' );
			}
			return array( 'status' => 'not_approved', 'reason_code' => 'approval_review_not_found' );
		}
		if ( ! is_array( $contract ) || ! is_array( $invoice ) || ! $this->target_review_matches( $target, $review, $contract, $invoice ) ) {
			return array( 'status' => 'inconsistent', 'reason_code' => 'approval_records_do_not_match' );
		}
		return array( 'status' => 'approved', 'reason_code' => 'approval_records_match', 'contract_id' => (int) $contract['id'], 'invoice_row_id' => (int) $invoice['id'], 'review_id' => (int) $review['id'] );
	}

	private function authorized( $local_id, $admin_user_id ) {
		return is_int( $local_id ) && $local_id > 0 && is_int( $admin_user_id ) && $admin_user_id > 0
			&& get_current_user_id() === $admin_user_id && current_user_can( 'manage_options' )
			&& (bool) get_userdata( $admin_user_id );
	}

	private function prepare_context( array $validation, $kind ) {
		if ( ( 'initial' !== $kind && 'recurring' !== $kind ) || ! isset( $validation['target_type'], $validation['period_candidate'], $validation['timezone'], $validation['monthly_billing_anchor_date'], $validation['payment_evidence'] ) || $validation['target_type'] !== $kind || ! is_int( $validation['monthly_billing_anchor_date'] ) || $validation['monthly_billing_anchor_date'] < 1 || $validation['monthly_billing_anchor_date'] > 31 ) {
			return new WP_Error( 'square_admin_approval_evidence_incomplete', '承認に必要な確認情報が不足しています。' );
		}
		$candidate = $validation['period_candidate'];
		$expected_period_type = 'initial' === $kind ? 'initial' : 'renewal';
		if ( ! is_array( $candidate ) || 'candidate' !== ( $candidate['status'] ?? null ) || $expected_period_type !== ( $candidate['period_type'] ?? null ) || ! isset( $candidate['start_date'], $candidate['end_exclusive_date'], $candidate['timezone'] ) || ! is_string( $candidate['start_date'] ) || ! is_string( $candidate['end_exclusive_date'] ) || ! is_string( $candidate['timezone'] ) || $candidate['timezone'] !== $validation['timezone'] || ! $this->valid_date( $candidate['start_date'] ) || ! $this->valid_date( $candidate['end_exclusive_date'] ) || $candidate['start_date'] >= $candidate['end_exclusive_date'] || ! $this->valid_timezone( $candidate['timezone'] ) ) {
			return new WP_Error( 'square_admin_approval_period_invalid', '承認期間を確認できません。' );
		}
		if ( empty( $validation['payment_evidence']['confirmed'] ) || (int) ( $validation['payment_evidence']['amount'] ?? 0 ) !== self::PRICE || ( $validation['payment_evidence']['currency'] ?? null ) !== self::CURRENCY ) {
			return new WP_Error( 'square_admin_approval_payment_unconfirmed', '決済完了を確認できません。' );
		}
		$payment_id = $validation['payment_evidence']['payment_id'] ?? null;
		$invoice_id = $validation['invoice_verification']['invoice_id'] ?? ( $validation['payment_evidence']['invoice_id'] ?? null );
		$subscription_id = $validation['subscription_link']['subscription_id'] ?? ( $validation['subscription_verification']['subscription_id'] ?? null );
		if ( ! $this->valid_square_id( $payment_id, 192 ) || ! $this->valid_square_id( $invoice_id, 255 ) || ! $this->valid_square_id( $subscription_id, 255 ) ) {
			return new WP_Error( 'square_admin_approval_identifiers_invalid', '決済・契約の識別情報を確認できません。' );
		}
		if ( ! isset( $validation['invoice_verification']['verified'] ) || true !== $validation['invoice_verification']['verified'] ) {
			return new WP_Error( 'square_admin_approval_invoice_unverified', '請求情報との対応を確認できません。' );
		}
		$utc_start = $this->local_boundary_to_utc( $candidate['start_date'], $candidate['timezone'] );
		$utc_end = $this->local_boundary_to_utc( $candidate['end_exclusive_date'], $candidate['timezone'] );
		if ( ! is_string( $utc_start ) || ! is_string( $utc_end ) || $utc_start >= $utc_end ) {
			return new WP_Error( 'square_admin_approval_period_invalid', '承認期間をUTCへ変換できません。' );
		}
		return array(
			'kind' => $kind,
			'validation' => $validation,
			'candidate' => $candidate,
			'payment_id' => $payment_id,
			'invoice_id' => $invoice_id,
			'subscription_id' => $subscription_id,
			'timezone' => $candidate['timezone'],
			'anchor' => $validation['monthly_billing_anchor_date'],
			'local_start' => $candidate['start_date'] . ' 00:00:00',
			'local_end' => $candidate['end_exclusive_date'] . ' 00:00:00',
			'utc_start' => $utc_start,
			'utc_end' => $utc_end,
			'note' => $this->normalize_note( $validation['admin_note'] ?? null ),
		);
	}

	private function persist_initial( array $before, array $ctx, $admin_user_id, $admin_note ) {
		$tx = $this->begin_transaction();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$user = $this->lock_user( (int) $before['user_id'] );
			if ( is_wp_error( $user ) ) {
				return $this->rollback_error( $user );
			}
			$attempt = $this->lock_attempt( (int) $before['id'] );
			if ( is_wp_error( $attempt ) ) {
				return $this->rollback_error( $attempt );
			}
			if ( ! $this->same_attempt( $before, $attempt ) || (int) $attempt['user_id'] !== (int) $before['user_id'] ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_local_state_changed', '検証後に申込情報が変更されたため承認を中止しました。' ) );
			}
			$contracts = $this->lock_attempt_contracts( (string) $attempt['attempt_uuid'] );
			if ( is_wp_error( $contracts ) || count( $contracts ) > 1 ) {
				return $this->rollback_error( is_wp_error( $contracts ) ? $contracts : new WP_Error( 'square_admin_approval_contract_conflict', '申込に複数の契約が関連付いています。' ) );
			}
			$contract = empty( $contracts ) ? null : $contracts[0];
			$expected_contract_id = $ctx['validation']['local_contract_id'] ?? null;
			if ( ( null === $contract ) !== ( null === $expected_contract_id ) || ( null !== $contract && (int) $contract['id'] !== (int) $expected_contract_id ) ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_local_state_changed', '検証後に契約情報が変更されたため承認を中止しました。' ) );
			}
			if ( null === $contract && true !== ( $ctx['validation']['contract_creation_candidate'] ?? false ) ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_reference_mismatch', '契約を作成できる識別子の根拠がありません。' ) );
			}
			if ( $contract && ( 'pending' !== $contract['internal_status'] || null !== $contract['current_slot'] || ! $this->contract_matches_attempt( $contract, $attempt, $ctx ) || ! $this->initial_version_is_not_stale( $contract, $ctx ) ) ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_contract_conflict', '既存契約の状態または識別情報が一致しません。' ) );
			}
			$current = $this->lock_current_contracts( (int) $attempt['user_id'], $attempt['environment'], $contract ? (int) $contract['id'] : null );
			if ( is_wp_error( $current ) || ! empty( $current ) ) {
				return $this->rollback_error( is_wp_error( $current ) ? $current : new WP_Error( 'square_admin_approval_current_contract_conflict', '別の現行契約があるため承認できません。' ) );
			}
			$contract_id = $contract ? (int) $contract['id'] : $this->insert_initial_contract( $attempt, $ctx );
			if ( is_wp_error( $contract_id ) ) {
				return $this->rollback_error( $contract_id );
			}
			$invoice_id = $this->upsert_paid_invoice( (int) $contract_id, (int) $attempt['user_id'], $attempt['environment'], $attempt['merchant_id'], $ctx );
			if ( is_wp_error( $invoice_id ) ) {
				return $this->rollback_error( $invoice_id );
			}
			$reviews = $this->lock_attempt_reviews( (string) $attempt['attempt_uuid'] );
			if ( is_wp_error( $reviews ) ) {
				return $this->rollback_error( $reviews );
			}
			$control_seen = false;
			$admin_suspended = false;
			foreach ( $reviews as $review ) {
				if ( in_array( $review['action_type'], array( 'approve', 'correct' ), true ) ) {
					return $this->rollback_error( new WP_Error( 'square_admin_approval_duplicate', 'この初回決済は既に承認されています。' ) );
				}
				if ( 'hold' === $review['action_type'] && ( $review['square_payment_id'] ?? null ) === $ctx['payment_id'] ) {
					return $this->rollback_error( new WP_Error( 'square_admin_approval_held', 'この決済は保留中です。' ) );
				}
				if ( ! $control_seen && in_array( $review['action_type'], array( 'suspend', 'resume' ), true ) ) {
					$control_seen = true;
					$admin_suspended = 'suspend' === $review['action_type'];
				}
			}
			if ( $admin_suspended ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_admin_suspended', '管理者停止中の契約は通常承認できません。' ) );
			}
			if ( $contract && ! $this->update_initial_contract( (int) $contract_id, $ctx ) ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_database_error', '契約情報を更新できません。' ) );
			}
			$entry = $this->review_entry( $attempt, (int) $contract_id, (int) $invoice_id, $ctx, $admin_user_id, $admin_note, 'initial_payment_and_period_candidate_verified' );
			$review = $this->review_repository->append_review_in_transaction( $entry );
			if ( is_wp_error( $review ) ) {
				return $this->rollback_error( $this->map_review_error( $review ) );
			}
			return $this->commit_result( (int) $contract_id, (int) $invoice_id, $review, $ctx );
		} catch ( Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'square_admin_approval_database_error', '承認処理を完了できませんでした。' );
		}
	}

	private function persist_recurring( array $before, array $invoice_snapshot, array $ctx, $admin_user_id, $admin_note ) {
		$tx = $this->begin_transaction();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$user = $this->lock_user( (int) $before['user_id'] );
			if ( is_wp_error( $user ) ) {
				return $this->rollback_error( $user );
			}
			$attempt = $this->lock_attempt_by_uuid( (string) $before['checkout_attempt_uuid'] );
			if ( is_wp_error( $attempt ) ) {
				return $this->rollback_error( $attempt );
			}
			$contract = $this->lock_contract( (int) $before['id'] );
			if ( is_wp_error( $contract ) ) {
				return $this->rollback_error( $contract );
			}
			if ( ! $this->same_contract( $before, $contract ) || 1 !== (int) $contract['current_slot'] || ! $attempt || (int) $attempt['user_id'] !== (int) $contract['user_id'] || $attempt['attempt_uuid'] !== $contract['checkout_attempt_uuid'] ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_local_state_changed', '検証後に契約情報が変更されたため承認を中止しました。' ) );
			}
			if ( 'paused' === $contract['internal_status'] ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_admin_suspended', '管理者停止中の契約は通常承認できません。' ) );
			}
			$all_invoices = $this->lock_contract_invoices( (int) $contract['id'] );
			if ( is_wp_error( $all_invoices ) || ! $this->same_invoice_set( $invoice_snapshot, $all_invoices ) ) {
				return $this->rollback_error( is_wp_error( $all_invoices ) ? $all_invoices : new WP_Error( 'square_admin_approval_local_state_changed', '検証後に請求情報が変更されたため承認を中止しました。' ) );
			}
			$current = $this->lock_current_contracts( (int) $contract['user_id'], $contract['environment'], null );
			if ( is_wp_error( $current ) || 1 !== count( $current ) || (int) $current[0]['id'] !== (int) $contract['id'] ) {
				return $this->rollback_error( is_wp_error( $current ) ? $current : new WP_Error( 'square_admin_approval_current_contract_conflict', '対象契約が現行契約ではありません。' ) );
			}
			$invoice = $this->lock_invoice_by_square_id( $contract['environment'], $contract['merchant_id'], $ctx['invoice_id'] );
			if ( is_wp_error( $invoice ) ) {
				return $this->rollback_error( $invoice );
			}
			if ( is_array( $invoice ) && (int) $invoice['subscription_id'] !== (int) $contract['id'] ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_invoice_conflict', '請求情報が別の契約に関連付いています。' ) );
			}
			$payment_owner = $this->lock_payment_owner( $contract['environment'], $contract['merchant_id'], $ctx['payment_id'] );
			if ( is_wp_error( $payment_owner ) || ( null !== $payment_owner && ( ! is_array( $invoice ) || (int) $payment_owner['id'] !== (int) $invoice['id'] ) ) ) {
				return $this->rollback_error( is_wp_error( $payment_owner ) ? $payment_owner : new WP_Error( 'square_admin_approval_payment_conflict', '決済が別の請求情報に関連付いています。' ) );
			}
			$invoice_row_id = $this->upsert_paid_invoice( (int) $contract['id'], (int) $contract['user_id'], $contract['environment'], $contract['merchant_id'], $ctx );
			if ( is_wp_error( $invoice_row_id ) ) {
				return $this->rollback_error( $invoice_row_id );
			}
			$reviews = $this->lock_contract_reviews( (int) $contract['id'] );
			if ( is_wp_error( $reviews ) ) {
				return $this->rollback_error( $reviews );
			}
			$latest_approval = null;
			$control_seen = false;
			$admin_suspended = false;
			foreach ( $reviews as $review ) {
				if ( null === $latest_approval && in_array( $review['action_type'], array( 'approve', 'correct' ), true ) ) { $latest_approval = $review; }
				if ( 'hold' === $review['action_type'] && ( $review['square_invoice_id'] ?? null ) === $ctx['invoice_id'] ) {
					return $this->rollback_error( new WP_Error( 'square_admin_approval_held', 'この請求は保留中です。' ) );
				}
				if ( ! $control_seen && in_array( $review['action_type'], array( 'suspend', 'resume' ), true ) ) { $control_seen = true; $admin_suspended = 'suspend' === $review['action_type']; }
			}
			if ( $admin_suspended ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_admin_suspended', '管理者停止中の契約は通常承認できません。' ) );
			}
			if ( ! is_array( $latest_approval ) || (int) $latest_approval['id'] !== (int) ( $ctx['validation']['previous_review_id'] ?? 0 ) ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_local_state_changed', '最新の承認履歴が検証時から変化しました。' ) );
			}
			if ( ! $this->update_recurring_contract( $contract, $ctx ) ) {
				return $this->rollback_error( new WP_Error( 'square_admin_approval_database_error', '契約情報を更新できません。' ) );
			}
			$entry = $this->review_entry( $attempt, (int) $contract['id'], (int) $invoice_row_id, $ctx, $admin_user_id, $admin_note, 'recurring_invoice_and_period_candidate_verified' );
			$review = $this->review_repository->append_review_in_transaction( $entry );
			if ( is_wp_error( $review ) ) {
				return $this->rollback_error( $this->map_review_error( $review ) );
			}
			return $this->commit_result( (int) $contract['id'], (int) $invoice_row_id, $review, $ctx );
		} catch ( Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'square_admin_approval_database_error', '承認処理を完了できませんでした。' );
		}
	}

	private function insert_initial_contract( array $attempt, array $ctx ) {
		$sub = $ctx['validation']['verified_subscription'] ?? null;
		if ( ! is_array( $sub ) || ! isset( $sub['id'], $sub['customer_id'], $sub['location_id'], $sub['plan_variation_id'], $sub['status'] ) || $sub['id'] !== $ctx['subscription_id'] || $sub['location_id'] !== $attempt['square_location_id'] || $sub['plan_variation_id'] !== $attempt['subscription_plan_variation_id'] || ! $this->valid_square_id( $sub['customer_id'], 191 ) || 'ACTIVE' !== $sub['status'] ) {
			return new WP_Error( 'square_admin_approval_evidence_incomplete', '契約の確認済み識別情報が不足しています。' );
		}
		$data = array(
			'user_id' => (int) $attempt['user_id'], 'environment' => $attempt['environment'], 'merchant_id' => $attempt['merchant_id'],
			'square_location_id' => $sub['location_id'], 'square_customer_id' => $sub['customer_id'], 'square_subscription_id' => $sub['id'],
			'square_plan_variation_id' => $sub['plan_variation_id'], 'checkout_attempt_uuid' => $attempt['attempt_uuid'],
			'square_version' => ( isset( $sub['version'] ) && is_int( $sub['version'] ) && $sub['version'] >= 0 ) ? $sub['version'] : null,
			'plan_code' => 'standard', 'internal_status' => 'active', 'square_status' => 'ACTIVE', 'initial_payment_status' => 'succeeded',
			'current_slot' => 1, 'current_period_start' => $ctx['utc_start'], 'current_period_end' => $ctx['utc_end'],
			'sync_state' => 'ok', 'last_synced_at' => current_time( 'mysql', true ), 'sync_error_code' => '', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		);
		$this->wpdb->last_error = '';
		$inserted = $this->wpdb->insert( $this->subscriptions_table(), $data );
		if ( 1 !== $inserted || '' !== (string) $this->wpdb->last_error || (int) $this->wpdb->insert_id < 1 ) {
			return new WP_Error( 'square_admin_approval_current_contract_conflict', '契約を安全に作成できませんでした。' );
		}
		return (int) $this->wpdb->insert_id;
	}

	private function update_initial_contract( $contract_id, array $ctx ) {
		$data = array( 'internal_status' => 'active', 'initial_payment_status' => 'succeeded', 'square_status' => 'ACTIVE', 'current_slot' => 1, 'current_period_start' => $ctx['utc_start'], 'current_period_end' => $ctx['utc_end'], 'review_grace_ends_at' => null, 'sync_state' => 'ok', 'last_synced_at' => current_time( 'mysql', true ), 'sync_error_code' => '', 'updated_at' => current_time( 'mysql', true ) );
		$version = $ctx['validation']['verified_subscription']['version'] ?? null;
		if ( null !== $version ) { $data['square_version'] = $version; }
		return $this->conditional_update( $this->subscriptions_table(), $data, array( 'id' => $contract_id, 'internal_status' => 'pending', 'current_slot' => null ) );
	}

	private function initial_version_is_not_stale( array $contract, array $ctx ) {
		$version = $ctx['validation']['verified_subscription']['version'] ?? null;
		if ( null === $version ) { return true; }
		if ( ! is_int( $version ) || $version < 0 || ( null !== $contract['square_version'] && $version < (int) $contract['square_version'] ) ) { return false; }
		if ( null !== $contract['square_version'] && $version === (int) $contract['square_version'] && ( $ctx['validation']['verified_subscription']['status'] ?? null ) !== $contract['square_status'] ) { return false; }
		return true;
	}

	private function update_recurring_contract( array $contract, array $ctx ) {
		$square_status = $ctx['validation']['subscription_verification']['square_status'] ?? $contract['square_status'];
		if ( ! is_string( $square_status ) || ! in_array( $square_status, array( 'ACTIVE', 'PENDING', 'CANCELED', 'DEACTIVATED', 'PAUSED', 'COMPLETED' ), true ) ) {
			return false;
		}
		$version = $ctx['validation']['subscription_verification']['version'] ?? null;
		if ( null !== $version && ( ! is_int( $version ) || $version < 0 || ( null !== $contract['square_version'] && $version < (int) $contract['square_version'] ) ) ) {
			return false;
		}
		if ( null !== $version && null !== $contract['square_version'] && $version === (int) $contract['square_version'] && $square_status !== $contract['square_status'] ) {
			return false;
		}
		$internal = $contract['internal_status'];
		if ( in_array( $square_status, array( 'CANCELED', 'DEACTIVATED', 'COMPLETED' ), true ) ) {
			$internal = 'canceled';
		} elseif ( 'ACTIVE' === $square_status && 'cancel_scheduled' !== $internal && 'canceled' !== $internal ) {
			$internal = 'active';
		}
		$data = array( 'internal_status' => $internal, 'square_status' => $square_status, 'current_period_start' => $ctx['utc_start'], 'current_period_end' => $ctx['utc_end'], 'sync_state' => 'ok', 'last_synced_at' => current_time( 'mysql', true ), 'sync_error_code' => '', 'updated_at' => current_time( 'mysql', true ) );
		if ( null !== $version ) {
			$data['square_version'] = $version;
		}
		$updated = $this->conditional_update( $this->subscriptions_table(), $data, array( 'id' => (int) $contract['id'], 'user_id' => (int) $contract['user_id'], 'environment' => $contract['environment'], 'merchant_id' => $contract['merchant_id'], 'square_subscription_id' => $contract['square_subscription_id'], 'current_slot' => 1 ) );
		if ( ! $updated ) {
			return false;
		}
		$this->wpdb->last_error = '';
		$unresolved = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->invoices_table()} WHERE subscription_id = %d AND payment_status NOT IN ('paid','succeeded','completed')", (int) $contract['id'] ) );
		if ( '' !== (string) $this->wpdb->last_error || null === $unresolved ) {
			return false;
		}
		if ( 0 === (int) $unresolved ) {
			$this->wpdb->last_error = '';
			$clear = $this->wpdb->update( $this->subscriptions_table(), array( 'review_grace_ends_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $contract['id'] ), array( '%s', '%s' ), array( '%d' ) );
			if ( false === $clear || '' !== (string) $this->wpdb->last_error ) {
				return false;
			}
		}
		return true;
	}

	private function upsert_paid_invoice( $contract_id, $user_id, $environment, $merchant_id, array $ctx ) {
		$verified = $ctx['validation']['verified_invoice'] ?? null;
		if ( ! is_array( $verified ) ) {
			return new WP_Error( 'square_admin_approval_evidence_incomplete', '確認済み請求情報がありません。' );
		}
		$invoice_id = $verified['id'] ?? null;
		$payment_id = $ctx['payment_id'];
		$amount = $verified['amount'] ?? self::PRICE;
		$completed = $verified['completed_amount'] ?? self::PRICE;
		$currency = $verified['currency'] ?? self::CURRENCY;
		$invoice_status = $verified['status'] ?? null;
		$payment_state = $verified['payment_state'] ?? 'paid';
		$invoice_version = $verified['version'] ?? null;
		if ( $invoice_id !== $ctx['invoice_id'] || ! $this->valid_square_id( $invoice_id, 255 ) || ! $this->valid_square_id( $payment_id, 192 ) || self::PRICE !== $amount || self::PRICE !== $completed || self::CURRENCY !== $currency || ! is_string( $invoice_status ) || '' === $invoice_status || ! in_array( $payment_state, array( 'paid', 'PAID' ), true ) ) {
			return new WP_Error( 'square_admin_approval_invoice_unverified', '請求額・状態を確認できません。' );
		}
		$table = $this->invoices_table();
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE environment = %s AND merchant_id = %s AND square_invoice_id = %s FOR UPDATE", $environment, $merchant_id, $invoice_id ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error || count( $rows ) > 1 ) {
			return new WP_Error( 'square_admin_approval_database_error', '請求情報を確認できません。' );
		}
		$row = $rows[0] ?? null;
		if ( is_array( $row ) && ( (int) $row['subscription_id'] !== $contract_id || (int) $row['user_id'] !== $user_id || $row['environment'] !== $environment || $row['merchant_id'] !== $merchant_id || ( ! empty( $row['square_payment_id'] ) && $row['square_payment_id'] !== $payment_id ) ) ) {
			return new WP_Error( 'square_admin_approval_invoice_conflict', '請求情報が別の決済または契約に関連付いています。' );
		}
		if ( null !== $invoice_version && ( ! is_int( $invoice_version ) || $invoice_version < 0 || ( is_array( $row ) && null !== $row['square_version'] && $invoice_version < (int) $row['square_version'] ) ) ) {
			return new WP_Error( 'square_admin_approval_invoice_conflict', '古い請求情報では更新できません。' );
		}
		if ( null !== $invoice_version && is_array( $row ) && null !== $row['square_version'] && $invoice_version === (int) $row['square_version'] && ( (int) $row['amount'] !== self::PRICE || (int) $row['completed_amount'] !== self::PRICE || $row['currency'] !== self::CURRENCY || strtoupper( (string) $row['square_status'] ) !== strtoupper( $invoice_status ) ) ) {
			return new WP_Error( 'square_admin_approval_invoice_conflict', '同じ請求バージョンの内容が一致しません。' );
		}
		$owner = $this->lock_payment_owner( $environment, $merchant_id, $payment_id );
		if ( is_wp_error( $owner ) || ( null !== $owner && ( ! is_array( $row ) || (int) $owner['id'] !== (int) $row['id'] ) ) ) {
			return new WP_Error( 'square_admin_approval_payment_conflict', '決済が別の請求情報に関連付いています。' );
		}
		$data = array( 'subscription_id' => $contract_id, 'user_id' => $user_id, 'environment' => $environment, 'merchant_id' => $merchant_id, 'square_invoice_id' => $invoice_id, 'square_payment_id' => $payment_id, 'invoice_status' => strtolower( $invoice_status ), 'square_status' => $invoice_status, 'payment_status' => 'succeeded', 'amount' => self::PRICE, 'completed_amount' => self::PRICE, 'currency' => self::CURRENCY, 'updated_at' => current_time( 'mysql', true ) );
		if ( null !== $invoice_version ) { $data['square_version'] = $invoice_version; }
		if ( ! is_array( $row ) ) {
			if ( null === $invoice_version ) { $data['square_version'] = null; }
			$data['created_at'] = current_time( 'mysql', true );
			$this->wpdb->last_error = '';
			$inserted = $this->wpdb->insert( $table, $data );
			if ( 1 !== $inserted || '' !== (string) $this->wpdb->last_error || (int) $this->wpdb->insert_id < 1 ) {
				return new WP_Error( 'square_admin_approval_database_error', '請求情報を保存できません。' );
			}
			return (int) $this->wpdb->insert_id;
		}
		unset( $data['subscription_id'], $data['user_id'], $data['environment'], $data['merchant_id'], $data['square_invoice_id'] );
		$this->wpdb->last_error = '';
		$updated = $this->wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
		if ( false === $updated || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_admin_approval_database_error', '請求情報を更新できません。' );
		}
		return (int) $row['id'];
	}

	private function review_entry( array $attempt, $contract_id, $invoice_row_id, array $ctx, $admin_user_id, $admin_note, $evidence_code ) {
		$note = $admin_note;
		return array(
			'user_id' => (int) $attempt['user_id'], 'environment' => $attempt['environment'], 'merchant_id' => $attempt['merchant_id'],
			'contract_id' => $contract_id, 'invoice_row_id' => $invoice_row_id, 'checkout_attempt_id' => (int) $attempt['id'], 'checkout_attempt_uuid' => $attempt['attempt_uuid'],
			'square_invoice_id' => $ctx['invoice_id'], 'square_payment_id' => $ctx['payment_id'], 'action_type' => 'approve',
			'candidate_period_start' => $ctx['local_start'], 'candidate_period_end' => $ctx['local_end'], 'approved_period_start' => $ctx['local_start'], 'approved_period_end' => $ctx['local_end'],
			'subscription_timezone' => $ctx['timezone'], 'monthly_billing_anchor_date' => $ctx['anchor'], 'evidence_code' => $evidence_code,
			'admin_note' => $note, 'reviewer_user_id' => $admin_user_id,
		);
	}

	private function commit_result( $contract_id, $invoice_row_id, array $review, array $ctx ) {
		$this->wpdb->last_error = '';
		try {
			$committed = $this->wpdb->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			return $this->commit_unknown_error( $contract_id, $invoice_row_id, $review, $ctx );
		}
		if ( false === $committed || '' !== (string) $this->wpdb->last_error ) {
			return $this->commit_unknown_error( $contract_id, $invoice_row_id, $review, $ctx );
		}
		return array( 'status' => 'approved', 'contract_id' => $contract_id, 'invoice_row_id' => $invoice_row_id, 'review_id' => (int) ( $review['review_id'] ?? 0 ), 'period_start_utc' => $ctx['utc_start'], 'period_end_utc_exclusive' => $ctx['utc_end'], 'automatic_approval_candidate' => false );
	}

	private function commit_unknown_error( $contract_id, $invoice_row_id, array $review, array $ctx ) {
		$validation = $ctx['validation'];
		$target = array(
			'type' => $ctx['kind'],
			'local_id' => (int) ( 'initial' === $ctx['kind'] ? ( $validation['local_attempt_id'] ?? 0 ) : ( $validation['local_contract_id'] ?? 0 ) ),
			'contract_id' => (int) $contract_id,
			'invoice_row_id' => (int) $invoice_row_id,
			'review_id' => (int) ( $review['review_id'] ?? 0 ),
			'square_invoice_id' => $ctx['invoice_id'],
			'square_payment_id' => $ctx['payment_id'],
			'period_start_utc' => $ctx['utc_start'],
			'period_end_utc_exclusive' => $ctx['utc_end'],
			'local_period_start' => $ctx['local_start'],
			'local_period_end_exclusive' => $ctx['local_end'],
			'timezone' => $ctx['timezone'],
			'anchor' => $ctx['anchor'],
		);
		return new WP_Error( 'square_admin_approval_commit_outcome_unknown', '承認結果を確認できません。別の決済を使って再試行せず、同じ対象を再照合してください。', array( 'reconciliation_target' => $target ) );
	}

	private function read_review( $id ) {
		return $this->read_row_by_id( $this->reviews_table(), $id );
	}

	private function read_row_by_id( $table, $id ) {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) { return new WP_Error( 'square_admin_approval_database_error', '承認記録を取得できません。' ); }
		return is_array( $row ) ? $row : null;
	}

	private function target_rows_look_committed( array $target, array $contract, array $invoice ) {
		return (int) $contract['id'] === $target['contract_id'] && (int) $invoice['id'] === $target['invoice_row_id']
			&& (int) $invoice['subscription_id'] === $target['contract_id']
			&& ( $invoice['square_invoice_id'] ?? null ) === $target['square_invoice_id'] && ( $invoice['square_payment_id'] ?? null ) === $target['square_payment_id']
			&& ( $contract['current_period_start'] ?? null ) === $target['period_start_utc'] && ( $contract['current_period_end'] ?? null ) === $target['period_end_utc_exclusive'];
	}

	private function target_review_matches( array $target, array $review, array $contract, array $invoice ) {
		if ( (int) $review['id'] !== $target['review_id'] || 'approve' !== $review['action_type'] || (int) $review['contract_id'] !== $target['contract_id'] || (int) $review['invoice_row_id'] !== $target['invoice_row_id']
			|| ( $review['square_invoice_id'] ?? null ) !== $target['square_invoice_id'] || ( $review['square_payment_id'] ?? null ) !== $target['square_payment_id']
			|| ( $review['approved_period_start'] ?? null ) !== $target['local_period_start'] || ( $review['approved_period_end'] ?? null ) !== $target['local_period_end_exclusive']
			|| ( $review['subscription_timezone'] ?? null ) !== $target['timezone'] || (int) $review['monthly_billing_anchor_date'] !== $target['anchor']
			|| ! $this->target_rows_look_committed( $target, $contract, $invoice ) ) { return false; }
		if ( (int) $invoice['user_id'] !== (int) $contract['user_id'] || $invoice['environment'] !== $contract['environment'] || $invoice['merchant_id'] !== $contract['merchant_id']
			|| ! in_array( $invoice['payment_status'], array( 'succeeded', 'paid', 'completed' ), true ) || (int) $invoice['amount'] !== self::PRICE || (int) $invoice['completed_amount'] !== self::PRICE || $invoice['currency'] !== self::CURRENCY ) { return false; }
		$start = $this->local_boundary_to_utc( substr( $target['local_period_start'], 0, 10 ), $target['timezone'] );
		$end = $this->local_boundary_to_utc( substr( $target['local_period_end_exclusive'], 0, 10 ), $target['timezone'] );
		return $start === $target['period_start_utc'] && $end === $target['period_end_utc_exclusive']
			&& $contract['current_period_start'] === $start && $contract['current_period_end'] === $end;
	}

	private function begin_transaction() {
		$this->wpdb->last_error = '';
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_admin_approval_transaction_unavailable', '承認処理を開始できません。' );
		}
		return true;
	}

	private function rollback_error( WP_Error $error ) {
		$this->wpdb->query( 'ROLLBACK' );
		return $error;
	}

	private function require_innodb( array $tables ) {
		foreach ( array_unique( $tables ) as $table ) {
			$this->wpdb->last_error = '';
			$status = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
			if ( ! is_object( $status ) || ! isset( $status->Engine ) || 'INNODB' !== strtoupper( (string) $status->Engine ) || '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_admin_approval_transaction_unavailable', '承認に必要なデータベースを確認できません。' );
			}
		}
		return true;
	}

	private function lock_user( $user_id ) {
		$this->wpdb->last_error = '';
		$id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT ID FROM {$this->wpdb->users} WHERE ID = %d FOR UPDATE", $user_id ) );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_admin_approval_database_error', '利用者情報をロックできません。' );
		}
		return (int) $id === $user_id ? true : new WP_Error( 'square_admin_approval_user_not_found', '利用者情報が見つかりません。' );
	}

	private function lock_attempt( $id ) { return $this->one_row( $this->attempts_table(), 'id', '%d', $id ); }
	private function lock_attempt_by_uuid( $uuid ) { return $this->one_row( $this->attempts_table(), 'attempt_uuid', '%s', $uuid ); }
	private function lock_contract( $id ) { return $this->one_row( $this->subscriptions_table(), 'id', '%d', $id ); }

	private function one_row( $table, $column, $placeholder, $value ) {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = {$placeholder} FOR UPDATE", $value ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_admin_approval_database_error', '契約情報をロックできません。' );
		}
		return is_array( $row ) ? $row : new WP_Error( 'square_admin_approval_local_state_changed', '対象情報が見つかりません。' );
	}

	private function lock_attempt_contracts( $uuid ) {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->subscriptions_table()} WHERE checkout_attempt_uuid = %s ORDER BY id FOR UPDATE", $uuid ), ARRAY_A );
		return is_array( $rows ) && '' === (string) $this->wpdb->last_error ? $rows : new WP_Error( 'square_admin_approval_database_error', '関連契約をロックできません。' );
	}

	private function lock_current_contracts( $user_id, $environment, $exclude_id ) {
		$sql = "SELECT * FROM {$this->subscriptions_table()} WHERE user_id = %d AND environment = %s AND current_slot = 1";
		$args = array( $user_id, $environment );
		if ( null !== $exclude_id ) { $sql .= ' AND id <> %d'; $args[] = $exclude_id; }
		$sql .= ' ORDER BY id FOR UPDATE';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) && '' === (string) $this->wpdb->last_error ? $rows : new WP_Error( 'square_admin_approval_database_error', '現行契約を確認できません。' );
	}

	private function lock_contract_invoices( $contract_id ) {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->invoices_table()} WHERE subscription_id = %d ORDER BY id FOR UPDATE", $contract_id ), ARRAY_A );
		return is_array( $rows ) && '' === (string) $this->wpdb->last_error ? $rows : new WP_Error( 'square_admin_approval_database_error', '請求情報をロックできません。' );
	}

	private function lock_invoice_by_square_id( $environment, $merchant_id, $invoice_id ) {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->invoices_table()} WHERE environment = %s AND merchant_id = %s AND square_invoice_id = %s FOR UPDATE", $environment, $merchant_id, $invoice_id ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error || count( $rows ) > 1 ) {
			return new WP_Error( 'square_admin_approval_database_error', '請求情報を確認できません。' );
		}
		return $rows[0] ?? null;
	}

	private function lock_payment_owner( $environment, $merchant_id, $payment_id ) {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->invoices_table()} WHERE environment = %s AND merchant_id = %s AND square_payment_id = %s FOR UPDATE", $environment, $merchant_id, $payment_id ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error || count( $rows ) > 1 ) {
			return new WP_Error( 'square_admin_approval_database_error', '決済の関連情報を確認できません。' );
		}
		return $rows[0] ?? null;
	}

	private function lock_attempt_reviews( $uuid ) { return $this->lock_reviews( 'checkout_attempt_uuid', '%s', $uuid ); }
	private function lock_contract_reviews( $contract_id ) { return $this->lock_reviews( 'contract_id', '%d', $contract_id ); }

	private function lock_reviews( $column, $placeholder, $value ) {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->reviews_table()} WHERE {$column} = {$placeholder} ORDER BY id DESC FOR UPDATE", $value ), ARRAY_A );
		return is_array( $rows ) && '' === (string) $this->wpdb->last_error ? $rows : new WP_Error( 'square_admin_approval_database_error', '承認履歴を確認できません。' );
	}

	private function conditional_update( $table, array $data, array $where ) {
		$this->wpdb->last_error = '';
		$updated = $this->wpdb->update( $table, $data, $where );
		return false !== $updated && '' === (string) $this->wpdb->last_error;
	}

	private function read_attempt( $id ) { return $this->read_one( $this->attempts_table(), 'id', $id ); }
	private function read_contract( $id ) { return $this->read_one( $this->subscriptions_table(), 'id', $id ); }

	private function read_one( $table, $column, $value ) {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %d", $value ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_admin_approval_database_error', '対象情報を取得できません。' );
		}
		return is_array( $row ) ? $row : null;
	}

	private function read_contract_invoices( $contract_id ) {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->invoices_table()} WHERE subscription_id = %d ORDER BY id", $contract_id ), ARRAY_A );
		return is_array( $rows ) && '' === (string) $this->wpdb->last_error ? $rows : new WP_Error( 'square_admin_approval_database_error', '請求情報を取得できません。' );
	}

	private function same_attempt( array $a, array $b ) {
		foreach ( array( 'id', 'user_id', 'attempt_uuid', 'square_order_id', 'environment', 'merchant_id', 'square_location_id', 'subscription_plan_variation_id', 'square_customer_id', 'plan_code', 'status' ) as $field ) {
			if ( (string) ( $a[ $field ] ?? '' ) !== (string) ( $b[ $field ] ?? '' ) ) { return false; }
		}
		return true;
	}

	private function same_contract( array $a, array $b ) {
		foreach ( array( 'id', 'user_id', 'environment', 'merchant_id', 'square_location_id', 'square_customer_id', 'square_subscription_id', 'square_plan_variation_id', 'checkout_attempt_uuid', 'square_version', 'plan_code', 'internal_status', 'square_status', 'initial_payment_status', 'current_slot', 'current_period_start', 'current_period_end', 'first_payment_failed_at', 'grace_ends_at', 'review_grace_ends_at', 'sync_state', 'sync_error_code' ) as $field ) {
			if ( (string) ( $a[ $field ] ?? '' ) !== (string) ( $b[ $field ] ?? '' ) ) { return false; }
		}
		return true;
	}

	private function same_invoice_set( array $a, array $b ) {
		if ( count( $a ) !== count( $b ) ) { return false; }
		$fields = array( 'id', 'subscription_id', 'user_id', 'environment', 'merchant_id', 'square_invoice_id', 'square_payment_id', 'square_version', 'invoice_status', 'square_status', 'payment_status', 'amount', 'completed_amount', 'currency', 'billing_period_start', 'billing_period_end', 'due_at', 'paid_at', 'failed_at' );
		foreach ( $a as $index => $row ) {
			foreach ( $fields as $field ) {
				if ( (string) ( $row[ $field ] ?? '' ) !== (string) ( $b[ $index ][ $field ] ?? '' ) ) { return false; }
			}
		}
		return true;
	}

	private function contract_matches_attempt( array $contract, array $attempt, array $ctx ) {
		$sub = $ctx['validation']['verified_subscription'] ?? array();
		return (int) $contract['user_id'] === (int) $attempt['user_id'] && $contract['environment'] === $attempt['environment'] && $contract['merchant_id'] === $attempt['merchant_id'] && $contract['square_location_id'] === $attempt['square_location_id'] && $contract['square_subscription_id'] === $ctx['subscription_id'] && $contract['square_customer_id'] === ( $sub['customer_id'] ?? $contract['square_customer_id'] ) && $contract['square_plan_variation_id'] === $attempt['subscription_plan_variation_id'] && $contract['checkout_attempt_uuid'] === $attempt['attempt_uuid'] && 'standard' === $contract['plan_code'];
	}

	private function not_eligible( $validation ) {
		$codes = is_array( $validation ) && is_array( $validation['reason_codes'] ?? null ) ? $validation['reason_codes'] : array( 'validation_not_eligible' );
		$code = isset( $codes[0] ) && is_string( $codes[0] ) && preg_match( '/\A[a-z0-9_]{1,100}\z/', $codes[0] ) ? $codes[0] : 'validation_not_eligible';
		return new WP_Error( 'square_admin_approval_not_eligible', '決済・契約情報が通常承認の条件を満たしていません。', array( 'reason_code' => $code ) );
	}

	private function map_review_error( WP_Error $error ) {
		$map = array( 'square_admin_review_duplicate_approval' => 'square_admin_approval_duplicate', 'square_admin_review_reference_mismatch' => 'square_admin_approval_reference_mismatch', 'square_admin_review_forbidden' => 'square_admin_approval_forbidden', 'square_admin_review_database_unavailable' => 'square_admin_approval_database_error' );
		$code = $map[ $error->get_error_code() ] ?? 'square_admin_approval_review_rejected';
		return new WP_Error( $code, '承認履歴を安全に保存できません。' );
	}

	private function normalize_note( $note ) {
		return null === $note ? null : $note;
	}

	private function valid_date( $value ) {
		if ( ! preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m ) ) { return false; }
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private function valid_timezone( $value ) {
		return 'UTC' === $value || in_array( $value, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true );
	}

	private function local_boundary_to_utc( $date, $timezone ) {
		$local = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' 00:00:00', new DateTimeZone( $timezone ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $local || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $local->format( 'Y-m-d H:i:s' ) !== $date . ' 00:00:00' ) { return null; }
		return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private function valid_square_id( $value, $max ) {
		return is_string( $value ) && strlen( $value ) <= $max && (bool) preg_match( '/\A[A-Za-z0-9._:-]{1,' . (int) $max . '}\z/', $value );
	}

	private function attempts_table() { return $this->wpdb->prefix . 'egov_law_checkout_attempts'; }
	private function subscriptions_table() { return $this->wpdb->prefix . 'egov_law_subscriptions'; }
	private function invoices_table() { return $this->wpdb->prefix . 'egov_law_subscription_invoices'; }
	private function reviews_table() { return $this->wpdb->prefix . 'egov_law_square_admin_reviews'; }
}
