<?php

/**
 * GrandPay決済モジュール（Welcart標準準拠）- 完全版
 * OAuth2認証とRESTful API統合、包括的エラーハンドリング
 */

// 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GrandPay決済クラス - 完全実装版
 */
class GRANDPAY_SETTLEMENT {

    /**
     * Instance of this class.
     *
     * @var object
     */
    protected static $instance = null;

    protected $paymod_id;          // 決済代行会社ID
    protected $pay_method;         // 決済種別
    protected $acting_name;        // 決済代行会社略称
    protected $acting_formal_name; // 決済代行会社正式名称
    protected $acting_company_url; // 決済代行会社URL

    /**
     * エラーメッセージ
     *
     * @var string
     */
    protected $error_mes;

    /**
     * Construct.
     */
    public function __construct() {

        $this->paymod_id          = 'grandpay';
        $this->pay_method         = array('acting_grandpay_card');
        $this->acting_name        = 'GrandPay';
        $this->acting_formal_name = 'GrandPay Asia';
        $this->acting_company_url = 'https://payment-gateway.asia/';

        $this->initialize_data();

        if (is_admin()) {
            add_action('usces_action_admin_settlement_update', array($this, 'settlement_update'));
            add_action('usces_action_settlement_tab_title', array($this, 'settlement_tab_title'));
            add_action('usces_action_settlement_tab_body', array($this, 'settlement_tab_body'));

            // AJAX処理
            add_action('wp_ajax_grandpay_validate_settings', array($this, 'ajax_validate_settings'));
            add_action('wp_ajax_grandpay_test_credentials', array($this, 'ajax_test_credentials'));
        }

        if ($this->is_activate_card()) {
            add_action('usces_action_reg_orderdata', array($this, 'register_orderdata'));
            add_filter('usces_filter_acting_getdata', array($this, 'acting_getdata'), 10, 2);
            add_action('usces_action_acting_processing', array($this, 'acting_processing'), 10);
        }

        // フロントエンド処理
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        error_log('GrandPay Settlement Class: Initialized successfully with enhanced features');
    }

    /**
     * Return an instance of this class.
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize enhanced data structure
     */
    public function initialize_data() {
        $options = get_option('usces', array());
        if (!isset($options['acting_settings']) || !isset($options['acting_settings']['grandpay'])) {
            $default_settings = array(
                'activate'            => 'off',
                'test_mode'           => 'on',
                'payment_name'        => 'クレジットカード決済（GrandPay）',
                'payment_description' => 'クレジットカードで安全にお支払いいただけます。VISA、MasterCard、JCB等がご利用いただけます。',
                'tenant_key'          => '',
                'client_id'           => '',
                'username'            => '',
                'credentials'         => '',
                'card_activate'       => 'off',
                'auto_settlement'     => 'off',
                'send_customer_info'  => 'on',
                'debug_mode'          => 'off',
                'webhook_secret'      => wp_generate_password(32, false)
            );

            $options['acting_settings']['grandpay'] = $default_settings;
            update_option('usces', $options);

            error_log('GrandPay Settlement: Default enhanced settings initialized');
        }
    }

    /**
     * 決済有効判定（強化版）
     *
     * @param string $type Module type.
     * @return boolean
     */
    public function is_validity_acting($type = '') {
        $acting_opts = $this->get_acting_settings();
        if (empty($acting_opts)) {
            return false;
        }

        // 基本設定チェック
        if (($acting_opts['activate'] ?? 'off') !== 'on') {
            return false;
        }

        // 必須設定項目チェック
        $required_fields = array('tenant_key', 'client_id', 'username', 'credentials');
        foreach ($required_fields as $field) {
            if (empty($acting_opts[$field])) {
                error_log('GrandPay Settlement: Missing required field: ' . $field);
                return false;
            }
        }

        $payment_method = usces_get_system_option('usces_payment_method', 'sort');
        $method = false;

        switch ($type) {
            case 'card':
                foreach ($payment_method as $payment) {
                    if ('acting_grandpay_card' == $payment['settlement'] && 'activate' == $payment['use']) {
                        $method = true;
                        break;
                    }
                }
                if ($method && $this->is_activate_card()) {
                    return true;
                } else {
                    return false;
                }
                break;

            default:
                return true;
        }
    }

    /**
     * クレジットカード決済有効判定（強化版）
     *
     * @return boolean $res
     */
    public function is_activate_card() {
        $acting_opts = $this->get_acting_settings();

        $conditions = array(
            isset($acting_opts['activate']) && 'on' == $acting_opts['activate'],
            isset($acting_opts['card_activate']) && 'on' == $acting_opts['card_activate']
        );

        $res = array_reduce($conditions, function ($carry, $condition) {
            return $carry && $condition;
        }, true);

        return $res;
    }

