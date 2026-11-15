<?php
/** Shared membership protection; no deletion operation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_withdrawal_protected_ids() {
    $ids = get_option( 'egov_law_monitor_withdrawal_protected_user_ids', array() );
    if ( ! is_array( $ids ) ) { return null; }
    foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return null; } }
    return array_values( array_unique( $ids ) );
}

/** Account-only classification shared with capability-checked deletion. */
function egov_law_monitor_withdrawal_general_account( $user ) {
    if ( is_multisite() || ! $user ) { return false; }
    $roles = array_values( array_unique( (array) ( $user->roles ?? array() ) ) );
    if ( $roles !== array( 'subscriber' ) || ( $user->user_login ?? '' ) === 'law_watch_api' ) { return false; }
    foreach ( array( 'manage_options', 'edit_users', 'create_users', 'delete_users', 'promote_users', 'edit_posts', 'publish_posts', 'edit_pages', 'publish_pages', 'upload_files', 'edit_others_posts', 'law_watch_internal_api' ) as $cap ) {
        if ( user_can( $user, $cap ) ) { return false; }
    }
    return true;
}

function egov_law_monitor_withdrawal_member( $user ) {
    $ids = egov_law_monitor_withdrawal_protected_ids();
    return egov_law_monitor_withdrawal_general_account( $user ) && $ids !== null && ! in_array( (int) $user->ID, $ids, true );
}

function egov_law_monitor_withdrawal_target( $user ) {
    return egov_law_monitor_withdrawal_member( $user ) && (int) $user->ID !== (int) get_current_user_id();
}

function egov_law_monitor_withdrawal_sanitize_ids( $value ) {
    if ( current_user_can( 'manage_options' ) && is_string( $value ) && preg_match( '/\A[0-9,\s]*\z/', $value ) ) {
        $ids = array();
        foreach ( preg_split( '/[,\s]+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY ) as $id ) {
            if ( ! preg_match( '/\A[1-9][0-9]*\z/', $id ) || (string) (int) $id !== $id ) { $ids = null; break; }
            $ids[] = (int) $id;
        }
        if ( $ids !== null ) { return array_values( array_unique( $ids ) ); }
    }
    // Settings API can sanitize the first newly added option twice.
    if ( current_user_can( 'manage_options' ) && is_array( $value ) ) {
        $valid = true;
        foreach ( $value as $id ) { if ( ! is_int( $id ) || $id < 1 ) { $valid = false; } }
        if ( $valid ) { return array_values( array_unique( $value ) ); }
    }
    add_settings_error( 'egov_law_monitor_withdrawal_protected_user_ids', 'invalid_ids', '保護対象は正のユーザーIDをカンマ区切りで入力してください。設定は変更していません。' );
    return get_option( 'egov_law_monitor_withdrawal_protected_user_ids', array() );
}

add_action( 'admin_init', function () {
    register_setting( 'egov_law_monitor_withdrawal_settings', 'egov_law_monitor_withdrawal_protected_user_ids', array( 'type' => 'array', 'sanitize_callback' => 'egov_law_monitor_withdrawal_sanitize_ids' ) );
} );

function egov_law_monitor_render_withdrawal_settings() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $ids = egov_law_monitor_withdrawal_protected_ids();
    echo '<h2>退会処理の保護対象アカウント</h2>';
    settings_errors( 'egov_law_monitor_withdrawal_protected_user_ids' );
    if ( $ids === null ) { echo '<p>保存設定が不正です。修正まで退会対象の判定を停止しています。</p>'; }
    echo '<form method="post" action="options.php">';
    settings_fields( 'egov_law_monitor_withdrawal_settings' );
    echo '<p><label for="egov-withdrawal-protected">サービス用ユーザーID（カンマ区切り）</label></p><input class="regular-text" id="egov-withdrawal-protected" name="egov_law_monitor_withdrawal_protected_user_ids" value="' . esc_attr( implode( ', ', $ids ?? array() ) ) . '"><p>law_watch_api・管理権限等を持つアカウントは、この設定にかかわらず対象外です。投稿・内部API用アカウントも必ず確認してください。</p>';
    submit_button( '保護対象を保存' );
    echo '</form>';
}

/** Capability-checked deletion only. Direct wp_delete_user()/SQL cannot be vetoed here. */
add_filter( 'map_meta_cap', function ( $caps, $cap, $actor, $args ) {
    if ( $cap !== 'delete_user' || empty( $args[0] ) || is_multisite() ) { return $caps; }
    $user = get_userdata( (int) $args[0] );
    if ( egov_law_monitor_withdrawal_member( $user ) ) { return array( 'do_not_allow' ); }
    // Corrupt configuration must not accidentally enable deletion of members.
    if ( egov_law_monitor_withdrawal_general_account( $user ) && egov_law_monitor_withdrawal_protected_ids() === null ) { return array( 'do_not_allow' ); }
    return $caps;
}, 10, 4 );
