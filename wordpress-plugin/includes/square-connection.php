<?php
/** One non-persistent connection, owned exclusively by one Square save. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Interpret existing WordPress settings; never invent connection defaults. */
function egov_law_monitor_square_connection_options( $source, $host, $snapshot, $flags = 0 ) {
    if ( ! is_string( $host ) || $host === '' || stripos( $host, 'p:' ) === 0 || $flags !== 0 || ! empty( $snapshot['ssl_cipher'] ) ) {
        throw new RuntimeException( '永続接続・追加接続フラグ・TLS接続の設定を再現できないため保存できません。' );
    }
    $parts = $source->parse_db_host( $host );
    if ( ! is_array( $parts ) || count( $parts ) !== 4 || $parts[0] === '' || ( $parts[1] !== null && ( $parts[1] < 1 || $parts[1] > 65535 ) ) ) {
        throw new RuntimeException( 'DB_HOSTの接続先を確認できません。' );
    }
    if ( $parts[3] && extension_loaded( 'mysqlnd' ) ) {
        $parts[0] = '[' . $parts[0] . ']';
    }
    foreach ( array( 'charset', 'collation' ) as $key ) {
        if ( ! isset( $snapshot[$key] ) || ! preg_match( '/\A[a-zA-Z0-9_]+\z/', $snapshot[$key] ) ) {
            throw new RuntimeException( 'DB文字コード・照合順序を確認できません。' );
        }
    }
    if ( ! isset( $snapshot['sql_mode'] ) || ! preg_match( '/\A[A-Z0-9_,]*\z/', $snapshot['sql_mode'] ) || (string) ( $snapshot['read_only'] ?? '' ) !== '0' ) {
        throw new RuntimeException( 'DBのセッション設定または書き込み先を確認できません。' );
    }
    return array( 'host' => $parts[0], 'port' => $parts[1], 'socket' => $parts[2], 'charset' => $snapshot['charset'], 'collation' => $snapshot['collation'], 'sql_mode' => $snapshot['sql_mode'] );
}

class EGOV_Law_Monitor_Square_Connection {
    private $handle;
    private $thread;
    private $closed = false;
    public $prefix;
    public $last_error = '';
    public $connection_lost = false;
    public $insert_id = 0;

    /** Only open() supplies a real handle; constructor also permits driver doubles. */
    public function __construct( $handle, $prefix ) {
        $this->handle = $handle;
        $this->thread = $handle->thread_id;
        $this->prefix = $prefix;
    }

    /** Read constants/session identity without reconnecting or changing the WP session. */
    public static function open( $source ) {
        if ( get_class( $source ) !== 'wpdb' || ! ( $source->dbh instanceof mysqli ) || ! $source->ready || ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' ) ) ) {
            throw new RuntimeException( '標準wpdb・mysqli以外のDB構成では専用接続を保証できません。' );
        }
        foreach ( array( 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ) as $name ) {
            if ( ! defined( $name ) ) {
                throw new RuntimeException( 'WordPressのDB設定を確認できません。' );
            }
        }
        if ( $source->dbhost !== DB_HOST || $source->dbname !== DB_NAME || $source->dbuser !== DB_USER || $source->dbpassword !== DB_PASSWORD ) {
            throw new RuntimeException( 'WordPressのDB接続と既存設定が一致しないため保存できません。' );
        }
        $expected = self::snapshot( $source->dbh );
        if ( $expected['db_name'] !== DB_NAME ) {
            throw new RuntimeException( 'WordPressの選択中DBを確認できません。' );
        }
        $options = egov_law_monitor_square_connection_options( $source, DB_HOST, $expected, defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 );
        $handle = null;
        $db = null;
        try {
            $handle = mysqli_init();
            if ( ! $handle || ! $handle->options( MYSQLI_OPT_CONNECT_TIMEOUT, 5 ) || ! $handle->options( MYSQLI_OPT_READ_TIMEOUT, 15 ) ) {
                throw new RuntimeException();
            }
            // Do not change mysqli_report(): its setting also affects WordPress.
            if ( ! @$handle->real_connect( $options['host'], DB_USER, DB_PASSWORD, DB_NAME, $options['port'], $options['socket'], 0 ) ) {
                throw new RuntimeException();
            }
            $db = new self( $handle, $source->prefix );
            if ( ! @$handle->set_charset( $options['charset'] ) || ! @$handle->autocommit( true ) ) {
                throw new RuntimeException();
            }
            // Values come from the server and are restricted to identifier/mode characters.
            if ( ! $db->write( 'SET SESSION collation_connection = ?', array( $options['collation'] ) ) || ! $db->write( 'SET SESSION sql_mode = ?', array( $options['sql_mode'] ) ) ) {
                throw new RuntimeException();
            }
            $actual = self::snapshot( $handle );
            self::verify_identity( $expected, $actual );
            return $db;
        } catch ( Throwable $error ) {
            if ( $db ) {
                $db->close();
            } elseif ( $handle ) {
                try { @$handle->close(); } catch ( Throwable $close_error ) { /* Never touch the WP handle. */ }
            }
            throw new RuntimeException( '保存専用DB接続の確立・設定・書き込み先確認に失敗しました。共有接続では保存しません。' );
        }
    }

