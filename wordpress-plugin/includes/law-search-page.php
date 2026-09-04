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

    $rest_nonce = wp_create_nonce( 'wp_rest' );

    $watches = egov_law_monitor_get_watches();

    ob_start();
    ?>

    <div class="egov-law-search">

        <h2>法令ウォッチ</h2>

        <p class="egov-law-watch-last-checked">
            最終確認：<span id="egov-law-watch-last-checked">確認中…</span>
        </p>

        <?php if ( ! empty( $watches ) ) : ?>

            <h3>現在ウォッチ中のキーワード</h3>

            <div class="egov-law-watches">

                <?php foreach ( $watches as $watch ) : ?>

                    <article class="egov-law-watch">

                        <div class="egov-law-watch-header">
                            <p>
                                「<?php echo esc_html( $watch['keyword'] ); ?>」
                            </p>

                            <div class="egov-law-watch-actions">
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