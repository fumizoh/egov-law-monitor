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

    $search_text = isset( $_GET['law_search'] )
        ? sanitize_text_field( wp_unslash( $_GET['law_search'] ) )
        : '';

    $results = [];

    if ( $search_text !== '' ) {
        $results = egov_law_monitor_search_laws( $search_text );
    }

    $rest_nonce = wp_create_nonce( 'wp_rest' );

    $watches = egov_law_monitor_get_watches();

    ob_start();
    ?>

    <div class="egov-law-search">

        <?php if ( ! empty( $watches ) ) : ?>

            <h2>現在ウォッチ中の法令</h2>

            <div class="egov-law-watch-status">
                <p>
                    現在 <?php echo count( $watches ); ?> 件の法令を監視中
                </p>
                <p>
                    最終確認：<span id="egov-law-watch-last-checked">確認中…</span>
                </p>
            </div>

            <div class="egov-law-watches">

                <?php foreach ( $watches as $watch ) : ?>

                    <article class="egov-law-watch">
                        <div class="egov-law-watch-header">
                            <h3>
                                <?php echo esc_html( $watch['law_name'] ); ?>
                            </h3>

                            <button
                                type="button"
                                class="egov-law-unwatch-button"
                                data-law-id="<?php echo esc_attr( $watch['law_id'] ); ?>"
                                data-rest-url="<?php echo esc_url( rest_url( 'egov-law-monitor/v1/watches/' . $watch['law_id'] ) ); ?>"
                                data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
                            >
                                ウォッチ解除
                            </button>
                        </div>

                        <?php if ( $watch['law_no'] !== '' ) : ?>

                            <p>
                                <?php echo esc_html( $watch['law_no'] ); ?>
                            </p>

                        <?php endif; ?>

                        <?php if ( $watch['law_type'] !== '' ) : ?>

                            <p>
                                法令種別：
                                <?php echo esc_html( $watch['law_type'] ); ?>
                            </p>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <h2>法令を検索</h2>

        <form method="get">
            <div class="egov-law-search-form">

                <label for="egov-law-search-input">
                    法令名・キーワード
                </label>

                <input
                    type="search"
                    id="egov-law-search-input"
                    name="law_search"
                    value="<?php echo esc_attr( $search_text ); ?>"
                >

                <button type="submit">
                    検索
                </button>

            </div>
        </form>

        <?php if ( $search_text !== '' ) : ?>

            <h3>
                「<?php echo esc_html( $search_text ); ?>」の検索結果
            </h3>

            <?php if ( is_wp_error( $results ) ) : ?>

                <p>
                    法令検索でエラーが発生しました。
                </p>

            <?php elseif ( empty( $results ) ) : ?>

                <p>
                    該当する法令が見つかりませんでした。
                </p>

            <?php else : ?>

                <p>
                    <?php echo count( $results ); ?>件
                </p>

                <div class="egov-law-search-results">

                    <?php
                    $watched_law_ids = array_map(
                        static function ( $watch ) {
                            return $watch['law_id'];
                        },
                        $watches
                    );
                    ?>

                    <?php foreach ( $results as $law ) : ?>

                        <article class="egov-law-search-result">
                            <div class="egov-law-search-result-header">
                                <h4>
                                    <?php echo esc_html( $law['law_name'] ); ?>
                                </h4>

                                <?php if ( in_array( $law['law_id'], $watched_law_ids, true ) ) : ?>

                                    <button
                                        type="button"
                                        disabled
                                    >
                                        ウォッチ中
                                    </button>

                                <?php else : ?>

                                    <button
                                        type="button"
                                        class="egov-law-watch-button"
                                        data-law-id="<?php echo esc_attr( $law['law_id'] ); ?>"
                                        data-law-name="<?php echo esc_attr( $law['law_name'] ); ?>"
                                        data-law-no="<?php echo esc_attr( $law['law_no'] ); ?>"
                                        data-law-type="<?php echo esc_attr( $law['law_type'] ); ?>"
                                        data-rest-url="<?php echo esc_url( rest_url( 'egov-law-monitor/v1/watches' ) ); ?>"
                                        data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
                                    >
                                        ウォッチする
                                    </button>

                                <?php endif; ?>
                            </div>

                            <p>
                                <?php echo esc_html( $law['law_no'] ); ?>
                            </p>

                            <p>
                                法令種別：
                                <?php echo esc_html( $law['law_type'] ); ?>
                            </p>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </div>

    <?php

    return ob_get_clean();
}


add_shortcode(
    'egov_law_search',
    'egov_law_monitor_render_search_page'
);