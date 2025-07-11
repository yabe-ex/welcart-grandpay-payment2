<?php

class WelcartGrandpayPaymentAdmin {

    public function __construct() {
        error_log('GrandPay Admin: Constructor called');

        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue'));
        add_action('admin_menu', array($this, 'create_menu'));
        add_filter('plugin_action_links_' . plugin_basename(WELCART_GRANDPAY_PAYMENT_PATH . '/welcart-grandpay-payment.php'), array($this, 'plugin_action_links'));
        add_action('admin_notices', array($this, 'show_installation_guide'));
        add_action('admin_init', array($this, 'ensure_settlement_module_registration'), 20);

        // AJAX処理
        add_action('wp_ajax_grandpay_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_grandpay_test_checkout', array($this, 'ajax_test_checkout'));

        error_log('GrandPay Admin: Constructor completed');
    }

    /**
     * 決済モジュールが正しく登録されているかを確認・修正
     */
    public function ensure_settlement_module_registration() {
        if (!function_exists('usces_get_system_option')) {
            error_log('GrandPay Admin: Welcart not available for settlement module registration');
            return;
        }

        $settlement_file = WP_PLUGIN_DIR . '/usc-e-shop/settlement/grandpay.php';
        if (!file_exists($settlement_file)) {
            error_log('GrandPay Admin: Settlement module file not found: ' . $settlement_file);
            return;
        }

        $available_settlement = get_option('usces_available_settlement', array());

        if (!isset($available_settlement['grandpay'])) {
            $available_settlement['grandpay'] = 'GrandPay';
            update_option('usces_available_settlement', $available_settlement);
            error_log('GrandPay Admin: Added to available settlement modules');
        }

        if (file_exists($settlement_file)) {
            require_once($settlement_file);

            if (function_exists('usces_get_settlement_info_grandpay')) {
                $info = usces_get_settlement_info_grandpay();
                error_log('GrandPay Admin: Settlement module info: ' . print_r($info, true));
            } else {
                error_log('GrandPay Admin: usces_get_settlement_info_grandpay function not found in module file');
            }
        }

        error_log('GrandPay Admin: Settlement module registration check completed');
    }

