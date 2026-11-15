<?php
/** DB read boundary only. Actual save functions and standard API calls are exercised. */
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function __construct( public $code, public $message, public $data = array() ) {} public function get_error_message() { return $this->message; } } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( ...$args ) {} }
if ( ! function_exists( 'register_activation_hook' ) ) { function register_activation_hook( ...$args ) {} }
function withdrawal_test_query( $query ) {
    list( $sql, $p ) = is_array( $query ) ? $query : array( $query, array() );
    return str_contains( $sql, 'egov_user_withdrawals' ) || ( str_contains( $sql, 'information_schema' ) && str_contains( $p[0] ?? '', 'egov_user_withdrawals' ) );
}
class WithdrawalReadStore {
    public $last_error = '', $connection_lost = false;
    public function rows( $sql, $params = array() ) {
        $GLOBALS['withdrawal_read_events'][] = array( $sql, $params );
        if ( ( $GLOBALS['withdrawal_read_failure'] ?? '' ) === 'read' ) { return false; }
        if ( str_contains( $sql, 'information_schema.TABLES' ) ) { return array( array( 'TABLE_NAME' => $params[0], 'ENGINE' => ( $GLOBALS['withdrawal_read_failure'] ?? '' ) === 'engine' ? 'MyISAM' : 'InnoDB' ) ); }
        if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) {
            $rows = array();
            foreach ( egov_law_monitor_withdrawal_columns() as $key => $spec ) { $rows[] = array( 'Field' => $key, 'Type' => $spec[0], 'Null' => $spec[1] ? 'YES' : 'NO' ); }
            if ( ( $GLOBALS['withdrawal_read_failure'] ?? '' ) === 'column' ) { array_pop( $rows ); }
            return $rows;
        }
        if ( str_starts_with( $sql, 'SHOW INDEX' ) ) { return array( array( 'Key_name' => 'PRIMARY', 'Column_name' => 'process_uuid', 'Non_unique' => 0 ), array( 'Key_name' => 'user_id', 'Column_name' => 'user_id', 'Non_unique' => ( $GLOBALS['withdrawal_read_failure'] ?? '' ) === 'index' ? 1 : 0 ) ); }
        if ( str_starts_with( $sql, 'SELECT ID ' ) ) { return array( array( 'ID' => $params[0] ) ); }
        if ( str_contains( $sql, 'egov_user_withdrawals' ) ) {
            if ( ! empty( $GLOBALS['withdrawal_blocked'] ) ) { return array( array( 'user_id' => $params[0], 'process_uuid' => '10000000-0000-4000-8000-000000000000', 'phase' => 'started', 'revision' => 1 ) ); }
            return isset( $GLOBALS['withdrawal_records'][$params[0]] ) ? array( $GLOBALS['withdrawal_records'][$params[0]] ) : array();
        }
        throw new LogicException( 'Unexpected withdrawal read: ' . $sql );
    }
}
function reset_withdrawal_reads() {
    $GLOBALS['withdrawal_records'] = array(); $GLOBALS['withdrawal_read_events'] = array();
    $GLOBALS['withdrawal_read_failure'] = ''; $GLOBALS['withdrawal_blocked'] = false;
    $GLOBALS['egov_withdrawal_schema_checks'] = array();
}
reset_withdrawal_reads();
require_once __DIR__ . '/../wordpress-plugin/includes/user-withdrawal-foundation.php';
