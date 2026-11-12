<?php
/** Durable mail reservations. SMTP is always outside a DB transaction. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_cancellation_mail_job( $row, $now = null ) {
    $now = $now ?? current_datetime(); $utc = $now->setTimezone( new DateTimeZone( 'UTC' ) );
    if ( $row['completed_at'] !== null ) { return null; }
    if ( $row['end_date'] !== null && ! egov_law_monitor_cancellation_date_valid( $row['end_date'] ) ) { return null; }
    if ( $row['mail_claim_token'] !== null && $row['mail_claimed_at'] !== null && strtotime( $row['mail_claimed_at'] . ' UTC' ) > $utc->getTimestamp() - 900 ) { return null; }
    if ( $row['last_mail_attempt_at'] !== null && strtotime( $row['last_mail_attempt_at'] . ' UTC' ) > $utc->getTimestamp() - 3600 ) { return null; }
    $today = $now->format( 'Y-m-d' );
    if ( $row['end_date'] !== null && egov_law_monitor_cancellation_date_valid( $row['end_date'] ) && $row['end_date'] < $today && $row['last_due_attempt_date'] !== $today ) { return 'due'; }
    if ( $row['end_date'] !== null && (int) $row['user_notified_revision'] < (int) $row['revision'] ) { return 'end_date'; }
    if ( $row['admin_notified_at'] === null ) { return 'request'; }
    return null;
}

function egov_law_monitor_process_cancellation_mail( $id, $factory = null ) {
    try {
        $table = egov_law_monitor_cancellation_table();
        $row = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE id = ?", array( $id ) )[0] ?? null;
        if ( ! $row || $row['completed_at'] !== null ) { return; }
        $user_id = (int) $row['user_id'];
        $job = egov_law_monitor_cancellation_transaction( $user_id, static function ( $db, $tables, $table ) use ( $id, $user_id ) {
            $row = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE id = ? AND user_id = ? FOR UPDATE", array( $id, $user_id ), $db )[0] ?? null;
            if ( ! $row || $row['completed_at'] !== null ) { return null; }
            $plan = egov_law_monitor_square_plan_snapshot( $user_id, $tables, $db );
            if ( $plan['plan'] === 'free' ) { egov_law_monitor_complete_cancellations_for_free( $db, $tables, $user_id, 'Free変更を確認して解約申請を完了' ); return null; }
            $context = egov_law_monitor_cancellation_context( $user_id, $db );
            if ( $context['approval_id'] !== (int) $row['approval_id'] || (int) $context['contract']['id'] !== (int) $row['contract_id'] ) { return null; }
            $kind = egov_law_monitor_cancellation_mail_job( $row );
            if ( $kind === null ) { return null; }
            $token = bin2hex( random_bytes( 16 ) ); $now = egov_law_monitor_cancellation_now(); $today = current_datetime()->format( 'Y-m-d' );
            // Reserve the due-day before sending, even if delivery fails or COMMIT is ambiguous.
            $due = $kind === 'due' ? $today : $row['last_due_attempt_date'];
            if ( ! $db->write( "UPDATE `{$table}` SET mail_claim_token = ?, mail_claimed_at = ?, last_mail_attempt_at = ?, last_due_attempt_date = ? WHERE id = ? AND user_id = ?", array( $token, $now, $now, $due, $id, $user_id ) ) ) { throw new RuntimeException(); }
            return array( 'kind' => $kind, 'token' => $token, 'row' => $row, 'contract' => $context['contract'], 'today' => $today );
        }, $factory );
        if ( is_wp_error( $job ) || ! $job ) { return; }
        // Stop a queued mail if Free or a date edit was observed after the reservation.
        $latest = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE id = ? AND user_id = ?", array( $id, $user_id ) )[0] ?? null;
        $plan = egov_law_monitor_square_plan_snapshot( $user_id, egov_law_monitor_square_plan_tables() );
        $send = $latest && $latest['mail_claim_token'] === $job['token'] && $latest['completed_at'] === null && (int) $latest['revision'] === (int) $job['row']['revision'] && $plan['plan'] === 'standard' && (int) $plan['revision'] === (int) $latest['approval_id'];
        $sent = $send ? egov_law_monitor_send_cancellation_mail( $job, $user_id ) : false;
        egov_law_monitor_cancellation_transaction( $user_id, static function ( $db, $tables, $table ) use ( $job, $id, $user_id, $sent ) {
            $row = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE id = ? AND user_id = ? FOR UPDATE", array( $id, $user_id ), $db )[0] ?? null;
            if ( ! $row || $row['mail_claim_token'] !== $job['token'] ) { return null; }
            $admin_at = $sent && $job['kind'] === 'request' ? egov_law_monitor_cancellation_now() : $row['admin_notified_at'];
            $user_revision = $sent && $job['kind'] === 'end_date' ? (int) $job['row']['revision'] : (int) $row['user_notified_revision'];
            $due_sent = $sent && $job['kind'] === 'due' ? $job['today'] : $row['last_due_sent_date'];
            if ( ! $db->write( "UPDATE `{$table}` SET admin_notified_at = ?, user_notified_revision = ?, last_due_sent_date = ?, mail_claim_token = NULL, mail_claimed_at = NULL WHERE id = ? AND user_id = ?", array( $admin_at, $user_revision, $due_sent, $id, $user_id ) ) ) { throw new RuntimeException(); }
            return null;
        }, $factory );
    } catch ( Throwable $error ) { error_log( 'e-Gov Square: cancellation mail processing failed; request_id=' . (int) $id ); }
}

function egov_law_monitor_send_cancellation_mail( $job, $user_id ) {
    try {
        $user = get_userdata( $user_id ); if ( ! $user ) { return false; }
        $recipient = $job['kind'] === 'end_date' ? $user->user_email : get_option( 'admin_email' );
        if ( ! is_string( $recipient ) || ! is_email( $recipient ) ) { return false; }
        $row = $job['row']; $contract = $job['contract'];
        $url = admin_url( 'admin.php?page=egov-square-subscriptions&user_id=' . $user_id );
        $identity = "WordPressユーザーID: {$user_id}\nユーザー名: {$user->user_login}\nメール: {$user->user_email}\nCustomer ID: {$contract['square_customer_id']}\nSubscription ID: {$contract['square_subscription_id']}\n";
        if ( $job['kind'] === 'request' ) {
            $subject = 'Standard解約申請';
            $body = $identity . '解約申請日時: ' . wp_date( 'Y年m月d日 H:i', strtotime( $row['requested_at'] . ' UTC' ), wp_timezone() ) . "\nSquare Dashboardで手動処理し、確認した利用終了日を登録してください。WordPressプランはまだStandardです。\n管理画面: {$url}";
        } elseif ( $job['kind'] === 'end_date' ) {
            $subject = 'Standard利用終了日のお知らせ';
            $body = 'Standard利用終了日: ' . egov_law_monitor_cancellation_date_label( $row['end_date'] ) . "\n利用終了日当日までStandardプランをご利用いただけます。利用権限の終了処理は管理者が行います。\n";
        } else {
            $subject = 'Standard利用終了日到来・承認解除の確認';
            $body = $identity . "解約予定日: {$row['end_date']}\n現在のWordPressプラン: Standard\n利用終了日はWordPressのタイムゾーン（" . wp_timezone()->getName() . "）で当日23:59までで、既に終了日を過ぎています。既存のStandard承認解除を手動で行ってください。\n管理画面: {$url}\nSquareの解約状況も確認してください。この通知はSquareの解約を証明しません。";
        }
        return wp_mail( $recipient, '[法令ウォッチ] ' . $subject . ' #' . (int) $row['id'], $body ) === true;
    } catch ( Throwable $error ) { return false; }
}

function egov_law_monitor_schedule_cancellation_cron() {
    if ( ! wp_next_scheduled( 'egov_standard_cancellation_cron' ) ) { wp_schedule_event( time() + 300, 'hourly', 'egov_standard_cancellation_cron' ); }
}
add_action( 'init', 'egov_law_monitor_schedule_cancellation_cron' );

function egov_law_monitor_cancellation_cron( $factory = null ) {
    if ( ! egov_law_monitor_cancellation_storage_ready() ) { return; }
    $after = max( 0, (int) get_option( 'egov_standard_cancellation_cursor', 0 ) ); $started = microtime( true );
    try {
        $table = egov_law_monitor_cancellation_table();
        for ( $page = 0; $page < 10; ++$page ) {
            $rows = egov_law_monitor_cancellation_rows( "SELECT id FROM `{$table}` WHERE completed_at IS NULL AND id > ? ORDER BY id LIMIT 100", array( $after ) );
            if ( ! $rows ) { $after = 0; break; }
            foreach ( $rows as $row ) {
                egov_law_monitor_process_cancellation_mail( (int) $row['id'], $factory ); $after = (int) $row['id'];
                if ( microtime( true ) - $started > 20 ) { break 2; }
            }
            if ( count( $rows ) < 100 ) { $after = 0; break; }
        }
        update_option( 'egov_standard_cancellation_cursor', $after, false );
    } catch ( Throwable $error ) { error_log( 'e-Gov Square: cancellation cron read failed.' ); }
}
add_action( 'egov_standard_cancellation_cron', 'egov_law_monitor_cancellation_cron' );
