<?php
/*
 * Plugin Name: WooCommerce Telegram Orders Pro
 * Description: ارسال جزئیات سفارش، گزارش‌گیری و جستجوی سفارش با ربات تلگرام
 * Version: 1
 * Author: pori
 * Author URI: https://drpori.ir
 */


add_action('admin_menu', function () {
    add_options_page(
        'تنظیمات ربات تلگرام',
        'تلگرام ووکامرس',
        'manage_options',
        'wc-telegram-orders',
        'pori_telegram_orders_settings_page'
    );
});

function pori_telegram_orders_settings_page() {
    ?>
    <div class="wrap">
        <h1>تنظیمات ربات تلگرام</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('wc_telegram_orders_options');
            do_settings_sections('wc-telegram-orders');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function () {
    register_setting('wc_telegram_orders_options', 'wc_telegram_bot_token');
    register_setting('wc_telegram_orders_options', 'wc_telegram_chat_id');

    add_settings_section('wc_telegram_orders_main', '', null, 'wc-telegram-orders');

    add_settings_field(
        'wc_telegram_bot_token',
        'توکن ربات تلگرام',
        function () {
            $val = get_option('wc_telegram_bot_token', '');
            echo "<input type='text' name='wc_telegram_bot_token' value='$val' style='width:400px'>";
        },
        'wc-telegram-orders',
        'wc_telegram_orders_main'
    );

    add_settings_field(
        'wc_telegram_chat_id',
        'Chat ID',
        function () {
            $val = get_option('wc_telegram_chat_id', '');
            echo "<input type='text' name='wc_telegram_chat_id' value='$val' style='width:400px'>";
        },
        'wc-telegram-orders',
        'wc_telegram_orders_main'
    );
});


add_action('woocommerce_checkout_order_processed', function ($order_id) {
    $bot_token = get_option('wc_telegram_bot_token');
    $chat_id = get_option('wc_telegram_chat_id');

    if (!$bot_token || !$chat_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $name = $order->get_formatted_billing_full_name();
    $phone = $order->get_billing_phone();
    $total_amount = $order->get_total();
    $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_NOQUOTES, 'UTF-8');
    $total = $total_amount . ' ' . $currency_symbol;
    $payment = $order->get_payment_method_title();
    $shipping = $order->get_shipping_method();
    $address = $order->get_formatted_billing_address();

    $items_list = '';
    foreach ($order->get_items() as $item) {
        $product_name = $item->get_name();
        $quantity = $item->get_quantity();
        $items_list .= "- {$product_name} ({$quantity} عدد)\n";
    }

    $message = "📦 سفارش جدید #{$order_id}\n\n";
    $message .= "👤 مشتری: {$name}\n";
    $message .= "📞 تلفن: {$phone}\n";
    $message .= "💰 مبلغ کل: {$total}\n";
    $message .= "💳 روش پرداخت: {$payment}\n";
    $message .= "🚚 روش ارسال: {$shipping}\n";
    $message .= "🏠 آدرس: {$address}\n\n";
    $message .= "🛍 محصولات:\n{$items_list}";

    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

    $args = [
        'body' => [
            'chat_id' => $chat_id,
            'text' => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '📦 سفارش‌های امروز', 'callback_data' => 'today_orders'],
                        ['text' => '📊 گزارش فروش', 'callback_data' => 'sales_report'],
                        ['text' => '🔍 جستجوی سفارش', 'callback_data' => 'search_order']
                    ]
                ]
            ])
        ]
    ];

    wp_remote_post($url, $args);
}, 10, 1);


add_action('init', function () {
    $bot_token = get_option('wc_telegram_bot_token');
    $update = file_get_contents('php://input');
    if (!$update) return;
    $update = json_decode($update, true);

    if (isset($update['callback_query'])) {
        $callback_id = $update['callback_query']['id'];
        $chat_id = $update['callback_query']['message']['chat']['id'];
        $data = $update['callback_query']['data'];
        $response_text = '';

        if ($data === 'today_orders') {
            $today = date('Y-m-d');
            $orders = wc_get_orders(['limit' => -1, 'date_created' => $today]);
            $response_text = "📦 سفارش‌های امروز:\n";
            foreach ($orders as $order) {
                $response_text .= "#{$order->get_id()} - {$order->get_formatted_billing_full_name()} - {$order->get_total()} " . html_entity_decode(get_woocommerce_currency_symbol(), ENT_NOQUOTES, 'UTF-8') . "\n";
            }
        } elseif ($data === 'sales_report') {
            $today = date('Y-m-d');
            $orders = wc_get_orders(['limit' => -1, 'date_created' => $today]);
            $total_sales = 0;
            foreach ($orders as $order) {
                $total_sales += $order->get_total();
            }
            $response_text = "📊 گزارش فروش امروز:\nتعداد سفارش: " . count($orders) . "\nمجموع فروش: {$total_sales} " . html_entity_decode(get_woocommerce_currency_symbol(), ENT_NOQUOTES, 'UTF-8');
        } elseif ($data === 'search_order') {
            $response_text = "لطفاً شماره سفارش را ارسال کنید:";
        }

        if ($response_text) {
            $url = "https://api.telegram.org/bot{$bot_token}/answerCallbackQuery";
            wp_remote_post($url, [
                'body' => [
                    'callback_query_id' => $callback_id,
                    'text' => $response_text,
                    'show_alert' => true
                ]
            ]);
        }
    }

    
    if (isset($update['message']['text'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text']);

        if (is_numeric($text)) {
            $order = wc_get_order($text);
            if ($order) {
                $name = $order->get_formatted_billing_full_name();
                $phone = $order->get_billing_phone();
                $total_amount = $order->get_total();
                $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_NOQUOTES, 'UTF-8');
                $total = $total_amount . ' ' . $currency_symbol;
                $payment = $order->get_payment_method_title();
                $shipping = $order->get_shipping_method();
                $address = $order->get_formatted_billing_address();

                $items_list = '';
                foreach ($order->get_items() as $item) {
                    $product_name = $item->get_name();
                    $quantity = $item->get_quantity();
                    $items_list .= "- {$product_name} ({$quantity} عدد)\n";
                }

                $message = "📦 سفارش #{$order->get_id()}\n\n";
                $message .= "👤 مشتری: {$name}\n";
                $message .= "📞 تلفن: {$phone}\n";
                $message .= "💰 مبلغ کل: {$total}\n";
                $message .= "💳 روش پرداخت: {$payment}\n";
                $message .= "🚚 روش ارسال: {$shipping}\n";
                $message .= "🏠 آدرس: {$address}\n\n";
                $message .= "🛍 محصولات:\n{$items_list}";
            } else {
                $message = "⚠️ سفارش با شماره {$text} یافت نشد.";
            }

            $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            wp_remote_post($url, ['body' => ['chat_id' => $chat_id, 'text' => $message]]);
        }
    }
});
