<?php
/**
 * e-Gov Law Monitor - AI Summary Authentication
 */

/**
 * Get encryption key.
 *
 * @return string
 */
function egov_law_monitor_get_encryption_key() {
    if ( ! defined( 'EGOV_LAW_MONITOR_ENCRYPTION_KEY' ) ) {
        return '';
    }

    return EGOV_LAW_MONITOR_ENCRYPTION_KEY;
}

/**
 * Encrypt a secret value.
 *
 * @param string $value Plain text value.
 * @return string
 */
function egov_law_monitor_encrypt_secret( $value ) {
    $key = egov_law_monitor_get_encryption_key();

    if ( $key === '' || $value === '' ) {
        return '';
    }

    $cipher    = 'aes-256-gcm';
    $iv_length = openssl_cipher_iv_length( $cipher );

    if ( $iv_length === false ) {
        return '';
    }

    $iv = random_bytes( $iv_length );

    $tag = '';

    $encrypted = openssl_encrypt(
        $value,
        $cipher,
        hash( 'sha256', $key, true ),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ( $encrypted === false ) {
        return '';
    }

    return base64_encode(
        $iv . $tag . $encrypted
    );
}

/**
 * Decrypt a secret value.
 *
 * @param string $value Encrypted value.
 * @return string
 */
function egov_law_monitor_decrypt_secret( $value ) {
    $key = egov_law_monitor_get_encryption_key();

    if ( $key === '' || $value === '' ) {
        return '';
    }

    $cipher    = 'aes-256-gcm';
    $iv_length = openssl_cipher_iv_length( $cipher );
    $tag_length = 16;

    if ( $iv_length === false ) {
        return '';
    }

    $decoded = base64_decode( $value, true );

    if ( $decoded === false ) {
        return '';
    }

    $minimum_length = $iv_length + $tag_length;

    if ( strlen( $decoded ) <= $minimum_length ) {
        return '';
    }

    $iv = substr(
        $decoded,
        0,
        $iv_length
    );

    $tag = substr(
        $decoded,
        $iv_length,
        $tag_length
    );

    $encrypted = substr(
        $decoded,
        $minimum_length
    );

    $decrypted = openssl_decrypt(
        $encrypted,
        $cipher,
        hash( 'sha256', $key, true ),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ( $decrypted === false ) {
        return '';
    }

    return $decrypted;
}