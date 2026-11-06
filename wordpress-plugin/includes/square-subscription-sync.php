<?php

/** Persist matched Square subscription and invoice snapshots without granting access. */
class Egov_Law_Monitor_Square_Subscription_Contract_Repository {
	private $wpdb;

	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Save snapshots only after validating every identifier against the stored Attempt.
	 *
	 * @return array|WP_Error Outcome: saved, unchanged, or a safe conflict/database error.
	 */
	public function save_verified_snapshot( array $match, array $subscription, array $invoice, array $payment, array $scope ) {
		$validation = $this->validate_snapshot( $match, $subscription, $invoice, $payment, $scope );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$engines = array( $this->wpdb->users, $this->wpdb->prefix . 'egov_law_checkout_attempts', $this->wpdb->prefix . 'egov_law_subscriptions', $this->wpdb->prefix . 'egov_law_subscription_invoices' );
		foreach ( $engines as $table_name ) {
			$this->wpdb->last_error = '';
			$status = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name ) );
			if ( ! is_object( $status ) || ! isset( $status->Engine ) || 'INNODB' !== strtoupper( (string) $status->Engine ) ) {
				return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
			}
		}

		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
		}

		$outcome = $this->persist_in_transaction( $match, $subscription, $invoice, $payment, $scope );
		if ( is_wp_error( $outcome ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return $outcome;
		}

		$this->wpdb->last_error = '';
		if ( false === $this->wpdb->query( 'COMMIT' ) || '' !== (string) $this->wpdb->last_error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
		}
		return $outcome;
	}

	private function persist_in_transaction( $match, $subscription, $invoice, $payment, $scope ) {
		$users = $this->wpdb->users;
		$this->wpdb->last_error = '';
		$user_id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT ID FROM {$users} WHERE ID = %d FOR UPDATE", $match['user_id'] ) );
		if ( '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
		}
		if ( (int) $user_id !== $match['user_id'] ) {
			return new WP_Error( 'square_contract_reconciliation_required', '契約情報を照合できません。' );
		}

		$attempts = $this->wpdb->prefix . 'egov_law_checkout_attempts';
		$this->wpdb->last_error = '';
		$attempt_rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$attempts} WHERE attempt_uuid = %s FOR UPDATE", $match['attempt_uuid'] ),
			ARRAY_A
		);
		if ( ! is_array( $attempt_rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
		}
		if ( 1 !== count( $attempt_rows ) || ! $this->attempt_matches( $attempt_rows[0], $match, $subscription, $scope ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '申込情報と契約情報を照合できません。' );
		}

		$subscriptions = $this->wpdb->prefix . 'egov_law_subscriptions';
		$this->wpdb->last_error = '';
		$subscription_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$subscriptions} WHERE environment = %s AND merchant_id = %s AND square_subscription_id = %s FOR UPDATE",
				$scope['environment'],
				$scope['merchant_id'],
				$subscription['id']
			),
			ARRAY_A
		);
		if ( ! is_array( $subscription_rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
		}
		if ( count( $subscription_rows ) > 1 ) {
			return new WP_Error( 'square_contract_reconciliation_required', '契約情報が一意に特定できません。' );
		}
		$existing_subscription = isset( $subscription_rows[0] ) ? $subscription_rows[0] : null;
		if ( is_array( $existing_subscription ) && ! $this->subscription_identity_matches( $existing_subscription, $match, $subscription, $scope ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '既存契約との紐付けが一致しません。' );
		}

		$this->wpdb->last_error = '';
		$attempt_link_rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$subscriptions} WHERE checkout_attempt_uuid = %s FOR UPDATE", $match['attempt_uuid'] ),
			ARRAY_A
		);
		if ( ! is_array( $attempt_link_rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
		}
		if ( count( $attempt_link_rows ) > 1 || ( ! empty( $attempt_link_rows ) && ( ! is_array( $existing_subscription ) || (int) $attempt_link_rows[0]['id'] !== (int) $existing_subscription['id'] ) ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '申込が別の契約に紐付いています。' );
		}

		$this->wpdb->last_error = '';
		$current_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$subscriptions} WHERE user_id = %d AND environment = %s AND merchant_id = %s AND current_slot = 1 FOR UPDATE",
				$match['user_id'],
				$scope['environment'],
				$scope['merchant_id']
			),
			ARRAY_A
		);
		if ( ! is_array( $current_rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
		}
		if ( count( $current_rows ) > 1 || ( ! empty( $current_rows ) && ( ! is_array( $existing_subscription ) || (int) $current_rows[0]['id'] !== (int) $existing_subscription['id'] ) ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '別の現行契約が存在します。' );
		}

		$invoices = $this->wpdb->prefix . 'egov_law_subscription_invoices';
		$this->wpdb->last_error = '';
		$invoice_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$invoices} WHERE environment = %s AND merchant_id = %s AND square_invoice_id = %s FOR UPDATE",
				$scope['environment'],
				$scope['merchant_id'],
				$invoice['id']
			),
			ARRAY_A
		);
		if ( ! is_array( $invoice_rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '請求情報を保存できません。' );
		}
		if ( count( $invoice_rows ) > 1 ) {
			return new WP_Error( 'square_contract_reconciliation_required', '請求情報が一意に特定できません。' );
		}
		$existing_invoice = isset( $invoice_rows[0] ) ? $invoice_rows[0] : null;
		if ( is_array( $existing_invoice ) && ( ! is_array( $existing_subscription ) || ! $this->invoice_identity_matches( $existing_invoice, $existing_subscription, $match, $scope ) ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '既存請求との紐付けが一致しません。' );
		}
		if ( is_array( $existing_invoice ) && ! empty( $existing_invoice['square_payment_id'] ) && ! hash_equals( $match['payment_id'], (string) $existing_invoice['square_payment_id'] ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '既存請求の決済IDと一致しません。' );
		}

		$this->wpdb->last_error = '';
		$payment_rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$invoices} WHERE environment = %s AND merchant_id = %s AND square_payment_id = %s FOR UPDATE", $scope['environment'], $scope['merchant_id'], $payment['id'] ),
			ARRAY_A
		);
		if ( ! is_array( $payment_rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_contract_database_unavailable', '請求情報を保存できません。' );
		}
		if ( count( $payment_rows ) > 1 || ( ! empty( $payment_rows ) && ( ! is_array( $existing_invoice ) || (int) $payment_rows[0]['id'] !== (int) $existing_invoice['id'] ) ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '決済が別の請求情報に紐付いています。' );
		}

		$subscription_data = $this->subscription_snapshot_data( $match, $subscription, $scope );
		$invoice_data      = $this->invoice_snapshot_data( $match, $invoice, $payment, $scope );
		$subscription_action = $this->version_action( $existing_subscription, $subscription_data, 'subscription' );
		if ( is_wp_error( $subscription_action ) ) {
			return $subscription_action;
		}
		$invoice_action = $this->version_action( $existing_invoice, $invoice_data, 'invoice' );
		if ( is_wp_error( $invoice_action ) ) {
			return $invoice_action;
		}

		$changed = false;
		$subscription_id = is_array( $existing_subscription ) ? (int) $existing_subscription['id'] : 0;
		if ( 'insert' === $subscription_action ) {
			$now = current_time( 'mysql', true );
			$insert = array_merge(
				$subscription_data,
				array(
					'plan_code'                => 'standard',
					'internal_status'          => 'pending',
					'initial_payment_status'   => 'succeeded',
					'current_slot'             => null,
					'current_period_start'      => null,
					'current_period_end'        => null,
					'first_payment_failed_at'   => null,
					'grace_ends_at'             => null,
					'sync_state'                => 'unknown',
					'last_synced_at'            => null,
					'sync_error_code'           => '',
					'last_square_event_at'       => null,
					'created_at'                => $now,
					'updated_at'                => $now,
				)
			);
			$this->wpdb->last_error = '';
			$inserted = $this->wpdb->insert( $subscriptions, $insert );
			if ( 1 !== $inserted || '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_contract_database_unavailable', '契約情報を保存できません。' );
			}
			$subscription_id = (int) $this->wpdb->insert_id;
			$changed = true;
		} elseif ( 'update' === $subscription_action ) {
			$subscription_data['updated_at'] = current_time( 'mysql', true );
			$this->wpdb->last_error = '';
			$updated = $this->wpdb->update( $subscriptions, $subscription_data, array( 'id' => $subscription_id ), null, array( '%d' ) );
			if ( false === $updated || '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_contract_database_unavailable', '契約情報を更新できません。' );
			}
			$changed = $changed || $updated > 0;
		}

		$invoice_data['subscription_id'] = $subscription_id;
		$invoice_data['user_id']         = $match['user_id'];
		$invoice_data['environment']     = $scope['environment'];
		$invoice_data['merchant_id']     = $scope['merchant_id'];
		if ( 'insert' === $invoice_action ) {
			$now = current_time( 'mysql', true );
			$invoice_data = array_merge(
				$invoice_data,
				array(
					'invoice_status' => $invoice_data['invoice_status'],
					'payment_status' => 'unknown',
					'billing_period_start' => null,
					'billing_period_end'   => null,
					'paid_at'              => null,
					'failed_at'            => null,
					'created_at'           => $now,
					'updated_at'           => $now,
				)
			);
			$this->wpdb->last_error = '';
			$inserted = $this->wpdb->insert( $invoices, $invoice_data );
			if ( 1 !== $inserted || '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_contract_database_unavailable', '請求情報を保存できません。' );
			}
			$changed = true;
		} elseif ( 'update' === $invoice_action ) {
			$invoice_id = (int) $existing_invoice['id'];
			$update_data = $invoice_data;
			unset( $update_data['subscription_id'], $update_data['user_id'], $update_data['environment'], $update_data['merchant_id'] );
			$update_data['updated_at'] = current_time( 'mysql', true );
			$this->wpdb->last_error = '';
			$updated = $this->wpdb->update( $invoices, $update_data, array( 'id' => $invoice_id ), null, array( '%d' ) );
			if ( false === $updated || '' !== (string) $this->wpdb->last_error ) {
				return new WP_Error( 'square_contract_database_unavailable', '請求情報を更新できません。' );
			}
			$changed = $changed || $updated > 0;
		}

		return array(
			'status'             => $changed ? 'saved' : 'unchanged',
			'reason_code'        => $changed ? 'verified_snapshot_saved' : 'verified_snapshot_unchanged',
			'subscription_row_id' => $subscription_id,
		);
	}

	private function validate_snapshot( $match, $subscription, $invoice, $payment, $scope ) {
		$required_match = array( 'status', 'user_id', 'attempt_uuid', 'subscription_id', 'invoice_id', 'order_id', 'payment_id', 'customer_id', 'environment', 'merchant_id', 'location_id', 'plan_variation_id', 'square_status' );
		foreach ( $required_match as $key ) {
			if ( ! array_key_exists( $key, $match ) ) {
				return new WP_Error( 'square_contract_reconciliation_required', '照合結果に必要な情報がありません。' );
			}
		}
		if ( 'subscription_matched' !== $match['status'] || ! is_int( $match['user_id'] ) || $match['user_id'] < 1 ) {
			return new WP_Error( 'square_contract_reconciliation_required', '照合済み契約として確認できません。' );
		}
		foreach ( array( 'attempt_uuid', 'subscription_id', 'invoice_id', 'order_id', 'payment_id', 'customer_id', 'environment', 'merchant_id', 'location_id', 'plan_variation_id', 'square_status' ) as $key ) {
			if ( ! is_string( $match[ $key ] ) || '' === $match[ $key ] ) {
				return new WP_Error( 'square_contract_reconciliation_required', '照合結果に必要な情報がありません。' );
			}
		}
		$maximum_lengths = array( 'environment' => 16, 'merchant_id' => 64, 'location_id' => 64, 'customer_id' => 191, 'subscription_id' => 255, 'invoice_id' => 255, 'payment_id' => 192, 'plan_variation_id' => 255, 'square_status' => 32 );
		foreach ( $maximum_lengths as $key => $maximum_length ) {
			if ( strlen( $match[ $key ] ) > $maximum_length ) {
				return new WP_Error( 'square_contract_reconciliation_required', '識別情報が保存可能な形式ではありません。' );
			}
		}
		if ( ! in_array( $match['environment'], array( 'sandbox', 'production' ), true ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', 'Square環境が不正です。' );
		}
		if ( ! preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/i', $match['attempt_uuid'] ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '申込識別子が不正です。' );
		}
		if ( ! is_array( $subscription ) || ! is_array( $invoice ) || ! is_array( $payment ) || ! is_array( $scope ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', 'Square取得情報を確認できません。' );
		}
		if (
			! isset( $subscription['id'], $subscription['location_id'], $subscription['customer_id'], $subscription['plan_variation_id'], $subscription['status'] )
			|| ! is_string( $subscription['id'] ) || ! is_string( $subscription['location_id'] ) || ! is_string( $subscription['customer_id'] ) || ! is_string( $subscription['plan_variation_id'] ) || ! is_string( $subscription['status'] )
			|| ! isset( $invoice['id'], $invoice['subscription_id'], $invoice['location_id'], $invoice['order_id'], $invoice['status'], $invoice['payment_state'] )
			|| ! is_string( $invoice['id'] ) || ! is_string( $invoice['subscription_id'] ) || ! is_string( $invoice['location_id'] ) || ! is_string( $invoice['order_id'] ) || ! is_string( $invoice['status'] ) || ! is_string( $invoice['payment_state'] )
			|| ! isset( $payment['id'], $payment['order_id'], $payment['location_id'], $payment['status'] )
			|| ! is_string( $payment['id'] ) || ! is_string( $payment['order_id'] ) || ! is_string( $payment['location_id'] ) || ! is_string( $payment['status'] )
		) {
			return new WP_Error( 'square_contract_reconciliation_required', 'Square取得情報の形式が不正です。' );
		}
		foreach ( array( array( $subscription, 'version', 'square_subscription_response_invalid' ), array( $invoice, 'version', 'square_invoice_response_invalid' ) ) as $version_spec ) {
			$version_resource = $version_spec[0];
			$version_key      = $version_spec[1];
			if ( array_key_exists( $version_key, $version_resource ) && null !== $version_resource[ $version_key ] && ( ! is_int( $version_resource[ $version_key ] ) || $version_resource[ $version_key ] < 0 ) ) {
				return new WP_Error( 'square_contract_reconciliation_required', 'Square versionの形式が不正です。' );
			}
		}
		if ( strlen( $subscription['status'] ) > 32 || strlen( $invoice['status'] ) > 32 || strlen( $invoice['payment_state'] ) > 32 ) {
			return new WP_Error( 'square_contract_reconciliation_required', 'Square状態値が保存可能な形式ではありません。' );
		}
		$expected_scope = array( 'environment' => 'environment', 'merchant_id' => 'merchant_id', 'location_id' => 'location_id', 'plan_variation_id' => 'subscription_plan_variation_id' );
		foreach ( $expected_scope as $match_key => $scope_key ) {
			if ( ! isset( $scope[ $scope_key ] ) || ! is_string( $scope[ $scope_key ] ) || ! hash_equals( $scope[ $scope_key ], $match[ $match_key ] ) ) {
				return new WP_Error( 'square_contract_reconciliation_required', 'Square環境または識別情報が一致しません。' );
			}
		}
		if (
			! isset( $subscription['id'], $subscription['location_id'], $subscription['customer_id'], $subscription['plan_variation_id'], $subscription['status'] )
			|| ! hash_equals( $match['subscription_id'], (string) $subscription['id'] )
			|| ! hash_equals( $match['location_id'], (string) $subscription['location_id'] )
			|| ! hash_equals( $match['customer_id'], (string) $subscription['customer_id'] )
			|| ! hash_equals( $match['plan_variation_id'], (string) $subscription['plan_variation_id'] )
			|| ! hash_equals( $match['square_status'], (string) $subscription['status'] )
			|| ! isset( $invoice['id'], $invoice['subscription_id'], $invoice['location_id'], $invoice['order_id'], $invoice['status'], $invoice['payment_state'] )
			|| ! hash_equals( $match['invoice_id'], (string) $invoice['id'] )
			|| ! hash_equals( $match['subscription_id'], (string) $invoice['subscription_id'] )
			|| ! hash_equals( $match['location_id'], (string) $invoice['location_id'] )
			|| ! hash_equals( $match['order_id'], (string) $invoice['order_id'] )
			|| 'PAID' !== $invoice['status']
			|| 'paid' !== $invoice['payment_state']
			|| ! isset( $payment['id'], $payment['order_id'], $payment['location_id'], $payment['status'] )
			|| ! hash_equals( $match['payment_id'], (string) $payment['id'] )
			|| ! hash_equals( $match['order_id'], (string) $payment['order_id'] )
			|| ! hash_equals( $match['location_id'], (string) $payment['location_id'] )
			|| 'COMPLETED' !== $payment['status']
		) {
			return new WP_Error( 'square_contract_reconciliation_required', 'Square取得情報の識別子または支払状態が一致しません。' );
		}
		$price = array( 'amount' => 500, 'currency' => 'JPY' );
		if ( ! $this->money_matches( isset( $invoice['requested_amount_money'] ) ? $invoice['requested_amount_money'] : null, $price ) || ! $this->money_matches( isset( $invoice['completed_amount_money'] ) ? $invoice['completed_amount_money'] : null, $price ) ) {
			return new WP_Error( 'square_contract_snapshot_pending', '請求金額または決済完了額を確認できません。' );
		}
		if ( isset( $payment['customer_id'] ) && ( ! is_string( $payment['customer_id'] ) || ! hash_equals( $match['customer_id'], $payment['customer_id'] ) ) ) {
			return new WP_Error( 'square_contract_reconciliation_required', '決済Customer IDが契約情報と一致しません。' );
		}
		return true;
	}

	private function attempt_matches( $attempt, $match, $subscription, $scope ) {
		if ( ! is_array( $attempt ) || ! isset( $attempt['id'], $attempt['attempt_uuid'], $attempt['user_id'], $attempt['environment'], $attempt['merchant_id'], $attempt['square_location_id'], $attempt['plan_code'], $attempt['subscription_plan_variation_id'], $attempt['status'], $attempt['square_order_id'], $attempt['square_customer_id'] ) || ! array_key_exists( 'square_subscription_id', $attempt ) ) {
			return false;
		}
		$user = get_userdata( (int) $attempt['user_id'] );
		return preg_match( '/\A[1-9][0-9]*\z/', (string) $attempt['id'] )
			&& preg_match( '/\A[1-9][0-9]*\z/', (string) $attempt['user_id'] )
			&& (int) $attempt['user_id'] === $match['user_id']
			&& is_object( $user ) && (int) $user->ID === $match['user_id']
			&& hash_equals( $match['attempt_uuid'], (string) $attempt['attempt_uuid'] )
			&& hash_equals( $scope['environment'], (string) $attempt['environment'] )
			&& hash_equals( $scope['merchant_id'], (string) $attempt['merchant_id'] )
			&& hash_equals( $scope['location_id'], (string) $attempt['square_location_id'] )
			&& hash_equals( $scope['subscription_plan_variation_id'], (string) $attempt['subscription_plan_variation_id'] )
			&& 'standard' === $attempt['plan_code']
			&& hash_equals( $match['order_id'], (string) $attempt['square_order_id'] )
			&& hash_equals( $match['customer_id'], (string) $attempt['square_customer_id'] )
			&& in_array( $attempt['status'], array( 'pending', 'reconciliation_required' ), true )
			&& ( null === $attempt['square_subscription_id'] || '' === $attempt['square_subscription_id'] || hash_equals( $match['subscription_id'], (string) $attempt['square_subscription_id'] ) )
			&& ( empty( $attempt['square_subscription_id'] ) || hash_equals( $match['subscription_id'], (string) $attempt['square_subscription_id'] ) )
			&& hash_equals( $match['plan_variation_id'], (string) $subscription['plan_variation_id'] );
	}

	private function subscription_identity_matches( $row, $match, $subscription, $scope ) {
		return isset( $row['id'], $row['user_id'], $row['checkout_attempt_uuid'], $row['square_customer_id'], $row['square_location_id'], $row['square_plan_variation_id'] )
			&& (int) $row['user_id'] === $match['user_id']
			&& hash_equals( $match['attempt_uuid'], (string) $row['checkout_attempt_uuid'] )
			&& hash_equals( $match['customer_id'], (string) $row['square_customer_id'] )
			&& hash_equals( $match['location_id'], (string) $row['square_location_id'] )
			&& hash_equals( $match['plan_variation_id'], (string) $row['square_plan_variation_id'] )
			&& hash_equals( $scope['environment'], (string) $row['environment'] )
			&& hash_equals( $scope['merchant_id'], (string) $row['merchant_id'] )
			&& 'standard' === $row['plan_code'];
	}

	private function invoice_identity_matches( $row, $subscription, $match, $scope ) {
		return isset( $row['id'], $row['subscription_id'], $row['user_id'], $row['environment'], $row['merchant_id'] )
			&& (int) $row['subscription_id'] === (int) $subscription['id']
			&& (int) $row['user_id'] === $match['user_id']
			&& $row['environment'] === $scope['environment']
			&& $row['merchant_id'] === $scope['merchant_id'];
	}

	private function subscription_snapshot_data( $match, $subscription, $scope ) {
		$start = $this->date_to_utc( isset( $subscription['start_date'] ) ? $subscription['start_date'] : null, isset( $subscription['timezone'] ) ? $subscription['timezone'] : null );
		$cancel_scheduled = null;
		$canceled = null;
		$cancel_at_period_end = 0;
		if ( isset( $subscription['canceled_date'] ) ) {
			$date = $this->date_to_utc( $subscription['canceled_date'], isset( $subscription['timezone'] ) ? $subscription['timezone'] : null );
			if ( 'ACTIVE' === $subscription['status'] ) {
				$cancel_scheduled = $date;
				$cancel_at_period_end = null !== $date ? 1 : 0;
			} elseif ( 'CANCELED' === $subscription['status'] ) {
				$canceled = $date;
			}
		}
		return array(
			'user_id'                    => $match['user_id'],
			'environment'                => $scope['environment'],
			'merchant_id'                => $scope['merchant_id'],
			'square_location_id'         => $match['location_id'],
			'square_customer_id'         => $match['customer_id'],
			'square_subscription_id'     => $match['subscription_id'],
			'square_plan_variation_id'    => $match['plan_variation_id'],
			'checkout_attempt_uuid'       => $match['attempt_uuid'],
			'square_version'             => array_key_exists( 'version', $subscription ) ? $subscription['version'] : null,
			'square_status'              => $subscription['status'],
			'started_at'                 => $start,
			'cancel_at_period_end'       => $cancel_at_period_end,
			'cancel_scheduled_at'        => $cancel_scheduled,
			'canceled_at'                => $canceled,
		);
	}

	private function invoice_snapshot_data( $match, $invoice, $payment, $scope ) {
		$due_at = null;
		$requests = isset( $invoice['payment_requests'] ) && is_array( $invoice['payment_requests'] ) ? $invoice['payment_requests'] : array();
		if ( 1 === count( $requests ) && isset( $requests[0]['due_date'], $invoice['timezone'] ) ) {
			$due_at = $this->date_to_utc( $requests[0]['due_date'], $invoice['timezone'] );
		}
		return array(
			'square_invoice_id' => $invoice['id'],
			'square_payment_id' => $payment['id'],
			'square_version'    => array_key_exists( 'version', $invoice ) ? $invoice['version'] : null,
			'invoice_status'    => $invoice['payment_state'],
			'square_status'     => $invoice['status'],
			'amount'            => $invoice['requested_amount_money']['amount'],
			'completed_amount'  => $invoice['completed_amount_money']['amount'],
			'currency'          => $invoice['requested_amount_money']['currency'],
			'due_at'            => $due_at,
		);
	}

	private function version_action( $existing, $incoming, $resource ) {
		if ( ! is_array( $existing ) ) {
			return 'insert';
		}
		$version_key = 'square_version';
		$old_version = null === $existing[ $version_key ] ? null : (string) $existing[ $version_key ];
		$new_version = null === $incoming[ $version_key ] ? null : (string) $incoming[ $version_key ];
		$same_content = $this->snapshot_content_matches( $existing, $incoming, $resource );
		if ( null === $old_version || null === $new_version ) {
			if ( $same_content ) {
				if ( null === $old_version && null !== $new_version ) {
					return 'update';
				}
				return 'unchanged';
			}
			return new WP_Error( 'square_contract_reconciliation_required', 'versionが不明なため状態を更新できません。' );
		}
		$comparison = $this->compare_decimal_versions( $new_version, $old_version );
		if ( $comparison < 0 ) {
			return new WP_Error( 'square_contract_reconciliation_required', '古いSquare versionのため更新を保留しました。' );
		}
		if ( 0 === $comparison ) {
			return $same_content ? 'unchanged' : new WP_Error( 'square_contract_reconciliation_required', '同じSquare versionの内容が一致しません。' );
		}
		return 'update';
	}

	private function snapshot_content_matches( $existing, $incoming, $resource ) {
		$keys = 'subscription' === $resource
			? array( 'square_status', 'started_at', 'cancel_at_period_end', 'cancel_scheduled_at', 'canceled_at' )
			: array( 'invoice_status', 'square_status', 'square_payment_id', 'amount', 'completed_amount', 'currency', 'due_at' );
		foreach ( $keys as $key ) {
			$old = isset( $existing[ $key ] ) ? (string) $existing[ $key ] : null;
			$new = isset( $incoming[ $key ] ) ? (string) $incoming[ $key ] : null;
			if ( $old !== $new ) {
				return false;
			}
		}
		return true;
	}

	private function compare_decimal_versions( $left, $right ) {
		$left  = ltrim( (string) $left, '0' );
		$right = ltrim( (string) $right, '0' );
		$left  = '' === $left ? '0' : $left;
		$right = '' === $right ? '0' : $right;
		if ( strlen( $left ) !== strlen( $right ) ) {
			return strlen( $left ) < strlen( $right ) ? -1 : 1;
		}
		return strcmp( $left, $right ) <=> 0;
	}

	private function money_matches( $money, $expected ) {
		return is_array( $money )
			&& isset( $money['amount'], $money['currency'] )
			&& is_int( $money['amount'] )
			&& $money['amount'] === $expected['amount']
			&& is_string( $money['currency'] )
			&& hash_equals( $expected['currency'], $money['currency'] );
	}

	private function date_to_utc( $date, $timezone ) {
		if ( ! is_string( $date ) || ! is_string( $timezone ) || '' === $timezone ) {
			return null;
		}
		try {
			$zone = new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			return null;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $zone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return null;
		}
		return $parsed->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}

/** Orchestrate strict Square re-verification and contract snapshot persistence. */
class Egov_Law_Monitor_Square_Subscription_Sync_Service {
	private $repository;
	private $client;
	private $matcher;

	public function __construct( Egov_Law_Monitor_Square_Subscription_Contract_Repository $repository, Egov_Law_Monitor_Square_Api_Client $client, Egov_Law_Monitor_Square_Subscription_Attempt_Matcher $matcher ) {
		$this->repository = $repository;
		$this->client     = $client;
		$this->matcher    = $matcher;
	}

	/**
	 * Verify a Subscription through the existing matcher, re-fetch its supporting objects,
	 * and persist only a fully verified initial-payment snapshot.
	 *
	 * @param string $subscription_id Square Subscription ID.
	 * @return array{status:string,reason_code:string}|WP_Error
	 */
	public function sync_subscription( $subscription_id ) {
		if ( ! is_string( $subscription_id ) || ! preg_match( '/\A[A-Za-z0-9._:-]{1,255}\z/', $subscription_id ) ) {
			return $this->result( 'reconciliation_required', 'subscription_id_invalid' );
		}
		$scope = $this->client->get_checkout_scope();
		if ( is_wp_error( $scope ) ) {
			return $this->result( 'retry', 'square_configuration_unavailable' );
		}
		$match = $this->matcher->match_subscription( $subscription_id );
		if ( is_wp_error( $match ) ) {
			return $this->result( 'retry', 'subscription_match_database_unavailable' );
		}
		if ( ! is_array( $match ) || ! isset( $match['status'] ) || ! is_string( $match['status'] ) ) {
			return $this->result( 'reconciliation_required', 'subscription_match_result_invalid' );
		}
		if ( 'subscription_pending' === $match['status'] ) {
			return $this->result( 'pending', 'subscription_match_pending' );
		}
		if ( 'square_api_error' === $match['status'] ) {
			return $this->result( 'retry', 'square_api_unavailable' );
		}
		if ( 'reconciliation_required' === $match['status'] ) {
			return $this->result( 'reconciliation_required', 'subscription_match_requires_reconciliation' );
		}
		if ( 'subscription_matched' !== $match['status'] ) {
			return $this->result( 'reconciliation_required', 'subscription_match_result_invalid' );
		}

		foreach ( array( 'user_id', 'attempt_uuid', 'subscription_id', 'invoice_id', 'order_id', 'payment_id', 'customer_id', 'environment', 'merchant_id', 'location_id', 'plan_variation_id', 'square_status' ) as $key ) {
			if ( ! array_key_exists( $key, $match ) ) {
				return $this->result( 'reconciliation_required', 'subscription_match_data_incomplete' );
			}
		}
		if ( ! is_int( $match['user_id'] ) || $match['user_id'] < 1 || ! hash_equals( $subscription_id, (string) $match['subscription_id'] ) ) {
			return $this->result( 'reconciliation_required', 'subscription_match_identity_invalid' );
		}
		foreach ( array( 'environment' => 'environment', 'merchant_id' => 'merchant_id', 'location_id' => 'location_id', 'plan_variation_id' => 'subscription_plan_variation_id' ) as $key => $scope_key ) {
			if ( ! isset( $scope[ $scope_key ] ) || ! is_string( $match[ $key ] ) || ! hash_equals( $scope[ $scope_key ], $match[ $key ] ) ) {
				return $this->result( 'reconciliation_required', 'subscription_match_scope_mismatch' );
			}
		}

		$subscription = $this->client->retrieve_subscription( $subscription_id );
		if ( is_wp_error( $subscription ) ) {
			return $this->classify_api_error( $subscription, 'subscription' );
		}
		$invoice = $this->client->get_invoice( $match['invoice_id'] );
		if ( is_wp_error( $invoice ) ) {
			return $this->classify_api_error( $invoice, 'invoice' );
		}
		$order = $this->client->retrieve_order( $match['order_id'] );
		if ( is_wp_error( $order ) ) {
			return $this->classify_api_error( $order, 'order' );
		}
		$payment = $this->client->get_payment( $match['payment_id'] );
		if ( is_wp_error( $payment ) ) {
			return $this->classify_api_error( $payment, 'payment' );
		}

		if ( ! $this->retrieved_objects_match( $match, $subscription, $invoice, $order, $payment, $scope ) ) {
			return $this->result( 'reconciliation_required', 'square_snapshot_identity_mismatch' );
		}
		$price = $this->client->get_standard_plan_price();
		if ( ! $this->price_matches( isset( $order['total_money'] ) ? $order['total_money'] : null, $price ) || ! $this->price_matches( isset( $payment['amount_money'] ) ? $payment['amount_money'] : null, $price ) || ! $this->price_matches( isset( $payment['total_money'] ) ? $payment['total_money'] : null, $price ) ) {
			return $this->result( 'reconciliation_required', 'initial_payment_amount_mismatch' );
		}
		if ( ! isset( $invoice['requested_amount_money'], $invoice['completed_amount_money'] ) ) {
			return $this->result( 'pending', 'invoice_amount_data_unavailable' );
		}
		if ( ! $this->price_matches( $invoice['requested_amount_money'], $price ) || ! $this->price_matches( $invoice['completed_amount_money'], $price ) || 'PAID' !== $invoice['status'] || 'paid' !== $invoice['payment_state'] ) {
			return $this->result( 'reconciliation_required', 'invoice_payment_not_fully_verified' );
		}

		$saved = $this->repository->save_verified_snapshot( $match, $subscription, $invoice, $payment, $scope );
		if ( is_wp_error( $saved ) ) {
			$code = $saved->get_error_code();
			if ( false !== strpos( $code, 'database_unavailable' ) ) {
				return $this->result( 'retry', 'contract_database_unavailable' );
			}
			if ( false !== strpos( $code, 'snapshot_pending' ) ) {
				return $this->result( 'pending', 'snapshot_data_pending' );
			}
			return $this->result( 'reconciliation_required', 'contract_snapshot_conflict' );
		}
		return $saved;
	}

	private function retrieved_objects_match( $match, $subscription, $invoice, $order, $payment, $scope ) {
		if ( ! is_array( $subscription ) || ! is_array( $invoice ) || ! is_array( $order ) || ! is_array( $payment ) ) {
			return false;
		}
		if (
			! isset( $subscription['id'], $subscription['location_id'], $subscription['customer_id'], $subscription['plan_variation_id'], $subscription['status'] )
			|| ! hash_equals( $match['subscription_id'], $subscription['id'] )
			|| ! hash_equals( $match['location_id'], $subscription['location_id'] )
			|| ! hash_equals( $match['customer_id'], $subscription['customer_id'] )
			|| ! hash_equals( $match['plan_variation_id'], $subscription['plan_variation_id'] )
			|| ! hash_equals( $match['square_status'], $subscription['status'] )
			|| ! isset( $invoice['id'], $invoice['subscription_id'], $invoice['location_id'], $invoice['order_id'] )
			|| ! hash_equals( $match['invoice_id'], $invoice['id'] )
			|| ! hash_equals( $match['subscription_id'], $invoice['subscription_id'] )
			|| ! hash_equals( $match['location_id'], $invoice['location_id'] )
			|| ! hash_equals( $match['order_id'], $invoice['order_id'] )
			|| ! isset( $order['id'], $order['reference_id'], $order['location_id'], $order['tenders'] )
			|| ! hash_equals( $match['order_id'], $order['id'] )
			|| ! hash_equals( $match['attempt_uuid'], $order['reference_id'] )
			|| ! hash_equals( $match['location_id'], $order['location_id'] )
			|| ! is_array( $order['tenders'] ) || 1 !== count( $order['tenders'] )
			|| ! isset( $order['tenders'][0]['payment_id'] ) || ! hash_equals( $match['payment_id'], (string) $order['tenders'][0]['payment_id'] )
			|| ! isset( $payment['id'], $payment['order_id'], $payment['location_id'], $payment['status'] )
			|| ! hash_equals( $match['payment_id'], $payment['id'] )
			|| ! hash_equals( $match['order_id'], $payment['order_id'] )
			|| ! hash_equals( $match['location_id'], $payment['location_id'] )
			|| 'COMPLETED' !== $payment['status']
			|| ! hash_equals( $scope['environment'], $match['environment'] )
			|| ! hash_equals( $scope['merchant_id'], $match['merchant_id'] )
			|| ! hash_equals( $scope['location_id'], $match['location_id'] )
			|| ! hash_equals( $scope['subscription_plan_variation_id'], $match['plan_variation_id'] )
		) {
			return false;
		}
		if ( isset( $payment['customer_id'] ) && ! hash_equals( $match['customer_id'], $payment['customer_id'] ) ) {
			return false;
		}
		return true;
	}

	private function price_matches( $money, $price ) {
		return is_array( $money ) && isset( $money['amount'], $money['currency'] ) && is_int( $money['amount'] ) && $money['amount'] === $price['amount'] && is_string( $money['currency'] ) && $money['currency'] === $price['currency'];
	}

	private function classify_api_error( $error, $resource ) {
		$code = is_wp_error( $error ) ? $error->get_error_code() : '';
		if ( false !== strpos( $code, '_response_invalid' ) || false !== strpos( $code, '_id_invalid' ) ) {
			return $this->result( 'reconciliation_required', $resource . '_response_invalid' );
		}
		return $this->result( 'retry', $resource . '_api_unavailable' );
	}

	private function result( $status, $reason_code ) {
		return array( 'status' => $status, 'reason_code' => $reason_code );
	}
}

/** Build the independent contract snapshot persistence service. No hooks are registered. */
function egov_law_monitor_square_subscription_sync_service() {
	global $wpdb;
	$client = Egov_Law_Monitor_Square_Api_Client::from_wordpress_config();
	if ( is_wp_error( $client ) ) {
		return new WP_Error( 'square_subscription_sync_unavailable', '契約同期を利用できません。' );
	}
	$checkout_repository = new Egov_Law_Monitor_Checkout_Repository( $wpdb );
	$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $checkout_repository, $client );
	$confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $checkout_repository, $client, $payment_matcher );
	$matcher = new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $checkout_repository, $client, $confirmation );
	$service = new Egov_Law_Monitor_Square_Subscription_Sync_Service( new Egov_Law_Monitor_Square_Subscription_Contract_Repository( $wpdb ), $client, $matcher );
	return apply_filters( 'egov_law_monitor_square_subscription_sync_service', $service, $wpdb );
}