    /**
     * 決済オプション登録・更新（強化版）
     * usces_action_admin_settlement_update
     */
    public function settlement_update() {
        global $usces;

        if ($this->paymod_id != $_POST['acting']) {
            return;
        }

        error_log('GrandPay Settlement: Enhanced settlement_update() called');

        $this->error_mes = '';
        $options = get_option('usces', array());
        $payment_method = usces_get_system_option('usces_payment_method', 'settlement');

        // 既存設定をクリア
        unset($options['acting_settings']['grandpay']);

        // 新しい設定を構築
        $new_settings = array(
            'activate'            => (isset($_POST['activate'])) ? $_POST['activate'] : 'off',
            'test_mode'           => (isset($_POST['test_mode'])) ? $_POST['test_mode'] : 'on',
            'payment_name'        => (isset($_POST['payment_name'])) ? sanitize_text_field($_POST['payment_name']) : 'クレジットカード決済（GrandPay）',
            'payment_description' => (isset($_POST['payment_description'])) ? sanitize_textarea_field($_POST['payment_description']) : 'クレジットカードで安全にお支払いいただけます。',
            'tenant_key'          => (isset($_POST['tenant_key'])) ? sanitize_text_field($_POST['tenant_key']) : '',
            'client_id'           => (isset($_POST['client_id'])) ? sanitize_text_field($_POST['client_id']) : '',
            'username'            => (isset($_POST['username'])) ? sanitize_text_field($_POST['username']) : '',
            'credentials'         => (isset($_POST['credentials'])) ? sanitize_text_field($_POST['credentials']) : '',
            'card_activate'       => (isset($_POST['activate']) && $_POST['activate'] == 'on') ? 'on' : 'off',
            'auto_settlement'     => (isset($_POST['auto_settlement'])) ? $_POST['auto_settlement'] : 'off',
            'send_customer_info'  => (isset($_POST['send_customer_info'])) ? $_POST['send_customer_info'] : 'on',
            'debug_mode'          => (isset($_POST['debug_mode'])) ? $_POST['debug_mode'] : 'off'
        );

        // Webhookシークレットキーの保持または生成
        $existing_settings = $options['acting_settings']['grandpay'] ?? array();
        $new_settings['webhook_secret'] = $existing_settings['webhook_secret'] ?? wp_generate_password(32, false);

        $options['acting_settings']['grandpay'] = $new_settings;

        // 強化されたバリデーション
        if ('on' == $new_settings['activate']) {
            $validation_errors = $this->validate_settings($new_settings);
            if (!empty($validation_errors)) {
                $this->error_mes = implode('<br>', $validation_errors);
            }

            // API接続テスト（オプション）
            if (empty($this->error_mes) && isset($_POST['test_connection_on_save'])) {
                $test_result = $this->test_api_connection($new_settings);
                if (!$test_result['success']) {
                    $this->error_mes .= '※API接続テストに失敗しました: ' . $test_result['error'] . '<br>';
                }
            }
        }

        if ('' == $this->error_mes) {
            $usces->action_status = 'success';
            $usces->action_message = __('Options are updated.', 'usces');

            if ('on' == $new_settings['activate']) {
                $toactive = array();

                // 決済処理の登録
                $usces->payment_structure['acting_grandpay_card'] = $new_settings['payment_name'];

                foreach ($payment_method as $settlement => $payment) {
                    if ('acting_grandpay_card' == $settlement && 'deactivate' == $payment['use']) {
                        $toactive[] = $payment['name'];
                    }
                }

                usces_admin_orderlist_show_wc_trans_id();
                if (0 < count($toactive)) {
                    $usces->action_message .= __("Please update the payment method to \"Activate\". <a href=\"admin.php?page=usces_initial#payment_method_setting\">General Setting > Payment Methods</a>", 'usces');
                }

                // acting_flagを自動設定（GrandPayが有効な場合）
                $options['acting_settings']['acting_flag'] = 'grandpay';
                error_log('GrandPay Settlement: Set acting_flag to grandpay');

                // 成功メッセージに追加情報
                $usces->action_message .= '<br><strong>GrandPay設定が完了しました。</strong>';
                if ($new_settings['test_mode'] === 'on') {
                    $usces->action_message .= '<br>⚠️ テストモードで動作しています。本番運用前にテストモードを無効にしてください。';
                }
            } else {
                unset($usces->payment_structure['acting_grandpay_card']);

                // GrandPayを無効にした場合、acting_flagもリセット
                if (
                    isset($options['acting_settings']['acting_flag']) &&
                    $options['acting_settings']['acting_flag'] === 'grandpay'
                ) {
                    $options['acting_settings']['acting_flag'] = '';
                    error_log('GrandPay Settlement: Reset acting_flag');
                }
            }

            $this->handle_payment_method_updates($usces, $payment_method);
        } else {
            $usces->action_status = 'error';
            $usces->action_message = __('Data have deficiency.', 'usces');
            $options['acting_settings']['grandpay']['activate'] = 'off';
            unset($usces->payment_structure['acting_grandpay_card']);

            $this->handle_payment_method_updates($usces, $payment_method, true);
        }

        ksort($usces->payment_structure);
        update_option('usces', $options);
        update_option('usces_payment_structure', $usces->payment_structure);

        // 個別オプションとしても保存（API クラスで使用）
        $this->update_individual_options($new_settings);

        // キャッシュクリア
        $this->clear_api_cache();

        error_log('GrandPay Settlement: Enhanced settings saved successfully');
    }

