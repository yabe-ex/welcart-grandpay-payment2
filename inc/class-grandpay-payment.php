<?php

class WelcartGrandpayPaymentProcessor {

    private $api;

    public function __construct() {
        $this->api = new WelcartGrandpayAPI();

        // 🚨 重要: usces_action_acting_processing フックは削除
        // これは settlement/grandpay.php で処理される
        // add_action('usces_action_acting_processing', array($this, 'process_payment'), 10); // ❌ 削除

        // 🔧 修正: コールバック処理をより早いタイミングで登録
        add_action('wp', array($this, 'handle_payment_callback'), 1);  // 最優先で実行
        add_action('template_redirect', array($this, 'handle_payment_callback'), 1);  // フォールバック

        // Webhook処理
        add_action('wp_ajax_grandpay_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_grandpay_webhook', array($this, 'handle_webhook'));

        // REST API登録
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        error_log('GrandPay Payment Processor: Initialized with callback and webhook hooks only');
    }

    // 🚨 process_payment() メソッドは削除
    // 決済処理は settlement/grandpay.php の acting_processing() で処理される

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
     * 決済完了後のコールバック処理（詳細デバッグ版 + 強化検索）
     */
    public function handle_payment_callback() {
        // 🔧 重複実行防止フラグ
        static $callback_processed = false;
        if ($callback_processed) {
            error_log('GrandPay Payment: Callback already processed, skipping');
            return;
        }

        // 🔧 修正: GrandPay専用コールバックのみ処理するよう条件を厳格化
        if (!isset($_GET['grandpay_result'])) {
            // grandpay_resultパラメータがない場合はGrandPayのコールバックではない
            return;
        }

        if (!isset($_GET['order_id'])) {
            error_log('GrandPay Payment: Missing order_id parameter in GrandPay callback');
            return;
        }

        // 🔧 詳細なデバッグログ
        error_log('GrandPay Payment: ========== CALLBACK DEBUG START ==========');
        error_log('GrandPay Payment: Current hook: ' . current_filter());
        error_log('GrandPay Payment: Request URI: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        error_log('GrandPay Payment: GET parameters: ' . print_r($_GET, true));
        error_log('GrandPay Payment: Current time: ' . current_time('Y-m-d H:i:s'));

        // 処理フラグを設定
        $callback_processed = true;

        $order_id = sanitize_text_field($_GET['order_id']);
        $result = sanitize_text_field($_GET['grandpay_result']);
        $session_check = $_GET['session_check'] ?? '';

        error_log('GrandPay Payment: Processing GrandPay callback - Result: ' . $result . ', Order ID: ' . $order_id);
        error_log('GrandPay Payment: Session check value: ' . $session_check);

        // 🔧 nonce検証の詳細デバッグ
        if (empty($session_check)) {
            error_log('GrandPay Payment: ❌ Missing session_check parameter for order: ' . $order_id);
            wp_die('Missing session parameter', 'Callback Error', array('response' => 400));
            return;
        }

        // nonce検証
        $nonce_action = 'grandpay_callback_' . $order_id;
        $nonce_verification = wp_verify_nonce($session_check, $nonce_action);
        error_log('GrandPay Payment: Nonce verification result: ' . ($nonce_verification ? 'TRUE' : 'FALSE'));

        if (!$nonce_verification) {
            error_log('GrandPay Payment: ❌ Invalid callback nonce for order: ' . $order_id);
            wp_die('Invalid session. Debug info logged.', 'Callback Error', array('response' => 403));
            return;
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

            // メタデータから検索
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
                error_log('GrandPay Payment: Method 2 - Found via meta query: ' . $final_order_id);
            }
        }

        error_log('GrandPay Payment: ========== ORDER SEARCH END ==========');

        if (!$order) {
            error_log('GrandPay Payment: ❌ Order not found after all search methods: ' . $order_id);
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
        global $usces, $wpdb;

        error_log('GrandPay Payment: 🚨 EMERGENCY FIX - Starting Welcart-force complete_order for order_id: ' . $order_id);
        error_log('GrandPay Payment: Payment data: ' . print_r($payment_data, true));

        try {
            // 🔧 重複処理防止
            $current_status = get_post_meta($order_id, '_grandpay_payment_status', true);
            if ($current_status === 'completed') {
                error_log('GrandPay Payment: Order already completed: ' . $order_id);
                return true;
            }

            // 処理中フラグを即座に設定
            update_post_meta($order_id, '_grandpay_payment_status', 'processing');
            update_post_meta($order_id, '_grandpay_completion_started_at', current_time('mysql'));

            // 🚨 Step 1: Welcart注文テーブルの直接更新（強制）
            $table_name = $wpdb->prefix . 'usces_order';

            $welcart_update_result = $wpdb->update(
                $table_name,
                array(
                    'order_status' => 'ordercompletion', // Welcart標準の完了ステータス
                    'order_modified' => current_time('mysql')
                ),
                array('ID' => $order_id),
                array('%s', '%s'),
                array('%d')
            );

            if ($welcart_update_result === false) {
                error_log('GrandPay Payment: Failed to update Welcart order status: ' . $wpdb->last_error);
                throw new Exception('Welcart注文ステータス更新に失敗しました');
            }

            error_log('GrandPay Payment: ✅ Welcart order status updated to ordercompletion');

            // 🚨 Step 2: Welcart標準の在庫管理を強制実行
            error_log('GrandPay Payment: 🚨 FORCING Welcart inventory management...');
            $this->force_welcart_inventory_management($order_id);

            // 🚨 Step 3: Welcartのポイント処理を強制実行
            error_log('GrandPay Payment: 🚨 FORCING Welcart point processing...');
            $this->force_welcart_point_processing($order_id);

            // 🔧 Step 4: メール送信はスキップ（ユーザーリクエスト）
            error_log('GrandPay Payment: Email notifications skipped (user request)');

            // 🔧 Step 4: GrandPay固有情報の保存
            update_post_meta($order_id, '_grandpay_payment_status', 'completed');
            update_post_meta($order_id, '_grandpay_transaction_id', $payment_data['id'] ?? '');
            update_post_meta($order_id, '_grandpay_completed_at', current_time('mysql'));
            update_post_meta($order_id, '_grandpay_payment_data', $payment_data);

            // 🔧 Step 5: Welcart標準フィールドの設定
            update_post_meta($order_id, '_wc_trans_id', $payment_data['id'] ?? ''); // Welcart標準の取引ID
            update_post_meta($order_id, '_acting_return', 'completion'); // Welcart標準の決済結果
            update_post_meta($order_id, '_payment_method', 'grandpay');
            update_post_meta($order_id, '_settlement', 'grandpay');

            // 🚨 Step 7: Welcart標準フックを強制実行
            error_log('GrandPay Payment: 🚨 FORCING Welcart standard hooks...');

            // 注文完了アクション
            do_action('usces_action_order_completion', $order_id);

            // 決済完了アクション
            do_action('usces_action_acting_return', array(
                'order_id' => $order_id,
                'acting' => 'grandpay',
                'result' => 'completion'
            ));

            // 🚨 Step 8: Welcartの注文ステータス変更関数を強制実行
            if (function_exists('usces_change_order_status')) {
                $status_change_result = usces_change_order_status($order_id, 'ordercompletion');

                if (is_wp_error($status_change_result)) {
                    error_log('GrandPay Payment: usces_change_order_status error: ' . $status_change_result->get_error_message());
                } else {
                    error_log('GrandPay Payment: ✅ usces_change_order_status completed successfully');
                }
            }

            // 🔧 Step 9: セッションクリア
            $this->clear_welcart_session();

            // 🔧 Step 10: 最終確認
            $final_status = $wpdb->get_var($wpdb->prepare(
                "SELECT order_status FROM {$table_name} WHERE ID = %d",
                $order_id
            ));

            error_log('GrandPay Payment: Final Welcart order status: ' . $final_status);
            error_log('GrandPay Payment: ✅ 🚨 EMERGENCY FIX - Welcart-force order completion finished successfully - ID: ' . $order_id);

            // GrandPay固有のフック
            do_action('grandpay_payment_completed', $order_id, $payment_data);

            return true;
        } catch (Exception $e) {
            error_log('GrandPay Payment: ❌ Exception in emergency fix complete_order: ' . $e->getMessage());
            error_log('GrandPay Payment: Exception trace: ' . $e->getTraceAsString());

            // エラー時の状態設定
            update_post_meta($order_id, '_grandpay_payment_status', 'error');
            update_post_meta($order_id, '_grandpay_error_message', $e->getMessage());
            update_post_meta($order_id, '_grandpay_error_at', current_time('mysql'));

            throw $e;
        }
    }

    /**
     * 🚨 新規追加: Welcart在庫管理の強制実行
     */
    private function force_welcart_inventory_management($order_id) {
        try {
            error_log('GrandPay Payment: 🚨 Starting forced inventory management for order: ' . $order_id);

            // Welcartの注文データから商品情報を取得
            global $wpdb;
            $table_name = $wpdb->prefix . 'usces_order';

            $order = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE ID = %d",
                $order_id
            ), ARRAY_A);

            if (!$order) {
                error_log('GrandPay Payment: Order not found for inventory management');
                return false;
            }

            // カート情報をアンシリアライズ
            $cart_data = unserialize($order['order_cart']);

            if (is_array($cart_data) && !empty($cart_data)) {
                error_log('GrandPay Payment: Processing ' . count($cart_data) . ' cart items for inventory');

                foreach ($cart_data as $item_index => $cart_item) {
                    $post_id = intval($cart_item['post_id'] ?? 0);
                    $sku = sanitize_text_field($cart_item['sku'] ?? '');
                    $quantity = intval($cart_item['quantity'] ?? 0);

                    if ($post_id && $sku && $quantity > 0) {
                        // 🚨 Welcartの在庫減算関数を直接呼び出し
                        if (function_exists('usces_update_item_stock')) {
                            $stock_result = usces_update_item_stock($post_id, $sku, $quantity);
                            error_log("GrandPay Payment: 🚨 FORCED stock update for {$post_id}:{$sku} (-{$quantity}): " . print_r($stock_result, true));
                        } else {
                            // フォールバック: 直接在庫を減算
                            $this->direct_stock_reduction($post_id, $sku, $quantity);
                        }
                    } else {
                        error_log("GrandPay Payment: Skipping invalid item: post_id={$post_id}, sku={$sku}, quantity={$quantity}");
                    }
                }
            } else {
                error_log('GrandPay Payment: No cart data found for inventory management');
            }

            update_post_meta($order_id, '_grandpay_inventory_updated', current_time('mysql'));
            error_log('GrandPay Payment: ✅ Forced inventory management completed');
        } catch (Exception $e) {
            error_log('GrandPay Payment: Error in forced inventory management: ' . $e->getMessage());
            update_post_meta($order_id, '_grandpay_inventory_error', $e->getMessage());
        }
    }

    /**
     * 🚨 新規追加: 直接在庫減算（フォールバック用）
     */
    private function direct_stock_reduction($post_id, $sku, $quantity) {
        try {
            // Welcartの在庫データを直接操作
            $current_stock = get_post_meta($post_id, '_stock', true);

            if (is_numeric($current_stock) && $current_stock >= $quantity) {
                $new_stock = $current_stock - $quantity;
                update_post_meta($post_id, '_stock', $new_stock);
                error_log("GrandPay Payment: 🚨 DIRECT stock reduction: {$post_id}:{$sku} {$current_stock} -> {$new_stock}");
            } else {
                error_log("GrandPay Payment: Insufficient stock for {$post_id}:{$sku} (current: {$current_stock}, requested: {$quantity})");
            }
        } catch (Exception $e) {
            error_log('GrandPay Payment: Error in direct stock reduction: ' . $e->getMessage());
        }
    }

    /**
     * 🚨 新規追加: Welcartポイント処理の強制実行
     */
    private function force_welcart_point_processing($order_id) {
        try {
            error_log('GrandPay Payment: 🚨 Starting forced point processing for order: ' . $order_id);

            // Welcartの注文データを取得
            global $wpdb;
            $table_name = $wpdb->prefix . 'usces_order';

            $order = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE ID = %d",
                $order_id
            ), ARRAY_A);

            if (!$order) {
                error_log('GrandPay Payment: Order not found for point processing');
                return false;
            }

            $member_id = $order['mem_id'];
            $getpoint = intval($order['order_getpoint']);
            $usedpoint = intval($order['order_usedpoint']);

            if ($member_id && $getpoint > 0) {
                // 🚨 Welcartのポイント付与関数を直接呼び出し
                if (function_exists('usces_add_point')) {
                    $point_result = usces_add_point($member_id, $getpoint, $order_id);
                    error_log("GrandPay Payment: 🚨 FORCED point addition: member {$member_id} + {$getpoint} points");
                } else {
                    // フォールバック: 直接ポイントを追加
                    $this->direct_point_addition($member_id, $getpoint, $order_id);
                }
            }

            update_post_meta($order_id, '_grandpay_points_processed', current_time('mysql'));
            error_log('GrandPay Payment: ✅ Forced point processing completed');
        } catch (Exception $e) {
            error_log('GrandPay Payment: Error in forced point processing: ' . $e->getMessage());
            update_post_meta($order_id, '_grandpay_points_error', $e->getMessage());
        }
    }

    /**
     * 🚨 新規追加: 直接ポイント追加（フォールバック用）
     */
    private function direct_point_addition($member_id, $points, $order_id) {
        try {
            global $wpdb;

            // 会員の現在のポイントを取得
            $member_table = $wpdb->prefix . 'usces_member';
            $current_points = $wpdb->get_var($wpdb->prepare(
                "SELECT mem_point FROM {$member_table} WHERE ID = %d",
                $member_id
            ));

            if ($current_points !== null) {
                $new_points = intval($current_points) + $points;

                $update_result = $wpdb->update(
                    $member_table,
                    array('mem_point' => $new_points),
                    array('ID' => $member_id),
                    array('%d'),
                    array('%d')
                );

                if ($update_result !== false) {
                    error_log("GrandPay Payment: 🚨 DIRECT point addition: member {$member_id} {$current_points} -> {$new_points}");
                }
            }
        } catch (Exception $e) {
            error_log('GrandPay Payment: Error in direct point addition: ' . $e->getMessage());
        }
    }



    /**
     * Welcart標準のセッションクリア（既存のまま）
     */
    private function clear_welcart_session() {
        try {
            global $usces;

            // Welcartの標準的なカートクリア
            if (isset($usces->cart) && method_exists($usces->cart, 'empty_cart')) {
                $usces->cart->empty_cart();
                error_log('GrandPay Payment: ✅ Welcart cart cleared using standard method');
            }

            // Welcart標準のセッションクリア
            $welcart_session_keys = array(
                'usces_cart',
                'usces_entry',
                'usces_member_regmode',
                'usces_checkout'
            );

            foreach ($welcart_session_keys as $key) {
                if (isset($_SESSION[$key])) {
                    unset($_SESSION[$key]);
                    error_log('GrandPay Payment: Cleared session key: ' . $key);
                }
            }

            error_log('GrandPay Payment: ✅ Welcart session cleared');
        } catch (Exception $e) {
            error_log('GrandPay Payment: Warning - Session clear failed: ' . $e->getMessage());
        }
    }

    /**
     * 注文失敗処理（修正版 - エラーハンドリング強化）
     */
    private function fail_order($order_id) {
        global $wpdb;

        error_log('GrandPay Payment: Starting Welcart-standard fail_order for order_id: ' . $order_id);

        try {
            // 重複処理防止
            $current_status = get_post_meta($order_id, '_grandpay_payment_status', true);
            if ($current_status === 'failed') {
                error_log('GrandPay Payment: Order already failed: ' . $order_id);
                return;
            }

            // 🔧 Welcart注文テーブルのステータス更新
            $table_name = $wpdb->prefix . 'usces_order';

            $wpdb->update(
                $table_name,
                array(
                    'order_status' => 'cancel', // Welcart標準のキャンセルステータス
                    'order_modified' => current_time('mysql')
                ),
                array('ID' => $order_id),
                array('%s', '%s'),
                array('%d')
            );

            // GrandPay固有情報の更新
            update_post_meta($order_id, '_grandpay_payment_status', 'failed');
            update_post_meta($order_id, '_grandpay_failed_at', current_time('mysql'));
            update_post_meta($order_id, '_acting_return', 'failure');

            // 🔧 Welcart標準の失敗処理を実行
            if (function_exists('usces_change_order_status')) {
                usces_change_order_status($order_id, 'cancel');
            }

            // Welcart標準の失敗フックを実行
            do_action('usces_action_acting_return', array(
                'order_id' => $order_id,
                'acting' => 'grandpay',
                'result' => 'failure'
            ));

            error_log('GrandPay Payment: ✅ Welcart-standard order failure processing completed - ID: ' . $order_id);

            // GrandPay固有のフック
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
        error_log('GrandPay Payment: ========== WEBHOOK ORDER PROCESSING START ==========');

        // Webhookデータから情報抽出
        $payment_id = $data['data']['id'] ?? '';
        $session_id = $data['data']['metadata']['checkoutSessionId'] ?? '';
        $payment_status = $data['data']['status'] ?? '';
        $amount = floatval($data['data']['amount'] ?? 0);
        $customer_email = $data['data']['to'] ?? '';
        $customer_name = $data['data']['recipientName'] ?? '';

        error_log('GrandPay Payment: Webhook payment ID: ' . $payment_id);
        error_log('GrandPay Payment: Webhook session ID: ' . $session_id);
        error_log('GrandPay Payment: Webhook status: ' . $payment_status);

        // 決済が成功していない場合は処理しない
        if (strtoupper($payment_status) !== 'COMPLETED') {
            error_log('GrandPay Payment: Payment not completed, status: ' . $payment_status);
            return false;
        }

        // 既存の注文を検索
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

            // 🔧 重要: Welcart標準の完了処理を呼び出し
            $this->complete_order($existing_order_id, $data['data']);
            return true;
        }

        // 注文が見つからない場合のログ
        error_log('GrandPay Payment: ⚠️ No existing order found for webhook');
        error_log('GrandPay Payment: This may be normal if the payment was completed via redirect');

        return false;
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
