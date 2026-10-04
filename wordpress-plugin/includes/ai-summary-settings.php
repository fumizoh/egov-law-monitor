<?php
/**
 * e-Gov Law Monitor - AI Summary Settings
 */

/**
 * Add AI Summary settings page.
 */
add_action(
    'admin_menu',
    function () {
        add_options_page(
            'e-Gov Law Monitor AI設定',
            'e-Gov Law Monitor AI',
            'manage_options',
            'egov-law-monitor-ai-settings',
            'egov_law_monitor_ai_settings_page'
        );
    }
);

/**
 * Register settings.
 */
add_action(
    'admin_init',
    function () {
        register_setting(
            'egov_law_monitor_ai_settings_group',
            'egov_law_monitor_gcp_project_id',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        register_setting(
            'egov_law_monitor_ai_settings_group',
            'egov_law_monitor_gcp_client_email',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_email',
                'default'           => '',
            ]
        );
    }
);

/**
 * Save encrypted Private Key.
 */
add_action(
    'admin_post_egov_law_monitor_save_ai_private_key',
    function () {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません。' );
        }

        check_admin_referer(
            'egov_law_monitor_save_ai_private_key'
        );

        $private_key = isset( $_POST['egov_law_monitor_gcp_private_key'] )
            ? trim( wp_unslash( $_POST['egov_law_monitor_gcp_private_key'] ) )
            : '';

        // 空欄の場合は既存のPrivate Keyを維持する。
        if ( $private_key !== '' ) {
            $encrypted = egov_law_monitor_encrypt_secret(
                $private_key
            );

            if ( $encrypted === '' ) {
                wp_die(
                    'Private Keyの暗号化に失敗しました。'
                );
            }

            update_option(
                'egov_law_monitor_gcp_private_key',
                $encrypted,
                false
            );
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'    => 'egov-law-monitor-ai-settings',
                    'updated' => '1',
                ],
                admin_url( 'options-general.php' )
            )
        );
        exit;
    }
);

/**
 * Test Cloud Run connection.
 */
add_action(
    'admin_post_egov_law_monitor_test_ai_cloud_run',
    function () {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません。' );
        }

        check_admin_referer(
            'egov_law_monitor_test_ai_cloud_run'
        );

        $result = egov_law_monitor_test_cloud_run_connection();

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'    => 'egov-law-monitor-ai-settings',
                        'ai_test' => 'error',
                        'message' => rawurlencode(
                            $result->get_error_message()
                        ),
                    ],
                    admin_url( 'options-general.php' )
                )
            );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'    => 'egov-law-monitor-ai-settings',
                    'ai_test' => 'success',
                ],
                admin_url( 'options-general.php' )
            )
        );
        exit;
    }
);

/**
 * AI Summary settings page.
 */
function egov_law_monitor_ai_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $project_id = get_option(
        'egov_law_monitor_gcp_project_id',
        ''
    );

    $client_email = get_option(
        'egov_law_monitor_gcp_client_email',
        ''
    );

    $private_key = get_option(
        'egov_law_monitor_gcp_private_key',
        ''
    );
    ?>
    <div class="wrap">
        <?php if ( isset( $_GET['ai_test'] ) ) : ?>

            <?php if ( $_GET['ai_test'] === 'success' ) : ?>

                <div class="notice notice-success is-dismissible">
                    <p>
                        Cloud Runへの接続に成功しました。
                    </p>
                </div>

            <?php elseif ( $_GET['ai_test'] === 'error' ) : ?>

                <div class="notice notice-error is-dismissible">
                    <p>
                        Cloud Runへの接続に失敗しました。
                    </p>

                    <?php if ( ! empty( $_GET['message'] ) ) : ?>
                        <p>
                            <?php
                            echo esc_html(
                                wp_unslash( $_GET['message'] )
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                </div>

            <?php endif; ?>

        <?php endif; ?>

        <h1>e-Gov Law Monitor AI設定</h1>

        <h2>Google Cloud設定</h2>

        <form method="post" action="options.php">
            <?php
            settings_fields(
                'egov_law_monitor_ai_settings_group'
            );
            ?>

            <table class="form-table">

                <tr>
                    <th scope="row">
                        <label for="egov_law_monitor_gcp_project_id">
                            Google Cloud Project ID
                        </label>
                    </th>

                    <td>
                        <input
                            type="text"
                            id="egov_law_monitor_gcp_project_id"
                            name="egov_law_monitor_gcp_project_id"
                            value="<?php echo esc_attr( $project_id ); ?>"
                            class="regular-text"
                        >
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="egov_law_monitor_gcp_client_email">
                            Service Account Email
                        </label>
                    </th>

                    <td>
                        <input
                            type="email"
                            id="egov_law_monitor_gcp_client_email"
                            name="egov_law_monitor_gcp_client_email"
                            value="<?php echo esc_attr( $client_email ); ?>"
                            class="regular-text"
                        >
                    </td>
                </tr>

            </table>

            <?php submit_button( 'Google Cloud設定を保存' ); ?>
        </form>

        <hr>

        <h2>Private Key</h2>

        <form
            method="post"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
        >
            <input
                type="hidden"
                name="action"
                value="egov_law_monitor_save_ai_private_key"
            >

            <?php
            wp_nonce_field(
                'egov_law_monitor_save_ai_private_key'
            );
            ?>

            <table class="form-table">

                <tr>
                    <th scope="row">
                        <label for="egov_law_monitor_gcp_private_key">
                            Private Key
                        </label>
                    </th>

                    <td>
                        <textarea
                            id="egov_law_monitor_gcp_private_key"
                            name="egov_law_monitor_gcp_private_key"
                            rows="8"
                            class="large-text code"
                        ></textarea>

                        <?php if ( $private_key !== '' ) : ?>
                            <p class="description">
                                Private Keyは登録済みです。
                                変更する場合のみ新しいPrivate Keyを入力してください。
                            </p>
                        <?php else : ?>
                            <p class="description">
                                Google CloudのサービスアカウントPrivate Keyを入力してください。
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

            </table>

            <?php submit_button( 'Private Keyを保存' ); ?>
        </form>

        <hr>

        <h2>Cloud Run接続テスト</h2>

        <form
            method="post"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
        >
            <input
                type="hidden"
                name="action"
                value="egov_law_monitor_test_ai_cloud_run"
            >

            <?php
            wp_nonce_field(
                'egov_law_monitor_test_ai_cloud_run'
            );
            ?>

            <p>
                Cloud Runへの認証付き接続をテストします。
                AIサマリー生成は実行しません。
            </p>

            <?php
            submit_button(
                'Cloud Run接続テスト',
                'secondary'
            );
            ?>
        </form>

    </div>
    <?php
}