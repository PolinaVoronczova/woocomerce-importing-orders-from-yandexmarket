<?php

/*
Plugin Name: Мой плагин WooCommerce для импорта заказов из Яндекс.Маркета (Фишер)
Description: Плагин забирает заказы с Яндекс.Маркета и создает соответствующие заказы в WooCommerce.
Version: 1.2
*/

// Планировщик задач для выполнения импорта заказов
add_action('my_custom_import_orders_schedule', 'my_custom_import_orders');

// Запускаем планировщик задач
register_activation_hook(__FILE__, 'my_custom_import_orders_activate');
register_deactivation_hook(__FILE__, 'my_custom_import_orders_deactivate');

function my_custom_import_orders_activate() {
    if (!wp_next_scheduled('my_custom_import_orders_schedule')) {
        wp_schedule_event(time(), 'daily', 'my_custom_import_orders_schedule');
    }
}

function my_custom_import_orders_deactivate() {
    wp_clear_scheduled_hook('my_custom_import_orders_schedule');
}

// Подключаем хук для выполнения задачи импорта заказов
add_action('my_custom_import_orders_schedule', 'my_custom_import_orders');

function my_custom_import_orders() {
    // Получаем заказы с Яндекс.Маркета
    $api_key = 'MY_API_KEY';
    $api_url = 'https://api.market.yandex.ru/v2/orders';

    // Определяем период времени для получения заказов (7 дней назад до текущей даты)
    $start_date = date('Y-m-d H:i:s', strtotime('-7 days'));
    $end_date = date('Y-m-d H:i:s');

    // Формируем параметры запроса
    $query_params = array(
        'from' => $start_date,
        'to' => $end_date,
    );

    // Отправляем запрос по API Яндекс.Маркет
    $response = wp_remote_get(add_query_arg($query_params, $api_url), array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
    ));

    // Обрабатываем ответ от API Яндекс.Маркет
    if (!is_wp_error($response)) {
        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code == 200) {
            // Успешно получили заказы с Яндекс.Маркета
            $orders_data = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($orders_data['orders']) && !empty($orders_data['orders'])) {
                $imported_count = 0;

                foreach ($orders_data['orders'] as $order_data) {
                    // Проверяем, существует ли заказ с таким же Идентификатором в WooCommerce
                    if (!is_existing_order($order_data['order_id'])) {
                        // Если заказ не существует, создаем новый заказ в WooCommerce
                        $new_order_id = create_new_order($order_data);

                        if ($new_order_id) {
                            $imported_count++;
                        }
                    }
                }

                // Выводим сообщение о результате импорта
                echo '<div class="notice notice-success"><p>Импорт завершен. Импортировано ' . $imported_count . ' заказов.</p></div>';
            } else {
                // Нет доступных заказов для импорта
                echo '<div class="notice notice-warning"><p>Нет доступных заказов для импорта.</p></div>';
            }
        } else {
            // Произошла ошибка при получении заказов с Яндекс.Маркета
            echo '<div class="notice notice-error"><p>Ошибка при получении заказов с Яндекс.Маркета.</p></div>';
        }
    }
}

/**
 * Функция для проверки существования заказа с заданным Идентификатором в WooCommerce
 */
function is_existing_order($order_id) {
    $existing_order = wc_get_order_id_by_order_key($order_id);
    return !empty($existing_order);
}

/**
 * Функция для создания нового заказа в WooCommerce
 */
function create_new_order($order_data) {
    // Проверяем, есть ли заказ с таким же номером
    $existing_order = wc_get_order($order_data['order_number']);

    if (!empty($existing_order)) {
        // Заказ с таким номером уже существует, пропускаем его
        return false;
    }

    $order = wc_create_order();

    // Устанавливаем номер заказа
    $order->set_order_number($order_data['order_number']);

    // Добавляем информацию о покупателе
    $billing_address = array(
        'first_name' => $order_data['delivery']['address']['first_name'],
        'last_name' => $order_data['delivery']['address']['last_name'],
        'address_1' => $order_data['delivery']['address']['address'],
        'city' => $order_data['delivery']['address']['city'],
        'postcode' => $order_data['delivery']['address']['postcode'],
        'country' => $order_data['delivery']['address']['country'],
        'email' => $order_data['buyer']['email'],
        'phone' => $order_data['buyer']['phone'],
    );

    $order->set_address($billing_address, 'billing');

    // Добавляем товары в заказ
    foreach ($order_data['cart']['items'] as $item) {
        $product_id = get_product_id($item['offer']['id']);

        if ($product_id) {
            $order->add_product(get_product($product_id), $item['quantity']);
        }
    }

    // Устанавливаем цену и статус заказа
    $order->set_total($order_data['total_price'], 'total');
    $order->update_status('completed');

    // Сохраняем заказ
    $order->save();

    return $order->get_id();
}

/**
 * Функция для получения идентификатора товара из WooCommerce на основе артикула товара
 */
function get_product_id($offer_id) {
    // Ваш код для получения идентификатора товара в WooCommerce
    // В данном примере возвращается артикул товара в качестве идентификатора товара, так как артикул совпадает с идентификатором товара в WooCommerce

    $product_id = wc_get_product_id_by_sku($offer_id);

    return $product_id;
}