    public function show_installation_guide() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'usces_settlement') === false) {
            return;
        }

        $settlement_file = WP_PLUGIN_DIR . '/usc-e-shop/settlement/grandpay.php';

        if (!file_exists($settlement_file)) {
?>
            <div class="notice notice-warning">
                <h4>📋 GrandPay決済モジュールの設定手順</h4>
                <ol>
                    <li><strong>決済モジュールファイルを配置</strong><br>
                        <code><?php echo WELCART_GRANDPAY_PAYMENT_PATH; ?>/settlement/grandpay.php</code><br>
                        ↓ コピー ↓<br>
                        <code><?php echo WP_PLUGIN_DIR; ?>/usc-e-shop/settlement/grandpay.php</code>
                    </li>
                    <li><strong>利用できるモジュールリストに追加</strong><br>
                        このページの「利用できるクレジット決済モジュール」に「GrandPay」が表示されるので、
                        「利用中のクレジット決済モジュール」にドラッグ&ドロップで移動
                    </li>
                    <li><strong>「利用するモジュールを更新する」をクリック</strong></li>
                    <li><strong>「GrandPay」タブで詳細設定を行う</strong></li>
                </ol>
            </div>
            <?php
        } else {
            $available_settlement = get_option('usces_available_settlement', array());

            if (!isset($available_settlement['grandpay'])) {
            ?>
                <div class="notice notice-info">
                    <h4>🔄 GrandPay決済モジュール 自動登録中</h4>
                    <p>決済モジュールファイルは配置済みです。利用可能モジュールリストに自動登録しています...</p>
                    <p>ページをリロードしてください。</p>
                </div>
                <?php
            } else {
                $selected_settlement = get_option('usces_settlement_selected', array());
                $is_selected = false;

                if (is_array($selected_settlement)) {
                    $is_selected = in_array('grandpay', $selected_settlement);
                } elseif (is_string($selected_settlement)) {
                    $is_selected = strpos($selected_settlement, 'grandpay') !== false;
                }

                if (!$is_selected) {
                ?>
                    <div class="notice notice-info">
                        <h4>✅ GrandPay決済モジュール設定可能</h4>
                        <p><strong>手順:</strong></p>
                        <ol>
                            <li>下記の「利用できるクレジット決済モジュール」から「<strong>GrandPay</strong>」を見つける</li>
                            <li>「<strong>GrandPay</strong>」を「利用中のクレジット決済モジュール」エリアにドラッグ&ドロップ</li>
                            <li>「<strong>利用するモジュールを更新する</strong>」ボタンをクリック</li>
                            <li>「<strong>GrandPay</strong>」タブが表示されるので、そこで詳細設定</li>
                        </ol>
                    </div>
                <?php
                } else {
                ?>
                    <div class="notice notice-success">
                        <h4>🎉 GrandPay決済モジュール利用準備完了</h4>
                        <p>「<strong>GrandPay</strong>」タブで詳細設定を行ってください。</p>
                    </div>
        <?php
                }
            }
        }
    }

    /**
     * プラグイン独自の管理メニュー（デバッグ用）
     */
    function create_menu() {
        add_submenu_page(
            'options-general.php',
            WELCART_GRANDPAY_PAYMENT_NAME . ' - テスト＆診断',
            WELCART_GRANDPAY_PAYMENT_NAME,
            'manage_options',
            'welcart-grandpay-payment',
            array($this, 'show_setting_page'),
            1
        );
    }

    function admin_enqueue($hook) {
        if (strpos($hook, 'usces_settlement') === false && strpos($hook, 'welcart-grandpay-payment') === false) {
            return;
        }

        $version = (defined('WELCART_GRANDPAY_PAYMENT_DEVELOP') && true === WELCART_GRANDPAY_PAYMENT_DEVELOP) ? time() : WELCART_GRANDPAY_PAYMENT_VERSION;

        wp_register_style(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin',  WELCART_GRANDPAY_PAYMENT_URL . '/css/admin.css', array(), $version);
        wp_register_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin', WELCART_GRANDPAY_PAYMENT_URL . '/js/admin.js', array('jquery'), $version);

        wp_enqueue_style(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin');
        wp_enqueue_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin');

        $selected_settlements = get_option('usces_settlement_selected', array());
        $is_grandpay_selected = false;

        if (is_array($selected_settlements)) {
            $is_grandpay_selected = in_array('grandpay', $selected_settlements);
        } elseif (is_string($selected_settlements)) {
            $is_grandpay_selected = strpos($selected_settlements, 'grandpay') !== false;
        }

        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();

        $admin_data = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(WELCART_GRANDPAY_PAYMENT_SLUG),
            'is_selected' => $is_grandpay_selected,
            'settings' => $grandpay_settings,
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        );

        wp_localize_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin', 'grandpay_admin', $admin_data);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay Admin: Scripts enqueued');
        }
    }

    function plugin_action_links($links) {
        $url = '<a href="' . esc_url(admin_url("/options-general.php?page=welcart-grandpay-payment")) . '">テスト＆診断</a>';
        array_unshift($links, $url);
        return $links;
    }

    /**
     * AJAX: API接続テスト
     */
    public function ajax_test_api() {
        check_ajax_referer(WELCART_GRANDPAY_PAYMENT_SLUG, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
        }

        $api = new WelcartGrandpayAPI();
        $result = $api->test_connection();

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['error']));
        }
    }

    /**
     * AJAX: チェックアウトテスト
     */
    public function ajax_test_checkout() {
        check_ajax_referer(WELCART_GRANDPAY_PAYMENT_SLUG, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
        }

        $api = new WelcartGrandpayAPI();

        // テスト用注文データ
        $test_order_data = array(
            'order_id' => 'TEST_' . time(),
            'amount' => 1000,
            'name' => 'Test Customer',
            'email' => 'test@example.com',
            'phone' => '09012345678',
            'success_url' => home_url('/checkout/success/'),
            'failure_url' => home_url('/checkout/failure/')
        );

        $result = $api->create_checkout_session($test_order_data);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'チェックアウトセッション作成成功',
                'session_id' => $result['session_id'],
                'checkout_url' => $result['checkout_url']
            ));
        } else {
            wp_send_json_error(array('message' => $result['error']));
        }
    }

    function show_setting_page() {
        $api = new WelcartGrandpayAPI();
        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();

        // 各種テスト処理
        $test_results = array();

        if (isset($_POST['test_log'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_log')) {
                error_log('GrandPay: Log test from admin page - ' . current_time('Y-m-d H:i:s'));
                $test_results['log'] = '<div class="notice notice-info"><p>📝 ログテストを実行しました。/wp-content/debug.log を確認してください。</p></div>';
            }
        }

        if (isset($_POST['test_connection'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_connection')) {
                $connection_result = $api->test_connection();
                if ($connection_result['success']) {
                    $test_results['connection'] = '<div class="notice notice-success"><p>✓ API接続テスト成功 - ' . $connection_result['message'] . '</p></div>';
                } else {
                    $test_results['connection'] = '<div class="notice notice-error"><p>✗ API接続テスト失敗 - ' . $connection_result['error'] . '</p></div>';
                }
            }
        }

        if (isset($_POST['test_checkout'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_checkout')) {
                $checkout_result = $this->run_checkout_test($api);
                $test_results['checkout'] = $checkout_result;
            }
        }

        if (isset($_POST['test_detailed_api'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_detailed_api')) {
                $detailed_result = $this->run_detailed_api_test($api);
                $test_results['detailed'] = $detailed_result;
            }
        }

        if (isset($_POST['test_webhook'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_webhook')) {
                $webhook_result = $this->test_webhook_endpoint();
                $test_results['webhook'] = $webhook_result;
            }
        }

        // システム状況確認
        $settlement_file = WP_PLUGIN_DIR . '/usc-e-shop/settlement/grandpay.php';
        $settlement_file_exists = file_exists($settlement_file);

        $available_settlement = get_option('usces_available_settlement', array());
        $is_available = isset($available_settlement['grandpay']);

        $selected_settlement = get_option('usces_settlement_selected', array());
        $is_selected = false;
        if (is_array($selected_settlement)) {
            $is_selected = in_array('grandpay', $selected_settlement);
        } elseif (is_string($selected_settlement)) {
            $is_selected = strpos($selected_settlement, 'grandpay') !== false;
        }

        $webhook_url = rest_url('grandpay/v1/webhook');

        ?>
        <div class="wrap">
            <h1><?php echo WELCART_GRANDPAY_PAYMENT_NAME; ?> - テスト＆診断</h1>

            <?php foreach ($test_results as $result): ?>
                <?php echo $result; ?>
            <?php endforeach; ?>

            <div class="grandpay-debug-page">
                <div class="card">
                    <h2>🔍 システム状況</h2>
                    <table class="form-table">
                        <tr>
                            <th>WordPress Debug</th>
                            <td><span class="grandpay-status-<?php echo defined('WP_DEBUG') && WP_DEBUG ? 'success' : 'warning'; ?>">
                                    <?php echo defined('WP_DEBUG') && WP_DEBUG ? '✓ 有効' : '⚠️ 無効'; ?>
                                </span></td>
                        </tr>
                        <tr>
                            <th>Debug Log</th>
                            <td><span class="grandpay-status-<?php echo defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'success' : 'warning'; ?>">
                                    <?php echo defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? '✓ 有効' : '⚠️ 無効'; ?>
                                </span></td>
                        </tr>
                        <tr>
                            <th>決済モジュールファイル</th>
                            <td><span class="grandpay-status-<?php echo $settlement_file_exists ? 'success' : 'error'; ?>">
                                    <?php echo $settlement_file_exists ? '✓ 存在' : '✗ 未作成'; ?>
                                </span></td>
                        </tr>
                        <tr>
                            <th>利用可能モジュールリスト</th>
                            <td><span class="grandpay-status-<?php echo $is_available ? 'success' : 'error'; ?>">
                                    <?php echo $is_available ? '✓ 登録済み' : '✗ 未登録'; ?>
                                </span></td>
                        </tr>
                        <tr>
                            <th>利用中モジュールリスト</th>
                            <td><span class="grandpay-status-<?php echo $is_selected ? 'success' : 'warning'; ?>">
                                    <?php echo $is_selected ? '✓ 選択済み' : '⚠️ 未選択'; ?>
                                </span></td>
                        </tr>
                    </table>
                </div>

                <div class="card">
                    <h2>⚙️ GrandPay設定状況</h2>
                    <table class="form-table">
                        <tr>
                            <th>有効状態</th>
                            <td><span class="grandpay-status-<?php echo ($grandpay_settings['activate'] ?? '') === 'on' ? 'success' : 'error'; ?>">
                                    <?php echo ($grandpay_settings['activate'] ?? '') === 'on' ? '✓ 有効' : '✗ 無効'; ?>
                                </span></td>
                        </tr>
                        <tr>
                            <th>テストモード</th>
                            <td><span class="grandpay-status-<?php echo ($grandpay_settings['test_mode'] ?? '') === 'on' ? 'warning' : 'success'; ?>">
                                    <?php echo ($grandpay_settings['test_mode'] ?? '') === 'on' ? '⚠️ テストモード' : '✓ 本番モード'; ?>
                                </span></td>
                        </tr>
                        <tr>
                            <th>Tenant Key</th>
                            <td><?php echo !empty($grandpay_settings['tenant_key']) ? '設定済み (' . substr($grandpay_settings['tenant_key'], 0, 10) . '...)' : '未設定'; ?></td>
                        </tr>
                        <tr>
                            <th>Client ID</th>
                            <td><?php echo !empty($grandpay_settings['client_id']) ? '設定済み (' . substr($grandpay_settings['client_id'], 0, 10) . '...)' : '未設定'; ?></td>
                        </tr>
                        <tr>
                            <th>Username</th>
                            <td><?php echo !empty($grandpay_settings['username']) ? '設定済み (' . $grandpay_settings['username'] . ')' : '未設定'; ?></td>
                        </tr>
                        <tr>
                            <th>Credentials</th>
                            <td><?php echo !empty($grandpay_settings['credentials']) ? '設定済み' : '未設定'; ?></td>
                        </tr>
                        <tr>
                            <th>Webhook URL</th>
                            <td><code style="word-break: break-all;"><?php echo $webhook_url; ?></code></td>
                        </tr>
                    </table>
                </div>

                <div class="card">
                    <h2>🧪 テスト機能</h2>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">

                        <div>
                            <h4>基本テスト</h4>
                            <form method="post" style="margin-bottom: 15px;">
                                <?php wp_nonce_field('grandpay_test_log'); ?>
                                <input type="submit" name="test_log" class="button button-secondary" value="ログテスト" />
                                <p class="description">デバッグログにテストメッセージを出力</p>
                            </form>

                            <button id="test-api-btn" class="button button-primary" type="button">API接続テスト</button>
                            <p class="description">OAuth2認証テストを実行</p>
                            <div id="test-api-result" style="margin-top: 10px;"></div>
                        </div>

                        <div>
                            <h4>決済テスト</h4>
                            <button id="test-checkout-btn" class="button button-primary" type="button">チェックアウトテスト</button>
                            <p class="description">実際のチェックアウトセッション作成をテスト</p>
                            <div id="test-checkout-result" style="margin-top: 10px;"></div>
                        </div>

                        <div>
                            <h4>詳細診断</h4>
                            <form method="post" style="margin-bottom: 15px;">
                                <?php wp_nonce_field('grandpay_test_detailed_api'); ?>
                                <input type="submit" name="test_detailed_api" class="button button-secondary" value="詳細API診断" />
                                <p class="description">API接続の詳細な診断を実行</p>
                            </form>
                        </div>

                        <div>
                            <h4>Webhook テスト</h4>
                            <form method="post" style="margin-bottom: 15px;">
                                <?php wp_nonce_field('grandpay_test_webhook'); ?>
                                <input type="submit" name="test_webhook" class="button button-secondary" value="Webhookテスト" />
                                <p class="description">Webhook受信エンドポイントをテスト</p>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>📋 設定完了チェックリスト</h2>
                    <ol style="line-height: 2;">
                        <li><strong>決済モジュールファイルの配置</strong>
                            <span class="grandpay-status-<?php echo $settlement_file_exists ? 'success' : 'error'; ?>">
                                <?php echo $settlement_file_exists ? '✓' : '→ 必要'; ?>
                            </span>
                        </li>
                        <li><strong>利用可能モジュールリストに登録</strong>
                            <span class="grandpay-status-<?php echo $is_available ? 'success' : 'error'; ?>">
                                <?php echo $is_available ? '✓' : '→ 必要'; ?>
                            </span>
                        </li>
                        <li><strong>Welcart決済設定ページでドラッグ&ドロップ</strong>
                            <span class="grandpay-status-<?php echo $is_selected ? 'success' : 'warning'; ?>">
                                <?php echo $is_selected ? '✓' : '→ 必要'; ?>
                            </span>
                        </li>
                        <li><strong>GrandPayタブで詳細設定</strong>
                            <span class="grandpay-status-<?php echo !empty($grandpay_settings['tenant_key']) ? 'success' : 'warning'; ?>">
                                <?php echo !empty($grandpay_settings['tenant_key']) ? '✓' : '→ 必要'; ?>
                            </span>
                        </li>
                        <li><strong>API接続テスト成功</strong> → 上記のテスト機能で確認</li>
                        <li><strong>Webhook URL設定</strong> → GrandPay技術サポートに依頼</li>
                    </ol>
                </div>

                <div class="card">
                    <h2>🔗 関連リンク</h2>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=usces_settlement'); ?>" class="button button-primary">
                            Welcart クレジット決済設定
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=usces_initial#payment_method_setting'); ?>" class="button button-secondary">
                            決済方法設定
                        </a>
                    </p>
                </div>

                <?php if (isset($_POST['test_detailed_api']) && !empty($detailed_result)): ?>
                    <div class="card">
                        <h2>🔍 詳細API診断結果</h2>
                        <pre style="background: #f1f1f1; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; white-space: pre-wrap;"><?php echo esc_html($detailed_result); ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#test-api-btn').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#test-api-result');

                    $btn.prop('disabled', true).text('テスト中...');
                    $result.html('<div style="color: #0073aa;">テスト実行中...</div>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'grandpay_test_api',
                            nonce: '<?php echo wp_create_nonce(WELCART_GRANDPAY_PAYMENT_SLUG); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<div style="color: #46b450;">✓ ' + response.data.message + '</div>');
                            } else {
                                $result.html('<div style="color: #dc3232;">✗ ' + response.data.message + '</div>');
                            }
                        },
                        error: function() {
                            $result.html('<div style="color: #dc3232;">✗ 通信エラーが発生しました</div>');
                        },
                        complete: function() {
                            $btn.prop('disabled', false).text('API接続テスト');
                        }
                    });
                });

                $('#test-checkout-btn').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#test-checkout-result');

                    $btn.prop('disabled', true).text('テスト中...');
                    $result.html('<div style="color: #0073aa;">チェックアウトセッション作成中...</div>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'grandpay_test_checkout',
                            nonce: '<?php echo wp_create_nonce(WELCART_GRANDPAY_PAYMENT_SLUG); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<div style="color: #46b450;">✓ ' + response.data.message +
                                    '<br><small>Session ID: ' + response.data.session_id + '</small>' +
                                    '<br><a href="' + response.data.checkout_url + '" target="_blank" class="button button-small">決済ページを開く</a></div>');
                            } else {
                                $result.html('<div style="color: #dc3232;">✗ ' + response.data.message + '</div>');
                            }
                        },
                        error: function() {
                            $result.html('<div style="color: #dc3232;">✗ 通信エラーが発生しました</div>');
                        },
                        complete: function() {
                            $btn.prop('disabled', false).text('チェックアウトテスト');
                        }
                    });
                });
            });
        </script>