    /** Fixed read-only SQL; no WordPress query/reconnect callbacks. */
    private static function snapshot( $handle ) {
        try {
            $result = @$handle->query( 'SELECT DATABASE() AS db_name, @@hostname AS server_host, @@port AS server_port, @@server_id AS server_id, CONNECTION_ID() AS connection_id, @@SESSION.character_set_connection AS charset, @@SESSION.collation_connection AS collation, @@SESSION.sql_mode AS sql_mode, @@read_only AS read_only' );
            if ( ! $result ) { throw new RuntimeException(); }
            try { $row = $result->fetch_assoc(); } finally { $result->free(); }
            $result = @$handle->query( "SHOW SESSION STATUS LIKE 'Ssl_cipher'" );
            if ( ! $result ) { throw new RuntimeException(); }
            try { $ssl = $result->fetch_assoc(); } finally { $result->free(); }
            if ( ! is_array( $row ) || ! is_array( $ssl ) || ! array_key_exists( 'Value', $ssl ) ) { throw new RuntimeException(); }
            $row['ssl_cipher'] = $ssl['Value'];
            return $row;
        } catch ( Throwable $error ) {
            throw new RuntimeException( 'DBの接続先・セッション情報を読み取れません。' );
        }
    }

    public static function verify_identity( $expected, $actual ) {
        foreach ( array( 'db_name', 'server_host', 'server_port', 'server_id', 'charset', 'collation', 'sql_mode', 'read_only', 'ssl_cipher' ) as $key ) {
            if ( ! isset( $expected[$key], $actual[$key] ) || (string) $expected[$key] !== (string) $actual[$key] ) {
                throw new RuntimeException( '保存専用接続のDB・サーバー・セッション設定が一致しません。' );
            }
        }
        if ( empty( $expected['connection_id'] ) || empty( $actual['connection_id'] ) || (string) $expected['connection_id'] === (string) $actual['connection_id'] ) {
            throw new RuntimeException( '独立したDBセッションを確認できません。' );
        }
    }

    /** Execute once; failures never reconnect or retry. */
    private function perform( $operation ) {
        $this->last_error = '';
        if ( $this->closed || $this->connection_lost ) { $this->last_error = 'connection_unavailable'; return false; }
        try {
            if ( $this->handle->thread_id !== $this->thread ) { throw new RuntimeException(); }
            $value = $operation();
            if ( $this->connection_lost || $this->handle->thread_id !== $this->thread ) { throw new RuntimeException(); }
            if ( $value === false ) {
                $this->last_error = 'database_operation_failed';
                if ( in_array( $this->handle->errno, array( 2006, 2013, 2055 ), true ) ) { $this->connection_lost = true; }
            }
            return $value;
        } catch ( Throwable $error ) {
            if ( ! ( $error instanceof mysqli_sql_exception ) || in_array( (int) $error->getCode(), array( 2006, 2013, 2055 ), true ) ) {
                $this->connection_lost = true;
            }
            $this->last_error = 'connection_or_driver_failure';
            return false;
        }
    }

    private function statement( $sql, $params, $read ) {
        return $this->perform( function () use ( $sql, $params, $read ) {
            $stmt = @$this->handle->prepare( $sql );
            if ( ! $stmt ) { return false; }
            try {
                if ( $params ) {
                    $types = '';
                    foreach ( $params as $value ) { $types .= is_int( $value ) ? 'i' : 's'; }
                    if ( ! @$stmt->bind_param( $types, ...$params ) ) { return false; }
                }
                if ( ! @$stmt->execute() ) { return false; }
                if ( ! $read ) { $this->insert_id = (int) $stmt->insert_id; return true; }
                if ( ! @$stmt->store_result() ) { return false; }
                $metadata = $stmt->result_metadata();
                if ( ! $metadata ) { return false; }
                try { $names = array_column( $metadata->fetch_fields(), 'name' ); } finally { $metadata->free(); }
                $values = array_fill( 0, count( $names ), null );
                if ( ! @$stmt->bind_result( ...$values ) ) { return false; }
                $rows = array();
                while ( true ) {
                    $fetched = @$stmt->fetch();
                    if ( $fetched === null ) { break; }
                    if ( $fetched === false ) { return false; }
                    $row = array();
                    foreach ( $values as $index => $value ) { $row[$names[$index]] = $value; }
                    $rows[] = $row;
                }
                return $rows;
            } finally {
                try { $stmt->close(); } catch ( Throwable $error ) { $this->connection_lost = true; }
            }
        } );
    }

    public function rows( $sql, $params = array() ) { return $this->statement( $sql, $params, true ); }
    public function write( $sql, $params = array() ) { return $this->statement( $sql, $params, false ); }
    public function begin() { return $this->perform( function () { return @$this->handle->begin_transaction(); } ); }
    public function commit() { return $this->perform( function () { return @$this->handle->commit(); } ); }
    public function rollback() { return $this->perform( function () { return @$this->handle->rollback(); } ); }

    /** Close only the owned non-persistent connection; do not mask a committed result. */
    public function close() {
        if ( $this->closed ) { return; }
        $this->closed = true;
        try {
            if ( @$this->handle->close() === false ) { throw new RuntimeException(); }
        } catch ( Throwable $error ) {
            error_log( 'e-Gov Square: dedicated connection close failed.' );
        } finally {
            $this->handle = null;
        }
    }
}
