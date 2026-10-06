<?php

/**
 * Cloud Run AI Summary API client.
 */

/**
 * Cloud Run runtime service account.
 */
define(
    'EGOV_LAW_MONITOR_CLOUD_RUN_SERVICE_ACCOUNT',
    'e-gov-law-monitor-cloud-run@project-9dc19b38-12b0-40dd-871.iam.gserviceaccount.com'
);

/**
 * Get Google Cloud settings.
 *
 * @return array
 */
function egov_law_monitor_get_ai_cloud_settings() {
    return [
        'project_id'   => get_option(
            'egov_law_monitor_gcp_project_id',
            ''
        ),
        'client_email' => get_option(
            'egov_law_monitor_gcp_client_email',
            ''
        ),
        'private_key'  => egov_law_monitor_decrypt_secret(
            get_option(
                'egov_law_monitor_gcp_private_key',
                ''
            )
        ),
    ];
}

/**
 * Base64 URL encode.
 *
 * @param string $value Value.
 * @return string
 */
function egov_law_monitor_base64url_encode( $value ) {
    return rtrim(
        strtr(
            base64_encode( $value ),
            '+/',
            '-_'
        ),
        '='
    );
}

/**
 * Create a Google service account JWT.
 *
 * @param array $settings Google Cloud settings.
 * @return string|WP_Error
 */
function egov_law_monitor_create_service_account_jwt(
    $settings
) {
    if (
        empty( $settings['client_email'] ) ||
        empty( $settings['private_key'] )
    ) {
        return new WP_Error(
            'egov_law_monitor_missing_credentials',
            'Google Cloud credentials are not configured.'
        );
    }

    $now = time();

    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
    ];

    $payload = [
        'iss'   => $settings['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $encoded_header = egov_law_monitor_base64url_encode(
        wp_json_encode( $header )
    );

    $encoded_payload = egov_law_monitor_base64url_encode(
        wp_json_encode( $payload )
    );

    $unsigned_token =
        $encoded_header . '.' . $encoded_payload;

    $private_key = openssl_pkey_get_private(
        $settings['private_key']
    );

    if ( $private_key === false ) {
        return new WP_Error(
            'egov_law_monitor_invalid_private_key',
            'Google Cloud private key is invalid.'
        );
    }

    $signature = '';

    if (
        ! openssl_sign(
            $unsigned_token,
            $signature,
            $private_key,
            OPENSSL_ALGO_SHA256
        )
    ) {
        return new WP_Error(
            'egov_law_monitor_jwt_error',
            'Failed to sign Google service account JWT.'
        );
    }

    return $unsigned_token . '.' .
        egov_law_monitor_base64url_encode( $signature );
}

/**
 * Get Google OAuth access token.
 *
 * @param array $settings Google Cloud settings.
 * @return string|WP_Error
 */
