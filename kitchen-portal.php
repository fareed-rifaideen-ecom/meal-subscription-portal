<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

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

    $log_row = $wpdb->get_row($wpdb->prepare("SELECT subscription_id, target_date, plan_mode FROM {$wpdb->prefix}cmp_daily_logs WHERE id = %d", $log_id));
    $log_mode = null;

    if ($log_row) {
        $sub = $wpdb->get_row($wpdb->prepare("SELECT plan_name, allowed_categories, is_flexible, allow_custom FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", $log_row->subscription_id));
        
        // Phase 7: Evaluate mode
        $log_mode = !empty($log_row->plan_mode) ? $log_row->plan_mode : null;
        if (!$log_mode && $sub) {
            if (!empty($sub->allow_custom)) $log_mode = 'custom';
            elseif (!empty($sub->is_flexible)) $log_mode = 'flexible';
            else $log_mode = 'standard';
        }

        $has_custom = !empty($_POST['custom_breakfast']) || !empty($_POST['custom_lunch']) || !empty($_POST['custom_dinner']) || !empty($_POST['custom_snack_1']) || !empty($_POST['custom_snack_2']) || !empty($_POST['custom_juice_1']);
        $has_meals = !empty($_POST['breakfast']) || !empty($_POST['lunch']) || !empty($_POST['dinner']) || !empty($_POST['snack_1']) || !empty($_POST['snack_2']) || !empty($_POST['juice_1']);
        
        if ($has_meals && !$has_custom) {
            $food_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_foods WHERE is_active = 1 AND valid_from <= %s AND valid_until >= %s", $log_row->target_date, $log_row->target_date));
            if (intval($food_exists) === 0) {
                wp_send_json_error('The menu for this date is pending release. Meals cannot be assigned yet.');
            }
        }

        if ($sub) {
            $is_juice = (stripos($sub->allowed_categories, 'Juices') !== false || stripos($sub->plan_name, 'juice') !== false || stripos($sub->plan_name, 'cleanse') !== false);
            preg_match('/(\d+)\s*Meal/i', $sub->plan_name, $m);
            $allowed_quota = isset($m[1]) ? intval($m[1]) : 0;

            if (!$is_juice && $allowed_quota > 0) {
                $submitted_count = 0;
                if (!empty($_POST['breakfast']) || !empty($_POST['custom_breakfast'])) $submitted_count++;
                if (!empty($_POST['lunch']) || !empty($_POST['custom_lunch'])) $submitted_count++;
                if (!empty($_POST['dinner']) || !empty($_POST['custom_dinner'])) $submitted_count++;

                if ($submitted_count > $allowed_quota) {
                    wp_send_json_error("Quota Exceeded! This customer's plan only allows {$allowed_quota} main meal(s).");
                }
            }
        }
    }

    $data = array(
        'plan_mode'    => $log_mode ?: 'standard',
        'breakfast_id' => !empty($_POST['breakfast']) ? intval($_POST['breakfast']) : null,
        'lunch_id'     => !empty($_POST['lunch']) ? intval($_POST['lunch']) : null,
        'dinner_id'    => !empty($_POST['dinner']) ? intval($_POST['dinner']) : null,
        'snack_1_id'   => !empty($_POST['snack_1']) ? intval($_POST['snack_1']) : null,
        'snack_2_id'   => !empty($_POST['snack_2']) ? intval($_POST['snack_2']) : null,
        'juice_1_id'   => !empty($_POST['juice_1']) ? intval($_POST['juice_1']) : null,
        'juice_2_id'   => !empty($_POST['juice_2']) ? intval($_POST['juice_2']) : null,
        'juice_3_id'   => !empty($_POST['juice_3']) ? intval($_POST['juice_3']) : null,
        
        'custom_breakfast' => !empty($_POST['custom_breakfast']) ? sanitize_text_field($_POST['custom_breakfast']) : null,
        'custom_lunch'     => !empty($_POST['custom_lunch']) ? sanitize_text_field($_POST['custom_lunch']) : null,
        'custom_dinner'    => !empty($_POST['custom_dinner']) ? sanitize_text_field($_POST['custom_dinner']) : null,
        'custom_snack_1'   => !empty($_POST['custom_snack_1']) ? sanitize_text_field($_POST['custom_snack_1']) : null,
        'custom_snack_2'   => !empty($_POST['custom_snack_2']) ? sanitize_text_field($_POST['custom_snack_2']) : null,
        'custom_juice_1'   => !empty($_POST['custom_juice_1']) ? sanitize_text_field($_POST['custom_juice_1']) : null,
        'custom_juice_2'   => !empty($_POST['custom_juice_2']) ? sanitize_text_field($_POST['custom_juice_2']) : null,
        'custom_juice_3'   => !empty($_POST['custom_juice_3']) ? sanitize_text_field($_POST['custom_juice_3']) : null,
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
    
    $prep_date = isset($_GET['prep_date']) ? sanitize_text_field($_GET['prep_date']) : date('Y-m-d');
    $export_type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'meals'; 
    $target_date = date('Y-m-d', strtotime($prep_date . ' + 1 day'));
    
    // Exclude paused subscriptions and cancelled days from CSV export
    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, s.expiry_date, s.foh_remarks, s.is_flexible, s.allow_custom, u.display_name, u.user_email 
         FROM {$wpdb->prefix}cmp_daily_logs l 
         JOIN {$wpdb->prefix}cmp_subscriptions s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON l.user_id = u.ID 
         WHERE l.target_date = %s AND l.is_locked = 1 AND s.status != 'paused' AND (l.delivery_result IS NULL OR l.delivery_result != 'Cancelled')", 
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
        fputcsv($output, array('Order ID', 'Customer Name', 'Recipient Name', 'Email', 'Phone', 'Eating Date', 'Address', 'Method', 'Receive By', 'Delivery Date', 'Time Slot', 'Allergies', 'FOH Note', 'Plan', 'Juice 1', 'Juice 2', 'Juice 3', 'Prepared', 'Dispatched', 'Delivery', 'POS Check'));
    } else {
        fputcsv($output, array('Order ID', 'Customer Name', 'Recipient Name', 'Email', 'Phone', 'Eating Date', 'Address', 'Method', 'Receive By', 'Delivery Date', 'Time Slot', 'Allergies', 'FOH Note', 'Plan', 'Breakfast / Meal 1', 'Lunch / Meal 2', 'Dinner / Meal 3', 'Snack 1', 'Snack 2', 'Prepared', 'Dispatched', 'Delivery', 'POS Check'));
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

        $is_assigned = ($log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1);
        $chef_tag = $log->is_chefs_choice ? ' (Chef)' : '';
        $pending_tag = "[Pending Chef]";

        $target_ymd = date('Y-m-d', strtotime($log->target_date));
        $expiry_ymd = date('Y-m-d', strtotime($log->expiry_date));
        $is_expired = ($target_ymd > $expiry_ymd);

        $prepared = $log->is_prepared ? 'Yes' : 'No';
        $dispatched = $log->dispatch_status ? 'Yes' : 'No';
        $delivery = $log->delivery_result ?: 'Pending';
        $pos_check = $log->pos_updated ? 'Yes' : 'No';
        
        $day_number = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM {$wpdb->prefix}cmp_daily_logs 
             WHERE subscription_id = %d AND target_date <= %s 
             AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))",
            $log->subscription_id, $log->target_date
        ));
        
        $plan_parts = explode(' - ', $log->plan_name);
        $potential_recipient = end($plan_parts);
        $recipient_name = '';
        if (!preg_match('/(Meal|Day|Cleanse|Plans)/i', $potential_recipient) && count($plan_parts) > 1) {
            $recipient_name = $potential_recipient;
            array_pop($plan_parts);
            $core_plan_name = implode(' - ', $plan_parts);
        } else {
            $core_plan_name = $log->plan_name;
        }

        $plan_string = $core_plan_name . " ({$day_number}/{$log->total_days})";
        
        $order_id_display = 'Manual';
        if ($log->wc_order_id < 0) { $order_id_display = 'M-' . str_pad(abs($log->wc_order_id), 4, '0', STR_PAD_LEFT); } 
        elseif ($log->wc_order_id > 0) { $order_id_display = '#' . $log->wc_order_id; }
        else { $order_id_display = 'M-' . str_pad($log->subscription_id, 4, '0', STR_PAD_LEFT); }

        $foh_note = !empty($log->foh_remarks) ? wp_strip_all_tags($log->foh_remarks) : 'None';

        $row_data = array();

        if ($export_type === 'juices') {
            $j1 = '-'; $j2 = '-'; $j3 = '-';
            if ($is_expired) {
                $j1 = 'PLAN EXPIRED'; $j2 = 'PLAN EXPIRED'; $j3 = 'PLAN EXPIRED';
            } elseif ($log->is_chefs_choice && !$is_assigned) {
                $j1 = $pending_tag; $j2 = $pending_tag; $j3 = $pending_tag;
            } else {
                if ($log->custom_juice_1) $j1 = $log->custom_juice_1 . $chef_tag;
                elseif ($log->juice_1_id) $j1 = ($food_map[$log->juice_1_id] ?? 'Unknown') . $chef_tag;
                
                if ($log->custom_juice_2) $j2 = $log->custom_juice_2 . $chef_tag;
                elseif ($log->juice_2_id) $j2 = ($food_map[$log->juice_2_id] ?? 'Unknown') . $chef_tag;
                
                if ($log->custom_juice_3) $j3 = $log->custom_juice_3 . $chef_tag;
                elseif ($log->juice_3_id) $j3 = ($food_map[$log->juice_3_id] ?? 'Unknown') . $chef_tag;
            }
            $row_data = array($order_id_display, $full_name, $recipient_name, $email, $phone, $log->target_date, $address, $method, $timing, $delivery_date, $time_slot, $allergies, $foh_note, $plan_string, $j1, $j2, $j3, $prepared, $dispatched, $delivery, $pos_check);
        
        } else {
            $b = '-'; $l = '-'; $d = '-'; $s1 = '-'; $s2 = '-';
            if ($is_expired) {
                $b = 'PLAN EXPIRED'; $l = 'PLAN EXPIRED'; $d = 'PLAN EXPIRED'; $s1 = 'PLAN EXPIRED'; $s2 = 'PLAN EXPIRED';
            } elseif ($log->is_chefs_choice && !$is_assigned) {
                $b = $pending_tag; $l = $pending_tag; $d = $pending_tag; $s1 = $pending_tag; $s2 = $pending_tag;
            } else {
                $b  = !empty($log->custom_breakfast) ? $log->custom_breakfast : ($log->breakfast_id ? ($food_map[$log->breakfast_id] ?? 'Unknown') . $chef_tag : '-');
                $l  = !empty($log->custom_lunch)     ? $log->custom_lunch     : ($log->lunch_id     ? ($food_map[$log->lunch_id]     ?? 'Unknown') . $chef_tag : '-');
                $d  = !empty($log->custom_dinner)    ? $log->custom_dinner    : ($log->dinner_id    ? ($food_map[$log->dinner_id]    ?? 'Unknown') . $chef_tag : '-');
                $s1 = !empty($log->custom_snack_1)   ? $log->custom_snack_1   : ($log->snack_1_id   ? ($food_map[$log->snack_1_id]   ?? 'Unknown') . $chef_tag : '-');
                $s2 = !empty($log->custom_snack_2)   ? $log->custom_snack_2   : ($log->snack_2_id   ? ($food_map[$log->snack_2_id]   ?? 'Unknown') . $chef_tag : '-');
            }
            $row_data = array($order_id_display, $full_name, $recipient_name, $email, $phone, $log->target_date, $address, $method, $timing, $delivery_date, $time_slot, $allergies, $foh_note, $plan_string, $b, $l, $d, $s1, $s2, $prepared, $dispatched, $delivery, $pos_check);
        }
        
        $export_rows[] = array(
            'data' => $row_data,
            'is_day_before' => $is_day_before ? 1 : 0
        );
    }
    
    usort($export_rows, function($a, $b) { return $b['is_day_before'] - $a['is_day_before']; });
    foreach($export_rows as $er) { fputcsv($output, $er['data']); }
    fclose($output); exit;
}

