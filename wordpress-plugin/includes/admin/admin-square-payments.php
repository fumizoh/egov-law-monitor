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
	return new Egov_Law_Monitor_Square_Initial_Payment_Read_Service( $wpdb, $repository, $client, $payment_confirmation, $subscription_matcher );
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
	return new Egov_Law_Monitor_Square_Recurring_Payment_Read_Service( $wpdb, $client );
}

/** Render the Square payment review screen; Square is queried only for a valid POST. */
function egov_law_monitor_render_admin_square_payments() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'このページにアクセスする権限がありません。' );
		return;
	}

	$inspection = null;
	$notice = '';
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
		egov_law_monitor_render_square_payment_inspection( $inspection );
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

/** Render normalized read-only payment evidence without exposing raw errors or PII. */
function egov_law_monitor_render_square_payment_inspection( array $result ) {
	$evidence = isset( $result['payment_evidence'] ) && is_array( $result['payment_evidence'] ) ? $result['payment_evidence'] : array();
	$invoice_scope = isset( $result['invoice_scope'] ) && is_array( $result['invoice_scope'] ) ? $result['invoice_scope'] : array();
	$period = isset( $result['billing_period'] ) && is_array( $result['billing_period'] ) ? $result['billing_period'] : array();
	$invoice = isset( $result['invoice'] ) && is_array( $result['invoice'] ) ? $result['invoice'] : array();
	$payment = isset( $result['payment'] ) && is_array( $result['payment'] ) ? $result['payment'] : array();
	$subscription = isset( $result['subscription_link'] ) && is_array( $result['subscription_link'] ) ? $result['subscription_link'] : array();
	if ( empty( $subscription ) && isset( $result['subscription'] ) && is_array( $result['subscription'] ) ) {
		$stored_id = $result['contract']['subscription_id'] ?? null;
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
	$period_verified = ! empty( $period['verified'] );
	$review_labels = array( 'required' => '追加確認が必要', 'awaiting_payment' => '決済確認待ち', 'retrieval_failed' => '情報取得失敗' );
	$scope_labels = array( 'latest_invoice_only' => '最新Invoiceのみ', 'initial_checkout_order_only' => '初回CheckoutのOrderのみ' );
	$link_status = ( $subscription['status'] ?? '' ) === 'confirmed' ? '照合済み' : '未照合・要確認';
	$status_labels = array(
		'payment_confirmed' => '決済情報を確認（利用期間は別途確認）',
		'payment_pending' => '決済未完了または処理中',
		'payment_failed' => '決済失敗の情報あり',
		'square_api_error' => 'Square情報の取得に失敗',
		'reconciliation_required' => '照合・追加確認が必要',
	);

	echo '<section class="notice notice-info"><h2>決済情報の取得結果</h2><dl>';
	egov_law_monitor_square_payment_result_item( '取得結果', $status_labels[ $result['status'] ?? '' ] ?? '状態不明・要確認' );
	egov_law_monitor_square_payment_result_item( '決済証拠', $payment_confirmed ? '決済完了を確認' : ( ( $evidence['status'] ?? '' ) === 'pending' ? '決済処理中または未完了' : '確認できず' ) );
	egov_law_monitor_square_payment_result_item( '金額・通貨', null !== $amount && null !== $currency ? number_format_i18n( (int) $amount ) . ' ' . $currency : '確認できず' );
	egov_law_monitor_square_payment_result_item( 'Payment ID', $payment_id );
	egov_law_monitor_square_payment_result_item( 'Order ID', $order_id );
	egov_law_monitor_square_payment_result_item( 'Invoice ID', $invoice_id );
	egov_law_monitor_square_payment_result_item( 'Subscription ID', $subscription['subscription_id'] ?? ( $result['contract']['subscription_id'] ?? null ) );
	egov_law_monitor_square_payment_result_item( 'Subscriptionとの紐付け', $link_status );
	egov_law_monitor_square_payment_result_item( '確認したInvoice範囲', $scope_labels[ $invoice_scope['selection'] ?? '' ] ?? '範囲不明' );
	egov_law_monitor_square_payment_result_item( 'Invoice件数', sprintf( '全体: %s / 確認: %s / 未確認: %s', isset( $invoice_scope['invoice_total_count'] ) ? (string) $invoice_scope['invoice_total_count'] : '不明', isset( $invoice_scope['checked_invoice_count'] ) ? (string) $invoice_scope['checked_invoice_count'] : '不明', isset( $invoice_scope['unchecked_invoice_count'] ) ? (string) $invoice_scope['unchecked_invoice_count'] : '不明' ) );
	egov_law_monitor_square_payment_result_item( 'レビュー状態', $review_labels[ $result['review_status'] ?? '' ] ?? '不明（要確認）' );
	egov_law_monitor_square_payment_result_item( '利用期間', $period_verified ? ( ( $period['start'] ?? '' ) . ' 〜 ' . ( $period['end'] ?? '' ) ) : '未確認（期間の根拠なし）' );
	egov_law_monitor_square_payment_result_item( '期間確認根拠', $period['evidence_code'] ?? '根拠不明' );
	egov_law_monitor_square_payment_result_item( '保留理由', $result['reason_code'] ?? '理由不明' );
	echo '</dl><p><strong>決済完了の確認とStandard利用期間・権限の承認は別の判断です。この画面では権限を変更しません。</strong></p></section>';
}

function egov_law_monitor_square_payment_result_item( $label, $value ) {
	if ( null === $value || '' === $value || ! is_scalar( $value ) ) {
		$value = '—';
	}
	echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( (string) $value ) . '</dd>';
}
