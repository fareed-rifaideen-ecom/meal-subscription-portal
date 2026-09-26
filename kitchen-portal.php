<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. AJAX HANDLERS: KITCHEN ACTIONS & EXPORT
// ==========================================

add_action('wp_ajax_cmp_kitchen_action', 'cmp_ajax_kitchen_action');
function cmp_ajax_kitchen_action() {
    check_ajax_referer('cmp_kitchen_nonce', 'nonce');
    global $wpdb;
    
    $log_id = intval($_POST['log_id']);
    $field = sanitize_text_field($_POST['field']);
    $value = sanitize_text_field($_POST['value']);
    
    $is_admin = current_user_can('manage_options') || current_user_can('menu_manager');
    $is_chef = current_user_can('kitchen_staff');
    $is_foh = current_user_can('foh_manager');

    $current_log = $wpdb->get_row($wpdb->prepare("SELECT pos_updated FROM {$wpdb->prefix}cmp_daily_logs WHERE id = %d", $log_id));

    if ($field === 'dispatch_status' || $field === 'delivery_result') {
        if (!$is_admin && !$is_chef) wp_send_json_error('Permission Denied');
        if ($current_log && $current_log->pos_updated == 1) {
            wp_send_json_error('Locked: FOH has already completed the POS Check.');
        }
    } elseif ($field === 'pos_updated') {
        if (!$is_admin && !$is_foh) wp_send_json_error('Permission Denied');
    } elseif ($field === 'is_prepared') {
        if (!$is_admin && !$is_chef) wp_send_json_error('Permission Denied');
    }

    $wpdb->update($wpdb->prefix . 'cmp_daily_logs', array($field => $value), array('id' => $log_id));
    wp_send_json_success();
}

add_action('wp_ajax_cmp_assign_chef_meals', 'cmp_ajax_assign_chef_meals');
function cmp_ajax_assign_chef_meals() {
    check_ajax_referer('cmp_kitchen_nonce', 'nonce');
    global $wpdb;
    
    if (!current_user_can('manage_options') && !current_user_can('kitchen_staff') && !current_user_can('menu_manager')) {
        wp_send_json_error('Permission Denied');
    }
    
    $log_id = intval($_POST['log_id']);
    $table_foods = $wpdb->prefix . 'cmp_foods';

    $log_row = $wpdb->get_row($wpdb->prepare("SELECT subscription_id, target_date FROM {$wpdb->prefix}cmp_daily_logs WHERE id = %d", $log_id));
    
    if ($log_row) {
        
        // --- STRICT MENU AVAILABILITY CHECK ---
        $has_meals = !empty($_POST['breakfast']) || !empty($_POST['lunch']) || !empty($_POST['dinner']) || !empty($_POST['snack_1']) || !empty($_POST['snack_2']) || !empty($_POST['juice_1']) || !empty($_POST['juice_2']) || !empty($_POST['juice_3']);
        
        if ($has_meals) {
            $food_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_foods WHERE is_active = 1 AND valid_from <= %s AND valid_until >= %s", $log_row->target_date, $log_row->target_date));
            if (intval($food_exists) === 0) {
                wp_send_json_error('The menu for this date is pending release. Meals cannot be assigned yet.');
            }
        }

        $sub = $wpdb->get_row($wpdb->prepare("SELECT plan_name, allowed_categories FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", $log_row->subscription_id));
        if ($sub) {
            $is_juice = (stripos($sub->allowed_categories, 'Juices') !== false || stripos($sub->plan_name, 'juice') !== false || stripos($sub->plan_name, 'cleanse') !== false);
            preg_match('/(\d+)\s*Meal/i', $sub->plan_name, $m);
            $allowed_quota = isset($m[1]) ? intval($m[1]) : 0;

            if (!$is_juice && $allowed_quota > 0) {
                $submitted_count = 0;
                if (!empty($_POST['breakfast'])) $submitted_count++;
                if (!empty($_POST['lunch'])) $submitted_count++;
                if (!empty($_POST['dinner'])) $submitted_count++;

                if ($submitted_count > $allowed_quota) {
                    wp_send_json_error("Quota Exceeded! This customer's plan only allows {$allowed_quota} main meal(s).");
                }
            }
        }
    }

    $data = array(
        'breakfast_id' => !empty($_POST['breakfast']) ? intval($_POST['breakfast']) : null,
        'lunch_id'     => !empty($_POST['lunch']) ? intval($_POST['lunch']) : null,
        'dinner_id'    => !empty($_POST['dinner']) ? intval($_POST['dinner']) : null,
        'snack_1_id'   => !empty($_POST['snack_1']) ? intval($_POST['snack_1']) : null,
        'snack_2_id'   => !empty($_POST['snack_2']) ? intval($_POST['snack_2']) : null,
        'juice_1_id'   => !empty($_POST['juice_1']) ? intval($_POST['juice_1']) : null,
        'juice_2_id'   => !empty($_POST['juice_2']) ? intval($_POST['juice_2']) : null,
        'juice_3_id'   => !empty($_POST['juice_3']) ? intval($_POST['juice_3']) : null,
    );
    
    $wpdb->update($wpdb->prefix . 'cmp_daily_logs', $data, array('id' => $log_id));
    wp_send_json_success();
}

