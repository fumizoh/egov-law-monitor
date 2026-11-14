<?php
/**
 * e-Gov Law Monitor - Law Search Page
 */

/**
 * Render law search page.
 *
 * @return string
 */
function egov_law_monitor_render_search_page() {

    if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
    if ( ! headers_sent() ) { nocache_headers(); }

    $rest_nonce = wp_create_nonce( 'wp_rest' );
    $notifications = egov_law_monitor_get_notifications( get_current_user_id() );

    $watches = egov_law_monitor_get_watches();
    $active_watch_count = count( array_filter(
        $watches,
        static function ( $watch ) {
            return ! empty( $watch['is_active'] );
        }
    ) );
    $stopped_watch_count = count( $watches ) - $active_watch_count;
    $watch_limit = egov_law_monitor_get_watch_limit( get_current_user_id() );

    ob_start();
    ?>

    <div class="egov-law-search">

        <h2>🔭 法令ウォッチ</h2>

        <?php if ( get_current_user_id() > 0 ) : ?>
        <div class="egov-watch-mail-setting">
            <button type="button" class="egov-watch-mail-toggle"
                aria-pressed="<?php echo $notifications ? 'true' : 'false'; ?>"
                data-rest-url="<?php echo esc_url( rest_url( 'egov-law-monitor/v1/watch-notifications' ) ); ?>"
                data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>">
                法令ウォッチのメール通知　<span><?php echo $notifications ? 'ON' : 'OFF'; ?></span>
            </button>
            <p>登録したキーワードに関連する法令の更新をメールでお知らせします。</p>
            <p class="egov-watch-mail-off" <?php echo $notifications ? 'hidden' : ''; ?>>メール通知は停止中です。登録済みキーワードは保持されます。</p>
            <p class="egov-watch-mail-result" role="status" aria-live="polite"></p>
            <noscript><p>メール通知の切替にはJavaScriptを有効にしてください。</p></noscript>
        </div>
        <?php endif; ?>

        <p class="egov-law-watch-notice">
            メール通知が有効な場合、有効なキーワードに一致する法令の更新をメールでお知らせします。<br>
            停止中のキーワードは通知対象外です。
        </p>

        <p class="egov-law-watch-last-checked">
            法令更新の最終確認：<span id="egov-law-watch-last-checked"><?php echo esc_html( egov_law_monitor_last_checked_label() ); ?></span>
        </p>

        <?php if ( ! empty( $watches ) ) : ?>

            <h3>登録済みキーワード</h3>
            <p class="egov-law-watch-count">
                有効：<strong><?php echo esc_html( $active_watch_count ); ?> / <?php echo esc_html( $watch_limit ); ?>件</strong>
                （停止中：<?php echo esc_html( $stopped_watch_count ); ?>件）
            </p>

            <div class="egov-law-watches">

                <?php foreach ( $watches as $watch ) : ?>

                    <article class="egov-law-watch">

                        <div class="egov-law-watch-header">
                            <div>
                                <p>
                                    「<?php echo esc_html( $watch['keyword'] ); ?>」
                                </p>
                                <p class="egov-law-watch-status">
                                    <?php if ( ! empty( $watch['is_active'] ) ) : ?>
                                        <span>有効</span>
                                    <?php else : ?>
                                        <span>停止中</span>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="egov-law-watch-actions">
                                <?php if ( empty( $watch['is_active'] ) && $active_watch_count < $watch_limit ) : ?>
                                    <button
                                        type="button"
                                        class="egov-law-resume-button"
                                        data-keyword="<?php echo esc_attr( $watch['keyword'] ); ?>"
                                        data-rest-url="<?php echo esc_url( rest_url( 'egov-law-monitor/v1/watches/' . rawurlencode( $watch['keyword'] ) . '/resume' ) ); ?>"
                                        data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
                                    >
                                        再開
                                    </button>
                                <?php endif; ?>

                                <button
                                    type="button"
                                    class="egov-law-unwatch-button"
                                    data-keyword="<?php echo esc_attr( $watch['keyword'] ); ?>"
                                    data-rest-url="<?php echo esc_url( rest_url( 'egov-law-monitor/v1/watches/' . rawurlencode( $watch['keyword'] ) ) ); ?>"
                                    data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
                                >
                                    ウォッチ解除
                                </button>

                                <button
                                    type="button"
                                    class="egov-law-watch-targets-button"
                                    data-keyword="<?php echo esc_attr( $watch['keyword'] ); ?>"
                                >
                                    対象法令を確認
                                </button>
                            </div>
                        </div>

                        <div
                            class="egov-law-watch-targets"
                            data-keyword="<?php echo esc_attr( $watch['keyword'] ); ?>"
                            hidden
                        >
                            <h4></h4>
                            <ul></ul>
                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <div class="egov-law-watch-form">

            <h3>法令名キーワードを設定</h3>

            <form id="egov-law-watch-form">

                <label for="egov-law-watch-input">
                    法令名キーワード
                </label>

                <p class="egov-law-watch-description">
                    <span class="egov-law-watch-note">※</span>
                    1文字以上のキーワードを1個入力してください。
                </p>

                <div class="egov-law-search-form">

                    <input
                        type="text"
                        id="egov-law-watch-input"
                        name="keyword"
                        required
                    >

                    <button type="submit">
                        このキーワードを設定
                    </button>

                </div>

            </form>

        </div>

    </div>

    <?php

    return ob_get_clean();
}


add_shortcode(
    'egov_law_search',
    'egov_law_monitor_render_search_page'
);
