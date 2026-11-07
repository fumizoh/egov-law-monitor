<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Build the read-only initial-payment service only after an explicit POST. */
function egov_law_monitor_admin_square_initial_read_service() {
	$injected = apply_filters( 'egov_law_monitor_admin_square_initial_read_service', null );
	if ( null !== $injected ) {
		return $injected;
	}
	$client = Egov_Law_Monitor_Square_Api_Client::from_wordpress_config();
	if ( is_wp_error( $client ) ) {
		return $client;
	}
	global $wpdb;
	$repository = new Egov_Law_Monitor_Checkout_Repository( $wpdb );
	$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $repository, $client );
	$payment_confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $repository, $client, $payment_matcher );
	$subscription_matcher = new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $repository, $client, $payment_confirmation );
	$initial_payment_reader = new Egov_Law_Monitor_Square_Initial_Payment_Read_Service( $wpdb, $repository, $client, $payment_confirmation, $subscription_matcher );
	return new Egov_Law_Monitor_Square_Initial_Billing_Period_Inspection_Service( $wpdb, $repository, $client, $initial_payment_reader );
}

/** Build the read-only recurring-payment service only after an explicit POST. */
function egov_law_monitor_admin_square_recurring_read_service() {
	$injected = apply_filters( 'egov_law_monitor_admin_square_recurring_read_service', null );
	if ( null !== $injected ) {
		return $injected;
	}
	$client = Egov_Law_Monitor_Square_Api_Client::from_wordpress_config();
	if ( is_wp_error( $client ) ) {
		return $client;
	}
	global $wpdb;
	return new Egov_Law_Monitor_Square_Recurring_Billing_Inspection_Service( $wpdb, $client );
}

/** Build the read-only approval preflight only for an explicit administrator action. */
function egov_law_monitor_admin_square_approval_validation_service() {
	$injected = apply_filters( 'egov_law_monitor_admin_square_approval_validation_service', null );
	if ( null !== $injected ) { return $injected; }
	$initial = egov_law_monitor_admin_square_initial_read_service();
	if ( is_wp_error( $initial ) ) { return $initial; }
	$recurring = egov_law_monitor_admin_square_recurring_read_service();
	if ( is_wp_error( $recurring ) ) { return $recurring; }
	global $wpdb;
	return new Egov_Law_Monitor_Square_Admin_Approval_Validation_Service( $wpdb, $initial, $recurring, new Egov_Law_Monitor_Square_Admin_Review_Repository( $wpdb ) );
}

/** Build the atomic approval service only for the final explicit POST. */
function egov_law_monitor_admin_square_approval_service() {
	$injected = apply_filters( 'egov_law_monitor_admin_square_approval_service', null );
	if ( null !== $injected ) { return $injected; }
	$validation = egov_law_monitor_admin_square_approval_validation_service();
	if ( is_wp_error( $validation ) ) { return $validation; }
	global $wpdb;
	return new Egov_Law_Monitor_Square_Admin_Approval_Service( $wpdb, $validation, new Egov_Law_Monitor_Square_Admin_Review_Repository( $wpdb ) );
}

