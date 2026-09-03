<?php
/**
 * Search one page of laws using e-Gov internal API.
 *
 * @param string $search_text Search text.
 * @param int    $offset      Result offset.
 * @return array|WP_Error
 */
function egov_law_monitor_search_laws_page( $search_text, $offset = 0 ) {

    $search_text = trim( $search_text );

    if ( $search_text === '' ) {
        return [];
    }

    $payload = [
        'searchType'            => 1,
        'lawType_array'         => [1, 2, 7, 8, 3, 4, 5, 6],
        'occasionDate'          => current_time( 'Y/m/d' ),
        'searchText'            => $search_text,
        'searchTextSnt'         => '',
        'lawNo_1'               => '',
        'lawNo_2'               => '',
        'lawNo_3'               => '',
        'repealReason_array'    => [2, 1, 4],
        'lawName'               => '',
        'status_array'           => [1],
        'lawConstruction_array' => [1, 2, 3, 4, 5, 6, 7, 8, 9],
        'promulgationDate_from'  => '',
        'promulgationDate_to'    => '',
        'categoryCd_array'       => range( 1, 50 ),
        'matchingTurnFlg'        => 0,
        'matchingWordCnt'        => 0,
        'matchingSoundFlg'       => 0,
        'dispCnt'               => 100,
        'sort'                   => 2,
        'offset'                 => $offset,
    ];

    $response = wp_remote_post(
        'https://laws.e-gov.go.jp/internal-api/SelectLaw.json',
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'e-Gov Law Monitor',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code( $response );

    if ( $status_code !== 200 ) {
        return new WP_Error(
            'egov_law_search_http_error',
            'e-Gov law search request failed.',
            [
                'status' => $status_code,
            ]
        );
    }

    $body = json_decode(
        wp_remote_retrieve_body( $response ),
        true
    );

    if ( ! is_array( $body ) ) {
        return new WP_Error(
            'egov_law_search_invalid_response',
            'Invalid response from e-Gov law search API.'
        );
    }

    if ( empty( $body['result']['success'] ) ) {
        return new WP_Error(
            'egov_law_search_api_error',
            $body['result']['errorMessage'] ?? 'e-Gov law search failed.'
        );
    }

    $results = $body['result']['searchResult_array'] ?? [];

    return array_map(
        static function ( $result ) {
            return [
                'law_id'   => $result['law_id'] ?? '',
                'law_name' => $result['law_name'] ?? '',
                'law_no'   => $result['law_no'] ?? '',
                'law_type' => $result['law_type_label'] ?? '',
            ];
        },
        $results
    );
}


/**
 * Search laws using e-Gov internal API.
 *
 * Returns the first 100 results.
 *
 * @param string $search_text Search text.
 * @return array|WP_Error
 */
function egov_law_monitor_search_laws( $search_text ) {

    return egov_law_monitor_search_laws_page(
        $search_text,
        0
    );
}


/**
 * Register law search REST API.
 */
add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'egov-law-monitor/v1',
            '/law-search',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => function ( WP_REST_Request $request ) {

                    $query = $request->get_param( 'query' );

                    if ( ! is_string( $query ) ) {
                        return new WP_Error(
                            'invalid_query',
                            'query is required.',
                            [
                                'status' => 400,
                            ]
                        );
                    }

                    $query = trim( $query );

                    if ( $query === '' ) {
                        return new WP_Error(
                            'empty_query',
                            'query is required.',
                            [
                                'status' => 400,
                            ]
                        );
                    }

                    return egov_law_monitor_search_laws( $query );
                },
                'permission_callback' => '__return_true',
            ]
        );
    }
);