    /**
     * 設定値バリデーション
     */
    private function validate_settings($settings) {
        $errors = array();

        $required_fields = array(
            'tenant_key' => 'Tenant Key',
            'client_id' => 'Client ID',
            'username' => 'Username',
            'credentials' => 'Credentials'
        );

        foreach ($required_fields as $field => $label) {
            if (empty($settings[$field])) {
                $errors[] = '※' . $label . 'を入力してください';
            }
        }

        // Tenant Keyのフォーマットチェック
        if (!empty($settings['tenant_key']) && !preg_match('/^tk_[a-f0-9]{32}$/', $settings['tenant_key'])) {
            $errors[] = '※Tenant Keyの形式が正しくありません（tk_で始まる32桁の16進数である必要があります）';
        }

        // メールアドレス形式のバリデーション（usernameがメールアドレスの場合）
        if (!empty($settings['username']) && strpos($settings['username'], '@') !== false) {
            if (!is_email($settings['username'])) {
                $errors[] = '※Usernameのメールアドレス形式が正しくありません';
            }
        }

        return $errors;
    }

    /**
     * API接続テスト
     */
    private function test_api_connection($settings) {
        // テスト用に一時的に設定を更新
        update_option('welcart_grandpay_tenant_key', $settings['tenant_key']);
        update_option('welcart_grandpay_client_id', $settings['client_id']);
        update_option('welcart_grandpay_username', $settings['username']);
        update_option('welcart_grandpay_credentials', $settings['credentials']);
        update_option('welcart_grandpay_test_mode', $settings['test_mode'] === 'on');

        // APIクラスインスタンス作成
        if (class_exists('WelcartGrandpayAPI')) {
            $api = new WelcartGrandpayAPI();
            return $api->test_connection();
        }

        return array('success' => false, 'error' => 'APIクラスが見つかりません');
    }

    /**
     * 決済方法の更新処理
     */
    private function handle_payment_method_updates($usces, $payment_method, $force_deactivate = false) {
        $deactivate = array();

        foreach ($payment_method as $settlement => $payment) {
            if ($force_deactivate || !array_key_exists($settlement, $usces->payment_structure)) {
                if ('deactivate' != $payment['use']) {
                    $payment['use'] = 'deactivate';
                    $deactivate[] = $payment['name'];
                    usces_update_system_option('usces_payment_method', $payment['id'], $payment);
                }
            }
        }

        if (0 < count($deactivate)) {
            $deactivate_message = sprintf(__("\"Deactivate\" %s of payment method.", 'usces'), implode(',', $deactivate));
            $usces->action_message .= $deactivate_message;

            if ($force_deactivate) {
                $usces->action_message .= __("Please complete the setup and update the payment method to \"Activate\".", 'usces');
            }
        }
    }

    /**
     * 個別オプションの更新
     */
    private function update_individual_options($settings) {
        update_option('welcart_grandpay_tenant_key', $settings['tenant_key']);
        update_option('welcart_grandpay_client_id', $settings['client_id']);
        update_option('welcart_grandpay_username', $settings['username']);
        update_option('welcart_grandpay_credentials', $settings['credentials']);
        update_option('welcart_grandpay_test_mode', $settings['test_mode'] === 'on');

        // usces_exオプションも更新
        $ex_options = get_option('usces_ex', array());
        $ex_options['grandpay'] = $settings;
        update_option('usces_ex', $ex_options);
    }

    /**
     * APIキャッシュクリア
     */
    private function clear_api_cache() {
        delete_transient('welcart_grandpay_access_token');
        delete_transient('welcart_grandpay_token_expires_at');
        error_log('GrandPay Settlement: API cache cleared');
    }

