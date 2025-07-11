<?php

/**
 * GrandPay Payment Gateway API Implementation for Welcart
 * 完全版 - OAuth2認証とチェックアウトセッション作成を実装
 */

class WelcartGrandpayAPI {
    private $tenant_key;
    private $client_id;
    private $username;
    private $credentials;
    private $test_mode;
    private $base_url = 'https://api.payment-gateway.asia';

    public function __construct() {
        $this->tenant_key = get_option('welcart_grandpay_tenant_key', '');
        $this->client_id = get_option('welcart_grandpay_client_id', '');
        $this->username = get_option('welcart_grandpay_username', '');
        $this->credentials = get_option('welcart_grandpay_credentials', '');
        $this->test_mode = get_option('welcart_grandpay_test_mode', true);

        error_log('GrandPay API: Initialized with test_mode=' . ($this->test_mode ? 'true' : 'false'));
    }

    /**
     * OAuth2アクセストークン取得（Postmanコレクション準拠）
     */
    public function get_access_token() {
        error_log('GrandPay API: Starting OAuth2 authentication');

        // 設定値チェック
        if (empty($this->client_id) || empty($this->username) || empty($this->credentials)) {
            error_log('GrandPay API: Missing credentials - client_id, username, or credentials');
            return array('success' => false, 'error' => 'API認証情報が不足しています');
        }

        // キャッシュされたトークンをチェック
        $cached_token = get_transient('welcart_grandpay_access_token');
        $expires_at = get_transient('welcart_grandpay_token_expires_at');

        if ($cached_token && $expires_at && time() < $expires_at - 300) { // 5分前にリフレッシュ
            error_log('GrandPay API: Using cached access token');
            return array('success' => true, 'access_token' => $cached_token);
        }

        $endpoint = $this->base_url . '/uaa/oauth2/token';

        // Postmanコレクション準拠のリクエストボディ
        $body = array(
            'grant_type' => 'custom-password-grant',
            'username' => $this->username,
            'credentials' => $this->credentials
        );

        // Postmanコレクション準拠のヘッダー
        $headers = array(
            'Authorization' => 'Basic ' . base64_encode('client:secret'),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Welcart-GrandPay/' . WELCART_GRANDPAY_PAYMENT_VERSION,
            'Accept' => 'application/json'
        );

        error_log('GrandPay API: OAuth2 request to ' . $endpoint);
        error_log('GrandPay API: Request headers: ' . print_r($headers, true));
        error_log('GrandPay API: Request body: grant_type=' . $body['grant_type'] . ', username=' . $body['username']);

        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30,
            'sslverify' => !$this->test_mode
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('GrandPay API: OAuth2 request failed - ' . $error_message);
            return array('success' => false, 'error' => 'API接続エラー: ' . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        error_log('GrandPay API: OAuth2 response code: ' . $response_code);
        error_log('GrandPay API: OAuth2 response headers: ' . print_r($response_headers, true));
        error_log('GrandPay API: OAuth2 response body: ' . $response_body);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);

            if (isset($data['data']['accessToken'])) {
                $access_token = $data['data']['accessToken'];
                $expires_in = isset($data['data']['expiresIn']) ? intval($data['data']['expiresIn']) : 3600;

                // トークンをキャッシュ
                set_transient('welcart_grandpay_access_token', $access_token, $expires_in);
                set_transient('welcart_grandpay_token_expires_at', time() + $expires_in, $expires_in);

                error_log('GrandPay API: OAuth2 success - token cached for ' . $expires_in . ' seconds');
                return array('success' => true, 'access_token' => $access_token);
            } else {
                error_log('GrandPay API: OAuth2 response missing accessToken');
                return array('success' => false, 'error' => 'レスポンスにアクセストークンが含まれていません');
            }
        } else {
            // エラーレスポンスの詳細解析
            $error_data = json_decode($response_body, true);
            $error_message = 'OAuth2認証失敗 (HTTP ' . $response_code . ')';

            if ($error_data && isset($error_data['error'])) {
                $error_message .= ': ' . $error_data['error'];
                if (isset($error_data['error_description'])) {
                    $error_message .= ' - ' . $error_data['error_description'];
                }
            }

            error_log('GrandPay API: OAuth2 authentication failed - ' . $error_message);
            return array('success' => false, 'error' => $error_message);
        }
    }

    /**
     * チェックアウトセッション作成（デバッグ強化版）
     */
    public function create_checkout_session($order_data) {
        error_log('GrandPay API: === チェックアウトセッション作成開始 ===');
        error_log('GrandPay API: 受信した注文データ: ' . print_r($order_data, true));

        // アクセストークンを取得
        $token_result = $this->get_access_token();
        if (!$token_result['success']) {
            error_log('GrandPay API: アクセストークン取得失敗: ' . $token_result['error']);
            return $token_result;
        }

        $access_token = $token_result['access_token'];
        error_log('GrandPay API: アクセストークン取得成功: ' . substr($access_token, 0, 20) . '...');

        $endpoint = $this->base_url . '/p/v2/checkout-sessions';
        error_log('GrandPay API: リクエスト先エンドポイント: ' . $endpoint);

        // Postmanコレクション準拠のチェックアウトデータ（デバッグ用簡略版）
        $checkout_data = array(
            'title' => 'Test Order #' . $order_data['order_id'],
            'type' => 'WEB_REDIRECT',
            'currency' => 'JPY',
            'nature' => 'ONE_OFF',
            'payer' => array(
                'name' => $order_data['name'] ?: 'Test Customer',
                'phone' => $this->format_phone_number($order_data['phone']),
                'email' => $order_data['email'],
                'areaCode' => '081',
                'city' => 'tokyo',
                'country' => 'JP'
            ),
            'successUrl' => $order_data['success_url'],
            'failureUrl' => $order_data['failure_url'],
            'lineItems' => array(
                array(
                    'priceData' => array(
                        'currency' => 'JPY',
                        'productData' => array(
                            'name' => 'Test Product - Order #' . $order_data['order_id']
                        ),
                        'unitAmount' => strval(intval($order_data['amount'])),
                        'taxBehavior' => 'inclusive'
                    ),
                    'adjustableQuantity' => array(
                        'enabled' => false,
                        'minimum' => 1,
                        'maximum' => 1
                    ),
                    'quantity' => 1
                )
            )
        );

        error_log('GrandPay API: 作成したチェックアウトデータ: ' . json_encode($checkout_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // ヘッダー構築
        $headers = array(
            'x-tenant-key' => $this->tenant_key,
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'Welcart-GrandPay/' . WELCART_GRANDPAY_PAYMENT_VERSION,
            'Accept' => 'application/json'
        );

        // テストモードの場合はヘッダーを追加
        if ($this->test_mode) {
            $headers['IsTestMode'] = 'true';
            error_log('GrandPay API: テストモードヘッダー追加');
        }

        error_log('GrandPay API: リクエストヘッダー: ' . print_r($headers, true));

        // リクエスト本体
        $request_body = json_encode($checkout_data);
        error_log('GrandPay API: リクエストボディ: ' . $request_body);
        error_log('GrandPay API: リクエストボディサイズ: ' . strlen($request_body) . ' bytes');

        // HTTPリクエスト実行
        error_log('GrandPay API: HTTPリクエスト開始...');
        $start_time = microtime(true);

        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => $request_body,
            'timeout' => 30,
            'sslverify' => !$this->test_mode,
            'blocking' => true,
            'httpversion' => '1.1'
        ));

        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        error_log('GrandPay API: HTTPリクエスト完了 (所要時間: ' . $request_duration . 'ms)');

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            error_log('GrandPay API: WP_Error発生');
            error_log('GrandPay API: エラーコード: ' . $error_code);
            error_log('GrandPay API: エラーメッセージ: ' . $error_message);
            return array('success' => false, 'error' => 'HTTP通信エラー: ' . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        error_log('GrandPay API: === レスポンス詳細 ===');
        error_log('GrandPay API: ステータスコード: ' . $response_code);
        error_log('GrandPay API: レスポンスヘッダー: ' . print_r($response_headers, true));
        error_log('GrandPay API: レスポンスボディ: ' . $response_body);
        error_log('GrandPay API: レスポンスサイズ: ' . strlen($response_body) . ' bytes');

        // レスポンス解析
        if ($response_code === 200 || $response_code === 201) {
            $data = json_decode($response_body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('GrandPay API: JSONデコードエラー: ' . json_last_error_msg());
                return array('success' => false, 'error' => 'JSONレスポンス解析エラー: ' . json_last_error_msg());
            }

            error_log('GrandPay API: デコードされたデータ: ' . print_r($data, true));

            if (isset($data['data']['id']) && isset($data['data']['checkoutUrl'])) {
                error_log('GrandPay API: ✅ チェックアウトセッション作成成功');
                error_log('GrandPay API: セッションID: ' . $data['data']['id']);
                error_log('GrandPay API: チェックアウトURL: ' . $data['data']['checkoutUrl']);
                return array(
                    'success' => true,
                    'session_id' => $data['data']['id'],
                    'checkout_url' => $data['data']['checkoutUrl']
                );
            } else {
                error_log('GrandPay API: ❌ レスポンスに必要なフィールドが不足');
                error_log('GrandPay API: 利用可能なフィールド: ' . print_r(array_keys($data['data'] ?? array()), true));
                return array('success' => false, 'error' => 'レスポンスに必要なフィールド（id, checkoutUrl）が含まれていません');
            }
        } else {
            // エラーレスポンスの詳細解析
            error_log('GrandPay API: ❌ HTTPエラー (ステータス: ' . $response_code . ')');

            $error_data = json_decode($response_body, true);
            $error_message = 'チェックアウトセッション作成失敗 (HTTP ' . $response_code . ')';

            if ($error_data) {
                error_log('GrandPay API: エラーレスポンス解析: ' . print_r($error_data, true));

                if (isset($error_data['error'])) {
                    $error_message .= ': ' . $error_data['error'];
                }
                if (isset($error_data['message'])) {
                    $error_message .= ' - ' . $error_data['message'];
                }
                if (isset($error_data['details'])) {
                    $error_message .= ' (詳細: ' . json_encode($error_data['details']) . ')';
                }
            } else {
                error_log('GrandPay API: エラーレスポンスのJSON解析失敗');
                if (!empty($response_body)) {
                    error_log('GrandPay API: 生のエラーレスポンス: ' . substr($response_body, 0, 1000));
                }
            }

            error_log('GrandPay API: 最終エラーメッセージ: ' . $error_message);
            return array('success' => false, 'error' => $error_message);
        }
    }

    /**
     * 決済ステータス確認
     */
    public function get_payment_status($session_id) {
        error_log('GrandPay API: Getting payment status for session: ' . $session_id);

        $token_result = $this->get_access_token();
        if (!$token_result['success']) {
            return $token_result;
        }

        $access_token = $token_result['access_token'];
        $endpoint = $this->base_url . '/p/checkout-sessions/' . $session_id;

        $headers = array(
            'x-tenant-key' => $this->tenant_key,
            'Authorization' => 'Bearer ' . $access_token,
            'User-Agent' => 'Welcart-GrandPay/' . WELCART_GRANDPAY_PAYMENT_VERSION,
            'Accept' => 'application/json'
        );

        if ($this->test_mode) {
            $headers['IsTestMode'] = 'true';
        }

        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => !$this->test_mode
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('GrandPay API: Payment status request failed - ' . $error_message);
            return array('success' => false, 'error' => $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('GrandPay API: Payment status response code: ' . $response_code);
        error_log('GrandPay API: Payment status response: ' . $response_body);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            return array('success' => true, 'data' => $data);
        }

        return array('success' => false, 'error' => 'Failed to get payment status', 'response' => $response_body);
    }

    /**
     * API接続テスト（改良版）
     */
    public function test_connection() {
        error_log('GrandPay API: Starting connection test');

        // 設定値チェック
        $missing_config = array();
        if (empty($this->tenant_key)) $missing_config[] = 'Tenant Key';
        if (empty($this->client_id)) $missing_config[] = 'Client ID';
        if (empty($this->username)) $missing_config[] = 'Username';
        if (empty($this->credentials)) $missing_config[] = 'Credentials';

        if (!empty($missing_config)) {
            $error_message = '設定が不足しています: ' . implode(', ', $missing_config);
            error_log('GrandPay API: ' . $error_message);
            return array('success' => false, 'error' => $error_message);
        }

        // OAuth2認証テスト
        $token_result = $this->get_access_token();
        if ($token_result['success']) {
            error_log('GrandPay API: Connection test successful');
            return array(
                'success' => true,
                'message' => 'OAuth2認証成功 - アクセストークン取得完了'
            );
        } else {
            error_log('GrandPay API: Connection test failed - ' . $token_result['error']);
            return $token_result;
        }
    }

    /**
     * モックアクセストークン取得（テスト用）
     */
    public function get_mock_access_token() {
        if (!$this->test_mode) {
            return false;
        }

        $mock_token = 'mock_token_' . wp_generate_password(32, false);
        set_transient('welcart_grandpay_access_token', $mock_token, 3600);
        set_transient('welcart_grandpay_token_expires_at', time() + 3600, 3600);

        error_log('GrandPay API: Mock token generated: ' . substr($mock_token, 0, 20) . '...');
        return $mock_token;
    }

    /**
     * API エンドポイント検出（デバッグ用）
     */
    public function discover_api_endpoint() {
        $endpoints = array(
            '/uaa/oauth2/token',
            '/oauth2/token',
            '/auth/token',
            '/api/oauth2/token',
            '/v1/oauth2/token',
            '/v2/oauth2/token',
            '/p/v2/checkout-sessions',
            '/p/v1/checkout-sessions',
            '/checkout-sessions',
            '/api/checkout-sessions'
        );

        $results = array();

        foreach ($endpoints as $endpoint) {
            $url = $this->base_url . $endpoint;
            $response = wp_remote_get($url, array(
                'timeout' => 10,
                'sslverify' => !$this->test_mode
            ));

            $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);

            $results[] = array(
                'url' => $url,
                'status' => $status
            );

            error_log("GrandPay API Discovery: $url -> HTTP $status");
        }

        return $results;
    }

    /**
     * Webhook署名検証
     */
    public function verify_webhook_signature($payload, $signature) {
        // TODO: GrandPayのWebhook署名方式に応じて実装
        // 現在は常にtrueを返す（テスト用）
        return true;
    }

    /**
     * 電話番号フォーマット
     */
    private function format_phone_number($phone) {
        if (empty($phone)) {
            return '9012345678'; // デフォルト値
        }

        // 日本の電話番号フォーマットに変換
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) === 11 && substr($phone, 0, 1) === '0') {
            return substr($phone, 1); // 先頭の0を除去
        }

        if (strlen($phone) === 10) {
            return $phone;
        }

        return '9012345678'; // フォーマットが不正な場合のデフォルト
    }

    /**
     * デバッグ情報取得
     */
    public function get_debug_info() {
        return array(
            'base_url' => $this->base_url,
            'test_mode' => $this->test_mode,
            'has_tenant_key' => !empty($this->tenant_key),
            'has_client_id' => !empty($this->client_id),
            'has_username' => !empty($this->username),
            'has_credentials' => !empty($this->credentials),
            'cached_token_exists' => !empty(get_transient('welcart_grandpay_access_token')),
            'token_expires_at' => get_transient('welcart_grandpay_token_expires_at')
        );
    }
}
