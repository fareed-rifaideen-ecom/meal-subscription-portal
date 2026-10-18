<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// 1. AJAX HANDLERS
// ==========================================
add_action('wp_ajax_cmp_save_daily_log', 'cmp_ajax_save_daily_log');
function cmp_ajax_save_daily_log() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');
    global $wpdb;

    if (!is_user_logged_in()) wp_send_json_error('Not logged in.');

    $log_id       = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
    $sub_id       = intval($_POST['sub_id']);
    $target_date  = sanitize_text_field($_POST['date']);

    // Check privileges
    $is_admin      = current_user_can('manage_options') || current_user_can('foh_manager');
    $is_chef       = current_user_can('kitchen_staff') || current_user_can('menu_manager');
    $is_privileged = $is_admin || $is_chef;

    // Cutoff Time Validation (Ironclad Server Rule)
    if ( ! $is_privileged ) {
        $tz           = new DateTimeZone('Asia/Dubai');
        $now          = new DateTime('now', $tz);
        $current_hour = (int) $now->format('H');
        $cutoff_hour  = intval( get_option('cmp_cutoff_time', '11') );
        $tomorrow     = ( clone $now )->modify('+1 day')->format('Y-m-d');
        $day_after    = ( clone $now )->modify('+2 days')->format('Y-m-d');

        $earliest_allowed = ( $current_hour >= $cutoff_hour ) ? $day_after : $tomorrow;

        if ( $target_date < $earliest_allowed ) {
            wp_send_json_error(
                'This date is no longer available. The cutoff time of ' .
                str_pad($cutoff_hour, 2, '0', STR_PAD_LEFT) .
                ':00 GST has passed. The earliest available date is ' .
                date('D, d M Y', strtotime($earliest_allowed)) . '.'
            );
        }
    }

    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $table_foods = $wpdb->prefix . 'cmp_foods';

    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_subs WHERE id = %d", $sub_id));
    if (!$sub) wp_send_json_error('Subscription not found.');

    // --- LOG-LEVEL SNAPSHOT LOGIC (Phase 7) ---
    if ($log_id > 0) {
        // It's an update: Preserve historical plan mode
        $existing_log = $wpdb->get_row($wpdb->prepare("SELECT plan_mode FROM $table_logs WHERE id = %d", $log_id));
        $plan_mode = ($existing_log && !empty($existing_log->plan_mode)) ? $existing_log->plan_mode : 'standard';
    } else {
        // It's new: Capture current subscription mode
        $plan_mode = 'standard';
        if (!empty($sub->is_flexible)) $plan_mode = 'flexible';
        if (!empty($sub->allow_custom)) $plan_mode = 'custom';
    }

    $row_is_flexible  = ($plan_mode === 'flexible') ? 1 : 0;
    $row_allow_custom = ($plan_mode === 'custom') ? 1 : 0;
    $is_chefs_choice  = isset($_POST['chefs_choice']) ? intval($_POST['chefs_choice']) : 0;

    // --- STRICT MENU AVAILABILITY CHECK ---
    $has_meals = false;
    if ($row_allow_custom) {
        $has_meals = !empty($_POST['custom_breakfast']) || !empty($_POST['custom_lunch']) || !empty($_POST['custom_dinner']) || !empty($_POST['custom_snack_1']) || !empty($_POST['custom_snack_2']) || !empty($_POST['custom_juice_1']) || !empty($_POST['custom_juice_2']) || !empty($_POST['custom_juice_3']);
    } else {
        $has_meals = !empty($_POST['breakfast']) || !empty($_POST['lunch']) || !empty($_POST['dinner']) || !empty($_POST['snack_1']) || !empty($_POST['snack_2']) || !empty($_POST['juice_1']) || !empty($_POST['juice_2']) || !empty($_POST['juice_3']);
    }

    $is_menu_pending = false;
    if (!$row_allow_custom) {
        $food_exists_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_foods WHERE is_active = 1 AND (valid_from IS NULL OR (valid_from <= %s AND valid_until >= %s))", $target_date, $target_date));
        $is_menu_pending = (intval($food_exists_count) === 0);

        if ($has_meals && $is_menu_pending) {
            wp_send_json_error('The menu for this date is pending release. Specific meals cannot be selected yet.');
        }
    }

    // --- ROBUST QUOTA CALCULATION ENGINE ---
    $is_juice = (stripos($sub->allowed_categories, 'Juices') !== false || stripos($sub->plan_name, 'juice') !== false || stripos($sub->plan_name, 'cleanse') !== false);

    $allowed_quota = 0;
    $snack_quota = 0;

    if (!$is_juice) {
        preg_match('/(\d+)\s*Meal/i', $sub->plan_name, $m);
        if (isset($m[1])) {
            $allowed_quota = intval($m[1]);
        } else {
            if (preg_match('/\b(one|1)\b/i', $sub->plan_name)) $allowed_quota = 1;
            elseif (preg_match('/\b(two|2)\b/i', $sub->plan_name)) $allowed_quota = 2;
            elseif (preg_match('/\b(three|3)\b/i', $sub->plan_name)) $allowed_quota = 3;
            else {
                $cats = explode(',', $sub->allowed_categories);
                foreach($cats as $c) {
                    if (in_array(trim($c), ['Breakfast', 'Lunch', 'Dinner'])) $allowed_quota++;
                }
            }
        }
        if ($allowed_quota <= 0) $allowed_quota = 1;
        $snack_quota = ($allowed_quota >= 2) ? 2 : 1;
    }

    // --- STRICT SELECTION QUOTA ENFORCEMENT ---
    if (!$is_admin && !$is_chefs_choice && !$is_menu_pending) {
        if ($is_juice) {
            if ($row_allow_custom) {
                if (empty($_POST['custom_juice_1']) || empty($_POST['custom_juice_2']) || empty($_POST['custom_juice_3'])) {
                    wp_send_json_error("Please provide exactly 3 juices to complete this day.");
                }
            } else {
                if (empty($_POST['juice_1']) || empty($_POST['juice_2']) || empty($_POST['juice_3'])) {
                    wp_send_json_error("Please select exactly 3 juices to complete this day.");
                }
            }
        } else {
            $submitted_count = 0;
            if ($row_allow_custom) {
                if (!empty($_POST['custom_breakfast'])) $submitted_count++;
                if (!empty($_POST['custom_lunch'])) $submitted_count++;
                if (!empty($_POST['custom_dinner'])) $submitted_count++;
            } else {
                if (!empty($_POST['breakfast'])) $submitted_count++;
                if (!empty($_POST['lunch'])) $submitted_count++;
                if (!empty($_POST['dinner'])) $submitted_count++;
            }

            if ($submitted_count !== $allowed_quota) {
                wp_send_json_error("Please select exactly {$allowed_quota} main meal(s).");
            }

            $submitted_snacks = 0;
            if ($row_allow_custom) {
                if (!empty($_POST['custom_snack_1'])) $submitted_snacks++;
                if (!empty($_POST['custom_snack_2'])) $submitted_snacks++;
            } else {
                if (!empty($_POST['snack_1'])) $submitted_snacks++;
                if (!empty($_POST['snack_2'])) $submitted_snacks++;
            }

            if ($submitted_snacks !== $snack_quota) {
                wp_send_json_error("Please select exactly {$snack_quota} snack(s).");
            }
        }
    }

    $data = array(
        'user_id'         => $sub->user_id,
        'subscription_id' => $sub_id,
        'target_date'     => $target_date,
        'is_chefs_choice' => $is_chefs_choice,
        'is_locked'       => 1,
        'plan_mode'       => $plan_mode
    );

    if ($row_allow_custom) {
        $data['custom_breakfast'] = !empty($_POST['custom_breakfast']) ? sanitize_text_field($_POST['custom_breakfast']) : null;
        $data['custom_lunch']     = !empty($_POST['custom_lunch']) ? sanitize_text_field($_POST['custom_lunch']) : null;
        $data['custom_dinner']    = !empty($_POST['custom_dinner']) ? sanitize_text_field($_POST['custom_dinner']) : null;
        $data['custom_snack_1']   = !empty($_POST['custom_snack_1']) ? sanitize_text_field($_POST['custom_snack_1']) : null;
        $data['custom_snack_2']   = !empty($_POST['custom_snack_2']) ? sanitize_text_field($_POST['custom_snack_2']) : null;
        $data['custom_juice_1']   = !empty($_POST['custom_juice_1']) ? sanitize_text_field($_POST['custom_juice_1']) : null;
        $data['custom_juice_2']   = !empty($_POST['custom_juice_2']) ? sanitize_text_field($_POST['custom_juice_2']) : null;
        $data['custom_juice_3']   = !empty($_POST['custom_juice_3']) ? sanitize_text_field($_POST['custom_juice_3']) : null;
        
        $data['breakfast_id'] = null;
        $data['lunch_id'] = null;
        $data['dinner_id'] = null;
        $data['snack_1_id'] = null;
        $data['snack_2_id'] = null;
        $data['juice_1_id'] = null;
        $data['juice_2_id'] = null;
        $data['juice_3_id'] = null;
    } else {
        $data['breakfast_id'] = !empty($_POST['breakfast']) ? intval($_POST['breakfast']) : null;
        $data['lunch_id']     = !empty($_POST['lunch']) ? intval($_POST['lunch']) : null;
        $data['dinner_id']    = !empty($_POST['dinner']) ? intval($_POST['dinner']) : null;
        $data['snack_1_id']   = !empty($_POST['snack_1']) ? intval($_POST['snack_1']) : null;
        $data['snack_2_id']   = !empty($_POST['snack_2']) ? intval($_POST['snack_2']) : null;
        $data['juice_1_id']   = !empty($_POST['juice_1']) ? intval($_POST['juice_1']) : null;
        $data['juice_2_id']   = !empty($_POST['juice_2']) ? intval($_POST['juice_2']) : null;
        $data['juice_3_id']   = !empty($_POST['juice_3']) ? intval($_POST['juice_3']) : null;

        $data['custom_breakfast'] = null;
        $data['custom_lunch'] = null;
        $data['custom_dinner'] = null;
        $data['custom_snack_1'] = null;
        $data['custom_snack_2'] = null;
        $data['custom_juice_1'] = null;
        $data['custom_juice_2'] = null;
        $data['custom_juice_3'] = null;
    }

    if ($log_id > 0) {
        $wpdb->update($table_logs, $data, array('id' => $log_id));
        $response_data = array('msg' => 'Updated');
    } else {
        $wpdb->insert($table_logs, $data);
        $response_data = array('new_log_id' => $wpdb->insert_id);
    }

    $updated_subs_queue = get_option('cmp_daily_updated_subs', array());
    if (!is_array($updated_subs_queue)) { $updated_subs_queue = array(); }

    if (!in_array($sub_id, $updated_subs_queue)) {
        $updated_subs_queue[] = $sub_id;
        update_option('cmp_daily_updated_subs', $updated_subs_queue);
    }

    wp_send_json_success($response_data);
}

// 1.5 AJAX HANDLER: CUSTOMER PORTAL INLINE LOGISTICS EDITING
add_action('wp_ajax_cmp_cp_update_customer_logistics', 'cmp_ajax_cp_update_customer_logistics');
function cmp_ajax_cp_update_customer_logistics() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');

    if ( ! current_user_can('manage_options') && ! current_user_can('foh_manager') && ! current_user_can('menu_manager') ) {
        wp_send_json_error('Permission Denied');
    }

    $user_id = intval($_POST['user_id']);
    $order_id = intval($_POST['order_id']);
    $timing = sanitize_text_field($_POST['delivery_timing']);
    $time_slot = sanitize_text_field($_POST['time_slot']);
    $allergies = sanitize_textarea_field($_POST['allergies']);

    if ($user_id > 0) {
        update_user_meta($user_id, 'delivery_timing', $timing);
        update_user_meta($user_id, 'time_slot', $time_slot);
        update_user_meta($user_id, 'allergies', $allergies);
    }

    if ($order_id > 0) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('delivery_timing', $timing);
            $order->update_meta_data('_cmp_delivery_timing', $timing);
            $order->update_meta_data('time_slot', $time_slot);
            $order->update_meta_data('_cmp_time_slot', $time_slot);
            $order->update_meta_data('allergies', $allergies);
            $order->set_customer_note($allergies);
            $order->save();
        }
    }

    wp_send_json_success(array('timing' => $timing, 'time_slot' => $time_slot, 'allergies' => $allergies ?: 'No Allergies Recorded'));
}

