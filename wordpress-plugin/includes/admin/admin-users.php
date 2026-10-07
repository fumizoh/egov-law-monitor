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

    if (
        isset( $_POST['egov_law_monitor_save_user'] )
        && check_admin_referer(
            'egov_law_monitor_save_user',
            'egov_law_monitor_nonce'
        )
    ) {
        $user_id = isset( $_POST['user_id'] )
            ? absint( $_POST['user_id'] )
            : 0;

        $plan = isset( $_POST['plan'] )
            ? sanitize_key( $_POST['plan'] )
            : EGOV_LAW_MONITOR_PLAN_FREE;

        $allowed_plans = array(
            EGOV_LAW_MONITOR_PLAN_FREE,
            EGOV_LAW_MONITOR_PLAN_STANDARD,
        );

        if ( ! in_array( $plan, $allowed_plans, true ) ) {
            $plan = EGOV_LAW_MONITOR_PLAN_FREE;
        }

        $notifications = isset( $_POST['notifications'] )
            ? 1
            : 0;

        if ( $user_id > 0 && get_userdata( $user_id ) ) {
            update_user_meta(
                $user_id,
                EGOV_LAW_MONITOR_META_PLAN,
                $plan
            );

            update_user_meta(
                $user_id,
                EGOV_LAW_MONITOR_META_NOTIFICATIONS,
                $notifications
            );

            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>ユーザー設定を保存しました。</p>';
            echo '</div>';
        }
    }

    if (
        isset( $_POST['egov_law_monitor_clear_ai_summary'] )
        && check_admin_referer(
            'egov_law_monitor_clear_ai_summary',
            'egov_law_monitor_clear_ai_summary_nonce'
        )
    ) {
        $user_id = isset( $_POST['ai_summary_user_id'] )
            ? absint( $_POST['ai_summary_user_id'] )
            : 0;

        if ( $user_id > 0 && get_userdata( $user_id ) ) {

            $deleted =
                egov_law_monitor_clear_ai_summary_usage(
                    $user_id
                );

            if ( $deleted !== false ) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>';
                echo '今月のAI要約利用履歴を';
                echo esc_html( $deleted );
                echo '件クリアしました。';
                echo '</p>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p>AI要約利用履歴のクリアに失敗しました。</p>';
                echo '</div>';
            }
        }
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

                                    <select
                                        name="plan"
                                        form="egov-law-monitor-user-<?php echo esc_attr( $user->ID ); ?>"
                                    >
                                        <option
                                            value="<?php echo esc_attr( EGOV_LAW_MONITOR_PLAN_FREE ); ?>"
                                            <?php selected( $plan, EGOV_LAW_MONITOR_PLAN_FREE ); ?>
                                        >
                                            無料
                                        </option>
                                        <option
                                            value="<?php echo esc_attr( EGOV_LAW_MONITOR_PLAN_STANDARD ); ?>"
                                            <?php selected( $plan, EGOV_LAW_MONITOR_PLAN_STANDARD ); ?>
                                        >
                                            スタンダード
                                        </option>
                                    </select>
                                </td>

                                <td>
                                    <?php
                                    $notifications = egov_law_monitor_get_notifications( $user->ID );
                                    ?>

                                    <label>
                                        <input
                                            type="checkbox"
                                            name="notifications"
                                            value="1"
                                            form="egov-law-monitor-user-<?php echo esc_attr( $user->ID ); ?>"
                                            <?php checked( $notifications, true ); ?>
                                        >
                                        ON
                                    </label>
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
                                    $ai_summary_usage =
                                        egov_law_monitor_get_ai_summary_usage_count(
                                            $user->ID
                                        );

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

                                    <?php if ( $ai_summary_usage > 0 ) : ?>

                                        <form
                                            method="post"
                                            style="margin-top: 6px;"
                                        >
                                            <?php
                                            wp_nonce_field(
                                                'egov_law_monitor_clear_ai_summary',
                                                'egov_law_monitor_clear_ai_summary_nonce'
                                            );
                                            ?>

                                            <input
                                                type="hidden"
                                                name="ai_summary_user_id"
                                                value="<?php echo esc_attr( $user->ID ); ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="egov_law_monitor_clear_ai_summary"
                                                class="button button-secondary"
                                                onclick="return confirm('今月のAI要約利用履歴を削除して、利用回数をリセットします。よろしいですか？');"
                                            >
                                                今月をリセット
                                            </button>
                                        </form>

                                    <?php endif; ?>
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
                                    <form
                                        method="post"
                                        id="egov-law-monitor-user-<?php echo esc_attr( $user->ID ); ?>"
                                    >
                                        <?php
                                        wp_nonce_field(
                                            'egov_law_monitor_save_user',
                                            'egov_law_monitor_nonce'
                                        );
                                        ?>

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?php echo esc_attr( $user->ID ); ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="egov_law_monitor_save_user"
                                            class="button button-primary"
                                        >
                                            保存
                                        </button>
                                    </form>
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