<?php

/** Append-only persistence for administrator Square payment reviews. */
class Egov_Law_Monitor_Square_Admin_Review_Repository {
	private const ACTION_TYPES = array( 'approve', 'hold', 'correct', 'suspend', 'resume' );
	private $wpdb;

	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Append one review/audit event without updating contract, invoice, attempt, or user rows.
	 *
	 * @param array $entry Whitelisted review fields; raw Square payloads are rejected.
	 * @return array|WP_Error Saved review ID or a safe error.
	 */
	public function append_review( array $entry ) {
		$validated = $this->prepare_entry( $entry );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'square_admin_review_database_unavailable', 'レビュー履歴を保存できません。' );
		}

		$result = $this->insert_review_in_transaction( $validated );
		if ( is_wp_error( $result ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return $result;
		}
		$this->wpdb->last_error = '';
		if ( false === $this->wpdb->query( 'COMMIT' ) || '' !== (string) $this->wpdb->last_error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'square_admin_review_database_unavailable', 'レビュー履歴の保存結果を確認できません。' );
		}

		return $result;
	}

	/**
	 * Append a review inside a transaction owned by the caller.
	 *
	 * The caller must start, commit, and roll back the transaction on this same
	 * wpdb connection. This method never issues transaction-control statements.
	 *
	 * @param array $entry Whitelisted review fields.
	 * @return array|WP_Error Saved review ID or a safe error.
	 */
	public function append_review_in_transaction( array $entry ) {
		$validated = $this->prepare_entry( $entry );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $this->insert_review_in_transaction( $validated );
	}

	/** Validate common fields and reviewer authority before any database write. */
	private function prepare_entry( array $entry ) {
		$validated = $this->validate_entry( $entry );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( get_current_user_id() !== $validated['reviewer_user_id'] || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'square_admin_review_forbidden', '管理者の操作として記録できません。' );
		}
		return $validated;
	}

	/** Insert one validated review; the caller owns any surrounding transaction. */
	private function insert_review_in_transaction( array $validated ) {
		$references = $this->validate_references_in_transaction( $validated );
		if ( is_wp_error( $references ) ) {
			return $references;
		}

		$guards = $this->approval_guards( $validated );
		if ( is_wp_error( $guards ) ) {
			return $guards;
		}

		$data = array(
			'user_id'                     => $validated['user_id'],
			'environment'                 => $validated['environment'],
			'merchant_id'                 => $validated['merchant_id'],
			'contract_id'                 => $validated['contract_id'],
			'invoice_row_id'              => $validated['invoice_row_id'],
			'checkout_attempt_id'         => $validated['checkout_attempt_id'],
			'checkout_attempt_uuid'       => $validated['checkout_attempt_uuid'],
			'square_invoice_id'           => $validated['square_invoice_id'],
			'square_payment_id'           => $validated['square_payment_id'],
			'action_type'                 => $validated['action_type'],
			'candidate_period_start'      => $validated['candidate_period_start'],
			'candidate_period_end'        => $validated['candidate_period_end'],
			'approved_period_start'       => $validated['approved_period_start'],
			'approved_period_end'         => $validated['approved_period_end'],
			'subscription_timezone'       => $validated['subscription_timezone'],
			'monthly_billing_anchor_date' => $validated['monthly_billing_anchor_date'],
			'evidence_code'               => $validated['evidence_code'],
			'admin_note'                  => $validated['admin_note'],
			'reviewer_user_id'            => $validated['reviewer_user_id'],
			'supersedes_review_id'        => $validated['supersedes_review_id'],
			'approval_payment_guard'      => $guards['payment'],
			'approval_invoice_guard'      => $guards['invoice'],
			'recorded_at'                 => current_time( 'mysql', true ),
		);

		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		$this->wpdb->last_error = '';
		$inserted = $this->wpdb->insert( $table, $data );
		if ( 1 !== $inserted || '' !== (string) $this->wpdb->last_error ) {
			$error = (string) $this->wpdb->last_error;
			if ( false !== stripos( $error, 'approval_payment_guard' ) || false !== stripos( $error, 'approval_invoice_guard' ) ) {
				return new WP_Error( 'square_admin_review_duplicate_approval', '同じ決済または請求期間の承認履歴が既にあります。' );
			}
			return new WP_Error( 'square_admin_review_database_unavailable', 'レビュー履歴を保存できません。' );
		}

		return array( 'status' => 'saved', 'review_id' => (int) $this->wpdb->insert_id );
	}

	/** Retrieve one audit row by its local ID. */
	public function get_review( $review_id ) {
		if ( ! is_int( $review_id ) || $review_id < 1 ) {
			return new WP_Error( 'square_admin_review_id_invalid', 'レビュー履歴を確認できません。' );
		}
		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $review_id ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_admin_review_database_unavailable', 'レビュー履歴を取得できません。' );
		}
		return is_array( $row ) ? $row : null;
	}

	/** List recent audit rows for one local contract. */
	public function get_reviews_for_contract( $contract_id, $limit = 50 ) {
		if ( ! is_int( $contract_id ) || $contract_id < 1 || ! is_int( $limit ) || $limit < 1 || $limit > 200 ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', 'レビュー履歴の検索条件が不正です。' );
		}
		return $this->get_rows( 'contract_id', $contract_id, '%d', $limit );
	}

	/** List recent audit rows for one exact Attempt UUID. */
	public function get_reviews_for_attempt( $attempt_uuid, $limit = 50 ) {
		if ( ! is_string( $attempt_uuid ) || ! $this->is_uuid( $attempt_uuid ) || ! is_int( $limit ) || $limit < 1 || $limit > 200 ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', 'レビュー履歴の検索条件が不正です。' );
		}
		return $this->get_rows( 'checkout_attempt_uuid', $attempt_uuid, '%s', $limit );
	}

	private function get_rows( $column, $value, $placeholder, $limit ) {
		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = {$placeholder} ORDER BY id DESC LIMIT %d", $value, $limit ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_admin_review_database_unavailable', 'レビュー履歴を取得できません。' );
		}
		return $rows;
	}

	private function validate_entry( array $entry ) {
		$allowed = array(
			'user_id', 'environment', 'merchant_id', 'contract_id', 'invoice_row_id',
			'checkout_attempt_id', 'checkout_attempt_uuid', 'square_invoice_id', 'square_payment_id',
			'action_type', 'candidate_period_start', 'candidate_period_end', 'approved_period_start',
			'approved_period_end', 'subscription_timezone', 'monthly_billing_anchor_date', 'evidence_code', 'admin_note', 'reviewer_user_id', 'supersedes_review_id',
		);
		if ( array_diff( array_keys( $entry ), $allowed ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', 'レビュー情報の形式が不正です。' );
		}
		foreach ( array( 'user_id', 'reviewer_user_id' ) as $field ) {
			if ( ! isset( $entry[ $field ] ) || ! is_int( $entry[ $field ] ) || $entry[ $field ] < 1 ) {
				return new WP_Error( 'square_admin_review_arguments_invalid', 'レビュー情報の形式が不正です。' );
			}
		}
		if ( ! in_array( $entry['environment'] ?? null, array( 'sandbox', 'production' ), true ) || ! is_string( $entry['merchant_id'] ?? null ) || '' === trim( $entry['merchant_id'] ) || strlen( $entry['merchant_id'] ) > 64 ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', 'レビュー情報の形式が不正です。' );
		}
		if ( ! is_string( $entry['action_type'] ?? null ) || ! in_array( $entry['action_type'], self::ACTION_TYPES, true ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', 'レビュー操作の種類が不正です。' );
		}
		if ( ! is_string( $entry['evidence_code'] ?? null ) || ! preg_match( '/\A[a-z0-9_]{1,100}\z/', $entry['evidence_code'] ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '確認根拠コードが不正です。' );
		}
		$admin_note = $entry['admin_note'] ?? null;
		if ( null !== $admin_note ) {
			if ( ! is_string( $admin_note ) ) {
				return new WP_Error( 'square_admin_review_arguments_invalid', '管理者メモの形式が不正です。' );
			}
			$admin_note = trim( sanitize_textarea_field( $admin_note ) );
			if ( '' === $admin_note ) {
				$admin_note = null;
			} elseif ( false === preg_match_all( '/./us', $admin_note, $note_characters ) || 2000 < count( $note_characters[0] ) ) {
				return new WP_Error( 'square_admin_review_arguments_invalid', '管理者メモは2000文字以内で入力してください。' );
			}
		}

		$validated = array(
			'user_id' => $entry['user_id'],
			'environment' => $entry['environment'],
			'merchant_id' => $entry['merchant_id'],
			'action_type' => $entry['action_type'],
			'evidence_code' => $entry['evidence_code'],
			'admin_note' => $admin_note,
			'reviewer_user_id' => $entry['reviewer_user_id'],
		);
		$nullable_ids = array( 'contract_id', 'invoice_row_id', 'checkout_attempt_id', 'supersedes_review_id' );
		foreach ( $nullable_ids as $field ) {
			$value = $entry[ $field ] ?? null;
			if ( null !== $value && ( ! is_int( $value ) || $value < 1 ) ) {
				return new WP_Error( 'square_admin_review_arguments_invalid', 'レビュー情報の形式が不正です。' );
			}
			$validated[ $field ] = $value;
		}
		if ( empty( $validated['contract_id'] ) && empty( $validated['checkout_attempt_id'] ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '契約または申込の識別子が必要です。' );
		}

		$uuid = $entry['checkout_attempt_uuid'] ?? null;
		if ( null !== $uuid && ( ! is_string( $uuid ) || ! $this->is_uuid( $uuid ) ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '申込識別子が不正です。' );
		}
		$validated['checkout_attempt_uuid'] = $uuid;

		foreach ( array( 'square_invoice_id' => 255, 'square_payment_id' => 192 ) as $field => $max_length ) {
			$value = $entry[ $field ] ?? null;
			if ( null !== $value && ( ! is_string( $value ) || strlen( $value ) > $max_length || ! preg_match( '/\A[A-Za-z0-9._:-]{1,' . $max_length . '}\z/', $value ) ) ) {
				return new WP_Error( 'square_admin_review_arguments_invalid', 'Square識別子が不正です。' );
			}
			$validated[ $field ] = $value;
		}

		foreach ( array( 'candidate_period_start', 'candidate_period_end', 'approved_period_start', 'approved_period_end' ) as $field ) {
			$value = $entry[ $field ] ?? null;
			if ( null !== $value && ( ! is_string( $value ) || ! $this->is_datetime( $value ) ) ) {
				return new WP_Error( 'square_admin_review_arguments_invalid', '利用期間の形式が不正です。' );
			}
			$validated[ $field ] = $value;
		}
		if ( ( null === $validated['candidate_period_start'] ) !== ( null === $validated['candidate_period_end'] ) || ( null === $validated['approved_period_start'] ) !== ( null === $validated['approved_period_end'] ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '利用期間の開始日と終了日が揃っていません。' );
		}
		$timezone = $entry['subscription_timezone'] ?? null;
		if ( null !== $timezone && ( ! is_string( $timezone ) || strlen( $timezone ) > 64 || ! $this->is_valid_timezone_identifier( $timezone ) ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', 'Timezoneの形式が不正です。' );
		}
		$anchor = $entry['monthly_billing_anchor_date'] ?? null;
		if ( null !== $anchor && ( ! is_int( $anchor ) || $anchor < 1 || $anchor > 31 ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '月次課金日の形式が不正です。' );
		}
		$validated['subscription_timezone'] = $timezone;
		$validated['monthly_billing_anchor_date'] = $anchor;
		if ( null !== $validated['candidate_period_start'] && $validated['candidate_period_start'] >= $validated['candidate_period_end'] ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '候補期間が不正です。' );
		}
		if ( in_array( $validated['action_type'], array( 'approve', 'correct' ), true ) ) {
			if ( null === $validated['approved_period_start'] || $validated['approved_period_start'] >= $validated['approved_period_end'] || '00:00:00' !== substr( $validated['approved_period_start'], 11 ) || '00:00:00' !== substr( $validated['approved_period_end'], 11 ) || null === $validated['subscription_timezone'] || null === $validated['monthly_billing_anchor_date'] || ( null === $validated['square_invoice_id'] && null === $validated['square_payment_id'] ) ) {
				return new WP_Error( 'square_admin_review_arguments_invalid', '承認期間または決済識別子が不足しています。' );
			}
		} elseif ( null !== $validated['approved_period_start'] || null !== $validated['approved_period_end'] ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '承認操作以外に承認期間は指定できません。' );
		}
		if ( ( 'correct' === $validated['action_type'] ) !== ( null !== $validated['supersedes_review_id'] ) ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '訂正対象のレビュー履歴が不正です。' );
		}
		if ( 'approve' === $validated['action_type'] && null === $validated['square_payment_id'] ) {
			return new WP_Error( 'square_admin_review_arguments_invalid', '承認には確認済みPayment IDが必要です。' );
		}
		if ( 'suspend' === $validated['action_type'] || 'resume' === $validated['action_type'] ) {
			if ( null === $validated['contract_id'] ) {
				return new WP_Error( 'square_admin_review_arguments_invalid', '停止・再開には契約IDが必要です。' );
			}
		}
		return $validated;
	}

	private function validate_references_in_transaction( array &$entry ) {
		$attempt = null;
		if ( null !== $entry['checkout_attempt_id'] || null !== $entry['checkout_attempt_uuid'] ) {
			$table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
			$this->wpdb->last_error = '';
			if ( null !== $entry['checkout_attempt_id'] ) {
				$attempt = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $entry['checkout_attempt_id'] ), ARRAY_A );
			} else {
				$attempt = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE attempt_uuid = %s FOR UPDATE", $entry['checkout_attempt_uuid'] ), ARRAY_A );
				if ( is_array( $attempt ) ) {
					$entry['checkout_attempt_id'] = (int) $attempt['id'];
				}
			}
			if ( '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_admin_review_database_unavailable', '申込情報を確認できません。' );
			}
			if ( ! is_array( $attempt ) || (int) $attempt['user_id'] !== $entry['user_id'] || $attempt['environment'] !== $entry['environment'] || $attempt['merchant_id'] !== $entry['merchant_id'] || 'standard' !== $attempt['plan_code'] ) {
				return new WP_Error( 'square_admin_review_reference_mismatch', '申込情報との対応を確認できません。' );
			}
			if ( null !== $entry['checkout_attempt_uuid'] && ! hash_equals( $entry['checkout_attempt_uuid'], (string) $attempt['attempt_uuid'] ) ) {
				return new WP_Error( 'square_admin_review_reference_mismatch', '申込情報との対応を確認できません。' );
			}
			$entry['checkout_attempt_uuid'] = (string) $attempt['attempt_uuid'];
		}

		$contract = null;
		if ( null !== $entry['contract_id'] ) {
			$table = $this->wpdb->prefix . 'egov_law_subscriptions';
			$this->wpdb->last_error = '';
			$contract = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $entry['contract_id'] ), ARRAY_A );
			if ( '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_admin_review_database_unavailable', '契約情報を確認できません。' );
			}
			if ( ! is_array( $contract ) || (int) $contract['user_id'] !== $entry['user_id'] || $contract['environment'] !== $entry['environment'] || $contract['merchant_id'] !== $entry['merchant_id'] || 'standard' !== $contract['plan_code'] ) {
				return new WP_Error( 'square_admin_review_reference_mismatch', '契約情報との対応を確認できません。' );
			}
			if ( is_array( $attempt ) && ( empty( $contract['checkout_attempt_uuid'] ) || ! hash_equals( (string) $attempt['attempt_uuid'], (string) $contract['checkout_attempt_uuid'] ) ) ) {
				return new WP_Error( 'square_admin_review_reference_mismatch', '契約と申込の対応を確認できません。' );
			}
			if ( ! is_array( $attempt ) && ! empty( $contract['checkout_attempt_uuid'] ) ) {
				$attempts_table = $this->wpdb->prefix . 'egov_law_checkout_attempts';
				$this->wpdb->last_error = '';
				$attempt = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$attempts_table} WHERE attempt_uuid = %s FOR UPDATE", $contract['checkout_attempt_uuid'] ), ARRAY_A );
				if ( '' !== (string) $this->wpdb->last_error ) {
					return new WP_Error( 'square_admin_review_database_unavailable', '申込情報を確認できません。' );
				}
				if ( ! is_array( $attempt ) || (int) $attempt['user_id'] !== $entry['user_id'] || $attempt['environment'] !== $entry['environment'] || $attempt['merchant_id'] !== $entry['merchant_id'] || 'standard' !== $attempt['plan_code'] ) {
					return new WP_Error( 'square_admin_review_reference_mismatch', '契約と申込の対応を確認できません。' );
				}
				$entry['checkout_attempt_id'] = (int) $attempt['id'];
				$entry['checkout_attempt_uuid'] = (string) $attempt['attempt_uuid'];
			}
		}

		if ( null !== $entry['invoice_row_id'] ) {
			$table = $this->wpdb->prefix . 'egov_law_subscription_invoices';
			$this->wpdb->last_error = '';
			$invoice = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $entry['invoice_row_id'] ), ARRAY_A );
			if ( '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_admin_review_database_unavailable', '請求情報を確認できません。' );
			}
			if ( ! is_array( $invoice ) || ! is_array( $contract ) || (int) $invoice['subscription_id'] !== (int) $contract['id'] || (int) $invoice['user_id'] !== $entry['user_id'] || $invoice['environment'] !== $entry['environment'] || $invoice['merchant_id'] !== $entry['merchant_id'] ) {
				return new WP_Error( 'square_admin_review_reference_mismatch', '請求情報との対応を確認できません。' );
			}
			if ( null !== $entry['square_invoice_id'] && ! hash_equals( $entry['square_invoice_id'], (string) $invoice['square_invoice_id'] ) ) {
				return new WP_Error( 'square_admin_review_reference_mismatch', 'Square請求識別子が一致しません。' );
			}
			if ( null !== $invoice['square_payment_id'] && '' !== $invoice['square_payment_id'] && null !== $entry['square_payment_id'] && ! hash_equals( $entry['square_payment_id'], (string) $invoice['square_payment_id'] ) ) {
				return new WP_Error( 'square_admin_review_reference_mismatch', 'Square決済識別子が一致しません。' );
			}
			$entry['square_invoice_id'] = (string) $invoice['square_invoice_id'];
			if ( null === $entry['square_payment_id'] && ! empty( $invoice['square_payment_id'] ) ) {
				$entry['square_payment_id'] = (string) $invoice['square_payment_id'];
			}
		}

		if ( null !== $entry['supersedes_review_id'] ) {
			$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
			$this->wpdb->last_error = '';
			$prior = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $entry['supersedes_review_id'] ), ARRAY_A );
			if ( '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_admin_review_database_unavailable', '訂正対象を確認できません。' );
			}
			if ( ! is_array( $prior ) || ! in_array( $prior['action_type'], array( 'approve', 'correct' ), true ) || (int) $prior['user_id'] !== $entry['user_id'] || $prior['environment'] !== $entry['environment'] || $prior['merchant_id'] !== $entry['merchant_id'] || $prior['contract_id'] !== ( null === $entry['contract_id'] ? null : (string) $entry['contract_id'] ) || $prior['checkout_attempt_uuid'] !== $entry['checkout_attempt_uuid'] || $prior['square_invoice_id'] !== $entry['square_invoice_id'] || $prior['square_payment_id'] !== $entry['square_payment_id'] ) {
				return new WP_Error( 'square_admin_review_reference_mismatch', '訂正対象の履歴と関連情報が一致しません。' );
			}
		}

		return true;
	}

	private function approval_guards( array $entry ) {
		$guards = array( 'payment' => null, 'invoice' => null );
		if ( 'approve' !== $entry['action_type'] ) {
			return $guards;
		}
		$base = $entry['environment'] . '|' . $entry['merchant_id'] . '|';
		$guards['payment'] = hash( 'sha256', $base . $entry['square_payment_id'] );
		if ( null !== $entry['square_invoice_id'] ) {
			$guards['invoice'] = hash( 'sha256', $base . $entry['square_invoice_id'] );
		}
		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		foreach ( array( 'approval_payment_guard' => $guards['payment'], 'approval_invoice_guard' => $guards['invoice'] ) as $column => $guard ) {
			if ( null === $guard ) {
				continue;
			}
			$this->wpdb->last_error = '';
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$table} WHERE {$column} = %s FOR UPDATE", $guard ) );
			if ( '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_admin_review_database_unavailable', '承認履歴を確認できません。' );
			}
			if ( null !== $existing ) {
				return new WP_Error( 'square_admin_review_duplicate_approval', '同じ決済または請求期間の承認履歴が既にあります。' );
			}
		}
		return $guards;
	}

	private function is_uuid( $value ) {
		return is_string( $value ) && (bool) preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/i', $value );
	}

	private function is_datetime( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );
		$errors = DateTimeImmutable::getLastErrors();
		return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function is_valid_timezone_identifier( $timezone ) {
		return 'UTC' === $timezone || in_array( $timezone, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true );
	}
}