add_action('wp_ajax_cmp_export_kitchen_csv', 'cmp_export_kitchen_csv');
function cmp_export_kitchen_csv() {
    if (!is_user_logged_in() || (!current_user_can('manage_options') && !current_user_can('kitchen_staff') && !current_user_can('foh_manager') && !current_user_can('menu_manager'))) {
        wp_die('Permission Denied');
    }

    global $wpdb;
    
    // Auto-create is_prepared column if it doesn't exist
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $col_check = $wpdb->get_results("SHOW COLUMNS FROM {$table_logs} LIKE 'is_prepared'");
    if (empty($col_check)) { $wpdb->query("ALTER TABLE {$table_logs} ADD is_prepared TINYINT(1) DEFAULT 0 AFTER is_chefs_choice"); }

    $prep_date = isset($_GET['prep_date']) ? sanitize_text_field($_GET['prep_date']) : date('Y-m-d');
    $export_type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'meals'; 
    
    // SMART DATE MATH: Prep Date is ALWAYS Target Date - 1 day.
    $target_date = date('Y-m-d', strtotime($prep_date . ' + 1 day'));
    
    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, u.display_name, u.user_email 
         FROM {$wpdb->prefix}cmp_daily_logs l 
         JOIN {$wpdb->prefix}cmp_subscriptions s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON l.user_id = u.ID 
         WHERE l.target_date = %s AND l.is_locked = 1", 
        $target_date
    ) );

    $foods = $wpdb->get_results("SELECT id, food_name FROM {$wpdb->prefix}cmp_foods");
    $food_map = array(); foreach ($foods as $f) { $food_map[$f->id] = $f->food_name; }

    $filename_suffix = ($export_type === 'juices') ? 'Juice_Plans' : 'Meal_Plans';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Kitchen_Report_' . $filename_suffix . '_' . $prep_date . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

    if ($export_type === 'juices') {
        fputcsv($output, array('Order ID', 'Customer Name', 'Email', 'Phone', 'Eating Date (Target)', 'Address', 'Method', 'Receive By', 'Delivery Date', 'Time Slot', 'Allergies', 'Plan', 'Juice 1', 'Juice 2', 'Juice 3', 'Prepared', 'Dispatched', 'Delivery', 'POS Check'));
    } else {
        fputcsv($output, array('Order ID', 'Customer Name', 'Email', 'Phone', 'Eating Date (Target)', 'Address', 'Method', 'Receive By', 'Delivery Date', 'Time Slot', 'Allergies', 'Plan', 'Breakfast', 'Lunch', 'Dinner', 'Snack 1', 'Snack 2', 'Prepared', 'Dispatched', 'Delivery', 'POS Check'));
    }

    $export_rows = array();

    foreach ($logs as $log) {
        $is_juice_plan = (stripos($log->allowed_categories, 'Juices') !== false || stripos($log->plan_name, 'juice') !== false || stripos($log->plan_name, 'cleanse') !== false);
        
        if ($export_type === 'juices' && !$is_juice_plan) continue;
        if ($export_type === 'meals' && $is_juice_plan) continue;

        $order = wc_get_order($log->wc_order_id);
        $sub_user = get_userdata($log->user_id);
        
        $fname = get_user_meta($log->user_id, 'first_name', true) ?: get_user_meta($log->user_id, 'billing_first_name', true);
        $lname = get_user_meta($log->user_id, 'last_name', true) ?: get_user_meta($log->user_id, 'billing_last_name', true);
        $fallback_name = trim($fname . ' ' . $lname);
        if (empty($fallback_name)) $fallback_name = $log->display_name;
        
        $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $fallback_name;
        if (empty(trim($full_name))) { $full_name = $fallback_name; }

        $email = $order ? $order->get_billing_email() : ($sub_user ? $sub_user->user_email : $log->user_email);
        $phone = $order ? $order->get_billing_phone() : get_user_meta($log->user_id, 'billing_phone', true);

        $timing = '';
        if ($order) $timing = $order->get_meta('_cmp_delivery_timing') ?: $order->get_meta('delivery_timing');
        if (empty($timing)) $timing = get_user_meta($log->user_id, 'delivery_timing', true) ?: 'N/A';

        // --- NEW VISUAL CLEANUP ---
        $timing = str_ireplace(['Deliver Day Before', 'Deliver Same Day'], ['Day Before', 'Same Day'], $timing);

        $is_day_before = (stripos($timing, 'Day Before') !== false);
        $delivery_date = $is_day_before ? $prep_date : $log->target_date;

        $time_slot = '';
        if ($order) $time_slot = $order->get_meta('_cmp_time_slot') ?: $order->get_meta('time_slot');
        if (empty($time_slot)) $time_slot = get_user_meta($log->user_id, 'time_slot', true) ?: 'N/A';

        $method = '';
        if ($order) $method = $order->get_meta('_cmp_logistics_method') ?: $order->get_meta('delivery_method');
        if (empty($method)) $method = get_user_meta($log->user_id, 'delivery_method', true) ?: 'N/A';

        $pickup = '';
        if ($order) $pickup = $order->get_meta('_cmp_pickup_location') ?: $order->get_meta('pickup_location');
        if (empty($pickup)) $pickup = get_user_meta($log->user_id, 'pickup_location', true);

        $allergies = '';
        if ($order) $allergies = $order->get_customer_note();
        if (empty($allergies)) $allergies = get_user_meta($log->user_id, 'allergies', true) ?: 'No Allergies';

        $address = 'Address not provided';
        if ($order) {
            $addr_parts = array_filter([$order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city()]);
            if (empty($addr_parts)) $addr_parts = array_filter([$order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city()]);
            if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
        }
        if ($address === 'Address not provided') {
            $addr_parts = array_filter([get_user_meta($log->user_id, 'billing_address_1', true), get_user_meta($log->user_id, 'billing_address_2', true), get_user_meta($log->user_id, 'billing_city', true)]);
            if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
        }

        if ($method === 'Pickup' && !empty($pickup)) $method .= ' (' . $pickup . ')';

        $is_assigned = ($log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id);
        $chef_tag = $log->is_chefs_choice ? ' (Chef)' : '';
        $pending_tag = "[Pending Chef]";

        $prepared = $log->is_prepared ? 'Yes' : 'No';
        $dispatched = $log->dispatch_status ? 'Yes' : 'No';
        $delivery = $log->delivery_result ?: 'Pending';
        $pos_check = $log->pos_updated ? 'Yes' : 'No';
        
        // Fulfillment Tracker
        $day_number = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM {$wpdb->prefix}cmp_daily_logs 
             WHERE subscription_id = %d AND target_date <= %s 
             AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))",
            $log->subscription_id, $log->target_date
        ));
        
        $plan_parts = explode(' - ', $log->plan_name);
        $plan_string = $plan_parts[0] . " ({$day_number}/{$log->total_days})";
        if (isset($plan_parts[1])) $plan_string .= " - " . $plan_parts[1];
        
        $order_id_display = $log->wc_order_id > 0 ? '#' . $log->wc_order_id : 'Manual';

        $row_data = array();

        if ($export_type === 'juices') {
            $j1 = '-'; $j2 = '-'; $j3 = '-';
            if ($log->is_chefs_choice && !$is_assigned) {
                $j1 = $pending_tag; $j2 = $pending_tag; $j3 = $pending_tag;
            } else {
                if ($log->juice_1_id) $j1 = ($food_map[$log->juice_1_id] ?? 'Unknown') . $chef_tag;
                if ($log->juice_2_id) $j2 = ($food_map[$log->juice_2_id] ?? 'Unknown') . $chef_tag;
                if ($log->juice_3_id) $j3 = ($food_map[$log->juice_3_id] ?? 'Unknown') . $chef_tag;
            }
            $row_data = array($order_id_display, $full_name, $email, $phone, $log->target_date, $address, $method, $timing, $delivery_date, $time_slot, $allergies, $plan_string, $j1, $j2, $j3, $prepared, $dispatched, $delivery, $pos_check);
        
        } else {
            $b = '-'; $l = '-'; $d = '-'; $s1 = '-'; $s2 = '-';
            if ($log->is_chefs_choice && !$is_assigned) {
                $b = $pending_tag; $l = $pending_tag; $d = $pending_tag; $s1 = $pending_tag; $s2 = $pending_tag;
            } else {
                if ($log->breakfast_id) $b = ($food_map[$log->breakfast_id] ?? 'Unknown') . $chef_tag;
                if ($log->lunch_id)     $l = ($food_map[$log->lunch_id] ?? 'Unknown') . $chef_tag;
                if ($log->dinner_id)    $d = ($food_map[$log->dinner_id] ?? 'Unknown') . $chef_tag;
                if ($log->snack_1_id)   $s1 = ($food_map[$log->snack_1_id] ?? 'Unknown') . $chef_tag;
                if ($log->snack_2_id)   $s2 = ($food_map[$log->snack_2_id] ?? 'Unknown') . $chef_tag;
            }
            $row_data = array($order_id_display, $full_name, $email, $phone, $log->target_date, $address, $method, $timing, $delivery_date, $time_slot, $allergies, $plan_string, $b, $l, $d, $s1, $s2, $prepared, $dispatched, $delivery, $pos_check);
        }
        
        $export_rows[] = array(
            'data' => $row_data,
            'is_day_before' => $is_day_before ? 1 : 0
        );
    }
    
    // Sort array: Day Before = 1 goes first
    usort($export_rows, function($a, $b) {
        return $b['is_day_before'] - $a['is_day_before'];
    });

    foreach($export_rows as $er) {
        fputcsv($output, $er['data']);
    }

    fclose($output);
    exit;
}

