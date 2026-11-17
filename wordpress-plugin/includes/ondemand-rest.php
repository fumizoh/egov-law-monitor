<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_ondemand_nocache() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
    do_action( 'litespeed_control_set_nocache', 'Admin-only on-demand discovery' );
    if ( ! headers_sent() ) {
        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Vary: Cookie', false );
    }
}

function egov_ondemand_permission( $request ) {
    egov_ondemand_nocache();
    $allowed = egov_ondemand_access();
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return new WP_Error( 'ondemand_nonce', '認証情報を再読み込みしてください。', array( 'status' => 403 ) ); }
    return true;
}

function egov_ondemand_dispatch( $request, $operation ) {
    $allowed = egov_ondemand_permission( $request );
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( $operation === 'search' ) {
        $offset = $request->get_param( 'offset' ) ?? '0';
        if ( ! ( is_int( $offset ) || is_string( $offset ) ) || ! preg_match( '/^[0-9]{1,5}$/D', (string) $offset ) ) { return new WP_Error( 'ondemand_input', 'ページ指定が不正です。', array( 'status' => 400 ) ); }
        return egov_ondemand_search( $request->get_param( 'mode' ), $request->get_param( 'query' ), (int) $offset );
    }
    if ( $operation === 'revisions' ) { return egov_ondemand_revisions( $request->get_param( 'law_id' ) ); }
    return egov_ondemand_match( $request->get_param( 'law_id' ), $request->get_param( 'revision_id' ) );
}

add_action( 'rest_api_init', static function () {
    foreach ( array( 'laws' => array( 'GET', 'search' ), 'revisions' => array( 'GET', 'revisions' ), 'revision-match' => array( 'POST', 'match' ) ) as $path => $spec ) {
        register_rest_route( 'egov-law-monitor/v1', '/ondemand/' . $path, array( 'methods' => $spec[0], 'permission_callback' => 'egov_ondemand_permission', 'callback' => static function ( $request ) use ( $spec ) { return egov_ondemand_dispatch( $request, $spec[1] ); } ) );
    }
} );

add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
    if ( strpos( $request->get_route(), '/egov-law-monitor/v1/ondemand/' ) === 0 ) {
        egov_ondemand_nocache();
        $response = rest_ensure_response( $response );
        $response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Vary', 'Cookie' );
    }
    return $response;
}, 10, 3 );
