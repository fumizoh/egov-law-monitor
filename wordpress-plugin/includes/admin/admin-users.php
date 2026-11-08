<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ユーザー管理画面
 */
function egov_law_monitor_render_admin_users() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'このページにアクセスする権限がありません。' );
    }

    if ( isset( $_POST['egov_law_monitor_save_user'] ) || isset( $_POST['egov_law_monitor_clear_ai_summary'] ) ) {
        wp_die( 'この一覧は閲覧専用です。プラン変更は対象ユーザーのSquare契約管理から操作してください。' );
    }

    global $wpdb;

    $users = get_users(
        array(
            'orderby' => 'registered',
            'order'   => 'DESC',
        )
    );

    $table_name = $wpdb->prefix . 'law_watch_settings';

    $watch_settings = $wpdb->get_results(
        "SELECT user_id, keyword
         FROM {$table_name}
         ORDER BY user_id ASC, created_at ASC",
        ARRAY_A
    );

    $watches_by_user = array();

    foreach ( $watch_settings as $watch ) {
        $user_id = (int) $watch['user_id'];

        if ( ! isset( $watches_by_user[ $user_id ] ) ) {
            $watches_by_user[ $user_id ] = array();
        }

        $watches_by_user[ $user_id ][] = $watch['keyword'];
    }
    ?>
    <div class="wrap">
        <style>
        .egov-law-monitor-users-table-wrap {
            overflow-x: auto;
            width: 100%;
        }

        .egov-law-monitor-users-table {
            min-width: 1250px;
            table-layout: auto;
        }

        .egov-law-monitor-users-table th,
        .egov-law-monitor-users-table td {
            vertical-align: middle;
            white-space: nowrap;
        }

        .egov-law-monitor-users-table th:nth-child(1),
        .egov-law-monitor-users-table td:nth-child(1) {
            width: 80px;
        }

        .egov-law-monitor-users-table th:nth-child(2),
        .egov-law-monitor-users-table td:nth-child(2) {
            width: 120px;
        }

        .egov-law-monitor-users-table th:nth-child(3),
        .egov-law-monitor-users-table td:nth-child(3) {
            width: 210px;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .egov-law-monitor-users-table th:nth-child(4),
        .egov-law-monitor-users-table td:nth-child(4) {
            width: 120px;
        }

        .egov-law-monitor-users-table th:nth-child(5),
        .egov-law-monitor-users-table td:nth-child(5) {
            width: 100px;
        }

        .egov-law-monitor-users-table th:nth-child(6),
        .egov-law-monitor-users-table td:nth-child(6) {
            width: 160px;
            white-space: normal;
        }

        .egov-law-monitor-users-table th:nth-child(7),
        .egov-law-monitor-users-table td:nth-child(7) {
            width: 150px;
        }

        .egov-law-monitor-users-table th:nth-child(8),
        .egov-law-monitor-users-table td:nth-child(8) {
            width: 120px;
        }

        .egov-law-monitor-users-table th:nth-child(9),
        .egov-law-monitor-users-table td:nth-child(9) {
            width: 100px;
        }
        </style>

        <h1>法令ウォッチ ユーザー管理</h1>

        <div class="egov-law-monitor-users-table-wrap">
            <table class="widefat fixed striped egov-law-monitor-users-table">
                <thead>
                    <tr>
                        <th>ユーザーID</th>
                        <th>ユーザー</th>
                        <th>メールアドレス</th>
                        <th>プラン</th>
                        <th>通知</th>                    
                        <th>ウォッチキーワード</th>
                        <th>AI要約</th>
                        <th>登録日</th>
                        <th>操作</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ( empty( $users ) ) : ?>
                        <tr>
                            <td colspan="9">ユーザーはいません。</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $users as $user ) : ?>
                            <tr>
                                <td>
                                    <?php echo esc_html( $user->ID ); ?>
                                </td>

                                <td>
                                    <?php echo esc_html( $user->display_name ); ?>
                                </td>

                                <td>
                                    <?php echo esc_html( $user->user_email ); ?>
                                </td>

                                <td>
                                    <?php
                                    $plan = egov_law_monitor_get_plan( $user->ID );
                                    ?>

                                    <?php echo esc_html( $plan === EGOV_LAW_MONITOR_PLAN_FREE ? '無料' : ( $plan === EGOV_LAW_MONITOR_PLAN_STANDARD ? 'スタンダード' : egov_law_monitor_get_plan_label( $plan ) ) ); ?>
                                </td>

                                <td>
                                    <?php
                                    $notifications = egov_law_monitor_get_notifications( $user->ID );
                                    ?>

                                    <?php echo esc_html( $notifications ? 'ON' : 'OFF' ); ?>
                                </td>

                                <td>
                                    <?php
                                    $keywords = $watches_by_user[ $user->ID ] ?? array();

                                    if ( empty( $keywords ) ) {
                                        echo '—';
                                    } else {
                                        echo esc_html( implode( '、', $keywords ) );
                                    }
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    $plan = egov_law_monitor_get_plan( $user->ID );

                                    if ( $plan === EGOV_LAW_MONITOR_PLAN_FREE ) {

                                        $ai_summary_usage =
                                            egov_law_monitor_get_ai_summary_trial_usage_count(
                                                $user->ID
                                            );

                                    } else {

                                        $ai_summary_usage =
                                            egov_law_monitor_get_ai_summary_usage_count(
                                                $user->ID
                                            );
                                    }

                                    $ai_summary_limit =
                                        egov_law_monitor_get_ai_summary_limit(
                                            $user->ID
                                        );
                                    ?>

                                    <strong>
                                        <?php echo esc_html( $ai_summary_usage ); ?>
                                        /
                                        <?php echo esc_html( $ai_summary_limit ); ?>回
                                    </strong>


                                </td>

                                <td>
                                    <?php
                                    echo esc_html(
                                        wp_date(
                                            'Y年n月j日',
                                            strtotime( $user->user_registered )
                                        )
                                    );
                                    ?>
                                </td>

                                <td>

                                    <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'egov-square-subscriptions', 'user_id' => $user->ID ), admin_url( 'admin.php' ) ) ); ?>">Square契約管理</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
