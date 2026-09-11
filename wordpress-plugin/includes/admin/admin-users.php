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
        <h1>法令ウォッチ ユーザー管理</h1>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>ユーザーID</th>
                    <th>ユーザー</th>
                    <th>メールアドレス</th>
                    <th>プラン</th>
                    <th>通知</th>                    
                    <th>ウォッチキーワード</th>
                    <th>登録日</th>
                </tr>
            </thead>

            <tbody>
                <?php if ( empty( $users ) ) : ?>
                    <tr>
                        <td colspan="7">ユーザーはいません。</td>
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

                                if ( $plan === EGOV_LAW_MONITOR_PLAN_FREE ) {
                                    echo '無料';
                                } else {
                                    echo esc_html( $plan );
                                }
                                ?>
                            </td>

                            <td>
                                <?php
                                echo egov_law_monitor_get_notifications( $user->ID )
                                    ? 'ON'
                                    : 'OFF';
                                ?>
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
                                echo esc_html(
                                    wp_date(
                                        'Y年n月j日',
                                        strtotime( $user->user_registered )
                                    )
                                );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}