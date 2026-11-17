<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_ondemand_sanitize_enabled( $value ) {
    if ( current_user_can( 'manage_options' ) && in_array( $value, array( '0', '1' ), true ) ) { return $value; }
    add_settings_error( 'egov_law_monitor_ondemand_enabled', 'invalid_ondemand', '設定値が不正か、変更権限がありません。' );
    $previous = get_option( 'egov_law_monitor_ondemand_enabled', '0' );
    return in_array( $previous, array( '0', '1' ), true ) ? $previous : '0';
}

add_action( 'admin_init', static function () {
    register_setting( 'egov_ondemand_settings', 'egov_law_monitor_ondemand_enabled', array( 'type' => 'string', 'sanitize_callback' => 'egov_ondemand_sanitize_enabled', 'default' => '0' ) );
} );

function egov_ondemand_render_settings() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    echo '<h2>オンデマンド機能の管理者検証</h2>';
    settings_errors( 'egov_law_monitor_ondemand_enabled' );
    echo '<form method="post" action="options.php">';
    settings_fields( 'egov_ondemand_settings' );
    echo '<input type="hidden" name="egov_law_monitor_ondemand_enabled" value="0">';
    echo '<label><input type="checkbox" name="egov_law_monitor_ondemand_enabled" value="1" ' . checked( egov_ondemand_enabled(), true, false ) . '> 管理者検証を有効にする</label>';
    echo '<p>初期値はOFFです。ONでもmanage_options権限を持つ管理者だけが利用できます。AI生成は行いません。</p>';
    submit_button( '管理者検証設定を保存' );
    echo '</form>';
}

function egov_ondemand_is_page() {
    if ( ! is_singular() ) { return false; }
    $post = get_queried_object();
    return is_page( 'ondemand-summary' ) || ( $post && isset( $post->post_content ) && has_shortcode( $post->post_content, 'egov_ondemand_summary' ) );
}

add_action( 'template_redirect', static function () {
    $post = is_singular() ? get_queried_object() : null;
    $card = $post && isset( $post->post_content ) && has_shortcode( $post->post_content, 'egov_ondemand_link' );
    if ( egov_ondemand_is_page() || $card ) { egov_ondemand_nocache(); }
    if ( egov_ondemand_is_page() ) {
        $allowed = egov_ondemand_access();
        if ( is_wp_error( $allowed ) ) { wp_die( esc_html( $allowed->get_error_message() ), 'オンデマンド機能', array( 'response' => 403 ) ); }
    }
}, 0 );

add_action( 'wp_enqueue_scripts', static function () {
    if ( ! egov_ondemand_is_page() || is_wp_error( egov_ondemand_access() ) ) { return; }
    $root = dirname( __DIR__ );
    $url = plugin_dir_url( $root . '/egov-law-monitor.php' );
    wp_enqueue_style( 'egov-ondemand', $url . 'assets/css/ondemand.css', array(), filemtime( $root . '/assets/css/ondemand.css' ) );
    wp_enqueue_script( 'egov-ondemand', $url . 'assets/js/ondemand.js', array(), filemtime( $root . '/assets/js/ondemand.js' ), true );
    wp_localize_script( 'egov-ondemand', 'egovOndemand', array( 'api' => rest_url( 'egov-law-monitor/v1/ondemand/' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
} );

function egov_ondemand_link_shortcode() {
    if ( is_wp_error( egov_ondemand_access() ) ) { return ''; }
    egov_ondemand_nocache();
    $page = get_page_by_path( 'ondemand-summary' );
    if ( ! $page || get_post_status( $page ) !== 'publish' ) { return ''; }
    return '<p class="egov-ondemand-card"><a href="' . esc_url( get_permalink( $page ) ) . '">オンデマンドAI要約〈管理者検証〉</a><br>公式法令検索・改正版の選択を確認できます。AI生成は行いません。</p>';
}
add_shortcode( 'egov_ondemand_link', 'egov_ondemand_link_shortcode' );

function egov_ondemand_page_shortcode() {
    $allowed = egov_ondemand_access();
    if ( is_wp_error( $allowed ) ) { return '<p>' . esc_html( $allowed->get_error_message() ) . '</p>'; }
    egov_ondemand_nocache();
    ob_start(); ?>
    <section class="egov-ondemand" data-egov-ondemand>
        <h2>オンデマンドAI要約〈管理者検証〉</h2>
        <p>工程A：法令検索・改正版の選択のみです。AI要約・背景情報の生成、通常AIの履歴・回数への記録は行いません。</p>
        <form data-search>
            <label>検索方法 <select name="mode"><option value="title">法令名・略称</option><option value="number">法令番号</option></select></label>
            <label>検索語 <input name="query" type="search" required maxlength="200"></label>
            <button type="submit">法令を検索</button>
        </form>
        <p role="status" aria-live="polite" data-status></p>
        <div data-laws></div>
        <p><button type="button" data-law-prev hidden>検索結果の前ページ</button> <button type="button" data-law-next hidden>検索結果の次ページ</button></p>
        <h3>対象法令・改正履歴</h3>
        <p data-law></p><div data-revisions></div>
        <p><button type="button" data-rev-prev hidden>履歴の前ページ</button> <button type="button" data-rev-next hidden>履歴の次ページ</button></p>
        <h3>選択した改正版</h3>
        <div data-selected>改正履歴から1件選択してください。</div>
        <p>内部版の一致はメタデータ上の一意候補です。本文の同一性は未確認であり、工程Bの生成時にはCloud Run側で改正版を再検証する必要があります。</p>
        <noscript><p>この検証画面はJavaScriptを有効にして操作してください。</p></noscript>
    </section>
    <?php return ob_get_clean();
}
add_shortcode( 'egov_ondemand_summary', 'egov_ondemand_page_shortcode' );