// ==========================================
// 2. KITCHEN REPORTING PORTAL
// ==========================================
add_shortcode( 'meal_kitchen_portal', 'cmp_render_kitchen_portal' );
function cmp_render_kitchen_portal() {
    date_default_timezone_set('Asia/Dubai');

    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-kitchen-login', 'label_username' => __('Email Address or Username'), 'label_password' => __('Password'));
        $custom_css = '<style>#cmp-kitchen-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; } #cmp-kitchen-login input[type="text"], #cmp-kitchen-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; } #cmp-kitchen-login .login-submit input[type="submit"] { width: 100%; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; box-sizing: border-box; } #cmp-kitchen-login .login-remember { text-align: left; margin-bottom: 15px; }</style>';
        return $custom_css . '<div style="max-width: 400px; margin: 50px auto; padding: 30px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);"><h2 style="text-align: center; margin-top: 0; color: #222;">Kitchen Portal</h2><p style="text-align: center; color: #666; margin-bottom: 20px;">Please log in with your staff account.</p>' . wp_login_form( $login_args ) . '</div>';
    }

    $is_admin = current_user_can('manage_options') || current_user_can('menu_manager');
    $is_chef = current_user_can('kitchen_staff');
    $is_foh = current_user_can('foh_manager');

    if ( !$is_admin && !$is_chef && !$is_foh ) {
        return '<p style="padding: 20px; background: #fff; border-left: 4px solid #dc3232;">Access Denied. Kitchen Staff or FOH only.</p>';
    }

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    
    // Auto-create is_prepared column if it doesn't exist
    $col_check = $wpdb->get_results("SHOW COLUMNS FROM {$table_logs} LIKE 'is_prepared'");
    if (empty($col_check)) { $wpdb->query("ALTER TABLE {$table_logs} ADD is_prepared TINYINT(1) DEFAULT 0 AFTER is_chefs_choice"); }

    $selected_date = isset($_GET['prep_date']) ? sanitize_text_field($_GET['prep_date']) : date('Y-m-d');
    
    // SMART DATE MATH: Prep Date is ALWAYS Target Date - 1 day.
    $target_date = date('Y-m-d', strtotime($selected_date . ' + 1 day'));

    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, u.display_name, u.user_email 
         FROM {$table_logs} l 
         JOIN {$wpdb->prefix}cmp_subscriptions s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON l.user_id = u.ID 
         WHERE l.target_date = %s AND l.is_locked = 1 
         ORDER BY l.id ASC", 
        $target_date
    ) );

    // --- FETCH ALL FOODS FOR JS INJECTION ---
    $foods = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cmp_foods WHERE is_active = 1 ORDER BY category_name, food_name");
    $food_map = array(); foreach ($foods as $f) { $food_map[$f->id] = $f->food_name; }

    $customers = array();

    foreach ($logs as $log) {
        $order = wc_get_order($log->wc_order_id);
        $sub_user = get_userdata($log->user_id);
        
        $fname = get_user_meta($log->user_id, 'first_name', true) ?: get_user_meta($log->user_id, 'billing_first_name', true);
        $lname = get_user_meta($log->user_id, 'last_name', true) ?: get_user_meta($log->user_id, 'billing_last_name', true);
        $fallback_name = trim($fname . ' ' . $lname);
        if (empty($fallback_name)) $fallback_name = $log->display_name;
        
        $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $fallback_name;
        if (empty(trim($full_name))) { $full_name = $fallback_name; }

        $email = $order ? $order->get_billing_email() : ($sub_user ? $sub_user->user_email : $log->user_email);
        $phone = $order ? $order->get_billing_phone() : get_user_meta($log->user_id, 'billing_phone', true);

        $timing = '';
        if ($order) $timing = $order->get_meta('_cmp_delivery_timing') ?: $order->get_meta('delivery_timing');
        if (empty($timing)) $timing = get_user_meta($log->user_id, 'delivery_timing', true) ?: 'N/A';

        // --- NEW VISUAL CLEANUP ---
        $timing = str_ireplace(['Deliver Day Before', 'Deliver Same Day'], ['Day Before', 'Same Day'], $timing);

        $is_day_before = (stripos($timing, 'Day Before') !== false);
        $delivery_date = $is_day_before ? $selected_date : $log->target_date;

        $time_slot = '';
        if ($order) $time_slot = $order->get_meta('_cmp_time_slot') ?: $order->get_meta('time_slot');
        if (empty($time_slot)) $time_slot = get_user_meta($log->user_id, 'time_slot', true) ?: 'N/A';

        $method = '';
        if ($order) $method = $order->get_meta('_cmp_logistics_method') ?: $order->get_meta('delivery_method');
        if (empty($method)) $method = get_user_meta($log->user_id, 'delivery_method', true) ?: 'N/A';

        $pickup = '';
        if ($order) $pickup = $order->get_meta('_cmp_pickup_location') ?: $order->get_meta('pickup_location');
        if (empty($pickup)) $pickup = get_user_meta($log->user_id, 'pickup_location', true);

        $allergies = '';
        if ($order) $allergies = $order->get_customer_note();
        if (empty($allergies)) $allergies = get_user_meta($log->user_id, 'allergies', true) ?: 'No Allergies';

        $address = 'Address not provided';
        if ($order) {
            $addr_parts = array_filter([$order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city()]);
            if (empty($addr_parts)) $addr_parts = array_filter([$order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city()]);
            if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
        }
        if ($address === 'Address not provided') {
            $addr_parts = array_filter([get_user_meta($log->user_id, 'billing_address_1', true), get_user_meta($log->user_id, 'billing_address_2', true), get_user_meta($log->user_id, 'billing_city', true)]);
            if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
        }

        if ($method === 'Pickup' && !empty($pickup)) $method .= ' (' . $pickup . ')';

        $meals_list = array();
        $is_assigned = ($log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id);

        if ($log->is_chefs_choice && !$is_assigned) {
            $meals_list[] = "Chef's Choice (Pending Assignment)";
        } elseif ($log->juice_1_id) {
            $meals_list[] = "Juice 1: " . ($food_map[$log->juice_1_id] ?? 'Unknown');
            $meals_list[] = "Juice 2: " . ($food_map[$log->juice_2_id] ?? 'Unknown');
            $meals_list[] = "Juice 3: " . ($food_map[$log->juice_3_id] ?? 'Unknown');
        } else {
            if ($log->breakfast_id) $meals_list[] = "Breakfast: " . ($food_map[$log->breakfast_id] ?? 'Unknown');
            if ($log->lunch_id)     $meals_list[] = "Lunch: " . ($food_map[$log->lunch_id] ?? 'Unknown');
            if ($log->dinner_id)    $meals_list[] = "Dinner: " . ($food_map[$log->dinner_id] ?? 'Unknown');
            if ($log->snack_1_id)   $meals_list[] = "Snack 1: " . ($food_map[$log->snack_1_id] ?? 'Unknown');
            if ($log->snack_2_id)   $meals_list[] = "Snack 2: " . ($food_map[$log->snack_2_id] ?? 'Unknown');
        }

        // Fetch Fulfillment Day Number
        $day_number = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM {$table_logs} 
             WHERE subscription_id = %d AND target_date <= %s 
             AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))",
            $log->subscription_id, $log->target_date
        ));

        $customers[] = array(
            'log_id' => $log->id, 
            'order_id' => $log->wc_order_id,
            'name' => $full_name, 
            'phone' => $phone, 
            'email' => $email, 
            'address' => $address,
            'method' => $method, 
            'timing' => $timing, 
            'delivery_date' => $delivery_date, 
            'time_slot' => $time_slot, 
            'plan' => $log->plan_name,
            'total_days' => $log->total_days,
            'day_number' => $day_number,
            'allergies' => !empty($allergies) ? $allergies : 'No Allergies', 
            'prepared' => $log->is_prepared,
            'dispatch' => $log->dispatch_status,
            'delivery' => $log->delivery_result, 
            'pos' => $log->pos_updated, 
            'meals' => $meals_list,
            'is_chefs_choice' => $log->is_chefs_choice,
            'is_assigned' => $is_assigned,
            'allowed_categories' => $log->allowed_categories,
            'target_date' => $log->target_date, 
            'is_day_before' => $is_day_before ? 1 : 0,
            'raw_log' => $log
        );
    }

    // Sort array: Day Before = 1 goes first (To deliver today after 5PM)
    usort($customers, function($a, $b) {
        return $b['is_day_before'] - $a['is_day_before'];
    });

    $chef_disabled = ($is_admin || $is_chef) ? '' : 'disabled';
    $foh_disabled = ($is_admin || $is_foh) ? '' : 'disabled';

    $count_all = count($customers);
    $count_pending = 0;
    $count_assigned = 0;
    foreach ($customers as $c) {
        if ($c['is_chefs_choice']) {
            if ($c['is_assigned']) {
                $count_assigned++;
            } else {
                $count_pending++;
            }
        }
    }

    ob_start();
    ?>
    <script>
        var cmpGlobalFoodData = <?php echo json_encode($foods); ?>;
    </script>

    <style>
        .k-table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.85em; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .k-table th { background: #222; color: #fff; padding: 12px 8px; text-align: left; }
        .k-table td { padding: 10px 8px; border-bottom: 1px solid #ddd; vertical-align: middle; }
        .chk-large { transform: scale(1.5); cursor: pointer; }
        
        .k-customer-meta { margin: 2px 0; color: #555; display: flex; align-items: center; gap: 5px; }
        .k-customer-meta svg { width: 12px; height: 12px; flex-shrink: 0; }
        
        .logistics-box { margin-top: 10px; padding: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; line-height: 1.3; }
        
        .k-tab-btn { background: #f1f1f1; border: 1px solid #ccc; padding: 10px 20px; border-radius: 4px; font-weight: bold; color: #333; cursor: pointer; transition: 0.2s; }
        .k-tab-btn.active { background: #0073aa !important; color: #fff !important; border-color: #0073aa !important; }

        @media print {
            .cmp-no-print { display: none !important; }
            body, html { background: #fff !important; margin: 0 !important; padding: 0 !important; }
            .k-table { box-shadow: none !important; border: 1px solid #000; }
            .k-table th, .k-table td { border: 1px solid #000; padding: 6px; }
            select { appearance: none; border: none; background: transparent; }
        }
    </style>

    <div style="max-width: 1400px; margin: 0 auto; font-family: inherit;">
        
        <div class="cmp-no-print" style="background: #f1f1f1; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #ddd; flex-wrap: wrap; gap: 15px;">
            <form method="GET" id="cmp-kitchen-date-form" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                <label style="font-weight: bold; font-size: 1.1em;">Food Preparation Date:</label>
                <input type="date" name="prep_date" value="<?php echo esc_attr($selected_date); ?>" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;" onchange="document.getElementById('cmp-kitchen-date-form').submit();">
            </form>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo site_url('/chef-dashboard/'); ?>" style="background: #0073aa; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; text-decoration: none;">Chef's Assignment ⇗</a>
                
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_kitchen_csv&type=meals&prep_date=' . $selected_date)); ?>" style="background: #1d6f42; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; text-decoration: none;">Export Meals CSV</a>
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_kitchen_csv&type=juices&prep_date=' . $selected_date)); ?>" style="background: #0ea5e9; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; text-decoration: none;">Export Juices CSV</a>
                
                <button onclick="window.print()" style="background: #46b450; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">Print PDF</button>
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" style="background: #dc3232; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; text-decoration: none;">Log Out</a>
            </div>
        </div>

        <!-- STICKY HEADER WRAPPER -->
        <div style="position: sticky; top: 0; z-index: 99; background: #ffffff; text-align: center; border-bottom: 2px solid #222; margin-bottom: 20px; padding: 15px 0 10px 0;">
            <h1 style="margin: 0; font-size: 2em; color: #222;">Order Preparation Report</h1>
            <h3 style="margin: 5px 0 0 0; color: #0073aa;">Food Orders to Prep on: <?php echo date('l, d/m/Y', strtotime($selected_date)); ?></h3>
        </div>

        <div class="cmp-no-print" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom: 20px; background:#f8fafc; padding:15px; border:1px solid #e2e8f0; border-radius:6px;">
            <div class="k-tabs-container" style="display:flex; gap:10px; flex-wrap:wrap;">
                <button class="k-tab-btn active" data-filter="all">All Orders (<?php echo $count_all; ?>)</button>
                <button class="k-tab-btn" data-filter="pending">Pending Chef's Choice <?php if($count_pending > 0) echo '<span style="background:#dc3232; color:#fff; border-radius:10px; padding:2px 8px; font-size:0.8em; margin-left:5px;">'.$count_pending.'</span>'; ?></button>
                <button class="k-tab-btn" data-filter="assigned">Completed Assignments (<?php echo $count_assigned; ?>)</button>
            </div>
            <div style="flex-grow:1; min-width:200px; max-width:350px;">
                <input type="text" id="k-search" placeholder="🔍 Search customers..." style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-size:1em;">
            </div>
        </div>

        <?php if(empty($customers)): ?>
            <p style="text-align: center; font-size: 1.2em; color: #666; padding: 40px; border: 1px dashed #ccc;">No orders found for this prep date.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="k-table">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Customer Details</th>
                            <th style="width: 17%;">Logistics</th>
                            <th style="width: 31%;">The Food</th>
                            <th style="width: 8%; text-align:center;">Prepared</th>
                            <th style="width: 8%; text-align:center;">Dispatch</th>
                            <th style="width: 10%;">Delivery</th>
                            <th style="width: 6%; text-align:center;">POS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($customers as $c): 
                            $allergy_style = ($c['allergies'] !== 'No Allergies') ? 'background:#fff3cd; color:#856404; font-weight:bold; padding:2px 6px; border-radius:3px; display:inline-block; margin-top:3px;' : 'color:#666; font-style:italic; display:inline-block; margin-top:3px;';
                            
                            $prepared_disabled = (!empty($chef_disabled) || $c['dispatch']) ? 'disabled' : '';
                            $dispatch_disabled = (!empty($chef_disabled) || $c['pos']) ? 'disabled' : '';
                            $delivery_disabled = (!$c['dispatch'] || !empty($chef_disabled) || $c['pos']) ? 'disabled' : '';
                            $pos_disabled = (!$c['dispatch'] || $c['delivery'] === 'Pending' || !empty($foh_disabled)) ? 'disabled' : '';

                            // Filter classes for Tabs
                            $row_filter_class = 'filter-standard';
                            if ($c['is_chefs_choice']) {
                                $row_filter_class = $c['is_assigned'] ? 'filter-assigned' : 'filter-pending';
                            }
                            $search_name = esc_attr(strtolower($c['name']));
                            
                            // Splitting Plan Name logically
                            $plan_parts = explode(' - ', $c['plan']);
                            $plan_duration = $plan_parts[0];
                            $plan_meals = isset($plan_parts[1]) ? $plan_parts[1] : '';
                        ?>
                        <tr class="k-row <?php echo $row_filter_class; ?>" data-name="<?php echo $search_name; ?>">
                            <td>
                                <strong style="color: #0073aa; font-size: 1.1em;"><?php echo esc_html($c['name']); ?></strong>
                                
                                <span style="background: #ef4444; color: white; font-size: 0.75em; padding: 2px 6px; border-radius: 4px; margin-left: 8px; vertical-align: middle; white-space:nowrap;">
                                    Eating: <?php echo date('M j', strtotime($c['target_date'])); ?>
                                </span>
                                <br>
                                
                                <div style="margin-top:4px; font-size:0.95em; line-height:1.4;">
                                    <span class="k-customer-meta" style="color: #334155; font-weight: bold;">
                                        Order: <?php echo $c['order_id'] > 0 ? '#' . esc_html($c['order_id']) : '<span style="color:#d63638; font-weight:normal;">Manual</span>'; ?>
                                    </span>
                                    
                                    <span class="k-customer-meta">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                        <?php echo esc_html($c['email']); ?>
                                    </span>
                                    
                                    <span class="k-customer-meta">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                        <?php echo esc_html($c['phone']); ?>
                                    </span>

                                    <span class="k-customer-meta" style="align-items: flex-start;">
                                        <svg xmlns="http://www.w3.org/2000/svg" style="margin-top: 2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                        <?php echo esc_html($c['address']); ?>
                                    </span>
                                </div>

                                <div style="<?php echo $allergy_style; ?>"><?php echo esc_html($c['allergies']); ?></div>

                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #ccc;">
                                    <strong><?php echo esc_html($plan_duration); ?> (<?php echo $c['day_number']; ?>/<?php echo $c['total_days']; ?>)</strong><br>
                                    <span style="color:#64748b; font-size:0.9em;"><?php echo esc_html($plan_meals); ?></span>
                                </div>
                            </td>
                            
                            <td>
                                <div class="logistics-box" style="margin-top:0;">
                                    <span style="color: #475569;"><strong>Method:</strong> <?php echo esc_html($c['method']); ?></span><br>
                                    <span style="color: #475569;"><strong>Receive By:</strong> <?php echo esc_html($c['timing']); ?></span><br>
                                    <span style="color: #0f766e; font-weight:bold;"><strong>Delivery:</strong> <?php echo date('D, M j', strtotime($c['delivery_date'])); ?></span><br>
                                    <span style="color: #475569;"><strong>Time:</strong> <?php echo esc_html($c['time_slot']); ?></span>
                                </div>
                            </td>

                            <td>
                                <?php if ($c['is_chefs_choice']): ?>
                                    
                                    <?php 
                                    $display_style = $c['is_assigned'] ? 'block' : 'none'; 
                                    $form_style = $c['is_assigned'] ? 'none' : 'block'; 
                                    ?>
                                    
                                    <div class="chef-assigned-view" style="display: <?php echo $display_style; ?>;">
                                        <ul style="margin:0; padding-left:15px; line-height: 1.4;">
                                            <?php foreach($c['meals'] as $meal) echo "<li>$meal <span style='color:#379237; font-weight:bold; font-size:0.85em;'>(Chef)</span></li>"; ?>
                                        </ul>
                                        <button class="edit-chef-assign cmp-no-print" style="margin-top:8px; font-size:0.85em; background:#e2e8f0; color:#334155; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">Edit Selection</button>
                                    </div>
                                    
                                    <?php 
                                    $is_juice_plan = (stripos($c['allowed_categories'], 'Juices') !== false || stripos($c['plan'], 'juice') !== false || stripos($c['plan'], 'cleanse') !== false);
                                    preg_match('/(\d+)\s*Meal/i', $c['plan'], $m);
                                    $allowed_meals = isset($m[1]) ? intval($m[1]) : 0;
                                    
                                    if (!$is_juice_plan) {
                                        $allowed_cats = ['Breakfast', 'Lunch', 'Dinner', 'Snacks'];
                                        $snack_count = ($allowed_meals >= 2) ? 2 : 1; 
                                    } else {
                                        $allowed_cats = ['Juices'];
                                        $snack_count = 0;
                                    }
                                    $raw = $c['raw_log'];
                                    ?>
                                    
                                    <div class="chef-assign-form cmp-no-print" data-log-id="<?php echo $c['log_id']; ?>" data-target-date="<?php echo esc_attr($c['target_date']); ?>" data-allowed-meals="<?php echo $allowed_meals; ?>" data-is-juice="<?php echo $is_juice_plan ? '1' : '0'; ?>" style="display: <?php echo $form_style; ?>; background:#fffbdd; padding:10px; border:1px dashed #eab308; border-radius:5px;">
                                        <div style="font-size:0.9em; font-weight:bold; color:#b45309; margin-bottom:8px;">Assign Chef's Choice:</div>
                                        
                                        <?php if(!$is_juice_plan): ?>
                                            <?php foreach(['Breakfast','Lunch','Dinner'] as $cat): if(in_array($cat, $allowed_cats)): ?>
                                                <select class="chef-meal-select chef-main-meal" data-cat="<?php echo $cat; ?>" data-saved-val="<?php echo $raw->{strtolower($cat).'_id'}; ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                    <option value="">- <?php echo $cat; ?> -</option>
                                                </select>
                                            <?php endif; endforeach; ?>
                                            
                                            <?php if($snack_count > 0): ?>
                                                <select class="chef-meal-select" data-cat="Snacks" data-saved-val="<?php echo $raw->snack_1_id; ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                    <option value="">- Snack 1 -</option>
                                                </select>
                                                <?php if($snack_count == 2): ?>
                                                    <select class="chef-meal-select" data-cat="Snacks" data-saved-val="<?php echo $raw->snack_2_id; ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                        <option value="">- Snack 2 -</option>
                                                    </select>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <?php for($j=1; $j<=3; $j++): ?>
                                                <select class="chef-meal-select" data-cat="Juices" data-saved-val="<?php echo $raw->{'juice_'.$j.'_id'}; ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                    <option value="">- Juice <?php echo $j; ?> -</option>
                                                </select>
                                            <?php endfor; ?>
                                        <?php endif; ?>
                                        
                                        <button class="save-chef-assign" style="width:100%; background:#dba617; color:#fff; border:none; padding:6px; border-radius:4px; font-weight:bold; cursor:pointer; margin-top:4px; transition: 0.2s;">Save</button>
                                    </div>
                                    
                                <?php else: ?>
                                    <ul style="margin:0; padding-left:15px; line-height: 1.4;">
                                        <?php foreach($c['meals'] as $meal) echo "<li>$meal</li>"; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            
                            <td style="text-align:center; background:#f0fdf4; border-left:1px solid #ddd;">
                                <input type="checkbox" class="chk-large cmp-prepared-box cmp-no-print" data-id="<?php echo $c['log_id']; ?>" <?php echo $c['prepared'] ? 'checked' : ''; ?> <?php echo $prepared_disabled; ?>>
                                <?php if($c['prepared']) echo '<span style="display:none; color:#166534; font-weight:bold;" class="print-only">Yes</span>'; ?>
                            </td>

                            <td style="text-align:center; background:#fcfcfc;">
                                <input type="checkbox" class="chk-large cmp-dispatch-box cmp-no-print" data-id="<?php echo $c['log_id']; ?>" <?php echo $c['dispatch'] ? 'checked' : ''; ?> <?php echo $dispatch_disabled; ?>>
                                <?php if($c['dispatch']) echo '<span style="display:none; color:green; font-weight:bold;" class="print-only">Yes</span>'; ?>
                            </td>
                            <td style="background:#fcfcfc;">
                                <select class="cmp-delivery-box" data-id="<?php echo $c['log_id']; ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" <?php echo $delivery_disabled; ?>>
                                    <option value="Pending" <?php selected($c['delivery'], 'Pending'); ?>>Pending</option>
                                    <option value="Successful" <?php selected($c['delivery'], 'Successful'); ?>>Successful</option>
                                    <option value="Cancelled" <?php selected($c['delivery'], 'Cancelled'); ?>>Cancelled</option>
                                    <option value="Returned" <?php selected($c['delivery'], 'Returned'); ?>>Returned</option>
                                </select>
                            </td>
                            <td style="text-align:center; background:#f4f8fa;">
                                <input type="checkbox" class="chk-large cmp-pos-box cmp-no-print" data-id="<?php echo $c['log_id']; ?>" <?php echo $c['pos'] ? 'checked' : ''; ?> <?php echo $pos_disabled; ?>>
                                <?php if($c['pos']) echo '<span style="display:none; color:green; font-weight:bold;" class="print-only">Yes</span>'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
    jQuery(document).ready(function($) {
        
        var canEditDelivery = <?php echo empty($chef_disabled) ? 'true' : 'false'; ?>;
        var canEditPos = <?php echo empty($foh_disabled) ? 'true' : 'false'; ?>;

        // --- SMART DYNAMIC DROPDOWNS ---
        function populateDynamicDropdowns(container) {
            var dateVal = container.data('target-date');
            var selects = container.find('select');
            var btn = container.find('.save-chef-assign');
            
            container.find('.boundary-overlay').remove();

            if (!dateVal) {
                selects.hide(); return;
            }

            var availableFoods = cmpGlobalFoodData.filter(function(food) {
                return (dateVal >= food.valid_from && dateVal <= food.valid_until);
            });

            if (availableFoods.length === 0) {
                // LOCKOUT: No food exists for this date!
                selects.hide();
                container.prepend('<div class="boundary-overlay" style="color:#b45309; font-weight:bold; font-size:0.9em; background:#fef3c7; padding:10px; border-radius:4px; text-align:center; line-height:1.4;">Menu Pending Release</div>');
                btn.text('Menu Pending').css({'background':'#94a3b8', 'color':'#fff', 'cursor':'not-allowed'}).prop('disabled', true);
                return;
            }

            // POPULATE: Food exists, build the dropdowns!
            selects.show();
            if (btn.text() === 'Menu Pending') {
                btn.text('Save').css({'background':'#dba617', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
            }

            selects.each(function() {
                var select = $(this);
                var category = select.data('cat');
                var savedVal = select.data('saved-val');
                var placeholder = select.find('option').first().text();
                
                select.empty().append('<option value="">' + placeholder + '</option>');
                
                availableFoods.forEach(function(food) {
                    if (food.category_name === category) {
                        var isSelected = (savedVal == food.id) ? 'selected' : '';
                        select.append('<option value="'+food.id+'" '+isSelected+'>'+food.food_name+'</option>');
                    }
                });
            });
        }

        // Init all chef assignment forms dynamically
        $('.chef-assign-form').each(function() {
            populateDynamicDropdowns($(this));
        });
        // --------------------------------------

        // --- Tab Memory & Search Logic ---
        var urlParams = new URLSearchParams(window.location.search);
        var initialTab = urlParams.get('k_tab') || 'all';
        
        $('.k-tab-btn').removeClass('active');
        $('.k-tab-btn[data-filter="'+initialTab+'"]').addClass('active');

        function applyKitchenFilters() {
            var activeFilter = $('.k-tab-btn.active').data('filter');
            var searchQuery = $('#k-search').val().toLowerCase();

            $('.k-table tbody tr.k-row').each(function() {
                var row = $(this);
                var customerName = row.data('name');
                
                var matchTab = false;
                if (activeFilter === 'all') matchTab = true;
                else if (activeFilter === 'pending' && row.hasClass('filter-pending')) matchTab = true;
                else if (activeFilter === 'assigned' && row.hasClass('filter-assigned')) matchTab = true;

                var matchSearch = customerName.indexOf(searchQuery) > -1;

                if (matchTab && matchSearch) {
                    row.show();
                } else {
                    row.hide();
                }
            });

            if ($('.k-table tbody tr.k-row:visible').length === 0) {
                if ($('#k-empty-msg').length === 0) {
                    $('.k-table tbody').append('<tr id="k-empty-msg"><td colspan="7" style="text-align:center; padding:30px; color:#666; font-size:1.1em;">No customers match your criteria.</td></tr>');
                } else {
                    $('#k-empty-msg').show();
                }
            } else {
                $('#k-empty-msg').hide();
            }
        }

        applyKitchenFilters();

        $('.k-tab-btn').on('click', function() {
            $('.k-tab-btn').removeClass('active');
            $(this).addClass('active');
            
            var url = new URL(window.location);
            url.searchParams.set('k_tab', $(this).data('filter'));
            window.history.pushState({}, '', url);

            applyKitchenFilters();
        });

        $('#k-search').on('keyup', function() {
            applyKitchenFilters();
        });
        // --------------------------------------

        function enforceChefMealQuota(container) {
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var isJuice = container.data('is-juice') == '1';
            
            if (allowedMeals <= 0 || isJuice) return; 

            var mainSelects = container.find('.chef-main-meal');
            var selectedCount = 0;
            
            mainSelects.each(function() {
                if ($(this).val() !== "") selectedCount++;
            });

            if (selectedCount >= allowedMeals) {
                mainSelects.each(function() {
                    if ($(this).val() === "") {
                        $(this).prop('disabled', true).addClass('quota-locked');
                    } else {
                        $(this).prop('disabled', false).removeClass('quota-locked');
                    }
                });
            } else {
                mainSelects.each(function() {
                    $(this).prop('disabled', false).removeClass('quota-locked');
                });
            }
        }

        $('.chef-assign-form').each(function() {
            enforceChefMealQuota($(this));
        });

        $(document).on('change', '.chef-main-meal', function() {
            enforceChefMealQuota($(this).closest('.chef-assign-form'));
        });

        $('.edit-chef-assign').on('click', function() {
            var cell = $(this).closest('td');
            cell.find('.chef-assigned-view').hide();
            cell.find('.chef-assign-form').show();
        });

        $('.save-chef-assign').on('click', function() {
            var btn = $(this);
            var container = btn.closest('.chef-assign-form');
            var logId = container.data('log-id');
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var isJuice = container.data('is-juice') == '1';
            
            // Check if dropdowns are hidden due to no menu
            var isMenuPending = container.find('.boundary-overlay').length > 0;
            if (isMenuPending) {
                alert('The specific menu is not available yet.');
                return;
            }

            if (!isJuice && allowedMeals > 0) {
                var selectedMainCount = 0;
                container.find('.chef-main-meal').each(function() {
                    if ($(this).val() !== "") selectedMainCount++;
                });
                
                if (selectedMainCount < allowedMeals) {
                    alert("Please select exactly " + allowedMeals + " main meal(s) to complete the assignment."); 
                    return; 
                }
            } else if (isJuice) {
                var missingJuice = false;
                container.find('select').each(function() {
                    if ($(this).val() === '') missingJuice = true;
                });
                if(missingJuice) { 
                    alert("Please select all juices to complete the assignment."); 
                    return; 
                }
            }
            
            btn.text('Saving...').prop('disabled', true).css('opacity', '0.7');
            
            var data = {
                action: 'cmp_assign_chef_meals',
                nonce: '<?php echo wp_create_nonce("cmp_kitchen_nonce"); ?>',
                log_id: logId,
                breakfast: container.find('select').eq(0).data('cat') === 'Breakfast' ? container.find('select').eq(0).val() : null,
                lunch:     container.find('select').eq(1).data('cat') === 'Lunch' ? container.find('select').eq(1).val() : null,
                dinner:    container.find('select').eq(2).data('cat') === 'Dinner' ? container.find('select').eq(2).val() : null,
                snack_1:   container.find('select[data-cat="Snacks"]').eq(0).length ? container.find('select[data-cat="Snacks"]').eq(0).val() : null,
                snack_2:   container.find('select[data-cat="Snacks"]').eq(1).length ? container.find('select[data-cat="Snacks"]').eq(1).val() : null,
                juice_1:   container.find('select[data-cat="Juices"]').eq(0).length ? container.find('select[data-cat="Juices"]').eq(0).val() : null,
                juice_2:   container.find('select[data-cat="Juices"]').eq(1).length ? container.find('select[data-cat="Juices"]').eq(1).val() : null,
                juice_3:   container.find('select[data-cat="Juices"]').eq(2).length ? container.find('select[data-cat="Juices"]').eq(2).val() : null
            };
            
            $.post("<?php echo admin_url('admin-ajax.php'); ?>", data, function(res) {
                if(res.success) {
                    location.reload(); 
                } else {
                    alert(res.data || 'Error saving assignment.');
                    btn.text('Save').prop('disabled', false).css('opacity', '1');
                }
            });
        });

        function saveAction(logId, field, val) {
            $.post("<?php echo admin_url('admin-ajax.php'); ?>", {
                action: 'cmp_kitchen_action',
                nonce: "<?php echo wp_create_nonce('cmp_kitchen_nonce'); ?>",
                log_id: logId,
                field: field,
                value: val
            }, function(res) {
                if(!res.success) { 
                    alert(res.data || 'Permission Denied.'); 
                    location.reload(); 
                }
            });
        }

        function updateRowLogic(logId) {
            var preparedBox = $('.cmp-prepared-box[data-id="' + logId + '"]');
            var dispatchBox = $('.cmp-dispatch-box[data-id="' + logId + '"]');
            var deliveryBox = $('.cmp-delivery-box[data-id="' + logId + '"]');
            var posBox = $('.cmp-pos-box[data-id="' + logId + '"]');
            
            var isPrepared = preparedBox.is(':checked');
            var isDispatched = dispatchBox.is(':checked');
            var deliveryResult = deliveryBox.val();
            var isPosChecked = posBox.is(':checked');
            
            if (isPosChecked) {
                preparedBox.prop('disabled', true);
                dispatchBox.prop('disabled', true);
                deliveryBox.prop('disabled', true);
            } else {
                if (canEditDelivery) {
                    
                    if (isDispatched) {
                        preparedBox.prop('disabled', true);
                        deliveryBox.prop('disabled', false);
                    } else {
                        preparedBox.prop('disabled', false);
                        deliveryBox.prop('disabled', true);
                        if (deliveryResult !== 'Pending') {
                            deliveryBox.val('Pending');
                            saveAction(logId, 'delivery_result', 'Pending');
                            deliveryResult = 'Pending';
                        }
                    }
                    
                    // Dispatch requires Prepared
                    if (isPrepared) {
                        dispatchBox.prop('disabled', false);
                    } else {
                        dispatchBox.prop('disabled', true);
                        if (isDispatched) {
                            dispatchBox.prop('checked', false);
                            saveAction(logId, 'dispatch_status', 0);
                            isDispatched = false;
                            deliveryBox.prop('disabled', true);
                        }
                    }
                }
            }
            
            if (canEditPos) {
                if (isDispatched && deliveryResult !== 'Pending') {
                    posBox.prop('disabled', false);
                } else {
                    posBox.prop('disabled', true);
                    if (isPosChecked) {
                        posBox.prop('checked', false);
                        saveAction(logId, 'pos_updated', 0);
                    }
                }
            }
        }

        $('.cmp-prepared-box').on('change', function() {
            var logId = $(this).data('id');
            saveAction(logId, 'is_prepared', $(this).is(':checked') ? 1 : 0);
            updateRowLogic(logId);
        });

        $('.cmp-dispatch-box').on('change', function() {
            var logId = $(this).data('id');
            saveAction(logId, 'dispatch_status', $(this).is(':checked') ? 1 : 0);
            updateRowLogic(logId);
        });

        $('.cmp-delivery-box').on('change', function() {
            var logId = $(this).data('id');
            saveAction(logId, 'delivery_result', $(this).val());
            updateRowLogic(logId);
        });

        $('.cmp-pos-box').on('change', function() {
            var logId = $(this).data('id');
            saveAction(logId, 'pos_updated', $(this).is(':checked') ? 1 : 0);
            updateRowLogic(logId);
        });

    });
    </script>
    <?php
    return ob_get_clean();
}
