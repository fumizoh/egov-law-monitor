<?php

define( 'DB_NAME', 'egov_law_monitor_test' );
define( 'DB_USER', 'egov_test' );
define( 'DB_PASSWORD', 'egov_test_password' );
define( 'DB_HOST', 'wp-test-db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'e-Gov Law Monitor Test Site' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'ABSPATH', '/opt/wordpress/' );

// Only the WordPress test site's seeded administrator may exercise Square Checkout.
define( 'EGOV_LAW_MONITOR_SQUARE_TEST_USER_IDS', array( 1 ) );