// 1.6 AJAX HANDLER: CUSTOMER PORTAL ADMIN ACCOUNT DETAILS EDITING
add_action('wp_ajax_cmp_cp_admin_update_account_details', 'cmp_ajax_cp_admin_update_account_details');
function cmp_ajax_cp_admin_update_account_details() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('Permission Denied. Admins only.');
    }

    $user_id = intval($_POST['user_id']);
    $order_id = intval($_POST['order_id']);
    $sub_id = isset($_POST['sub_id']) ? intval($_POST['sub_id']) : 0;

    $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']);
    $address = sanitize_text_field($_POST['address']);
    $recipient_name = sanitize_text_field($_POST['recipient_name']);

    if ($user_id > 0) {
        wp_update_user(['ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name]);
        update_user_meta($user_id, 'billing_first_name', $first_name);
        update_user_meta($user_id, 'billing_last_name', $last_name);
        update_user_meta($user_id, 'billing_address_1', $address);
        update_user_meta($user_id, 'billing_address_2', '');
        update_user_meta($user_id, 'billing_city', '');
    }

    if ($order_id > 0) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->set_billing_first_name($first_name);
            $order->set_billing_last_name($last_name);
            $order->set_shipping_first_name($first_name);
            $order->set_shipping_last_name($last_name);
            $order->set_billing_address_1($address);
            $order->set_shipping_address_1($address);
            $order->save();
        }
    }

    $new_plan_name = '';
    if ($sub_id > 0) {
        global $wpdb;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';
        
        $sub = $wpdb->get_row($wpdb->prepare("SELECT plan_name FROM $table_subs WHERE id = %d", $sub_id));
        if ($sub) {
            $plan_parts = explode(' - ', $sub->plan_name);
            $potential_recipient = end($plan_parts);
            if (!preg_match('/(Meal|Day|Cleanse|Plans)/i', $potential_recipient) && count($plan_parts) > 1) {
                array_pop($plan_parts);
                $core_plan_name = implode(' - ', $plan_parts);
            } else {
                $core_plan_name = $sub->plan_name;
            }

            $new_plan_name = $core_plan_name;
            if (!empty($recipient_name)) {
                $new_plan_name .= ' - ' . $recipient_name;
            }

            $wpdb->update($table_subs, array('plan_name' => $new_plan_name), array('id' => $sub_id));
        }
    }

    wp_send_json_success(array(
        'name' => trim($first_name . ' ' . $last_name),
        'address' => $address ?: 'Address not provided',
        'recipient' => $recipient_name,
        'plan_name' => $new_plan_name
    ));
}

// 1.7 AJAX HANDLER: ADMIN ADD/REMOVE PLAN DAYS
add_action('wp_ajax_cmp_cp_admin_adjust_days', 'cmp_ajax_cp_admin_adjust_days');
function cmp_ajax_cp_admin_adjust_days() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');
    if ( ! current_user_can('manage_options') && ! current_user_can('menu_manager') ) {
        wp_send_json_error('Permission Denied. Only Admins and Menu Managers can adjust total plan days.');
    }

    $sub_id = intval($_POST['sub_id']);
    $operation = sanitize_text_field($_POST['operation']);

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $sub = $wpdb->get_row($wpdb->prepare("SELECT total_days, expiry_date FROM $table_subs WHERE id = %d", $sub_id));

    if (!$sub) wp_send_json_error('Subscription not found.');

    $new_days = intval($sub->total_days);
    $current_expiry = new DateTime($sub->expiry_date);

    if ($operation === 'add') {
        $new_days++;
        $current_expiry->modify('+1 day');
    } elseif ($operation === 'remove') {
        if ($new_days <= 1) {
            wp_send_json_error('Cannot reduce plan below 1 day.');
        }
        $new_days--;
        $current_expiry->modify('-1 day');
    } else {
        wp_send_json_error('Invalid operation.');
    }

    $wpdb->update($table_subs, array(
        'total_days' => $new_days,
        'expiry_date' => $current_expiry->format('Y-m-d H:i:s')
    ), array('id' => $sub_id));

    wp_send_json_success(array('new_days' => $new_days));
}

// 1.8 AJAX HANDLER: CLEAR/RESET DAILY SELECTION
add_action('wp_ajax_cmp_clear_daily_log', 'cmp_ajax_clear_daily_log');
function cmp_ajax_clear_daily_log() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');
    global $wpdb;

    if (!is_user_logged_in()) wp_send_json_error('Not logged in.');

    $log_id = intval($_POST['log_id']);
    $sub_id = intval($_POST['sub_id']);
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_logs WHERE id = %d", $log_id));
    if (!$log) wp_send_json_error('Selection not found.');
    
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_subs WHERE id = %d", $sub_id));
    if (!$sub) wp_send_json_error('Subscription not found.');

    // Check privileges
    $is_admin      = current_user_can('manage_options') || current_user_can('foh_manager') || current_user_can('menu_manager');
    $is_chef       = current_user_can('kitchen_staff');
    $is_privileged = $is_admin || $is_chef;
    
    if (!$is_privileged && $sub->user_id != get_current_user_id()) {
        wp_send_json_error('Unauthorized access to this subscription.');
    }

    if ( ! $is_privileged ) {
        $tz           = new DateTimeZone('Asia/Dubai');
        $now          = new DateTime('now', $tz);
        $current_hour = (int) $now->format('H');
        $cutoff_hour  = intval( get_option('cmp_cutoff_time', '11') );
        $tomorrow     = ( clone $now )->modify('+1 day')->format('Y-m-d');
        $day_after    = ( clone $now )->modify('+2 days')->format('Y-m-d');

        $earliest_allowed = ( $current_hour >= $cutoff_hour ) ? $day_after : $tomorrow;

        if ( $log->target_date < $earliest_allowed ) {
            wp_send_json_error('Cut-off time has passed for this date. You cannot clear this selection.');
        }
    }

    $wpdb->delete($table_logs, array('id' => $log_id));
    wp_send_json_success(array('msg' => 'Cleared'));
}

// 1.9 AJAX HANDLER: CUSTOMER/FOH CANCEL SPECIFIC DAY
add_action('wp_ajax_cmp_cancel_daily_log', 'cmp_ajax_cancel_daily_log');
function cmp_ajax_cancel_daily_log() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');
    global $wpdb;

    if (!is_user_logged_in()) wp_send_json_error('Not logged in.');

    $log_id = intval($_POST['log_id']);
    $sub_id = intval($_POST['sub_id']);
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_logs WHERE id = %d", $log_id));
    if (!$log) wp_send_json_error('Selection not found.');
    
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_subs WHERE id = %d", $sub_id));
    if (!$sub) wp_send_json_error('Subscription not found.');

    $is_admin      = current_user_can('manage_options') || current_user_can('foh_manager') || current_user_can('menu_manager');
    $is_chef       = current_user_can('kitchen_staff');
    $is_privileged = $is_admin || $is_chef;
    
    if (!$is_privileged && $sub->user_id != get_current_user_id()) {
        wp_send_json_error('Unauthorized access to this subscription.');
    }

    if ( ! $is_privileged ) {
        $tz           = new DateTimeZone('Asia/Dubai');
        $now          = new DateTime('now', $tz);
        $current_hour = (int) $now->format('H');
        $cutoff_hour  = intval( get_option('cmp_cutoff_time', '11') );
        $tomorrow     = ( clone $now )->modify('+1 day')->format('Y-m-d');
        $day_after    = ( clone $now )->modify('+2 days')->format('Y-m-d');

        $earliest_allowed = ( $current_hour >= $cutoff_hour ) ? $day_after : $tomorrow;

        if ( $log->target_date < $earliest_allowed ) {
            wp_send_json_error('Cut-off time has passed for this date. You cannot cancel this day.');
        }
    }

    $wpdb->update($table_logs, array('delivery_result' => 'Cancelled'), array('id' => $log_id));

    $updated_subs_queue = get_option('cmp_daily_updated_subs', array());
    if (!is_array($updated_subs_queue)) { $updated_subs_queue = array(); }
    if (!in_array($sub_id, $updated_subs_queue)) {
        $updated_subs_queue[] = $sub_id;
        update_option('cmp_daily_updated_subs', $updated_subs_queue);
    }

    wp_send_json_success(array('msg' => 'Day Cancelled'));
}


