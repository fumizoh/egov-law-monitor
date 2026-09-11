<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 法令ウォッチ管理メニュー
 */
function egov_law_monitor_admin_menu() {
    add_menu_page(
        '法令ウォッチ',
        '法令ウォッチ',
        'manage_options',
        'egov-law-monitor',
        'egov_law_monitor_render_admin_users',
        'dashicons-visibility',
        30
    );

    add_submenu_page(
        'egov-law-monitor',
        'ユーザー管理',
        'ユーザー管理',
        'manage_options',
        'egov-law-monitor',
        'egov_law_monitor_render_admin_users'
    );
}

add_action( 'admin_menu', 'egov_law_monitor_admin_menu' );