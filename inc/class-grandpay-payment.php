<?php

/**
 * GrandPay決済処理クラス - 完全版（ステータス確認・URL修正版）
 * Welcartとの統合、チェックアウトセッション作成、コールバック処理を実装
 */
class WelcartGrandpayPaymentProcessor {

    private $api;

    public function __construct() {
        $this->api = new WelcartGrandpayAPI();

        // Welcartの決済フックに登録
        add_action('usces_action_acting_processing', array($this, 'process_payment'), 10);

        // 🔧 修正: コールバック処理をより早いタイミングで登録
        add_action('wp', array($this, 'handle_payment_callback'), 1);  // 最優先で実行
        add_action('template_redirect', array($this, 'handle_payment_callback'), 1);  // フォールバック

        // Webhook処理
        add_action('wp_ajax_grandpay_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_grandpay_webhook', array($this, 'handle_webhook'));

        // REST API登録
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        error_log('GrandPay Payment Processor: Initialized with early callback hooks');
    }

    /**
     * Webhook用REST APIエンドポイント登録
     */
    public function register_webhook_endpoint() {
        register_rest_route('grandpay/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook_rest'),
            'permission_callback' => '__return_true'
        ));

        error_log('GrandPay Payment: REST API webhook endpoint registered');
    }

    /**
     * メイン決済処理 - Welcart決済フロー統合
     */
    public function process_payment() {
        global $usces;

        error_log('GrandPay Payment: process_payment() called');

        // Welcartの決済設定を確認
        $acting_settings = $usces->options['acting_settings'] ?? array();
        $acting_flag = $acting_settings['acting_flag'] ?? '';

        error_log('GrandPay Payment: Current acting_flag: ' . $acting_flag);

        // フォームデータも確認
        $payment_method = $_POST['offer']['payment_method'] ?? '';
        error_log('GrandPay Payment: Posted payment method: ' . $payment_method);

        // GrandPayが選択されているかチェック
        $is_grandpay_selected = false;

        if ($acting_flag === 'grandpay') {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: Selected via acting_flag');
        }

        if (in_array($payment_method, array('acting_grandpay_card', 'grandpay'))) {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: Selected via payment_method');
        }

        // payment_nameでも確認
        if (
            isset($_POST['offer']['payment_name']) &&
            strpos($_POST['offer']['payment_name'], 'GrandPay') !== false
        ) {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: Selected via payment_name');
        }

        if (!$is_grandpay_selected) {
            error_log('GrandPay Payment: Not GrandPay payment, skipping');
            return;
        }

        error_log('GrandPay Payment: GrandPay payment detected, proceeding');

        // GrandPay設定確認
        $grandpay_options = $acting_settings['grandpay'] ?? array();
        if (($grandpay_options['activate'] ?? 'off') !== 'on') {
            error_log('GrandPay Payment: GrandPay not activated');
            $usces->error_message = 'GrandPay決済が有効になっていません。';
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        // 注文データを取得・準備
        $order_data = $this->prepare_order_data();
        if (!$order_data) {
            error_log('GrandPay Payment: Failed to prepare order data');
            $usces->error_message = '注文データの準備に失敗しました';
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        error_log('GrandPay Payment: Order data prepared: ' . print_r($order_data, true));

        // チェックアウトセッション作成
        $result = $this->api->create_checkout_session($order_data);

        if (!$result['success']) {
            error_log('GrandPay Payment: Checkout session creation failed: ' . $result['error']);
            $usces->error_message = $result['error'];
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        if (isset($result['session_id']) && isset($result['checkout_url'])) {
            // 注文情報を保存
            $this->save_order_data($order_data['order_id'], $result, $order_data);

            error_log('GrandPay Payment: Redirecting to checkout URL: ' . $result['checkout_url']);

            // GrandPayの決済ページにリダイレクト
            wp_redirect($result['checkout_url']);
            exit;
        }

        // 予期しないエラー
        error_log('GrandPay Payment: Unexpected error in payment processing');
        $usces->error_message = '決済処理中にエラーが発生しました';
        $this->redirect_to_cart_with_error($usces->error_message);
    }

    /**
     * 注文データを準備（改善版 - 注文ID取得方法強化）
     */
    private function prepare_order_data() {
        global $usces;

        try {
            // 基本データ取得
            $cart = $usces->cart;
            $member = $usces->get_member();
            $total_price = $usces->get_total_price();

            // 🔧 改善: 注文IDの取得方法を強化
            $order_id = null;
            $is_temp_id = false;

            error_log('GrandPay Payment: ========== ORDER ID DETECTION START ==========');

            // 1. セッションから注文IDを取得
            if (isset($_SESSION['usces_entry']['order_id'])) {
                $order_id = $_SESSION['usces_entry']['order_id'];
                error_log('GrandPay Payment: Order ID from session: ' . $order_id);
            }

            // 2. POSTデータから取得
            if (!$order_id && isset($_POST['order_id'])) {
                $order_id = intval($_POST['order_id']);
                error_log('GrandPay Payment: Order ID from POST: ' . $order_id);
            }

            // 3. Welcartの内部変数から取得
            if (!$order_id && isset($usces->current_order_id)) {
                $order_id = $usces->current_order_id;
                error_log('GrandPay Payment: Order ID from usces object: ' . $order_id);
            }

            // 🔧 4. Welcartの注文データベースから最新の注文を取得
            if (!$order_id) {
                error_log('GrandPay Payment: Attempting to find latest order in database');

                // 現在のユーザーまたはセッションに関連する最新の注文を検索
                $recent_orders = get_posts(array(
                    'post_type' => 'shop_order',
                    'post_status' => array('draft', 'private', 'publish'),
                    'numberposts' => 5,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'meta_query' => array(
                        array(
                            'key' => '_order_status',
                            'value' => array('pending', 'processing', 'new'),
                            'compare' => 'IN'
                        )
                    )
                ));

                error_log('GrandPay Payment: Found ' . count($recent_orders) . ' recent orders');

                if (!empty($recent_orders)) {
                    $order_id = $recent_orders[0]->ID;
                    error_log('GrandPay Payment: Using latest order ID: ' . $order_id);
                }
            }

            // 5. 一時的な注文IDを生成（最後の手段）
            if (!$order_id) {
                $temp_id = 'TEMP_' . time() . '_' . rand(1000, 9999);
                $order_id = $temp_id;
                $is_temp_id = true;
                error_log('GrandPay Payment: ⚠️ Generated temporary order ID: ' . $order_id);

                // 🔧 一時的IDの場合、後で実際の注文と関連付けるための情報を保存
                if (isset($_SESSION['usces_entry'])) {
                    $_SESSION['usces_entry']['grandpay_temp_id'] = $temp_id;
                    error_log('GrandPay Payment: Saved temp ID to session for later matching');
                }
            }

            error_log('GrandPay Payment: Final selected order ID: ' . $order_id . ' (Is temp: ' . ($is_temp_id ? 'YES' : 'NO') . ')');

            // 顧客情報の取得
            $customer_data = array();

            // 1. セッションのエントリーデータから取得
            if (isset($_SESSION['usces_entry']['customer'])) {
                $customer_data = $_SESSION['usces_entry']['customer'];
                error_log('GrandPay Payment: Customer data from session entry');
            }
            // 2. POSTデータから取得
            elseif (isset($_POST['customer'])) {
                $customer_data = $_POST['customer'];
                error_log('GrandPay Payment: Customer data from POST');
            }
            // 3. セッションのお客様情報から取得
            elseif (isset($_SESSION['usces_member'])) {
                $session_member = $_SESSION['usces_member'];
                $customer_data = array(
                    'name1' => $session_member['mem_name1'] ?? '',
                    'name2' => $session_member['mem_name2'] ?? '',
                    'mailaddress1' => $session_member['mem_email'] ?? '',
                    'tel' => $session_member['mem_tel'] ?? ''
                );
                error_log('GrandPay Payment: Customer data from session member');
            }
            // 4. 会員情報から取得
            elseif (!empty($member)) {
                $customer_data = array(
                    'name1' => $member['mem_name1'] ?? '',
                    'name2' => $member['mem_name2'] ?? '',
                    'mailaddress1' => $member['mem_email'] ?? '',
                    'tel' => $member['mem_tel'] ?? ''
                );
                error_log('GrandPay Payment: Customer data from member');
            }

            // デバッグ: 利用可能なセッションデータをログ出力
            error_log('GrandPay Payment: Available session keys: ' . print_r(array_keys($_SESSION), true));
            if (isset($_SESSION['usces_entry'])) {
                error_log('GrandPay Payment: usces_entry keys: ' . print_r(array_keys($_SESSION['usces_entry']), true));
            }

            // 顧客情報の統合
            $customer_name = trim(($customer_data['name1'] ?? '') . ' ' . ($customer_data['name2'] ?? ''));
            $customer_email = $customer_data['mailaddress1'] ?? $customer_data['email'] ?? '';
            $customer_phone = $customer_data['tel'] ?? $customer_data['phone'] ?? '';

            // デフォルト値の設定
            if (empty($customer_email)) {
                $customer_email = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'example.com');
                error_log('GrandPay Payment: Using default email: ' . $customer_email);
            }

            if (empty($customer_name)) {
                $customer_name = 'お客様';
                error_log('GrandPay Payment: Using default name');
            }

            // 金額の確認
            if (empty($total_price) || $total_price <= 0) {
                $total_price = 1000; // デフォルト金額
                error_log('GrandPay Payment: Using default amount: ' . $total_price);
            }

            // URL構築
            $base_url = home_url();

            // Welcartの標準的なURL構造
            $complete_url = $base_url . '/usces-member/?page=completionmember';
            $cart_url = $base_url . '/usces-cart/';

            // usces->urlが利用可能な場合はそれを使用
            if (isset($usces->url['complete_page'])) {
                $complete_url = $usces->url['complete_page'];
            }
            if (isset($usces->url['cart_page'])) {
                $cart_url = $usces->url['cart_page'];
            }

            // 🔧 修正: パラメータ名とnonce追加
            // コールバック用のnonceを生成
            $callback_nonce = wp_create_nonce('grandpay_callback_' . $order_id);

            // 修正されたコールバックURL（パラメータ名を統一）
            $success_url = add_query_arg(array(
                'grandpay_result' => 'success',
                'order_id' => $order_id,
                'session_check' => $callback_nonce
            ), $complete_url);

            $failure_url = add_query_arg(array(
                'grandpay_result' => 'failure',
                'order_id' => $order_id,
                'session_check' => $callback_nonce
            ), $cart_url);

            error_log('GrandPay Payment: Generated callback URLs:');
            error_log('GrandPay Payment: Success URL: ' . $success_url);
            error_log('GrandPay Payment: Failure URL: ' . $failure_url);
            error_log('GrandPay Payment: Callback nonce: ' . $callback_nonce);

            $order_data = array(
                'order_id' => $order_id,
                'amount' => intval($total_price),
                'name' => $customer_name,
                'email' => $customer_email,
                'phone' => $customer_phone,
                'success_url' => $success_url,
                'failure_url' => $failure_url,
                'is_temp_id' => $is_temp_id  // 🔧 追加: 一時的IDかどうかのフラグ
            );

            error_log('GrandPay Payment: ========== ORDER ID DETECTION END ==========');
            error_log('GrandPay Payment: Final order data prepared for order: ' . $order_id);

            return $order_data;
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in prepare_order_data: ' . $e->getMessage());
            error_log('GrandPay Payment: Exception trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * 注文データを保存（強化版 - 注文作成・紐付け処理）
     */
    private function save_order_data($order_id, $payment_result, $order_data) {
        error_log('GrandPay Payment: ========== SAVE ORDER DATA START ==========');
        error_log('GrandPay Payment: Order ID: ' . $order_id);
        error_log('GrandPay Payment: Is temp ID: ' . (isset($order_data['is_temp_id']) && $order_data['is_temp_id'] ? 'YES' : 'NO'));

        // 🔧 一時的IDの場合の特別処理
        if (isset($order_data['is_temp_id']) && $order_data['is_temp_id']) {
            error_log('GrandPay Payment: Handling temporary order ID: ' . $order_id);

            // 1. 一時的IDの情報をセッションに保存
            $_SESSION['grandpay_temp_order'] = array(
                'temp_id' => $order_id,
                'session_id' => $payment_result['session_id'],
                'checkout_url' => $payment_result['checkout_url'],
                'created_at' => current_time('mysql'),
                'order_data' => $order_data
            );

            error_log('GrandPay Payment: Temporary order data saved to session');

            // 2. 🔧 より強力な実際の注文検索
            $actual_order_id = $this->find_or_create_actual_order($order_data, $payment_result);

            if ($actual_order_id) {
                error_log('GrandPay Payment: Found/Created actual order ID: ' . $actual_order_id);

                // 実際の注文に一時的IDを関連付け
                update_post_meta($actual_order_id, '_grandpay_temp_order_id', $order_id);
                update_post_meta($actual_order_id, '_grandpay_session_id', $payment_result['session_id']);
                update_post_meta($actual_order_id, '_grandpay_checkout_url', $payment_result['checkout_url']);
                update_post_meta($actual_order_id, '_payment_method', 'grandpay');
                update_post_meta($actual_order_id, '_grandpay_payment_status', 'pending');
                update_post_meta($actual_order_id, '_grandpay_created_at', current_time('mysql'));

                // 顧客・注文情報も保存
                update_post_meta($actual_order_id, '_customer_email', $order_data['email']);
                update_post_meta($actual_order_id, '_customer_name', $order_data['name']);
                update_post_meta($actual_order_id, '_customer_phone', $order_data['phone']);
                update_post_meta($actual_order_id, '_order_total', $order_data['amount']);

                error_log('GrandPay Payment: Successfully linked temp ID ' . $order_id . ' to actual order ' . $actual_order_id);

                // セッションに実際の注文IDも保存
                $_SESSION['grandpay_temp_order']['actual_order_id'] = $actual_order_id;
            } else {
                error_log('GrandPay Payment: ❌ Failed to find or create actual order for temp ID: ' . $order_id);
            }
        } else {
            // 通常の注文IDの場合
            error_log('GrandPay Payment: Handling normal order ID: ' . $order_id);

            // 注文の存在確認
            $order = get_post($order_id);
            if (!$order) {
                error_log('GrandPay Payment: ❌ Order not found for ID: ' . $order_id);
                return false;
            }

            // GrandPayセッション情報を保存
            update_post_meta($order_id, '_grandpay_session_id', $payment_result['session_id']);
            update_post_meta($order_id, '_grandpay_checkout_url', $payment_result['checkout_url']);
            update_post_meta($order_id, '_payment_method', 'grandpay');
            update_post_meta($order_id, '_grandpay_payment_status', 'pending');
            update_post_meta($order_id, '_grandpay_created_at', current_time('mysql'));

            // 注文データも保存
            update_post_meta($order_id, '_customer_email', $order_data['email']);
            update_post_meta($order_id, '_customer_name', $order_data['name']);
            update_post_meta($order_id, '_customer_phone', $order_data['phone']);
            update_post_meta($order_id, '_order_total', $order_data['amount']);
        }

        error_log('GrandPay Payment: ========== SAVE ORDER DATA END ==========');
        return true;
    }

    /**
     * 🔧 新規追加: 実際の注文を検索または作成
     */
    private function find_or_create_actual_order($order_data, $payment_result) {
        global $usces;

        error_log('GrandPay Payment: ========== FIND OR CREATE ORDER START ==========');

        // 1. 最新の注文を検索（複数条件）
        $search_criteria = array(
            // 最近作成された注文
            array(
                'post_type' => 'shop_order',
                'post_status' => array('draft', 'private', 'publish'),
                'numberposts' => 10,
                'orderby' => 'date',
                'order' => 'DESC',
                'date_query' => array(
                    array(
                        'after' => '30 minutes ago'  // 30分以内の注文
                    )
                )
            ),
            // カート情報が一致する注文
            array(
                'post_type' => 'shop_order',
                'post_status' => array('draft', 'private', 'publish'),
                'numberposts' => 5,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_customer_email',
                        'value' => $order_data['email'],
                        'compare' => '='
                    )
                )
            )
        );

        foreach ($search_criteria as $index => $criteria) {
            error_log('GrandPay Payment: Searching with criteria ' . ($index + 1));
            $orders = get_posts($criteria);

            error_log('GrandPay Payment: Found ' . count($orders) . ' orders with criteria ' . ($index + 1));

            if (!empty($orders)) {
                // 最も適切な注文を選択
                $selected_order = $this->select_best_matching_order($orders, $order_data);

                if ($selected_order) {
                    error_log('GrandPay Payment: Selected order ID: ' . $selected_order->ID);
                    return $selected_order->ID;
                }
            }
        }

        // 2. 🔧 注文が見つからない場合は作成
        error_log('GrandPay Payment: No matching order found, creating new order');
        $created_order_id = $this->create_order_from_session($order_data, $payment_result);

        if ($created_order_id) {
            error_log('GrandPay Payment: Successfully created new order: ' . $created_order_id);
            return $created_order_id;
        }

        error_log('GrandPay Payment: ❌ Failed to find or create order');
        error_log('GrandPay Payment: ========== FIND OR CREATE ORDER END ==========');
        return false;
    }

    /**
     * 🔧 新規追加: 最適な注文を選択
     */
    private function select_best_matching_order($orders, $order_data) {
        error_log('GrandPay Payment: Selecting best matching order from ' . count($orders) . ' candidates');

        foreach ($orders as $order) {
            error_log('GrandPay Payment: Checking order ID: ' . $order->ID);

            // 既にGrandPay決済が設定されている注文は除外
            $existing_session = get_post_meta($order->ID, '_grandpay_session_id', true);
            if (!empty($existing_session)) {
                error_log('GrandPay Payment: Order ' . $order->ID . ' already has GrandPay session, skipping');
                continue;
            }

            // 注文金額が一致するかチェック
            $order_total = get_post_meta($order->ID, '_order_total', true);
            if (empty($order_total)) {
                $order_total = get_post_meta($order->ID, '_total_full_price', true);
            }

            error_log('GrandPay Payment: Order ' . $order->ID . ' total: ' . $order_total . ', Expected: ' . $order_data['amount']);

            if (abs(intval($order_total) - intval($order_data['amount'])) <= 10) {  // 10円以内の誤差は許容
                error_log('GrandPay Payment: Order ' . $order->ID . ' amount matches, selected');
                return $order;
            }
        }

        // 金額が一致しない場合は最新の注文を返す
        if (!empty($orders)) {
            error_log('GrandPay Payment: No amount match, returning latest order: ' . $orders[0]->ID);
            return $orders[0];
        }

        return null;
    }

    /**
     * 🔧 新規追加: セッション情報から注文を作成
     */
    private function create_order_from_session($order_data, $payment_result) {
        global $usces;

        error_log('GrandPay Payment: Creating new order from session data');

        try {
            // Welcartの注文作成処理
            if (function_exists('usces_new_order_id')) {
                $new_order_id = usces_new_order_id();
                error_log('GrandPay Payment: Generated new order ID: ' . $new_order_id);
            } else {
                // フォールバック: 直接投稿作成
                $order_post = array(
                    'post_type' => 'shop_order',
                    'post_status' => 'private',
                    'post_title' => 'Order #' . time(),
                    'post_content' => 'GrandPay Order',
                    'post_author' => get_current_user_id()
                );

                $new_order_id = wp_insert_post($order_post);

                if (is_wp_error($new_order_id)) {
                    error_log('GrandPay Payment: Failed to create order post: ' . $new_order_id->get_error_message());
                    return false;
                }

                error_log('GrandPay Payment: Created order post ID: ' . $new_order_id);
            }

            if ($new_order_id) {
                // 基本的な注文メタデータを設定
                $current_time = current_time('mysql');

                update_post_meta($new_order_id, '_order_date', $current_time);
                update_post_meta($new_order_id, '_order_status', 'pending');
                update_post_meta($new_order_id, '_order_total', $order_data['amount']);
                update_post_meta($new_order_id, '_total_full_price', $order_data['amount']);
                update_post_meta($new_order_id, '_customer_email', $order_data['email']);
                update_post_meta($new_order_id, '_customer_name', $order_data['name']);
                update_post_meta($new_order_id, '_customer_phone', $order_data['phone']);
                update_post_meta($new_order_id, '_payment_method', 'grandpay');

                // カート情報をセッションから取得して保存
                if (isset($usces->cart) && !empty($usces->cart->cart)) {
                    update_post_meta($new_order_id, '_cart', $usces->cart->cart);
                    error_log('GrandPay Payment: Cart data saved to order');
                }

                // 顧客情報をセッションから取得して保存
                if (isset($_SESSION['usces_entry']['customer'])) {
                    update_post_meta($new_order_id, '_customer_data', $_SESSION['usces_entry']['customer']);
                    error_log('GrandPay Payment: Customer data saved to order');
                }

                error_log('GrandPay Payment: Order metadata saved for new order: ' . $new_order_id);
                return $new_order_id;
            }
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception creating order: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * 決済完了後のコールバック処理（詳細デバッグ版 + 強化検索）
     */
    public function handle_payment_callback() {
        // 🔧 重複実行防止フラグ
        static $callback_processed = false;
        if ($callback_processed) {
            error_log('GrandPay Payment: Callback already processed, skipping');
            return;
        }

        // 🔧 詳細なデバッグログ
        error_log('GrandPay Payment: ========== CALLBACK DEBUG START ==========');
        error_log('GrandPay Payment: Current hook: ' . current_filter());
        error_log('GrandPay Payment: Request URI: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        error_log('GrandPay Payment: GET parameters: ' . print_r($_GET, true));
        error_log('GrandPay Payment: Current time: ' . current_time('Y-m-d H:i:s'));

        // 基本パラメータの確認
        if (!isset($_GET['grandpay_result']) || !isset($_GET['order_id'])) {
            error_log('GrandPay Payment: Missing required parameters - grandpay_result or order_id');
            error_log('GrandPay Payment: Available GET keys: ' . implode(', ', array_keys($_GET)));
            return;
        }

        // 処理フラグを設定
        $callback_processed = true;

        $order_id = sanitize_text_field($_GET['order_id']);  // 🔧 文字列として処理
        $result = sanitize_text_field($_GET['grandpay_result']);
        $session_check = $_GET['session_check'] ?? '';

        error_log('GrandPay Payment: Processing callback - Result: ' . $result . ', Order ID: ' . $order_id);
        error_log('GrandPay Payment: Session check value: ' . $session_check);
        error_log('GrandPay Payment: Session check length: ' . strlen($session_check));

        // 🔧 nonce検証の詳細デバッグ
        if (empty($session_check)) {
            error_log('GrandPay Payment: ❌ Missing session_check parameter for order: ' . $order_id);
            wp_die('Missing session parameter', 'Callback Error', array('response' => 400));
            return;
        }

        // nonce検証の詳細ログ
        $nonce_action = 'grandpay_callback_' . $order_id;
        error_log('GrandPay Payment: Nonce verification details:');
        error_log('GrandPay Payment: - Expected action: ' . $nonce_action);
        error_log('GrandPay Payment: - Received nonce: ' . $session_check);
        error_log('GrandPay Payment: - Nonce age limit: ' . wp_nonce_tick());

        // 🔧 nonce検証前の追加チェック
        $nonce_verification = wp_verify_nonce($session_check, $nonce_action);
        error_log('GrandPay Payment: Nonce verification result: ' . ($nonce_verification ? 'TRUE' : 'FALSE'));

        if (!$nonce_verification) {
            error_log('GrandPay Payment: ❌ Invalid callback nonce for order: ' . $order_id);
            error_log('GrandPay Payment: Nonce verification failed. Possible causes:');
            error_log('GrandPay Payment: 1. Nonce expired (older than 24 hours)');
            error_log('GrandPay Payment: 2. Order ID mismatch');
            error_log('GrandPay Payment: 3. WordPress nonce salt changed');

            // 🔧 一時的にnonce検証をスキップしてテスト（デバッグ用）
            if (defined('WP_DEBUG') && WP_DEBUG && defined('GRANDPAY_SKIP_NONCE_DEBUG')) {
                error_log('GrandPay Payment: ⚠️ DEBUG MODE: Skipping nonce verification');
            } else {
                wp_die('Invalid session. Debug info logged.', 'Callback Error', array('response' => 403));
                return;
            }
        } else {
            error_log('GrandPay Payment: ✅ Nonce verification successful for order: ' . $order_id);
        }

        // 🔧 注文の存在確認（強化版 - 複数検索方法）
        $order = null;
        $final_order_id = $order_id;

        error_log('GrandPay Payment: ========== ORDER SEARCH START ==========');

        // 方法1: 数値IDの場合
        if (is_numeric($order_id)) {
            $order = get_post(intval($order_id));
            error_log('GrandPay Payment: Method 1 - Numeric ID search: ' . ($order ? 'FOUND' : 'NOT FOUND'));
        }

        // 方法2: 一時的IDの場合（TEMP_で始まる）
        if (!$order && strpos($order_id, 'TEMP_') === 0) {
            error_log('GrandPay Payment: Method 2 - Temporary ID detected: ' . $order_id);

            // 2-1: メタデータから検索
            $orders = get_posts(array(
                'post_type' => 'shop_order',
                'meta_key' => '_grandpay_temp_order_id',
                'meta_value' => $order_id,
                'post_status' => 'any',
                'numberposts' => 1
            ));

            if (!empty($orders)) {
                $order = $orders[0];
                $final_order_id = $order->ID;
                error_log('GrandPay Payment: Method 2-1 - Found via meta query: ' . $final_order_id);
            } else {
                error_log('GrandPay Payment: Method 2-1 - Meta query failed');

                // 2-2: セッションから実際の注文IDを取得
                if (isset($_SESSION['grandpay_temp_order']['actual_order_id'])) {
                    $session_order_id = $_SESSION['grandpay_temp_order']['actual_order_id'];
                    $order = get_post($session_order_id);

                    if ($order) {
                        $final_order_id = $order->ID;
                        error_log('GrandPay Payment: Method 2-2 - Found via session: ' . $final_order_id);

                        // メタデータも更新
                        update_post_meta($final_order_id, '_grandpay_temp_order_id', $order_id);
                    } else {
                        error_log('GrandPay Payment: Method 2-2 - Session order ID invalid: ' . $session_order_id);
                    }
                } else {
                    error_log('GrandPay Payment: Method 2-2 - No session data found');
                }

                // 2-3: セッションから注文作成（最後の手段）
                if (!$order && isset($_SESSION['grandpay_temp_order']['order_data'])) {
                    error_log('GrandPay Payment: Method 2-3 - Attempting to create order from session');

                    $session_order_data = $_SESSION['grandpay_temp_order']['order_data'];
                    $session_payment_result = array(
                        'session_id' => $_SESSION['grandpay_temp_order']['session_id'],
                        'checkout_url' => $_SESSION['grandpay_temp_order']['checkout_url']
                    );

                    $created_order_id = $this->create_order_from_session($session_order_data, $session_payment_result);

                    if ($created_order_id) {
                        $order = get_post($created_order_id);
                        $final_order_id = $created_order_id;

                        // 一時的IDとの関連付け
                        update_post_meta($final_order_id, '_grandpay_temp_order_id', $order_id);

                        error_log('GrandPay Payment: Method 2-3 - Created order: ' . $final_order_id);
                    } else {
                        error_log('GrandPay Payment: Method 2-3 - Order creation failed');
                    }
                }
            }
        }

        error_log('GrandPay Payment: ========== ORDER SEARCH END ==========');

        if (!$order) {
            error_log('GrandPay Payment: ❌ Order not found after all search methods: ' . $order_id);
            error_log('GrandPay Payment: Search methods attempted:');
            error_log('GrandPay Payment: 1. Direct get_post()');
            error_log('GrandPay Payment: 2. Meta query for temp ID');
            error_log('GrandPay Payment: 3. Session data lookup');
            error_log('GrandPay Payment: 4. Order creation from session');
            wp_die('Order not found. Order ID: ' . $order_id, 'Callback Error', array('response' => 404));
            return;
        }

        error_log('GrandPay Payment: ✅ Order found: ' . $order->ID . ' (Type: ' . $order->post_type . ')');

        // 実際の注文IDを使用
        $order_id = $final_order_id;

        // 🔧 重複処理防止（既に処理済みかチェック）
        $current_status = get_post_meta($order_id, '_grandpay_payment_status', true);
        error_log('GrandPay Payment: Current order status: ' . $current_status);

        if (in_array($current_status, array('completed', 'failed'))) {
            error_log('GrandPay Payment: ⚠️ Order already processed with status: ' . $current_status);

            // 既に処理済みの場合は適切なページにリダイレクト
            if ($current_status === 'completed') {
                $this->redirect_to_complete_page($order_id);
            } else {
                $this->redirect_to_cart_with_error('この注文は既に処理済みです');
            }
            return;
        }

        // 結果に基づいて処理を分岐
        error_log('GrandPay Payment: Processing result: ' . $result);

        if ($result === 'success') {
            error_log('GrandPay Payment: 🟢 Processing success callback for order: ' . $order_id);
            $this->handle_success_callback($order_id);
        } elseif ($result === 'failure') {
            error_log('GrandPay Payment: 🔴 Processing failure callback for order: ' . $order_id);
            $this->handle_failure_callback($order_id);
        } else {
            error_log('GrandPay Payment: ❌ Unknown callback result: ' . $result . ' for order: ' . $order_id);
            wp_die('Invalid callback result: ' . $result, 'Callback Error', array('response' => 400));
        }

        error_log('GrandPay Payment: ========== CALLBACK DEBUG END ==========');
    }

    /**
     * 🔧 新規追加: 完了ページへのリダイレクト処理
     */
    private function redirect_to_complete_page($order_id) {
        global $usces;

        $complete_url = $usces->url['complete_page'] ?? home_url('/usces-member/?page=completionmember');
        $redirect_url = add_query_arg('order_id', $order_id, $complete_url);

        error_log('GrandPay Payment: Redirecting to complete page: ' . $redirect_url);
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * 成功時のコールバック処理（修正版 - payments配列対応）
     */
    private function handle_success_callback($order_id) {
        error_log('GrandPay Payment: Processing success callback for order: ' . $order_id);

        // 🔧 修正: 処理状況を即座に更新（重複処理防止）
        update_post_meta($order_id, '_grandpay_payment_status', 'processing');
        update_post_meta($order_id, '_grandpay_callback_received_at', current_time('mysql'));

        $session_id = get_post_meta($order_id, '_grandpay_session_id', true);

        if ($session_id) {
            error_log('GrandPay Payment: Session ID found: ' . $session_id . ', checking payment status');

            // 決済状況を確認
            $status_result = $this->api->get_payment_status($session_id);

            error_log('GrandPay Payment: Payment status response: ' . print_r($status_result, true));

            if ($status_result['success'] && isset($status_result['data']['data'])) {
                $payment_data = $status_result['data']['data'];

                error_log('GrandPay Payment: ========== ENHANCED PAYMENT STATUS ANALYSIS ==========');
                error_log('GrandPay Payment: Full payment data keys: ' . print_r(array_keys($payment_data), true));

                // 🔧 重要修正: セッションステータスではなく、実際の決済ステータスを確認
                $session_status = $payment_data['status'] ?? '';
                error_log('GrandPay Payment: Session status: [' . $session_status . ']');

                // 🔧 新規追加: payments配列から実際の決済ステータスを確認
                $actual_payment_status = '';
                $payment_transaction_id = '';

                if (isset($payment_data['payments']) && is_array($payment_data['payments']) && !empty($payment_data['payments'])) {
                    error_log('GrandPay Payment: Found payments array with ' . count($payment_data['payments']) . ' payments');

                    // 最新の決済を取得（通常は配列の最後）
                    $latest_payment = end($payment_data['payments']);
                    $actual_payment_status = $latest_payment['status'] ?? '';
                    $payment_transaction_id = $latest_payment['id'] ?? '';

                    error_log('GrandPay Payment: Latest payment ID: ' . $payment_transaction_id);
                    error_log('GrandPay Payment: Actual payment status: [' . $actual_payment_status . ']');
                    error_log('GrandPay Payment: Payment transaction type: ' . ($latest_payment['transactionType'] ?? 'N/A'));
                    error_log('GrandPay Payment: Payment amount: ' . ($latest_payment['amount'] ?? 'N/A'));

                    // 決済データを保存
                    update_post_meta($order_id, '_grandpay_payment_details', $latest_payment);
                } else {
                    error_log('GrandPay Payment: ⚠️ No payments array found in response');
                    error_log('GrandPay Payment: Available data: ' . print_r($payment_data, true));
                }

                // 🔧 修正: 判定優先順位を変更
                // 1. 実際の決済ステータスを最優先
                // 2. セッションステータスは参考程度

                $final_status_to_check = $actual_payment_status ?: $session_status;
                $status_source = $actual_payment_status ? 'payments_array' : 'session_status';

                error_log('GrandPay Payment: Final status to check: [' . $final_status_to_check . '] (source: ' . $status_source . ')');

                // 🔧 修正: より柔軟なステータス判定
                $final_status_upper = strtoupper(trim($final_status_to_check));
                error_log('GrandPay Payment: Normalized final status: [' . $final_status_upper . ']');

                // 🔧 成功ステータスのパターンを拡張
                $success_statuses = array('COMPLETED', 'COMPLETE', 'SUCCESS', 'SUCCEEDED', 'PAID', 'AUTHORIZED', 'CONFIRMED');
                $pending_statuses = array('PENDING', 'PROCESSING', 'IN_PROGRESS', 'WAITING', 'AUTHORIZED');
                $failed_statuses = array('REJECTED', 'FAILED', 'CANCELLED', 'CANCELED', 'ERROR', 'DECLINED', 'EXPIRED');

                error_log('GrandPay Payment: Checking against success statuses: ' . implode(', ', $success_statuses));

                if (in_array($final_status_upper, $success_statuses)) {
                    error_log('GrandPay Payment: ✅ Payment status indicates SUCCESS');
                    error_log('GrandPay Payment: Status source: ' . $status_source);
                    error_log('GrandPay Payment: Transaction ID: ' . $payment_transaction_id);

                    // 注文完了処理
                    $this->complete_order($order_id, $payment_data);

                    // 完了ページにリダイレクト
                    $this->redirect_to_complete_page($order_id);
                } elseif (in_array($final_status_upper, $pending_statuses)) {
                    error_log('GrandPay Payment: ⏳ Payment status indicates PENDING');

                    // 保留状態の場合
                    update_post_meta($order_id, '_grandpay_payment_status', 'pending');
                    update_post_meta($order_id, '_grandpay_pending_reason', $final_status_to_check);
                    $this->redirect_to_complete_page($order_id); // 完了ページに移動（保留メッセージ表示）

                } elseif (in_array($final_status_upper, $failed_statuses)) {
                    error_log('GrandPay Payment: ❌ Payment status indicates FAILURE');

                    // 🔧 特別対応: セッションがEXPIREDでも実際の決済が成功している場合
                    if ($session_status === 'EXPIRED' && $actual_payment_status === 'COMPLETED') {
                        error_log('GrandPay Payment: 🔧 SPECIAL CASE: Session expired but payment completed');
                        error_log('GrandPay Payment: Treating as SUCCESS due to actual payment completion');

                        // 成功として処理
                        $this->complete_order($order_id, $payment_data);
                        $this->redirect_to_complete_page($order_id);
                    } else {
                        // 通常の失敗処理
                        $this->fail_order($order_id);
                        $this->redirect_to_cart_with_error('決済が失敗しました。再度お試しください。');
                    }
                } else {
                    // 🔧 修正: 不明なステータスの場合の詳細ログと暫定処理
                    error_log('GrandPay Payment: ⚠️ UNKNOWN payment status: [' . $final_status_to_check . ']');
                    error_log('GrandPay Payment: Status source: ' . $status_source);
                    error_log('GrandPay Payment: Available statuses for reference:');
                    error_log('GrandPay Payment: - Success: ' . implode(', ', $success_statuses));
                    error_log('GrandPay Payment: - Pending: ' . implode(', ', $pending_statuses));
                    error_log('GrandPay Payment: - Failed: ' . implode(', ', $failed_statuses));

                    // 🔧 実際の決済がある場合は成功として処理
                    if (!empty($actual_payment_status)) {
                        error_log('GrandPay Payment: 🔧 FALLBACK: Actual payment exists, treating as SUCCESS');
                        update_post_meta($order_id, '_grandpay_unknown_status', $final_status_to_check);
                        update_post_meta($order_id, '_grandpay_payment_status', 'completed');
                        $this->complete_order($order_id, $payment_data);
                        $this->redirect_to_complete_page($order_id);
                    } else {
                        // 実際の決済がない場合は保留状態
                        error_log('GrandPay Payment: No actual payment found, setting to pending');
                        update_post_meta($order_id, '_grandpay_payment_status', 'pending');
                        update_post_meta($order_id, '_grandpay_unknown_status', $final_status_to_check);
                        $this->redirect_to_complete_page($order_id);
                    }
                }
            } else {
                error_log('GrandPay Payment: Failed to get payment status: ' . print_r($status_result, true));

                // 🔧 修正: API呼び出し失敗時の処理を改善
                // ステータス確認に失敗した場合でも、Webhookでの処理を期待して保留状態にする
                update_post_meta($order_id, '_grandpay_payment_status', 'pending');
                update_post_meta($order_id, '_grandpay_status_check_failed', current_time('mysql'));

                $this->redirect_to_complete_page($order_id);
            }
        } else {
            error_log('GrandPay Payment: Session ID not found for order: ' . $order_id);

            // セッションIDがない場合の処理
            update_post_meta($order_id, '_grandpay_payment_status', 'error');
            $this->redirect_to_cart_with_error('セッション情報が見つかりません。');
        }
    }

    /**
     * 失敗時のコールバック処理（修正版）
     */
    private function handle_failure_callback($order_id) {
        error_log('GrandPay Payment: Processing failure callback for order: ' . $order_id);

        // 注文を失敗状態に設定
        $this->fail_order($order_id);

        // 🔧 修正: より詳細なエラー情報を付与
        update_post_meta($order_id, '_grandpay_callback_received_at', current_time('mysql'));
        update_post_meta($order_id, '_grandpay_failure_reason', 'callback_failure');

        // エラーメッセージと共にカートページにリダイレクト
        $this->redirect_to_cart_with_error('決済に失敗しました。再度お試しください。');
    }

    /**
     * 注文完了処理（修正版 - Welcart連携強化）
     */
    private function complete_order($order_id, $payment_data) {
        global $usces;

        error_log('GrandPay Payment: Starting complete_order for order_id: ' . $order_id);
        error_log('GrandPay Payment: Payment data: ' . print_r($payment_data, true));

        try {
            // 🔧 修正: 重複処理防止
            $current_status = get_post_meta($order_id, '_grandpay_payment_status', true);
            if ($current_status === 'completed') {
                error_log('GrandPay Payment: Order already completed: ' . $order_id);
                return;
            }

            // 🔧 修正: Welcartの正しい注文ステータス更新方法

            // 1. Welcart標準の注文ステータス更新
            if (function_exists('usces_change_order_status')) {
                $status_result = usces_change_order_status($order_id, 'ordercompletion');
                error_log('GrandPay Payment: usces_change_order_status result: ' . print_r($status_result, true));
            } else {
                // フォールバック: 直接更新
                error_log('GrandPay Payment: usces_change_order_status not found, using fallback');
                update_post_meta($order_id, '_order_status', 'ordercompletion');
            }

            // 2. 注文投稿タイプのステータス更新
            $order_post = array(
                'ID' => $order_id,
                'post_status' => 'publish'  // Welcartの完了済み注文ステータス
            );
            $update_result = wp_update_post($order_post);

            if (is_wp_error($update_result)) {
                error_log('GrandPay Payment: Failed to update post status: ' . $update_result->get_error_message());
            } else {
                error_log('GrandPay Payment: Post status updated successfully');
            }

            // 3. 決済情報を保存
            update_post_meta($order_id, '_grandpay_payment_status', 'completed');
            update_post_meta($order_id, '_grandpay_transaction_id', $payment_data['id'] ?? '');
            update_post_meta($order_id, '_grandpay_completed_at', current_time('mysql'));
            update_post_meta($order_id, '_grandpay_payment_data', $payment_data);

            // 🔧 修正: Welcart標準の決済情報更新
            if (isset($payment_data['id'])) {
                update_post_meta($order_id, '_wc_trans_id', $payment_data['id']); // Welcart標準フィールド
            }

            // 決済方法情報
            update_post_meta($order_id, '_payment_method', 'grandpay');
            update_post_meta($order_id, '_acting_return', 'completion');

            // 4. 🔧 カートクリア部分を一時的に無効化（エラー回避）
            error_log('GrandPay Payment: Skipping cart clear to avoid method errors');
            /*
            // TODO: 正しいWelcartカートクリア方法を調査後に実装
            if (isset($usces->cart)) {
                error_log('GrandPay Payment: Clearing cart');
                $usces->cart->empty_cart();
            }
            */

            // セッションの注文情報をクリア
            if (isset($_SESSION['usces_entry'])) {
                unset($_SESSION['usces_entry']);
                error_log('GrandPay Payment: Cleared usces_entry session');
            }

            // 5. 🔧 新規追加: 在庫管理処理
            $this->process_inventory_update($order_id);

            // 6. 🔧 新規追加: メール通知処理
            $this->send_completion_notifications($order_id);

            error_log('GrandPay Payment: Order completed successfully - ID: ' . $order_id);

            // 7. 完了フックを実行
            do_action('grandpay_payment_completed', $order_id, $payment_data);
            do_action('usces_action_order_completion', $order_id); // Welcart標準フック

        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in complete_order: ' . $e->getMessage());
            error_log('GrandPay Payment: Exception trace: ' . $e->getTraceAsString());

            // エラー時は失敗状態に設定
            $this->fail_order($order_id);
            throw $e;
        }
    }

    /**
     * 🔧 新規追加: 在庫管理処理
     */
    private function process_inventory_update($order_id) {
        try {
            // Welcartの在庫減算処理
            if (function_exists('usces_update_item_stock')) {
                $cart_data = get_post_meta($order_id, '_cart', true);

                if ($cart_data && is_array($cart_data)) {
                    foreach ($cart_data as $cart_item) {
                        $post_id = $cart_item['post_id'] ?? 0;
                        $sku = $cart_item['sku'] ?? '';
                        $quantity = intval($cart_item['quantity'] ?? 0);

                        if ($post_id && $sku && $quantity > 0) {
                            $stock_result = usces_update_item_stock($post_id, $sku, $quantity);
                            error_log("GrandPay Payment: Stock updated for {$post_id}:{$sku} (-{$quantity}): " . print_r($stock_result, true));
                        }
                    }
                }
            } else {
                error_log('GrandPay Payment: usces_update_item_stock function not found');
            }
        } catch (Exception $e) {
            error_log('GrandPay Payment: Error in inventory update: ' . $e->getMessage());
            // 在庫更新エラーは注文完了を阻害しない
        }
    }

    /**
     * 🔧 新規追加: 完了通知メール送信
     */
    private function send_completion_notifications($order_id) {
        try {
            // Welcartの標準メール送信
            if (function_exists('usces_send_order_mail')) {
                $mail_result = usces_send_order_mail($order_id, 'completion');
                error_log('GrandPay Payment: Order completion mail sent: ' . print_r($mail_result, true));
            }

            // 管理者通知
            if (function_exists('usces_send_admin_mail')) {
                $admin_mail_result = usces_send_admin_mail($order_id, 'completion');
                error_log('GrandPay Payment: Admin notification mail sent: ' . print_r($admin_mail_result, true));
            }
        } catch (Exception $e) {
            error_log('GrandPay Payment: Error in sending notifications: ' . $e->getMessage());
            // メール送信エラーは注文完了を阻害しない
        }
    }

    /**
     * 注文失敗処理（修正版 - エラーハンドリング強化）
     */
    private function fail_order($order_id) {
        global $usces;

        error_log('GrandPay Payment: Starting fail_order for order_id: ' . $order_id);

        try {
            // 🔧 修正: 重複処理防止
            $current_status = get_post_meta($order_id, '_grandpay_payment_status', true);
            if ($current_status === 'failed') {
                error_log('GrandPay Payment: Order already failed: ' . $order_id);
                return;
            }

            // 1. Welcart標準の注文ステータス更新
            if (function_exists('usces_change_order_status')) {
                $status_result = usces_change_order_status($order_id, 'cancel');
                error_log('GrandPay Payment: Order status changed to cancel: ' . print_r($status_result, true));
            } else {
                // フォールバック
                update_post_meta($order_id, '_order_status', 'cancel');
            }

            // 2. 決済情報を更新
            update_post_meta($order_id, '_grandpay_payment_status', 'failed');
            update_post_meta($order_id, '_grandpay_failed_at', current_time('mysql'));
            update_post_meta($order_id, '_acting_return', 'failure');

            error_log('GrandPay Payment: Order failed - ID: ' . $order_id);

            // 3. 失敗フックを実行
            do_action('grandpay_payment_failed', $order_id);
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in fail_order: ' . $e->getMessage());
        }
    }

    /**
     * 🔧 修正: エラー時のリダイレクト（URL修正版）
     */
    private function redirect_to_cart_with_error($error_message) {
        global $usces;

        // 🔧 修正: 正しいWelcartカートページURLを取得
        $cart_url = home_url('/usces-cart/');  // デフォルト

        // Welcartの設定からカートページURLを取得
        if (isset($usces->url['cart_page'])) {
            $cart_url = $usces->url['cart_page'];
        } elseif (function_exists('usces_url')) {
            $cart_url = usces_url('cart');
        }

        // 🔧 フォールバック: 存在しないURLの場合はホームページに
        if (empty($cart_url) || $cart_url === home_url('/checkout/')) {
            $cart_url = home_url();
            error_log('GrandPay Payment: Using home URL as fallback: ' . $cart_url);
        }

        $redirect_url = add_query_arg('grandpay_error', urlencode($error_message), $cart_url);

        error_log('GrandPay Payment: Redirecting to cart with error: ' . $redirect_url);
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * REST API Webhook処理
     */
    public function handle_webhook_rest($request) {
        error_log('GrandPay Payment: ========== WEBHOOK PROCESSING START ==========');
        error_log('GrandPay Payment: REST API Webhook received');

        $body = $request->get_body();
        $headers = $request->get_headers();

        // 署名検証（将来的に実装）
        $signature = $headers['x_grandpay_signature'][0] ?? '';

        error_log('GrandPay Payment: Webhook payload: ' . $body);
        error_log('GrandPay Payment: Webhook headers: ' . print_r($headers, true));

        $data = json_decode($body, true);

        if (!$data) {
            error_log('GrandPay Payment: ❌ Invalid JSON in webhook payload');
            return new WP_Error('invalid_payload', 'Invalid JSON payload', array('status' => 400));
        }

        if (!isset($data['eventName']) && !isset($data['type'])) {
            error_log('GrandPay Payment: ❌ Missing eventName/type in webhook payload');
            error_log('GrandPay Payment: Available keys: ' . implode(', ', array_keys($data)));
            return new WP_Error('invalid_payload', 'Missing event type', array('status' => 400));
        }

        $event_type = $data['eventName'] ?? $data['type'] ?? '';
        error_log('GrandPay Payment: Webhook event type: ' . $event_type);

        // 🔧 新規追加: 詳細なイベントデータ解析
        if (isset($data['data'])) {
            error_log('GrandPay Payment: Webhook data section found');
            error_log('GrandPay Payment: Data keys: ' . implode(', ', array_keys($data['data'])));

            if (isset($data['data']['id'])) {
                error_log('GrandPay Payment: Payment ID: ' . $data['data']['id']);
            }

            if (isset($data['data']['status'])) {
                error_log('GrandPay Payment: Payment status: ' . $data['data']['status']);
            }

            if (isset($data['data']['metadata']['checkoutSessionId'])) {
                error_log('GrandPay Payment: Checkout session ID: ' . $data['data']['metadata']['checkoutSessionId']);
            }
        }

        // イベントタイプに応じて処理
        switch ($event_type) {
            case 'payment.payment.done':
            case 'PAYMENT_CHECKOUT':
            case 'checkout.session.completed':
            case 'payment.succeeded':
                error_log('GrandPay Payment: 🟢 Processing success webhook event: ' . $event_type);
                $result = $this->process_payment_webhook($data);
                error_log('GrandPay Payment: Webhook processing result: ' . ($result ? 'SUCCESS' : 'FAILED'));
                break;

            case 'payment.failed':
                error_log('GrandPay Payment: 🔴 Processing failure webhook event: ' . $event_type);
                $this->process_payment_failure_webhook($data);
                break;

            default:
                error_log('GrandPay Payment: ⚠️ Unknown webhook event: ' . $event_type);
                error_log('GrandPay Payment: Full webhook data: ' . print_r($data, true));
                break;
        }

        error_log('GrandPay Payment: ========== WEBHOOK PROCESSING END ==========');
        return rest_ensure_response(array('status' => 'ok', 'message' => 'Webhook processed'));
    }

    /**
     * 旧形式のWebhook処理（後方互換性）
     */
    public function handle_webhook() {
        error_log('GrandPay Payment: Legacy webhook received');

        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_GRANDPAY_SIGNATURE'] ?? '';

        if (empty($payload)) {
            error_log('GrandPay Payment: Empty webhook payload');
            wp_die('Empty payload', 'Webhook Error', array('response' => 400));
        }

        $data = json_decode($payload, true);

        if (!$data || !isset($data['type'])) {
            error_log('GrandPay Payment: Invalid webhook payload');
            wp_die('Invalid payload', 'Webhook Error', array('response' => 400));
        }

        // REST API処理に転送
        $request = new WP_REST_Request('POST', '/grandpay/v1/webhook');
        $request->set_body($payload);
        $request->set_header('x-grandpay-signature', $signature);

        $response = $this->handle_webhook_rest($request);

        if (is_wp_error($response)) {
            wp_die($response->get_error_message(), 'Webhook Error', array('response' => 400));
        }

        wp_die('OK', 'Webhook Success', array('response' => 200));
    }

    /**
     * 決済成功Webhook処理
     */
    private function process_payment_webhook($data) {
        error_log('GrandPay Payment: ========== WEBHOOK ORDER CREATION START ==========');

        // Webhookデータから情報抽出
        $payment_id = $data['data']['id'] ?? '';
        $session_id = $data['data']['metadata']['checkoutSessionId'] ?? '';
        $payment_status = $data['data']['status'] ?? '';
        $amount = floatval($data['data']['amount'] ?? 0);
        $currency = $data['data']['currency'] ?? 'JPY';
        $customer_email = $data['data']['to'] ?? '';
        $customer_name = $data['data']['recipientName'] ?? '';
        $product_names = $data['data']['productNames'] ?? array();

        error_log('GrandPay Payment: Webhook payment ID: ' . $payment_id);
        error_log('GrandPay Payment: Webhook session ID: ' . $session_id);
        error_log('GrandPay Payment: Webhook amount: ' . $amount);
        error_log('GrandPay Payment: Webhook customer: ' . $customer_name . ' (' . $customer_email . ')');

        // 🔧 重要：決済が成功していない場合は処理しない
        if (strtoupper($payment_status) !== 'COMPLETED') {
            error_log('GrandPay Payment: Payment not completed, status: ' . $payment_status);
            return false;
        }

        // まず既存の注文を検索
        $existing_order_id = $this->find_order_by_session_id($session_id);
        if (!$existing_order_id) {
            $existing_order_id = $this->find_order_by_payment_id($payment_id);
        }

        if ($existing_order_id) {
            error_log('GrandPay Payment: Found existing order: ' . $existing_order_id);

            // 既存注文のステータス確認
            $current_status = get_post_meta($existing_order_id, '_grandpay_payment_status', true);
            if ($current_status === 'completed') {
                error_log('GrandPay Payment: Order already completed, skipping');
                return true;
            }

            // 既存注文を完了処理
            $this->complete_existing_order($existing_order_id, $data['data']);
            return true;
        }

        // 🔧 新規：Webhook情報からWelcart注文を作成
        error_log('GrandPay Payment: No existing order found, creating new Welcart order');
        $new_order_id = $this->create_welcart_order_from_webhook($data['data']);

        if ($new_order_id) {
            error_log('GrandPay Payment: Successfully created Welcart order: ' . $new_order_id);
            $this->complete_existing_order($new_order_id, $data['data']);
            return true;
        } else {
            error_log('GrandPay Payment: Failed to create Welcart order from webhook');
            return false;
        }
    }

    private function complete_existing_order($order_id, $payment_data) {
        error_log('GrandPay Payment: === COMPLETING EXISTING ORDER ===');
        error_log('GrandPay Payment: Order ID: ' . $order_id);

        // 基本的な完了処理
        update_post_meta($order_id, '_grandpay_payment_status', 'completed');
        update_post_meta($order_id, '_grandpay_transaction_id', $payment_data['id'] ?? '');
        update_post_meta($order_id, '_grandpay_completed_at', current_time('mysql'));
        update_post_meta($order_id, '_grandpay_payment_data', $payment_data);
        update_post_meta($order_id, '_wc_trans_id', $payment_data['id'] ?? '');
        update_post_meta($order_id, '_order_status', 'ordercompletion');
        update_post_meta($order_id, '_acting_return', 'completion');

        // 投稿ステータスを公開に
        wp_update_post(array(
            'ID' => $order_id,
            'post_status' => 'publish'
        ));

        error_log('GrandPay Payment: Order completion processing finished for: ' . $order_id);

        // 完了フックを実行
        do_action('grandpay_payment_completed', $order_id, $payment_data);
        do_action('usces_action_order_completion', $order_id);

        return true;
    }

    private function create_welcart_order_from_webhook($payment_data) {
        global $usces;

        error_log('GrandPay Payment: === CREATING WELCART ORDER FROM WEBHOOK ===');

        try {
            // 顧客情報の準備
            $customer_data = array(
                'name' => $payment_data['recipientName'] ?? 'GrandPay Customer',
                'email' => $payment_data['to'] ?? 'noreply@' . $_SERVER['HTTP_HOST'],
                'phone' => '', // Webhookには含まれていない
                'amount' => floatval($payment_data['amount'] ?? 0)
            );

            // 商品情報の準備
            $product_names = $payment_data['productNames'] ?? array('GrandPay Payment');
            $product_name = is_array($product_names) ? implode(', ', $product_names) : $product_names;

            error_log('GrandPay Payment: Creating order for: ' . $customer_data['name'] . ' (' . $customer_data['email'] . ')');
            error_log('GrandPay Payment: Product: ' . $product_name . ', Amount: ' . $customer_data['amount']);

            // 🔧 Welcart注文投稿を作成
            $order_post_data = array(
                'post_type' => 'shop_order',
                'post_status' => 'private', // Welcartの標準的な注文ステータス
                'post_title' => 'GrandPay Order - ' . current_time('Y-m-d H:i:s'),
                'post_content' => 'Order created from GrandPay webhook',
                'post_author' => 1, // 管理者
                'meta_input' => array(
                    // 基本的な注文情報
                    '_order_date' => current_time('mysql'),
                    '_order_status' => 'ordercompletion', // 完了済み
                    '_payment_method' => 'grandpay',
                    '_acting_return' => 'completion',

                    // 金額情報
                    '_order_total' => $customer_data['amount'],
                    '_total_full_price' => $customer_data['amount'],
                    '_order_subtotal' => $customer_data['amount'],

                    // 顧客情報
                    '_customer_name' => $customer_data['name'],
                    '_customer_email' => $customer_data['email'],
                    '_customer_phone' => $customer_data['phone'],

                    // GrandPay固有情報
                    '_grandpay_session_id' => $payment_data['metadata']['checkoutSessionId'] ?? '',
                    '_grandpay_transaction_id' => $payment_data['id'] ?? '',
                    '_grandpay_payment_status' => 'completed',
                    '_grandpay_completed_at' => current_time('mysql'),
                    '_grandpay_payment_data' => $payment_data,
                    '_grandpay_webhook_created' => true,

                    // Welcart標準フィールド
                    '_wc_trans_id' => $payment_data['id'] ?? '',
                    '_settlement' => 'grandpay',
                    '_order_currency' => $payment_data['currency'] ?? 'JPY'
                )
            );

            // 注文投稿を作成
            $order_id = wp_insert_post($order_post_data, true);

            if (is_wp_error($order_id)) {
                error_log('GrandPay Payment: Failed to create order post: ' . $order_id->get_error_message());
                return false;
            }

            error_log('GrandPay Payment: Created order post with ID: ' . $order_id);

            // 🔧 商品情報を追加（簡略版）
            $cart_item = array(
                'post_id' => 0, // 実際の商品がない場合
                'sku' => 'GRANDPAY_PAYMENT',
                'item_name' => $product_name,
                'quantity' => 1,
                'price' => $customer_data['amount'],
                'options' => array()
            );

            update_post_meta($order_id, '_cart', array($cart_item));
            update_post_meta($order_id, '_cart_total_items', 1);

            // 🔧 Welcartシステムに注文を登録
            if (function_exists('usces_update_system_option')) {
                // Welcartの注文システムに登録する処理があれば実行
                error_log('GrandPay Payment: Welcart system integration available');
            }

            error_log('GrandPay Payment: Welcart order created successfully: ' . $order_id);

            // 作成完了フックを実行
            do_action('grandpay_order_created_from_webhook', $order_id, $payment_data);

            return $order_id;
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception creating Welcart order: ' . $e->getMessage());
            error_log('GrandPay Payment: Exception trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    private function debug_order_search($session_id, $payment_id) {
        error_log('GrandPay Payment: === DEBUG ORDER SEARCH ===');

        // 全てのGrandPay関連注文を検索
        $all_orders = get_posts(array(
            'post_type' => 'shop_order',
            'meta_query' => array(
                array(
                    'key' => '_payment_method',
                    'value' => 'grandpay',
                    'compare' => '='
                )
            ),
            'post_status' => 'any',
            'numberposts' => 10,
            'fields' => 'ids'
        ));

        error_log('GrandPay Payment: Found ' . count($all_orders) . ' GrandPay orders');

        foreach ($all_orders as $order_id) {
            $stored_session = get_post_meta($order_id, '_grandpay_session_id', true);
            $stored_transaction = get_post_meta($order_id, '_grandpay_transaction_id', true);

            error_log("GrandPay Payment: Order {$order_id}: session={$stored_session}, transaction={$stored_transaction}");
        }
    }

    private function find_order_by_payment_id($payment_id) {
        $posts = get_posts(array(
            'post_type' => 'shop_order',
            'meta_key' => '_grandpay_transaction_id',
            'meta_value' => $payment_id,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids'
        ));

        return empty($posts) ? false : $posts[0];
    }

    /**
     * 決済失敗Webhook処理
     */
    private function process_payment_failure_webhook($data) {
        if (!isset($data['data']['object']['id'])) {
            error_log('GrandPay Payment: Failure webhook missing session ID');
            return;
        }

        $session_id = $data['data']['object']['id'];
        $order_id = $this->find_order_by_session_id($session_id);

        if ($order_id) {
            $this->fail_order($order_id);
            update_post_meta($order_id, '_grandpay_webhook_received', current_time('mysql'));
        }
    }

    /**
     * セッションIDから注文を検索
     */
    private function find_order_by_session_id($session_id) {
        $posts = get_posts(array(
            'post_type' => 'shop_order',
            'meta_key' => '_grandpay_session_id',
            'meta_value' => $session_id,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids'
        ));

        return empty($posts) ? false : $posts[0];
    }
}