    /**
     * クレジット決済設定画面タブ（強化版）
     * usces_action_settlement_tab_title
     */
    public function settlement_tab_title() {
        $settlement_selected = get_option('usces_settlement_selected');
        if (in_array($this->paymod_id, (array) $settlement_selected)) {
            $acting_opts = $this->get_acting_settings();
            $status_class = '';

            if (($acting_opts['activate'] ?? 'off') === 'on') {
                $status_class = ($acting_opts['test_mode'] ?? 'on') === 'on' ? 'test-mode' : 'production-mode';
            }

            echo '<li class="grandpay-tab ' . $status_class . '"><a href="#uscestabs_' . $this->paymod_id . '">' . $this->acting_name . '</a></li>';
            error_log('GrandPay Settlement: Enhanced tab title added');
        } else {
            error_log('GrandPay Settlement: Not in selected settlements - tab not added');
        }
    }

    /**
     * クレジット決済設定画面フォーム（大幅強化版）
     * usces_action_settlement_tab_body
     */
    public function settlement_tab_body() {
        global $usces;

        $acting_opts = $this->get_acting_settings();
        $settlement_selected = get_option('usces_settlement_selected');

        if (in_array($this->paymod_id, (array) $settlement_selected)) :
            error_log('GrandPay Settlement: Displaying enhanced tab body');

            // ステータス判定
            $is_configured = !empty($acting_opts['tenant_key']) && !empty($acting_opts['client_id']) &&
                !empty($acting_opts['username']) && !empty($acting_opts['credentials']);
            $is_active = ($acting_opts['activate'] ?? 'off') === 'on';
            $is_test_mode = ($acting_opts['test_mode'] ?? 'on') === 'on';
?>
            <div id="uscestabs_grandpay">
                <div class="settlement_service">
                    <span class="service_title"><?php echo esc_html($this->acting_formal_name); ?></span>
                    <div class="settlement_status">
                        <?php if ($is_active): ?>
                            <span class="status-badge <?php echo $is_test_mode ? 'test' : 'production'; ?>">
                                <?php echo $is_test_mode ? '🧪 テストモード' : '🚀 本番モード'; ?>
                            </span>
                        <?php else: ?>
                            <span class="status-badge inactive">⚪ 停止中</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($_POST['acting']) && 'grandpay' == $_POST['acting']) : ?>
                    <?php if ('' != $this->error_mes) : ?>
                        <div class="error_message"><?php wel_esc_script_e($this->error_mes); ?></div>
                    <?php elseif ($is_active) : ?>
                        <div class="message">
                            <strong>✅ GrandPay設定が完了しました。</strong><br>
                            <?php if ($is_test_mode): ?>
                                ⚠️ 現在テストモードです。本番運用前にテストモードを無効にしてください。
                            <?php else: ?>
                                🚀 本番モードで稼働中です。
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <form action="" method="post" name="grandpay_form" id="grandpay_form">
                    <table class="settle_table <?php echo $is_test_mode ? 'test-mode' : ''; ?>">
                        <tr>
                            <th><a class="explanation-label" id="label_ex_activate_grandpay">GrandPay を利用する</a></th>
                            <td>
                                <label><input name="activate" type="radio" id="activate_grandpay_1" value="on" <?php checked($is_active, true); ?> /><span>利用する</span></label><br />
                                <label><input name="activate" type="radio" id="activate_grandpay_2" value="off" <?php checked($is_active, false); ?> /><span>利用しない</span></label>
                            </td>
                        </tr>
                        <tr id="ex_activate_grandpay" class="explanation">
                            <td colspan="2">GrandPay決済サービスを利用するかどうかを選択してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_payment_name_grandpay">決済方法名</a></th>
                            <td><input name="payment_name" type="text" id="payment_name_grandpay" value="<?php echo esc_attr($acting_opts['payment_name'] ?? 'クレジットカード決済（GrandPay）'); ?>" class="regular-text" /></td>
                        </tr>
                        <tr id="ex_payment_name_grandpay" class="explanation">
                            <td colspan="2">フロント画面に表示される決済方法名を設定してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_payment_description_grandpay">決済説明文</a></th>
                            <td><textarea name="payment_description" id="payment_description_grandpay" rows="3" cols="50" class="regular-text"><?php echo esc_textarea($acting_opts['payment_description'] ?? 'クレジットカードで安全にお支払いいただけます。VISA、MasterCard、JCB等がご利用いただけます。'); ?></textarea></td>
                        </tr>
                        <tr id="ex_payment_description_grandpay" class="explanation">
                            <td colspan="2">フロント画面に表示される決済方法の説明文を設定してください。</td>
                        </tr>