/** Render the Square payment review screen; Square is queried only for a valid POST. */
function egov_law_monitor_render_admin_square_payments() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'このページにアクセスする権限がありません。' );
		return;
	}

	$inspection = null;
	$notice = '';
	$approval_validation = null;
	$approval_outcome = null;
	$workflow_type = null;
	$workflow_id = 0;
	$workflow_note = '';
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) && isset( $_POST['square_admin_action'] ) ) {
		$action = isset( $_POST['square_admin_action'] ) && is_string( $_POST['square_admin_action'] ) ? sanitize_key( wp_unslash( $_POST['square_admin_action'] ) ) : '';
		$workflow_type = isset( $_POST['record_type'] ) && is_string( $_POST['record_type'] ) ? sanitize_key( wp_unslash( $_POST['record_type'] ) ) : '';
		$raw_id = isset( $_POST['local_id'] ) ? wp_unslash( $_POST['local_id'] ) : null;
		$workflow_id = is_string( $raw_id ) && preg_match( '/\A[1-9][0-9]*\z/', $raw_id ) && false !== filter_var( $raw_id, FILTER_VALIDATE_INT ) ? (int) $raw_id : 0;
		$allowed_actions = array( 'validate', 'approve', 'reconcile' );
		$nonce = isset( $_POST['square_admin_approval_nonce'] ) && is_string( $_POST['square_admin_approval_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['square_admin_approval_nonce'] ) ) : '';
		$nonce_action = $workflow_id > 0 && in_array( $workflow_type, array( 'initial', 'recurring' ), true ) && in_array( $action, $allowed_actions, true )
			? 'egov_square_approval_' . $action . '_' . $workflow_type . '_' . $workflow_id
			: '';
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'この操作を実行する権限がありません。' );
		} elseif ( '' === $nonce_action || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$notice = 'リクエストを確認できませんでした。画面を再読み込みして再度お試しください。';
		} else {
			global $wpdb;
			$local = egov_law_monitor_admin_square_payment_local_target( $workflow_type, $workflow_id, $wpdb );
			if ( is_wp_error( $local ) ) {
				$notice = '対象の申込・契約を確認できません。';
			} elseif ( 'validate' === $action ) {
				$validation_service = egov_law_monitor_admin_square_approval_validation_service();
				if ( is_wp_error( $validation_service ) ) {
					$notice = 'Square設定または決済情報を確認できません。';
				} else {
					try {
						$approval_validation = 'initial' === $workflow_type
							? $validation_service->validate_initial_approval( $workflow_id )
							: $validation_service->validate_recurring_approval( $workflow_id );
					} catch ( Throwable $error ) {
						$notice = '承認条件を確認できませんでした。';
					}
					if ( is_wp_error( $approval_validation ) ) {
						$notice = '承認条件を確認できませんでした。';
						$approval_validation = null;
					}
				}
			} elseif ( 'approve' === $action ) {
				$raw_note = isset( $_POST['admin_note'] ) && is_string( $_POST['admin_note'] ) ? wp_unslash( $_POST['admin_note'] ) : '';
				$workflow_note = sanitize_textarea_field( $raw_note );
				$note_length = function_exists( 'mb_strlen' ) ? mb_strlen( $workflow_note, 'UTF-8' ) : preg_match_all( '/./us', $workflow_note );
				if ( false === $note_length || $note_length > 2000 ) {
					$notice = '管理者メモは2,000文字以内で入力してください。';
				} else {
					$approval_service = egov_law_monitor_admin_square_approval_service();
					if ( is_wp_error( $approval_service ) ) {
						$approval_outcome = array( 'status' => 'square_error' );
					} else {
						try {
							$approval_outcome = 'initial' === $workflow_type
								? $approval_service->approve_initial( $workflow_id, get_current_user_id(), $workflow_note )
								: $approval_service->approve_recurring( $workflow_id, get_current_user_id(), $workflow_note );
						} catch ( Throwable $error ) {
							$approval_outcome = new WP_Error( 'square_admin_approval_database_error', '承認処理を完了できませんでした。' );
						}
						if ( is_wp_error( $approval_outcome ) && 'square_admin_approval_commit_outcome_unknown' === $approval_outcome->get_error_code() ) {
							$error_data = $approval_outcome->get_error_data();
							$target = is_array( $error_data ) && is_array( $error_data['reconciliation_target'] ?? null ) ? $error_data['reconciliation_target'] : null;
							if ( is_array( $target ) && (int) ( $target['local_id'] ?? 0 ) === $workflow_id && ( $target['type'] ?? null ) === $workflow_type ) {
								set_transient( egov_law_monitor_admin_square_reconciliation_transient_key( $workflow_type, $workflow_id, get_current_user_id() ), $target, 12 * HOUR_IN_SECONDS );
							}
						}
					}
				}
			} elseif ( 'reconcile' === $action ) {
				$transient_key = egov_law_monitor_admin_square_reconciliation_transient_key( $workflow_type, $workflow_id, get_current_user_id() );
				$target = get_transient( $transient_key );
				if ( ! is_array( $target ) ) {
					$approval_outcome = array( 'status' => 'unavailable', 'reason_code' => 'reconciliation_target_expired' );
				} else {
					$approval_service = egov_law_monitor_admin_square_approval_service();
					if ( is_wp_error( $approval_service ) ) {
						$approval_outcome = array( 'status' => 'unavailable', 'reason_code' => 'approval_service_unavailable' );
					} else {
						try { $approval_outcome = $approval_service->reconcile_commit_outcome( $target, get_current_user_id() ); }
						catch ( Throwable $error ) { $approval_outcome = array( 'status' => 'unavailable', 'reason_code' => 'reconciliation_failed' ); }
						if ( is_wp_error( $approval_outcome ) ) { $approval_outcome = array( 'status' => 'unavailable', 'reason_code' => $approval_outcome->get_error_code() ); }
						if ( is_array( $approval_outcome ) && 'approved' === ( $approval_outcome['status'] ?? null ) ) { delete_transient( $transient_key ); }
						if ( is_array( $approval_outcome ) && 'not_approved' === ( $approval_outcome['status'] ?? null ) ) { delete_transient( $transient_key ); }
					}
				}
			}
		}
	}
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) && isset( $_POST['egov_square_payment_inspect'] ) ) {
		$record_type = isset( $_POST['record_type'] ) && is_string( $_POST['record_type'] ) ? sanitize_key( wp_unslash( $_POST['record_type'] ) ) : '';
		$raw_id = isset( $_POST['local_id'] ) ? wp_unslash( $_POST['local_id'] ) : null;
		$local_id = is_string( $raw_id ) && preg_match( '/\A[1-9][0-9]*\z/', $raw_id ) && false !== filter_var( $raw_id, FILTER_VALIDATE_INT ) ? (int) $raw_id : 0;
		$nonce = isset( $_POST['egov_square_payment_nonce'] ) && is_string( $_POST['egov_square_payment_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['egov_square_payment_nonce'] ) ) : '';
		$nonce_action = $local_id > 0 && in_array( $record_type, array( 'initial', 'recurring' ), true )
			? 'egov_square_payment_inspect_' . $record_type . '_' . $local_id
			: '';
		if ( '' === $nonce_action || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$notice = 'リクエストを確認できませんでした。画面を再読み込みして再度お試しください。';
		} else {
			$can_inspect = true;
			if ( 'initial' === $record_type ) {
				global $wpdb;
				$attempt_table = $wpdb->prefix . 'egov_law_checkout_attempts';
				$wpdb->last_error = '';
				$attempt_row = $wpdb->get_row( $wpdb->prepare( "SELECT status, square_order_id FROM {$attempt_table} WHERE id = %d AND plan_code = 'standard'", $local_id ), ARRAY_A );
				if ( '' !== (string) $wpdb->last_error || ! is_array( $attempt_row ) ) {
					$can_inspect = false;
					$notice = '対象の申込情報を確認できません。';
				} elseif ( ! is_string( $attempt_row['square_order_id'] ) || '' === trim( $attempt_row['square_order_id'] ) ) {
					$can_inspect = false;
					$notice = 'Square Order IDが記録されていないため、決済情報を照会できません。';
				} elseif ( ! in_array( $attempt_row['status'], array( 'pending', 'reconciliation_required' ), true ) ) {
					$can_inspect = false;
					$notice = '申込処理中のため、まだ決済情報を照会できません。';
				}
			}
			$service = $can_inspect
				? ( 'initial' === $record_type
					? egov_law_monitor_admin_square_initial_read_service()
					: egov_law_monitor_admin_square_recurring_read_service() )
				: null;
			if ( ! $can_inspect ) {
				// Keep the notice above; ineligible attempts must not reach a read service.
			} elseif ( is_wp_error( $service ) ) {
				$notice = 'Squareの設定を確認できないため、決済情報を取得できませんでした。';
			} elseif ( 'initial' === $record_type && is_object( $service ) && is_callable( array( $service, 'inspect_attempt' ) ) ) {
				try {
					$inspection = $service->inspect_attempt( $local_id );
				} catch ( Throwable $error ) {
					$notice = '決済情報を取得できませんでした。';
				}
			} elseif ( 'recurring' === $record_type && is_object( $service ) && is_callable( array( $service, 'inspect_contract' ) ) ) {
				try {
					$inspection = $service->inspect_contract( $local_id );
				} catch ( Throwable $error ) {
					$notice = '決済情報を取得できませんでした。';
				}
			} else {
				$notice = '決済情報を取得できませんでした。';
			}
			if ( is_wp_error( $inspection ) ) {
				$inspection = null;
				$notice = '決済情報を取得できませんでした。';
			}
		}
	}

	global $wpdb;
	$page_value = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? (string) wp_unslash( $_GET['paged'] ) : '1';
	$validated_page = preg_match( '/\A[1-9][0-9]*\z/', $page_value ) ? filter_var( $page_value, FILTER_VALIDATE_INT ) : false;
	$page = false !== $validated_page && $validated_page <= 1000000 ? (int) $validated_page : 1;
	$per_page = 20;
	$attempts = $wpdb->prefix . 'egov_law_checkout_attempts';
	$subscriptions = $wpdb->prefix . 'egov_law_subscriptions';
	$sql = "SELECT * FROM (
		SELECT 'initial' AS record_type, a.id AS local_id, a.user_id, a.environment, a.merchant_id, a.square_subscription_id, a.status AS local_status, a.created_at, NULL AS last_checked_at, s.id AS related_local_id, a.square_order_id
		FROM {$attempts} a
		LEFT JOIN {$subscriptions} s ON s.checkout_attempt_uuid = a.attempt_uuid AND s.user_id = a.user_id AND s.environment = a.environment AND s.merchant_id = a.merchant_id AND s.plan_code = 'standard'
		WHERE a.plan_code = 'standard' AND a.status IN ('creating', 'pending', 'reconciliation_required')
		UNION ALL
		SELECT 'recurring' AS record_type, s.id AS local_id, s.user_id, s.environment, s.merchant_id, s.square_subscription_id, s.internal_status AS local_status, s.created_at, s.last_synced_at AS last_checked_at, a.id AS related_local_id, NULL AS square_order_id
		FROM {$subscriptions} s
		LEFT JOIN {$attempts} a ON a.attempt_uuid = s.checkout_attempt_uuid AND a.user_id = s.user_id AND a.environment = s.environment AND a.merchant_id = s.merchant_id AND a.plan_code = 'standard'
		WHERE s.plan_code = 'standard' AND s.square_subscription_id IS NOT NULL AND s.square_subscription_id <> ''
	) AS payment_rows ORDER BY created_at DESC, record_type ASC, local_id DESC LIMIT %d OFFSET %d";
	$count_sql = "SELECT COUNT(*) FROM (
		SELECT id FROM {$attempts} WHERE plan_code = 'standard' AND status IN ('creating', 'pending', 'reconciliation_required')
		UNION ALL
		SELECT id FROM {$subscriptions} WHERE plan_code = 'standard' AND square_subscription_id IS NOT NULL AND square_subscription_id <> ''
	) AS payment_rows";
	$wpdb->last_error = '';
	$total_value = $wpdb->get_var( $count_sql );
	$total_failed = '' !== (string) $wpdb->last_error || false === $total_value || null === $total_value;
	$total = $total_failed ? 0 : (int) $total_value;
	$total_pages = (int) ceil( $total / $per_page );
	$page = $total_pages > 0 ? min( $page, $total_pages ) : 1;
	$offset = ( $page - 1 ) * $per_page;
	$rows = array();
	$list_failed = false;
	if ( ! $total_failed ) {
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $per_page, $offset ), ARRAY_A );
		$list_failed = ! is_array( $rows ) || '' !== (string) $wpdb->last_error;
	}
	$list_unavailable = $list_failed || $total_failed;
	if ( $list_unavailable ) {
		$notice = '決済管理情報を取得できませんでした。';
		$rows = array();
		$total = 0;
	}
	echo '<div class="wrap"><h1>Square決済管理</h1>';
	echo '<p>Squareの決済情報は、各行の取得操作を実行した場合のみ照会されます。決済完了の確認はStandard利用期間や権限の承認を意味しません。</p>';
	if ( '' !== $notice ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>';
	}
	if ( is_array( $inspection ) ) {
		egov_law_monitor_render_square_payment_inspection( $inspection, $record_type ?? null );
		if ( isset( $record_type, $local_id ) && in_array( $record_type, array( 'initial', 'recurring' ), true ) && is_int( $local_id ) && $local_id > 0 ) {
			egov_law_monitor_render_square_admin_action_form( 'validate', $record_type, $local_id, '承認候補を確認', '承認条件を再検証します。' );
		}
	}
	if ( is_array( $approval_validation ) ) {
		egov_law_monitor_render_square_approval_validation( $approval_validation, $workflow_type, $workflow_id, $workflow_note );
	}
	if ( is_wp_error( $approval_outcome ) || is_array( $approval_outcome ) ) {
		egov_law_monitor_render_square_approval_outcome( $approval_outcome, $workflow_type, $workflow_id );
	}
	echo '<table class="widefat striped"><thead><tr><th>区分</th><th>WordPressユーザーID</th><th>ローカルID</th><th>関連する申込・契約</th><th>Square環境</th><th>Merchant ID</th><th>Square Subscription ID</th><th>ローカル契約状態</th><th>保存済み同期日時</th><th>操作</th></tr></thead><tbody>';
	if ( $list_unavailable ) {
		echo '<tr><td colspan="10">決済管理情報を取得できませんでした。</td></tr>';
	} elseif ( ! is_array( $rows ) || empty( $rows ) ) {
		echo '<tr><td colspan="10">確認対象の申込・契約はありません。</td></tr>';
	} else {
		foreach ( $rows as $row ) {
			$type = 'initial' === $row['record_type'] ? '初回' : '継続';
			$nonce_action = 'egov_square_payment_inspect_' . $row['record_type'] . '_' . (int) $row['local_id'];
			echo '<tr>';
			echo '<td>' . esc_html( $type ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['user_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['local_id'] ) . '</td>';
			$related_label = null !== $row['related_local_id']
				? ( 'initial' === $row['record_type'] ? '関連契約ID #' : '関連申込ID #' ) . (string) (int) $row['related_local_id']
				: '—';
			echo '<td>' . esc_html( $related_label ) . '</td>';
			echo '<td>' . esc_html( $row['environment'] ) . '</td>';
			echo '<td>' . esc_html( $row['merchant_id'] ) . '</td>';
			echo '<td>' . esc_html( ! empty( $row['square_subscription_id'] ) ? $row['square_subscription_id'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $row['local_status'] ) . '</td>';
			echo '<td>' . esc_html( ! empty( $row['last_checked_at'] ) ? $row['last_checked_at'] : '—' ) . '</td>';
			$can_inspect = 'initial' !== $row['record_type']
				|| ( in_array( $row['local_status'], array( 'pending', 'reconciliation_required' ), true ) && is_string( $row['square_order_id'] ) && '' !== trim( $row['square_order_id'] ) );
			if ( $can_inspect ) {
				echo '<td><form method="post" action="' . esc_url( admin_url( 'admin.php?page=egov-law-monitor-square-payments' ) ) . '">';
				echo '<input type="hidden" name="egov_square_payment_inspect" value="1">';
				echo '<input type="hidden" name="record_type" value="' . esc_attr( $row['record_type'] ) . '">';
				echo '<input type="hidden" name="local_id" value="' . esc_attr( (string) (int) $row['local_id'] ) . '">';
				echo '<input type="hidden" name="egov_square_payment_nonce" value="' . esc_attr( wp_create_nonce( $nonce_action ) ) . '">';
				echo '<button type="submit" class="button">決済情報を取得</button></form></td>';
			} else {
				$unavailable_reason = empty( $row['square_order_id'] )
					? 'Square Order ID未記録のため照会できません。'
					: '申込処理中のため照会できません。';
				echo '<td><span>' . esc_html( $unavailable_reason ) . '</span></td>';
			}
			echo '</tr>';
		}
	}
	echo '</tbody></table>';
	if ( $total_pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array(
			'base' => add_query_arg( array( 'page' => 'egov-law-monitor-square-payments', 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
			'format' => '', 'current' => $page, 'total' => $total_pages,
		) ) ) . '</div></div>';
	}
	echo '</div>';
}

/** Confirm that the posted identifier refers to a local Standard target. */
function egov_law_monitor_admin_square_payment_local_target( $type, $id, $wpdb ) {
	if ( ! is_int( $id ) || $id < 1 || ! in_array( $type, array( 'initial', 'recurring' ), true ) ) { return new WP_Error( 'square_admin_target_invalid' ); }
	$table = 'initial' === $type ? $wpdb->prefix . 'egov_law_checkout_attempts' : $wpdb->prefix . 'egov_law_subscriptions';
	$where = 'initial' === $type ? "id = %d AND plan_code = 'standard'" : "id = %d AND plan_code = 'standard' AND square_subscription_id IS NOT NULL AND square_subscription_id <> ''";
	$wpdb->last_error = '';
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$table} WHERE {$where}", $id ), ARRAY_A );
	if ( '' !== (string) $wpdb->last_error ) { return new WP_Error( 'square_admin_target_database_error' ); }
	return is_array( $row ) ? $row : new WP_Error( 'square_admin_target_not_found' );
}

function egov_law_monitor_admin_square_reconciliation_transient_key( $type, $id, $user_id ) {
	return 'egov_sq_reconcile_' . substr( hash_hmac( 'sha256', $type . '|' . (int) $id . '|' . (int) $user_id, wp_salt( 'auth' ) ), 0, 48 );
}

function egov_law_monitor_render_square_admin_action_form( $action, $type, $id, $label, $description = '' ) {
	$nonce_action = 'egov_square_approval_' . $action . '_' . $type . '_' . (int) $id;
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=egov-law-monitor-square-payments' ) ) . '" class="egov-square-approval-action">';
	echo '<input type="hidden" name="square_admin_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="record_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="local_id" value="' . esc_attr( (string) (int) $id ) . '">';
	echo '<input type="hidden" name="square_admin_approval_nonce" value="' . esc_attr( wp_create_nonce( $nonce_action ) ) . '">';
	if ( '' !== $description ) { echo '<p>' . esc_html( $description ) . '</p>'; }
	echo '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
}

/** Render verified preflight data; only an eligible result gets an approval POST. */
function egov_law_monitor_render_square_approval_validation( array $validation, $type, $id, $admin_note = '' ) {
	global $wpdb;
	$local = egov_law_monitor_admin_square_payment_local_target( $type, (int) $id, $wpdb );
	$user_id = is_array( $local ) ? (int) $local['user_id'] : 0;
	$evidence = is_array( $validation['payment_evidence'] ?? null ) ? $validation['payment_evidence'] : array();
	$invoice = is_array( $validation['verified_invoice'] ?? null ) ? $validation['verified_invoice'] : array();
	$payment = is_array( $validation['verified_payment'] ?? null ) ? $validation['verified_payment'] : array();
	$subscription = is_array( $validation['verified_subscription'] ?? null ) ? $validation['verified_subscription'] : array();
	$subscription_summary = is_array( $validation['subscription_verification'] ?? null ) ? $validation['subscription_verification'] : array();
	$candidate = is_array( $validation['period_candidate'] ?? null ) ? $validation['period_candidate'] : array();
	$eligible = true === ( $validation['approval_eligible'] ?? false ) && 'validation_passed' === ( $validation['verification_status'] ?? null ) && empty( $validation['approved'] );
	$reason_codes = is_array( $validation['reason_codes'] ?? null ) ? $validation['reason_codes'] : array();
	$reason_code = $eligible ? 'approval_eligible' : ( isset( $reason_codes[0] ) && is_string( $reason_codes[0] ) ? $reason_codes[0] : 'reason_unavailable' );
	$payment_id = $evidence['payment_id'] ?? ( $payment['id'] ?? null );
	$invoice_id = $invoice['id'] ?? ( $evidence['invoice_id'] ?? null );
	$subscription_id = $subscription['id'] ?? ( $subscription_summary['subscription_id'] ?? ( $validation['subscription_link']['subscription_id'] ?? null ) );
	$period_available = 'candidate' === ( $candidate['status'] ?? null ) && is_string( $candidate['start_date'] ?? null ) && is_string( $candidate['end_exclusive_date'] ?? null );
	$inclusive_end = $candidate['end_date_inclusive'] ?? null;
	if ( $period_available && ( ! is_string( $inclusive_end ) || '' === $inclusive_end ) ) {
		$end = DateTimeImmutable::createFromFormat( '!Y-m-d', $candidate['end_exclusive_date'], new DateTimeZone( $candidate['timezone'] ?? 'UTC' ) );
		$inclusive_end = false !== $end ? $end->modify( '-1 day' )->format( 'Y-m-d' ) : null;
	}
	echo '<section class="notice ' . ( $eligible ? 'notice-warning' : 'notice-error' ) . ' egov-square-approval-candidate"><h2>管理者承認の事前確認</h2><p><strong>' . esc_html( $eligible ? '承認内容を確認してください。まだ権限は変更されていません。' : '通常承認できません。' ) . '</strong></p><dl>';
	egov_law_monitor_square_payment_result_item( '対象ユーザー', $user_id > 0 ? 'WordPressユーザー #' . $user_id : '確認できず' );
	egov_law_monitor_square_payment_result_item( '区分', 'initial' === $type ? '初回決済' : '継続決済' );
	egov_law_monitor_square_payment_result_item( 'ローカル対象ID', (string) (int) $id );
	egov_law_monitor_square_payment_result_item( 'Payment ID', $payment_id );
	egov_law_monitor_square_payment_result_item( 'Invoice ID', $invoice_id );
	egov_law_monitor_square_payment_result_item( 'Subscription ID', $subscription_id );
	egov_law_monitor_square_payment_result_item( '決済確認', ! empty( $evidence['confirmed'] ) ? '決済完了を確認' : '確認できず' );
	egov_law_monitor_square_payment_result_item( '決済額・通貨', isset( $evidence['amount'], $evidence['currency'] ) ? number_format_i18n( (int) $evidence['amount'] ) . ' ' . $evidence['currency'] : '確認できず' );
	egov_law_monitor_square_payment_result_item( 'Subscription照合', 'confirmed' === ( $validation['subscription_link']['status'] ?? '' ) || 'identified_from_local_contract' === ( $validation['subscription_link']['status'] ?? '' ) ? '確認済み' : '要確認' );
	egov_law_monitor_square_payment_result_item( '利用開始日', $period_available ? $candidate['start_date'] : '要確認' );
	egov_law_monitor_square_payment_result_item( '利用終了日', $period_available && is_string( $inclusive_end ) ? $inclusive_end . '（最終利用日）' : '要確認' );
	egov_law_monitor_square_payment_result_item( '終了境界（exclusive）', $period_available ? $candidate['end_exclusive_date'] : '要確認' );
	egov_law_monitor_square_payment_result_item( 'Timezone', $candidate['timezone'] ?? ( $validation['timezone'] ?? null ) );
	egov_law_monitor_square_payment_result_item( '月次課金アンカー', $candidate['monthly_billing_anchor_date'] ?? ( $validation['monthly_billing_anchor_date'] ?? null ) );
	egov_law_monitor_square_payment_result_item( '承認可否', $eligible ? '通常承認可能（管理者の最終確認が必要）' : '承認不可・要確認' );
	egov_law_monitor_square_payment_result_item( '理由', $eligible ? '検証条件を満たしました。' : egov_law_monitor_square_reason_label( $reason_code ) );
	egov_law_monitor_square_payment_result_item( '理由コード', $reason_code );
	echo '</dl>';
	if ( $eligible ) {
		echo '<p>Square ID・金額・期間はこのフォームから送信されません。最終操作時にSquare情報とDBを再検証します。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=egov-law-monitor-square-payments' ) ) . '" class="egov-square-approval-final" onsubmit="const b=this.querySelector(\'button[type=submit]\');if(b){b.disabled=true;}">';
		echo '<input type="hidden" name="square_admin_action" value="approve"><input type="hidden" name="record_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="local_id" value="' . esc_attr( (string) (int) $id ) . '">';
		echo '<input type="hidden" name="square_admin_approval_nonce" value="' . esc_attr( wp_create_nonce( 'egov_square_approval_approve_' . $type . '_' . (int) $id ) ) . '">';
		echo '<label for="egov-square-admin-note"><strong>管理者メモ（任意、2,000文字以内）</strong></label><textarea id="egov-square-admin-note" name="admin_note" rows="4" maxlength="2000" class="large-text"></textarea><p>パスワード、APIキー、カード情報などの機密情報は入力しないでください。</p>';
		echo '<p><button type="submit" class="button button-primary" data-confirm="1">この内容で承認する</button></p></form>';
	}
	echo '</section>';
}

