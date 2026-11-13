<?php
/** Shared server-side Square authentication and identifier validation. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Secrets live in server configuration, never WordPress options or HTML. */
function egov_law_monitor_square_api_settings() {
    $environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : null;
    if ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) ) {
        return new WP_Error( 'square_api_not_configured', 'Square APIの環境設定を確認してください。sandbox または production が必要です。' );
    }
    if ( $environment === 'sandbox' ) {
        $token = defined( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX' ) ? EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX : null;
    } else {
        $token = defined( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION' ) ? EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION : null;
    }
    if ( ! is_string( $token ) || $token === '' || strlen( $token ) > 4096 || preg_match( '/[^\x21-\x7e]/', $token ) ) {
        return new WP_Error( 'square_api_not_configured', '選択したSquare環境のアクセストークンが未設定、または形式が不正です。サーバー設定を確認してください。' );
    }
    return array( 'token' => $token, 'environment' => $environment );
}

/** Validate only displayable identifiers, not arbitrary API payloads. */
function egov_law_monitor_square_api_id_valid( $value ) {
    return is_string( $value ) && strlen( $value ) <= 255 && preg_match( '/\A[A-Za-z0-9_-]+\z/', $value );
}
