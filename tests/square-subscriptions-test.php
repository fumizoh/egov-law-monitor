<?php
/** Run: php tests/square-subscriptions-test.php. No WordPress or real DB access. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
if ( ! class_exists( 'mysqli_sql_exception' ) ) {
    class mysqli_sql_exception extends RuntimeException {}
}
class WP_Error {
    public function __construct( public $code, public $message ) {}
    public function get_error_message() { return $this->message; }
}
function current_user_can( $capability ) { return $GLOBALS['allowed']; }
function get_userdata( $id ) { return in_array( (int) $id, array( 10, 20 ), true ) ? (object) array( 'ID' => $id ) : false; }
function get_current_user_id() { return 99; }
function current_time( $format ) { return '2026-11-07 12:00:00'; }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return 'test-only-signing-key'; }
function get_option( $name ) { return $GLOBALS['schema_version'] ?? ''; }
function update_option( $name, $value, $autoload = false ) { $GLOBALS['schema_version'] = $value; }
function dbDelta( $sql ) { ++$GLOBALS['ddl_calls']; }
function add_action( ...$args ) {}
function register_activation_hook( ...$args ) {}
function wp_die( $message ) { throw new RuntimeException( $message ); }
function check_admin_referer( $action, $name ) {
    $GLOBALS['nonce_action'] = $action;
    throw new RuntimeException( 'invalid nonce' );
}

/** In-memory transactional double: test failure paths, never connects to a DB. */
#[AllowDynamicProperties]
class SquareTestDatabase {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $contracts = array();
    public $logs = array();
    public $fail = '';
    public $engine = 'InnoDB';
    public $snapshot;
    public $metadata;
    public $disconnect_on = '';
    public $disconnected = false;
    public $reconnects = 0;
    public $switched = false;
    public $queries = array();
    public $commit_applied = false;
    public function __construct() {
        // Physical metadata fixture, independent of the production validator.
        $definitions = array(
            'subscriptions' => array(
                'id' => 'bigint(20) unsigned', 'user_id' => 'bigint(20) unsigned',
                'square_customer_id' => 'varchar(255)', 'square_subscription_id' => 'varchar(255)',
                'created_at' => 'datetime', 'updated_at' => 'datetime',
                'verified_at' => 'datetime', 'verified_by' => 'bigint(20) unsigned', 'admin_note' => 'text',
            ),
            'log' => array(
                'id' => 'bigint(20) unsigned', 'user_id' => 'bigint(20) unsigned',
                'admin_user_id' => 'bigint(20) unsigned', 'action' => 'varchar(50)',
                'old_value' => 'longtext', 'new_value' => 'longtext', 'reason' => 'text', 'created_at' => 'datetime',
            ),
        );
        foreach ( $definitions as $table => $columns ) {
            foreach ( $columns as $name => $type ) {
                $this->metadata[$table]['columns'][] = array(
                    'Field' => $name, 'Type' => $type,
                    'Null' => in_array( $name, array( 'verified_at', 'verified_by', 'admin_note', 'old_value', 'new_value', 'reason' ), true ) ? 'YES' : 'NO',
                    'Extra' => $name === 'id' ? 'auto_increment' : '',
                );
            }
            $this->metadata[$table]['indexes'] = array(
                array( 'Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 0, 'Sub_part' => null ),
                array( 'Key_name' => 'user_id', 'Seq_in_index' => 1, 'Column_name' => 'user_id', 'Non_unique' => 1, 'Sub_part' => null ),
            );
        }
        $this->metadata['subscriptions']['indexes'][] = array( 'Key_name' => 'square_subscription_id', 'Seq_in_index' => 1, 'Column_name' => 'square_subscription_id', 'Non_unique' => 0, 'Sub_part' => null );
    }
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_row( $query, $mode ) {
        list( $sql, $args ) = $query;
        if ( $this->query( $sql ) === false ) { return null; }
        if ( str_starts_with( $sql, 'SHOW TABLE' ) ) { return array( 'Engine' => $this->engine ); }
        return $this->contracts[ $args[0] ] ?? null;
    }
    public function get_results( $sql, $mode ) {
        if ( $this->query( $sql ) === false ) { return null; }
        $table = str_contains( $sql, 'subscriptions' ) ? 'subscriptions' : 'log';
        return $this->metadata[$table][ str_starts_with( $sql, 'SHOW INDEX' ) ? 'indexes' : 'columns' ];
    }
    public function get_var( $query ) {
        if ( $this->query( $query[0] ) === false ) { return null; }
        foreach ( $this->contracts as $id => $row ) {
            if ( $row['square_subscription_id'] === $query[1][0] && $id !== $query[1][1] ) { return $id; }
        }
        return null;
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        $this->last_error = '';
        if ( $this->disconnect_on === $sql ) {
            $this->disconnected = true;
            $this->disconnect_on = '';
            if ( $this->snapshot ) { list( $this->contracts, $this->logs ) = $this->snapshot; }
        }
        // A native dedicated session never retries a failed operation.
        if ( $this->disconnected ) {
            $this->last_error = 'connection lost';
            return false;
        }
        if ( $this->fail === $sql || ( $sql === 'ROLLBACK' && $this->fail === 'log_and_rollback' ) ) {
            $this->last_error = 'simulated SQL failure';
            if ( $sql === 'COMMIT' && $this->commit_applied ) { $this->snapshot = array( $this->contracts, $this->logs ); }
            return false;
        }
        if ( $sql === 'START TRANSACTION' ) { $this->snapshot = array( $this->contracts, $this->logs ); }
        if ( $sql === 'ROLLBACK' ) { list( $this->contracts, $this->logs ) = $this->snapshot; }
        return 0;
    }
    public function insert( $table, $data, $formats ) {
        if ( $this->query( str_ends_with( $table, 'admin_log' ) ? 'INSERT audit' : 'INSERT contract' ) === false ) { return false; }
        if ( str_ends_with( $table, 'admin_log' ) ) {
            if ( in_array( $this->fail, array( 'log', 'log_and_rollback' ), true ) ) { return false; }
            $this->logs[] = $data;
        } else {
            if ( $this->fail === 'contract' ) { return false; }
            $this->insert_id = count( $this->contracts ) + 1;
            $this->contracts[ $this->insert_id ] = array( 'id' => $this->insert_id ) + $data;
        }
        return 1;
    }
    public function update( $table, $data, $where, $formats, $where_formats ) {
        if ( $this->query( 'UPDATE contract' ) === false || $this->fail === 'contract' ) { return false; }
        $this->contracts[ $where['id'] ] = array_merge( $this->contracts[ $where['id'] ], $data );
        return 1;
    }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
}
// The unit harness models wpdb; it does not test WordPress/mysqli itself.
class wpdb extends SquareTestDatabase {
    public function parse_db_host( $host ) {
        if ( $host !== 'localhost' ) { throw new LogicException( 'factory fixture only supports localhost' ); }
        return array( 'localhost', null, null, false );
    }
}
require __DIR__ . '/../wordpress-plugin/includes/square-subscriptions.php';
/** Separate session double: only committed database storage is shared with WordPress. */
$checks = 0;
function check_square( $condition, $label ) {
    global $checks;
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$checks;
    echo "PASS: {$label}\n";
}
$allowed = true;
$wpdb = new SquareTestDatabase();
check_square( egov_law_monitor_square_storage_ready(), 'complete physical schema accepted' );
$good_metadata = $wpdb->metadata;
foreach ( array( 'subscriptions', 'log' ) as $table_name ) {
    foreach ( $good_metadata[$table_name]['columns'] as $position => $column ) {
        $wpdb->metadata = $good_metadata;
        unset( $wpdb->metadata[$table_name]['columns'][$position] );
        check_square( ! egov_law_monitor_square_storage_ready(), 'missing column rejected: ' . $table_name . '.' . $column['Field'] );
    }
}
$wpdb->metadata = $good_metadata;
$wpdb->metadata['subscriptions']['columns'][0]['Type'] = 'bigint';
check_square( ! egov_law_monitor_square_storage_ready(), 'signed primary ID rejected' );
$wpdb->metadata = $good_metadata;
$wpdb->metadata['subscriptions']['columns'][0]['Extra'] = '';
check_square( ! egov_law_monitor_square_storage_ready(), 'missing auto increment rejected' );
$wpdb->metadata = $good_metadata;
$wpdb->metadata['subscriptions']['columns'][6]['Null'] = 'NO';
check_square( ! egov_law_monitor_square_storage_ready(), 'incorrect verification nullability rejected' );
foreach ( array( 'subscriptions', 'log' ) as $table_name ) {
    foreach ( array( 0 => 'primary key', 1 => 'user index' ) as $position => $label ) {
        $wpdb->metadata = $good_metadata;
        unset( $wpdb->metadata[$table_name]['indexes'][$position] );
        check_square( ! egov_law_monitor_square_storage_ready(), "missing {$table_name} {$label} rejected" );
    }
}
$wpdb->metadata = $good_metadata;
unset( $wpdb->metadata['subscriptions']['indexes'][2] );
check_square( ! egov_law_monitor_square_storage_ready() && ! $wpdb->contracts, 'missing subscription UNIQUE fails shared storage validation' );
$schema_version = '1';
$ddl_calls = 0;
egov_law_monitor_install_square_tables();
check_square( $ddl_calls === 2 && $schema_version === '1', 'partial dbDelta outcome does not advance schema version' );
$schema_version = '2';
egov_law_monitor_install_square_tables();
check_square( $ddl_calls === 4 && ! egov_law_monitor_square_storage_ready(), 'current version never bypasses physical validation' );
$wpdb->metadata = $good_metadata;
$schema_version = '1';
egov_law_monitor_install_square_tables();
check_square( $schema_version === '2', 'schema version advances only with complete metadata' );
$ddl_before = $ddl_calls;
egov_law_monitor_install_square_tables();
check_square( $ddl_calls === $ddl_before, 'complete current schema skips DDL' );
foreach ( array( 'prefix', 'composite', 'nonunique' ) as $bad_index ) {
    $wpdb->metadata = $good_metadata;
    if ( $bad_index === 'prefix' ) { $wpdb->metadata['subscriptions']['indexes'][2]['Sub_part'] = 50; }
    if ( $bad_index === 'nonunique' ) { $wpdb->metadata['subscriptions']['indexes'][2]['Non_unique'] = 1; }
    if ( $bad_index === 'composite' ) {
        $wpdb->metadata['subscriptions']['indexes'][] = array( 'Key_name' => 'square_subscription_id', 'Seq_in_index' => 2, 'Column_name' => 'user_id', 'Non_unique' => 0, 'Sub_part' => null );
    }
    check_square( ! egov_law_monitor_square_storage_ready(), "{$bad_index} subscription index rejected" );
}

class SquareHostParserStub {
    public $seen;
    public function __construct( private $parts ) {}
    public function parse_db_host( $host ) { $this->seen = $host; return $this->parts; }
}
$snapshot = array(
    'db_name' => 'wordpress', 'server_host' => 'db-server', 'server_port' => '3306', 'server_id' => '1',
    'connection_id' => '10', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    'sql_mode' => 'NO_ENGINE_SUBSTITUTION', 'read_only' => '0', 'ssl_cipher' => '',
);
foreach ( array(
    array( 'localhost', array( 'localhost', null, null, false ) ),
    array( 'db.example:3307', array( 'db.example', 3307, null, false ) ),
    array( 'localhost:/run/mysql.sock', array( 'localhost', null, '/run/mysql.sock', false ) ),
    array( '[::1]:3307', array( '::1', 3307, null, true ) ),
) as $case ) {
    $parser = new SquareHostParserStub( $case[1] );
    $options = egov_law_monitor_square_connection_options( $parser, $case[0], $snapshot );
    $expected_host = $case[1][3] && extension_loaded( 'mysqlnd' ) ? '[' . $case[1][0] . ']' : $case[1][0];
    check_square( $parser->seen === $case[0] && $options['host'] === $expected_host && $options['port'] === $case[1][1] && $options['socket'] === $case[1][2], 'WordPress host parser output respected: ' . $case[0] );
    check_square( $options['charset'] === $snapshot['charset'] && $options['collation'] === $snapshot['collation'] && $options['sql_mode'] === $snapshot['sql_mode'], 'effective WordPress session settings retained' );
}
foreach ( array( 'persistent', 'flags', 'tls', 'readonly', 'bad_charset', 'bad_collation', 'bad_mode', 'bad_port', 'unparsed_host' ) as $invalid ) {
    $bad = $snapshot;
    $parts = array( 'localhost', null, null, false );
    if ( $invalid === 'tls' ) { $bad['ssl_cipher'] = 'TLS_CIPHER'; }
    if ( $invalid === 'readonly' ) { $bad['read_only'] = '1'; }
    if ( $invalid === 'bad_charset' ) { $bad['charset'] = "utf8mb4'; SELECT 1"; }
    if ( $invalid === 'bad_collation' ) { $bad['collation'] = 'invalid collation'; }
    if ( $invalid === 'bad_mode' ) { $bad['sql_mode'] = "MODE'; SELECT 1"; }
    if ( $invalid === 'bad_port' ) { $parts[1] = 65536; }
    if ( $invalid === 'unparsed_host' ) { $parts = false; }
    try {
        egov_law_monitor_square_connection_options( new SquareHostParserStub( $parts ), $invalid === 'persistent' ? 'p:localhost' : 'localhost', $bad, $invalid === 'flags' ? 1 : 0 );
        throw new LogicException( 'unsupported connection accepted' );
    } catch ( RuntimeException $error ) {
        check_square( true, "unsupported connection refused: {$invalid}" );
    }
}
$other = $snapshot;
$other['connection_id'] = '11';
EGOV_Law_Monitor_Square_Connection::verify_identity( $snapshot, $other );
check_square( true, 'same server/database with a different session accepted' );
foreach ( array_keys( $snapshot ) as $key ) {
    $bad = $other;
    $bad[$key] = $key === 'connection_id' ? $snapshot[$key] : 'different';
    try {
        EGOV_Law_Monitor_Square_Connection::verify_identity( $snapshot, $bad );
        throw new LogicException( 'mismatched connection accepted' );
    } catch ( RuntimeException $error ) {
        check_square( true, 'connection identity mismatch refused: ' . $key );
    }
}
try {
    EGOV_Law_Monitor_Square_Connection::open( new stdClass() );
    throw new LogicException( 'custom DB source accepted' );
} catch ( RuntimeException $error ) {
    check_square( true, 'custom database source rejected without fallback' );
}

/** Native-driver doubles exercise the production prepared-statement code. */
class SquareNativeMetadata {
    public function __construct( private $names ) {}
    public function fetch_fields() { return array_map( static fn( $name ) => (object) array( 'name' => $name ), $this->names ); }
    public function free() {}
}
class SquareNativeStatement {
    public $insert_id = 123;
    public $closed = false;
    private $bindings = array();
    private $outputs = array();
    private $position = 0;
    public function __construct( private $driver, private $sql, private $rows ) {}
    public function bind_param( $types, &...$values ) { $this->bindings = array( 'types' => $types, 'values' => $values ); return true; }
    public function execute() {
        $this->driver->bindings[] = $this->bindings;
        if ( $this->driver->failure === 'strict_sql' ) { throw new mysqli_sql_exception( 'test SQL error', 1062 ); }
        if ( $this->driver->failure === 'disconnect' ) { $this->driver->errno = 2006; return false; }
        if ( $this->driver->failure === 'switch' ) { ++$this->driver->thread_id; }
        return $this->driver->failure !== 'execute';
    }
    public function store_result() { return true; }
    public function result_metadata() { return new SquareNativeMetadata( array_keys( $this->rows[0] ?? array( 'id' => null ) ) ); }
    public function bind_result( &...$values ) { $this->outputs =& $values; return true; }
    public function fetch() {
        if ( $this->position >= count( $this->rows ) ) { return null; }
        foreach ( array_values( $this->rows[$this->position++] ) as $index => $value ) { $this->outputs[$index] = $value; }
        return true;
    }
    public function close() { $this->closed = true; }
}
class SquareNativeDriver {
    public $thread_id = 101;
    public $errno = 0;
    public $failure = '';
    public $prepared = array();
    public $bindings = array();
    public $statements = array();
    public $result_rows = array();
    public $close_count = 0;
    public $controls = array();
    public function prepare( $sql ) {
        $this->prepared[] = $sql;
        if ( $this->failure === 'prepare' ) { return false; }
        $statement = new SquareNativeStatement( $this, $sql, $this->result_rows );
        $this->statements[] = $statement;
        return $statement;
    }
    public function begin_transaction() { $this->controls[] = 'begin'; return true; }
    public function commit() { $this->controls[] = 'commit'; if ( $this->failure === 'commit' ) { $this->errno = 2013; return false; } return true; }
    public function rollback() { $this->controls[] = 'rollback'; return $this->failure !== 'rollback'; }
    public function close() { ++$this->close_count; return true; }
}
$driver = new SquareNativeDriver();
$native = new EGOV_Law_Monitor_Square_Connection( $driver, 'custom_' );
$driver->result_rows = array( array( 'id' => 1, 'admin_note' => '最初' ), array( 'id' => 2, 'admin_note' => '次' ) );
check_square( $native->rows( 'SELECT id, admin_note FROM example WHERE user_id = ?', array( 10 ) ) === $driver->result_rows, 'native bound results copied correctly across rows' );
check_square( $driver->bindings[0]['types'] === 'i' && $driver->bindings[0]['values'] === array( 10 ), 'native integer parameter bound' );
check_square( $driver->statements[0]->closed, 'native SELECT statement released' );
$note = "日本語'; DROP TABLE example; --";
check_square( $native->write( 'INSERT INTO example (note, old_value) VALUES (?, ?)', array( $note, null ) ) && $native->insert_id === 123, 'native insert succeeds and returns inserted ID' );
check_square( $driver->bindings[1]['values'] === array( $note, null ) && ! str_contains( $driver->prepared[1], $note ), 'note and NULL stay bound parameters, never SQL text' );
check_square( $native->begin() && $native->commit() && $native->rollback() && $driver->controls === array( 'begin', 'commit', 'rollback' ), 'native transaction control methods used' );
$native->close();
$native->close();
check_square( $driver->close_count === 1, 'owned native connection closed exactly once' );
check_square( $native->write( 'UPDATE example SET note = ?', array( 'no' ) ) === false && count( $driver->prepared ) === 2, 'closed connection never reused' );
foreach ( array( 'prepare', 'execute', 'strict_sql', 'disconnect', 'switch', 'commit', 'rollback' ) as $failure ) {
    $driver = new SquareNativeDriver();
    $driver->failure = $failure;
    $native = new EGOV_Law_Monitor_Square_Connection( $driver, 'wp_' );
    $result = $failure === 'commit' ? $native->commit() : ( $failure === 'rollback' ? $native->rollback() : $native->write( 'UPDATE example SET note = ?', array( 'test' ) ) );
    check_square( $result === false, "native failure returned without retry: {$failure}" );
    if ( in_array( $failure, array( 'disconnect', 'switch', 'commit' ), true ) ) {
        $count = count( $driver->prepared );
        check_square( $native->connection_lost && $native->write( 'UPDATE example SET note = ?', array( 'retry' ) ) === false && count( $driver->prepared ) === $count, 'native connection fault latches and blocks further SQL' );
    }
    if ( $failure === 'strict_sql' ) {
        $driver->failure = '';
        check_square( ! $native->connection_lost && $native->rollback(), 'ordinary SQL exception still permits rollback' );
    }
    $native->close();
    check_square( $driver->close_count === 1, "native failed connection released: {$failure}" );
}

// When mysqli is absent, exercise open()/cleanup with a complete driver double.
// With the real extension enabled, these doubles must never replace native functions.
if ( ! extension_loaded( 'mysqli' ) ) {
    foreach ( array( 'DB_HOST' => 'localhost', 'DB_NAME' => 'wordpress', 'DB_USER' => 'test-only-user', 'DB_PASSWORD' => 'test-only-password', 'MYSQLI_OPT_CONNECT_TIMEOUT' => 0, 'MYSQLI_OPT_READ_TIMEOUT' => 11 ) as $name => $value ) {
        define( $name, $value );
    }
    class SquareFactoryResult {
        public function __construct( private $row ) {}
        public function fetch_assoc() { return $this->row; }
        public function free() {}
    }
    class mysqli extends SquareNativeDriver {
        public $settings = array();
        public $factory_failure = '';
        public $snapshot;
        public function options( $key, $value ) { $this->settings[$key] = $value; return true; }
        public function real_connect( $host, $user, $password, $database, $port, $socket, $flags ) {
            $this->settings['host'] = $host;
            $this->settings['port'] = $port;
            $this->settings['socket'] = $socket;
            $this->settings['flags'] = $flags;
            return $this->factory_failure !== 'connect';
        }
        public function set_charset( $charset ) { $this->settings['charset'] = $charset; return $this->factory_failure !== 'charset'; }
        public function autocommit( $enabled ) { $this->settings['autocommit'] = $enabled; return true; }
        public function query( $sql ) {
            if ( $this->factory_failure === 'snapshot' ) { return false; }
            if ( str_starts_with( $sql, 'SHOW' ) ) { return new SquareFactoryResult( array( 'Variable_name' => 'Ssl_cipher', 'Value' => $this->snapshot['ssl_cipher'] ) ); }
            $row = $this->snapshot;
            $row['connection_id'] = $this->thread_id;
            if ( $this->factory_failure === 'identity' ) { $row['server_id'] = 'other-server'; }
            return new SquareFactoryResult( $row );
        }
    }
    function mysqli_init() {
        $driver = new mysqli();
        $driver->thread_id = 202;
        $driver->snapshot = $GLOBALS['factory_snapshot'];
        $driver->factory_failure = $GLOBALS['factory_failure'];
        if ( $driver->factory_failure === 'configure' ) { $driver->failure = 'execute'; }
        $GLOBALS['factory_handles'][] = $driver;
        return $driver;
    }
    $factory_snapshot = $snapshot;
    $source = new wpdb();
    $source->dbhost = DB_HOST;
    $source->dbname = DB_NAME;
    $source->dbuser = DB_USER;
    $source->dbpassword = DB_PASSWORD;
    $source->ready = true;
    $source->prefix = 'site_7_';
    $source->dbh = new mysqli();
    $source->dbh->snapshot = $snapshot;
    $source->dbh->begin_transaction();
    $factory_failure = '';
    $factory_handles = array();
    $connection = EGOV_Law_Monitor_Square_Connection::open( $source );
    $owned = $factory_handles[0];
    check_square( count( $factory_handles ) === 1 && $connection->prefix === 'site_7_', 'factory opens one independent handle and retains site prefix' );
    check_square( $owned->settings['flags'] === 0 && $owned->settings['host'] === 'localhost' && $owned->settings['autocommit'] === true && $owned->settings['charset'] === 'utf8mb4', 'factory uses nonpersistent standard settings on owned handle' );
    check_square( $owned->bindings[0]['values'] === array( 'utf8mb4_unicode_ci' ) && $owned->bindings[1]['values'] === array( 'NO_ENGINE_SUBSTITUTION' ), 'factory binds collation and SQL mode on owned handle' );
    $connection->close();
    check_square( $owned->close_count === 1 && $source->dbh->close_count === 0 && $source->dbh->controls === array( 'begin' ) && ! $source->dbh->settings, 'factory and close leave original connection and outer transaction untouched' );
    foreach ( array( 'connect', 'charset', 'configure', 'snapshot', 'identity' ) as $failure ) {
        $factory_failure = $failure;
        $factory_handles = array();
        try {
            EGOV_Law_Monitor_Square_Connection::open( $source );
            throw new LogicException( 'invalid factory result accepted' );
        } catch ( RuntimeException $error ) {
            check_square( ! str_contains( $error->getMessage(), DB_PASSWORD ), "factory failure contains no credentials: {$failure}" );
        }
        check_square( count( $factory_handles ) === 1 && $factory_handles[0]->close_count === 1 && $source->dbh->close_count === 0, "initialization failure closes only owned connection: {$failure}" );
    }
    $source->dbh->snapshot['ssl_cipher'] = 'TLS_CIPHER';
    $factory_handles = array();
    try {
        EGOV_Law_Monitor_Square_Connection::open( $source );
        throw new LogicException( 'unreproducible TLS accepted' );
    } catch ( RuntimeException $error ) {
        check_square( ! $factory_handles && $source->dbh->close_count === 0, 'unreproducible TLS rejected before opening any connection' );
    }
}

echo "{$checks} checks passed.\n";