                        <tr class="section-header">
                            <th colspan="2">
                                <h3>🔐 API認証設定</h3>
                            </th>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_tenant_key_grandpay">Tenant Key <span class="required">*</span></a></th>
                            <td>
                                <input name="tenant_key" type="text" id="tenant_key_grandpay" value="<?php echo esc_attr($acting_opts['tenant_key'] ?? ''); ?>" class="regular-text" placeholder="tk_f231a0556470a99c22112755043b33f6" />
                                <?php if (!empty($acting_opts['tenant_key'])): ?>
                                    <span class="status-indicator success">✓</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr id="ex_tenant_key_grandpay" class="explanation">
                            <td colspan="2">GrandPayから提供されたTenant Keyを入力してください。<br><strong>形式:</strong> tk_で始まる32桁の16進数</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_client_id_grandpay">Client ID <span class="required">*</span></a></th>
                            <td>
                                <input name="client_id" type="text" id="client_id_grandpay" value="<?php echo esc_attr($acting_opts['client_id'] ?? ''); ?>" class="regular-text" placeholder="YXBpLW1lcmNoYW50OnNlY3JldA==" />
                                <?php if (!empty($acting_opts['client_id'])): ?>
                                    <span class="status-indicator success">✓</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr id="ex_client_id_grandpay" class="explanation">
                            <td colspan="2">GrandPayから提供されたClient IDを入力してください。<br><strong>OAuth2認証で使用されます。</strong></td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_username_grandpay">Username <span class="required">*</span></a></th>
                            <td>
                                <input name="username" type="text" id="username_grandpay" value="<?php echo esc_attr($acting_opts['username'] ?? ''); ?>" class="regular-text" placeholder="your_username" />
                                <?php if (!empty($acting_opts['username'])): ?>
                                    <span class="status-indicator success">✓</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr id="ex_username_grandpay" class="explanation">
                            <td colspan="2">GrandPay管理画面にログインする際のユーザー名を入力してください。<br>
                                <strong>※OAuth2認証に使用されます</strong>
                            </td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_credentials_grandpay">Credentials <span class="required">*</span></a></th>
                            <td>
                                <input name="credentials" type="password" id="credentials_grandpay" value="<?php echo esc_attr($acting_opts['credentials'] ?? ''); ?>" class="regular-text" placeholder="your_password" />
                                <button type="button" class="button button-small" onclick="togglePasswordVisibility('credentials_grandpay')">👁️ 表示</button>
                                <?php if (!empty($acting_opts['credentials'])): ?>
                                    <span class="status-indicator success">✓</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr id="ex_credentials_grandpay" class="explanation">
                            <td colspan="2">GrandPay管理画面にログインする際のパスワードを入力してください。<br>
                                <strong>※OAuth2認証に使用されます</strong>
                            </td>
                        </tr>

                        <tr class="section-header">
                            <th colspan="2">
                                <h3>⚙️ 動作設定</h3>
                            </th>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_test_mode_grandpay">動作環境</a></th>
                            <td>
                                <label><input name="test_mode" type="radio" id="test_mode_grandpay_1" value="on" <?php checked($is_test_mode, true); ?> /><span>🧪 テスト環境</span></label><br />
                                <label><input name="test_mode" type="radio" id="test_mode_grandpay_2" value="off" <?php checked($is_test_mode, false); ?> /><span>🚀 本番環境</span></label>
                            </td>
                        </tr>
                        <tr id="ex_test_mode_grandpay" class="explanation">
                            <td colspan="2">
                                <strong>テスト環境:</strong> 実際の決済は行わず、テスト用の決済フローを実行します。<br>
                                <strong>本番環境:</strong> 実際の決済を行います。十分なテストを行ってから有効にしてください。
                            </td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_auto_settlement_grandpay">自動売上確定</a></th>
                            <td>
                                <label><input name="auto_settlement" type="radio" id="auto_settlement_grandpay_1" value="on" <?php checked(($acting_opts['auto_settlement'] ?? 'off'), 'on'); ?> /><span>有効</span></label><br />
                                <label><input name="auto_settlement" type="radio" id="auto_settlement_grandpay_2" value="off" <?php checked(($acting_opts['auto_settlement'] ?? 'off'), 'off'); ?> /><span>無効（手動売上確定）</span></label>
                            </td>
                        </tr>
                        <tr id="ex_auto_settlement_grandpay" class="explanation">
                            <td colspan="2">決済完了時に自動的に売上を確定するかどうかを設定します。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_send_customer_info_grandpay">顧客情報送信</a></th>
                            <td>
                                <label><input name="send_customer_info" type="radio" id="send_customer_info_grandpay_1" value="on" <?php checked(($acting_opts['send_customer_info'] ?? 'on'), 'on'); ?> /><span>送信する</span></label><br />
                                <label><input name="send_customer_info" type="radio" id="send_customer_info_grandpay_2" value="off" <?php checked(($acting_opts['send_customer_info'] ?? 'on'), 'off'); ?> /><span>送信しない</span></label>
                            </td>
                        </tr>
                        <tr id="ex_send_customer_info_grandpay" class="explanation">
                            <td colspan="2">決済時に顧客の詳細情報（住所、電話番号等）をGrandPayに送信するかどうかを設定します。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_debug_mode_grandpay">デバッグモード</a></th>
                            <td>
                                <label><input name="debug_mode" type="radio" id="debug_mode_grandpay_1" value="on" <?php checked(($acting_opts['debug_mode'] ?? 'off'), 'on'); ?> /><span>有効</span></label><br />
                                <label><input name="debug_mode" type="radio" id="debug_mode_grandpay_2" value="off" <?php checked(($acting_opts['debug_mode'] ?? 'off'), 'off'); ?> /><span>無効</span></label>
                            </td>
                        </tr>
                        <tr id="ex_debug_mode_grandpay" class="explanation">
                            <td colspan="2">詳細なログ出力を有効にします。トラブルシューティング時のみ有効にしてください。</td>
                        </tr>

