<?php

class WelcartGrandpayPaymentFront {

    public function __construct() {
        // Welcartのフロント側フィルターに登録
        add_filter('usces_filter_the_payment_method', array($this, 'add_payment_method'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX処理
        add_action('wp_ajax_grandpay_start_payment', array($this, 'ajax_start_payment'));
        add_action('wp_ajax_nopriv_grandpay_start_payment', array($this, 'ajax_start_payment'));

        error_log('GrandPay Front: Initialized');
    }

    /**
     * 決済方法リストにGrandPayを追加
     */
    public function add_payment_method($payment_methods) {
        global $usces;

        // GrandPay設定を取得
        $grandpay_options = $usces->options['acting_settings']['grandpay'] ?? array();

        // GrandPayが有効な場合のみ追加
        if (($grandpay_options['activate'] ?? 'off') === 'on') {
            $payment_name = $grandpay_options['payment_name'] ?? 'クレジットカード決済（GrandPay）';
            $payment_description = $grandpay_options['payment_description'] ?? 'クレジットカードで安全にお支払いいただけます。';

            $payment_methods['acting_grandpay_card'] = array(
                'name' => $payment_name,
                'explanation' => $payment_description,
                'settlement' => 'credit',
                'module' => 'grandpay',
                'sort' => 10
            );

            error_log('GrandPay Front: Payment method added - ' . $payment_name);

            // acting_flagも確認・設定
            $current_acting_flag = $usces->options['acting_settings']['acting_flag'] ?? '';
            if ($current_acting_flag !== 'grandpay') {
                error_log('GrandPay Front: Warning - acting_flag is not set to grandpay (current: ' . $current_acting_flag . ')');
            }
        } else {
            error_log('GrandPay Front: Payment method not added (not activated)');
        }

        return $payment_methods;
    }

    /**
     * スクリプトとスタイルの読み込み
     */
    public function enqueue_scripts() {
        // Welcartのページかチェック
        if (!$this->is_welcart_page()) {
            return;
        }

        $version = (defined('WELCART_GRANDPAY_PAYMENT_DEVELOP') && true === WELCART_GRANDPAY_PAYMENT_DEVELOP) ? time() : WELCART_GRANDPAY_PAYMENT_VERSION;

        wp_register_style(WELCART_GRANDPAY_PAYMENT_SLUG . '-front', WELCART_GRANDPAY_PAYMENT_URL . '/css/front.css', array(), $version);
        wp_register_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-front', WELCART_GRANDPAY_PAYMENT_URL . '/js/front.js', array('jquery'), $version, true);

        wp_enqueue_style(WELCART_GRANDPAY_PAYMENT_SLUG . '-front');
        wp_enqueue_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-front');

        $front_data = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(WELCART_GRANDPAY_PAYMENT_SLUG),
            'messages' => array(
                'processing' => '決済処理中です...',
                'redirecting' => 'GrandPayの決済ページにリダイレクトしています...',
                'error' => '決済処理中にエラーが発生しました。'
            ),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        );
        wp_localize_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-front', 'grandpay_front', $front_data);

        error_log('GrandPay Front: Scripts enqueued');
    }

    /**
     * Welcartページかどうかチェック
     */
    private function is_welcart_page() {
        // カートページ、チェックアウトページ、完了ページなどをチェック
        global $usces;

        if (function_exists('usces_is_cart_page') && usces_is_cart_page()) {
            return true;
        }

        if (function_exists('usces_is_member_page') && usces_is_member_page()) {
            return true;
        }

        // URLベースでのチェック
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $welcart_pages = array('/cart/', '/checkout/', '/member/', '/complete/');

        foreach ($welcart_pages as $page) {
            if (strpos($current_url, $page) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * AJAX: 決済開始処理
     */
    public function ajax_start_payment() {
        check_ajax_referer(WELCART_GRANDPAY_PAYMENT_SLUG, 'nonce');

        error_log('GrandPay Front: AJAX start payment called');

        $order_id = intval($_POST['order_id'] ?? 0);

        if (!$order_id) {
            error_log('GrandPay Front: Invalid order ID');
            wp_send_json_error(array('message' => '注文IDが無効です'));
        }

        // 注文データを取得
        $order = get_post($order_id);
        if (!$order || $order->post_type !== 'shop_order') {
            error_log('GrandPay Front: Order not found: ' . $order_id);
            wp_send_json_error(array('message' => '注文が見つかりません'));
        }

        error_log('GrandPay Front: Processing order: ' . $order_id);

        // API通信
        $api = new WelcartGrandpayAPI();

        // 注文情報を構築
        $order_data = array(
            'order_id' => $order_id,
            'amount' => get_post_meta($order_id, '_order_total', true) ?: 1000, // デフォルト値
            'email' => get_post_meta($order_id, '_customer_email', true) ?: 'test@example.com',
            'phone' => get_post_meta($order_id, '_customer_tel', true) ?: '',
            'name' => get_post_meta($order_id, '_customer_name', true) ?: 'Test Customer',
            'success_url' => add_query_arg(array(
                'grandpay_result' => 'success',
                'order_id' => $order_id
            ), home_url('/checkout/complete/')),
            'failure_url' => add_query_arg(array(
                'grandpay_result' => 'failure',
                'order_id' => $order_id
            ), home_url('/checkout/'))
        );

        error_log('GrandPay Front: Order data: ' . print_r($order_data, true));

        $result = $api->create_checkout_session($order_data);

        if (isset($result['error'])) {
            error_log('GrandPay Front: Checkout session creation failed: ' . $result['error']);
            wp_send_json_error(array('message' => $result['error']));
        }

        if (isset($result['checkout_url'])) {
            // セッション情報を保存
            update_post_meta($order_id, '_grandpay_session_id', $result['session_id']);
            update_post_meta($order_id, '_grandpay_checkout_url', $result['checkout_url']);
            update_post_meta($order_id, '_grandpay_payment_status', 'pending');

            error_log('GrandPay Front: Checkout URL created: ' . $result['checkout_url']);

            wp_send_json_success(array('checkout_url' => $result['checkout_url']));
        }

        error_log('GrandPay Front: Unexpected error in checkout session creation');
        wp_send_json_error(array('message' => '予期しないエラーが発生しました'));
    }

    /**
     * 決済状況表示のショートコード
     */
    public function payment_status_shortcode($atts) {
        $atts = shortcode_atts(array(
            'order_id' => 0
        ), $atts);

        if (!$atts['order_id']) {
            return '<p>注文IDが指定されていません。</p>';
        }

        $order_id = intval($atts['order_id']);
        $payment_method = get_post_meta($order_id, '_payment_method', true);

        if ($payment_method !== 'grandpay') {
            return '<p>この注文はGrandPay決済ではありません。</p>';
        }

        $payment_status = get_post_meta($order_id, '_grandpay_payment_status', true);

        ob_start();
?>
        <div class="grandpay-payment-status">
            <?php if ($payment_status === 'pending'): ?>
                <div class="grandpay-status-pending">
                    <h3>⏳ 決済処理中</h3>
                    <p>決済処理を進めています。しばらくお待ちください。</p>
                </div>
            <?php elseif ($payment_status === 'completed'): ?>
                <div class="grandpay-status-completed">
                    <h3>✅ 決済完了</h3>
                    <p>決済が正常に完了しました。</p>
                </div>
            <?php elseif ($payment_status === 'failed'): ?>
                <div class="grandpay-status-failed">
                    <h3>❌ 決済失敗</h3>
                    <p>決済に失敗しました。お手数ですが、再度お試しください。</p>
                </div>
            <?php else: ?>
                <div class="grandpay-status-unknown">
                    <h3>❓ 状況不明</h3>
                    <p>決済状況が不明です。お手数ですが、サポートまでお問い合わせください。</p>
                </div>
            <?php endif; ?>
        </div>
<?php
        return ob_get_clean();
    }
}

// ショートコード登録
if (!shortcode_exists('grandpay_payment_status')) {
    add_shortcode('grandpay_payment_status', array(new WelcartGrandpayPaymentFront(), 'payment_status_shortcode'));
}
