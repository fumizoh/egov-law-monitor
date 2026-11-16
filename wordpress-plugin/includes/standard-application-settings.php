<?php
/** General Standard application gate; independent of AI and terms settings. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_standard_application_public() {
    return in_array( get_option( 'egov_law_monitor_standard_application_public', '0' ), array( '1', 1, true ), true );
}

function egov_law_monitor_sanitize_standard_application_public( $value ) {
    if ( current_user_can( 'manage_options' ) && in_array( $value, array( '0', '1' ), true ) ) { return $value; }
    add_settings_error( 'egov_law_monitor_standard_application_public', 'invalid_application_public', '申込み受付の設定が不正か、変更権限がありません。設定は変更していません。' );
    return get_option( 'egov_law_monitor_standard_application_public', '0' );
}

add_action( 'admin_init', function () {
    register_setting( 'egov_law_monitor_standard_application_settings', 'egov_law_monitor_standard_application_public', array(
        'type' => 'string',
        'sanitize_callback' => 'egov_law_monitor_sanitize_standard_application_public',
    ) );
} );

function egov_law_monitor_render_standard_application_settings() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $enabled = egov_law_monitor_standard_application_public();
    echo '<h2>Standardプランの申込み受付</h2>';
    settings_errors( 'egov_law_monitor_standard_application_public' );
    echo '<form method="post" action="options.php">';
    // WordPress options.php validates manage_options and this settings group nonce.
    settings_fields( 'egov_law_monitor_standard_application_settings' );
    echo '<input type="hidden" name="egov_law_monitor_standard_application_public" value="0">';
    echo '<p><label><input type="checkbox" name="egov_law_monitor_standard_application_public" value="1"' . ( $enabled ? ' checked' : '' ) . '> 一般ユーザーからの申込みを受け付ける</label></p>';
    echo '<p>OFFの場合、一般ユーザーからの新規申込みを停止します。管理者・指定テストユーザーは従来どおり利用できます。</p>';
    echo '<p>現在の受付状態：<strong>' . ( $enabled ? 'ON（一般受付中）' : 'OFF（一般受付停止中）' ) . '</strong></p>';
    submit_button( '申込み受付設定を保存' );
    echo '</form>';
}