function egov_law_monitor_render_square_approval_outcome( $outcome, $type, $id ) {
	if ( is_array( $outcome ) && 'square_error' === ( $outcome['status'] ?? null ) ) {
		echo '<div class="notice notice-error"><p>Square情報または承認サービスを利用できないため、承認していません。</p></div>';
		return;
	}
	if ( is_wp_error( $outcome ) ) {
		$code = $outcome->get_error_code();
		if ( 'square_admin_approval_commit_outcome_unknown' === $code ) {
			echo '<div class="notice notice-warning"><p><strong>承認結果を確認できません。再確認してください。</strong> 成功・失敗はまだ確定していません。</p></div>';
			egov_law_monitor_render_square_admin_action_form( 'reconcile', $type, $id, '承認結果を再確認', '同じ承認対象の履歴・契約・Invoiceを読み取り専用で照合します。別決済による自動再試行は行いません。' );
			return;
		}
		$messages = array(
			'square_admin_approval_duplicate' => '既に承認済み、または重複承認です。',
			'square_admin_approval_database_error' => 'DB更新に失敗し、承認は確定していません。',
			'square_admin_approval_transaction_unavailable' => 'DBトランザクションを開始できず、承認していません。',
			'square_admin_approval_forbidden' => '承認権限がありません。',
			'square_admin_approval_validation_failed' => 'Square情報を取得できませんでした。決済失敗を意味しません。',
			'square_admin_approval_not_eligible' => '承認条件を満たしていません。',
			'square_admin_approval_payment_unconfirmed' => '決済完了の証拠を確認できません。',
			'square_admin_approval_invoice_unverified' => 'Invoiceとの対応を確認できません。',
			'square_admin_approval_period_invalid' => '利用期間を安全に確定できません。',
			'square_admin_approval_local_state_changed' => '確認後にローカル契約情報が変わったため、承認していません。再取得してください。',
			'square_admin_approval_current_contract_conflict' => '現行契約が競合するため承認できません。',
			'square_admin_approval_contract_conflict' => '申込に関連する契約が競合しています。',
			'square_admin_approval_payment_conflict' => '対象Paymentが別の承認記録と競合しています。',
			'square_admin_approval_invoice_conflict' => '対象Invoiceが別の承認記録と競合しています。',
			'square_admin_approval_admin_suspended' => '管理者停止中の契約は通常承認できません。',
		);
		$message = $messages[ $code ] ?? '承認条件不一致またはDB処理エラーです。(' . $code . ')';
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		$error_data = $outcome->get_error_data();
		$reason_code = is_array( $error_data ) && is_string( $error_data['reason_code'] ?? null ) ? $error_data['reason_code'] : null;
		if ( null !== $reason_code ) {
			echo '<p>' . esc_html( egov_law_monitor_square_reason_label( $reason_code ) ) . '</p>';
		}
		return;
	}
	if ( is_array( $outcome ) && in_array( $outcome['status'] ?? null, array( 'approved', 'not_approved', 'inconsistent', 'unavailable' ), true ) && isset( $outcome['reason_code'] ) ) {
		$labels = array( 'approved' => '承認済み', 'not_approved' => '未承認', 'inconsistent' => '不整合があります。管理者による個別確認が必要です。', 'unavailable' => '確認不能です。自動承認は行っていません。' );
		echo '<div class="notice ' . ( 'approved' === $outcome['status'] ? 'notice-success' : ( 'not_approved' === $outcome['status'] ? 'notice-warning' : 'notice-error' ) ) . '"><p><strong>再照合結果：' . esc_html( $labels[ $outcome['status'] ] ) . '</strong></p>';
		if ( isset( $outcome['reason_code'] ) ) { echo '<p>' . esc_html( (string) $outcome['reason_code'] ) . '</p>'; }
		if ( 'approved' === $outcome['status'] && isset( $outcome['contract_id'] ) ) {
			$contract_id = (int) $outcome['contract_id'];
			egov_law_monitor_render_square_approved_period( $contract_id );
			$user_id = (int) egov_law_monitor_square_contract_user_id( $contract_id );
			egov_law_monitor_render_square_entitlement_after_approval( $user_id );
		}
		echo '</div>';
		if ( 'inconsistent' === $outcome['status'] || 'unavailable' === $outcome['status'] ) {
			egov_law_monitor_render_square_admin_action_form( 'reconcile', $type, $id, '同じ承認結果を再確認', '不整合・確認不能の場合は承認を再実行しません。' );
		}
		return;
	}
	if ( is_array( $outcome ) && 'approved' === ( $outcome['status'] ?? null ) ) {
		echo '<div class="notice notice-success"><p><strong>管理者承認が完了しました。</strong> 利用期間は ' . esc_html( (string) $outcome['period_start_utc'] ) . ' 〜 ' . esc_html( (string) $outcome['period_end_utc_exclusive'] ) . ' UTC（終了境界を含まない）です。</p>';
		egov_law_monitor_render_square_entitlement_after_approval( (int) egov_law_monitor_square_contract_user_id( (int) $outcome['contract_id'] ) );
		echo '</div>';
	}
}