<?php
    }

    /**
     * チェックアウトテストの実行
     */
    private function run_checkout_test($api) {
        $test_order_data = array(
            'order_id' => 'TEST_' . time(),
            'amount' => 1000,
            'name' => 'Test Customer',
            'email' => 'test@example.com',
            'phone' => '09012345678',
            'success_url' => home_url('/test-success/'),
            'failure_url' => home_url('/test-failure/')
        );

        $result = $api->create_checkout_session($test_order_data);

        if ($result['success']) {
            return '<div class="notice notice-success">
                <p>✓ チェックアウトセッション作成成功</p>
                <p><strong>Session ID:</strong> ' . $result['session_id'] . '</p>
                <p><a href="' . $result['checkout_url'] . '" target="_blank" class="button button-secondary">決済ページを開く</a></p>
            </div>';
        } else {
            return '<div class="notice notice-error">
                <p>✗ チェックアウトセッション作成失敗: ' . $result['error'] . '</p>
            </div>';
        }
    }

    /**
     * 詳細APIテストの実行
     */
    private function run_detailed_api_test($api) {
        $output = "=== GrandPay API 詳細診断 ===\n";
        $output .= "実行時刻: " . current_time('Y-m-d H:i:s') . "\n\n";

        // デバッグ情報を取得
        $debug_info = $api->get_debug_info();

        $output .= "1. 設定情報\n";
        $output .= "   Base URL: " . $debug_info['base_url'] . "\n";
        $output .= "   Test Mode: " . ($debug_info['test_mode'] ? 'ON' : 'OFF') . "\n";
        $output .= "   Tenant Key: " . ($debug_info['has_tenant_key'] ? 'SET' : 'NOT SET') . "\n";
        $output .= "   Client ID: " . ($debug_info['has_client_id'] ? 'SET' : 'NOT SET') . "\n";
        $output .= "   Username: " . ($debug_info['has_username'] ? 'SET' : 'NOT SET') . "\n";
        $output .= "   Credentials: " . ($debug_info['has_credentials'] ? 'SET' : 'NOT SET') . "\n\n";

        // OAuth2テスト
        $output .= "2. OAuth2認証テスト\n";
        $token_result = $api->get_access_token();
        if ($token_result['success']) {
            $output .= "   ✅ OAuth2認証成功\n";
            $output .= "   Token cached: " . ($debug_info['cached_token_exists'] ? 'YES' : 'NO') . "\n";
            if ($debug_info['token_expires_at']) {
                $output .= "   Token expires: " . date('Y-m-d H:i:s', $debug_info['token_expires_at']) . "\n";
            }
        } else {
            $output .= "   ❌ OAuth2認証失敗: " . $token_result['error'] . "\n";
        }

        $output .= "\n=== 診断完了 ===";
        return $output;
    }

    /**
     * Webhookエンドポイントのテスト
     */
    private function test_webhook_endpoint() {
        $webhook_url = rest_url('grandpay/v1/webhook');

        // テスト用Webhookペイロード
        $test_payload = array(
            'type' => 'PAYMENT_CHECKOUT',
            'data' => array(
                'object' => array(
                    'id' => 'test_session_' . time(),
                    'status' => 'COMPLETED'
                )
            )
        );

        $response = wp_remote_post($webhook_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'GrandPay-Test'
            ),
            'body' => json_encode($test_payload),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return '<div class="notice notice-error">
                <p>✗ Webhookテスト失敗: ' . $response->get_error_message() . '</p>
            </div>';
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            return '<div class="notice notice-success">
                <p>✓ Webhookエンドポイント正常</p>
                <p><strong>URL:</strong> <code>' . $webhook_url . '</code></p>
                <p><strong>Response:</strong> ' . $response_body . '</p>
            </div>';
        } else {
            return '<div class="notice notice-warning">
                <p>⚠️ Webhookエンドポイント応答異常 (HTTP ' . $response_code . ')</p>
                <p><strong>Response:</strong> ' . $response_body . '</p>
            </div>';
        }
    }
}
