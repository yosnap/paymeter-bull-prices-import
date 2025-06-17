<?php
/*
Plugin Name: Update Pricing Rates for JetBooking
Description: Plugin para subir archivo CSV y actualizar tarifas de productos de JetBooking en WooCommerce.
Version: 1.5
Author: Paulo G. - YoSn4p
*/

session_start();

// Mostrar el formulario de subida
function upr_display_upload_form()
{
    if (!current_user_can('manage_options')) {
        return 'No tienes permisos suficientes para ver este formulario.';
    }

    $html = '<form enctype="multipart/form-data" method="post" action="' . admin_url('admin-post.php') . '">';
    $html .= '<input type="hidden" name="action" value="upr_process_file" />';
    $html .= '<input type="file" name="pricing_file" />';
    $html .= '<input type="submit" name="upload_pricing_file" value="Upload" />';
    $html .= '</form>';

    return $html;
}
add_shortcode('upr_upload_form', 'upr_display_upload_form');

// Procesar el archivo subido y actualizar tarifas
function upr_process_uploaded_file()
{
    if (isset($_POST['upload_pricing_file'])) {
        if (isset($_FILES['pricing_file']) && $_FILES['pricing_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['pricing_file']['tmp_name'];
            $csv_data = array_map('str_getcsv', file($file));

            // Obtener encabezados
            $headers = array_shift($csv_data);

            $updated_products = [];
            $not_found_products = [];

            foreach ($csv_data as $row) {
                $product_id = $row[0];
                // Comprobar si el producto existe
                if (get_post_status($product_id) !== false) {
                    // Obtener tarifas directamente (sin omitir segunda columna)
                    // $tarifas = array_slice($row, 1);
                    // Excluir la última columna (min-days)
                    $tarifas = array_slice($row, 1, -1);

                    // El primer día se guarda en _apartment_price y como precio del producto
                    $apartment_price = $tarifas[0];

                    // Crear array de tarifas con duración y valor desde el día 2
                    $pricing_rates = [];
                    for ($i = 1; $i < count($tarifas); $i++) {
                        $calculated_value = $tarifas[$i] / ($i + 1);  // Dividir el precio del día por su número
                        $pricing_rates[] = [
                            'duration' => (string)($i + 1),
                            'value' => $calculated_value,
                        ];
                    }

                    // Crear el array jet_abaf_price
                    $jet_abaf_price = [
                        '_apartment_price' => $apartment_price,
                        '_pricing_rates' => $pricing_rates,
                        '_weekend_prices' => [
                            'sun' => ['price' => 0, 'active' => false],
                            'mon' => ['price' => 0, 'active' => false],
                            'tue' => ['price' => 0, 'active' => false],
                            'wed' => ['price' => 0, 'active' => false],
                            'thu' => ['price' => 0, 'active' => false],
                            'fri' => ['price' => 0, 'active' => false],
                            'sat' => ['price' => 0, 'active' => false],
                        ],
                        'ID' => $product_id,
                        'action' => 'jet_abaf_price',
                        'nonce' => wp_create_nonce('jet_abaf_price'),
                        'isTrusted' => true,
                    ];

                    // Obtener el valor de min-days desde la última columna del CSV
                    $min_days = isset($row[count($row) - 1]) ? trim($row[count($row) - 1]) : '';
                    
                    // Solo actualizar si min_days tiene valor
                    if (!empty($min_days)) {
                        // Crear la configuración correctamente como array asociativo
                        $configuracion = [
                            'config' => [
                                'enable_config' => true,
                                'booking_period' => 'per_nights',
                                'allow_checkout_only' => false,
                                'one_day_bookings' => false,
                                'weekly_bookings' => false,
                                'week_offset' => '',
                                'start_day_offset' => '',
                                'min_days' => $min_days,
                                'max_days' => '',
                                'end_date_type' => 'none',
                                'end_date_range_number' => '1',
                                'end_date_range_unit' => 'year',
                            ],
                            'ID' => $product_id,
                            'action' => 'jet_abaf_configuration',
                            'nonce' => wp_create_nonce('jet_abaf_configuration'),
                        ];

                        // Eliminar el meta antes de guardar para evitar residuos de estructuras previas
                        delete_post_meta($product_id, 'jet_abaf_configuration');

                        // Guardar la configuración actualizada como array asociativo
                        update_post_meta($product_id, 'jet_abaf_configuration', $configuracion);
                    }

                    // Actualizar las tarifas del producto
                    if ($product_id && !empty($pricing_rates)) {
                        update_post_meta($product_id, '_apartment_price', $apartment_price);
                        update_post_meta($product_id, '_pricing_rates', $pricing_rates);
                        update_post_meta($product_id, 'jet_abaf_price', $jet_abaf_price);

                        // Actualizar el precio del producto
                        update_post_meta($product_id, '_price', $apartment_price);
                        update_post_meta($product_id, '_regular_price', $apartment_price);

                        $product = wc_get_product($product_id);
                        $updated_products[] = $product->get_name();
                    }
                } else {
                    $not_found_products[] = $product_id;
                }
            }

            $messages = [];

            if (!empty($updated_products)) {
                $messages[] = 'Productos actualizados correctamente:';
                foreach ($updated_products as $product_name) {
                    $messages[] = ' - ' . $product_name;
                }
            }

            if (!empty($not_found_products)) {
                $messages[] = 'Productos con ID no encontrado: ' . implode(', ', $not_found_products);
            }

            $_SESSION['upr_message'] = implode('\n', $messages);
            wp_redirect(wp_get_referer());
            exit;
        } else {
            $_SESSION['upr_message'] = 'Error subiendo el archivo.';
            wp_redirect(wp_get_referer());
            exit;
        }
    }
}
add_action('admin_post_upr_process_file', 'upr_process_uploaded_file');

// Mostrar mensajes de éxito o error como alertas
function upr_show_admin_notices()
{
    if (isset($_SESSION['upr_message'])) {
        $message = esc_html($_SESSION['upr_message']);
        echo "<script type='text/javascript'>alert('$message');</script>";
        unset($_SESSION['upr_message']);
    }
}
add_action('wp_footer', 'upr_show_admin_notices');
