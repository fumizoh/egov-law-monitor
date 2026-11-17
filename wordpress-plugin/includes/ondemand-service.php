<?php
/** Stage A only: discovery and metadata matching, never generation or usage writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_ondemand_enabled() {
    return get_option( 'egov_law_monitor_ondemand_enabled', '0' ) === '1';
}

function egov_ondemand_access() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'ondemand_forbidden', '管理者のみ利用できます。', array( 'status' => 403 ) );
    }
    if ( ! egov_ondemand_enabled() ) {
        return new WP_Error( 'ondemand_disabled', 'オンデマンド機能の管理者検証は停止中です。', array( 'status' => 403 ) );
    }
    return true;
}

function egov_ondemand_error( $message = '取得データを確認できません。時間をおいて再操作してください。' ) {
    return new WP_Error( 'ondemand_upstream', $message, array( 'status' => 502 ) );
}

function egov_ondemand_id( $value ) {
    return is_string( $value ) && preg_match( '/^[A-Za-z0-9]{10,32}$/D', $value );
}

function egov_ondemand_date( $value ) {
    if ( $value === null || $value === '' ) { return null; }
    if ( ! is_string( $value ) ) { throw new UnexpectedValueException(); }
    $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
    if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) { throw new UnexpectedValueException(); }
    return $value;
}

function egov_ondemand_text( $value ) {
    if ( $value === null ) { return ''; }
    if ( ! is_string( $value ) ) { throw new UnexpectedValueException(); }
    return $value;
}

/** Dedicated transient namespace; no ordinary summary caches are read or written. */
function egov_ondemand_cached( $kind, $args, $loader ) {
    $key = 'egov_ondemand_v1_' . hash( 'sha256', wp_json_encode( array( $kind, $args ) ) );
    $value = get_transient( $key );
    if ( is_array( $value ) && isset( $value['payload'] ) ) { return $value['payload']; }
    $value = $loader();
    if ( ! is_wp_error( $value ) ) { set_transient( $key, array( 'payload' => $value ), 300 ); }
    return $value;
}

function egov_ondemand_search( $mode, $query, $offset ) {
    $allowed = egov_ondemand_access();
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( ! in_array( $mode, array( 'title', 'number' ), true ) || ! is_string( $query ) || trim( $query ) === '' || strlen( $query ) > 600 || ! is_int( $offset ) || $offset < 0 || $offset > 10000 ) {
        return new WP_Error( 'ondemand_input', '検索条件が不正です。', array( 'status' => 400 ) );
    }
    $query = trim( $query );
    return egov_ondemand_cached( 'search', array( $mode, $query, $offset ), static function () use ( $mode, $query, $offset ) {
        $raw = egov_ondemand_http( 'laws', array( $mode === 'title' ? 'law_title' : 'law_num' => $query, 'limit' => 20, 'offset' => $offset, 'response_format' => 'json' ) );
        if ( is_wp_error( $raw ) ) { return $raw; }
        try {
            if ( ! isset( $raw['laws'], $raw['total_count'] ) || ! is_array( $raw['laws'] ) || ! is_int( $raw['total_count'] ) || $raw['total_count'] < 0 || count( $raw['laws'] ) > 20 ) { throw new UnexpectedValueException(); }
            $items = array();
            foreach ( $raw['laws'] as $row ) {
                if ( ! egov_ondemand_id( $row['law_info']['law_id'] ?? null ) ) { throw new UnexpectedValueException(); }
                $items[] = array( 'id' => $row['law_info']['law_id'], 'title' => egov_ondemand_text( $row['revision_info']['law_title'] ?? null ), 'number' => egov_ondemand_text( $row['law_info']['law_num'] ?? null ) );
            }
            return array( 'items' => $items, 'offset' => $offset, 'total' => $raw['total_count'], 'next_offset' => $offset + count( $items ) < $raw['total_count'] && count( $items ) ? $offset + count( $items ) : null );
        } catch ( Throwable $e ) { return egov_ondemand_error(); }
    } );
}