// ==========================================
// 2. KITCHEN REPORTING PORTAL
// ==========================================
add_shortcode( 'meal_kitchen_portal', 'cmp_render_kitchen_portal' );
function cmp_render_kitchen_portal() {
    date_default_timezone_set('Asia/Dubai');

    $is_admin = current_user_can('manage_options') || current_user_can('menu_manager');
    $is_chef  = current_user_can('kitchen_staff');
    $is_foh   = current_user_can('foh_manager');

    // Security & Custom Glassmorphism UI/UX Login Screen
    if ( ! is_user_logged_in() || ( ! $is_admin && ! $is_chef && ! $is_foh ) ) {
        if ( function_exists( 'cmp_render_portal_login_ui' ) ) {
            return cmp_render_portal_login_ui( array(
                'portal_id'      => 'kitchen',
                'title'          => 'Kitchen Portal',
                'subtitle'       => 'Daily Meal Prep, Chef Assignments & Dispatch',
                'required_caps'  => array( 'manage_options', 'menu_manager', 'kitchen_staff', 'foh_manager' ),
                'denied_message' => 'Kitchen Staff, Menu Manager, or FOH clearance is required to access this portal.',
            ) );
        }
    }

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    $today_ymd     = date('Y-m-d');
    $selected_date = isset($_GET['prep_date']) ? sanitize_text_field($_GET['prep_date']) : $today_ymd;
    $target_date   = date('Y-m-d', strtotime($selected_date . ' + 1 day'));
    $prev_date     = date('Y-m-d', strtotime($selected_date . ' - 1 day'));
    $next_date     = date('Y-m-d', strtotime($selected_date . ' + 1 day'));

    // Exclude paused subscriptions and cancelled days from the Kitchen View
    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, s.foh_remarks, s.expiry_date, s.is_flexible, s.allow_custom, u.display_name, u.user_email 
         FROM {$table_logs} l 
         JOIN {$wpdb->prefix}cmp_subscriptions s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON l.user_id = u.ID 
         WHERE l.target_date = %s AND l.is_locked = 1 AND s.status != 'paused' AND (l.delivery_result IS NULL OR l.delivery_result != 'Cancelled') 
         ORDER BY l.id ASC", 
        $target_date
    ) );

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

        // Phase 7: Evaluate Log-Level Snapshot
        $log_mode = !empty($log->plan_mode) ? $log->plan_mode : null;
        if ($log_mode) {
            $is_flexible  = ($log_mode === 'flexible') ? 1 : 0;
            $allow_custom = ($log_mode === 'custom') ? 1 : 0;
        } else {
            $is_flexible  = isset($log->is_flexible) ? intval($log->is_flexible) : 0;
            $allow_custom = isset($log->allow_custom) ? intval($log->allow_custom) : 0;
        }
        
        $meals_list = array();
        $is_assigned = ($log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1);

        $l_bf = $is_flexible ? 'Meal 1' : 'Breakfast';
        $l_lu = $is_flexible ? 'Meal 2' : 'Lunch';
        $l_di = $is_flexible ? 'Meal 3' : 'Dinner';

        if ($log->is_chefs_choice && !$is_assigned) {
            $meals_list[] = "Chef's Choice (Pending Assignment)";
        } elseif ($log->juice_1_id || $log->custom_juice_1) {
            if ($log->custom_juice_1) $meals_list[] = "Juice 1: " . esc_html($log->custom_juice_1);
            elseif ($log->juice_1_id) $meals_list[] = "Juice 1: " . ($food_map[$log->juice_1_id] ?? 'Unknown');
            
            if ($log->custom_juice_2) $meals_list[] = "Juice 2: " . esc_html($log->custom_juice_2);
            elseif ($log->juice_2_id) $meals_list[] = "Juice 2: " . ($food_map[$log->juice_2_id] ?? 'Unknown');
            
            if ($log->custom_juice_3) $meals_list[] = "Juice 3: " . esc_html($log->custom_juice_3);
            elseif ($log->juice_3_id) $meals_list[] = "Juice 3: " . ($food_map[$log->juice_3_id] ?? 'Unknown');
        } else {
            if ($log->custom_breakfast) $meals_list[] = "$l_bf: " . esc_html($log->custom_breakfast);
            elseif ($log->breakfast_id) $meals_list[] = "$l_bf: " . ($food_map[$log->breakfast_id] ?? 'Unknown');
            
            if ($log->custom_lunch) $meals_list[] = "$l_lu: " . esc_html($log->custom_lunch);
            elseif ($log->lunch_id) $meals_list[] = "$l_lu: " . ($food_map[$log->lunch_id] ?? 'Unknown');
            
            if ($log->custom_dinner) $meals_list[] = "$l_di: " . esc_html($log->custom_dinner);
            elseif ($log->dinner_id) $meals_list[] = "$l_di: " . ($food_map[$log->dinner_id] ?? 'Unknown');
            
            if ($log->custom_snack_1) $meals_list[] = "Snack 1: " . esc_html($log->custom_snack_1);
            elseif ($log->snack_1_id) $meals_list[] = "Snack 1: " . ($food_map[$log->snack_1_id] ?? 'Unknown');
            
            if ($log->custom_snack_2) $meals_list[] = "Snack 2: " . esc_html($log->custom_snack_2);
            elseif ($log->snack_2_id) $meals_list[] = "Snack 2: " . ($food_map[$log->snack_2_id] ?? 'Unknown');
        }

        $day_number = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM {$table_logs} 
             WHERE subscription_id = %d AND target_date <= %s 
             AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))",
            $log->subscription_id, $log->target_date
        ));

        $target_ymd = date('Y-m-d', strtotime($log->target_date));
        $expiry_ymd = date('Y-m-d', strtotime($log->expiry_date));
        $is_expired = ($target_ymd > $expiry_ymd);

        $plan_parts = explode(' - ', $log->plan_name);
        $potential_recipient = end($plan_parts);
        $recipient_badge = '';
        if (!preg_match('/(Meal|Day|Cleanse|Plans)/i', $potential_recipient) && count($plan_parts) > 1) {
            $recipient_badge = '<div style="margin-top:4px;"><span style="background:#EEF7E3; color:#1B5E20; border:1px solid #DCECC9; padding:2px 8px; border-radius:12px; font-size:0.85em; font-weight:bold; display:inline-block;">For: ' . esc_html($potential_recipient) . '</span></div>';
            array_pop($plan_parts);
            $core_plan_name = implode(' - ', $plan_parts);
        } else {
            $core_plan_name = $log->plan_name;
        }

        $display_order_id = 'Manual';
        if ($log->wc_order_id < 0) { $display_order_id = 'M-' . str_pad(abs($log->wc_order_id), 4, '0', STR_PAD_LEFT); } 
        elseif ($log->wc_order_id > 0) { $display_order_id = '#' . $log->wc_order_id; }
        else { $display_order_id = 'M-' . str_pad($log->subscription_id, 4, '0', STR_PAD_LEFT); }

        $customers[] = array(
            'log_id' => $log->id, 
            'order_id' => $display_order_id,
            'name' => $full_name, 
            'phone' => $phone, 
            'email' => $email, 
            'address' => $address,
            'method' => $method, 
            'timing' => $timing, 
            'delivery_date' => $delivery_date, 
            'time_slot' => $time_slot, 
            'plan' => $log->plan_name,
            'core_plan_name' => $core_plan_name,
            'recipient_badge' => $recipient_badge,
            'total_days' => $log->total_days,
            'day_number' => $day_number,
            'allergies' => !empty($allergies) ? $allergies : 'No Allergies', 
            'foh_remarks' => !empty($log->foh_remarks) ? wp_strip_all_tags($log->foh_remarks) : '',
            'is_expired' => $is_expired,
            'prepared' => $log->is_prepared,
            'dispatch' => $log->dispatch_status,
            'delivery' => $log->delivery_result, 
            'pos' => $log->pos_updated, 
            'meals' => $meals_list,
            'is_chefs_choice' => $log->is_chefs_choice,
            'is_assigned' => $is_assigned,
            'is_flexible' => $is_flexible,
            'allow_custom' => $allow_custom,
            'allowed_categories' => $log->allowed_categories,
            'target_date' => $log->target_date, 
            'expiry_date' => $log->expiry_date,
            'is_day_before' => $is_day_before ? 1 : 0,
            'raw_log' => $log
        );
    }

    usort($customers, function($a, $b) { return $b['is_day_before'] - $a['is_day_before']; });

    $chef_disabled = ($is_admin || $is_chef) ? '' : 'disabled';
    $foh_disabled = ($is_admin || $is_foh) ? '' : 'disabled';

    $count_all = count($customers);
    $count_pending = 0;
    $count_assigned = 0;
    foreach ($customers as $c) {
        if ($c['is_chefs_choice'] && !$c['is_expired']) {
            if ($c['is_assigned']) { $count_assigned++; } 
            else { $count_pending++; }
        }
    }

    ob_start();
    ?>
    <script>
        var cmpGlobalFoodData = <?php echo json_encode($foods); ?>;
    </script>

    <style>
        #kp-portal-root {
            --kp-admin-offset: 0px;
            --kp-banner-height: 82px;
            max-width: 1400px;
            margin: 0 auto;
            font-family: inherit;
            padding: 0 12px;
            box-sizing: border-box;
        }
        #kp-portal-root *, #kp-portal-root *::before, #kp-portal-root *::after {
            box-sizing: border-box;
        }

        /* ==========================================
           OPTION 1A: DYNAMIC STICKY STACKING
           ========================================== */
        #kp-sticky-banner {
            position: sticky;
            top: var(--kp-admin-offset, 0px);
            z-index: 100;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            text-align: center;
            border-bottom: 3px solid #379237;
            margin-bottom: 16px;
            padding: 14px 10px 10px 10px;
            transition: padding 0.2s ease, box-shadow 0.2s ease;
        }
        #kp-sticky-banner.kp-is-scrolled {
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.08);
        }
        #kp-sticky-banner h1 {
            margin: 0;
            font-size: 2em;
            line-height: 1.2;
            color: #231F20;
            transition: font-size 0.2s ease;
        }
        #kp-sticky-banner h3 {
            margin: 5px 0 0 0;
            font-size: 1.25em;
            line-height: 1.25;
            color: #379237;
            transition: font-size 0.2s ease, margin 0.2s ease;
        }

        .k-table-wrap {
            width: 100%;
            overflow: visible;
        }

        /* Important: Do NOT use overflow:hidden on .k-table so position:sticky works on thead th */
        .k-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #fff;
            font-size: 0.85em;
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.06);
            border-radius: 10px;
            table-layout: fixed;
        }
        .k-table thead th {
            position: sticky;
            top: calc(var(--kp-admin-offset, 0px) + var(--kp-banner-height, 82px));
            z-index: 95;
            background: linear-gradient(135deg, #1B5E20 0%, #379237 100%);
            color: #fff;
            padding: 12px 8px;
            text-align: left;
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(27, 94, 32, 0.16);
            border-bottom: 2px solid #1B5E20;
        }
        .k-table thead th:first-child {
            border-top-left-radius: 10px;
        }
        .k-table thead th:last-child {
            border-top-right-radius: 10px;
        }
        .k-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        /* Touch-friendly Checkboxes without layout overflow */
        .chk-large {
            width: 28px;
            height: 28px;
            transform: scale(1.25);
            cursor: pointer;
            margin: 6px auto;
            accent-color: #379237;
            display: inline-block;
            vertical-align: middle;
        }
        .chk-large:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }

        /* ==========================================
           OPTION 2C: SMART COLOR-CODED POS CUE
           ========================================== */
        .kp-pos-cell-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        .kp-pos-cue {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            font-size: 10px;
            font-weight: 800;
            line-height: 1.15;
            padding: 3px 6px;
            border-radius: 999px;
            white-space: nowrap;
            letter-spacing: 0.01em;
            border: 1px solid transparent;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
        }
        /* Future Eating Date (e.g. prepping Day-Before orders for tomorrow) */
        .kp-pos-cue.cue-upcoming {
            background: #fffbeb;
            color: #b45309;
            border-color: #fde68a;
        }
        /* Eating Date is Today (Actionable today) */
        .kp-pos-cue.cue-today {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }
        /* Eating Date Passed & Still Unchecked */
        .kp-pos-cue.cue-overdue {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
        }
        /* POS Checked / Completed */
        .kp-pos-cue.cue-done {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
            opacity: 0.88;
        }

        .k-customer-meta { margin: 2px 0; color: #555; display: flex; align-items: center; gap: 5px; }
        .k-customer-meta svg { width: 12px; height: 12px; flex-shrink: 0; color: #379237; }
        
        .logistics-box { margin-top: 10px; padding: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; line-height: 1.3; }
        
        .k-tab-btn { background: #ffffff; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; font-weight: bold; color: #231F20; cursor: pointer; transition: 0.2s; }
        .k-tab-btn.active { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; color: #fff !important; border-color: #1B5E20 !important; box-shadow: 0 4px 12px rgba(55, 146, 55, 0.25); }

        .k-glass-toolbar { background: linear-gradient(135deg, rgba(220, 236, 201, 0.78) 0%, rgba(238, 247, 227, 0.72) 100%); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 20px 24px; border-radius: 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border: 1.5px solid #DCECC9; box-shadow: 0 10px 26px rgba(27, 94, 32, 0.08); flex-wrap: wrap; gap: 15px; }

        .k-filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            background: #f8fafc;
            padding: 15px;
            border: 1px solid #DCECC9;
            border-radius: 10px;
        }

        /* Mobile Card Labels (Hidden on Desktop/Tablet/Landscape Table) */
        .kp-mob-cell-label {
            display: none;
        }

        /* ==========================================
           TABLET PORTRAIT & LANDSCAPE (768px - 1180px)
           ========================================== */
        @media screen and (max-width: 1180px) {
            #kp-portal-root {
                padding: 0 8px;
            }
            .k-glass-toolbar {
                padding: 14px 16px;
                gap: 12px;
            }
            .k-table {
                font-size: 0.81em;
            }
            .k-table thead th,
            .k-table td {
                padding: 8px 6px;
            }
            .k-table thead th:nth-child(1) { width: 21% !important; }
            .k-table thead th:nth-child(2) { width: 17% !important; }
            .k-table thead th:nth-child(3) { width: 26% !important; }
            .k-table thead th:nth-child(4) { width: 8% !important; }
            .k-table thead th:nth-child(5) { width: 8% !important; }
            .k-table thead th:nth-child(6) { width: 11% !important; }
            .k-table thead th:nth-child(7) { width: 9% !important; }

            .chk-large {
                width: 24px;
                height: 24px;
                transform: scale(1.15);
            }
            .kp-pos-cue {
                font-size: 9.5px;
                padding: 2px 5px;
            }
        }

        /* ==========================================
           SHORT HEIGHT LANDSCAPE (Mobile & Small Tablet Landscape)
           Compacts sticky banner so table rows have maximum vertical room
           ========================================== */
        @media screen and (orientation: landscape) and (max-height: 540px) {
            #kp-sticky-banner {
                padding: 6px 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 14px;
                flex-wrap: wrap;
                margin-bottom: 10px;
            }
            #kp-sticky-banner h1 {
                font-size: 1.15em;
            }
            #kp-sticky-banner h3 {
                margin: 0;
                font-size: 1em;
            }
            .k-table {
                font-size: 0.78em;
            }
            .k-table thead th {
                padding: 7px 5px;
                font-size: 0.92em;
            }
            .k-table td {
                padding: 6px 5px;
            }
            .chk-large {
                width: 22px;
                height: 22px;
                transform: scale(1.1);
                margin: 3px auto;
            }
            .kp-pos-cue {
                font-size: 8.5px;
                padding: 2px 4px;
            }
        }

        /* ==========================================
           OPTION 3A: MOBILE PORTRAIT CARDS (<= 767px, Portrait)
           ========================================== */
        @media screen and (max-width: 767px) and (orientation: portrait) {
            #kp-sticky-banner {
                padding: 10px 8px 8px 8px;
                margin-bottom: 14px;
            }
            #kp-sticky-banner h1 {
                font-size: 1.35em;
            }
            #kp-sticky-banner h3 {
                font-size: 0.98em;
                margin-top: 3px;
            }

            .k-glass-toolbar {
                padding: 14px;
                flex-direction: column;
                align-items: stretch;
            }
            .k-glass-toolbar form {
                width: 100%;
                justify-content: space-between;
            }
            .k-glass-toolbar form label {
                width: 100%;
                margin-bottom: 4px;
            }
            .k-glass-toolbar > div {
                width: 100%;
                display: grid !important;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px !important;
            }
            .k-glass-toolbar > div a,
            .k-glass-toolbar > div button {
                text-align: center;
                padding: 10px 8px !important;
                font-size: 0.85em;
            }

            .k-filter-bar {
                padding: 12px;
                gap: 10px;
            }
            .k-tabs-container {
                width: 100%;
                display: flex;
                overflow-x: auto;
                flex-wrap: nowrap !important;
                padding-bottom: 2px;
            }
            .k-tab-btn {
                flex: 1 0 auto;
                padding: 8px 12px;
                font-size: 0.85em;
                white-space: nowrap;
            }

            .k-table {
                display: block;
                background: transparent;
                box-shadow: none;
                border-radius: 0;
                font-size: 0.92em;
            }
            .k-table thead {
                display: none !important;
            }
            .k-table tbody {
                display: block;
                width: 100%;
            }

            /* Each Order Row becomes a 2-column Grid Card */
            .k-table tbody tr.k-row {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                background: #ffffff;
                border: 1.5px solid #cbd5e1;
                border-radius: 14px;
                margin-bottom: 16px;
                box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
                overflow: hidden;
            }
            .k-table tbody tr.k-row.k-row-hidden {
                display: none !important;
            }

            /* Top 3 data columns span full card width */
            .k-table tbody tr.k-row > td:nth-child(1),
            .k-table tbody tr.k-row > td:nth-child(2),
            .k-table tbody tr.k-row > td:nth-child(3) {
                grid-column: 1 / -1;
                display: block;
                width: 100%;
                padding: 12px 14px;
                border-bottom: 1px solid #e2e8f0;
            }
            .k-table tbody tr.k-row > td:nth-child(1) {
                background: linear-gradient(135deg, rgba(238, 247, 227, 0.55) 0%, rgba(255, 255, 255, 1) 100%);
                border-left: 4px solid #379237;
            }

            /* Bottom 4 operational action cells form a clean 2x2 touch grid */
            .k-table tbody tr.k-row > td:nth-child(4),
            .k-table tbody tr.k-row > td:nth-child(5),
            .k-table tbody tr.k-row > td:nth-child(6),
            .k-table tbody tr.k-row > td:nth-child(7) {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 12px 10px;
                min-height: 86px;
                border-bottom: 1px solid #e2e8f0;
            }
            .k-table tbody tr.k-row > td:nth-child(4),
            .k-table tbody tr.k-row > td:nth-child(6) {
                border-right: 1px solid #e2e8f0;
                border-left: none !important;
            }
            .k-table tbody tr.k-row > td:nth-child(6),
            .k-table tbody tr.k-row > td:nth-child(7) {
                border-bottom: none;
            }

            .kp-mob-cell-label {
                display: block;
                font-size: 0.72em;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #475569;
                margin-bottom: 6px;
                text-align: center;
            }

            .chk-large {
                width: 28px;
                height: 28px;
                transform: scale(1.15);
                margin: 2px auto;
            }
            .cmp-delivery-box {
                min-height: 38px;
                font-weight: 600;
            }
        }

        @media print {
            .cmp-no-print, .kp-pos-cue, .kp-mob-cell-label { display: none !important; }
            #kp-sticky-banner, .k-table thead th { position: static !important; box-shadow: none !important; }
            body, html { background: #fff !important; margin: 0 !important; padding: 0 !important; }
            .k-table { box-shadow: none !important; border: 1px solid #000; border-collapse: collapse !important; }
            .k-table th, .k-table td { border: 1px solid #000; padding: 6px; color: #000 !important; background: transparent !important; }
            select { appearance: none; border: none; background: transparent; }
        }
    </style>

    <div id="kp-portal-root">
        
        <div class="cmp-no-print k-glass-toolbar">
            <form method="GET" id="cmp-kitchen-date-form" style="margin: 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <label style="font-weight: 800; font-size: 1.05em; color: #1B5E20;">Food Preparation Date:</label>
                <a href="?prep_date=<?php echo $prev_date; ?>" style="background: #ffffff; color: #1B5E20; border: 1px solid #b7e1a1; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: bold;">&laquo; Prev</a>
                <input type="date" name="prep_date" value="<?php echo esc_attr($selected_date); ?>" style="padding: 8px 12px; border: 1.5px solid #379237; border-radius: 8px; background: #fff; color: #231F20; font-weight: 600;" onchange="document.getElementById('cmp-kitchen-date-form').submit();">
                <a href="?prep_date=<?php echo $next_date; ?>" style="background: #ffffff; color: #1B5E20; border: 1px solid #b7e1a1; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: bold;">Next &raquo;</a>
            </form>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo site_url('/chef-dashboard/'); ?>" target="_blank" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: white; border: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; text-decoration: none;">Chef's Assignment ⇗</a>
                
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_kitchen_csv&type=meals&prep_date=' . $selected_date)); ?>" style="background: #1d6f42; color: white; border: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; text-decoration: none;">Export Meals CSV</a>
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_kitchen_csv&type=juices&prep_date=' . $selected_date)); ?>" style="background: #0ea5e9; color: white; border: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; text-decoration: none;">Export Juices CSV</a>
                
                <button onclick="window.print()" style="background: #475569; color: white; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: bold;">Print PDF</button>
                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" style="background: #dc2626; color: white; border: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; text-decoration: none;">Log Out</a>
            </div>
        </div>

        <div id="kp-sticky-banner">
            <h1>Order Preparation Report</h1>
            <h3>Food Orders to Prep on: <?php echo date('l, d/m/Y', strtotime($selected_date)); ?></h3>
        </div>

        <div class="cmp-no-print k-filter-bar">
            <div class="k-tabs-container" style="display:flex; gap:10px; flex-wrap:wrap;">
                <button class="k-tab-btn active" data-filter="all">All Orders (<?php echo $count_all; ?>)</button>
                <button class="k-tab-btn" data-filter="pending">Pending Chef's Choice <?php if($count_pending > 0) echo '<span style="background:#dc3232; color:#fff; border-radius:10px; padding:2px 8px; font-size:0.8em; margin-left:5px;">'.$count_pending.'</span>'; ?></button>
                <button class="k-tab-btn" data-filter="assigned">Completed Assignments (<?php echo $count_assigned; ?>)</button>
            </div>
            <div style="flex-grow:1; min-width:200px; max-width:350px;">
                <input type="text" id="k-search" placeholder="🔍 Search customers by ID, name, email, or phone..." style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:1em;">
            </div>
        </div>

        <?php if(empty($customers)): ?>
            <p style="text-align: center; font-size: 1.2em; color: #666; padding: 40px; border: 1px dashed #ccc; border-radius: 10px;">No orders found for this prep date.</p>
        <?php else: ?>
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Customer Details</th>
                            <th style="width: 17%;">Logistics</th>
                            <th style="width: 30%;">The Food</th>
                            <th style="width: 8%; text-align:center;">Prepared</th>
                            <th style="width: 8%; text-align:center;">Dispatch</th>
                            <th style="width: 10%;">Delivery</th>
                            <th style="width: 7%; text-align:center;">POS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($customers as $c): 
                            $allergy_style = ($c['allergies'] !== 'No Allergies') ? 'background:#fff3cd; color:#856404; font-weight:bold; padding:2px 6px; border-radius:3px; display:inline-block; margin-top:3px;' : 'color:#666; font-style:italic; display:inline-block; margin-top:3px;';
                            
                            $prepared_disabled = (!empty($chef_disabled) || $c['dispatch'] || $c['is_expired']) ? 'disabled' : '';
                            $dispatch_disabled = (!empty($chef_disabled) || $c['pos'] || $c['is_expired']) ? 'disabled' : '';
                            $delivery_disabled = (!$c['dispatch'] || !empty($chef_disabled) || $c['pos'] || $c['is_expired']) ? 'disabled' : '';
                            $pos_disabled = (!$c['dispatch'] || $c['delivery'] === 'Pending' || !empty($foh_disabled) || $c['is_expired']) ? 'disabled' : '';

                            $row_filter_class = 'filter-standard';
                            if ($c['is_chefs_choice'] && !$c['is_expired']) {
                                $row_filter_class = $c['is_assigned'] ? 'filter-assigned' : 'filter-pending';
                            }
                            $search_name = esc_attr(strtolower($c['order_id'] . ' ' . $c['name'] . ' ' . $c['email'] . ' ' . $c['phone']));
                            
                            $plan_duration = $c['core_plan_name'];
                            $plan_parts = explode(' - ', $c['plan']);
                            $plan_meals = isset($plan_parts[1]) ? $plan_parts[1] : '';
                            if (!preg_match('/(Meal|Day|Cleanse|Plans)/i', end($plan_parts))) {
                                $plan_meals = count($plan_parts) > 2 ? $plan_parts[1] : '';
                            }

                            // Option 2C: Smart Color-Coded "Post on [Date]" Cue (Customer Eating Date)
                            $eat_ymd = date('Y-m-d', strtotime($c['target_date']));
                            $eat_short = date('d/m', strtotime($c['target_date']));
                            $eat_full  = date('l, d/m/Y', strtotime($c['target_date']));

                            if ($eat_ymd > $today_ymd) {
                                $base_cue_class = 'cue-upcoming';
                            } elseif ($eat_ymd === $today_ymd) {
                                $base_cue_class = 'cue-today';
                            } else {
                                $base_cue_class = 'cue-overdue';
                            }
                            $active_cue_class = $c['pos'] ? 'cue-done' : $base_cue_class;
                            $pos_cue_text = 'Post on ' . $eat_short . ($c['pos'] ? ' ✓' : '');
                        ?>
                        <tr class="k-row <?php echo $row_filter_class; ?>" data-name="<?php echo $search_name; ?>">
                            <td>
                                <div>
                                    <strong style="color: #379237; font-size: 1.1em;"><?php echo esc_html($c['name']); ?></strong>
                                    <?php echo $c['recipient_badge']; ?>
                                </div>
                                
                                <div style="margin-top:6px; font-size:0.95em; line-height:1.4;">
                                    <span class="k-customer-meta" style="color: #231F20; font-weight: bold;">
                                        Order: <?php echo $c['order_id']; ?>
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
                            </td>
                            
                            <td>
                                <div class="logistics-box" style="margin-top:0; margin-bottom:8px;">
                                    <span style="color: #475569;"><strong>Method:</strong> <?php echo esc_html($c['method']); ?></span><br>
                                    <span style="color: #475569;"><strong>Receive By:</strong> <?php echo esc_html($c['timing']); ?></span><br>
                                    <span style="color: #475569;"><strong>Time:</strong> <?php echo esc_html($c['time_slot']); ?></span>
                                </div>
                                <div style="text-align: left;">
                                    <div style="background: #379237; color: white; font-size: 0.9em; font-weight:bold; padding: 4px 8px; border-radius: 4px; display:inline-block; margin-bottom: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                                        Delivery: <?php echo date('D, M j', strtotime($c['delivery_date'])); ?>
                                    </div>
                                    <br>
                                    <span style="color: #64748b; font-size: 0.85em; font-weight: 600;">
                                        Eating: <?php echo date('D, M j', strtotime($c['target_date'])); ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <?php if ($c['is_expired']): ?>
                                    <div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; text-align:center; border:1px solid #f87171;">
                                        <strong style="font-size:1.1em; display:block; margin-bottom:5px;">PLAN EXPIRED</strong>
                                        <span style="font-size:0.9em;">Do not prep meals. Plan ended on <?php echo date('M j, Y', strtotime($c['expiry_date'])); ?>.</span>
                                    </div>
                                <?php elseif ($c['is_chefs_choice']): ?>
                                    
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
                                    
                                    $is_flexible = isset($c['is_flexible']) ? intval($c['is_flexible']) : 0;
                                    $allow_custom = isset($c['allow_custom']) ? intval($c['allow_custom']) : 0;
                                    ?>
                                    
                                    <div class="chef-assign-form cmp-no-print cmp-portal-container" data-log-id="<?php echo $c['log_id']; ?>" data-target-date="<?php echo esc_attr($c['target_date']); ?>" data-allowed-meals="<?php echo $allowed_meals; ?>" data-snack-quota="<?php echo $snack_count; ?>" data-is-juice="<?php echo $is_juice_plan ? '1' : '0'; ?>" data-allow-custom="<?php echo $allow_custom; ?>" style="display: <?php echo $form_style; ?>; background:#fffbdd; padding:10px; border:1px dashed #eab308; border-radius:5px;">
                                        <div style="font-size:0.9em; font-weight:bold; color:#b45309; margin-bottom:8px;">Assign Chef's Choice:</div>
                                        
                                        <?php if(!$is_juice_plan): ?>
                                            <?php foreach(['Breakfast','Lunch','Dinner'] as $cat): if(in_array($cat, $allowed_cats)): 
                                                $slot = strtolower($cat);
                                                $label = $is_flexible ? ($slot === 'breakfast' ? 'Meal 1' : ($slot === 'lunch' ? 'Meal 2' : 'Meal 3')) : $cat;
                                                $cat_filter = $is_flexible ? 'Any' : $cat;
                                            ?>
                                                <?php if($allow_custom): ?>
                                                    <input type="text" class="cmp-meal-custom cmp-main-meal" data-slot="<?php echo $slot; ?>" value="<?php echo esc_attr($raw->{'custom_'.$slot}); ?>" placeholder="Type <?php echo esc_attr($label); ?>..." style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                <?php else: ?>
                                                    <select class="chef-meal-select cmp-main-meal" data-cat="<?php echo $cat_filter; ?>" data-slot="<?php echo $slot; ?>" data-saved-val="<?php echo $raw->{$slot.'_id'}; ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                        <option value="">- <?php echo $label; ?> -</option>
                                                    </select>
                                                <?php endif; ?>
                                            <?php endif; endforeach; ?>
                                            
                                            <?php if($snack_count > 0): ?>
                                                <?php if($allow_custom): ?>
                                                    <input type="text" class="cmp-meal-custom cmp-snack-1" data-slot="snack_1" value="<?php echo esc_attr($raw->custom_snack_1); ?>" placeholder="Type Snack 1..." style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                    <?php if($snack_count == 2): ?>
                                                        <input type="text" class="cmp-meal-custom cmp-snack-2" data-slot="snack_2" value="<?php echo esc_attr($raw->custom_snack_2); ?>" placeholder="Type Snack 2..." style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <select class="chef-meal-select cmp-snack-1" data-cat="Snacks" data-slot="snack_1" data-saved-val="<?php echo $raw->snack_1_id; ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                        <option value="">- Snack 1 -</option>
                                                    </select>
                                                    <?php if($snack_count == 2): ?>
                                                        <select class="chef-meal-select cmp-snack-2" data-cat="Snacks" data-slot="snack_2" data-saved-val="<?php echo $raw->snack_2_id; ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                            <option value="">- Snack 2 -</option>
                                                        </select>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <?php for($j=1; $j<=3; $j++): ?>
                                                <?php if($allow_custom): ?>
                                                    <input type="text" class="cmp-meal-custom cmp-juice-<?php echo $j; ?>" data-slot="juice_<?php echo $j; ?>" value="<?php echo esc_attr($raw->{'custom_juice_'.$j}); ?>" placeholder="Type Juice <?php echo $j; ?>..." style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                <?php else: ?>
                                                    <select class="chef-meal-select cmp-juice-<?php echo $j; ?>" data-cat="Juices" data-slot="juice_<?php echo $j; ?>" data-saved-val="<?php echo $raw->{'juice_'.$j.'_id'}; ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                        <option value="">- Juice <?php echo $j; ?> -</option>
                                                    </select>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        <?php endif; ?>
                                        
                                        <button class="save-chef-assign" style="width:100%; background:#dba617; color:#fff; border:none; padding:6px; border-radius:4px; font-weight:bold; cursor:pointer; margin-top:4px; transition: 0.2s;">Save</button>
                                    </div>
                                    
                                <?php else: ?>
                                    <ul style="margin:0; padding-left:15px; line-height: 1.4;">
                                        <?php foreach($c['meals'] as $meal) echo "<li>$meal</li>"; ?>
                                    </ul>
                                <?php endif; ?>

                                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #ccc;">
                                    <div style="margin-bottom: 5px;">
                                        <strong><?php echo esc_html($plan_duration); ?> (<?php echo $c['day_number']; ?>/<?php echo $c['total_days']; ?>)</strong><br>
                                        <span style="color:#64748b; font-size:0.9em;"><?php echo esc_html($plan_meals); ?></span>
                                    </div>
                                    <?php if (!empty($c['foh_remarks'])): ?>
                                        <div style="background:#EEF7E3; color:#1B5E20; padding:6px 8px; border-radius:4px; font-size:0.85em; margin-top:8px; border-left:3px solid #379237; line-height:1.3;">
                                            <strong>FOH Note:</strong> <?php echo esc_html($c['foh_remarks']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td style="text-align:center; background:#f0fdf4; border-left:1px solid #ddd;">
                                <span class="kp-mob-cell-label">Prepared</span>
                                <input type="checkbox" class="chk-large cmp-prepared-box cmp-no-print" data-id="<?php echo $c['log_id']; ?>" <?php echo $c['prepared'] ? 'checked' : ''; ?> <?php echo $prepared_disabled; ?>>
                                <?php if($c['prepared']) echo '<span style="display:none; color:#166534; font-weight:bold;" class="print-only">Yes</span>'; ?>
                            </td>

                            <td style="text-align:center; background:#fcfcfc;">
                                <span class="kp-mob-cell-label">Dispatch</span>
                                <input type="checkbox" class="chk-large cmp-dispatch-box cmp-no-print" data-id="<?php echo $c['log_id']; ?>" <?php echo $c['dispatch'] ? 'checked' : ''; ?> <?php echo $dispatch_disabled; ?>>
                                <?php if($c['dispatch']) echo '<span style="display:none; color:green; font-weight:bold;" class="print-only">Yes</span>'; ?>
                            </td>

                            <td style="background:#fcfcfc;">
                                <span class="kp-mob-cell-label">Delivery</span>
                                <select class="cmp-delivery-box" data-id="<?php echo $c['log_id']; ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" <?php echo $delivery_disabled; ?>>
                                    <option value="Pending" <?php selected($c['delivery'], 'Pending'); ?>>Pending</option>
                                    <option value="Successful" <?php selected($c['delivery'], 'Successful'); ?>>Successful</option>
                                    <option value="Cancelled" <?php selected($c['delivery'], 'Cancelled'); ?>>Cancelled</option>
                                    <option value="Returned" <?php selected($c['delivery'], 'Returned'); ?>>Returned</option>
                                </select>
                            </td>

                            <td style="text-align:center; background:#f4f8fa;">
                                <span class="kp-mob-cell-label">POS Check</span>
                                <div class="kp-pos-cell-inner">
                                    <span class="kp-pos-cue <?php echo esc_attr($active_cue_class); ?> cmp-no-print"
                                          data-id="<?php echo $c['log_id']; ?>"
                                          data-base-class="<?php echo esc_attr($base_cue_class); ?>"
                                          data-eat-short="<?php echo esc_attr($eat_short); ?>"
                                          title="Post in POS on Customer Eating Date: <?php echo esc_attr($eat_full); ?>">
                                        <?php echo esc_html($pos_cue_text); ?>
                                    </span>
                                    <input type="checkbox" class="chk-large cmp-pos-box cmp-no-print" data-id="<?php echo $c['log_id']; ?>" <?php echo $c['pos'] ? 'checked' : ''; ?> <?php echo $pos_disabled; ?>>
                                </div>
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

        // ==========================================
        // OPTION 1A: DYNAMIC STICKY STACKING ENGINE
        // ==========================================
        function syncKitchenStickyOffsets() {
            var rootEl = document.getElementById('kp-portal-root');
            var bannerEl = document.getElementById('kp-sticky-banner');
            if (!rootEl || !bannerEl) return;

            var adminBar = document.getElementById('wpadminbar');
            var adminOffset = 0;
            if (adminBar) {
                var adminStyle = window.getComputedStyle(adminBar);
                if (adminStyle.position === 'fixed') {
                    adminOffset = adminBar.offsetHeight || 0;
                }
            }

            var bannerHeight = bannerEl.offsetHeight || 82;
            rootEl.style.setProperty('--kp-admin-offset', adminOffset + 'px');
            rootEl.style.setProperty('--kp-banner-height', bannerHeight + 'px');

            if (window.scrollY > 40) {
                bannerEl.classList.add('kp-is-scrolled');
            } else {
                bannerEl.classList.remove('kp-is-scrolled');
            }
        }

        syncKitchenStickyOffsets();
        $(window).on('resize orientationchange scroll', syncKitchenStickyOffsets);
        if (typeof ResizeObserver !== 'undefined') {
            var bannerEl = document.getElementById('kp-sticky-banner');
            if (bannerEl) {
                var ro = new ResizeObserver(function() {
                    syncKitchenStickyOffsets();
                });
                ro.observe(bannerEl);
            }
        }

        function populateDynamicDropdowns(container) {
            var isCustom = container.data('allow-custom') == 1;
            var dateVal = container.closest('tr').find('.cmp-date-picker').length ? container.closest('tr').find('.cmp-date-picker').val() : "<?php echo esc_js($target_date); ?>";
            var selects = container.find('select.chef-meal-select, select[class^="cmp-juice-"]');
            var inputs = container.find('input.cmp-meal-custom');
            var btn = container.find('.save-chef-assign');
            
            container.find('.boundary-overlay').remove();

            if (isCustom) {
                inputs.show();
                selects.hide();
                if (btn.text() === 'Menu Pending') {
                    btn.text('Save').css({'background':'#dba617', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
                }
                return true;
            }

            var availableFoods = cmpGlobalFoodData.filter(function(food) {
                if (!food.valid_from || !food.valid_until) return true; 
                return (dateVal >= food.valid_from && dateVal <= food.valid_until);
            });

            if (availableFoods.length === 0) {
                selects.hide();
                inputs.hide();
                container.prepend('<div class="boundary-overlay" style="color:#b45309; font-weight:bold; font-size:0.9em; background:#fef3c7; padding:10px; border-radius:4px; text-align:center; line-height:1.4;">Menu Pending Release</div>');
                btn.text('Menu Pending').css({'background':'#94a3b8', 'color':'#fff', 'cursor':'not-allowed'}).prop('disabled', true);
                return false;
            }

            selects.show();
            inputs.hide();
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
                    if (category === 'Any' && (food.category_name === 'Breakfast' || food.category_name === 'Lunch' || food.category_name === 'Dinner')) {
                        var isSelected = (savedVal == food.id) ? 'selected' : '';
                        var label = '[' + food.category_name + '] ' + food.food_name;
                        select.append('<option value="'+food.id+'" '+isSelected+'>'+label+'</option>');
                    } else if (food.category_name === category) {
                        var isSelected = (savedVal == food.id) ? 'selected' : '';
                        select.append('<option value="'+food.id+'" '+isSelected+'>'+food.food_name+'</option>');
                    }
                });
            });
            return true;
        }

        // Init all chef assignment forms dynamically
        $('.chef-assign-form').each(function() {
            populateDynamicDropdowns($(this));
        });

        // --- Tab Memory & Search Logic ---
        var urlParams = new URLSearchParams(window.location.search);
        var initialTab = urlParams.get('k_tab') || 'all';
        
        $('.k-tab-btn').removeClass('active');
        $('.k-tab-btn[data-filter="'+initialTab+'"]').addClass('active');

        function applyKitchenFilters() {
            var activeFilter = $('.k-tab-btn.active').data('filter');
            var searchQuery = $('#k-search').val().toLowerCase();
            var visibleCount = 0;

            $('.k-table tbody tr.k-row').each(function() {
                var row = $(this);
                var customerName = row.data('name');
                
                var matchTab = false;
                if (activeFilter === 'all') matchTab = true;
                else if (activeFilter === 'pending' && row.hasClass('filter-pending')) matchTab = true;
                else if (activeFilter === 'assigned' && row.hasClass('filter-assigned')) matchTab = true;

                var matchSearch = customerName.indexOf(searchQuery) > -1;

                if (matchTab && matchSearch) {
                    row.removeClass('k-row-hidden').show();
                    visibleCount++;
                } else {
                    row.addClass('k-row-hidden').hide();
                }
            });

            if (visibleCount === 0) {
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

        function enforceChefMealQuota(container) {
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var isJuice = container.data('is-juice') == '1';
            
            if (allowedMeals <= 0 || isJuice) return; 

            var mainSelects = container.find('.cmp-main-meal');
            var selectedCount = 0;
            
            mainSelects.each(function() {
                if ($(this).val().trim() !== "") selectedCount++;
            });

            if (selectedCount >= allowedMeals) {
                mainSelects.each(function() {
                    if ($(this).val().trim() === "") {
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

        $(document).on('change keyup', '.cmp-main-meal', function() {
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
            var snackQuota = parseInt(container.data('snack-quota')) || 0;
            var isJuice = container.data('is-juice') == '1';
            var isCustom = container.data('allow-custom') == '1';
            
            var isMenuPending = container.find('.boundary-overlay').length > 0;
            if (isMenuPending && !isCustom) {
                alert('The specific menu is not available yet.');
                return;
            }

            if (isCustom) {
                if (isJuice) {
                    var juiceInputs = container.find('.cmp-juice-1, .cmp-juice-2, .cmp-juice-3');
                    var missingJuice = false;
                    juiceInputs.each(function() { if ($(this).val().trim() === '') missingJuice = true; });
                    if (missingJuice) {
                        alert("Please complete all 3 juices to complete the assignment.");
                        return;
                    }
                } else {
                    var selectedMainCount = 0;
                    container.find('.cmp-main-meal').each(function() {
                        if ($(this).val().trim() !== "") selectedMainCount++;
                    });
                    if (selectedMainCount !== allowedMeals) {
                        alert("Please provide exactly " + allowedMeals + " main meal(s) to complete the assignment."); 
                        return; 
                    }
                    
                    var selectedSnackCount = 0;
                    container.find('.cmp-snack-1, .cmp-snack-2').each(function() {
                        if ($(this).val().trim() !== "") selectedSnackCount++;
                    });
                    if (selectedSnackCount !== snackQuota) {
                        alert("Please provide exactly " + snackQuota + " snack(s) to complete the assignment."); 
                        return; 
                    }
                }
            } else {
                if (!isJuice && allowedMeals > 0) {
                    var selectedMainCount = 0;
                    container.find('.cmp-main-meal').each(function() {
                        if ($(this).val() !== "") selectedMainCount++;
                    });
                    if (selectedMainCount < allowedMeals) {
                        alert("Please select exactly " + allowedMeals + " main meal(s) to complete the assignment."); 
                        return; 
                    }

                    var selectedSnackCount = 0;
                    container.find('select[data-cat="Snacks"]').each(function() {
                        if ($(this).val() !== "") selectedSnackCount++;
                    });
                    if (selectedSnackCount !== snackQuota) {
                        alert("Please select exactly " + snackQuota + " snack(s) to complete the assignment."); 
                        return; 
                    }
                } else if (isJuice) {
                    var missingJuice = false;
                    container.find('select').each(function() {
                        if ($(this).data('cat') === 'Juices' && $(this).val() === '') missingJuice = true;
                    });
                    if(missingJuice) { 
                        alert("Please select all juices to complete the assignment."); 
                        return; 
                    }
                }
            }
            
            btn.text('Saving...').prop('disabled', true).css('opacity', '0.7');
            
            var data = {
                action: 'cmp_assign_chef_meals',
                nonce: '<?php echo wp_create_nonce("cmp_kitchen_nonce"); ?>',
                log_id: logId
            };

            if (isCustom) {
                data.custom_breakfast = container.find('[data-slot="breakfast"]').val() || null;
                data.custom_lunch     = container.find('[data-slot="lunch"]').val() || null;
                data.custom_dinner    = container.find('[data-slot="dinner"]').val() || null;
                data.custom_snack_1   = container.find('[data-slot="snack_1"]').val() || null;
                data.custom_snack_2   = container.find('[data-slot="snack_2"]').val() || null;
                data.custom_juice_1   = container.find('[data-slot="juice_1"]').val() || null;
                data.custom_juice_2   = container.find('[data-slot="juice_2"]').val() || null;
                data.custom_juice_3   = container.find('[data-slot="juice_3"]').val() || null;
            } else {
                data.breakfast = container.find('[data-slot="breakfast"]').val() || null;
                data.lunch     = container.find('[data-slot="lunch"]').val() || null;
                data.dinner    = container.find('[data-slot="dinner"]').val() || null;
                data.snack_1   = container.find('[data-slot="snack_1"]').val() || null;
                data.snack_2   = container.find('[data-slot="snack_2"]').val() || null;
                data.juice_1   = container.find('[data-slot="juice_1"]').val() || null;
                data.juice_2   = container.find('[data-slot="juice_2"]').val() || null;
                data.juice_3   = container.find('[data-slot="juice_3"]').val() || null;
            }
            
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

        function updatePosCueBadge(logId, isPosChecked) {
            var cueEl = $('.kp-pos-cue[data-id="' + logId + '"]');
            if (!cueEl.length) return;
            var baseClass = cueEl.data('base-class') || 'cue-upcoming';
            var eatShort = cueEl.data('eat-short') || '';
            cueEl.removeClass('cue-upcoming cue-today cue-overdue cue-done');
            if (isPosChecked) {
                cueEl.addClass('cue-done').text('Post on ' + eatShort + ' ✓');
            } else {
                cueEl.addClass(baseClass).text('Post on ' + eatShort);
            }
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
                        isPosChecked = false;
                    }
                }
            }

            updatePosCueBadge(logId, isPosChecked);
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