// ==========================================
// 2. DASHBOARD RENDERER
// ==========================================
add_shortcode( 'meal_customer_portal', 'cmp_render_customer_portal' );
function cmp_render_customer_portal() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    date_default_timezone_set('Asia/Dubai');

    // Security & Custom Glassmorphism UI/UX Login Screen for Customers
    if ( ! is_user_logged_in() ) {
        if ( function_exists( 'cmp_render_portal_login_ui' ) ) {
            return cmp_render_portal_login_ui( array(
                'portal_id'      => 'customer',
                'title'          => 'My Meal Plan Portal',
                'subtitle'       => 'Sign in to customize your daily meals & schedule',
                'required_caps'  => 'any',
                'denied_message' => 'Please sign in with your registered customer account.',
            ) );
        }
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $current_user = wp_get_current_user();
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods = $wpdb->prefix . 'cmp_foods';

    $dynamic_cutoff_hour  = intval( get_option('cmp_cutoff_time', '11') );
    $label_chefs_choice   = get_option('cmp_label_chefs_choice', "Chef's Choice");
    $blackout_string      = get_option('cmp_blackout_dates', '');
    $blackout_dates_array = !empty($blackout_string) ? explode(',', str_replace(' ', '', $blackout_string)) : array();
    $raw_wa               = get_option('cmp_whatsapp_number', '');
    $clean_wa             = preg_replace('/[^0-9]/', '', $raw_wa);

    // Clean SVG Icons for Action Buttons
    $icon_reset = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>';
    $icon_cancel = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

    // OVERRIDE DETECTION
    $is_admin_override = isset($_GET['admin_edit_sub']) && (current_user_can('manage_options') || current_user_can('foh_manager') || current_user_can('menu_manager'));
    $is_chef_override  = isset($_GET['chef_override_sub']) && (current_user_can('manage_options') || current_user_can('kitchen_staff') || current_user_can('menu_manager') || current_user_can('foh_manager'));

    if ($is_admin_override) {
        $raw_subs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", intval($_GET['admin_edit_sub'])) );
    } elseif ($is_chef_override) {
        $raw_subs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", intval($_GET['chef_override_sub'])) );
    } else {
        $raw_subs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d AND status IN ('active','paused') ORDER BY id DESC", $user_id) );
    }

    $subs = array();
    $today = date('Y-m-d H:i:s');
    if ($raw_subs) {
        foreach ($raw_subs as $sub) {
            if ($is_admin_override || $is_chef_override) {
                $subs[] = $sub; 
            } else {
                $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
                if ($usage_days < $sub->total_days && $sub->expiry_date >= $today) {
                    $subs[] = $sub; 
                }
            }
        }
    }

    if ( empty($subs) ) {
        return '<div style="max-width:520px; margin:40px auto; padding:40px 30px; text-align:center; background:rgba(255,255,255,0.88); backdrop-filter:blur(18px); border:1.5px solid #DCECC9; border-top:5px solid #379237; border-radius:18px; box-shadow:0 16px 36px rgba(27,94,32,0.10);">
                    <h3 style="color:#1B5E20; font-size:1.5em; margin:0 0 10px 0;">No Active Meal Plans Found</h3>
                    <p style="color:#475569; margin-bottom: 25px; line-height:1.5;">It looks like your meal plans have been completed or expired.</p>
                    <a href="' . esc_url( site_url('/') ) . '" style="background:linear-gradient(135deg, #379237 0%, #1B5E20 100%); color:#fff; text-decoration:none; padding:13px 28px; border-radius:10px; font-weight:bold; display:inline-block; margin-bottom:18px; box-shadow:0 6px 16px rgba(55,146,55,0.25);">Subscribe to a New Meal Plan</a>
                    <br>
                    <a href="' . esc_url( wp_logout_url( get_permalink() ) ) . '" style="color:#dc2626; font-weight:bold; text-decoration:none; font-size:0.95em;">Log Out</a>
                </div>';
    }

    if (($is_admin_override || $is_chef_override) && !empty($subs)) {
        $override_user_id = $subs[0]->user_id;
        $override_user = get_userdata($override_user_id);
        $fname = get_user_meta($override_user_id, 'first_name', true) ?: get_user_meta($override_user_id, 'billing_first_name', true);
        $lname = get_user_meta($override_user_id, 'last_name', true) ?: get_user_meta($override_user_id, 'billing_last_name', true);
        $override_name = trim($fname . ' ' . $lname);
        $customer_display_name = !empty($override_name) ? $override_name : ($override_user ? $override_user->display_name : 'Customer');

        if ($is_chef_override) {
            $greeting_name = "Chef Mode (Managing: " . esc_html($customer_display_name) . ")";
        } else {
            $greeting_name = esc_html($customer_display_name) . " (Admin Override)";
        }
    } else {
        $fname = get_user_meta($current_user->ID, 'first_name', true) ?: get_user_meta($current_user->ID, 'billing_first_name', true);
        $lname = get_user_meta($current_user->ID, 'last_name', true) ?: get_user_meta($current_user->ID, 'billing_last_name', true);
        $curr_name = trim($fname . ' ' . $lname);
        $greeting_name = !empty($curr_name) ? $curr_name : $current_user->display_name;
    }

    // --- FETCH ALL FOODS FOR JS INJECTION ---
    $foods = $wpdb->get_results("SELECT * FROM $table_foods WHERE is_active = 1 ORDER BY category_name, food_name");
    $foods_map = array(); 
    foreach ($foods as $food) { $foods_map[$food->id] = $food; }

    $current_hour = (int) date('H');

    // --- UNLOCK BACK-DATES FOR ADMINS ---
    if ($is_admin_override || $is_chef_override) {
        $global_min_date = ''; 
        $note_text = "Admin/Chef Mode: Date restrictions are disabled. You may assign back-dates.";
        $note_color = "#379237"; 
    } elseif ( $current_hour >= $dynamic_cutoff_hour ) {
        $global_min_date = date( 'Y-m-d', strtotime('+2 days') );
        $note_text = "Note: It is past " . str_pad($dynamic_cutoff_hour, 2, '0', STR_PAD_LEFT) . ":00 GST. Meal selections are now locked for tomorrow.";
        $note_color = "#d63638"; 
    } else {
        $global_min_date = date( 'Y-m-d', strtotime('+1 day') );
        $note_text = "Note: Please make selections before " . str_pad($dynamic_cutoff_hour, 2, '0', STR_PAD_LEFT) . ":00 GST the day prior to delivery.";
        $note_color = "#1B5E20"; 
    }

    ob_start();
    ?>
    <script>
        var cmpGlobalFoodData = <?php echo json_encode($foods); ?>;
    </script>

    <style>
        .cmp-dashboard-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; overflow-x: hidden; box-sizing: border-box; position: relative; padding: 0 12px; }
        
        /* Desktop Customer Glass Header (> 992px) */
        .cmp-header-banner {
            background: linear-gradient(135deg, rgba(27, 94, 32, 0.92) 0%, rgba(55, 146, 55, 0.86) 55%, rgba(104, 185, 70, 0.82) 100%) !important;
            backdrop-filter: blur(18px) !important;
            -webkit-backdrop-filter: blur(18px) !important;
            color: #fff !important;
            padding: 25px 28px !important;
            border-radius: 18px 18px 0 0 !important;
            border: 1.5px solid #DCECC9 !important;
            border-bottom: 4px solid #68B946 !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 15px !important;
            box-shadow: 0 10px 28px rgba(27, 94, 32, 0.12) !important;
        }
        .cmp-header-banner h1 { margin: 0 !important; color: #ffffff !important; font-size: 1.9em !important; font-weight: 800 !important; }
        .cmp-header-banner p { margin: 5px 0 0 0 !important; color: #EEF7E3 !important; font-weight: 600 !important; }

        /* Tablet Customer Glass Header (601px - 991px) */
        @media (min-width: 601px) and (max-width: 991px) {
            .cmp-header-banner {
                background: linear-gradient(125deg, rgba(220, 236, 201, 0.92) 0%, rgba(104, 185, 70, 0.42) 100%) !important;
                border: 1.5px solid #379237 !important;
                padding: 22px 24px !important;
            }
            .cmp-header-banner h1 { color: #231F20 !important; font-size: 1.65em !important; }
            .cmp-header-banner p { color: #1B5E20 !important; }
        }

        /* Mobile Customer Glass Header (<= 600px) */
        @media (max-width: 600px) {
            .cmp-header-banner {
                background: linear-gradient(145deg, rgba(55, 146, 55, 0.95) 0%, rgba(27, 94, 32, 0.92) 100%) !important;
                border: 1.5px solid #68B946 !important;
                border-radius: 16px 16px 0 0 !important;
                padding: 18px !important;
            }
            .cmp-header-banner h1 { color: #ffffff !important; font-size: 1.45em !important; }
            .cmp-header-banner p { color: #DCECC9 !important; font-size: 0.95em !important; }
        }

        .cmp-tab-nav { display: flex; background: #fff; border: 1px solid #DCECC9; border-top: none; border-bottom: 2px solid #DCECC9; padding: 0 20px; overflow-x: auto; -webkit-overflow-scrolling: touch; align-items: flex-end; border-radius: 0 0 12px 12px; }
        .cmp-tab-btn { padding: 12px 25px; border: none; background: none; font-weight: bold; cursor: pointer; border-bottom: 3px solid transparent; color: #64748b; white-space: nowrap; line-height: 1.4; text-align: center; transition: 0.2s; }
        .cmp-tab-btn.active { color: #1B5E20; border-bottom-color: #379237; background: rgba(238, 247, 227, 0.45); }
        .cmp-tab-content { display: none; padding: 25px 0; }
        .cmp-tab-content.active { display: block; }

        .cmp-table-responsive { overflow: hidden; background: #fff; border: 1px solid #DCECC9; border-radius: 12px; width: 100%; box-shadow: 0 4px 14px rgba(27, 94, 32, 0.05); }
        .cmp-table { width: 100%; border-collapse: collapse; font-size: 0.9em; table-layout: fixed; }
        .cmp-table th { padding: 12px 5px; border-bottom: 2px solid #DCECC9; background: #EEF7E3; color: #1B5E20; text-align: center; vertical-align: middle; white-space: normal; overflow: visible; word-wrap: break-word; }
        .cmp-table td { padding: 10px 5px; border-bottom: 1px solid #eee; vertical-align: middle; box-sizing: border-box; }

        .cmp-mobile-label { display: none; }
        .cmp-mobile-status { display: none; }
        .macro-mobile { display: none; }
        .macro-desktop { display: inline-block; white-space: normal; word-wrap: break-word; line-height: 1.4; font-size: 0.85em; transition: opacity 0.2s; }

        .cmp-wa-float {
            position: fixed; bottom: 30px; right: 30px; background-color: #25d366; color: white; border-radius: 50px;
            padding: 12px 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 12px rgba(37, 211, 102, 0.4);
            text-decoration: none; font-weight: bold; font-size: 16px; z-index: 9999; transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .cmp-wa-float:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(37, 211, 102, 0.5); color: white; }

        @media (min-width: 769px) {
            .cmp-table select { width: 100%; padding: 8px; box-sizing: border-box; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; height: 42px; border: 1px solid #cbd5e1; border-radius: 6px; }
            .cmp-table input[type="date"] { width: 100% !important; padding: 6px !important; box-sizing: border-box !important; height: 42px !important; border: 1px solid #cbd5e1; border-radius: 6px; }
            .cmp-table th:nth-child(1) { width: 55px; }  
            .cmp-table th:nth-child(2) { width: 145px; } 
            .cmp-table th:nth-child(3) { width: 100px; } 

            .cmp-table th:last-child { width: 85px; }         
            .cmp-table th:nth-last-child(2) { width: 105px; } 
            .cmp-table th:nth-last-child(3) { width: 110px; } 
            .cmp-table th:nth-last-child(4) { width: 135px; } 

            .cmp-stacked-snack { margin-bottom: 8px; }
            .cmp-save-row { padding: 10px 5px !important; height: 42px; width: 100%; box-sizing: border-box; border-radius: 6px; }
        }

        @media (max-width: 768px) {
            .cmp-dashboard-wrap { padding: 0 5px; }
            .cmp-table-responsive { overflow: visible !important; border: none !important; box-shadow: none !important; background: transparent !important; }
            .cmp-table { width: 100% !important; display: block !important; } 
            .cmp-table tbody, .cmp-table tr, .cmp-table td { display: block !important; width: 100% !important; box-sizing: border-box !important; }
            .cmp-table thead { display: none !important; }
            .cmp-table td { border: none !important; padding: 10px 0 !important; text-align: left !important; overflow: hidden; }

            .cmp-table tr {
                margin-bottom: 25px !important; border-radius: 12px !important; padding: 15px 20px 20px 20px !important;
                box-shadow: 0 4px 10px rgba(0,0,0,0.05) !important; background: #fff !important; border: 2px solid #cbd5e1 !important;
            }

            .cmp-table td:nth-child(1) {
                border-radius: 8px !important; text-align: left !important; font-size: 1.3em !important; margin-bottom: 15px !important;
                padding: 15px 20px !important; cursor: pointer; position: relative; transition: all 0.2s ease;
            }

            .cmp-table td:nth-child(1)::after { content: '\25BC'; position: absolute; right: 20px; top: 50%; transform: translateY(-50%); font-size: 16px; color: inherit; }
            .cmp-table tr.is-open td:nth-child(1)::after { content: '\25B2'; }
            .cmp-table tr:not(.is-open) td:not(:first-child) { display: none !important; }

            .cmp-mobile-status { display: inline-block !important; margin-left: 10px; font-weight: normal; vertical-align: middle; }

            .status-pending { border: 2px solid #fde68a !important; }
            .status-pending td:nth-child(1) { background: #fffbeb !important; color: #b45309 !important; border: 1px solid #fde68a !important; }

            .status-saved { border: 2px solid #bbf7d0 !important; }
            .status-saved td:nth-child(1) { background: #f0fdf4 !important; color: #166534 !important; border: 1px solid #bbf7d0 !important; }

            .status-void { border: 2px solid #fecdd3 !important; opacity: 0.75; }
            .status-void td:nth-child(1) { background: #fff1f2 !important; color: #9f1239 !important; border: 1px solid #fecdd3 !important; }

            .cmp-mobile-label { display: block !important; font-weight: bold !important; color: #1B5E20 !important; margin-bottom: 8px !important; font-size: 1.05em !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; }

            .cmp-table select, .cmp-table input[type="text"], .cmp-table input[type="date"] {
                -webkit-appearance: none !important; appearance: none !important; width: 100% !important; min-height: 55px !important; padding: 15px 40px 15px 20px !important; font-size: 16px !important; 
                background-color: #f8fafc !important; border: 2px solid #cbd5e1 !important; border-radius: 10px !important; box-shadow: 0 3px 0 #cbd5e1 !important;
                font-weight: bold !important; color: #231F20 !important; margin-bottom: 10px !important; box-sizing: border-box !important;
            }
            .cmp-table select {
                background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23379237' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e") !important;
                background-repeat: no-repeat !important; background-position: right 15px center !important; background-size: 18px !important;
            }
            .cmp-table select:active { transform: translateY(3px) !important; box-shadow: 0 0 0 #cbd5e1 !important; }
            .cmp-table input[type="date"], .cmp-table input[type="text"] { padding-right: 15px !important; background-image: none !important; }

            .cmp-chefs-choice { transform: scale(1.8) !important; margin: 5px 15px 5px 5px !important; accent-color: #379237 !important; }

            .macro-mobile { display: block !important; width: 100%; transition: opacity 0.2s; }
            .cmp-macro-display { display: block !important; margin-top: 10px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1; font-size: 1.1em !important; }
            .mob-grid { display: flex; flex-wrap: wrap; justify-content: space-between; }
            .mob-grid div { width: 48%; margin-bottom: 5px; }

            .cmp-save-row { width: 100% !important; padding: 18px !important; font-size: 20px !important; border-radius: 10px !important; margin-top: 10px !important; }
            .cmp-wa-float { bottom: 20px; right: 20px; padding: 12px; border-radius: 50%; }
            .cmp-wa-text { display: none; }
        }
    </style>

    <div class="cmp-dashboard-wrap">
        <div class="cmp-header-banner">
            <div>
                <h1>My Meal Plan Dashboard</h1>
                <p><?php echo esc_html($greeting_name); ?></p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a id="cmp-global-export-btn" href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_customer_csv&sub_id=' . $subs[0]->id)); ?>" style="background:#379237; color:#fff; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:bold; border: 1px solid rgba(220,236,201,0.5);">Export to Excel</a>
                <?php if(!$is_chef_override && !$is_admin_override): ?>
                    <a href="<?php echo esc_url( wp_logout_url(get_permalink()) ); ?>" style="background:#dc2626; color:#fff; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:bold;">Log Out</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="cmp-tab-nav">
            <?php 
            $first_tab = true;
            foreach ($subs as $sub) { 
                $active_class = $first_tab ? 'active' : '';
                $start_date_formatted = !empty($sub->start_date) ? date('M d, Y', strtotime($sub->start_date)) : 'N/A';

                echo '<button class="cmp-tab-btn ' . $active_class . '" onclick="switchTab(event, \'plan-tab-' . esc_attr($sub->id) . '\')">';
                echo '<span id="tab-title-' . esc_attr($sub->id) . '">' . esc_html($sub->plan_name) . '</span><br><span style="font-size: 0.85em; font-weight: normal; opacity: 0.8;">' . esc_html($start_date_formatted) . '</span>';
                echo '</button>';

                $first_tab = false;
            }
            ?>
        </div>

        <?php 
        $first_content = true;
        foreach ($subs as $sub): 
            $tab_id = 'plan-tab-' . $sub->id;
            $active_class = $first_content ? 'active' : '';
            $first_content = false;

            $order = wc_get_order($sub->wc_order_id);
            $sub_user = get_userdata($sub->user_id);

            $fname = get_user_meta($sub->user_id, 'first_name', true) ?: get_user_meta($sub->user_id, 'billing_first_name', true);
            $lname = get_user_meta($sub->user_id, 'last_name', true) ?: get_user_meta($sub->user_id, 'billing_last_name', true);
            if ($order) {
                $fname = $order->get_billing_first_name() ?: $fname;
                $lname = $order->get_billing_last_name() ?: $lname;
            }

            $fallback_name = trim($fname . ' ' . $lname);
            if (empty($fallback_name)) $fallback_name = $sub_user ? $sub_user->display_name : 'Customer';
            $display_name = $fallback_name;

            // Recipient Extraction
            $plan_parts = explode(' - ', $sub->plan_name);
            $potential_recipient = end($plan_parts);
            $recipient_name = '';

            if (!preg_match('/(Meal|Day|Cleanse|Plans)/i', $potential_recipient) && count($plan_parts) > 1) {
                $recipient_name = $potential_recipient;
                array_pop($plan_parts);
                $core_plan_name = implode(' - ', $plan_parts);
            } else {
                $core_plan_name = $sub->plan_name;
            }

            $timing = !empty($sub->delivery_timing) ? $sub->delivery_timing : '';
            if (empty($timing) && $order) $timing = $order->get_meta('_cmp_delivery_timing') ?: $order->get_meta('delivery_timing');
            if (empty($timing)) $timing = get_user_meta($sub->user_id, 'delivery_timing', true);

            $time_slot = !empty($sub->time_slot) ? $sub->time_slot : '';
            if (empty($time_slot) && $order) $time_slot = $order->get_meta('_cmp_time_slot') ?: $order->get_meta('time_slot');
            if (empty($time_slot)) $time_slot = get_user_meta($sub->user_id, 'time_slot', true);

            $method = !empty($sub->delivery_method) ? $sub->delivery_method : '';
            if (empty($method) && $order) $method = $order->get_meta('_cmp_logistics_method') ?: $order->get_meta('delivery_method');
            if (empty($method)) $method = get_user_meta($sub->user_id, 'delivery_method', true);

            $pickup = !empty($sub->pickup_location) ? $sub->pickup_location : '';
            if (empty($pickup) && $order) $pickup = $order->get_meta('_cmp_pickup_location') ?: $order->get_meta('pickup_location');
            if (empty($pickup)) $pickup = get_user_meta($sub->user_id, 'pickup_location', true);

            $allergies = !empty($sub->allergies) ? $sub->allergies : '';
            if (empty($allergies) && $order) $allergies = $order->get_customer_note();
            if (empty($allergies)) $allergies = get_user_meta($sub->user_id, 'allergies', true);

            $address = 'Address not provided';
            if ($order) {
                $addr_parts = array_filter([$order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city()]);
                if (empty($addr_parts)) $addr_parts = array_filter([$order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city()]);
                if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
            }
            if ($address === 'Address not provided') {
                $addr1 = get_user_meta($sub->user_id, 'billing_address_1', true);
                $addr2 = get_user_meta($sub->user_id, 'billing_address_2', true);
                $city = get_user_meta($sub->user_id, 'billing_city', true);
                $addr_parts = array_filter([$addr1, $addr2, $city]);
                if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
            }

            $email = $order ? $order->get_billing_email() : ($sub_user ? $sub_user->user_email : '');
            $phone = $order ? $order->get_billing_phone() : get_user_meta($sub->user_id, 'billing_phone', true);

            $method_display = $method ?: 'N/A';
            if ($method === 'Pickup' && !empty($pickup)) { $method_display .= ' (' . esc_html($pickup) . ')'; }
            $timing_display = $timing ?: 'N/A';
            $time_slot_display = $time_slot ?: 'N/A';
            $is_paused = ($sub->status === 'paused');

            // --- QUOTA ENGINE ---
            $is_juice = (stripos($sub->allowed_categories, 'Juices') !== false || stripos($sub->plan_name, 'juice') !== false || stripos($sub->plan_name, 'cleanse') !== false);
            
            $is_flexible  = isset($sub->is_flexible) ? intval($sub->is_flexible) : 0;
            $allow_custom = isset($sub->allow_custom) ? intval($sub->allow_custom) : 0;

            $allowed_quota = 0;
            $snack_quota = 0;

            if (!$is_juice) {
                preg_match('/(\d+)\s*Meal/i', $sub->plan_name, $m);
                if (isset($m[1])) {
                    $allowed_quota = intval($m[1]);
                } else {
                    if (preg_match('/\b(one|1)\b/i', $sub->plan_name)) $allowed_quota = 1;
                    elseif (preg_match('/\b(two|2)\b/i', $sub->plan_name)) $allowed_quota = 2;
                    elseif (preg_match('/\b(three|3)\b/i', $sub->plan_name)) $allowed_quota = 3;
                    else {
                        $cats = explode(',', $sub->allowed_categories);
                        foreach($cats as $c) {
                            if (in_array(trim($c), ['Breakfast', 'Lunch', 'Dinner'])) $allowed_quota++;
                        }
                    }
                }
                if ($allowed_quota <= 0) $allowed_quota = 1;
                $snack_quota = ($allowed_quota >= 2) ? 2 : 1; 
            }
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="cmp-tab-content <?php echo $active_class; ?> cmp-portal-container" data-sub-id="<?php echo $sub->id; ?>" data-total-days="<?php echo $sub->total_days; ?>" data-allowed-meals="<?php echo $allowed_quota; ?>" data-snack-quota="<?php echo $snack_quota; ?>" data-is-juice="<?php echo $is_juice ? '1' : '0'; ?>">

            <div style="background:#fff; border:1px solid #DCECC9; border-radius:12px; padding:25px; margin-bottom:20px; box-shadow:0 4px 12px rgba(27,94,32,0.04); display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px;">
                <div style="flex:1; min-width:250px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <h3 style="margin:0; color:#379237; font-size:1.3em;">Account Details</h3>
                        <?php if (current_user_can('manage_options')): ?>
                            <button class="toggle-account-edit" data-sub-id="<?php echo $sub->id; ?>" style="background:none; border:none; color:#379237; cursor:pointer; font-size:0.85em; font-weight:bold; text-decoration:underline; padding:0;">Edit</button>
                        <?php endif; ?>
                    </div>

                    <div id="account-view-<?php echo $sub->id; ?>">
                        <p style="margin:0 0 8px 0; color:#231F20;"><strong>Order ID:</strong> <?php echo ($sub->wc_order_id > 0) ? '#' . esc_html($sub->wc_order_id) : 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT); ?></p>
                        <p style="margin:0 0 8px 0; color:#231F20;"><strong>Name:</strong> <span id="name-val-<?php echo $sub->id; ?>"><?php echo esc_html($display_name); ?></span></p>

                        <p id="recipient-row-<?php echo $sub->id; ?>" style="margin:0 0 8px 0; color:#231F20; display:<?php echo !empty($recipient_name) ? 'block' : 'none'; ?>"><strong>Recipient:</strong> <span id="recipient-val-<?php echo $sub->id; ?>"><?php echo esc_html($recipient_name); ?></span></p>

                        <p style="margin:0 0 8px 0; color:#231F20;"><strong>Email:</strong> <?php echo esc_html($email); ?></p>
                        <p style="margin:0 0 8px 0; color:#231F20;"><strong>Phone:</strong> <?php echo esc_html($phone ?: 'N/A'); ?></p>
                        <p style="margin:0; color:#231F20;"><strong>Address:</strong> <span id="address-val-<?php echo $sub->id; ?>"><?php echo esc_html($address); ?></span></p>
                    </div>

                    <?php if (current_user_can('manage_options')): ?>
                    <div id="account-edit-<?php echo $sub->id; ?>" style="display:none; margin-top:10px;">
                        <div style="display:flex; gap:10px; margin-bottom:8px;">
                            <div style="flex:1;">
                                <label style="font-size:0.85em; font-weight:bold; color:#334155;">First Name:</label>
                                <input type="text" id="edit-fname-<?php echo $sub->id; ?>" value="<?php echo esc_attr($fname); ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                            </div>
                            <div style="flex:1;">
                                <label style="font-size:0.85em; font-weight:bold; color:#334155;">Last Name:</label>
                                <input type="text" id="edit-lname-<?php echo $sub->id; ?>" value="<?php echo esc_attr($lname); ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                            </div>
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:0.85em; font-weight:bold; color:#334155;">Recipient Name (Optional):</label>
                            <input type="text" id="edit-recipient-<?php echo $sub->id; ?>" value="<?php echo esc_attr($recipient_name); ?>" placeholder="e.g. Sarah" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="font-size:0.85em; font-weight:bold; color:#334155;">Full Address:</label>
                            <input type="text" id="edit-address-<?php echo $sub->id; ?>" value="<?php echo esc_attr($address !== 'Address not provided' ? $address : ''); ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                        </div>
                        <button class="save-account-btn" data-sub-id="<?php echo $sub->id; ?>" data-user-id="<?php echo $sub->user_id; ?>" data-order-id="<?php echo $sub->wc_order_id; ?>" style="background:#379237; color:#fff; border:none; padding:6px 12px; border-radius:4px; font-size:0.85em; font-weight:bold; cursor:pointer; width:100%; transition:background 0.2s;">Save Account Details</button>
                    </div>
                    <?php endif; ?>

                </div>

                <div style="flex:1; min-width:250px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0; position:relative;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h3 style="margin:0; color:#1B5E20; font-size:1.1em;">Active Logistics</h3>
                        <?php if ($is_admin_override): ?>
                            <button class="toggle-logistics-edit" data-sub-id="<?php echo $sub->id; ?>" style="background:none; border:none; color:#379237; cursor:pointer; font-size:0.85em; font-weight:bold; text-decoration:underline; padding:0;">Edit</button>
                        <?php endif; ?>
                    </div>

                    <div id="logistics-view-<?php echo $sub->id; ?>">
                        <p style="margin:0 0 8px 0; color:#475569;"><strong>Method:</strong> <?php echo esc_html($method_display); ?></p>
                        <p style="margin:0 0 8px 0; color:#475569;"><strong>Receive By:</strong> <span id="timing-val-<?php echo $sub->id; ?>"><?php echo esc_html($timing_display); ?></span></p>
                        <p style="margin:0 0 8px 0; color:#475569;"><strong>Time Slot:</strong> <span id="time-slot-val-<?php echo $sub->id; ?>"><?php echo esc_html($time_slot_display); ?></span></p>
                        <div style="margin-top:15px; padding-top:15px; border-top:1px dashed #cbd5e1;">
                            <p style="margin:0; color:#b45309;"><strong>Allergies:</strong> <span id="allergies-val-<?php echo $sub->id; ?>"><?php echo !empty($allergies) ? esc_html($allergies) : 'No Allergies Recorded'; ?></span></p>
                        </div>
                    </div>

                    <?php if ($is_admin_override): ?>
                    <div id="logistics-edit-<?php echo $sub->id; ?>" style="display:none; margin-top:10px;">
                        <div style="margin-bottom:8px;">
                            <label style="font-size:0.85em; font-weight:bold; color:#334155;">Receive By:</label>
                            <select id="edit-timing-<?php echo $sub->id; ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                                <option value="Deliver Day Before" <?php selected($timing, 'Deliver Day Before'); ?>>Deliver Day Before</option>
                                <option value="Deliver Same Day" <?php selected($timing, 'Deliver Same Day'); ?>>Deliver Same Day</option>
                            </select>
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:0.85em; font-weight:bold; color:#334155;">Time Slot:</label>
                            <select id="edit-timeslot-<?php echo $sub->id; ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                                <option value="5:00 AM to 6:00 AM" <?php selected($time_slot, '5:00 AM to 6:00 AM'); ?>>5:00 AM to 6:00 AM</option>
                                <option value="6:00 AM to 7:00 AM" <?php selected($time_slot, '6:00 AM to 7:00 AM'); ?>>6:00 AM to 7:00 AM</option>
                                <option value="7:00 AM to 8:00 AM" <?php selected($time_slot, '7:00 AM to 8:00 AM'); ?>>7:00 AM to 8:00 AM</option>
                                <option value="8:00 AM to 9:00 AM" <?php selected($time_slot, '8:00 AM to 9:00 AM'); ?>>8:00 AM to 9:00 AM</option>
                                <option value="5:00 PM to 8:00 PM" <?php selected($time_slot, '5:00 PM to 8:00 PM'); ?>>5:00 PM to 8:00 PM</option>
                            </select>
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="font-size:0.85em; font-weight:bold; color:#334155;">Allergies:</label>
                            <textarea id="edit-allergies-<?php echo $sub->id; ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;"><?php echo esc_textarea($allergies); ?></textarea>
                        </div>
                        <button class="save-logistics-btn" data-sub-id="<?php echo $sub->id; ?>" data-user-id="<?php echo $sub->user_id; ?>" data-order-id="<?php echo $sub->wc_order_id; ?>" style="background:#379237; color:#fff; border:none; padding:6px 12px; border-radius:4px; font-size:0.85em; font-weight:bold; cursor:pointer; width:100%; transition:background 0.2s;">Save Changes</button>
                    </div>
                    <?php endif; ?>

                </div>

                <div style="width: 100%; border-top: 1px solid #eee; margin-top: 10px; padding-top: 20px;">
                    <p style="color: <?php echo $note_color; ?>; font-size: 0.9em; font-weight: bold; margin: 0; text-align:center;"><em><?php echo $note_text; ?></em></p>
                </div>
            </div>

            <?php if ($is_paused): ?>
            <div class="cmp-no-print" style="background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:15px; margin-bottom:20px; text-align:center;">
                <strong style="color:#c2410c; font-size:1.1em;">⏸ This Meal Plan is Currently Paused</strong>
                <p style="color:#9a3412; margin:5px 0 0 0; font-size:0.9em;">Your deliveries have been temporarily halted. Please contact support to resume your plan.</p>
            </div>
            <?php endif; ?>

            <!-- PHASE 6: ADMIN PLAN MANAGER CONTROLS -->
            <?php if (current_user_can('manage_options') || current_user_can('menu_manager')): ?>
            <div class="cmp-no-print" style="background:#fffbdd; border:1px solid #eab308; border-radius:8px; padding:15px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <strong style="color:#b45309; font-size:1.1em;">Admin Plan Manager</strong><br>
                    <span style="color:#92400e; font-size:0.9em;">Current Quota: <strong><?php echo $sub->total_days; ?> Days</strong></span>
                </div>
                <div style="display:flex; gap:10px;">
                    <button class="admin-adjust-days-btn" data-sub-id="<?php echo $sub->id; ?>" data-operation="add" style="background:#379237; color:#fff; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; transition:0.2s;">+ Add Extra Day</button>
                    <button class="admin-adjust-days-btn" data-sub-id="<?php echo $sub->id; ?>" data-operation="remove" style="background:#dc2626; color:#fff; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; transition:0.2s;">- Remove Last Day</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="cmp-table-responsive">
                <table class="cmp-table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Date</th>
                            <th><?php echo esc_html($label_chefs_choice); ?></th>
                            <?php if(!$is_juice): 
                                $h1 = $is_flexible ? 'Meal 1' : 'Breakfast';
                                $h2 = $is_flexible ? 'Meal 2' : 'Lunch';
                                $h3 = $is_flexible ? 'Meal 3' : 'Dinner';
                            ?>
                                <th><?php echo $h1; ?></th>
                                <th><?php echo $h2; ?></th>
                                <th><?php echo $h3; ?></th>
                                <?php if($snack_quota > 0) echo '<th>Snacks</th>'; ?>
                                <th>Macros (Daily)</th>
                            <?php else: ?>
                                <th>Juice 1</th>
                                <th>Juice 2</th>
                                <th>Juice 3</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $saved_logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_logs WHERE subscription_id = %d ORDER BY target_date ASC, id ASC", $sub->id));
                        
                        $void_count = 0;
                        foreach ($saved_logs as $log) {
                            if (in_array($log->delivery_result, array('Cancelled', 'Returned'))) $void_count++;
                        }
                        $display_days = intval($sub->total_days) + $void_count;
                        $active_min_date = $global_min_date;

                        for ($i = 1; $i <= $display_days; $i++) {
                            $log = isset($saved_logs[$i-1]) ? $saved_logs[$i-1] : null;
                            $log_id = $log ? $log->id : 0;
                            $is_void = ($log && in_array($log->delivery_result, array('Cancelled', 'Returned')));
                            $saved_chefs_choice = ($log && $log->is_chefs_choice) ? true : false;

                            // --- PHASE 7 LOGIC: Evaluate Plan Mode at the Log Level ---
                            $log_mode = ($log && !empty($log->plan_mode)) ? $log->plan_mode : null;
                            if ($log_mode) {
                                $row_is_flexible  = ($log_mode === 'flexible') ? 1 : 0;
                                $row_allow_custom = ($log_mode === 'custom') ? 1 : 0;
                            } else {
                                $row_is_flexible  = $is_flexible;
                                $row_allow_custom = $allow_custom;
                            }
                            
                            $is_chef_assigned = ($log && ($log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1));
                            
                            $is_time_locked = false;
                            if ($log && !empty($log->target_date)) {
                                if ($log->target_date < $global_min_date) {
                                    $is_time_locked = true;
                                }
                            }

                            $base_input_disabled = ($is_void || (!$is_admin_override && !$is_chef_override && ($is_time_locked || $is_paused))) ? 'disabled' : '';
                            
                            if ($is_chef_override) {
                                $chefs_choice_checkbox_disabled = 'disabled';
                                $meal_select_disabled = $saved_chefs_choice ? '' : 'disabled';
                                $date_picker_disabled = 'disabled';
                            } else {
                                $chefs_choice_checkbox_disabled = $base_input_disabled;
                                $meal_select_disabled = ($base_input_disabled || $saved_chefs_choice) ? 'disabled' : '';
                                $date_picker_disabled = $base_input_disabled;
                            }

                            $row_status_class = 'status-pending';
                            if ($is_void) { $row_status_class = 'status-void row-void'; } 
                            elseif ($log_id > 0) { $row_status_class = 'status-saved'; }
                            
                            if ($log && !$is_void) {
                                $next_day_calc = date('Y-m-d', strtotime('+1 day', strtotime($log->target_date)));
                                if (empty($active_min_date) || $next_day_calc > $active_min_date) {
                                    if (!$is_admin_override && !$is_chef_override) {
                                        $active_min_date = $next_day_calc;
                                    }
                                }
                            }

                            $status_badge = '<span style="color:#666; font-size:0.9em; background:#f1f5f9; padding:4px 8px; border-radius:4px; font-weight:bold; white-space:nowrap;">Pending</span>';
                            
                            if ($log && $log->delivery_result === 'Successful') {
                                $status_badge = '<span style="color:#166534; font-size:0.9em; background:#dcfce7; padding:4px 8px; border-radius:4px; font-weight:bold; white-space:nowrap;">Successful</span>';
                            } elseif ($is_void && $log) {
                                $status_badge = '<span style="color:#9f1239; font-size:0.9em; background:#ffe4e6; padding:4px 8px; border-radius:4px; font-weight:bold; white-space:nowrap;">' . esc_html($log->delivery_result) . '</span>';
                            } elseif ($is_paused) {
                                $status_badge = '<span style="color:#b45309; font-size:0.9em; background:#fef3c7; padding:4px 8px; border-radius:4px; font-weight:bold; white-space:nowrap;">Paused</span>';
                            } elseif ($log_id > 0) {
                                $status_badge = '<span style="color:#1B5E20; font-size:0.9em; background:#EEF7E3; border:1px solid #DCECC9; padding:4px 8px; border-radius:4px; font-weight:bold; white-space:nowrap;">Confirmed</span>';
                            }

                            $macros_desk = '<strong>Cal:</strong> 0<br><strong>Fat:</strong> 0g<br><strong>Carb:</strong> 0g<br><strong>Pro:</strong> 0g';
                            $macros_mob = '<div class="mob-grid"><div><strong>Cal:</strong> 0</div><div><strong>Fat:</strong> 0g</div><div><strong>Carb:</strong> 0g</div><div><strong>Pro:</strong> 0g</div></div>';
                            
                            $min_attr = (!empty($active_min_date) && !$is_admin_override && !$is_chef_override) ? 'min="' . esc_attr($active_min_date) . '"' : '';

                        ?>
                            <tr class="cmp-day-row <?php echo $row_status_class; ?>" data-is-flexible="<?php echo $row_is_flexible; ?>" data-allow-custom="<?php echo $row_allow_custom; ?>">
                                <td>
                                    <strong>Day <?php echo $i; ?></strong>
                                    <span class="cmp-mobile-status"><?php echo $status_badge; ?></span>
                                </td>
                                <td>
                                    <input type="date" class="cmp-date-picker" data-row="<?php echo $i; ?>" <?php echo $min_attr; ?> value="<?php echo $log ? esc_attr($log->target_date) : ''; ?>" <?php echo $date_picker_disabled; ?>>
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox" class="cmp-chefs-choice" data-row="<?php echo $i; ?>" <?php echo $chefs_choice_checkbox_disabled; ?> <?php if($saved_chefs_choice) echo 'checked'; ?>>
                                </td>
                                
                                <?php if(!$is_juice): 
                                    $main_cats = [
                                        'Breakfast' => ['slot' => 'breakfast', 'label' => $row_is_flexible ? 'Meal 1' : 'Breakfast'],
                                        'Lunch'     => ['slot' => 'lunch',     'label' => $row_is_flexible ? 'Meal 2' : 'Lunch'],
                                        'Dinner'    => ['slot' => 'dinner',    'label' => $row_is_flexible ? 'Meal 3' : 'Dinner']
                                    ];
                                ?>
                                    <?php foreach($main_cats as $cat => $data): ?>
                                        <td>
                                            <?php if($row_allow_custom): ?>
                                                <input type="text" class="cmp-meal-custom cmp-main-meal" data-slot="<?php echo $data['slot']; ?>" data-row="<?php echo $i; ?>" value="<?php echo esc_attr($log ? $log->{'custom_'.$data['slot']} : ''); ?>" placeholder="Type <?php echo esc_attr($data['label']); ?>..." <?php echo $meal_select_disabled; ?> style="width:100%; padding:6px; box-sizing: border-box; border:1px solid #cbd5e1; border-radius:4px;">
                                            <?php else: ?>
                                                <select class="cmp-meal-select cmp-main-meal" data-cat="<?php echo $row_is_flexible ? 'Any' : $cat; ?>" data-slot="<?php echo $data['slot']; ?>" data-row="<?php echo $i; ?>" data-saved-val="<?php echo $log ? $log->{$data['slot'].'_id'} : ''; ?>" <?php echo $meal_select_disabled; ?>>
                                                    <option value="">- Select <?php echo esc_html($data['label']); ?> -</option>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    
                                    <?php if($snack_quota > 0): ?>
                                    <td>
                                        <?php if($row_allow_custom): ?>
                                            <input type="text" class="cmp-meal-custom cmp-snack-1 <?php echo ($snack_quota == 2) ? 'cmp-stacked-snack' : ''; ?>" data-slot="snack_1" data-row="<?php echo $i; ?>" value="<?php echo esc_attr($log ? $log->custom_snack_1 : ''); ?>" placeholder="Type Snack 1..." <?php echo $meal_select_disabled; ?> style="width:100%; padding:6px; box-sizing: border-box; border:1px solid #cbd5e1; border-radius:4px;">
                                            <?php if($snack_quota == 2): ?>
                                                <input type="text" class="cmp-meal-custom cmp-snack-2" data-slot="snack_2" data-row="<?php echo $i; ?>" value="<?php echo esc_attr($log ? $log->custom_snack_2 : ''); ?>" placeholder="Type Snack 2..." <?php echo $meal_select_disabled; ?> style="width:100%; padding:6px; box-sizing: border-box; border:1px solid #cbd5e1; border-radius:4px; margin-top:4px;">
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <select class="cmp-meal-select cmp-snack-1 <?php echo ($snack_quota == 2) ? 'cmp-stacked-snack' : ''; ?>" data-cat="Snacks" data-slot="snack_1" data-row="<?php echo $i; ?>" data-saved-val="<?php echo $log ? $log->snack_1_id : ''; ?>" <?php echo $meal_select_disabled; ?>>
                                                <option value="">- Select Snack 1 -</option>
                                            </select>
                                            <?php if($snack_quota == 2): ?>
                                            <select class="cmp-meal-select cmp-snack-2" data-cat="Snacks" data-slot="snack_2" data-row="<?php echo $i; ?>" data-saved-val="<?php echo $log ? $log->snack_2_id : ''; ?>" <?php echo $meal_select_disabled; ?>>
                                                <option value="">- Select Snack 2 -</option>
                                            </select>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    
                                    <td>
                                        <span class="cmp-macro-display" id="macros-row-<?php echo $i; ?>">
                                            <span class="macro-desktop"><?php echo $macros_desk; ?></span>
                                            <span class="macro-mobile"><?php echo $macros_mob; ?></span>
                                        </span>
                                    </td>

                                <?php else: ?>
                                    <?php for($j=1; $j<=3; $j++): ?>
                                        <td>
                                            <?php if($row_allow_custom): ?>
                                                <input type="text" class="cmp-meal-custom cmp-juice-<?php echo $j; ?>" data-slot="juice_<?php echo $j; ?>" data-row="<?php echo $i; ?>" value="<?php echo esc_attr($log ? $log->{'custom_juice_'.$j} : ''); ?>" placeholder="Type Juice <?php echo $j; ?>..." <?php echo $meal_select_disabled; ?> style="width:100%; padding:6px; box-sizing: border-box; border:1px solid #cbd5e1; border-radius:4px;">
                                            <?php else: ?>
                                                <select class="cmp-meal-select cmp-juice-<?php echo $j; ?>" data-cat="Juices" data-slot="juice_<?php echo $j; ?>" data-row="<?php echo $i; ?>" data-saved-val="<?php echo $log ? $log->{'juice_'.$j.'_id'} : ''; ?>" <?php echo $meal_select_disabled; ?> style="width:100%; padding:6px; box-sizing: border-box;">
                                                    <option value="">- Select Juice <?php echo $j; ?> -</option>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                    <?php endfor; ?>
                                <?php endif; ?>
                                
                                <td style="text-align:center;">
                                    <?php echo $status_badge; ?>
                                </td>

                                <td style="text-align:center;">
                                    <?php 
                                    if ($is_chef_override) {
                                        if ($saved_chefs_choice) {
                                            $chef_btn_text = ($is_chef_assigned) ? 'Update' : 'Save';
                                            $chef_btn_color = ($is_chef_assigned) ? '#1B5E20' : '#379237';
                                            echo '<button class="cmp-save-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" data-row="'.$i.'" style="background:'.$chef_btn_color.'; color:#fff; border:none; font-weight:bold; cursor:pointer; width:100%; margin-bottom:5px;">'.$chef_btn_text.'</button>';
                                            if ($log_id > 0) {
                                                echo '<button class="cmp-clear-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" style="background:#f59e0b; color:#fff; border:none; font-weight:bold; cursor:pointer; width:100%; padding:6px; border-radius:4px; display:flex; justify-content:center; align-items:center;" title="Reset / Clear Selection">'.$icon_reset.'</button>';
                                            }
                                        } else {
                                            echo '<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center;">Locked<br>(Customer Pick)</span>';
                                        }
                                    } else {
                                        if (!$is_time_locked && !$is_void && !$is_paused) {
                                            $btn_txt = ($log_id > 0) ? 'Update' : 'Save';
                                            $btn_color = ($log_id > 0) ? '#1B5E20' : '#379237';
                                            echo '<button class="cmp-save-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" data-row="'.$i.'" style="background:'.$btn_color.'; color:#fff; border:none; font-weight:bold; cursor:pointer; width:100%; margin-bottom:5px;">'.$btn_txt.'</button>';
                                            
                                            if ($log_id > 0) {
                                                echo '<div style="display:flex; gap:5px; margin-top:5px;">';
                                                echo '<button class="cmp-clear-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" style="background:#f59e0b; color:#fff; border:none; font-weight:bold; cursor:pointer; flex:1; padding:6px; border-radius:4px; display:flex; justify-content:center; align-items:center;" title="Reset Selection">'.$icon_reset.'</button>';
                                                echo '<button class="cmp-cancel-day-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" style="background:#dc2626; color:#fff; border:none; font-weight:bold; cursor:pointer; flex:1; padding:6px; border-radius:4px; display:flex; justify-content:center; align-items:center;" title="Cancel Day">'.$icon_cancel.'</button>';
                                                echo '</div>';
                                            }
                                        } else {
                                            if ($is_admin_override) {
                                                echo '<button class="cmp-save-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" data-row="'.$i.'" style="background:#379237; color:#fff; border:none; font-weight:bold; cursor:pointer; width:100%; margin-bottom:5px;">Update</button>';
                                                
                                                if ($log_id > 0) {
                                                    echo '<div style="display:flex; gap:5px; margin-top:5px;">';
                                                    echo '<button class="cmp-clear-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" style="background:#f59e0b; color:#fff; border:none; font-weight:bold; cursor:pointer; flex:1; padding:6px; border-radius:4px; display:flex; justify-content:center; align-items:center;" title="Reset Selection">'.$icon_reset.'</button>';
                                                    echo '<button class="cmp-cancel-day-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" style="background:#dc2626; color:#fff; border:none; font-weight:bold; cursor:pointer; flex:1; padding:6px; border-radius:4px; display:flex; justify-content:center; align-items:center;" title="Cancel Day">'.$icon_cancel.'</button>';
                                                    echo '</div>';
                                                }
                                            } else {
                                                $lock_reason = $is_paused ? 'Paused' : 'Cutoff Passed';
                                                $lock_reason = $is_void ? 'Void' : $lock_reason;
                                                echo '<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center;">Locked<br>('.$lock_reason.')</span>';
                                            }
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (!empty($clean_wa) && !$is_chef_override): ?>
        <a href="https://wa.me/<?php echo esc_attr($clean_wa); ?>?text=Hi,%20I%20need%20help%20with%20my%20meal%20plan." target="_blank" class="cmp-wa-float">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
            <span class="cmp-wa-text">Support</span>
        </a>
        <?php endif; ?>

    </div>

    <script type="text/javascript">
    function switchTab(evt, tabId) {
        var contents = document.getElementsByClassName("cmp-tab-content");
        for (var i = 0; i < contents.length; i++) contents[i].classList.remove("active");
        var btns = document.getElementsByClassName("cmp-tab-btn");
        for (var i = 0; i < btns.length; i++) btns[i].classList.remove("active");
        document.getElementById(tabId).classList.add("active");
        evt.currentTarget.classList.add("active");

        var subId = tabId.replace('plan-tab-', '');
        var exportBtn = document.getElementById('cmp-global-export-btn');
        if (exportBtn) {
            exportBtn.href = "<?php echo admin_url('admin-ajax.php?action=cmp_export_customer_csv&sub_id='); ?>" + subId;
        }
    }

    jQuery(document).ready(function($) {
        var isAdminOverride = <?php echo $is_admin_override ? 'true' : 'false'; ?>;
        var isChefOverride  = <?php echo $is_chef_override ? 'true' : 'false'; ?>;
        var isAdminOrChef = (isAdminOverride || isChefOverride);
        var chefsChoiceLabel = "<?php echo esc_js($label_chefs_choice); ?>";
        var blackoutDates = <?php echo json_encode($blackout_dates_array); ?>;

        // FOH INLINE LOGISTICS EDIT LOGIC
        $('.toggle-logistics-edit').on('click', function(e) {
            e.preventDefault();
            var subId = $(this).data('sub-id');
            var viewDiv = $('#logistics-view-' + subId);
            var editDiv = $('#logistics-edit-' + subId);

            if (editDiv.is(':visible')) {
                editDiv.hide();
                viewDiv.show();
                $(this).text('Edit');
            } else {
                viewDiv.hide();
                editDiv.show();
                $(this).text('Cancel');
            }
        });

        $('.save-logistics-btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var subId = btn.data('sub-id');
            var userId = btn.data('user-id');
            var orderId = btn.data('order-id');
            var timing = $('#edit-timing-' + subId).val();
            var timeSlot = $('#edit-timeslot-' + subId).val();
            var allergies = $('#edit-allergies-' + subId).val();
            var origText = btn.text();

            btn.text('Saving...').prop('disabled', true);

            var data = {
                action: 'cmp_cp_update_customer_logistics',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                user_id: userId,
                order_id: orderId,
                delivery_timing: timing,
                time_slot: timeSlot,
                allergies: allergies
            };

            $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(res) {
                if (res.success) {
                    $('#timing-val-' + subId).text(res.data.timing);
                    $('#time-slot-val-' + subId).text(res.data.time_slot);
                    $('#allergies-val-' + subId).text(res.data.allergies);

                    btn.text('✓ Saved').css('background', '#1B5E20');

                    setTimeout(function() {
                        $('#logistics-edit-' + subId).hide();
                        $('#logistics-view-' + subId).show();
                        $('.toggle-logistics-edit[data-sub-id="' + subId + '"]').text('Edit');
                        btn.text(origText).css('background', '#379237').prop('disabled', false);
                    }, 1000);
                } else {
                    alert('Error: ' + res.data);
                    btn.text(origText).prop('disabled', false);
                }
            });
        });

        // ADMIN ACCOUNT DETAILS EDIT LOGIC
        $('.toggle-account-edit').on('click', function(e) {
            e.preventDefault();
            var subId = $(this).data('sub-id');
            var viewDiv = $('#account-view-' + subId);
            var editDiv = $('#account-edit-' + subId);

            if (editDiv.is(':visible')) {
                editDiv.hide();
                viewDiv.show();
                $(this).text('Edit');
            } else {
                viewDiv.hide();
                editDiv.show();
                $(this).text('Cancel');
            }
        });

        $('.save-account-btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var subId = btn.data('sub-id');
            var userId = btn.data('user-id');
            var orderId = btn.data('order-id');
            var fName = $('#edit-fname-' + subId).val();
            var lName = $('#edit-lname-' + subId).val();
            var recipient = $('#edit-recipient-' + subId).val();
            var addr = $('#edit-address-' + subId).val();
            var origText = btn.text();

            if (!fName || !addr) {
                alert("First Name and Address are required.");
                return;
            }

            btn.text('Saving...').prop('disabled', true);

            var data = {
                action: 'cmp_cp_admin_update_account_details',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                user_id: userId,
                order_id: orderId,
                sub_id: subId,
                first_name: fName,
                last_name: lName,
                recipient_name: recipient,
                address: addr
            };

            $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(res) {
                if (res.success) {
                    $('#name-val-' + subId).text(res.data.name);
                    $('#address-val-' + subId).text(res.data.address);

                    if (res.data.recipient) {
                        $('#recipient-val-' + subId).text(res.data.recipient);
                        $('#recipient-row-' + subId).show();
                    } else {
                        $('#recipient-row-' + subId).hide();
                    }

                    if (res.data.plan_name) {
                        $('#tab-title-' + subId).text(res.data.plan_name);
                    }

                    btn.text('✓ Saved').css('background', '#1B5E20');

                    setTimeout(function() {
                        $('#account-edit-' + subId).hide();
                        $('#account-view-' + subId).show();
                        $('.toggle-account-edit[data-sub-id="' + subId + '"]').text('Edit');
                        btn.text(origText).css('background', '#379237').prop('disabled', false);
                    }, 1000);
                } else {
                    alert('Error: ' + res.data);
                    btn.text(origText).prop('disabled', false);
                }
            });
        });

        // ADMIN PLAN MANAGER: ADD / REMOVE DAYS LOGIC
        $('.admin-adjust-days-btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var subId = btn.data('sub-id');
            var op = btn.data('operation');
            
            var confirmMsg = op === 'add' ? "Are you sure you want to ADD an extra day to this plan?" : "Are you sure you want to REMOVE a day from this plan?";
            if(!confirm(confirmMsg)) return;

            var origText = btn.text();
            btn.text('Updating...').prop('disabled', true);

            $.post('<?php echo admin_url("admin-ajax.php"); ?>', {
                action: 'cmp_cp_admin_adjust_days',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                sub_id: subId,
                operation: op
            }, function(res) {
                if(res.success) {
                    location.reload();
                } else {
                    alert('Error: ' + res.data);
                    btn.text(origText).prop('disabled', false);
                }
            }).fail(function() {
                alert('Connection error.');
                btn.text(origText).prop('disabled', false);
            });
        });

        function populateDynamicDropdowns(rowElement) {
            var isCustom = rowElement.data('allow-custom') == 1;
            
            var dateVal = rowElement.find('.cmp-date-picker').val();
            var selects = rowElement.find('select.cmp-meal-select, select[class^="cmp-juice-"]');
            var inputs = rowElement.find('input.cmp-meal-custom');
            
            var chefChoiceCell = rowElement.find('td:nth-child(3)');
            var macroCell = rowElement.find('.cmp-macro-display').closest('td');
            var btn = rowElement.find('.cmp-save-row');

            rowElement.find('.boundary-overlay').remove();
            rowElement.find('.date-prompt-overlay').remove();

            var selectContainer = rowElement.find('td').filter(function() {
                return $(this).find('select, input.cmp-meal-custom').length > 0;
            });

            if (!dateVal) {
                selects.hide();
                inputs.hide();
                chefChoiceCell.find('input, .cmp-mobile-label').hide();
                macroCell.css('opacity', '0'); 

                selectContainer.append('<div class="date-prompt-overlay" style="color:#94a3b8; font-size:0.85em; font-style:italic; text-align:center; padding:10px 0;">&larr; Please pick a date first to view options.</div>');
                return false;
            }

            macroCell.css('opacity', '1');
            
            if (isCustom) {
                inputs.show();
                chefChoiceCell.find('input, .cmp-mobile-label').show();
                if (btn.text() === 'Menu Pending') {
                    var btnTxt = isAdminOverride ? 'Update' : 'Save';
                    btn.text(btnTxt).css({'background':'#379237', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
                }
                return true;
            }

            var availableFoods = cmpGlobalFoodData.filter(function(food) {
                if (!food.valid_from || !food.valid_until) return true; 
                return (dateVal >= food.valid_from && dateVal <= food.valid_until);
            });

            if (availableFoods.length === 0) {
                selects.hide();
                chefChoiceCell.find('input, .cmp-mobile-label').show(); 

                selectContainer.append('<div class="boundary-overlay" style="color:#b45309; font-weight:bold; font-size:0.9em; background:#fef3c7; padding:10px; border-radius:4px; text-align:center; line-height:1.4;">Menu Pending Release</div>');

                if (isChefOverride) {
                    btn.text('Menu Pending').css({'background':'#94a3b8', 'color':'#fff', 'cursor':'not-allowed'}).prop('disabled', true);
                } else {
                    if (btn.text() === 'Menu Pending') {
                        var btnTxt = isAdminOverride ? 'Update' : 'Save';
                        btn.text(btnTxt).css({'background':'#379237', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
                    }
                }
                return false;
            }

            selects.show();
            chefChoiceCell.find('input, .cmp-mobile-label').show();

            if (btn.text() === 'Menu Pending') {
                var btnTxt = isAdminOverride ? 'Update' : 'Save';
                btn.text(btnTxt).css({'background':'#379237', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
            }

            selects.each(function() {
                var select = $(this);
                var category = select.data('cat');
                var savedVal = select.data('saved-val');
                var placeholder = select.find('option').first().text();

                select.empty().append('<option value="">' + placeholder + '</option>');

                if (category === 'Any') {
                    var optBreakfast = $('<optgroup label="— Breakfast —"></optgroup>');
                    var optLunch = $('<optgroup label="— Lunch —"></optgroup>');
                    var optDinner = $('<optgroup label="— Dinner —"></optgroup>');

                    availableFoods.forEach(function(food) {
                        if (food.category_name === 'Breakfast' || food.category_name === 'Lunch' || food.category_name === 'Dinner') {
                            var isSelected = (savedVal == food.id) ? 'selected' : '';
                            var optionHtml = '<option value="'+food.id+'" data-cal="'+food.calories+'" data-fat="'+food.total_fat+'" data-carbs="'+food.carbohydrates+'" data-pro="'+food.protein+'" '+isSelected+'>'+food.food_name+'</option>';
                            
                            if (food.category_name === 'Breakfast') optBreakfast.append(optionHtml);
                            if (food.category_name === 'Lunch') optLunch.append(optionHtml);
                            if (food.category_name === 'Dinner') optDinner.append(optionHtml);
                        }
                    });

                    if (optBreakfast.children().length > 0) select.append(optBreakfast);
                    if (optLunch.children().length > 0) select.append(optLunch);
                    if (optDinner.children().length > 0) select.append(optDinner);

                } else {
                    availableFoods.forEach(function(food) {
                        if (food.category_name === category) {
                            var isSelected = (savedVal == food.id) ? 'selected' : '';
                            select.append('<option value="'+food.id+'" data-cal="'+food.calories+'" data-fat="'+food.total_fat+'" data-carbs="'+food.carbohydrates+'" data-pro="'+food.protein+'" '+isSelected+'>'+food.food_name+'</option>');
                        }
                    });
                }
            });

            return true;
        }

        $('.cmp-day-row td:first-child').on('click', function() {
            if ($(window).width() <= 768) {
                $(this).parent('tr').toggleClass('is-open');
            }
        });

        setTimeout(function() {
            if ($(window).width() <= 768) {
                var $firstPending = $('.cmp-tab-content.active .status-pending').first();
                if ($firstPending.length) {
                    $firstPending.addClass('is-open');
                    $('html, body').animate({ scrollTop: $firstPending.offset().top - 80 }, 800);
                }
            }
        }, 300);

        $('.cmp-table').each(function() {
            var $table = $(this);
            var headers = [];
            $table.find('thead th').each(function() { headers.push($(this).text().trim()); });
            $table.find('tbody tr').each(function() {
                $(this).find('td').each(function(index) {
                    if (index !== 0 && headers[index] && $(this).find('.cmp-mobile-label').length === 0) {
                        $(this).prepend('<span class="cmp-mobile-label">' + headers[index] + '</span>');
                    }
                });
            });
        });

        function calculateMacros(rowElement) {
            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
            var macroDisplay = rowElement.find('.cmp-macro-display');
            var isCustom = rowElement.data('allow-custom') == 1;
            
            var selects = rowElement.find('select');
            var inputs = rowElement.find('input.cmp-meal-custom');

            var hasValues = false;
            selects.each(function() { if ($(this).val() !== "") hasValues = true; });
            inputs.each(function() { if ($(this).val().trim() !== "") hasValues = true; });

            if (isChefsChoice && !hasValues) {
                macroDisplay.html('<span class="macro-desktop"><strong>' + chefsChoiceLabel + '</strong><br><span style="color:#d63638; font-size:0.9em;">(Pending)</span></span><span class="macro-mobile"><strong>' + chefsChoiceLabel + '</strong> <span style="color:#d63638;">(Pending)</span></span>');
                return;
            }

            var cal=0, fat=0, carbs=0, pro=0;
            selects.each(function() {
                var selected = $(this).find('option:selected');
                if (selected.length > 0 && selected.val() !== "") {
                    cal += parseFloat(selected.data('cal')) || 0;
                    fat += parseFloat(selected.data('fat')) || 0;
                    carbs += parseFloat(selected.data('carbs')) || 0;
                    pro += parseFloat(selected.data('pro')) || 0;
                }
            });

            var deskHtml = '<strong>Cal:</strong> ' + cal + '<br><strong>Fat:</strong> ' + fat + 'g<br><strong>Carb:</strong> ' + carbs + 'g<br><strong>Pro:</strong> ' + pro + 'g';
            var mobHtml = '<div class="mob-grid"><div><strong>Cal:</strong> '+cal+'</div><div><strong>Fat:</strong> '+fat+'g</div><div><strong>Carb:</strong> '+carbs+'g</div><div><strong>Pro:</strong> '+pro+'g</div></div>';

            if (isCustom) {
                 deskHtml = '<strong style="color:#1B5E20;">Custom Macros N/A</strong>';
                 mobHtml = '<div style="color:#1B5E20; font-weight:bold;">Custom Macros N/A</div>';
            }

            if (isChefsChoice && hasValues) {
                var badge = '<div style="color:#379237; font-size:0.85em; font-weight:bold; margin-bottom:4px;">(Chef Assigned)</div>';
                macroDisplay.html('<span class="macro-desktop">' + badge + deskHtml + '</span><span class="macro-mobile">' + badge + mobHtml + '</span>');
            } else {
                macroDisplay.html('<span class="macro-desktop">' + deskHtml + '</span><span class="macro-mobile">' + mobHtml + '</span>');
            }
        }

        function enforceMealQuota(rowElement) {
            var container = rowElement.closest('.cmp-portal-container');
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var isJuice = container.data('is-juice') === 1;

            if (isJuice || allowedMeals <= 0) return; 

            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
            if (isChefsChoice && !isChefOverride) return; 

            var mainSelects = rowElement.find('.cmp-main-meal');
            if (mainSelects.first().is(':disabled') && !mainSelects.first().hasClass('quota-locked') && !isAdminOverride && !isChefOverride) {
                return;
            }

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

        $('.cmp-day-row').each(function() {
            var hasFood = populateDynamicDropdowns($(this));
            if (hasFood) {
                calculateMacros($(this));
                enforceMealQuota($(this));
            }
        });

        $(document).on('change keyup', '.cmp-meal-select, .cmp-meal-custom, select[class^="cmp-juice-"]', function() { 
            calculateMacros($(this).closest('tr')); 
            enforceMealQuota($(this).closest('tr'));
        });

        $('.cmp-chefs-choice').on('change', function() {
            var rowElement = $(this).closest('tr');
            var inputs = rowElement.find('select, input.cmp-meal-custom');
            if ($(this).is(':checked')) { 
                inputs.prop('disabled', true).val(''); 
                inputs.removeClass('quota-locked');
            } else { 
                inputs.prop('disabled', false); 
            }
            calculateMacros(rowElement); 
            enforceMealQuota(rowElement);
        });

        $('.cmp-date-picker').on('change', function() {
            var selectedDate = $(this).val();
            var rowInput = $(this);
            var container = rowInput.closest('.cmp-portal-container');
            var rowNumber = parseInt(rowInput.data('row'));
            var totalDays = parseInt(container.data('total-days'));

            if(selectedDate) {
                if(blackoutDates.includes(selectedDate)) {
                    alert('The kitchen is closed on this date. Please select a different day.');
                    rowInput.val('');
                    return;
                }
                var nextDate = new Date(selectedDate);
                nextDate.setDate(nextDate.getDate() + 1);
                var nextDateString = nextDate.toISOString().split('T')[0];

                var globalFloor = "<?php echo esc_js($global_min_date); ?>";
                if (!isAdminOrChef && globalFloor && nextDateString < globalFloor) {
                    nextDateString = globalFloor;
                }

                for(var i = rowNumber + 1; i <= (totalDays + 10); i++) { 
                    var targetInput = container.find('.cmp-date-picker[data-row="' + i + '"]');
                    if (targetInput.length) {
                        if (!isAdminOrChef) {
                            targetInput.attr('min', nextDateString);
                            if (targetInput.val() && targetInput.val() < nextDateString) {
                                targetInput.val('');
                            }
                        } else {
                            targetInput.removeAttr('min');
                        }
                    }
                }
            }

            var rowElement = $(this).closest('tr');
            var hasFood = populateDynamicDropdowns(rowElement);
            if (hasFood) {
                calculateMacros(rowElement); 
                enforceMealQuota(rowElement);
            }
        });

        $('.cmp-save-row').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var rowElement = btn.closest('tr'); 
            var container = btn.closest('.cmp-portal-container');

            var dateVal = rowElement.find('.cmp-date-picker').val();
            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked') ? 1 : 0;
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var snackQuota = parseInt(container.data('snack-quota')) || 0;
            var isJuice = container.data('is-juice') === 1;
            var isCustom = rowElement.data('allow-custom') == 1;

            if(!dateVal) { alert('Please select a date from the calendar first.'); return; }

            var isMenuPending = rowElement.find('.boundary-overlay').length > 0;

            if (!isAdminOverride && !isMenuPending && !isChefsChoice && !isCustom) { 
                if (isJuice) {
                    var juiceSelects = rowElement.find('select[class^="cmp-juice-"]');
                    var missingJuice = false;
                    juiceSelects.each(function() { if ($(this).val() === '') missingJuice = true; });
                    if (missingJuice) {
                        alert("Please select all 3 juices to complete this day.");
                        return;
                    }
                } else {
                    var selectedMainCount = 0;
                    rowElement.find('.cmp-main-meal').each(function() {
                        if ($(this).val() !== "") selectedMainCount++;
                    });

                    if (selectedMainCount !== allowedMeals) {
                        alert('Please select exactly ' + allowedMeals + ' main meal(s) before saving.');
                        return;
                    }

                    var selectedSnackCount = 0;
                    rowElement.find('select[data-cat="Snacks"]').each(function() {
                        if ($(this).val() !== "") selectedSnackCount++;
                    });

                    if (selectedSnackCount !== snackQuota) {
                        alert('Please select exactly ' + snackQuota + ' snack(s) before saving.');
                        return;
                    }
                }
            } else if (!isAdminOverride && !isChefsChoice && isCustom) {
                if (isJuice) {
                    var juiceInputs = rowElement.find('.cmp-juice-1, .cmp-juice-2, .cmp-juice-3');
                    var missingJuice = false;
                    juiceInputs.each(function() { if ($(this).val().trim() === '') missingJuice = true; });
                    if (missingJuice) {
                        alert("Please complete all 3 juices to complete this day.");
                        return;
                    }
                } else {
                    var selectedMainCount = 0;
                    rowElement.find('.cmp-main-meal').each(function() {
                        if ($(this).val().trim() !== "") selectedMainCount++;
                    });
                    if (selectedMainCount !== allowedMeals) {
                        alert('Please provide exactly ' + allowedMeals + ' main meal(s) before saving.');
                        return;
                    }

                    var selectedSnackCount = 0;
                    rowElement.find('.cmp-snack-1, .cmp-snack-2').each(function() {
                        if ($(this).val().trim() !== "") selectedSnackCount++;
                    });
                    if (selectedSnackCount !== snackQuota) {
                        alert('Please provide exactly ' + snackQuota + ' snack(s) before saving.');
                        return;
                    }
                }
            } else if (isMenuPending && !isChefsChoice && !isAdminOverride && !isCustom) {
                alert('The specific menu is not available yet. Please select "Chef\'s Choice" to secure this date.');
                return;
            }

            btn.text('Saving...').prop('disabled', true);

            var data = {
                action: 'cmp_save_daily_log',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                log_id: btn.data('log-id'),
                sub_id: btn.data('sub-id'),
                date: dateVal,
                chefs_choice: isChefsChoice
            };

            if (isCustom) {
                data.custom_breakfast = rowElement.find('[data-slot="breakfast"]').val() || null;
                data.custom_lunch     = rowElement.find('[data-slot="lunch"]').val() || null;
                data.custom_dinner    = rowElement.find('[data-slot="dinner"]').val() || null;
                data.custom_snack_1   = rowElement.find('[data-slot="snack_1"]').val() || null;
                data.custom_snack_2   = rowElement.find('[data-slot="snack_2"]').val() || null;
                data.custom_juice_1   = rowElement.find('[data-slot="juice_1"]').val() || null;
                data.custom_juice_2   = rowElement.find('[data-slot="juice_2"]').val() || null;
                data.custom_juice_3   = rowElement.find('[data-slot="juice_3"]').val() || null;
            } else {
                data.breakfast = isMenuPending ? null : (rowElement.find('[data-slot="breakfast"]').val() || null);
                data.lunch     = isMenuPending ? null : (rowElement.find('[data-slot="lunch"]').val() || null);
                data.dinner    = isMenuPending ? null : (rowElement.find('[data-slot="dinner"]').val() || null);
                data.snack_1   = isMenuPending ? null : (rowElement.find('[data-slot="snack_1"]').val() || null);
                data.snack_2   = isMenuPending ? null : (rowElement.find('[data-slot="snack_2"]').val() || null);
                data.juice_1   = isMenuPending ? null : (rowElement.find('[data-slot="juice_1"]').val() || null);
                data.juice_2   = isMenuPending ? null : (rowElement.find('[data-slot="juice_2"]').val() || null);
                data.juice_3   = isMenuPending ? null : (rowElement.find('[data-slot="juice_3"]').val() || null);
            }

            $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(response) {
                if(response.success) {
                    if(response.data && response.data.new_log_id) { btn.data('log-id', response.data.new_log_id); }
                    rowElement.removeClass('status-pending').addClass('status-saved');

                    if (!isAdminOverride && !isChefOverride) {
                        var globalFloor = "<?php echo esc_js($global_min_date); ?>";
                        var isTimeLocked = (dateVal < globalFloor);

                        if (isTimeLocked) {
                            rowElement.find('.cmp-date-picker, .cmp-chefs-choice, select, input.cmp-meal-custom').prop('disabled', true).removeClass('quota-locked');
                            btn.replaceWith('<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center;">Locked<br>(Cutoff Passed)</span>');
                        } else {
                            btn.text('Update').css({'background':'#1B5E20', 'box-shadow':'none'}).prop('disabled', false);
                        }

                        var newBadge = '<span style="color:#1B5E20; font-size:0.9em; background:#EEF7E3; border:1px solid #DCECC9; padding:4px 8px; border-radius:4px; font-weight:bold; white-space:nowrap;">Confirmed</span>';
                        rowElement.find('td:nth-last-child(2)').html(newBadge);
                        rowElement.find('.cmp-mobile-status').html(newBadge);

                        if ($(window).width() <= 768) {
                            rowElement.removeClass('is-open'); 
                            var $nextRow = rowElement.next('tr.status-pending'); 
                            if ($nextRow.length) {
                                $nextRow.addClass('is-open'); 
                                $('html, body').animate({ scrollTop: $nextRow.offset().top - 80 }, 600); 
                            }
                        }
                    } else {
                        var newBtnText = (isAdminOverride || isChefOverride) ? 'Update' : 'Saved';
                        btn.text(newBtnText).css({'background':'#1B5E20', 'box-shadow':'none'}).prop('disabled', false); 
                    }

                } else {
                    alert('Error: ' + response.data);
                    btn.text(btn.data('log-id') ? 'Update' : 'Save').prop('disabled', false);
                }
            });
        });

        // CLEAR SELECTION LOGIC
        $('.cmp-clear-row').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            
            if (!confirm('Are you sure you want to clear this day? This will reset the selection and return the quota so a new date can be picked.')) {
                return;
            }
            
            var origHTML = btn.html();
            btn.html('...').prop('disabled', true);
            
            var data = {
                action: 'cmp_clear_daily_log',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                log_id: btn.data('log-id'),
                sub_id: btn.data('sub-id')
            };
            
            $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(response) {
                if (response.success) {
                    location.reload(); 
                } else {
                    alert('Error: ' + response.data);
                    btn.html(origHTML).prop('disabled', false);
                }
            }).fail(function() {
                alert('Connection error.');
                btn.html(origHTML).prop('disabled', false);
            });
        });

        // CUSTOMER/FOH CANCEL DAY LOGIC
        $('.cmp-cancel-day-row').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            
            if (!confirm('Are you sure you want to CANCEL this specific day?\n\nThis will permanently void this date, display it as "Cancelled" to the customer, and push a replacement day to the bottom of their plan.')) {
                return;
            }
            
            var origHTML = btn.html();
            btn.html('...').prop('disabled', true);
            
            var data = {
                action: 'cmp_cancel_daily_log',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                log_id: btn.data('log-id'),
                sub_id: btn.data('sub-id')
            };
            
            $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(response) {
                if (response.success) {
                    location.reload(); 
                } else {
                    alert('Error: ' + response.data);
                    btn.html(origHTML).prop('disabled', false);
                }
            }).fail(function() {
                alert('Connection error.');
                btn.html(origHTML).prop('disabled', false);
            });
        });

    });
    </script>
    <?php
    return ob_get_clean();
}

// ==========================================
// 3. EXPORT CUSTOMER MEAL PLAN TO CSV
// ==========================================
if ( ! function_exists( 'cmp_export_customer_csv' ) ) {
    add_action('wp_ajax_cmp_export_customer_csv', 'cmp_export_customer_csv');
    add_action('wp_ajax_nopriv_cmp_export_customer_csv', 'cmp_export_customer_csv');

    function cmp_export_customer_csv() {
        if (!isset($_GET['sub_id'])) wp_die('No subscription ID provided.');
        global $wpdb;
        $sub_id = intval($_GET['sub_id']);

        $is_admin = current_user_can('manage_options') || current_user_can('foh_manager') || current_user_can('kitchen_staff') || current_user_can('menu_manager');
        $user_id = get_current_user_id();
        $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", $sub_id));

        if (!$sub || (!$is_admin && $sub->user_id != $user_id)) wp_die('Unauthorized Access');

        $is_flexible  = isset($sub->is_flexible) ? intval($sub->is_flexible) : 0;
        $allow_custom = isset($sub->allow_custom) ? intval($sub->allow_custom) : 0;

        $order = wc_get_order($sub->wc_order_id);
        $sub_user = get_userdata($sub->user_id);

        $fname = get_user_meta($sub->user_id, 'first_name', true) ?: get_user_meta($sub->user_id, 'billing_first_name', true);
        $lname = get_user_meta($sub->user_id, 'last_name', true) ?: get_user_meta($sub->user_id, 'billing_last_name', true);
        $fallback_name = trim($fname . ' ' . $lname);
        if (empty($fallback_name)) $fallback_name = $sub_user ? $sub_user->display_name : 'Customer';

        $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $fallback_name;
        if (empty(trim($full_name))) { $full_name = $fallback_name; }

        $email = $order ? $order->get_billing_email() : ($sub_user ? $sub_user->user_email : '');
        $phone = $order ? $order->get_billing_phone() : get_user_meta($sub->user_id, 'billing_phone', true);

        $timing = $order ? ($order->get_meta('_cmp_delivery_timing') ?: $order->get_meta('delivery_timing')) : get_user_meta($sub->user_id, 'delivery_timing', true);
        $time_slot = $order ? ($order->get_meta('_cmp_time_slot') ?: $order->get_meta('time_slot')) : get_user_meta($sub->user_id, 'time_slot', true);
        $method = $order ? ($order->get_meta('_cmp_logistics_method') ?: $order->get_meta('delivery_method')) : get_user_meta($sub->user_id, 'delivery_method', true);
        $pickup = $order ? ($order->get_meta('_cmp_pickup_location') ?: $order->get_meta('pickup_location')) : get_user_meta($sub->user_id, 'pickup_location', true);
        $allergies = $order ? $order->get_customer_note() : get_user_meta($sub->user_id, 'allergies', true);

        $address = 'Address not provided';
        if ($order) {
            $addr_parts = array_filter([$order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city()]);
            if (empty($addr_parts)) $addr_parts = array_filter([$order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city()]);
            if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
        }
        if ($address === 'Address not provided') {
            $addr_parts = array_filter([get_user_meta($sub->user_id, 'billing_address_1', true), get_user_meta($sub->user_id, 'billing_address_2', true), get_user_meta($sub->user_id, 'billing_city', true)]);
            if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
        }
        $method_display = $method === 'Pickup' && !empty($pickup) ? $method . ' (' . $pickup . ')' : $method;

        $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_daily_logs WHERE subscription_id = %d ORDER BY target_date ASC", $sub_id));
        $foods = $wpdb->get_results("SELECT id, food_name FROM {$wpdb->prefix}cmp_foods");
        $food_map = array(); foreach($foods as $f) { $food_map[$f->id] = $f->food_name; }

        $is_juice = (stripos($sub->allowed_categories, 'Juices') !== false || stripos($sub->plan_name, 'juice') !== false || stripos($sub->plan_name, 'cleanse') !== false);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Meal_Schedule_' . esc_attr(str_replace(' ', '_', $sub->plan_name)) . '.csv"');
        $output = fopen('php://output', 'w');
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

        fputcsv($output, array('CUSTOMER DETAILS'));
        fputcsv($output, array('Name:', $full_name, 'Email:', $email, 'Phone:', $phone));
        fputcsv($output, array('Address:', $address));
        fputcsv($output, array('Logistics:', $method_display, 'Receive By:', $timing, 'Time Slot:', $time_slot));
        fputcsv($output, array('Allergies:', $allergies ?: 'None'));
        fputcsv($output, array('')); 

        if ($is_juice) { 
            fputcsv($output, array('Date', 'Juice 1', 'Juice 2', 'Juice 3', 'Chefs Choice', 'Delivery Status')); 
        } else { 
            $h1 = $is_flexible ? 'Meal 1' : 'Breakfast';
            $h2 = $is_flexible ? 'Meal 2' : 'Lunch';
            $h3 = $is_flexible ? 'Meal 3' : 'Dinner';
            fputcsv($output, array('Date', $h1, $h2, $h3, 'Snack 1', 'Snack 2', 'Chefs Choice', 'Delivery Status')); 
        }

        foreach ($logs as $log) {
            $chefs = $log->is_chefs_choice ? 'Yes' : 'No';

            $status = 'Pending';
            if ($log->delivery_result === 'Successful') { $status = 'Successful'; }
            elseif (in_array($log->delivery_result, ['Cancelled','Returned'])) { $status = $log->delivery_result; }
            elseif ($log->id > 0) { $status = 'Confirmed'; }

            $is_assigned = ($log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || !empty($log->custom_breakfast) || !empty($log->custom_lunch) || !empty($log->custom_dinner) || !empty($log->custom_juice_1));
            $chef_tag = $log->is_chefs_choice ? ' (Chef)' : '';
            $pending_tag = "[Pending Chef]";

            if ($is_juice) {
                $j1 = '-'; $j2 = '-'; $j3 = '-';
                if ($log->is_chefs_choice && !$is_assigned) {
                    $j1 = $pending_tag; $j2 = $pending_tag; $j3 = $pending_tag;
                } else {
                    $j1 = !empty($log->custom_juice_1) ? $log->custom_juice_1 : ($log->juice_1_id ? ($food_map[$log->juice_1_id] ?? 'Unknown') . $chef_tag : '-');
                    $j2 = !empty($log->custom_juice_2) ? $log->custom_juice_2 : ($log->juice_2_id ? ($food_map[$log->juice_2_id] ?? 'Unknown') . $chef_tag : '-');
                    $j3 = !empty($log->custom_juice_3) ? $log->custom_juice_3 : ($log->juice_3_id ? ($food_map[$log->juice_3_id] ?? 'Unknown') . $chef_tag : '-');
                }
                fputcsv($output, array($log->target_date, $j1, $j2, $j3, $chefs, $status));

            } else {
                $b = '-'; $l = '-'; $d = '-'; $s1 = '-'; $s2 = '-';
                if ($log->is_chefs_choice && !$is_assigned) {
                    $b = $pending_tag; $l = $pending_tag; $d = $pending_tag; $s1 = $pending_tag; $s2 = $pending_tag;
                } else {
                    $b  = !empty($log->custom_breakfast) ? $log->custom_breakfast : ($log->breakfast_id ? ($food_map[$log->breakfast_id] ?? 'Unknown') . $chef_tag : '-');
                    $l  = !empty($log->custom_lunch)     ? $log->custom_lunch     : ($log->lunch_id     ? ($food_map[$log->lunch_id]     ?? 'Unknown') . $chef_tag : '-');
                    $d  = !empty($log->custom_dinner)    ? $log->custom_dinner    : ($log->dinner_id    ? ($food_map[$log->dinner_id]    ?? 'Unknown') . $chef_tag : '-');
                    $s1 = !empty($log->custom_snack_1)   ? $log->custom_snack_1   : ($log->snack_1_id   ? ($food_map[$log->snack_1_id]   ?? 'Unknown') . $chef_tag : '-');
                    $s2 = !empty($log->custom_snack_2)   ? $log->custom_snack_2   : ($log->snack_2_id   ? ($food_map[$log->snack_2_id]   ?? 'Unknown') . $chef_tag : '-');
                }
                fputcsv($output, array($log->target_date, $b, $l, $d, $s1, $s2, $chefs, $status));
            }
        }
        fclose($output); exit;
    }
}