function egov_law_monitor_get_google_access_token(
    $settings
) {
    $jwt = egov_law_monitor_create_service_account_jwt(
        $settings
    );

    if ( is_wp_error( $jwt ) ) {
        return $jwt;
    }

    $response = wp_remote_post(
        'https://oauth2.googleapis.com/token',
        [
            'timeout' => 30,
            'body'    => [
                'grant_type' =>
                    'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code(
        $response
    );

    $body = json_decode(
        wp_remote_retrieve_body( $response ),
        true
    );

    if (
        $status_code !== 200 ||
        empty( $body['access_token'] )
    ) {
        return new WP_Error(
            'egov_law_monitor_token_error',
            'Failed to obtain Google access token.',
            [
                'status_code' => $status_code,
            ]
        );
    }

    return $body['access_token'];
}

/**
 * Get a Cloud Run ID token.
 *
 * @param array  $settings Google Cloud settings.
 * @param string $audience Cloud Run service URL.
 * @return string|WP_Error
 */
function egov_law_monitor_get_cloud_run_id_token(
    $settings,
    $audience
) {
    $access_token =
        egov_law_monitor_get_google_access_token(
            $settings
        );

    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }

    $url =
        'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/' .
        rawurlencode(
            $settings['client_email']
        ) .
        ':generateIdToken';

    $response = wp_remote_post(
        $url,
        [
            'timeout' => 30,
            'headers' => [
                'Authorization' =>
                    'Bearer ' . $access_token,
                'Content-Type' =>
                    'application/json',
            ],
            'body' => wp_json_encode(
                [
                    'audience' => $audience,
                    'includeEmail' => true,
                ]
            ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code(
        $response
    );

    $body = json_decode(
        wp_remote_retrieve_body( $response ),
        true
    );

    if (
        $status_code !== 200 ||
        empty( $body['token'] )
    ) {
        return new WP_Error(
            'egov_law_monitor_id_token_error',
            'Failed to obtain Cloud Run ID token.',
            [
                'status_code' => $status_code,
            ]
        );
    }

    return $body['token'];
}

/**
 * Generate an AI summary through Cloud Run.
 *
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $law_data_id    e-Gov law data ID.
 * @param string $sub_revision   e-Gov sub revision.
 * @return array|WP_Error
 */
function egov_law_monitor_generate_ai_summary(
    $law_id,
    $effective_date,
    $law_data_id,
    $sub_revision
) {
    /*
     * Get cached AI summary.
     */
    $cache =
        egov_law_monitor_get_ai_summary_cache(
            $law_id,
            $effective_date,
            $law_data_id,
            $sub_revision
        );

    $revision_hash = '';

    if ( is_array( $cache ) ) {
        $revision_hash =
            $cache['revision_hash'] ?? '';
    }

    $settings =
        egov_law_monitor_get_ai_cloud_settings();

    if (
        empty( $settings['project_id'] ) ||
        empty( $settings['client_email'] ) ||
        empty( $settings['private_key'] )
    ) {
        return new WP_Error(
            'egov_law_monitor_ai_not_configured',
            'AI Summary is not configured.'
        );
    }

    $cloud_run_url =
        'https://egov-law-monitor-summary-803962180455.asia-northeast1.run.app';

    $id_token =
        egov_law_monitor_get_cloud_run_id_token(
            $settings,
            $cloud_run_url
        );

    if ( is_wp_error( $id_token ) ) {
        return $id_token;
    }

    $response = wp_remote_post(
        $cloud_run_url . '/summary',
        [
            'timeout' => 120,
            'headers' => [
                'Authorization' =>
                    'Bearer ' . $id_token,
                'Content-Type' =>
                    'application/json',
            ],
            'body' => wp_json_encode(
                [
                    'law_id' => $law_id,
                    'effective_date' => $effective_date,
                    'law_data_id' => $law_data_id,
                    'sub_revision' => $sub_revision,
                    'revision_hash' => $revision_hash,
                ]
            ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code =
        wp_remote_retrieve_response_code(
            $response
        );

    $body = json_decode(
        wp_remote_retrieve_body( $response ),
        true
    );

    if ( $status_code !== 200 ) {
        return new WP_Error(
            'egov_law_monitor_ai_api_error',
            'Cloud Run AI Summary API returned an error.',
            [
                'status_code' => $status_code,
            ]
        );
    }

    if ( ! is_array( $body ) ) {
        return new WP_Error(
            'egov_law_monitor_ai_invalid_response',
            'Cloud Run returned an invalid AI Summary response.'
        );
    }

    /*
     * Current revision is unchanged.
     * Return the existing WordPress cache.
     */
    if (
        ! empty( $body['cached'] ) &&
        is_array( $cache )
    ) {
        $amendment_name =
            $body['amendment_name'] ??
            $cache['amendment_name'] ??
            '';

        $comparison_effective_date =
            $body['comparison_effective_date'] ??
            $cache['comparison_effective_date'] ??
            '';

        if (
            $amendment_name !== '' ||
            $comparison_effective_date !== ''
        ) {
            $saved = egov_law_monitor_save_ai_summary_cache(
                $law_id,
                $effective_date,
                $law_data_id,
                $sub_revision,
                $amendment_name,
                $comparison_effective_date,
                $cache['revision_hash'],
                $cache['summary_title'],
                $cache['summary_body']
            );

            if ( $saved === false ) {
                global $wpdb;

                $db_error = $wpdb->last_error;

                if ( $db_error === '' ) {
                    $db_error = 'Unknown database error.';
                }

                return new WP_Error(
                    'egov_law_monitor_ai_cache_error',
                    'Failed to update AI Summary cache: ' . $db_error
                );
            }
        }

        return [
            'law_id' => $law_id,
            'law_name' => $body['law_name'] ?? '',
            'effective_date' => $effective_date,
            'law_data_id' => $law_data_id,
            'sub_revision' => $sub_revision,
            'amendment_name' => $amendment_name,
            'comparison_effective_date' => $comparison_effective_date,
            'revision_hash' => $cache['revision_hash'],
            'summary' => [
                'title' => $cache['summary_title'],
                'body' => $cache['summary_body'],
            ],
            'cached' => true,
        ];
    }

    /*
     * A new summary was generated.
     */
    if (
        empty( $body['revision_hash'] ) ||
        empty( $body['summary'] ) ||
        ! is_array( $body['summary'] )
    ) {
        return new WP_Error(
            'egov_law_monitor_ai_invalid_response',
            'Cloud Run returned an invalid AI Summary response.'
        );
    }

    $new_revision_hash =
        $body['revision_hash'];

    $summary_title =
        $body['summary']['title'] ?? '';

    $summary_body =
        $body['summary']['body'] ?? '';

    if (
        $summary_title === '' ||
        $summary_body === ''
    ) {
        return new WP_Error(
            'egov_law_monitor_ai_invalid_response',
            'Cloud Run returned an incomplete AI Summary response.'
        );
    }

    $amendment_name =
        $body['amendment_name'] ?? '';

    $comparison_effective_date =
        $body['comparison_effective_date'] ?? '';

    $saved = egov_law_monitor_save_ai_summary_cache(
        $law_id,
        $effective_date,
        $law_data_id,
        $sub_revision,
        $amendment_name,
        $comparison_effective_date,
        $new_revision_hash,
        $summary_title,
        $summary_body
    );

    if ( $saved === false ) {
        return new WP_Error(
            'egov_law_monitor_ai_cache_error',
            'Failed to save AI Summary cache.'
        );
    }

    $body['law_id'] = $law_id;
    $body['effective_date'] = $effective_date;
    $body['law_data_id'] = $law_data_id;
    $body['sub_revision'] = $sub_revision;

    return $body;
}

/**
 * Test Cloud Run authentication without generating an AI summary.
 *
 * @return array|WP_Error
 */
function egov_law_monitor_test_cloud_run_connection() {
    $settings = egov_law_monitor_get_ai_cloud_settings();

    if (
        empty( $settings['project_id'] ) ||
        empty( $settings['client_email'] ) ||
        empty( $settings['private_key'] )
    ) {
        return new WP_Error(
            'egov_law_monitor_ai_not_configured',
            'AI Summary is not configured.'
        );
    }

    $cloud_run_url =
        'https://egov-law-monitor-summary-803962180455.asia-northeast1.run.app';

    $id_token =
        egov_law_monitor_get_cloud_run_id_token(
            $settings,
            $cloud_run_url
        );

    if ( is_wp_error( $id_token ) ) {
        return $id_token;
    }

    $response = wp_remote_get(
        $cloud_run_url . '/',
        [
            'timeout' => 30,
            'headers' => [
                'Authorization' =>
                    'Bearer ' . $id_token,
            ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status_code =
        wp_remote_retrieve_response_code(
            $response
        );

    $body = json_decode(
        wp_remote_retrieve_body( $response ),
        true
    );

    if ( $status_code !== 200 ) {
        return new WP_Error(
            'egov_law_monitor_cloud_run_error',
            'Cloud Run returned an error.',
            [
                'status_code' => $status_code,
            ]
        );
    }

    return $body;
}