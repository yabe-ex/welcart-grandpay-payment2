jQuery(document).ready(function ($) {
    console.log('🔧 GrandPay Enhanced Front Script Loaded');

    // GrandPay 決済処理のメイン機能
    const GrandPayPayment = {
        // 設定
        config: {
            debug: typeof grandpay_front !== 'undefined' ? grandpay_front.debug : false,
            ajaxurl: typeof grandpay_front !== 'undefined' ? grandpay_front.ajaxurl : '/wp-admin/admin-ajax.php',
            nonce: typeof grandpay_front !== 'undefined' ? grandpay_front.nonce : '',
            messages:
                typeof grandpay_front !== 'undefined'
                    ? grandpay_front.messages
                    : {
                          processing: '決済処理中です...',
                          redirecting: 'GrandPayの決済ページにリダイレクトしています...',
                          error: '決済処理中にエラーが発生しました。',
                          timeout: 'リクエストがタイムアウトしました。',
                          network_error: 'ネットワークエラーが発生しました。',
                          payment_failed: '決済に失敗しました。',
                          verification_failed: '決済の確認に失敗しました。',
                          success: '決済が完了しました。'
                      },
            timeouts: {
                ajax: 45000, // 45秒
                redirect: 2000 // 2秒
            }
        },

        // 初期化
        init: function () {
            this.log('🚀 GrandPay Payment System Initializing');
            this.bindEvents();
            this.checkUrlParams();
            this.monitorPaymentMethodSelection();
            this.initializeFormIntegration();
            this.log('✅ GrandPay Payment System Ready');
        },

        // イベントバインディング
        bindEvents: function () {
            // 決済ボタンのクリック
            $(document).on('click', '#grandpay-payment-button, .grandpay-payment-btn, #grandpay-retry-button', this.handlePaymentClick.bind(this));

            // 決済方法選択の監視
            $(document).on('change', 'input[name*="payment"], input[name*="offer"]', this.handlePaymentMethodChange.bind(this));

            // フォーム送信の監視
            $(document).on('submit', 'form[name="customer_form"], .usces_cart_form, form.checkout-form', this.handleFormSubmit.bind(this));

            // ページ離脱時の警告
            $(window).on('beforeunload', this.handleBeforeUnload.bind(this));

            this.log('📋 Event handlers bound');
        },

        // 決済ボタンクリック処理
        handlePaymentClick: function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const orderId = $button.data('order-id') || this.getOrderIdFromPage();

            this.log('🔄 Payment button clicked', { orderId: orderId, button: $button.attr('id') });

            if (!orderId) {
                this.showError(this.config.messages.error + ' (注文IDが見つかりません)');
                return;
            }

            this.startPayment(orderId, $button);
        },

        // 決済開始処理
        startPayment: function (orderId, $button) {
            this.log('🚀 Starting payment process', { orderId: orderId });

            // UIの状態更新
            this.showLoading();
            if ($button) {
                $button.prop('disabled', true).addClass('grandpay-loading');
            }

            // 重複リクエスト防止
            if (this.isProcessing) {
                this.log('⚠️ Payment already in progress, ignoring request');
                return;
            }
            this.isProcessing = true;

            // AJAX リクエスト
            $.ajax({
                url: this.config.ajaxurl,
                type: 'POST',
                data: {
                    action: 'grandpay_start_payment',
                    order_id: orderId,
                    nonce: this.config.nonce
                },
                timeout: this.config.timeouts.ajax,
                beforeSend: function () {
                    // 追加の前処理があればここに
                }
            })
                .done(this.handlePaymentSuccess.bind(this))
                .fail(this.handlePaymentError.bind(this))
                .always(this.handlePaymentComplete.bind(this, $button));
        },

        // 決済成功時の処理
        handlePaymentSuccess: function (response) {
            this.log('✅ Payment AJAX success', response);

            if (response.success && response.data && response.data.checkout_url) {
                this.showRedirectMessage();

                // リダイレクト前のフック
                this.executeHook('beforeRedirect', response.data);

                setTimeout(() => {
                    this.log('🔗 Redirecting to checkout URL', response.data.checkout_url);
                    window.location.href = response.data.checkout_url;
                }, this.config.timeouts.redirect);
            } else {
                const errorMessage = response.data && response.data.message ? response.data.message : this.config.messages.error;

                this.log('❌ Payment response error', errorMessage);
                this.showError(errorMessage);
            }
        },

        // 決済エラー時の処理
        handlePaymentError: function (xhr, status, error) {
            this.log('❌ Payment AJAX error', { status: status, error: error, xhr: xhr });

            let errorMessage = this.config.messages.error;

            if (status === 'timeout') {
                errorMessage = this.config.messages.timeout;
            } else if (status === 'error' && xhr.status === 0) {
                errorMessage = this.config.messages.network_error;
            } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMessage = xhr.responseJSON.data.message;
            } else if (error) {
                errorMessage = error;
            }

            this.showError(errorMessage);
        },

        // 決済処理完了時の処理
        handlePaymentComplete: function ($button) {
            this.isProcessing = false;
            this.hideLoading();

            if ($button) {
                $button.prop('disabled', false).removeClass('grandpay-loading');
            }

            this.log('🏁 Payment process completed');
        },

        // 決済方法変更時の処理
        handlePaymentMethodChange: function (e) {
            const $input = $(e.currentTarget);
            const selectedValue = $input.val();
            const isChecked = $input.is(':checked');

            this.log('💳 Payment method changed', { value: selectedValue, checked: isChecked });

            if (isChecked && this.isGrandPayMethod(selectedValue, $input)) {
                this.showGrandPayInfo();
                this.executeHook('grandpaySelected', selectedValue);
            } else {
                this.hideGrandPayInfo();
            }
        },

        // フォーム送信時の処理
        handleFormSubmit: function (e) {
            const $form = $(e.currentTarget);
            const isGrandPaySelected = this.isGrandPaySelected();

            this.log('📋 Form submit detected', {
                grandpaySelected: isGrandPaySelected,
                formAction: $form.attr('action')
            });

            if (!isGrandPaySelected) {
                return; // GrandPay以外は通常処理
            }

            // GrandPay選択時の追加チェック
            if (!this.validateGrandPaySubmission($form)) {
                e.preventDefault();
                return false;
            }

            // GrandPayフローではWelcartに処理を委譲
            this.log('🔄 GrandPay selected, letting Welcart handle submission');
        },

        // GrandPay送信時のバリデーション
        validateGrandPaySubmission: function ($form) {
            // 必要な項目のチェック
            const requiredFields = ['customer[mailaddress1]', 'customer[name1]'];
            let isValid = true;

            requiredFields.forEach((field) => {
                const $field = $form.find('[name="' + field + '"]');
                if ($field.length && !$field.val().trim()) {
                    this.showError('必要な情報が入力されていません: ' + field);
                    isValid = false;
                }
            });

            return isValid;
        },

        // GrandPay決済方法判定
        isGrandPayMethod: function (value, $input) {
            // 値による判定
            if (value === 'acting_grandpay_card' || value === 'grandpay') {
                return true;
            }

            // ラベルテキストによる判定
            const labelText = $input.closest('label').text();
            if (labelText.indexOf('GrandPay') !== -1) {
                return true;
            }

            // name/id属性による判定
            const name = $input.attr('name') || '';
            const id = $input.attr('id') || '';

            return name.toLowerCase().indexOf('grandpay') !== -1 || id.toLowerCase().indexOf('grandpay') !== -1;
        },

        // GrandPay選択状態チェック
        isGrandPaySelected: function () {
            let isSelected = false;

            // チェックされたラジオボタンをチェック
            $('input[type="radio"]:checked').each((index, element) => {
                const $element = $(element);
                if (this.isGrandPayMethod($element.val(), $element)) {
                    isSelected = true;
                    return false; // break
                }
            });

            return isSelected;
        },

        // 決済方法選択の監視（ページロード時）
        monitorPaymentMethodSelection: function () {
            setTimeout(() => {
                if (this.isGrandPaySelected()) {
                    this.showGrandPayInfo();
                    this.log('📄 GrandPay pre-selected on page load');
                }
            }, 500);
        },

        // フォーム統合の初期化
        initializeFormIntegration: function () {
            // Welcartフォームの特定とカスタマイズ
            const $forms = $('form[name="customer_form"], .usces_cart_form');

            $forms.each((index, form) => {
                const $form = $(form);

                // GrandPayボタンの追加（必要に応じて）
                if (!$form.find('.grandpay-payment-btn').length) {
                    // この部分は実際のWelcartフォーム構造に合わせて調整
                }
            });
        },

        // ローディング表示
        showLoading: function () {
            this.hideMessages(); // 既存メッセージをクリア

            let $loading = $('#grandpay-loading');
            if (!$loading.length) {
                const loadingHtml = `
                    <div id="grandpay-loading" class="grandpay-loading grandpay-fade-in">
                        <p>${this.config.messages.processing}</p>
                        <div class="grandpay-spinner"></div>
                    </div>
                `;

                // 適切な場所に挿入
                if ($('.grandpay-payment-container').length) {
                    $('.grandpay-payment-container').append(loadingHtml);
                } else if ($('.usces_cart_form').length) {
                    $('.usces_cart_form').prepend(loadingHtml);
                } else {
                    $('body').append(loadingHtml);
                }
            } else {
                $loading.show().addClass('grandpay-fade-in');
            }
        },

        // ローディング非表示
        hideLoading: function () {
            $('#grandpay-loading').fadeOut(300);
        },

        // リダイレクトメッセージ表示
        showRedirectMessage: function () {
            const $loading = $('#grandpay-loading');
            if ($loading.length) {
                $loading.find('p').text(this.config.messages.redirecting);
                $loading.addClass('grandpay-redirecting');
            }
        },

        // エラーメッセージ表示
        showError: function (message) {
            this.log('❌ Showing error message', message);
            this.hideLoading();

            // 既存のエラーメッセージを削除
            $('.grandpay-error-message').remove();

            const errorHtml = `
                <div class="grandpay-error-message grandpay-fade-in" style="
                    background-color: #fef7f7;
                    border: 1px solid #dc3232;
                    border-left: 4px solid #dc3232;
                    color: #dc3232;
                    padding: 16px;
                    border-radius: 4px;
                    margin: 16px 0;
                    font-weight: 500;
                    position: relative;
                    z-index: 9999;
                    box-shadow: 0 2px 8px rgba(220, 50, 50, 0.1);
                ">
                    <strong>❌ エラー:</strong> ${message}
                    <button type="button" class="grandpay-error-close" style="
                        float: right;
                        background: none;
                        border: none;
                        color: #dc3232;
                        cursor: pointer;
                        font-size: 18px;
                        line-height: 1;
                        padding: 0;
                        margin-left: 10px;
                    ">&times;</button>
                </div>
            `;

            // 適切な場所に挿入
            if ($('.grandpay-payment-container').length) {
                $('.grandpay-payment-container').prepend(errorHtml);
            } else if ($('.usces_cart_form').length) {
                $('.usces_cart_form').prepend(errorHtml);
            } else {
                $('body').prepend(errorHtml);
            }

            // エラーメッセージのクローズボタン
            $('.grandpay-error-close').on('click', function () {
                $(this).closest('.grandpay-error-message').fadeOut(300);
            });

            // 自動非表示（10秒後）
            setTimeout(() => {
                $('.grandpay-error-message').fadeOut(300);
            }, 10000);

            // エラー表示時のフック
            this.executeHook('errorShown', message);
        },

        // 成功メッセージ表示
        showSuccessMessage: function (message) {
            message = message || this.config.messages.success;

            const successHtml = `
                <div class="grandpay-success-message grandpay-fade-in" style="
                    background-color: #f0fff4;
                    border: 1px solid #46b450;
                    border-left: 4px solid #46b450;
                    color: #46b450;
                    padding: 16px;
                    border-radius: 4px;
                    margin: 16px 0;
                    text-align: center;
                    font-weight: 600;
                    box-shadow: 0 2px 8px rgba(70, 180, 80, 0.1);
                ">
                    ✅ ${message}
                </div>
            `;

            $('body').prepend(successHtml);

            setTimeout(() => {
                $('.grandpay-success-message').fadeOut(300);
            }, 5000);
        },

        // GrandPay情報ボックス表示
        showGrandPayInfo: function () {
            this.log('ℹ️ Showing GrandPay info box');

            $('.grandpay-payment-info-box').remove(); // 既存削除

            const infoHtml = `
                <div class="grandpay-payment-info-box grandpay-fade-in" style="
                    background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%);
                    border: 1px solid #0073aa;
                    border-radius: 6px;
                    padding: 16px;
                    margin-top: 12px;
                    box-shadow: 0 2px 6px rgba(0, 115, 170, 0.1);
                ">
                    <div style="display: flex; align-items: center;">
                        <div style="font-size: 24px; margin-right: 12px;">💳</div>
                        <div>
                            <strong style="color: #0073aa; font-size: 16px;">クレジットカード決済（GrandPay）</strong><br>
                            <span style="color: #666; font-size: 14px;">安全な決済ページでクレジットカード情報を入力してお支払いいただけます。</span>
                        </div>
                    </div>
                </div>
            `;

            // GrandPay関連要素の近くに追加
            $('input[value="acting_grandpay_card"], input[value*="grandpay"]').each(function () {
                $(this).closest('tr, li, div.payment-method').after(infoHtml);
            });

            $('label:contains("GrandPay")').each(function () {
                $(this).after(infoHtml);
            });
        },

        // GrandPay情報ボックス非表示
        hideGrandPayInfo: function () {
            $('.grandpay-payment-info-box').fadeOut(300, function () {
                $(this).remove();
            });
        },

        // 全メッセージの非表示
        hideMessages: function () {
            $('.grandpay-error-message, .grandpay-success-message, .grandpay-result-message').fadeOut(300);
        },

        // 注文IDの取得
        getOrderIdFromPage: function () {
            // URLパラメータから
            const urlParams = new URLSearchParams(window.location.search);
            let orderId = urlParams.get('order_id');

            if (orderId) return orderId;

            // data属性から
            orderId = $('.grandpay-payment-container').data('order-id');
            if (orderId) return orderId;

            // hidden inputから
            orderId = $('input[name="order_id"]').val();
            if (orderId) return orderId;

            // フォーム内の他の場所から
            orderId = $('input[name*="order"]').val();
            if (orderId) return orderId;

            return null;
        },

        // URLパラメータのチェック
        checkUrlParams: function () {
            const urlParams = new URLSearchParams(window.location.search);
            const result = urlParams.get('grandpay_result');
            const error = urlParams.get('grandpay_error');

            if (result === 'success') {
                this.showSuccessMessage();
                this.executeHook('paymentSuccess', result);
            } else if (result === 'failure') {
                this.showError(this.config.messages.payment_failed);
                this.executeHook('paymentFailure', result);
            }

            if (error) {
                this.showError(decodeURIComponent(error));
            }

            // その他のエラーパラメータ
            const errorType = urlParams.get('error');
            if (errorType) {
                let errorMessage = this.config.messages.error;

                switch (errorType) {
                    case 'payment_failed':
                        errorMessage = this.config.messages.payment_failed;
                        break;
                    case 'payment_verification_failed':
                        errorMessage = this.config.messages.verification_failed;
                        break;
                }

                this.showError(errorMessage);
            }
        },

        // ページ離脱時の警告
        handleBeforeUnload: function (e) {
            if (this.isProcessing) {
                const message = '決済処理が進行中です。ページを離れると決済が中断される可能性があります。';
                e.originalEvent.returnValue = message;
                return message;
            }
        },

        // フック実行（拡張ポイント）
        executeHook: function (hookName, data) {
            const hookFunction = window['grandpay_' + hookName];
            if (typeof hookFunction === 'function') {
                try {
                    hookFunction(data);
                    this.log('🔧 Hook executed: ' + hookName, data);
                } catch (error) {
                    this.log('❌ Hook error: ' + hookName, error);
                }
            }

            // jQuery イベントとしても発火
            $(document).trigger('grandpay:' + hookName, [data]);
        },

        // ログ出力
        log: function (message, data) {
            if (this.config.debug && console && console.log) {
                if (data) {
                    console.log('[GrandPay] ' + message, data);
                } else {
                    console.log('[GrandPay] ' + message);
                }
            }
        }
    };

    // 初期化実行
    GrandPayPayment.init();

    // グローバルアクセス用
    window.GrandPayPayment = GrandPayPayment;

    // 開発者向けデバッグ情報
    if (GrandPayPayment.config.debug) {
        console.log('🔧 GrandPay Debug Mode Enabled');
        console.log('📊 GrandPay Config:', GrandPayPayment.config);

        // デバッグ用のグローバル関数
        window.grandpayDebug = {
            getConfig: () => GrandPayPayment.config,
            isSelected: () => GrandPayPayment.isGrandPaySelected(),
            showInfo: () => GrandPayPayment.showGrandPayInfo(),
            hideInfo: () => GrandPayPayment.hideGrandPayInfo(),
            testError: (msg) => GrandPayPayment.showError(msg || 'テストエラーメッセージ'),
            testSuccess: (msg) => GrandPayPayment.showSuccessMessage(msg || 'テスト成功メッセージ')
        };
    }
});