                        <?php if ($is_configured): ?>
                            <tr class="section-header">
                                <th colspan="2">
                                    <h3>🧪 テスト機能</h3>
                                </th>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <div class="test-buttons">
                                        <button type="button" class="button button-secondary" id="test-credentials-btn">認証テスト</button>
                                        <button type="button" class="button button-secondary" id="test-checkout-btn">チェックアウトテスト</button>
                                        <a href="<?php echo admin_url('options-general.php?page=welcart-grandpay-payment'); ?>" class="button button-secondary">詳細診断ページ</a>
                                    </div>
                                    <div id="test-results" style="margin-top: 15px;"></div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div class="submit-section">
                        <label style="margin-right: 20px;">
                            <input type="checkbox" name="test_connection_on_save" value="1" />
                            保存時にAPI接続テストを実行する
                        </label>
                        <input name="acting" type="hidden" value="grandpay" />
                        <input name="usces_option_update" type="submit" class="button button-primary" value="<?php echo esc_html($this->acting_name); ?>の設定を更新する" />
                        <?php wp_nonce_field('admin_settlement', 'wc_nonce'); ?>
                    </div>
                </form>

                <div class="settle_exp">
                    <p><strong><?php echo esc_html($this->acting_formal_name); ?></strong></p>
                    <a href="<?php echo esc_url($this->acting_company_url); ?>" target="_blank"><?php echo esc_html($this->acting_name); ?>の詳細はこちら »</a>

                    <!-- 設定状況ダッシュボード -->
                    <div class="settings-dashboard">
                        <h3>📊 設定状況</h3>
                        <div class="status-grid">
                            <div class="status-item">
                                <span class="status-label">基本設定</span>
                                <span class="status-value <?php echo $is_configured ? 'success' : 'warning'; ?>">
                                    <?php echo $is_configured ? '✅ 完了' : '⚠️ 未完了'; ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <span class="status-label">決済モジュール</span>
                                <span class="status-value <?php echo $is_active ? 'success' : 'inactive'; ?>">
                                    <?php echo $is_active ? '✅ 有効' : '⚪ 無効'; ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <span class="status-label">動作モード</span>
                                <span class="status-value <?php echo $is_test_mode ? 'warning' : 'success'; ?>">
                                    <?php echo $is_test_mode ? '🧪 テスト' : '🚀 本番'; ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <span class="status-label">Webhook URL</span>
                                <span class="status-value info">
                                    <code style="font-size: 12px;"><?php echo rest_url('grandpay/v1/webhook'); ?></code>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Webhook URL設定説明 -->
                    <div class="webhook-section">
                        <h3>📡 Webhook URL設定</h3>
                        <p><strong>GrandPayの技術サポートに以下のWebhook URLを設定依頼してください：</strong></p>
                        <div class="webhook-url-box">
                            <code><?php echo rest_url('grandpay/v1/webhook'); ?></code>
                            <button type="button" class="button button-small copy-webhook-url" data-url="<?php echo rest_url('grandpay/v1/webhook'); ?>">📋 コピー</button>
                        </div>
                        <p><em>※ このURLにより、決済完了/失敗時に自動的に注文ステータスが更新されます</em></p>
                    </div>

                    <div class="setup-guide">
                        <h3>📋 設定完了までの手順</h3>
                        <ol>
                            <li><strong>API認証情報の設定</strong> <?php echo $is_configured ? '✅' : '⬜'; ?></li>
                            <li><strong>「GrandPay を利用する」を有効化</strong> <?php echo $is_active ? '✅' : '⬜'; ?></li>
                            <li><strong><a href="<?php echo admin_url('admin.php?page=usces_initial#payment_method_setting'); ?>">決済方法設定</a></strong>で「カード決済（GrandPay）」を「Activate」に変更 </li>
                            <li><strong><a href="<?php echo admin_url('admin.php?page=usces_initial#acting_setting'); ?>">代行決済設定</a></strong>で「決済種別」を「GrandPay」に変更</li>
                            <li><strong>Webhook URL</strong>をGrandPay技術サポートに設定依頼</li>
                            <li><strong>決済テスト</strong>を実施（上記のテスト機能を使用）</li>
                            <li><strong>本番モード</strong>への切り替え（テスト完了後）</li>
                        </ol>
                    </div>
                </div>

