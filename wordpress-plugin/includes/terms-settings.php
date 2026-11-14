<?php
/** Shared terms version and its independent Settings API form. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const EGOV_LAW_MONITOR_DEFAULT_TERMS_VERSION = '2026-11-14';

function egov_law_monitor_terms_version_valid( $value ) {
    if ( ! is_string( $value ) || ! preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts ) ) { return false; }
    return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
}

/** Absence uses the original version without writing; corrupt stored values fail closed. */
function egov_law_monitor_terms_version() {
    $value = get_option( 'egov_law_monitor_terms_version', EGOV_LAW_MONITOR_DEFAULT_TERMS_VERSION );
    return egov_law_monitor_terms_version_valid( $value ) ? $value : new WP_Error( 'invalid_terms_version', '利用規約の設定を確認できません。管理者へお問い合わせください。' );
}

function egov_law_monitor_sanitize_terms_version( $value ) {
    if ( current_user_can( 'manage_options' ) && egov_law_monitor_terms_version_valid( $value ) ) { return $value; }
    add_settings_error( 'egov_law_monitor_terms_version', 'invalid_terms_version', '利用規約バージョンは実在する日付をYYYY-MM-DD形式で入力してください。設定は変更していません。' );
    return get_option( 'egov_law_monitor_terms_version', EGOV_LAW_MONITOR_DEFAULT_TERMS_VERSION );
}

add_action( 'admin_init', function () {
    register_setting( 'egov_law_monitor_terms_settings', 'egov_law_monitor_terms_version', array(
        'type' => 'string',
        'sanitize_callback' => 'egov_law_monitor_sanitize_terms_version',
    ) );
} );

function egov_law_monitor_render_terms_settings() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $version = egov_law_monitor_terms_version();
    echo '<h2>利用規約バージョン</h2>';
    settings_errors( 'egov_law_monitor_terms_version' );
    if ( is_wp_error( $version ) ) {
        echo '<div class="notice notice-error inline"><p>保存されている利用規約バージョンが不正です。修正するまでStandard申込の同意保存とSquareへの移動を停止します。</p></div>';
    }
    echo '<form method="post" action="options.php">';
    // options.php enforces manage_options and this separate group's nonce.
    settings_fields( 'egov_law_monitor_terms_settings' );
    echo '<p><label for="egov-law-monitor-terms-version">利用規約バージョン（YYYY-MM-DD）</label></p><input type="text" id="egov-law-monitor-terms-version" name="egov_law_monitor_terms_version" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" required value="' . esc_attr( is_wp_error( $version ) ? '' : $version ) . '">';
    echo '<p>利用規約ページの本文・改定日と合わせて更新してください。本文とこの設定は自動同期されません。</p>';
    submit_button( '利用規約バージョンを保存' );
    echo '</form>';
}