function egov_law_monitor_square_contract_user_id( $contract_id ) {
	global $wpdb;
	$wpdb->last_error = '';
	$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}egov_law_subscriptions WHERE id = %d", $contract_id ) );
	return '' === (string) $wpdb->last_error && null !== $user_id ? (int) $user_id : 0;
}

function egov_law_monitor_render_square_approved_period( $contract_id ) {
	global $wpdb;
	$wpdb->last_error = '';
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT current_period_start, current_period_end FROM {$wpdb->prefix}egov_law_subscriptions WHERE id = %d", (int) $contract_id ), ARRAY_A );
	echo '<dl>';
	if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || empty( $row['current_period_start'] ) || empty( $row['current_period_end'] ) ) {
		egov_law_monitor_square_payment_result_item( '承認済み利用期間', '確認できません' );
		echo '</dl>';
		return;
	}
	egov_law_monitor_square_payment_result_item( '承認済み利用期間（UTC、終了境界exclusive）', $row['current_period_start'] . ' 〜 ' . $row['current_period_end'] );
	echo '</dl>';
}

function egov_law_monitor_render_square_entitlement_after_approval( $user_id ) {
	if ( $user_id < 1 ) { egov_law_monitor_square_payment_result_item( '現在の権限', '確認できません' ); return; }
	$entitlement = egov_law_monitor_get_effective_entitlement( $user_id );
	if ( is_wp_error( $entitlement ) || ! is_array( $entitlement ) ) { egov_law_monitor_square_payment_result_item( '現在の権限', '取得失敗（再確認が必要）' ); return; }
	$plan = $entitlement['effective_plan'] ?? ( $entitlement['plan'] ?? 'unknown' );
	$reason = $entitlement['reason_code'] ?? 'reason_unavailable';
	echo '<dl>';
	egov_law_monitor_square_payment_result_item( '現在の権限', ( 'standard' === $plan ? 'Standard' : ( 'free' === $plan ? 'Free' : '不明' ) ) . '（' . $reason . '）' );
	echo '</dl>';
}