function egov_ondemand_revisions( $law_id ) {
    $allowed = egov_ondemand_access();
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( ! egov_ondemand_id( $law_id ) ) { return new WP_Error( 'ondemand_input', '法令IDが不正です。', array( 'status' => 400 ) ); }
    return egov_ondemand_cached( 'revisions', array( $law_id ), static function () use ( $law_id ) {
        $raw = egov_ondemand_http( 'revisions', array( 'law_id' => $law_id ) );
        if ( is_wp_error( $raw ) ) { return $raw; }
        try {
            if ( ( $raw['law_info']['law_id'] ?? null ) !== $law_id || ! isset( $raw['revisions'] ) || ! is_array( $raw['revisions'] ) ) { throw new UnexpectedValueException(); }
            $items = array(); $seen = array();
            foreach ( $raw['revisions'] as $row ) {
                $rid = $row['law_revision_id'] ?? null;
                if ( ! is_string( $rid ) || strlen( $rid ) > 128 || ! preg_match( '/^[A-Za-z0-9_]+$/D', $rid ) || isset( $seen[ $rid ] ) || ! array_key_exists( 'amendment_law_id', $row ) ) { throw new UnexpectedValueException(); }
                $seen[ $rid ] = true;
                $actual = egov_ondemand_date( $row['amendment_enforcement_date'] ?? null );
                $pseudo = egov_ondemand_date( $row['amendment_scheduled_enforcement_date'] ?? null );
                $amendment_id = $row['amendment_law_id'] ?? null;
                if ( $amendment_id !== null && ! egov_ondemand_id( $amendment_id ) ) { throw new UnexpectedValueException(); }
                $items[] = array(
                    'schema_version' => 1,
                    'law' => array( 'id' => $law_id, 'title' => egov_ondemand_text( $row['law_title'] ?? null ), 'number' => egov_ondemand_text( $raw['law_info']['law_num'] ?? null ) ),
                    'official_revision_id' => $rid,
                    'amendment' => array( 'id' => $amendment_id, 'title' => egov_ondemand_text( $row['amendment_law_title'] ?? null ), 'number' => egov_ondemand_text( $row['amendment_law_num'] ?? null ) ),
                    'enforcement' => array( 'api_date' => $actual, 'pseudo_date' => $pseudo, 'comment' => egov_ondemand_text( $row['amendment_enforcement_comment'] ?? null ), 'kind' => $pseudo ? 'pseudo' : ( $actual ? 'api_date' : 'unknown' ) ),
                    'revision_status' => egov_ondemand_text( $row['current_revision_status'] ?? null ),
                    'internal' => array( 'status' => 'not_checked', 'candidates' => array(), 'checked_at' => null, 'meaning' => 'metadata_only', 'generation_revalidation_required' => true ),
                );
            }
            return array( 'items' => $items, 'fetched_at' => gmdate( 'c' ) );
        } catch ( Throwable $e ) { return egov_ondemand_error(); }
    } );
}

function egov_ondemand_match( $law_id, $revision_id ) {
    $allowed = egov_ondemand_access();
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( ! is_string( $revision_id ) || strlen( $revision_id ) > 128 ) { return new WP_Error( 'ondemand_input', '公式版IDが不正です。', array( 'status' => 400 ) ); }
    $history = egov_ondemand_revisions( $law_id );
    if ( is_wp_error( $history ) ) { return $history; }
    $selected = array_values( array_filter( $history['items'], static function ( $r ) use ( $revision_id ) { return $r['official_revision_id'] === $revision_id; } ) );
    if ( count( $selected ) !== 1 ) { return new WP_Error( 'ondemand_revision', '対象法令の公式版IDを選び直してください。', array( 'status' => 400 ) ); }
    $record = $selected[0];
    $record['internal']['checked_at'] = gmdate( 'c' );
    $date = $record['enforcement']['pseudo_date'] ?: $record['enforcement']['api_date'];
    if ( ! $date ) { $record['internal']['status'] = 'unavailable'; return $record; }
    $raw = egov_ondemand_cached( 'internal', array( $law_id ), static function () use ( $law_id ) {
        $data = egov_ondemand_http( 'internal', array( 'law_id' => $law_id ) );
        if ( is_wp_error( $data ) ) { return $data; }
        if ( ( $data['result']['success'] ?? null ) !== true || ! isset( $data['result']['Amendment_History'] ) || ! is_array( $data['result']['Amendment_History'] ) ) { return egov_ondemand_error(); }
        try {
            $rows = array();
            foreach ( $data['result']['Amendment_History'] as $row ) {
                if ( ! is_array( $row ) || ! array_key_exists( 'AmendmentId', $row ) || ! array_key_exists( 'EnforcementDate', $row ) || ! array_key_exists( 'ScheduledEnforcementDate', $row ) ) { throw new UnexpectedValueException(); }
                $id = $row['AmendmentId']; $internal_id = $row['LawDataId'] ?? null; $sub = $row['SubRevision'] ?? null;
                if ( ( $id !== null && ! egov_ondemand_id( $id ) ) || ! ( is_int( $internal_id ) || is_string( $internal_id ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $internal_id ) || ! is_string( $sub ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,32}$/D', $sub ) ) { throw new UnexpectedValueException(); }
                $rows[] = array( 'amendment_id' => $id, 'api_date' => egov_ondemand_date( $row['EnforcementDate'] ), 'pseudo_date' => egov_ondemand_date( $row['ScheduledEnforcementDate'] ), 'law_data_id' => (string) $internal_id, 'sub_revision' => $sub );
            }
            return $rows;
        } catch ( Throwable $e ) { return egov_ondemand_error(); }
    } );
    if ( is_wp_error( $raw ) ) { $record['internal']['status'] = 'unavailable'; return $record; }
    try {
        $matches = array();
        foreach ( $raw as $row ) {
            if ( $row['amendment_id'] === $record['amendment']['id'] && ( $row['pseudo_date'] ?: $row['api_date'] ) === $date ) {
                $matches[] = array( 'law_data_id' => $row['law_data_id'], 'sub_revision' => $row['sub_revision'] );
            }
        }
        $record['internal']['status'] = count( $matches ) === 1 ? 'matched' : ( count( $matches ) ? 'ambiguous' : 'unmatched' );
        $record['internal']['candidates'] = $matches;
    } catch ( Throwable $e ) { $record['internal']['status'] = 'unavailable'; }
    return $record;
}
