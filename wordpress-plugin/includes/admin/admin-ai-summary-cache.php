
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI要約キャッシュ管理画面
 */
function egov_law_monitor_render_admin_ai_summary_cache() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'このページにアクセスする権限がありません。' );
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'egov_law_ai_summaries';

    $search = isset( $_GET['cache_search'] )
        ? sanitize_text_field(
            wp_unslash( $_GET['cache_search'] )
        )
        : '';

    $page = isset( $_GET['cache_page'] )
        ? max( 1, absint( $_GET['cache_page'] ) )
        : 1;

    $per_page = 50;
    $offset   = ( $page - 1 ) * $per_page;

    $where  = 'WHERE 1 = 1';
    $values = array();

    if ( $search !== '' ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';

        $where .= ' AND (law_id LIKE %s OR amendment_name LIKE %s)';
        $values[] = $like;
        $values[] = $like;
    }

    // キャッシュ総件数
    $count_sql = "SELECT COUNT(*) FROM {$table_name} {$where}";

    if ( ! empty( $values ) ) {
        $count_sql = $wpdb->prepare( $count_sql, $values );
    }

    $total = (int) $wpdb->get_var( $count_sql );

    // キャッシュ一覧
    $list_sql = "
        SELECT
            id,
            law_id,
            effective_date,
            law_data_id,
            sub_revision,
            amendment_name,
            comparison_effective_date,
            revision_hash,
            created_at,
            updated_at
        FROM {$table_name}
        {$where}
        ORDER BY updated_at DESC, id DESC
        LIMIT %d OFFSET %d
    ";

    $list_values   = $values;
    $list_values[] = $per_page;
    $list_values[] = $offset;

    $items = $wpdb->get_results(
        $wpdb->prepare( $list_sql, $list_values ),
        ARRAY_A
    );

    $total_pages = (int) ceil( $total / $per_page );

    ?>
    <div class="wrap">
        <h1>AI要約キャッシュ</h1>

        <p>
            キャッシュ総件数：
            <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
        </p>

        <form method="get">
            <input
                type="hidden"
                name="page"
                value="egov-law-monitor-ai-summary-cache"
            >

            <p class="search-box">
                <label class="screen-reader-text" for="cache-search">
                    法令ID・改正内容で検索
                </label>

                <input
                    type="search"
                    id="cache-search"
                    name="cache_search"
                    value="<?php echo esc_attr( $search ); ?>"
                    placeholder="法令ID・改正内容"
                >

                <input
                    type="submit"
                    class="button"
                    value="検索"
                >
            </p>
        </form>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>法令ID</th>
                    <th>施行日</th>
                    <th>Law Data ID</th>
                    <th>Sub Revision</th>
                    <th>改正法令名</th>
                    <th>比較対象の施行日</th>
                    <th>リビジョンハッシュ</th>
                    <th>作成日時</th>
                    <th>更新日時</th>
                </tr>
            </thead>

            <tbody>
                <?php if ( empty( $items ) ) : ?>
                    <tr>
                        <td colspan="9">該当するキャッシュはありません。</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $items as $item ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $item['law_id'] ); ?>
                            </td>
                            <td>
                                <?php echo esc_html( $item['effective_date'] ); ?>
                            </td>
                            <td>
                                <?php echo esc_html( $item['law_data_id'] ); ?>
                            </td>
                            <td>
                                <?php echo esc_html( $item['sub_revision'] ); ?>
                            </td>
                            <td>
                                <?php
                                echo esc_html(
                                    wp_html_excerpt(
                                        $item['amendment_name'],
                                        100,
                                        '…'
                                    )
                                );
                                ?>
                            </td>
                            <td>
                                <?php
                                echo esc_html(
                                    $item['comparison_effective_date']
                                );
                                ?>
                            </td>
                            <td>
                                <code title="<?php echo esc_attr( $item['revision_hash'] ); ?>">
                                    <?php
                                    echo esc_html(
                                        substr( $item['revision_hash'], 0, 12 )
                                    );
                                    ?>…
                                </code>
                            </td>
                            <td>
                                <?php echo esc_html( $item['created_at'] ); ?>
                            </td>
                            <td>
                                <?php echo esc_html( $item['updated_at'] ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    $base_url = add_query_arg(
                        array(
                            'page'         => 'egov-law-monitor-ai-summary-cache',
                            'cache_search' => $search,
                            'cache_page'   => '%#%',
                        ),
                        admin_url( 'admin.php' )
                    );

                    echo wp_kses_post(
                        paginate_links(
                            array(
                                'base'      => $base_url,
                                'format'    => '',
                                'current'   => $page,
                                'total'     => $total_pages,
                                'prev_text' => '« 前へ',
                                'next_text' => '次へ »',
                            )
                        )
                    );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
