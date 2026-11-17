<?php
/** Fixed e-Gov origins only; no generation, credentials, redirects or retries. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_ondemand_http( $operation, $args ) {
    $allowed = egov_ondemand_access();
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    $base = 'https://laws.e-gov.go.jp/';
    $options = array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 2097153, 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'e-Gov Law Monitor on-demand discovery' ) );
    if ( $operation === 'laws' ) {
        $url = add_query_arg( $args, $base . 'api/2/laws' );
    } elseif ( $operation === 'revisions' || $operation === 'internal' ) {
        if ( ! egov_ondemand_id( $args['law_id'] ?? null ) ) { return egov_ondemand_error(); }
        $url = $operation === 'revisions' ? $base . 'api/2/law_revisions/' . rawurlencode( $args['law_id'] ) . '?response_format=json' : $base . 'internal-api/SelectLawRevisionData.json';
    } else { return egov_ondemand_error(); }
    if ( $operation === 'internal' ) {
        $options['headers']['Content-Type'] = 'application/json';
        $options['body'] = wp_json_encode( array( 'law_id' => $args['law_id'] ) );
        $response = wp_remote_post( $url, $options );
    } else { $response = wp_remote_get( $url, $options ); }
    if ( is_wp_error( $response ) ) { return new WP_Error( 'ondemand_transport', 'e-Govへの接続に失敗しました。自動再送は行いません。', array( 'status' => 502 ) ); }
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) { return new WP_Error( 'ondemand_http', 'e-Govから正常な応答を取得できません。', array( 'status' => 502, 'upstream_status' => $code ) ); }
    $body = wp_remote_retrieve_body( $response );
    if ( strlen( $body ) > 2097152 ) { return new WP_Error( 'ondemand_size', '取得データが上限（2MiB）を超えました。', array( 'status' => 502 ) ); }
    $data = json_decode( $body, true, 128 );
    return json_last_error() === JSON_ERROR_NONE && is_array( $data ) ? $data : egov_ondemand_error();
}