/** Render normalized read-only payment evidence without exposing raw errors or PII. */
function egov_law_monitor_render_square_payment_inspection( array $result, $record_type = null ) {
	$is_initial = 'initial' === $record_type || isset( $result['initial_payment'] ) || isset( $result['billing_conditions'] );
	$evidence = $is_initial
		? ( is_array( $result['initial_payment'] ?? null ) ? $result['initial_payment'] : ( is_array( $result['payment_evidence'] ?? null ) ? $result['payment_evidence'] : array() ) )
		: ( is_array( $result['payment_evidence'] ?? null ) ? $result['payment_evidence'] : array() );
	$invoice_scope = isset( $result['invoice_scope'] ) && is_array( $result['invoice_scope'] ) ? $result['invoice_scope'] : array();
	$period_candidate = isset( $result['period_candidate'] ) && is_array( $result['period_candidate'] ) ? $result['period_candidate'] : array();
	$period = isset( $period_candidate['billing_period'] ) && is_array( $period_candidate['billing_period'] ) ? $period_candidate['billing_period'] : ( isset( $result['billing_period'] ) && is_array( $result['billing_period'] ) ? $result['billing_period'] : array() );
	$invoice = isset( $result['invoice'] ) && is_array( $result['invoice'] ) ? $result['invoice'] : ( isset( $result['initial_invoice'] ) && is_array( $result['initial_invoice'] ) ? $result['initial_invoice'] : array() );
	$payment = isset( $result['payment'] ) && is_array( $result['payment'] ) ? $result['payment'] : array();
	$contract = isset( $result['contract'] ) && is_array( $result['contract'] ) ? $result['contract'] : array();
	$subscription = isset( $result['subscription_link'] ) && is_array( $result['subscription_link'] ) ? $result['subscription_link'] : array();
	if ( empty( $subscription ) && isset( $result['subscription'] ) && is_array( $result['subscription'] ) ) {
		$stored_id = $contract['subscription_id'] ?? null;
		$remote_id = $result['subscription']['id'] ?? null;
		$subscription = array(
			'status' => is_string( $stored_id ) && is_string( $remote_id ) && '' !== $stored_id && hash_equals( $stored_id, $remote_id ) ? 'confirmed' : 'unconfirmed',
			'subscription_id' => $remote_id,
		);
	}
	$payment_id = $evidence['payment_id'] ?? ( $payment['id'] ?? null );
	$order_id = $evidence['order_id'] ?? ( $invoice['order_id'] ?? ( $result['order_id'] ?? null ) );
	$invoice_id = $invoice['id'] ?? ( $invoice_scope['invoice_id'] ?? null );
	$amount = $evidence['amount'] ?? ( $payment['amount'] ?? ( $invoice['completed_amount'] ?? null ) );
	$currency = $evidence['currency'] ?? ( $payment['currency'] ?? ( $invoice['currency'] ?? null ) );
	$payment_confirmed = ! empty( $evidence['confirmed'] );
	$period_available = 'candidate' === ( $period_candidate['status'] ?? null ) && is_string( $period['start_date'] ?? null ) && is_string( $period['end_date_inclusive'] ?? null );
	$review_labels = array( 'required' => '追加確認が必要', 'awaiting_payment' => '決済確認待ち', 'retrieval_failed' => '情報取得失敗' );
	$scope_labels = array( 'latest_invoice_only' => '最新Invoiceのみ', 'initial_checkout_order_only' => '初回Checkout Order', 'all_invoice_ids_newer_than_last_approved_invoice' => '前回承認Invoice以降のInvoice ID' );
	$link_status = ( $subscription['status'] ?? '' ) === 'confirmed' ? '照合済み' : '未照合・要確認';
	$status_labels = array(
		'payment_confirmed' => '決済完了を確認（利用期間候補は別途確認）',
		'payment_pending' => '決済未完了または処理中',
		'payment_failed' => 'Squareで決済失敗を確認',
		'square_api_error' => 'Square情報の取得失敗（決済失敗を意味しません）',
		'reconciliation_required' => '照合・追加確認が必要',
		'review_required' => '照合・追加確認が必要',
	);
	$variation = isset( $result['billing_conditions']['variation'] ) && is_array( $result['billing_conditions']['variation'] ) ? $result['billing_conditions']['variation'] : ( isset( $result['variation'] ) && is_array( $result['variation'] ) ? $result['variation'] : array() );
	$subscription_snapshot = isset( $result['billing_conditions']['subscription'] ) && is_array( $result['billing_conditions']['subscription'] ) ? $result['billing_conditions']['subscription'] : ( isset( $result['subscription'] ) && is_array( $result['subscription'] ) ? $result['subscription'] : array() );
	$event_complete = null;
	$schedule_state = 'unknown';
	if ( isset( $result['billing_conditions'] ) && is_array( $result['billing_conditions'] ) ) {
		$event_data = $result['billing_conditions']['events'] ?? null;
		$event_complete = is_array( $event_data ) && array_key_exists( 'complete', $event_data ) ? (bool) $event_data['complete'] : null;
		$schedule_state = 'confirmed' === ( $result['billing_conditions']['status'] ?? '' ) && true === $event_complete ? 'none' : 'unknown';
	} elseif ( isset( $result['event_scope'] ) && is_array( $result['event_scope'] ) ) {
		$event_complete = ! empty( $result['event_scope']['complete'] );
		$schedule_state = $result['schedule_review']['state'] ?? 'unknown';
	}
	$reason_code = is_string( $result['reason_code'] ?? null ) ? $result['reason_code'] : 'reason_unavailable';

	echo '<section class="notice notice-info"><h2>決済情報の取得結果</h2><dl>';
	egov_law_monitor_square_payment_result_item( '区分', $is_initial ? '初回決済' : '継続決済' );
	egov_law_monitor_square_payment_result_item( '取得結果', $status_labels[ $result['status'] ?? '' ] ?? '状態不明・要確認' );
	egov_law_monitor_square_payment_result_item( '決済状態', $payment['status'] ?? ( $evidence['payment_status'] ?? ( $evidence['status'] ?? '確認できず' ) ) );
	egov_law_monitor_square_payment_result_item( '決済証拠', $payment_confirmed ? '決済完了を確認' : ( ( $evidence['status'] ?? '' ) === 'pending' ? '決済処理中または未完了' : '確認できず' ) );
	egov_law_monitor_square_payment_result_item( '金額・通貨', null !== $amount && null !== $currency ? number_format_i18n( (int) $amount ) . ' ' . $currency : '確認できず' );
	egov_law_monitor_square_payment_result_item( 'Payment ID', $payment_id );
	egov_law_monitor_square_payment_result_item( 'Order ID', $order_id );
	egov_law_monitor_square_payment_result_item( 'Invoice ID', $invoice_id );
	egov_law_monitor_square_payment_result_item( 'Subscription ID', $subscription['subscription_id'] ?? ( $contract['subscription_id'] ?? ( $subscription_snapshot['id'] ?? null ) ) );
	egov_law_monitor_square_payment_result_item( 'Subscriptionとの紐付け', $link_status );
	egov_law_monitor_square_payment_result_item( 'Subscription照合根拠', $subscription['method'] ?? ( $subscription['source'] ?? null ) );
	egov_law_monitor_square_payment_result_item( 'Square Subscription状態', $subscription_snapshot['status'] ?? null );
	egov_law_monitor_square_payment_result_item( 'Plan Variation', egov_law_monitor_square_plan_description( $variation ) );
	egov_law_monitor_square_payment_result_item( '課金条件', ( 'confirmed' === ( $result['billing_conditions']['status'] ?? '' ) || ! empty( $variation['single_monthly_phase'] ) ) ? '単一月次Phaseを確認' : '要確認' );
	egov_law_monitor_square_payment_result_item( '予定・過去変更', 'none' === $schedule_state ? '変更なしを確認' : '変更履歴または予定を要確認' );
	egov_law_monitor_square_payment_result_item( 'イベント履歴', true === $event_complete ? '完全取得' : ( false === $event_complete ? '不完全・要確認' : '完全性不明・要確認' ) );
	egov_law_monitor_square_payment_result_item( '確認したInvoice範囲', $scope_labels[ $invoice_scope['selection'] ?? '' ] ?? '範囲不明' );
	egov_law_monitor_square_payment_result_item( '前回承認済みInvoice ID', $invoice_scope['baseline_invoice_id'] ?? null );
	$checked_count = $invoice_scope['checked_invoice_count'] ?? ( $invoice_scope['requested_invoice_count'] ?? null );
	$unchecked_count = $invoice_scope['unchecked_invoice_count'] ?? ( isset( $invoice_scope['requested_invoice_count'], $invoice_scope['checked_invoice_count'] ) ? max( 0, (int) $invoice_scope['requested_invoice_count'] - (int) $invoice_scope['checked_invoice_count'] ) : null );
	egov_law_monitor_square_payment_result_item( 'Invoice件数（全体／確認／未確認）', sprintf( '全体: %s / 確認: %s / 未確認: %s', isset( $invoice_scope['invoice_total_count'] ) ? (string) $invoice_scope['invoice_total_count'] : '不明', null !== $checked_count ? (string) $checked_count : '不明', null !== $unchecked_count ? (string) $unchecked_count : '不明' ) );
	egov_law_monitor_square_payment_result_item( 'Invoice範囲の完全性', egov_law_monitor_square_invoice_scope_completeness( $invoice_scope ) );
	egov_law_monitor_square_payment_result_item( 'レビュー状態', $review_labels[ $result['review_status'] ?? '' ] ?? '不明（要確認）' );
	egov_law_monitor_square_payment_result_item( '利用期間候補', $period_available ? ( $period['start_date'] . ' 〜 ' . $period['end_date_inclusive'] . '（最終利用日）' ) : '要確認' );
	egov_law_monitor_square_payment_result_item( '次回期間開始日', $period_available ? ( $period['next_start_date'] ?? null ) : '要確認' );
	egov_law_monitor_square_payment_result_item( 'Timezone', $period['timezone'] ?? ( $subscription_snapshot['timezone'] ?? null ) );
	egov_law_monitor_square_payment_result_item( '月次課金アンカー', $subscription_snapshot['monthly_billing_anchor_date'] ?? null );
	egov_law_monitor_square_payment_result_item( '候補計算状態', $period_available ? '候補を算出（未承認）' : '要確認' );
	egov_law_monitor_square_payment_result_item( '候補計算根拠', is_array( $period_candidate['basis'] ?? null ) ? implode( ', ', $period_candidate['basis'] ) : ( $period_candidate['reason_code'] ?? '根拠不明' ) );
	egov_law_monitor_square_payment_result_item( '要確認理由', egov_law_monitor_square_reason_label( $reason_code ) );
	egov_law_monitor_square_payment_result_item( '理由コード', $reason_code );
	echo '</dl>';
	$invoice_results = is_array( $evidence['invoice_results'] ?? null ) ? $evidence['invoice_results'] : array();
	if ( ! empty( $invoice_results ) ) {
		echo '<h3>確認した継続Invoice</h3><table class="widefat striped"><thead><tr><th>Invoice ID</th><th>Payment ID</th><th>状態</th><th>金額・通貨</th><th>決済証拠</th><th>理由</th></tr></thead><tbody>';
		foreach ( $invoice_results as $invoice_result ) {
			if ( ! is_array( $invoice_result ) ) {
				continue;
			}
			$invoice_row = is_array( $invoice_result['invoice'] ?? null ) ? $invoice_result['invoice'] : array();
			$payment_row = is_array( $invoice_result['payment'] ?? null ) ? $invoice_result['payment'] : array();
			$row_amount = $invoice_row['completed_amount'] ?? ( $invoice_row['amount'] ?? null );
			$row_currency = $invoice_row['currency'] ?? null;
			echo '<tr><td>' . esc_html( (string) ( $invoice_row['id'] ?? '—' ) ) . '</td><td>' . esc_html( (string) ( $payment_row['id'] ?? '—' ) ) . '</td><td>' . esc_html( (string) ( $invoice_result['status'] ?? '不明' ) ) . '</td><td>' . esc_html( null !== $row_amount && null !== $row_currency ? number_format_i18n( (int) $row_amount ) . ' ' . $row_currency : '確認できず' ) . '</td><td>' . esc_html( 'confirmed' === ( $invoice_result['payment_evidence'] ?? '' ) ? '決済完了を確認' : '確認できず' ) . '</td><td>' . esc_html( egov_law_monitor_square_reason_label( $invoice_result['reason_code'] ?? 'reason_unavailable' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '<p><strong>決済完了の確認とStandard利用期間・権限の承認は別の判断です。この画面では権限を変更しません。</strong></p></section>';
}

function egov_law_monitor_square_invoice_scope_completeness( array $scope ) {
	if ( isset( $scope['unchecked_invoice_count'] ) && (int) $scope['unchecked_invoice_count'] > 0 ) {
		return '未確認Invoiceあり（不完全）';
	}
	if ( 'latest_invoice_only' === ( $scope['selection'] ?? '' ) ) {
		return '最新Invoiceのみ。過去を含む全体確認ではありません';
	}
	if ( isset( $scope['invoice_list_completeness'] ) ) {
		return '取得根拠: ' . (string) $scope['invoice_list_completeness'] . '。全履歴の完全性は未保証';
	}
	if ( isset( $scope['invoice_total_count'], $scope['checked_invoice_count'] ) && (int) $scope['invoice_total_count'] === (int) $scope['checked_invoice_count'] ) {
		return '表示対象のInvoiceをすべて確認';
	}
	return '完全性不明・要確認';
}

function egov_law_monitor_square_plan_description( array $variation ) {
	$parts = array();
	$phases = is_array( $variation['phases'] ?? null ) ? $variation['phases'] : array();
	foreach ( $phases as $phase ) {
		if ( ! is_array( $phase ) ) {
			continue;
		}
		$money = is_array( $phase['recurring_price_money'] ?? null ) ? $phase['recurring_price_money'] : array();
		$amount = isset( $money['amount'] ) && is_numeric( $money['amount'] ) ? number_format_i18n( (int) $money['amount'] ) : '金額不明';
		$parts[] = ( is_string( $phase['cadence'] ?? null ) ? $phase['cadence'] : 'cadence不明' ) . ' / ' . $amount . ' ' . ( is_string( $money['currency'] ?? null ) ? $money['currency'] : '通貨不明' );
	}
	$id = is_string( $variation['id'] ?? null ) ? $variation['id'] : null;
	return ( null !== $id ? $id . ': ' : '' ) . ( empty( $parts ) ? '課金条件を確認できず' : implode( '; ', $parts ) );
}

function egov_law_monitor_square_reason_label( $reason_code ) {
	$labels = array(
		'payment_and_period_candidate_require_administrator_approval' => '決済と期間候補を取得しました。管理者の確認が必要です。',
		'invoice_order_payment_verified' => 'InvoiceとOrder・Paymentを照合しました。',
		'checkout_attempt_subscription_identifier_chain_unproven' => 'Checkout AttemptとSubscriptionを結ぶ識別子を確認できません。',
		'multiple_subscription_candidates_match_attempt' => '申込に一致するSubscription候補が複数あります。',
		'multiple_initial_subscription_invoices_or_candidates' => '申込に一致するInvoiceまたはSubscription候補が複数あります。',
		'attempt_contract_not_unique' => '申込に関連するローカル契約を一意に特定できません。',
		'attempt_contract_identity_mismatch' => '申込とローカル契約のユーザーまたはSquare情報が一致しません。',
		'attempt_contract_plan_mismatch' => '申込とローカル契約のプランが一致しません。',
		'attempt_contract_subscription_id_missing' => 'ローカル契約にSubscription IDがありません。',
		'subscription_invoice_relationship_unavailable' => 'Subscriptionと初回Invoiceの関係を確認できません。',
		'subscription_customer_id_mismatch' => 'SubscriptionのCustomer IDが決済または契約情報と一致しません。',
		'subscription_location_id_mismatch' => 'SubscriptionのLocation IDが設定と一致しません。',
		'subscription_plan_variation_id_mismatch' => 'SubscriptionのPlan Variation IDが申込と一致しません。',
		'local_contract_subscription_mismatch' => 'ローカル契約と初回InvoiceのSubscription IDが一致しません。',
		'initial_invoice_identity_mismatch' => 'Invoice ID、Subscription ID、Locationのいずれかが一致しません。',
		'initial_invoice_order_identity_mismatch' => 'Invoiceに紐づくOrder IDまたはLocationが一致しません。',
		'initial_invoice_payment_link_unproven' => '初回決済とSubscription Invoiceの関連付けを確認できません。',
		'initial_invoice_payment_not_unique' => 'Invoiceに対応するPaymentを一意に特定できません。',
		'initial_invoice_not_fully_paid' => 'Invoiceの全額支払を確認できません。',
		'multiple_subscription_invoices_require_review' => 'Subscriptionに複数Invoiceがあり、初回Invoiceを確定できません。',
		'initial_payment_refund_requires_review' => '返金の存在または状態を確認してください。',
		'initial_order_payment_identity_or_amount_mismatch' => 'Order・Payment・申込ID、金額、通貨のいずれかが一致しません。',
		'subscription_plan_phase_or_price_unconfirmed' => '月次Phaseまたは500円・JPYの課金条件を確認できません。',
		'subscription_status_requires_review' => 'Subscription状態または解約状態の確認が必要です。',
		'subscription_actions_unavailable' => '予定変更情報を取得できません。',
		'subscription_actions_pending' => 'Subscriptionに予定変更があります。',
		'subscription_events_incomplete' => 'Subscriptionの変更履歴が不完全です。',
		'subscription_event_type_unknown' => '未知の変更履歴があり、影響を確認できません。',
		'subscription_history_contains_change' => '過去の契約・課金予定変更があります。',
		'subscription_start_event_unconfirmed' => '開始イベントを確認できません。',
		'subscription_start_event_mismatch' => 'Subscription開始日と開始イベントが一致しません。',
		'subscription_timezone_or_anchor_missing' => 'Timezoneまたは月次課金アンカーが不足しています。',
		'subscription_search_incomplete' => 'Subscription候補検索がページング途中です。',
		'subscription_search_failed' => 'Subscription候補のSquare API取得に失敗しました。',
		'subscription_retrieval_failed' => 'SubscriptionのSquare API取得に失敗しました。',
		'subscription_invoice_retrieval_failed' => 'InvoiceのSquare API取得に失敗しました。',
		'initial_invoice_order_retrieval_failed' => 'Invoice OrderのSquare API取得に失敗しました。',
		'initial_order_retrieval_failed' => '初回OrderのSquare API取得に失敗しました。',
		'initial_payment_retrieval_failed' => '初回PaymentのSquare API取得に失敗しました。',
		'subscription_invoice_inspection_limit_reached' => 'Invoice確認上限に達しました。残りの確認が必要です。',
		'billing_period_candidate_unavailable' => '利用期間候補を算出できません。',
		'billing_period_provenance_unavailable' => '利用期間の根拠を確認できません。',
		'approved_invoice_baseline_missing' => '前回承認済みInvoiceを確認できません。',
		'approved_invoice_not_in_subscription_list' => '前回承認済みInvoiceがSubscription一覧にありません。',
		'subscription_schedule_changed' => 'Subscriptionの課金予定に変更があります。',
		'subscription_schedule_provenance_incomplete' => '課金予定の変更履歴が不完全です。',
		'invoice_amount_state_or_payment_request_unconfirmed' => 'Invoiceの状態・請求額・支払要求を確認できません。',
		'order_not_single_completed_payment' => 'Orderに単一の完了Paymentを確認できません。',
		'payment_amount_or_currency_mismatch' => 'Paymentの金額または通貨が一致しません。',
		'refund_detected' => '返金が確認されました。',
		'refund_identity_or_status_unknown' => '返金IDまたは返金状態を確認できません。',
		'refund_state_unavailable' => '返金情報を取得できません。',
		'latest_invoice_cross_check_failed' => '最新Invoiceの独立照合に失敗しました。',
		'latest_invoice_cross_check_not_confirmed' => '最新Invoiceの決済を照合できません。',
		'no_unapproved_invoice' => '前回承認以降に未承認Invoiceがありません。',
	);
	if ( ! is_string( $reason_code ) || '' === $reason_code ) {
		$reason_code = 'reason_unavailable';
	}
	return $labels[ $reason_code ] ?? '要確認（理由コード: ' . $reason_code . '）';
}

function egov_law_monitor_square_payment_result_item( $label, $value ) {
	if ( null === $value || '' === $value || ! is_scalar( $value ) ) {
		$value = '—';
	}
	echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( (string) $value ) . '</dd>';
}