                <style>
                    .settlement_service {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        margin-bottom: 20px;
                    }

                    .settlement_status .status-badge {
                        padding: 4px 12px;
                        border-radius: 12px;
                        font-size: 12px;
                        font-weight: 600;
                        text-transform: uppercase;
                    }

                    .status-badge.test {
                        background: #fff3cd;
                        color: #856404;
                    }

                    .status-badge.production {
                        background: #d4edda;
                        color: #155724;
                    }

                    .status-badge.inactive {
                        background: #f8d7da;
                        color: #721c24;
                    }

                    .settle_table.test-mode {
                        border-left: 4px solid #ffc107;
                    }

                    .section-header th {
                        background-color: #f8f9fa !important;
                        border-top: 2px solid #0073aa;
                        color: #0073aa;
                        font-weight: 600;
                    }

                    .section-header h3 {
                        margin: 0;
                        font-size: 16px;
                    }

                    .required {
                        color: #dc3232;
                        font-weight: bold;
                    }

                    .status-indicator {
                        margin-left: 10px;
                        font-weight: bold;
                    }

                    .status-indicator.success {
                        color: #46b450;
                    }

                    .test-buttons {
                        display: flex;
                        gap: 10px;
                        flex-wrap: wrap;
                    }

                    .submit-section {
                        margin-top: 20px;
                        padding: 15px;
                        background-color: #f8f9fa;
                        border-radius: 4px;
                        border-left: 4px solid #0073aa;
                    }

                    .settings-dashboard {
                        background: #f8f9fa;
                        border: 1px solid #e1e5e9;
                        border-radius: 6px;
                        padding: 20px;
                        margin: 20px 0;
                    }

                    .status-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                        gap: 15px;
                        margin-top: 15px;
                    }

                    .status-item {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        padding: 10px;
                        background: white;
                        border-radius: 4px;
                        border-left: 3px solid #ddd;
                    }

                    .status-item .status-label {
                        font-weight: 600;
                        color: #333;
                    }

                    .status-value.success {
                        color: #46b450;
                        border-left-color: #46b450;
                    }

                    .status-value.warning {
                        color: #ffb900;
                        border-left-color: #ffb900;
                    }

                    .status-value.inactive {
                        color: #999;
                        border-left-color: #999;
                    }

                    .status-value.info {
                        color: #0073aa;
                        border-left-color: #0073aa;
                    }

                    .webhook-section {
                        background: #e7f3ff;
                        border: 1px solid #0073aa;
                        border-radius: 4px;
                        padding: 15px;
                        margin: 20px 0;
                    }

                    .webhook-url-box {
                        background: white;
                        padding: 10px;
                        border-radius: 3px;
                        margin: 10px 0;
                        display: flex;
                        align-items: center;
                        gap: 10px;
                    }

                    .webhook-url-box code {
                        flex: 1;
                        word-break: break-all;
                        background: none;
                        padding: 0;
                    }

                    .setup-guide ol {
                        counter-reset: step-counter;
                        list-style: none;
                        padding-left: 0;
                    }

                    .setup-guide li {
                        counter-increment: step-counter;
                        margin-bottom: 10px;
                        padding-left: 30px;
                        position: relative;
                    }

                    .setup-guide li::before {
                        content: counter(step-counter);
                        position: absolute;
                        left: 0;
                        top: 0;
                        background: #0073aa;
                        color: white;
                        width: 20px;
                        height: 20px;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 12px;
                        font-weight: bold;
                    }
                </style>

                <script>
                    function togglePasswordVisibility(fieldId) {
                        const field = document.getElementById(fieldId);
                        const button = field.nextElementSibling;

                        if (field.type === 'password') {
                            field.type = 'text';
                            button.textContent = '🙈 非表示';
                        } else {
                            field.type = 'password';
                            button.textContent = '👁️ 表示';
                        }
                    }

                    // Webhook URLコピー機能
                    document.addEventListener('DOMContentLoaded', function() {
                        const copyBtn = document.querySelector('.copy-webhook-url');
                        if (copyBtn) {
                            copyBtn.addEventListener('click', function() {
                                const url = this.getAttribute('data-url');
                                navigator.clipboard.writeText(url).then(function() {
                                    copyBtn.textContent = '✅ コピー完了';
                                    setTimeout(function() {
                                        copyBtn.textContent = '📋 コピー';
                                    }, 2000);
                                });
                            });
                        }
                    });
                </script>
    <?php
        else :
            error_log('GrandPay Settlement: Not in selected settlements - tab body not displayed');
        endif;
    }

    /**
     * 受注データ登録（強化版）
     * usces_action_reg_orderdata
     *
     * @param array $args
     */
    public function register_orderdata($args) {
        global $usces;
        extract($args);

        $acting_flg = $payments['settlement'];
        if (!in_array($acting_flg, $this->pay_method)) {
            return;
        }

        if (!$entry['order']['total_full_price']) {
            return;
        }

        // GrandPay固有の注文データ処理
        $grandpay_data = array(
            'settlement_id' => $this->paymod_id,
            'payment_method' => $acting_flg,
            'created_at' => current_time('mysql'),
            'test_mode' => $this->get_acting_settings()['test_mode'] ?? 'on'
        );

        // カスタムフィールドとして保存
        update_post_meta($order_id, '_grandpay_order_data', $grandpay_data);

        error_log('GrandPay Settlement: Enhanced order data registered for order_id: ' . $order_id);
    }

    /**
     * フロントエンドスクリプトの読み込み
     */
    public function enqueue_frontend_scripts() {
        if (!is_admin() && $this->is_activate_card()) {
            wp_enqueue_script(
                'grandpay-settlement-frontend',
                plugins_url('js/settlement-frontend.js', __FILE__),
                array('jquery'),
                '1.0.0',
                true
            );

            wp_localize_script('grandpay-settlement-frontend', 'grandpay_settlement', array(
                'test_mode' => $this->get_acting_settings()['test_mode'] ?? 'on',
                'payment_name' => $this->get_acting_settings()['payment_name'] ?? 'GrandPay'
            ));
        }
    }

    /**
     * AJAX: 設定バリデーション
     */
    public function ajax_validate_settings() {
        check_ajax_referer('grandpay_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
        }

        $settings = array(
            'tenant_key' => sanitize_text_field($_POST['tenant_key'] ?? ''),
            'client_id' => sanitize_text_field($_POST['client_id'] ?? ''),
            'username' => sanitize_text_field($_POST['username'] ?? ''),
            'credentials' => sanitize_text_field($_POST['credentials'] ?? '')
        );

        $errors = $this->validate_settings($settings);

        if (empty($errors)) {
            wp_send_json_success(array('message' => '設定が正しく入力されています'));
        } else {
            wp_send_json_error(array('errors' => $errors));
        }
    }

    /**
     * AJAX: 認証情報テスト
     */
    public function ajax_test_credentials() {
        check_ajax_referer('grandpay_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
        }

        $test_result = $this->test_api_connection($_POST);

        if ($test_result['success']) {
            wp_send_json_success(array('message' => $test_result['message']));
        } else {
            wp_send_json_error(array('message' => $test_result['error']));
        }
    }

    /**
     * 決済オプション取得（強化版）
     *
     * @return array $acting_settings
     */
    protected function get_acting_settings() {
        global $usces;

        $acting_settings = (isset($usces->options['acting_settings'][$this->paymod_id]))
            ? $usces->options['acting_settings'][$this->paymod_id]
            : array();

        // デフォルト値をマージ
        $defaults = array(
            'activate' => 'off',
            'test_mode' => 'on',
            'payment_name' => 'クレジットカード決済（GrandPay）',
            'payment_description' => 'クレジットカードで安全にお支払いいただけます。',
            'auto_settlement' => 'off',
            'send_customer_info' => 'on',
            'debug_mode' => 'off'
        );

        return array_merge($defaults, $acting_settings);
    }
}

/**
 * 旧来の関数（後方互換性のため）- 強化版
 */
if (!function_exists('usces_get_settlement_info_grandpay')) {
    function usces_get_settlement_info_grandpay() {
        return array(
            'name'           => 'GrandPay',
            'company'        => 'GrandPay Asia Co., Ltd.',
            'version'        => '1.1.0',
            'correspondence' => 'JPY',
            'settlement'     => 'credit',
            'explanation'    => 'GrandPayクレジットカード決済サービス - OAuth2対応版',
            'note'           => 'アジア圏専用のセキュアなクレジットカード決済。リダイレクト型決済とWebhook通知に対応。',
            'country'        => 'JP,SG,MY,TH,ID,PH',
            'launch'         => true,
            'author'         => 'Welcart GrandPay Plugin Team',
            'features'       => array(
                'oauth2_authentication',
                'redirect_payment',
                'webhook_notification',
                'test_mode',
                'detailed_logging'
            )
        );
    }
}

// インスタンス作成
GRANDPAY_SETTLEMENT::get_instance();

error_log('GrandPay Settlement Module: Enhanced version loaded and initialized successfully');
