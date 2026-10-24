<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// 0. ROLE, QUOTA, DATE LOCK & DB HELPER FUNCTIONS
//    (CONNECTED TO WP ADMIN -> MEAL SETTINGS)
// ==========================================

/**
 * Ensures the customer feedback table exists without requiring plugin reactivation.
 */
add_action( 'init', 'cmp_ensure_feedback_table_exists' );
function cmp_ensure_feedback_table_exists() {
    if ( get_option( 'cmp_feedback_table_v1' ) === '1' ) {
        return;
    }
    global $wpdb;
    $table_feedback  = $wpdb->prefix . 'cmp_feedback';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE $table_feedback (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) DEFAULT 0,
        subscription_id bigint(20) DEFAULT 0,
        wc_order_id bigint(20) DEFAULT 0,
        is_anonymous tinyint(1) DEFAULT 0,
        customer_name varchar(255) DEFAULT '',
        customer_email varchar(255) DEFAULT '',
        customer_phone varchar(100) DEFAULT '',
        plan_name varchar(255) DEFAULT '',
        service_rating tinyint(2) NOT NULL DEFAULT 0,
        portal_rating tinyint(2) NOT NULL DEFAULT 0,
        quick_tags text NULL,
        comments text NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql );
    update_option( 'cmp_feedback_table_v1', '1' );
}

/**
 * Checks if the current logged-in user belongs to any of the configured role slugs
 * saved under WP Admin -> Meal Portal -> Meal Settings.
 */
function cmp_user_matches_configured_roles( $option_key, $default_roles = array( 'administrator' ) ) {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }

    $allowed_roles = get_option( $option_key, $default_roles );
    if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
        $allowed_roles = $default_roles;
    }

    $user       = wp_get_current_user();
    $user_roles = (array) $user->roles;

    foreach ( $allowed_roles as $role_slug ) {
        if ( in_array( $role_slug, $user_roles, true ) || current_user_can( $role_slug ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Dynamically checks WP Admin -> Meal Settings ('cmp_adjust_days_roles')
 * Default: Administrator & Menu Manager (FOH excluded).
 */
function cmp_can_adjust_plan_days() {
    return cmp_user_matches_configured_roles( 'cmp_adjust_days_roles', array( 'administrator', 'menu_manager' ) );
}

/**
 * Dynamically checks WP Admin -> Meal Settings ('cmp_strict_quota_enabled' & 'cmp_quota_bypass_roles')
 * Default: Strict Quota Enabled ('1'); Only Administrator can bypass meal/snack/juice quotas.
 */
function cmp_can_bypass_meal_quota() {
    $strict_enabled = get_option( 'cmp_strict_quota_enabled', '1' );
    if ( (string) $strict_enabled === '0' ) {
        return true; // Global strict quota enforcement is turned off in WP Admin
    }
    return cmp_user_matches_configured_roles( 'cmp_quota_bypass_roles', array( 'administrator' ) );
}

/**
 * Dynamically checks WP Admin -> Meal Settings ('cmp_date_exempt_roles')
 * Default: Administrator, FOH Manager & Menu Manager are exempt from date & cut-off locks.
 */
function cmp_can_bypass_date_restrictions() {
    return cmp_user_matches_configured_roles( 'cmp_date_exempt_roles', array( 'administrator', 'foh_manager', 'menu_manager' ) );
}

/**
 * Checks whether the current user is FOH, Menu Manager, or Admin (for read-only POS Check visibility & Logistics editing).
 */
function cmp_can_view_pos_and_edit_logistics() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    return current_user_can( 'manage_options' ) || current_user_can( 'foh_manager' ) || current_user_can( 'menu_manager' ) || cmp_can_bypass_date_restrictions();
}

/**
 * Returns the earliest allowed YYYY-MM-DD date a customer can modify, reset, or cancel
 * based on Asia/Dubai timezone and the configured daily cut-off hour.
 * Past dates and today's date are ALWAYS locked for customers. Tomorrow is locked if current hour >= cutoff.
 */
function cmp_get_earliest_customer_allowed_date() {
    $tz           = new DateTimeZone( 'Asia/Dubai' );
    $now          = new DateTime( 'now', $tz );
    $current_hour = (int) $now->format( 'H' );
    $cutoff_hour  = intval( get_option( 'cmp_cutoff_time', '11' ) );
    $tomorrow     = ( clone $now )->modify( '+1 day' )->format( 'Y-m-d' );
    $day_after    = ( clone $now )->modify( '+2 days' )->format( 'Y-m-d' );

    return ( $current_hour >= $cutoff_hour ) ? $day_after : $tomorrow;
}

/**
 * Checks whether a target date is locked for customer edits/resets/cancellations.
 */
function cmp_is_date_locked_for_customer( $target_date ) {
    if ( empty( $target_date ) || $target_date === '0000-00-00' ) {
        return false;
    }
    $tz               = new DateTimeZone( 'Asia/Dubai' );
    $today            = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
    $earliest_allowed = cmp_get_earliest_customer_allowed_date();

    if ( $target_date <= $today || $target_date < $earliest_allowed ) {
        return true;
    }
    return false;
}

// ==========================================
// 1. AJAX HANDLERS
// ==========================================
add_action('wp_ajax_cmp_save_daily_log', 'cmp_ajax_save_daily_log');
function cmp_ajax_save_daily_log() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');
    global $wpdb;

    if (!is_user_logged_in()) wp_send_json_error('Not logged in.');

    $log_id           = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
    $sub_id           = intval($_POST['sub_id']);
    $target_date      = sanitize_text_field($_POST['date']);
    $is_override_mode = !empty($_POST['is_override_mode']) ? intval($_POST['is_override_mode']) : 0;

    if ( empty( $target_date ) ) {
        wp_send_json_error('Please select a valid date first.');
    }

    // Separate Date/Cut-off Privilege from Quota Bypass Privilege (Read from WP Admin Settings)
    $can_bypass_dates = cmp_can_bypass_date_restrictions() || ( current_user_can('kitchen_staff') && $is_override_mode === 1 );
    $can_bypass_quota = cmp_can_bypass_meal_quota();

    // Cutoff Time & Past/Today Date Validation
    if ( ! $can_bypass_dates ) {
        $cutoff_hour      = intval( get_option('cmp_cutoff_time', '11') );
        $earliest_allowed = cmp_get_earliest_customer_allowed_date();

        if ( cmp_is_date_locked_for_customer( $target_date ) ) {
            wp_send_json_error(
                'This date is no longer available. Past dates, today, and dates past the ' .
                str_pad($cutoff_hour, 2, '0', STR_PAD_LEFT) .
                ':00 GST cutoff are locked. The earliest available date is ' .
                date('D, d M Y', strtotime($earliest_allowed)) . '.'
            );
        }
    }

    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $table_foods = $wpdb->prefix . 'cmp_foods';

    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_subs WHERE id = %d", $sub_id));
    if (!$sub) wp_send_json_error('Subscription not found.');

    $is_staff_user = cmp_can_bypass_date_restrictions() || cmp_can_bypass_meal_quota() || current_user_can('kitchen_staff');
    if ( ! $is_staff_user && intval($sub->user_id) !== get_current_user_id() ) {
        wp_send_json_error('Unauthorized access to this subscription.');
    }

    // --- LOG-LEVEL SNAPSHOT LOGIC (Phase 7) ---
    if ($log_id > 0) {
        $existing_log = $wpdb->get_row($wpdb->prepare("SELECT plan_mode, pos_updated FROM $table_logs WHERE id = %d", $log_id));
        $plan_mode    = ($existing_log && !empty($existing_log->plan_mode)) ? $existing_log->plan_mode : 'standard';
        $pos_updated  = ($existing_log && !empty($existing_log->pos_updated)) ? 1 : 0;
    } else {
        $plan_mode = 'standard';
        if (!empty($sub->is_flexible)) $plan_mode = 'flexible';
        if (!empty($sub->allow_custom)) $plan_mode = 'custom';
        $pos_updated = 0;
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

        // Non-bypass users cannot save an empty Menu Pending date unless Chef's Choice is selected
        if ($is_menu_pending && !$is_chefs_choice && !$can_bypass_quota) {
            wp_send_json_error('The specific menu is not available yet for this date. Please select "Chef\'s Choice" to secure this date.');
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

    // --- STRICT SELECTION QUOTA ENFORCEMENT (GOVERNED BY WP ADMIN SETTINGS) ---
    if ( ! $can_bypass_quota && ! $is_chefs_choice && ! $is_menu_pending ) {
        if ($is_juice) {
            if ($row_allow_custom) {
                if (empty(trim($_POST['custom_juice_1'] ?? '')) || empty(trim($_POST['custom_juice_2'] ?? '')) || empty(trim($_POST['custom_juice_3'] ?? ''))) {
                    wp_send_json_error("Please provide all 3 juices as per the subscription quota to save this day.");
                }
            } else {
                if (empty($_POST['juice_1']) || empty($_POST['juice_2']) || empty($_POST['juice_3'])) {
                    wp_send_json_error("Please select all 3 juices as per the subscription quota to save this day.");
                }
            }
        } else {
            $submitted_count = 0;
            if ($row_allow_custom) {
                if (!empty(trim($_POST['custom_breakfast'] ?? ''))) $submitted_count++;
                if (!empty(trim($_POST['custom_lunch'] ?? '')))     $submitted_count++;
                if (!empty(trim($_POST['custom_dinner'] ?? '')))    $submitted_count++;
            } else {
                if (!empty($_POST['breakfast'])) $submitted_count++;
                if (!empty($_POST['lunch']))     $submitted_count++;
                if (!empty($_POST['dinner']))    $submitted_count++;
            }

            if ($submitted_count !== $allowed_quota) {
                wp_send_json_error("Quota Requirement: Please select exactly {$allowed_quota} main meal(s) as allocated in this subscription.");
            }

            $submitted_snacks = 0;
            if ($row_allow_custom) {
                if (!empty(trim($_POST['custom_snack_1'] ?? ''))) $submitted_snacks++;
                if (!empty(trim($_POST['custom_snack_2'] ?? ''))) $submitted_snacks++;
            } else {
                if (!empty($_POST['snack_1'])) $submitted_snacks++;
                if (!empty($_POST['snack_2'])) $submitted_snacks++;
            }

            if ($submitted_snacks !== $snack_quota) {
                wp_send_json_error("Quota Requirement: Please select exactly {$snack_quota} snack(s) as allocated in this subscription.");
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
        $response_data = array('msg' => 'Updated', 'new_log_id' => $log_id, 'pos_updated' => $pos_updated);
    } else {
        $wpdb->insert($table_logs, $data);
        $response_data = array('new_log_id' => $wpdb->insert_id, 'pos_updated' => 0);
    }

    $updated_subs_queue = get_option('cmp_daily_updated_subs', array());
    if (!is_array($updated_subs_queue)) { $updated_subs_queue = array(); }

    if (!in_array($sub_id, $updated_subs_queue)) {
        $updated_subs_queue[] = $sub_id;
        update_option('cmp_daily_updated_subs', $updated_subs_queue);
    }

    wp_send_json_success($response_data);
}

// 1.5 AJAX HANDLER: CUSTOMER PORTAL INLINE LOGISTICS EDITING (WITH HYBRID CUSTOM TIMING ENGINE)
add_action('wp_ajax_cmp_cp_update_customer_logistics', 'cmp_ajax_cp_update_customer_logistics');
function cmp_ajax_cp_update_customer_logistics() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');

    if ( ! cmp_can_view_pos_and_edit_logistics() ) {
        wp_send_json_error('Permission Denied');
    }

    $user_id             = intval($_POST['user_id']);
    $order_id            = intval($_POST['order_id']);
    $sub_id              = isset($_POST['sub_id']) ? intval($_POST['sub_id']) : 0;
    $timing              = sanitize_text_field( wp_unslash($_POST['delivery_timing'] ?? '') );
    $time_slot           = sanitize_text_field( wp_unslash($_POST['time_slot'] ?? '') );
    $allergies           = sanitize_textarea_field( wp_unslash($_POST['allergies'] ?? '') );
    $save_custom_timing  = !empty($_POST['save_custom_timing']);
    $save_custom_slot    = !empty($_POST['save_custom_slot']);

    if ( empty($timing) ) {
        wp_send_json_error('Please provide a valid Delivery Timing (Receive By) option.');
    }
    if ( empty($time_slot) ) {
        wp_send_json_error('Please provide a valid Time Slot option.');
    }

    // Optionally persist new custom timing / time slot into reusable WP Options preset lists
    if ( $save_custom_timing ) {
        $saved_timings = get_option( 'cmp_custom_delivery_timings', array() );
        if ( ! is_array( $saved_timings ) ) {
            $saved_timings = array();
        }
        $default_timings = array( 'Deliver Day Before', 'Deliver Same Day' );
        if ( ! in_array( $timing, $default_timings, true ) && ! in_array( $timing, $saved_timings, true ) ) {
            $saved_timings[] = $timing;
            update_option( 'cmp_custom_delivery_timings', array_values( array_unique( $saved_timings ) ) );
        }
    }

    if ( $save_custom_slot ) {
        $saved_slots = get_option( 'cmp_custom_time_slots', array() );
        if ( ! is_array( $saved_slots ) ) {
            $saved_slots = array();
        }
        $default_slots = array(
            '5:00 AM to 6:00 AM',
            '6:00 AM to 7:00 AM',
            '7:00 AM to 8:00 AM',
            '8:00 AM to 9:00 AM',
            '5:00 PM to 8:00 PM',
        );
        if ( ! in_array( $time_slot, $default_slots, true ) && ! in_array( $time_slot, $saved_slots, true ) ) {
            $saved_slots[] = $time_slot;
            update_option( 'cmp_custom_time_slots', array_values( array_unique( $saved_slots ) ) );
        }
    }

    if ($user_id > 0) {
        update_user_meta($user_id, 'delivery_timing', $timing);
        update_user_meta($user_id, 'time_slot', $time_slot);
        update_user_meta($user_id, 'allergies', $allergies);
    }

    if ($order_id > 0 && function_exists('wc_get_order')) {
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

    // If subscription table has optional columns for delivery_timing / time_slot / allergies, update them safely
    if ( $sub_id > 0 ) {
        global $wpdb;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';
        $cols       = $wpdb->get_col( "DESC {$table_subs}", 0 );
        if ( is_array( $cols ) ) {
            $sub_update = array();
            if ( in_array( 'delivery_timing', $cols, true ) ) $sub_update['delivery_timing'] = $timing;
            if ( in_array( 'time_slot', $cols, true ) )       $sub_update['time_slot']       = $time_slot;
            if ( in_array( 'allergies', $cols, true ) )       $sub_update['allergies']       = $allergies;
            if ( ! empty( $sub_update ) ) {
                $wpdb->update( $table_subs, $sub_update, array( 'id' => $sub_id ) );
            }
        }
    }

    wp_send_json_success(array(
        'timing'    => $timing,
        'time_slot' => $time_slot,
        'allergies' => $allergies ?: 'No Allergies Recorded'
    ));
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

    if ($order_id > 0 && function_exists('wc_get_order')) {
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

// 1.7 AJAX HANDLER: ADD/REMOVE PLAN DAYS (GOVERNED BY WP ADMIN SETTINGS)
add_action('wp_ajax_cmp_cp_admin_adjust_days', 'cmp_ajax_cp_admin_adjust_days');
function cmp_ajax_cp_admin_adjust_days() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');
    if ( ! cmp_can_adjust_plan_days() ) {
        wp_send_json_error('Permission Denied. Your role is not authorized to add or remove plan days.');
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

    $log_id           = intval($_POST['log_id']);
    $sub_id           = intval($_POST['sub_id']);
    $is_override_mode = !empty($_POST['is_override_mode']) ? intval($_POST['is_override_mode']) : 0;

    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_logs WHERE id = %d", $log_id));
    if (!$log) wp_send_json_error('Selection not found.');
    
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_subs WHERE id = %d", $sub_id));
    if (!$sub) wp_send_json_error('Subscription not found.');

    $can_bypass_dates = cmp_can_bypass_date_restrictions() || ( current_user_can('kitchen_staff') && $is_override_mode === 1 );
    
    if (!$can_bypass_dates && $sub->user_id != get_current_user_id()) {
        wp_send_json_error('Unauthorized access to this subscription.');
    }

    if ( ! $can_bypass_dates ) {
        if ( cmp_is_date_locked_for_customer( $log->target_date ) || $log->delivery_result === 'Successful' ) {
            wp_send_json_error('Past dates, today\'s date, and dates past the daily cut-off time cannot be reset.');
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

    $log_id           = intval($_POST['log_id']);
    $sub_id           = intval($_POST['sub_id']);
    $is_override_mode = !empty($_POST['is_override_mode']) ? intval($_POST['is_override_mode']) : 0;

    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_logs WHERE id = %d", $log_id));
    if (!$log) wp_send_json_error('Selection not found.');
    
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_subs WHERE id = %d", $sub_id));
    if (!$sub) wp_send_json_error('Subscription not found.');

    $can_bypass_dates = cmp_can_bypass_date_restrictions() || ( current_user_can('kitchen_staff') && $is_override_mode === 1 );
    
    if (!$can_bypass_dates && $sub->user_id != get_current_user_id()) {
        wp_send_json_error('Unauthorized access to this subscription.');
    }

    if ( ! $can_bypass_dates ) {
        if ( cmp_is_date_locked_for_customer( $log->target_date ) || $log->delivery_result === 'Successful' ) {
            wp_send_json_error('Past dates, today\'s date, and dates past the daily cut-off time cannot be cancelled.');
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

// 1.10 AJAX HANDLER: SUBMIT CUSTOMER EXPERIENCE FEEDBACK SURVEY (OPTION 4A)
add_action('wp_ajax_cmp_submit_customer_feedback', 'cmp_ajax_submit_customer_feedback');
function cmp_ajax_submit_customer_feedback() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');
    if ( ! is_user_logged_in() ) {
        wp_send_json_error('Please sign in to submit feedback.');
    }

    cmp_ensure_feedback_table_exists();
    global $wpdb;

    $sub_id          = isset($_POST['sub_id']) ? intval($_POST['sub_id']) : 0;
    $service_rating  = isset($_POST['service_rating']) ? intval($_POST['service_rating']) : 0;
    $portal_rating   = isset($_POST['portal_rating']) ? intval($_POST['portal_rating']) : 0;
    $include_details = isset($_POST['include_details']) && intval($_POST['include_details']) === 1 ? 1 : 0;
    $quick_tags      = isset($_POST['quick_tags']) ? sanitize_text_field( wp_unslash($_POST['quick_tags']) ) : '';
    $comments        = isset($_POST['comments']) ? sanitize_textarea_field( wp_unslash($_POST['comments']) ) : '';

    if ( $service_rating < 1 && $portal_rating < 1 ) {
        wp_send_json_error('Please select a rating for your Meal Plan Service or Online Portal experience.');
    }

    $table_subs     = $wpdb->prefix . 'cmp_subscriptions';
    $table_feedback = $wpdb->prefix . 'cmp_feedback';
    $sub            = $sub_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_subs WHERE id = %d", $sub_id ) ) : null;

    $is_anonymous   = $include_details ? 0 : 1;
    $user_id        = 0;
    $wc_order_id    = 0;
    $customer_name  = 'Anonymous Customer';
    $customer_email = '';
    $customer_phone = '';
    $plan_name      = $sub ? $sub->plan_name : '';

    if ( $include_details ) {
        $target_uid = $sub ? intval( $sub->user_id ) : get_current_user_id();
        $user_id    = $target_uid;
        $wc_order_id = $sub ? intval( $sub->wc_order_id ) : 0;
        $u_obj      = get_userdata( $target_uid );
        $order_obj  = ( $wc_order_id > 0 && function_exists('wc_get_order') ) ? wc_get_order( $wc_order_id ) : false;

        $fname = get_user_meta( $target_uid, 'first_name', true ) ?: get_user_meta( $target_uid, 'billing_first_name', true );
        $lname = get_user_meta( $target_uid, 'last_name', true ) ?: get_user_meta( $target_uid, 'billing_last_name', true );
        if ( $order_obj ) {
            $fname = $order_obj->get_billing_first_name() ?: $fname;
            $lname = $order_obj->get_billing_last_name() ?: $lname;
        }
        $customer_name  = trim( $fname . ' ' . $lname ) ?: ( $u_obj ? $u_obj->display_name : 'Customer' );
        $customer_email = $order_obj ? $order_obj->get_billing_email() : ( $u_obj ? $u_obj->user_email : '' );
        $customer_phone = $order_obj ? $order_obj->get_billing_phone() : get_user_meta( $target_uid, 'billing_phone', true );
    }

    $wpdb->insert(
        $table_feedback,
        array(
            'user_id'         => $user_id,
            'subscription_id' => $include_details ? $sub_id : 0,
            'wc_order_id'     => $wc_order_id,
            'is_anonymous'    => $is_anonymous,
            'customer_name'   => $customer_name,
            'customer_email'  => $customer_email,
            'customer_phone'  => $customer_phone,
            'plan_name'       => $plan_name,
            'service_rating'  => $service_rating,
            'portal_rating'   => $portal_rating,
            'quick_tags'      => $quick_tags,
            'comments'        => $comments,
            'created_at'      => current_time( 'mysql' ),
        )
    );

    wp_send_json_success( array(
        'msg' => 'Thank you! Your feedback has been received.'
    ) );
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
    $user_id      = get_current_user_id();
    $current_user = wp_get_current_user();
    $table_logs   = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods  = $wpdb->prefix . 'cmp_foods';

    $subscribe_url        = 'https://mealplan.thecyclebistro.com/';
    $dynamic_cutoff_hour  = intval( get_option('cmp_cutoff_time', '11') );
    $label_chefs_choice   = get_option('cmp_label_chefs_choice', "Chef's Choice");
    $blackout_string      = get_option('cmp_blackout_dates', '');
    $blackout_dates_array = !empty($blackout_string) ? explode(',', str_replace(' ', '', $blackout_string)) : array();
    $raw_wa               = get_option('cmp_whatsapp_number', '');
    $clean_wa             = preg_replace('/[^0-9]/', '', $raw_wa);

    // Load Custom Delivery Timings & Time Slots saved by Staff
    $custom_saved_timings = get_option( 'cmp_custom_delivery_timings', array() );
    if ( ! is_array( $custom_saved_timings ) ) $custom_saved_timings = array();
    $custom_saved_slots   = get_option( 'cmp_custom_time_slots', array() );
    if ( ! is_array( $custom_saved_slots ) ) $custom_saved_slots = array();

    // Clean Fixed-Size SVG Icons (Zero WordPress Twemoji Interference)
    $icon_reset  = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>';
    $icon_cancel = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
    $icon_kbd    = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1B5E20" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M8 12h.01M12 12h.01M16 12h.01M7 16h10"/></svg>';
    $icon_target = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 12 12 16 16 12"/><line x1="12" y1="8" x2="12" y2="16"/></svg>';
    $icon_up     = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>';
    $icon_undo   = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>';
    $icon_plus   = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';

    // OVERRIDE & PRIVILEGE DETECTION (CONNECTED TO WP ADMIN SETTINGS)
    $is_admin_override        = isset($_GET['admin_edit_sub']) && ( cmp_can_bypass_date_restrictions() || current_user_can('manage_options') || current_user_can('foh_manager') || current_user_can('menu_manager') );
    $is_chef_override         = isset($_GET['chef_override_sub']) && ( cmp_can_bypass_date_restrictions() || current_user_can('kitchen_staff') );
    $can_bypass_date_lock     = cmp_can_bypass_date_restrictions() || $is_chef_override;
    $can_bypass_quota         = cmp_can_bypass_meal_quota();
    $can_view_pos_check       = current_user_can('manage_options') || current_user_can('foh_manager') || current_user_can('menu_manager');
    $can_edit_staff_logistics = cmp_can_view_pos_and_edit_logistics();

    if ($is_admin_override) {
        $raw_subs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", intval($_GET['admin_edit_sub'])) );
    } elseif ($is_chef_override) {
        $raw_subs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", intval($_GET['chef_override_sub'])) );
    } else {
        $raw_subs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d AND status IN ('active','paused') ORDER BY id DESC", $user_id) );
    }

    $subs = array();
    $tz_dubai  = new DateTimeZone('Asia/Dubai');
    $now_dubai = new DateTime('now', $tz_dubai);
    $today_dt  = $now_dubai->format('Y-m-d H:i:s');
    $today_ymd = $now_dubai->format('Y-m-d');

    if ($raw_subs) {
        foreach ($raw_subs as $sub) {
            if ($is_admin_override || $is_chef_override) {
                $subs[] = $sub; 
            } else {
                $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
                if ($usage_days < $sub->total_days && $sub->expiry_date >= $today_dt) {
                    $subs[] = $sub; 
                }
            }
        }
    }

    if ( empty($subs) ) {
        return '<div style="max-width:520px; margin:40px auto; padding:40px 30px; text-align:center; background:rgba(255,255,255,0.88); backdrop-filter:blur(18px); border:1.5px solid #DCECC9; border-top:5px solid #379237; border-radius:18px; box-shadow:0 16px 36px rgba(27,94,32,0.10);">
                    <h3 style="color:#1B5E20; font-size:1.5em; margin:0 0 10px 0;">No Active Meal Plans Found</h3>
                    <p style="color:#475569; margin-bottom: 25px; line-height:1.5;">It looks like your meal plans have been completed or expired.</p>
                    <a href="' . esc_url( $subscribe_url ) . '" style="background:linear-gradient(135deg, #379237 0%, #1B5E20 100%); color:#fff; text-decoration:none; padding:13px 28px; border-radius:10px; font-weight:bold; display:inline-block; margin-bottom:18px; box-shadow:0 6px 16px rgba(55,146,55,0.25);">Subscribe to a New Meal Plan</a>
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
            $greeting_name = esc_html($customer_display_name) . " (Staff Override)";
        }
    } else {
        $fname = get_user_meta($current_user->ID, 'first_name', true) ?: get_user_meta($current_user->ID, 'billing_first_name', true);
        $lname = get_user_meta($current_user->ID, 'last_name', true) ?: get_user_meta($current_user->ID, 'billing_last_name', true);
        $curr_name = trim($fname . ' ' . $lname);
        $greeting_name = !empty($curr_name) ? $curr_name : $current_user->display_name;
    }

    // --- FETCH ALL FOODS FOR JS INJECTION ---
    $foods = $wpdb->get_results("SELECT * FROM $table_foods WHERE is_active = 1 ORDER BY category_name, food_name");

    $current_hour      = (int) $now_dubai->format('H');
    $customer_min_date = cmp_get_earliest_customer_allowed_date();

    // --- DYNAMIC STATUS NOTE BASED ON CONFIGURED WP ADMIN PERMISSIONS ---
    if ($can_bypass_date_lock) {
        $global_min_date = ''; 
        $quota_note = $can_bypass_quota
            ? "Privilege Mode: Date, cut-off & meal quota restrictions are disabled for your role."
            : "Staff Mode: Date & cut-off restrictions are disabled for your role. Standard subscription meal quotas apply.";
        $note_text  = $quota_note;
        $note_color = "#379237"; 
    } elseif ( $current_hour >= $dynamic_cutoff_hour ) {
        $global_min_date = $customer_min_date;
        $note_text = "Note: It is past " . str_pad($dynamic_cutoff_hour, 2, '0', STR_PAD_LEFT) . ":00 GST. Meal selections, resets, and cancellations are now locked for today and tomorrow.";
        $note_color = "#d63638"; 
    } else {
        $global_min_date = $customer_min_date;
        $note_text = "Note: Please make selections, resets, or cancellations before " . str_pad($dynamic_cutoff_hour, 2, '0', STR_PAD_LEFT) . ":00 GST the day prior to delivery.";
        $note_color = "#1B5E20"; 
    }

    ob_start();
    ?>
    <script>
        var cmpGlobalFoodData = <?php echo json_encode($foods); ?>;
    </script>

    <style>
        .cmp-dashboard-wrap { max-width: 1220px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; overflow-x: hidden; box-sizing: border-box; position: relative; padding: 0 12px 40px 12px; }
        .cmp-dashboard-wrap *, .cmp-dashboard-wrap *::before, .cmp-dashboard-wrap *::after { box-sizing: border-box; }

        /* Ironclad WordPress Twemoji / SVG Size Lock (Prevents Theme CSS from Stretching Icons) */
        .cmp-dashboard-wrap img.emoji,
        .cmp-dashboard-wrap img.wp-smiley {
            width: 16px !important;
            height: 16px !important;
            max-width: 16px !important;
            max-height: 16px !important;
            display: inline-block !important;
            vertical-align: middle !important;
            margin: 0 4px !important;
            border: none !important;
            box-shadow: none !important;
        }
        .cmp-dashboard-wrap svg.cmp-fixed-svg {
            width: 16px !important;
            height: 16px !important;
            max-width: 16px !important;
            max-height: 16px !important;
            flex-shrink: 0 !important;
            display: inline-block !important;
            vertical-align: middle !important;
        }

        /* =========================================================
           1. HEADER BANNER & SUBSCRIBE TO NEW PLAN BUTTONS
           ========================================================= */
        .cmp-header-banner {
            background: linear-gradient(135deg, rgba(27, 94, 32, 0.94) 0%, rgba(55, 146, 55, 0.88) 55%, rgba(104, 185, 70, 0.84) 100%) !important;
            backdrop-filter: blur(18px) !important;
            -webkit-backdrop-filter: blur(18px) !important;
            color: #fff !important;
            padding: 24px 28px !important;
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
        .cmp-header-banner h1 { margin: 0 !important; color: #ffffff !important; font-size: 1.85em !important; font-weight: 800 !important; line-height: 1.2 !important; }
        .cmp-header-banner p { margin: 5px 0 0 0 !important; color: #EEF7E3 !important; font-weight: 600 !important; font-size: 0.98em !important; }

        .cmp-header-actions {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            flex-wrap: wrap !important;
        }
        .cmp-hdr-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 7px !important;
            padding: 10px 18px !important;
            border-radius: 10px !important;
            font-weight: 800 !important;
            font-size: 0.9rem !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            white-space: nowrap !important;
            line-height: 1.2 !important;
        }
        .cmp-hdr-btn-subscribe {
            background: #ffffff !important;
            color: #1B5E20 !important;
            border: 1.5px solid #DCECC9 !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.10) !important;
        }
        .cmp-hdr-btn-subscribe:hover {
            background: #EEF7E3 !important;
            color: #1B5E20 !important;
            transform: translateY(-1px) !important;
        }
        .cmp-hdr-btn-export {
            background: rgba(27, 94, 32, 0.72) !important;
            color: #ffffff !important;
            border: 1.5px solid rgba(220, 236, 201, 0.65) !important;
        }
        .cmp-hdr-btn-export:hover {
            background: #1B5E20 !important;
            color: #ffffff !important;
            transform: translateY(-1px) !important;
        }
        .cmp-hdr-btn-logout {
            background: #dc2626 !important;
            color: #ffffff !important;
            border: 1.5px solid rgba(254, 202, 202, 0.4) !important;
        }
        .cmp-hdr-btn-logout:hover {
            background: #b91c1c !important;
            color: #ffffff !important;
        }

        /* Tablet Customer Glass Header (601px - 991px) */
        @media (min-width: 601px) and (max-width: 991px) {
            .cmp-header-banner {
                background: linear-gradient(125deg, rgba(220, 236, 201, 0.95) 0%, rgba(104, 185, 70, 0.42) 100%) !important;
                border: 1.5px solid #379237 !important;
                padding: 20px 22px !important;
            }
            .cmp-header-banner h1 { color: #231F20 !important; font-size: 1.55em !important; }
            .cmp-header-banner p { color: #1B5E20 !important; }
            .cmp-hdr-btn-subscribe {
                background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important;
                color: #ffffff !important;
                border-color: #1B5E20 !important;
            }
            .cmp-hdr-btn-subscribe:hover {
                filter: brightness(1.06) !important;
                color: #ffffff !important;
            }
            .cmp-hdr-btn-export {
                background: #ffffff !important;
                color: #1B5E20 !important;
                border-color: #379237 !important;
            }
            .cmp-hdr-btn-export:hover {
                background: #EEF7E3 !important;
                color: #1B5E20 !important;
            }
        }

        /* Mobile Customer Glass Header (<= 600px) */
        @media (max-width: 600px) {
            .cmp-header-banner {
                background: linear-gradient(145deg, rgba(55, 146, 55, 0.96) 0%, rgba(27, 94, 32, 0.94) 100%) !important;
                border: 1.5px solid #68B946 !important;
                border-radius: 16px 16px 0 0 !important;
                padding: 15px 14px !important;
                gap: 12px !important;
            }
            .cmp-header-banner > div:first-child { width: 100% !important; }
            .cmp-header-banner h1 { color: #ffffff !important; font-size: 1.32em !important; }
            .cmp-header-banner p { color: #DCECC9 !important; font-size: 0.88em !important; margin-top: 3px !important; }
            .cmp-header-actions {
                width: 100% !important;
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 8px !important;
            }
            .cmp-hdr-btn-subscribe {
                grid-column: 1 / -1 !important;
                width: 100% !important;
                padding: 10px 14px !important;
                font-size: 0.86rem !important;
            }
            .cmp-hdr-btn-export,
            .cmp-hdr-btn-logout {
                width: 100% !important;
                padding: 8px 10px !important;
                font-size: 0.8rem !important;
            }
        }

        .cmp-tab-nav { display: flex; background: #fff; border: 1px solid #DCECC9; border-top: none; border-bottom: 2px solid #DCECC9; padding: 0 16px; overflow-x: auto; -webkit-overflow-scrolling: touch; align-items: flex-end; border-radius: 0 0 12px 12px; }
        .cmp-tab-btn { padding: 12px 22px; border: none; background: none; font-weight: bold; cursor: pointer; border-bottom: 3px solid transparent; color: #64748b; white-space: nowrap; line-height: 1.4; text-align: center; transition: 0.2s; }
        .cmp-tab-btn.active { color: #1B5E20; border-bottom-color: #379237; background: rgba(238, 247, 227, 0.45); }
        .cmp-tab-content { display: none; padding: 20px 0; }
        .cmp-tab-content.active { display: block; }

        /* =========================================================
           2. ACCOUNT DETAILS & ACTIVE LOGISTICS DRAWER (OPTION 5A)
           ========================================================= */
        .cmp-info-drawer-wrap {
            background: #ffffff;
            border: 1.5px solid #DCECC9;
            border-radius: 14px;
            margin-bottom: 18px;
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.04);
            overflow: hidden;
        }
        /* Mobile Collapsible Trigger Bar (Hidden on Desktop/Tablet) */
        .cmp-mobile-info-toggle {
            display: none;
            width: 100%;
            background: linear-gradient(135deg, #f8fafc 0%, #EEF7E3 100%);
            border: none;
            border-bottom: 1px solid transparent;
            padding: 11px 14px;
            text-align: left;
            cursor: pointer;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-family: inherit;
        }
        .cmp-mobile-info-toggle-left {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
            flex: 1;
        }
        .cmp-mobile-info-kicker {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #1B5E20;
        }
        .cmp-mobile-info-summary-line {
            font-size: 0.82rem;
            font-weight: 700;
            color: #231F20;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cmp-mobile-info-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #ffffff;
            color: #1B5E20;
            border: 1px solid #68B946;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.74rem;
            font-weight: 800;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .cmp-info-drawer-body {
            padding: 22px 24px 18px 24px;
        }
        .cmp-info-cards-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            align-items: stretch;
        }
        .cmp-account-card-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 18px;
        }
        .cmp-logistics-card-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 18px;
            position: relative;
        }
        .cmp-kv-grid {
            display: grid;
            grid-template-columns: 100px minmax(0, 1fr);
            row-gap: 7px;
            column-gap: 10px;
            font-size: 0.92rem;
            line-height: 1.4;
            color: #231F20;
        }
        .cmp-kv-label {
            font-weight: 700;
            color: #475569;
        }
        .cmp-kv-val {
            font-weight: 600;
            color: #1e293b;
            word-break: break-word;
        }
        .cmp-allergy-Dynamic-box {
            margin-top: 12px;
            padding: 9px 12px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            font-size: 0.88rem;
            color: #92400e;
        }
        .cmp-cutoff-callout {
            margin-top: 14px;
            padding: 10px 14px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            text-align: center;
        }

        /* Recipient Name Inline Pill Label */
        .cmp-recipient-label-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #EEF7E3;
            color: #1B5E20;
            border: 1px solid #68B946;
            padding: 2px 9px;
            border-radius: 999px;
            font-size: 0.82em;
            font-weight: 700;
            line-height: 1.3;
            white-space: nowrap;
        }

        /* Tablet Optimization for Account & Logistics (769px - 1024px) */
        @media (min-width: 769px) and (max-width: 1024px) {
            .cmp-info-drawer-body { padding: 16px; }
            .cmp-info-cards-grid { gap: 14px; }
            .cmp-account-card-box, .cmp-logistics-card-box { padding: 14px; }
            .cmp-kv-grid { grid-template-columns: 88px minmax(0, 1fr); font-size: 0.86rem; row-gap: 5px; }
        }

        /* Mobile Drawer Behavior (<= 768px) */
        @media (max-width: 768px) {
            .cmp-mobile-info-toggle {
                display: flex !important;
            }
            .cmp-info-drawer-wrap:not(.is-drawer-open) .cmp-info-drawer-body {
                display: none !important;
            }
            .cmp-info-drawer-wrap.is-drawer-open .cmp-mobile-info-toggle {
                border-bottom-color: #DCECC9;
            }
            .cmp-info-drawer-body {
                padding: 12px;
            }
            .cmp-info-cards-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 12px;
            }
            .cmp-account-card-box, .cmp-logistics-card-box {
                padding: 12px;
            }
            .cmp-kv-grid {
                grid-template-columns: 84px minmax(0, 1fr);
                font-size: 0.84rem;
                row-gap: 5px;
            }
            .cmp-cutoff-callout {
                margin-top: 10px;
                padding: 8px 10px;
                font-size: 0.82rem;
            }
        }

        /* =========================================================
           3. SCHEDULE UTILITY & KEYBOARD FAST-ENTRY TOOLBAR (OPTION 1A)
           ========================================================= */
        .cmp-schedule-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: #f8fafc;
            border: 1.5px solid #DCECC9;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .cmp-kbd-guide {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.82rem;
            color: #334155;
            font-weight: 600;
        }
        .cmp-kbd-key {
            display: inline-block;
            background: #ffffff;
            color: #1B5E20;
            border: 1px solid #cbd5e1;
            border-bottom: 2px solid #94a3b8;
            border-radius: 5px;
            padding: 2px 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.76rem;
            font-weight: 800;
        }
        .cmp-toolbar-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .cmp-util-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            background: #ffffff;
            color: #1B5E20;
            border: 1.5px solid #68B946;
            border-radius: 8px;
            padding: 7px 13px;
            font-size: 0.82rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.18s ease;
            white-space: nowrap;
        }
        .cmp-util-btn:hover {
            background: #EEF7E3;
            border-color: #379237;
        }
        .cmp-util-btn.primary-util {
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
            color: #ffffff;
            border-color: #1B5E20;
            box-shadow: 0 3px 10px rgba(27, 94, 32, 0.18);
        }
        .cmp-util-btn.primary-util:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
        }
        .cmp-pending-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.22);
            color: #ffffff;
            border: 1px solid rgba(220, 236, 201, 0.55);
            border-radius: 999px;
            padding: 1px 8px;
            font-size: 0.72rem;
            font-weight: 800;
            margin-left: 2px;
        }

        /* Pulse Highlight When Scrolling to Pending Row */
        @keyframes cmpPendingPulse {
            0%   { box-shadow: 0 0 0 0 rgba(55, 146, 55, 0.55); }
            50%  { box-shadow: 0 0 0 10px rgba(55, 146, 55, 0); }
            100% { box-shadow: 0 0 0 0 rgba(55, 146, 55, 0); }
        }
        .cmp-table tr.cmp-day-row.cmp-pulse-target {
            animation: cmpPendingPulse 1.1s ease-out 2;
            border-color: #379237 !important;
        }

        /* =========================================================
           UNSAVED CHANGES ("DIRTY STATE") UI/UX & PULSE ANIMATION
           ========================================================= */
        @keyframes cmpSavePulse {
            0%   { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.65), 0 4px 10px rgba(217, 119, 6, 0.28); transform: scale(1); }
            50%  { box-shadow: 0 0 0 8px rgba(245, 158, 11, 0), 0 6px 15px rgba(217, 119, 6, 0.42); transform: scale(1.02); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0), 0 4px 10px rgba(217, 119, 6, 0.28); transform: scale(1); }
        }
        .cmp-table tr.cmp-day-row.is-unsaved {
            background: #fffbeb !important;
            border-color: #f59e0b !important;
            border-left: 6px solid #d97706 !important;
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.16) !important;
        }
        .cmp-table tr.cmp-day-row.is-unsaved td.cmp-cell-day {
            background: #fef3c7 !important;
            color: #b45309 !important;
            border-color: #f59e0b !important;
        }
        .cmp-table tr.cmp-day-row.is-unsaved .cmp-save-row:not(:disabled) {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
            color: #ffffff !important;
            border: 1.5px solid #fde68a !important;
            animation: cmpSavePulse 1.75s infinite ease-in-out !important;
        }
        .cmp-table tr.cmp-day-row.is-unsaved .cmp-save-row:hover:not(:disabled) {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%) !important;
            filter: brightness(1.04);
        }
        .cmp-status-pill.pill-unsaved {
            color: #92400e !important;
            background: #fef3c7 !important;
            border: 1.5px solid #f59e0b !important;
        }
        .cmp-status-pill.pill-meals-pending {
            color: #9a3412 !important;
            background: #ffedd5 !important;
            border: 1.5px solid #fdba74 !important;
        }
        .cmp-undo-row {
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 5px !important;
            height: 48px !important;
            padding: 0 13px !important;
            border-radius: 10px !important;
            border: 1.5px solid #cbd5e1 !important;
            background: #ffffff !important;
            color: #334155 !important;
            font-size: 13px !important;
            font-weight: 800 !important;
            cursor: pointer !important;
            white-space: nowrap !important;
            transition: all 0.18s ease !important;
            flex-shrink: 0 !important;
        }
        .cmp-undo-row:hover {
            background: #f1f5f9 !important;
            color: #0f172a !important;
            border-color: #94a3b8 !important;
        }
        .cmp-table tr.cmp-day-row.is-unsaved .cmp-undo-row {
            display: inline-flex !important;
        }
        .cmp-table tr.cmp-day-row.is-unsaved .cmp-secondary-actions {
            display: none !important;
        }
        .cmp-table tr.cmp-day-row.status-void td.cmp-cell-day {
            cursor: default !important;
        }
        .cmp-table tr.cmp-day-row.status-void td.cmp-cell-day::after {
            display: none !important;
        }
        .cmp-table tr.cmp-day-row.status-void td.cmp-collapsed-summary {
            background: #fff1f2 !important;
            border: 1px dashed #fda4af !important;
            color: #9f1239 !important;
            cursor: default !important;
        }
        .cmp-table tr.cmp-day-row.status-void td.cmp-collapsed-summary .cmp-expand-hint {
            display: none !important;
        }
        .cmp-unsaved-float-bar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            color: #ffffff;
            border: 1.5px solid #f59e0b;
            border-radius: 12px;
            padding: 8px 16px;
            display: none;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.32);
            z-index: 9998;
            font-size: 0.84rem;
            font-weight: 700;
            max-width: 92vw;
            flex-wrap: nowrap;
            white-space: nowrap;
            justify-content: center;
        }
        .cmp-unsaved-float-bar.is-active {
            display: inline-flex !important;
        }
        .cmp-unsaved-float-bar .cmp-float-btn {
            background: #f59e0b;
            color: #0f172a;
            border: none;
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 0.78rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .cmp-unsaved-float-bar .cmp-float-btn:hover {
            background: #fbbf24;
        }
        .cmp-unsaved-float-bar .cmp-float-btn.cmp-float-undo-all {
            background: transparent;
            color: #fde68a;
            border: 1px solid rgba(253, 230, 138, 0.5);
        }
        .cmp-unsaved-float-bar .cmp-float-btn.cmp-float-undo-all:hover {
            background: rgba(253, 230, 138, 0.15);
            color: #ffffff;
        }

        /* =========================================================
           4. UNIFIED STACKED DAY CARD LAYOUT & POS CHECK BADGES
           ========================================================= */
        .cmp-table-responsive {
            overflow: visible !important;
            background: transparent !important;
            border: none !important;
            width: 100%;
            box-shadow: none !important;
        }
        .cmp-table { width: 100% !important; display: block !important; border-collapse: separate; }
        .cmp-table thead { display: none !important; }
        .cmp-table tbody { display: block !important; width: 100% !important; }

        /* Day Card Container */
        .cmp-table tr.cmp-day-row {
            margin-bottom: 16px !important;
            border-radius: 16px !important;
            padding: 16px 20px !important;
            background: #ffffff !important;
            border: 2px solid #dbe3ec !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04) !important;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
        }
        .cmp-table tr.cmp-day-row.status-pending:not(.is-unsaved) { border-color: #fde68a !important; }
        .cmp-table tr.cmp-day-row.status-saved:not(.is-unsaved) { border-color: #bbf7d0 !important; }
        .cmp-table tr.cmp-day-row.status-void { border-color: #fecdd3 !important; opacity: 0.82; }

        /* Active Keyboard Focus Highlight on Day Card */
        .cmp-table tr.cmp-day-row:focus-within:not(.is-unsaved) {
            border-color: #379237 !important;
            box-shadow: 0 0 0 4px rgba(104, 185, 70, 0.22), 0 10px 25px rgba(27, 94, 32, 0.10) !important;
        }

        /* Field Micro-Label inside each Card Box */
        .cmp-mobile-label {
            display: block !important;
            font-weight: 800 !important;
            color: #1B5E20 !important;
            margin-bottom: 6px !important;
            font-size: 0.78rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
        }

        /* Full-Visibility Mobile-Style Dropdowns & Inputs Across Desktop, Tablet & Mobile */
        .cmp-table select,
        .cmp-table input[type="text"],
        .cmp-table input[type="date"] {
            -webkit-appearance: none !important;
            appearance: none !important;
            width: 100% !important;
            min-height: 48px !important;
            padding: 10px 38px 10px 14px !important;
            font-size: 14.5px !important;
            line-height: 1.35 !important;
            background-color: #f8fafc !important;
            border: 2px solid #cbd5e1 !important;
            border-radius: 10px !important;
            box-shadow: 0 2px 0 #cbd5e1 !important;
            font-weight: 700 !important;
            color: #231F20 !important;
            margin-bottom: 0 !important;
            box-sizing: border-box !important;
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }
        .cmp-table select {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23379237' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e") !important;
            background-repeat: no-repeat !important;
            background-position: right 12px center !important;
            background-size: 17px !important;
            cursor: pointer;
        }
        .cmp-table select:focus,
        .cmp-table input[type="text"]:focus,
        .cmp-table input[type="date"]:focus {
            background-color: #ffffff !important;
            border-color: #1B5E20 !important;
            box-shadow: 0 0 0 3.5px rgba(55, 146, 55, 0.32) !important;
            outline: none !important;
        }
        .cmp-table select:disabled,
        .cmp-table input[type="text"]:disabled,
        .cmp-table input[type="date"]:disabled {
            background-color: #f1f5f9 !important;
            color: #64748b !important;
            border-color: #e2e8f0 !important;
            box-shadow: none !important;
            cursor: not-allowed !important;
        }
        .cmp-table input[type="date"],
        .cmp-table input[type="text"] {
            padding-right: 12px !important;
            background-image: none !important;
        }

        /* Symmetrical 2-Column Snacks Grid */
        .cmp-snacks-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 14px !important;
            width: 100% !important;
        }
        .cmp-snacks-grid.single-snack {
            grid-template-columns: minmax(0, 1fr) !important;
        }
        .cmp-snack-slot {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
        }

        /* Status Box & Read-Only POS Check Indicator (FOH, Menu Manager & Admin Only) */
        .cmp-status-box {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 4px !important;
            width: 100% !important;
            min-height: 48px !important;
            padding: 5px 8px !important;
            background: #f8fafc !important;
            border: 1.5px solid #e2e8f0 !important;
            border-radius: 10px !important;
            box-sizing: border-box !important;
            text-align: center !important;
            overflow: hidden !important;
        }
        .cmp-status-pill {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-width: 92px !important;
            max-width: 100% !important;
            padding: 4px 12px !important;
            border-radius: 999px !important;
            font-size: 0.81rem !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
            white-space: nowrap !important;
            text-align: center !important;
            box-sizing: border-box !important;
        }
        .cmp-status-pill.pill-pending { color: #92400e; background: #fffbeb; border: 1px solid #fde68a; }
        .cmp-status-pill.pill-confirmed { color: #166534; background: #f0fdf4; border: 1px solid #86efac; }
        .cmp-status-pill.pill-successful { color: #166534; background: #dcfce7; border: 1px solid #86efac; }
        .cmp-status-pill.pill-void { color: #9f1239; background: #fff1f2; border: 1px solid #fda4af; }
        .cmp-status-pill.pill-paused { color: #b45309; background: #fef3c7; border: 1px solid #fde68a; }

        /* Read-Only POS Check Badge (Visible only to FOH, Menu Manager, Admin) */
        .cmp-pos-readonly-pill {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 4px !important;
            padding: 2px 8px !important;
            border-radius: 999px !important;
            font-size: 0.67rem !important;
            font-weight: 800 !important;
            line-height: 1.15 !important;
            letter-spacing: 0.02em !important;
            white-space: nowrap !important;
        }
        .cmp-pos-readonly-pill.pos-is-checked {
            background: #ccfbf1 !important;
            color: #0f766e !important;
            border: 1px solid #5eead4 !important;
        }
        .cmp-pos-readonly-pill.pos-is-pending {
            background: #f1f5f9 !important;
            color: #64748b !important;
            border: 1px solid #cbd5e1 !important;
        }

        /* Action Buttons */
        .cmp-action-box {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 8px !important;
            flex-wrap: nowrap !important;
            width: 100% !important;
        }
        .cmp-save-row {
            min-width: 132px !important;
            width: auto !important;
            flex: 1 1 auto !important;
            min-height: 48px !important;
            padding: 10px 14px !important;
            font-size: 14px !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
            white-space: nowrap !important;
            border-radius: 10px !important;
            border: none !important;
            color: #ffffff !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 4px 10px rgba(27, 94, 32, 0.18) !important;
        }
        .cmp-save-row:hover:not(:disabled) { transform: translateY(-1px); filter: brightness(1.06); }
        .cmp-save-row:focus { outline: none !important; box-shadow: 0 0 0 3.5px rgba(55, 146, 55, 0.4) !important; }
        .cmp-save-row:disabled { opacity: 0.7; cursor: not-allowed !important; transform: none !important; animation: none !important; }

        .cmp-secondary-actions {
            display: inline-flex !important;
            gap: 6px !important;
            flex-shrink: 0 !important;
        }
        .cmp-clear-row,
        .cmp-cancel-day-row {
            width: 44px !important;
            height: 48px !important;
            padding: 0 !important;
            border-radius: 10px !important;
            border: none !important;
            color: #fff !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.2s ease !important;
            flex-shrink: 0 !important;
        }
        .cmp-clear-row { background: #f59e0b !important; }
        .cmp-clear-row:hover:not(:disabled) { background: #d97706 !important; }
        .cmp-cancel-day-row { background: #dc2626 !important; }
        .cmp-cancel-day-row:hover:not(:disabled) { background: #b91c1c !important; }

        /* Colourful Macro Tiles Inside the Box */
        .cmp-macro-display {
            display: block !important;
            width: 100% !important;
            padding: 10px 12px !important;
            background: #f8fafc !important;
            border: 1.5px solid #e2e8f0 !important;
            border-radius: 12px !important;
            box-sizing: border-box !important;
        }
        .cmp-macro-chef-tag {
            display: inline-block;
            background: #EEF7E3;
            color: #1B5E20;
            border: 1px solid #68B946;
            font-size: 0.73rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .cmp-macro-tiles-grid {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 8px !important;
            width: 100% !important;
        }
        .cmp-macro-tile {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 7px 6px !important;
            border-radius: 10px !important;
            text-align: center !important;
            box-sizing: border-box !important;
        }
        .cmp-macro-tile .tile-label {
            font-size: 0.66rem !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            opacity: 0.88 !important;
            margin-bottom: 2px !important;
            line-height: 1.1 !important;
        }
        .cmp-macro-tile .tile-val {
            font-size: 0.95rem !important;
            font-weight: 900 !important;
            line-height: 1.2 !important;
            white-space: nowrap !important;
        }
        .cmp-macro-tile.tile-cal {
            background: linear-gradient(145deg, #fffbeb 0%, #fef3c7 100%) !important;
            border: 1.5px solid #fcd34d !important;
            color: #b45309 !important;
        }
        .cmp-macro-tile.tile-pro {
            background: linear-gradient(145deg, #ecfdf5 0%, #d1fae5 100%) !important;
            border: 1.5px solid #6ee7b7 !important;
            color: #047857 !important;
        }
        .cmp-macro-tile.tile-carb {
            background: linear-gradient(145deg, #f0f9ff 0%, #e0f2fe 100%) !important;
            border: 1.5px solid #7dd3fc !important;
            color: #0369a1 !important;
        }
        .cmp-macro-tile.tile-fat {
            background: linear-gradient(145deg, #faf5ff 0%, #f3e8ff 100%) !important;
            border: 1.5px solid #d8b4fe !important;
            color: #6d28d9 !important;
        }
        .cmp-macro-banner-state {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.86rem;
            text-align: center;
        }

        /* Chef's Choice Box Styling */
        .cmp-chef-box {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-height: 48px !important;
            padding: 6px 12px !important;
            background: #f8fafc !important;
            border: 2px solid #cbd5e1 !important;
            border-radius: 10px !important;
            gap: 9px !important;
            cursor: pointer !important;
            transition: all 0.18s ease;
        }
        .cmp-chef-box:focus-within {
            background: #ffffff !important;
            border-color: #1B5E20 !important;
            box-shadow: 0 0 0 3.5px rgba(55, 146, 55, 0.32) !important;
        }
        .cmp-chefs-choice {
            width: 20px !important;
            height: 20px !important;
            accent-color: #379237 !important;
            cursor: pointer !important;
            margin: 0 !important;
        }

        /* Auto-Hide Empty Dropdowns When Chef's Choice is Checked */
        .cmp-table tr.cmp-day-row.is-chefs-choice-compact td.cmp-cell-meal,
        .cmp-table tr.cmp-day-row.is-chefs-choice-compact td.cmp-cell-snack,
        .cmp-table tr.cmp-day-row.is-chefs-choice-compact td.cmp-cell-juice {
            display: none !important;
        }
        .cmp-table tr.cmp-day-row.is-chefs-choice-compact td.cmp-cell-macros .cmp-mobile-label {
            display: none !important;
        }

        /* Collapsed Summary Bar for Cards on Desktop/Tablet — Strictly Hidden Until Collapsed */
        .cmp-table td.cmp-collapsed-summary {
            display: none !important;
            grid-column: 1 / span 12 !important;
            background: #f8fafc !important;
            border: 1px dashed #cbd5e1 !important;
            border-radius: 10px !important;
            padding: 9px 14px !important;
            margin-top: 4px !important;
            font-size: 0.85rem !important;
            color: #334155 !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 10px !important;
        }
        .cmp-table td.cmp-collapsed-summary:hover {
            background: #EEF7E3 !important;
            border-color: #68B946 !important;
            color: #1B5E20 !important;
        }

        /* DESKTOP & TABLET CARD GRID LAYOUT (> 768px) */
        @media (min-width: 769px) {
            .cmp-mobile-status,
            .cmp-mobile-date-badge,
            .cmp-mobile-pos-holder,
            .cmp-mobile-meal-preview {
                display: none !important;
            }
            .cmp-day-header-top {
                display: contents !important;
            }

            .cmp-table tr.cmp-day-row {
                display: grid !important;
                grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
                gap: 12px 14px !important;
                align-items: end !important;
            }
            .cmp-table td:not(.cmp-collapsed-summary) {
                display: block !important;
                padding: 0 !important;
                border: none !important;
                width: 100% !important;
            }

            /* Top Row of Day Card: Day Title (2 cols) | Date (3 cols) | Chef's Choice (2 cols) | Status Box (2 cols) | Action Buttons (3 cols) */
            .cmp-table td.cmp-cell-day {
                grid-column: 1 / span 2 !important;
                grid-row: 1 !important;
                align-self: stretch !important;
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                background: #EEF7E3 !important;
                color: #1B5E20 !important;
                border: 1.5px solid #DCECC9 !important;
                border-radius: 12px !important;
                font-size: 1.08rem !important;
                font-weight: 800 !important;
                padding: 10px 14px !important;
                cursor: pointer !important;
                user-select: none;
                transition: filter 0.15s ease;
            }
            .cmp-table td.cmp-cell-day:hover { filter: brightness(0.97); }
            .cmp-table td.cmp-cell-day::after {
                content: '\25B2';
                font-size: 0.72rem;
                opacity: 0.7;
            }
            .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-day::after {
                content: '\25BC';
            }

            .cmp-table tr.status-pending td.cmp-cell-day { background: #fffbeb !important; color: #b45309 !important; border-color: #fde68a !important; }
            .cmp-table tr.status-void td.cmp-cell-day { background: #fff1f2 !important; color: #9f1239 !important; border-color: #fecdd3 !important; }

            .cmp-table td.cmp-cell-date { grid-column: 3 / span 3 !important; grid-row: 1 !important; }
            .cmp-table td.cmp-cell-chef { grid-column: 6 / span 2 !important; grid-row: 1 !important; }
            .cmp-table td.cmp-cell-status { grid-column: 8 / span 2 !important; grid-row: 1 !important; }
            .cmp-table td.cmp-cell-action { grid-column: 10 / span 3 !important; grid-row: 1 !important; }

            /* Divider line between Card Header Controls and Meal Dropdown Grid */
            .cmp-table td.cmp-cell-meal,
            .cmp-table td.cmp-cell-snack,
            .cmp-table td.cmp-cell-macros,
            .cmp-table td.cmp-cell-juice {
                padding-top: 10px !important;
                border-top: 1px dashed #e2e8f0 !important;
            }

            /* SYMMETRICAL 3 + 2 MEAL & SNACK GRID */
            .cmp-table td.cmp-cell-meal-1 { grid-column: 1 / span 4 !important; grid-row: 2 !important; }
            .cmp-table td.cmp-cell-meal-2 { grid-column: 5 / span 4 !important; grid-row: 2 !important; }
            .cmp-table td.cmp-cell-meal-3 { grid-column: 9 / span 4 !important; grid-row: 2 !important; }
            .cmp-table td.cmp-cell-snack  { grid-column: 1 / span 12 !important; grid-row: 3 !important; }
            .cmp-table td.cmp-cell-macros { grid-column: 1 / span 12 !important; grid-row: 4 !important; }

            /* Juice Plan Layout (3 equal columns) */
            .cmp-table td.cmp-cell-juice-1 { grid-column: 1 / span 4 !important; grid-row: 2 !important; }
            .cmp-table td.cmp-cell-juice-2 { grid-column: 5 / span 4 !important; grid-row: 2 !important; }
            .cmp-table td.cmp-cell-juice-3 { grid-column: 9 / span 4 !important; grid-row: 2 !important; }

            /* Desktop Collapsed State (Default for All Cards Until Expanded) */
            .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-meal,
            .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-snack,
            .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-macros,
            .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-juice {
                display: none !important;
            }
            .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-collapsed-summary {
                display: flex !important;
                grid-row: 2 !important;
            }
        }

        /* Tablet Adjustments (769px - 1024px) */
        @media (min-width: 769px) and (max-width: 1024px) {
            .cmp-table td.cmp-cell-day    { grid-column: 1 / span 3 !important; grid-row: 1 !important; }
            .cmp-table td.cmp-cell-date   { grid-column: 4 / span 5 !important; grid-row: 1 !important; }
            .cmp-table td.cmp-cell-chef   { grid-column: 9 / span 4 !important; grid-row: 1 !important; }
            .cmp-table td.cmp-cell-status { grid-column: 1 / span 5 !important; grid-row: 2 !important; }
            .cmp-table td.cmp-cell-action { grid-column: 6 / span 7 !important; grid-row: 2 !important; }

            .cmp-table td.cmp-cell-meal-1 { grid-column: 1 / span 4 !important; grid-row: 3 !important; }
            .cmp-table td.cmp-cell-meal-2 { grid-column: 5 / span 4 !important; grid-row: 3 !important; }
            .cmp-table td.cmp-cell-meal-3 { grid-column: 9 / span 4 !important; grid-row: 3 !important; }
            .cmp-table td.cmp-cell-snack  { grid-column: 1 / span 12 !important; grid-row: 4 !important; }
            .cmp-table td.cmp-cell-macros { grid-column: 1 / span 12 !important; grid-row: 5 !important; }

            .cmp-table td.cmp-cell-juice-1 { grid-column: 1 / span 4 !important; grid-row: 3 !important; }
            .cmp-table td.cmp-cell-juice-2 { grid-column: 5 / span 4 !important; grid-row: 3 !important; }
            .cmp-table td.cmp-cell-juice-3 { grid-column: 9 / span 4 !important; grid-row: 3 !important; }

            .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-collapsed-summary {
                grid-row: 3 !important;
            }
        }

        /* =========================================================
           5. MOBILE SINGLE-VIEWPORT ACCORDION & FLUSH FULL-BLEED
              TWO-LINE MICRO-CARD (OPTIONS 1A & 2A — <= 768px)
           ========================================================= */
        @media (max-width: 768px) {
            .cmp-dashboard-wrap { padding: 0 6px 86px 6px; }

            /* Option 1A: Hide Collapse All, Expand All & Keyboard Guide on Mobile; Make Go To Pending Full-Width */
            .cmp-kbd-guide,
            .cmp-collapse-all-btn,
            .cmp-expand-all-btn {
                display: none !important;
            }
            .cmp-schedule-toolbar {
                background: transparent !important;
                border: none !important;
                padding: 0 !important;
                margin-bottom: 12px !important;
            }
            .cmp-toolbar-actions {
                width: 100% !important;
            }
            .cmp-jump-pending-btn {
                width: 100% !important;
                padding: 11px 16px !important;
                font-size: 0.9rem !important;
                border-radius: 11px !important;
            }

            .cmp-table td.cmp-collapsed-summary { display: none !important; }
            .cmp-table td.cmp-cell-status { display: none !important; }

            /* Hide floating unsaved popup on mobile so it never overlaps the card buttons */
            .cmp-unsaved-float-bar,
            .cmp-unsaved-float-bar.is-active {
                display: none !important;
            }

            /* Option 2A: Flush Full-Bleed Collapsed Mobile Card (Color fills 100% of card edge-to-edge) */
            .cmp-table tr.cmp-day-row:not(.is-open) {
                display: block !important;
                width: 100% !important;
                margin-bottom: 10px !important;
                padding: 0 !important;
                border-radius: 13px !important;
                overflow: hidden !important;
                border-width: 1.5px !important;
            }
            .cmp-table tr.cmp-day-row:not(.is-open) td:not(.cmp-cell-day) {
                display: none !important;
            }
            .cmp-table tr.cmp-day-row:not(.is-open) td.cmp-cell-day {
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 10px 34px 10px 13px !important;
                border: none !important;
                border-radius: 0 !important;
                box-sizing: border-box !important;
                cursor: pointer !important;
                position: relative !important;
            }

            /* Expanded Mobile Card: 12-Column Compact Grid */
            .cmp-table tr.cmp-day-row.is-open {
                display: grid !important;
                grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
                gap: 4px 8px !important;
                align-items: end !important;
                padding: 9px 10px 10px 10px !important;
                margin-bottom: 12px !important;
                border-radius: 13px !important;
            }
            .cmp-table tr.cmp-day-row.is-open td:not(.cmp-collapsed-summary):not(.cmp-cell-status) {
                display: block !important;
                width: 100% !important;
                border: none !important;
                padding: 0 !important;
                text-align: left !important;
            }
            .cmp-table tr.cmp-day-row.is-open td.cmp-cell-day {
                grid-column: 1 / span 12 !important;
                display: flex !important;
                flex-direction: column !important;
                width: 100% !important;
                border-radius: 9px !important;
                padding: 8px 34px 8px 12px !important;
                margin-bottom: 2px !important;
                position: relative !important;
                cursor: pointer !important;
            }

            /* Top Row inside Mobile Day Banner (Day + Date on Left | Uniform Status Pill on Right) */
            .cmp-day-header-top {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                width: 100% !important;
                gap: 8px !important;
            }
            .cmp-day-title-group {
                display: inline-flex !important;
                align-items: center !important;
                gap: 6px !important;
                min-width: 0 !important;
                flex: 1 1 auto !important;
            }
            .cmp-day-num {
                font-size: 0.95rem !important;
                font-weight: 800 !important;
                white-space: nowrap !important;
            }
            .cmp-mobile-date-badge {
                display: none;
                font-size: 0.78rem !important;
                font-weight: 700 !important;
                opacity: 0.92 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }
            .cmp-mobile-date-badge.has-date {
                display: inline-block !important;
            }
            .cmp-day-right-group {
                display: inline-flex !important;
                align-items: center !important;
                gap: 5px !important;
                flex-shrink: 0 !important;
            }

            /* Uniform Equal-Width Status Pill on Mobile */
            .cmp-mobile-status {
                display: inline-flex !important;
                margin: 0 !important;
            }
            .cmp-mobile-status .cmp-status-pill {
                min-width: 84px !important;
                padding: 3px 9px !important;
                font-size: 0.71rem !important;
                font-weight: 800 !important;
                justify-content: center !important;
                text-align: center !important;
                border-radius: 999px !important;
            }

            /* Mobile Read-Only POS Micro-Pill (For FOH, Menu Manager, Admin) */
            .cmp-mobile-pos-holder {
                display: inline-flex !important;
                align-items: center !important;
            }
            .cmp-pos-micro-pill {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 2px 6px !important;
                border-radius: 6px !important;
                font-size: 0.63rem !important;
                font-weight: 800 !important;
                white-space: nowrap !important;
            }
            .cmp-pos-micro-pill.pos-is-checked {
                background: #ccfbf1 !important;
                color: #0f766e !important;
                border: 1px solid #5eead4 !important;
            }
            .cmp-pos-micro-pill.pos-is-pending {
                background: rgba(255, 255, 255, 0.75) !important;
                color: #475569 !important;
                border: 1px solid #cbd5e1 !important;
            }

            /* Second Micro-Line on Collapsed Card: 1-Line Truncated Meal Selection Preview */
            .cmp-mobile-meal-preview {
                display: none !important;
                width: 100% !important;
                margin-top: 4px !important;
                padding-top: 4px !important;
                border-top: 1px dashed rgba(15, 23, 42, 0.12) !important;
                font-size: 0.75rem !important;
                font-weight: 600 !important;
                line-height: 1.25 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                opacity: 0.92 !important;
            }
            .cmp-table tr.cmp-day-row:not(.is-open) .cmp-mobile-meal-preview.has-preview {
                display: block !important;
            }

            /* Full-Card Color Fills for Pending (#FFFBEB), Selected (#F0FDF4), and Cancelled (#FFF1F2) */
            .status-pending td.cmp-cell-day { background: #FFFBEB !important; color: #b45309 !important; }
            .status-saved td.cmp-cell-day { background: #F0FDF4 !important; color: #166534 !important; }
            .status-void td.cmp-cell-day { background: #FFF1F2 !important; color: #9f1239 !important; }

            .status-pending .cmp-mobile-status .cmp-status-pill.pill-pending {
                background: #fef3c7 !important;
                color: #92400e !important;
                border: 1px solid #f59e0b !important;
            }
            .status-saved .cmp-mobile-status .cmp-status-pill.pill-confirmed,
            .status-saved .cmp-mobile-status .cmp-status-pill.pill-successful {
                background: #dcfce7 !important;
                color: #166534 !important;
                border: 1px solid #4ade80 !important;
            }
            .status-void .cmp-mobile-status .cmp-status-pill.pill-void {
                background: #ffe4e6 !important;
                color: #9f1239 !important;
                border: 1px solid #fb7185 !important;
            }

            .cmp-table td.cmp-cell-day::after {
                content: '\25BC';
                position: absolute;
                right: 11px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 11px;
                color: inherit;
                opacity: 0.8;
            }
            .cmp-table tr.is-open td.cmp-cell-day::after { content: '\25B2'; }

            /* Respect Chef's Choice Compact Mode on Mobile When Checked */
            .cmp-table tr.cmp-day-row.is-open.is-chefs-choice-compact td.cmp-cell-meal,
            .cmp-table tr.cmp-day-row.is-open.is-chefs-choice-compact td.cmp-cell-snack,
            .cmp-table tr.cmp-day-row.is-open.is-chefs-choice-compact td.cmp-cell-juice {
                display: none !important;
            }

            /* Row 2: Date (7 Cols) + Chef's Choice (5 Cols) Side-by-Side */
            .cmp-table tr.cmp-day-row.is-open td.cmp-cell-date {
                grid-column: 1 / span 7 !important;
            }
            .cmp-table tr.cmp-day-row.is-open td.cmp-cell-chef {
                grid-column: 8 / span 5 !important;
            }

            /* Rows 3-5: Main Meals & Juices */
            .cmp-table tr.cmp-day-row.is-open td.cmp-cell-meal,
            .cmp-table tr.cmp-day-row.is-open td.cmp-cell-juice {
                grid-column: 1 / span 12 !important;
            }

            /* Row 6: Full-Width Snacks on Mobile so Long Snack Names Never Clip */
            .cmp-table tr.cmp-day-row.is-open td.cmp-cell-snack {
                grid-column: 1 / span 12 !important;
            }
            .cmp-snacks-grid,
            .cmp-snacks-grid.single-snack {
                grid-template-columns: minmax(0, 1fr) !important;
                gap: 4px !important;
            }

            /* Compact Mobile Micro-Labels & Auto-Height Selects */
            .cmp-mobile-label {
                font-size: 0.64rem !important;
                margin-bottom: 2px !important;
                line-height: 1.05 !important;
            }
            .cmp-table select {
                min-height: 35px !important;
                height: auto !important;
                padding: 6px 30px 6px 10px !important;
                font-size: 13px !important;
                line-height: 1.25 !important;
                border-radius: 8px !important;
                border-width: 1.5px !important;
                box-shadow: 0 1px 0 #cbd5e1 !important;
                background-position: right 9px center !important;
                background-size: 14px !important;
            }
            .cmp-table input[type="text"],
            .cmp-table input[type="date"] {
                min-height: 35px !important;
                height: 35px !important;
                padding: 4px 8px 4px 10px !important;
                font-size: 13px !important;
                line-height: 1.2 !important;
                border-radius: 8px !important;
                border-width: 1.5px !important;
                box-shadow: 0 1px 0 #cbd5e1 !important;
            }
            .cmp-chef-box {
                min-height: 35px !important;
                height: 35px !important;
                padding: 4px 8px !important;
                gap: 6px !important;
                border-radius: 8px !important;
                border-width: 1.5px !important;
            }
            .cmp-chef-box span {
                font-size: 0.75rem !important;
                white-space: nowrap !important;
            }
            .cmp-chefs-choice {
                width: 16px !important;
                height: 16px !important;
            }

            /* Row 7: Single-Row 4-Column Colourful Macro Strip */
            .cmp-table tr.cmp-day-row.is-open td.cmp-cell-macros {
                grid-column: 1 / span 12 !important;
                margin-top: 1px !important;
            }
            .cmp-table td.cmp-cell-macros > .cmp-mobile-label {
                display: none !important;
            }
            .cmp-macro-display {
                padding: 4px 5px !important;
                border-radius: 8px !important;
            }
            .cmp-macro-chef-tag {
                font-size: 0.62rem !important;
                padding: 1px 6px !important;
                margin-bottom: 3px !important;
            }
            .cmp-macro-tiles-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
                gap: 4px !important;
            }
            .cmp-macro-tile {
                padding: 3px 2px !important;
                border-radius: 6px !important;
            }
            .cmp-macro-tile .tile-label {
                font-size: 0.54rem !important;
                margin-bottom: 1px !important;
                letter-spacing: 0.02em !important;
            }
            .cmp-macro-tile .tile-val {
                font-size: 0.74rem !important;
            }
            .cmp-macro-banner-state {
                padding: 4px 8px !important;
                font-size: 0.74rem !important;
            }

            /* Row 8: Single-Row Inline Action Bar (Update + Reset + Cancel / Undo) */
            .cmp-table tr.cmp-day-row.is-open td.cmp-cell-action {
                grid-column: 1 / span 12 !important;
                margin-top: 2px !important;
            }
            .cmp-table td.cmp-cell-action > .cmp-mobile-label {
                display: none !important;
            }
            .cmp-action-box {
                flex-direction: row !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 6px !important;
            }
            .cmp-save-row {
                flex: 1 1 auto !important;
                width: auto !important;
                min-width: 0 !important;
                min-height: 38px !important;
                height: 38px !important;
                padding: 6px 12px !important;
                font-size: 13.5px !important;
                border-radius: 8px !important;
            }
            .cmp-secondary-actions {
                width: auto !important;
                display: inline-flex !important;
                gap: 6px !important;
                flex-shrink: 0 !important;
            }
            .cmp-clear-row, .cmp-cancel-day-row {
                flex: 0 0 38px !important;
                width: 38px !important;
                height: 38px !important;
                border-radius: 8px !important;
            }
            .cmp-undo-row {
                width: auto !important;
                height: 38px !important;
                padding: 0 11px !important;
                font-size: 12.5px !important;
                border-radius: 8px !important;
            }

            /* Non-Intrusive Floating Buttons on Mobile */
            .cmp-wa-float {
                bottom: 14px;
                right: 12px;
                width: 42px;
                height: 42px;
                padding: 0;
                justify-content: center;
                border-radius: 50%;
                opacity: 0.88;
            }
            .cmp-wa-float svg { width: 22px; height: 22px; }
            .cmp-wa-text { display: none; }
            .cmp-back-to-top {
                bottom: 62px;
                right: 14px;
                width: 36px;
                height: 36px;
                opacity: 0.85;
            }
            body.cmp-mobile-input-active .cmp-wa-float,
            body.cmp-mobile-input-active .cmp-back-to-top {
                opacity: 0.18 !important;
                pointer-events: none !important;
            }

            /* Prevent iOS Safari Auto-Zoom on Tap while preserving compact card height */
            @supports (-webkit-touch-callout: none) {
                .cmp-table select,
                .cmp-table input[type="text"],
                .cmp-table input[type="date"] {
                    font-size: 16px !important;
                }
            }
        }

        /* =========================================================
           6. RENEW / SUBSCRIBE CALLOUT & FEEDBACK SURVEY (OPTION 4A)
           ========================================================= */
        .cmp-renew-banner-card {
            margin-top: 22px;
            background: linear-gradient(135deg, #EEF7E3 0%, #DCECC9 100%);
            border: 1.5px solid #68B946;
            border-radius: 16px;
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.06);
        }
        .cmp-renew-banner-text h4 {
            margin: 0 0 4px 0 !important;
            color: #1B5E20 !important;
            font-size: 1.12rem !important;
            font-weight: 800 !important;
        }
        .cmp-renew-banner-text p {
            margin: 0 !important;
            color: #334155 !important;
            font-size: 0.88rem !important;
            font-weight: 600 !important;
        }
        .cmp-renew-cta-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important;
            color: #ffffff !important;
            text-decoration: none !important;
            padding: 11px 22px !important;
            border-radius: 10px !important;
            font-weight: 800 !important;
            font-size: 0.9rem !important;
            box-shadow: 0 4px 12px rgba(27, 94, 32, 0.22) !important;
            transition: all 0.2s ease !important;
            white-space: nowrap !important;
        }
        .cmp-renew-cta-btn:hover {
            filter: brightness(1.07) !important;
            transform: translateY(-1px) !important;
            color: #ffffff !important;
        }

        .cmp-feedback-card {
            margin-top: 18px;
            background: #ffffff;
            border: 1.5px solid #DCECC9;
            border-top: 4px solid #379237;
            border-radius: 16px;
            padding: 22px 24px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.04);
        }
        .cmp-feedback-header {
            margin-bottom: 16px;
        }
        .cmp-feedback-header h3 {
            margin: 0 0 4px 0 !important;
            color: #1B5E20 !important;
            font-size: 1.22rem !important;
            font-weight: 800 !important;
        }
        .cmp-feedback-header p {
            margin: 0 !important;
            color: #475569 !important;
            font-size: 0.88rem !important;
        }
        .cmp-feedback-ratings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 14px;
        }
        .cmp-rating-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
        }
        .cmp-rating-box-label {
            display: block;
            font-size: 0.84rem;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 8px;
        }
        .cmp-star-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .cmp-star-btn {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            width: 40px;
            height: 36px;
            font-size: 1.1rem;
            line-height: 1;
            color: #94a3b8;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            padding: 0;
        }
        .cmp-star-btn.is-active {
            background: #fffbeb;
            border-color: #f59e0b;
            color: #f59e0b;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(245, 158, 11, 0.2);
        }
        .cmp-rating-score-txt {
            margin-left: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            color: #64748b;
        }

        .cmp-feedback-tags-wrap {
            margin-bottom: 14px;
        }
        .cmp-feedback-tags-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 7px;
        }
        .cmp-feedback-tags-list {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }
        .cmp-feedback-tag-pill {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            padding: 5px 12px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .cmp-feedback-tag-pill.is-selected {
            background: #EEF7E3;
            color: #1B5E20;
            border-color: #379237;
            box-shadow: 0 2px 6px rgba(55, 146, 55, 0.15);
        }

        .cmp-feedback-textarea {
            width: 100%;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.88rem;
            color: #1e293b;
            background: #f8fafc;
            resize: vertical;
            min-height: 68px;
            font-family: inherit;
            margin-bottom: 12px;
        }
        .cmp-feedback-textarea:focus {
            background: #ffffff;
            border-color: #379237;
            outline: none;
            box-shadow: 0 0 0 3px rgba(104, 185, 70, 0.2);
        }

        .cmp-feedback-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding-top: 8px;
            border-top: 1px dashed #e2e8f0;
        }
        .cmp-identity-toggle-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.83rem;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
        }
        .cmp-identity-toggle-label input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: #379237;
            cursor: pointer;
            margin: 0;
        }
        .cmp-identity-status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            background: #EEF7E3;
            color: #1B5E20;
            border: 1px solid #68B946;
        }
        .cmp-identity-status-pill.is-anon {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }
        .cmp-submit-feedback-btn {
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-size: 0.88rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .cmp-submit-feedback-btn:hover:not(:disabled) {
            filter: brightness(1.06);
            transform: translateY(-1px);
        }
        .cmp-submit-feedback-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        @media (max-width: 768px) {
            .cmp-renew-banner-card {
                padding: 14px 16px;
                text-align: left;
            }
            .cmp-renew-cta-btn {
                width: 100% !important;
            }
            .cmp-feedback-card {
                padding: 16px 14px;
            }
            .cmp-feedback-ratings-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 10px;
            }
            .cmp-submit-feedback-btn {
                width: 100%;
            }
        }

        /* Floating WhatsApp & Small Back-to-Top Buttons */
        .cmp-wa-float {
            position: fixed; bottom: 30px; right: 30px; background-color: #25d366; color: white; border-radius: 50px;
            padding: 12px 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 12px rgba(37, 211, 102, 0.4);
            text-decoration: none; font-weight: bold; font-size: 16px; z-index: 9999; transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .cmp-wa-float:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(37, 211, 102, 0.5); color: white; }

        .cmp-back-to-top {
            position: fixed;
            bottom: 92px;
            right: 30px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
            color: #ffffff !important;
            border: 1.5px solid #DCECC9;
            box-shadow: 0 4px 12px rgba(27, 94, 32, 0.30);
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 9999;
            padding: 0;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
        }
        .cmp-back-to-top.is-visible {
            display: inline-flex !important;
        }
        .cmp-back-to-top:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(27, 94, 32, 0.42);
        }
    </style>

    <div class="cmp-dashboard-wrap">
        <div class="cmp-header-banner">
            <div>
                <h1>My Meal Plan Dashboard</h1>
                <p><?php echo esc_html($greeting_name); ?></p>
            </div>
            <div class="cmp-header-actions">
                <a href="<?php echo esc_url($subscribe_url); ?>" class="cmp-hdr-btn cmp-hdr-btn-subscribe">
                    <?php echo $icon_plus; ?>
                    <span>Subscribe to a New Meal Plan</span>
                </a>
                <a id="cmp-global-export-btn" href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_customer_csv&sub_id=' . $subs[0]->id)); ?>" class="cmp-hdr-btn cmp-hdr-btn-export">Export to Excel</a>
                <?php if(!$is_chef_override && !$is_admin_override): ?>
                    <a href="<?php echo esc_url( wp_logout_url(get_permalink()) ); ?>" class="cmp-hdr-btn cmp-hdr-btn-logout">Log Out</a>
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

            $order = function_exists('wc_get_order') ? wc_get_order($sub->wc_order_id) : false;
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
            $allergies_display = !empty($allergies) ? $allergies : 'No Allergies Recorded';
            $is_paused = ($sub->status === 'paused');

            // Build Dynamic Timing & Time Slot Option Lists (Preserves Custom Values)
            $timing_options = array_values( array_unique( array_merge(
                array( 'Deliver Day Before', 'Deliver Same Day' ),
                $custom_saved_timings,
                !empty($timing) ? array($timing) : array()
            ) ) );

            $slot_options = array_values( array_unique( array_merge(
                array(
                    '5:00 AM to 6:00 AM',
                    '6:00 AM to 7:00 AM',
                    '7:00 AM to 8:00 AM',
                    '8:00 AM to 9:00 AM',
                    '5:00 PM to 8:00 PM',
                ),
                $custom_saved_slots,
                !empty($time_slot) ? array($time_slot) : array()
            ) ) );

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

            <!-- OPTION 5A: COLLAPSIBLE ACCOUNT & DELIVERY DRAWER ON MOBILE / 2-COL GRID ON TABLET & DESKTOP -->
            <div class="cmp-info-drawer-wrap" id="info-drawer-<?php echo $sub->id; ?>">
                <button type="button" class="cmp-mobile-info-toggle" data-sub-id="<?php echo $sub->id; ?>" aria-expanded="false">
                    <div class="cmp-mobile-info-toggle-left">
                        <span class="cmp-mobile-info-kicker">Account &amp; Active Logistics</span>
                        <span class="cmp-mobile-info-summary-line" id="mobile-drawer-summary-<?php echo $sub->id; ?>">
                            <?php echo esc_html($timing_display . ' (' . $time_slot_display . ') • ' . $allergies_display); ?>
                        </span>
                    </div>
                    <span class="cmp-mobile-info-pill">
                        <span class="drawer-pill-txt">Details</span> <span class="drawer-pill-arrow">&#9660;</span>
                    </span>
                </button>

                <div class="cmp-info-drawer-body">
                    <div class="cmp-info-cards-grid">
                        <!-- Account Details Card -->
                        <div class="cmp-account-card-box">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <h3 style="margin:0; color:#379237; font-size:1.15em; font-weight:800;">Account Details</h3>
                                <?php if (current_user_can('manage_options')): ?>
                                    <button class="toggle-account-edit" data-sub-id="<?php echo $sub->id; ?>" style="background:none; border:none; color:#379237; cursor:pointer; font-size:0.85em; font-weight:bold; text-decoration:underline; padding:0;">Edit</button>
                                <?php endif; ?>
                            </div>

                            <div id="account-view-<?php echo $sub->id; ?>" class="cmp-kv-grid">
                                <div class="cmp-kv-label">Order ID:</div>
                                <div class="cmp-kv-val"><?php echo ($sub->wc_order_id > 0) ? '#' . esc_html($sub->wc_order_id) : 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT); ?></div>

                                <div class="cmp-kv-label">Name:</div>
                                <div class="cmp-kv-val" style="display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
                                    <span id="name-val-<?php echo $sub->id; ?>"><?php echo esc_html($display_name); ?></span>
                                    <span id="recipient-row-<?php echo $sub->id; ?>" class="cmp-recipient-label-badge" style="display:<?php echo !empty($recipient_name) ? 'inline-flex' : 'none'; ?>;">
                                        Recipient: <strong id="recipient-val-<?php echo $sub->id; ?>"><?php echo esc_html($recipient_name); ?></strong>
                                    </span>
                                </div>

                                <div class="cmp-kv-label">Email:</div>
                                <div class="cmp-kv-val"><?php echo esc_html($email); ?></div>

                                <div class="cmp-kv-label">Phone:</div>
                                <div class="cmp-kv-val"><?php echo esc_html($phone ?: 'N/A'); ?></div>

                                <div class="cmp-kv-label">Address:</div>
                                <div class="cmp-kv-val"><span id="address-val-<?php echo $sub->id; ?>"><?php echo esc_html($address); ?></span></div>
                            </div>

                            <?php if (current_user_can('manage_options')): ?>
                            <div id="account-edit-<?php echo $sub->id; ?>" style="display:none; margin-top:10px;">
                                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
                                    <div style="flex:1; min-width:120px;">
                                        <label style="font-size:0.82em; font-weight:bold; color:#334155;">First Name:</label>
                                        <input type="text" id="edit-fname-<?php echo $sub->id; ?>" value="<?php echo esc_attr($fname); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                    </div>
                                    <div style="flex:1; min-width:120px;">
                                        <label style="font-size:0.82em; font-weight:bold; color:#334155;">Last Name:</label>
                                        <input type="text" id="edit-lname-<?php echo $sub->id; ?>" value="<?php echo esc_attr($lname); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                    </div>
                                    <div style="flex:1; min-width:140px;">
                                        <label style="font-size:0.82em; font-weight:bold; color:#1B5E20;">Recipient Name:</label>
                                        <input type="text" id="edit-recipient-<?php echo $sub->id; ?>" value="<?php echo esc_attr($recipient_name); ?>" placeholder="e.g. Sarah" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #68B946; background:#EEF7E3; border-radius:6px; box-sizing:border-box;">
                                    </div>
                                </div>
                                <div style="margin-bottom:10px;">
                                    <label style="font-size:0.82em; font-weight:bold; color:#334155;">Full Address:</label>
                                    <input type="text" id="edit-address-<?php echo $sub->id; ?>" value="<?php echo esc_attr($address !== 'Address not provided' ? $address : ''); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                </div>
                                <button class="save-account-btn" data-sub-id="<?php echo $sub->id; ?>" data-user-id="<?php echo $sub->user_id; ?>" data-order-id="<?php echo $sub->wc_order_id; ?>" style="background:#379237; color:#fff; border:none; padding:9px 14px; border-radius:8px; font-size:0.86em; font-weight:bold; cursor:pointer; width:100%; transition:background 0.2s;">Save Account Details</button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Active Logistics Card (With Staff Hybrid Custom Timing & Slot Engine) -->
                        <div class="cmp-logistics-card-box">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <h3 style="margin:0; color:#1B5E20; font-size:1.15em; font-weight:800;">Active Logistics</h3>
                                <?php if ($can_edit_staff_logistics): ?>
                                    <button class="toggle-logistics-edit" data-sub-id="<?php echo $sub->id; ?>" style="background:none; border:none; color:#379237; cursor:pointer; font-size:0.85em; font-weight:bold; text-decoration:underline; padding:0;">Edit</button>
                                <?php endif; ?>
                            </div>

                            <div id="logistics-view-<?php echo $sub->id; ?>">
                                <div class="cmp-kv-grid">
                                    <div class="cmp-kv-label">Method:</div>
                                    <div class="cmp-kv-val"><?php echo esc_html($method_display); ?></div>

                                    <div class="cmp-kv-label">Receive By:</div>
                                    <div class="cmp-kv-val"><span id="timing-val-<?php echo $sub->id; ?>"><?php echo esc_html($timing_display); ?></span></div>

                                    <div class="cmp-kv-label">Time Slot:</div>
                                    <div class="cmp-kv-val"><span id="time-slot-val-<?php echo $sub->id; ?>"><?php echo esc_html($time_slot_display); ?></span></div>
                                </div>

                                <div class="cmp-allergy-Dynamic-box">
                                    <strong>Allergies:</strong> <span id="allergies-val-<?php echo $sub->id; ?>"><?php echo esc_html($allergies_display); ?></span>
                                </div>
                            </div>

                            <?php if ($can_edit_staff_logistics): ?>
                            <div id="logistics-edit-<?php echo $sub->id; ?>" style="display:none; margin-top:10px;">
                                <!-- Receive By (Delivery Timing) Hybrid Preset + Custom Selector -->
                                <div style="margin-bottom:10px;">
                                    <label style="font-size:0.82em; font-weight:bold; color:#334155; display:block; margin-bottom:4px;">Receive By (Delivery Timing):</label>
                                    <select id="edit-timing-<?php echo $sub->id; ?>" class="cmp-logistics-timing-select" data-sub-id="<?php echo $sub->id; ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                        <?php foreach ($timing_options as $t_opt): ?>
                                            <option value="<?php echo esc_attr($t_opt); ?>" <?php selected($timing, $t_opt); ?>><?php echo esc_html($t_opt); ?></option>
                                        <?php endforeach; ?>
                                        <option value="__custom__">+ Enter Custom Timing...</option>
                                    </select>
                                    <div id="custom-timing-wrap-<?php echo $sub->id; ?>" style="display:none; margin-top:6px; padding:8px; background:#EEF7E3; border:1px solid #68B946; border-radius:6px;">
                                        <input type="text" id="custom-timing-input-<?php echo $sub->id; ?>" placeholder="e.g. Deliver Evening Prior (9 PM)" style="width:100%; padding:6px 8px; font-size:0.88em; border:1px solid #cbd5e1; border-radius:5px; margin-bottom:5px;">
                                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.78em; color:#1B5E20; font-weight:700; cursor:pointer;">
                                            <input type="checkbox" id="save-custom-timing-<?php echo $sub->id; ?>" checked style="accent-color:#379237;">
                                            <span>Save this timing to dropdown presets for future use</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Time Slot Hybrid Preset + Custom Selector -->
                                <div style="margin-bottom:10px;">
                                    <label style="font-size:0.82em; font-weight:bold; color:#334155; display:block; margin-bottom:4px;">Time Slot:</label>
                                    <select id="edit-timeslot-<?php echo $sub->id; ?>" class="cmp-logistics-slot-select" data-sub-id="<?php echo $sub->id; ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                        <?php foreach ($slot_options as $s_opt): ?>
                                            <option value="<?php echo esc_attr($s_opt); ?>" <?php selected($time_slot, $s_opt); ?>><?php echo esc_html($s_opt); ?></option>
                                        <?php endforeach; ?>
                                        <option value="__custom__">+ Enter Custom Time Slot...</option>
                                    </select>
                                    <div id="custom-slot-wrap-<?php echo $sub->id; ?>" style="display:none; margin-top:6px; padding:8px; background:#EEF7E3; border:1px solid #68B946; border-radius:6px;">
                                        <input type="text" id="custom-slot-input-<?php echo $sub->id; ?>" placeholder="e.g. 10:00 AM to 12:00 PM" style="width:100%; padding:6px 8px; font-size:0.88em; border:1px solid #cbd5e1; border-radius:5px; margin-bottom:5px;">
                                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.78em; color:#1B5E20; font-weight:700; cursor:pointer;">
                                            <input type="checkbox" id="save-custom-slot-<?php echo $sub->id; ?>" checked style="accent-color:#379237;">
                                            <span>Save this time slot to dropdown presets for future use</span>
                                        </label>
                                    </div>
                                </div>

                                <div style="margin-bottom:10px;">
                                    <label style="font-size:0.82em; font-weight:bold; color:#334155; display:block; margin-bottom:4px;">Allergies:</label>
                                    <textarea id="edit-allergies-<?php echo $sub->id; ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;"><?php echo esc_textarea($allergies); ?></textarea>
                                </div>
                                <button class="save-logistics-btn" data-sub-id="<?php echo $sub->id; ?>" data-user-id="<?php echo $sub->user_id; ?>" data-order-id="<?php echo $sub->wc_order_id; ?>" style="background:#379237; color:#fff; border:none; padding:9px 14px; border-radius:8px; font-size:0.86em; font-weight:bold; cursor:pointer; width:100%; transition:background 0.2s;">Save Logistics Changes</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cmp-cutoff-callout">
                        <p style="color: <?php echo $note_color; ?>; font-size: 0.88em; font-weight: 700; margin: 0;"><em><?php echo $note_text; ?></em></p>
                    </div>
                </div>
            </div>

            <?php if ($is_paused): ?>
            <div class="cmp-no-print" style="background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:15px; margin-bottom:20px; text-align:center;">
                <strong style="color:#c2410c; font-size:1.1em;">This Meal Plan is Currently Paused</strong>
                <p style="color:#9a3412; margin:5px 0 0 0; font-size:0.9em;">Your deliveries have been temporarily halted. Please contact support to resume your plan.</p>
            </div>
            <?php endif; ?>

            <!-- PHASE 6: PLAN DAYS CONTROLS (GOVERNED BY WP ADMIN -> MEAL SETTINGS) -->
            <?php if ( cmp_can_adjust_plan_days() ): ?>
            <div class="cmp-no-print" style="background:#fffbdd; border:1px solid #eab308; border-radius:8px; padding:15px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <strong style="color:#b45309; font-size:1.1em;">Plan Days Controls</strong><br>
                    <span style="color:#92400e; font-size:0.9em;">Current Quota: <strong><?php echo $sub->total_days; ?> Days</strong></span>
                </div>
                <div style="display:flex; gap:10px;">
                    <button class="admin-adjust-days-btn" data-sub-id="<?php echo $sub->id; ?>" data-operation="add" style="background:#379237; color:#fff; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; transition:0.2s;">+ Add Extra Day</button>
                    <button class="admin-adjust-days-btn" data-sub-id="<?php echo $sub->id; ?>" data-operation="remove" style="background:#dc2626; color:#fff; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; transition:0.2s;">- Remove Last Day</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- DESKTOP KEYBOARD FAST-ENTRY & MOBILE SMART PENDING BAR (OPTION 1A) -->
            <div class="cmp-schedule-toolbar cmp-no-print">
                <div class="cmp-kbd-guide">
                    <?php echo $icon_kbd; ?>
                    <strong>Keyboard Fast-Entry:</strong>
                    <span><kbd class="cmp-kbd-key">Tab</kbd> Next Field</span>
                    <span><kbd class="cmp-kbd-key">Alt</kbd>+<kbd class="cmp-kbd-key">C</kbd> Toggle <?php echo esc_html($label_chefs_choice); ?></span>
                    <span><kbd class="cmp-kbd-key">Enter</kbd> / <kbd class="cmp-kbd-key">Ctrl+Enter</kbd> Save &amp; Next Day</span>
                    <span><kbd class="cmp-kbd-key">Alt</kbd>+<kbd class="cmp-kbd-key">Up/Down</kbd> Switch Day</span>
                </div>
                <div class="cmp-toolbar-actions">
                    <button type="button" class="cmp-util-btn primary-util cmp-jump-pending-btn" title="Scroll directly to the first pending meal selection and open it">
                        <?php echo $icon_target; ?>
                        <span>Go to Pending Selection</span>
                        <span class="cmp-pending-count-badge">...</span>
                    </button>
                    <button type="button" class="cmp-util-btn cmp-collapse-all-btn">Collapse All</button>
                    <button type="button" class="cmp-util-btn cmp-expand-all-btn">Expand All</button>
                </div>
            </div>

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
                            $pos_updated = ($log && !empty($log->pos_updated)) ? 1 : 0;

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
                            
                            // Strict Lock Evaluation: Past dates, Today, and Post-Cutoff Tomorrow
                            $is_time_locked = false;
                            if ($log && !empty($log->target_date)) {
                                if ( cmp_is_date_locked_for_customer($log->target_date) || $log->delivery_result === 'Successful' ) {
                                    $is_time_locked = true;
                                }
                            }

                            $base_input_disabled = ($is_void || (!$can_bypass_date_lock && ($is_time_locked || $is_paused))) ? 'disabled' : '';
                            
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
                            
                            // Keep all Day Cards collapsed by default on Desktop/Tablet
                            $row_status_class .= ' is-collapsed-desktop';

                            // Keep Chef's Choice rows without assigned meals in compact mode when expanded
                            if ($saved_chefs_choice && !$is_chef_override && !$is_chef_assigned) {
                                $row_status_class .= ' is-chefs-choice-compact';
                            }

                            if ($log && !$is_void && !empty($log->target_date)) {
                                $next_day_calc = date('Y-m-d', strtotime('+1 day', strtotime($log->target_date)));
                                if (empty($active_min_date) || $next_day_calc > $active_min_date) {
                                    if (!$can_bypass_date_lock) {
                                        $active_min_date = $next_day_calc;
                                    }
                                }
                            }

                            // Status Badge ("Confirmed" renamed to "Selected")
                            $status_badge = '<span class="cmp-status-pill pill-pending">Pending</span>';
                            if ($log && $log->delivery_result === 'Successful') {
                                $status_badge = '<span class="cmp-status-pill pill-successful">Successful</span>';
                            } elseif ($is_void && $log) {
                                $status_badge = '<span class="cmp-status-pill pill-void">' . esc_html($log->delivery_result) . '</span>';
                            } elseif ($is_paused) {
                                $status_badge = '<span class="cmp-status-pill pill-paused">Paused</span>';
                            } elseif ($log_id > 0 && !$saved_chefs_choice && !$is_chef_assigned) {
                                $status_badge = '<span class="cmp-status-pill pill-meals-pending">Meals Pending</span>';
                            } elseif ($log_id > 0) {
                                $status_badge = '<span class="cmp-status-pill pill-confirmed">Selected</span>';
                            }

                            // Read-Only POS Check Badges for FOH, Menu Manager & Admin
                            $desktop_pos_badge = '';
                            $mobile_pos_badge  = '';
                            if ( $can_view_pos_check && $log_id > 0 && !$is_void ) {
                                if ( $pos_updated ) {
                                    $desktop_pos_badge = '<span class="cmp-pos-readonly-pill pos-is-checked" title="POS Reconciled">✓ POS Checked</span>';
                                    $mobile_pos_badge  = '<span class="cmp-pos-micro-pill pos-is-checked" title="POS Checked">POS ✓</span>';
                                } else {
                                    $desktop_pos_badge = '<span class="cmp-pos-readonly-pill pos-is-pending" title="Pending POS Check">POS Pending</span>';
                                    $mobile_pos_badge  = '<span class="cmp-pos-micro-pill pos-is-pending" title="Pending POS Check">POS •</span>';
                                }
                            }

                            // Formatted Mobile Date Preview
                            $formatted_mobile_date = '';
                            if ( $log && !empty($log->target_date) && $log->target_date !== '0000-00-00' ) {
                                $formatted_mobile_date = '• ' . date('D, d M', strtotime($log->target_date));
                            }

                            $default_macro_tiles = '
                                <div class="cmp-macro-tiles-grid">
                                    <div class="cmp-macro-tile tile-cal"><span class="tile-label">Calories</span><span class="tile-val">0 kcal</span></div>
                                    <div class="cmp-macro-tile tile-pro"><span class="tile-label">Protein</span><span class="tile-val">0g</span></div>
                                    <div class="cmp-macro-tile tile-carb"><span class="tile-label">Carbs</span><span class="tile-val">0g</span></div>
                                    <div class="cmp-macro-tile tile-fat"><span class="tile-label">Fat</span><span class="tile-val">0g</span></div>
                                </div>';
                            
                            $min_attr = (!empty($active_min_date) && !$can_bypass_date_lock) ? 'min="' . esc_attr($active_min_date) . '"' : '';

                        ?>
                            <tr class="cmp-day-row <?php echo esc_attr($row_status_class); ?>" data-row="<?php echo $i; ?>" data-pos-updated="<?php echo esc_attr($pos_updated); ?>" data-is-flexible="<?php echo $row_is_flexible; ?>" data-allow-custom="<?php echo $row_allow_custom; ?>">
                                <td class="cmp-cell-day" title="Click to Expand / Collapse Day <?php echo $i; ?>">
                                    <div class="cmp-day-header-top">
                                        <div class="cmp-day-title-group">
                                            <strong class="cmp-day-num">Day <?php echo $i; ?></strong>
                                            <span class="cmp-mobile-date-badge <?php echo !empty($formatted_mobile_date) ? 'has-date' : ''; ?>"><?php echo esc_html($formatted_mobile_date); ?></span>
                                        </div>
                                        <div class="cmp-day-right-group">
                                            <?php if ($can_view_pos_check): ?>
                                                <span class="cmp-mobile-pos-holder"><?php echo $mobile_pos_badge; ?></span>
                                            <?php endif; ?>
                                            <span class="cmp-mobile-status"><?php echo $status_badge; ?></span>
                                        </div>
                                    </div>
                                    <div class="cmp-mobile-meal-preview"></div>
                                </td>
                                <td class="cmp-cell-date">
                                    <span class="cmp-mobile-label">Date</span>
                                    <input type="date" class="cmp-date-picker" data-row="<?php echo $i; ?>" <?php echo $min_attr; ?> value="<?php echo $log ? esc_attr($log->target_date) : ''; ?>" <?php echo $date_picker_disabled; ?>>
                                </td>
                                <td class="cmp-cell-chef">
                                    <span class="cmp-mobile-label"><?php echo esc_html($label_chefs_choice); ?></span>
                                    <label class="cmp-chef-box" title="Shortcut: Alt + C">
                                        <input type="checkbox" class="cmp-chefs-choice" data-row="<?php echo $i; ?>" <?php echo $chefs_choice_checkbox_disabled; ?> <?php if($saved_chefs_choice) echo 'checked'; ?>>
                                        <span style="font-size:0.86rem; font-weight:700; color:#231F20;"><?php echo esc_html($label_chefs_choice); ?></span>
                                    </label>
                                </td>
                                
                                <?php if(!$is_juice): 
                                    $main_cats = [
                                        'Breakfast' => ['slot' => 'breakfast', 'idx' => 1, 'label' => $row_is_flexible ? 'Meal 1' : 'Breakfast'],
                                        'Lunch'     => ['slot' => 'lunch',     'idx' => 2, 'label' => $row_is_flexible ? 'Meal 2' : 'Lunch'],
                                        'Dinner'    => ['slot' => 'dinner',    'idx' => 3, 'label' => $row_is_flexible ? 'Meal 3' : 'Dinner']
                                    ];
                                ?>
                                    <?php foreach($main_cats as $cat => $data): ?>
                                        <td class="cmp-cell-meal cmp-cell-meal-<?php echo $data['idx']; ?>">
                                            <span class="cmp-mobile-label"><?php echo esc_html($data['label']); ?></span>
                                            <?php if($row_allow_custom): ?>
                                                <input type="text" class="cmp-meal-custom cmp-main-meal" data-slot="<?php echo $data['slot']; ?>" data-row="<?php echo $i; ?>" value="<?php echo esc_attr($log ? $log->{'custom_'.$data['slot']} : ''); ?>" placeholder="Type <?php echo esc_attr($data['label']); ?>..." <?php echo $meal_select_disabled; ?>>
                                            <?php else: ?>
                                                <select class="cmp-meal-select cmp-main-meal" data-cat="<?php echo $row_is_flexible ? 'Any' : $cat; ?>" data-slot="<?php echo $data['slot']; ?>" data-row="<?php echo $i; ?>" data-saved-val="<?php echo $log ? $log->{$data['slot'].'_id'} : ''; ?>" <?php echo $meal_select_disabled; ?>>
                                                    <option value="">- Select <?php echo esc_html($data['label']); ?> -</option>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    
                                    <?php if($snack_quota > 0): ?>
                                    <td class="cmp-cell-snack">
                                        <div class="cmp-snacks-grid <?php echo ($snack_quota == 1) ? 'single-snack' : ''; ?>">
                                            <div class="cmp-snack-slot">
                                                <span class="cmp-mobile-label"><?php echo ($snack_quota == 2) ? 'Snack 1' : 'Snack'; ?></span>
                                                <?php if($row_allow_custom): ?>
                                                    <input type="text" class="cmp-meal-custom cmp-snack-1" data-slot="snack_1" data-row="<?php echo $i; ?>" value="<?php echo esc_attr($log ? $log->custom_snack_1 : ''); ?>" placeholder="Type Snack 1..." <?php echo $meal_select_disabled; ?>>
                                                <?php else: ?>
                                                    <select class="cmp-meal-select cmp-snack-1" data-cat="Snacks" data-slot="snack_1" data-row="<?php echo $i; ?>" data-saved-val="<?php echo $log ? $log->snack_1_id : ''; ?>" <?php echo $meal_select_disabled; ?>>
                                                        <option value="">- Select Snack 1 -</option>
                                                    </select>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($snack_quota == 2): ?>
                                            <div class="cmp-snack-slot">
                                                <span class="cmp-mobile-label">Snack 2</span>
                                                <?php if($row_allow_custom): ?>
                                                    <input type="text" class="cmp-meal-custom cmp-snack-2" data-slot="snack_2" data-row="<?php echo $i; ?>" value="<?php echo esc_attr($log ? $log->custom_snack_2 : ''); ?>" placeholder="Type Snack 2..." <?php echo $meal_select_disabled; ?>>
                                                <?php else: ?>
                                                    <select class="cmp-meal-select cmp-snack-2" data-cat="Snacks" data-slot="snack_2" data-row="<?php echo $i; ?>" data-saved-val="<?php echo $log ? $log->snack_2_id : ''; ?>" <?php echo $meal_select_disabled; ?>>
                                                        <option value="">- Select Snack 2 -</option>
                                                    </select>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                    
                                    <td class="cmp-cell-macros">
                                        <span class="cmp-mobile-label">Daily Macros</span>
                                        <div class="cmp-macro-display" id="macros-row-<?php echo $i; ?>">
                                            <?php echo $default_macro_tiles; ?>
                                        </div>
                                    </td>

                                <?php else: ?>
                                    <?php for($j=1; $j<=3; $j++): ?>
                                        <td class="cmp-cell-juice cmp-cell-juice-<?php echo $j; ?>">
                                            <span class="cmp-mobile-label">Juice <?php echo $j; ?></span>
                                            <?php if($row_allow_custom): ?>
                                                <input type="text" class="cmp-meal-custom cmp-juice-<?php echo $j; ?>" data-slot="juice_<?php echo $j; ?>" data-row="<?php echo $i; ?>" value="<?php echo esc_attr($log ? $log->{'custom_juice_'.$j} : ''); ?>" placeholder="Type Juice <?php echo $j; ?>..." <?php echo $meal_select_disabled; ?>>
                                            <?php else: ?>
                                                <select class="cmp-meal-select cmp-juice-<?php echo $j; ?>" data-cat="Juices" data-slot="juice_<?php echo $j; ?>" data-row="<?php echo $i; ?>" data-saved-val="<?php echo $log ? $log->{'juice_'.$j.'_id'} : ''; ?>" <?php echo $meal_select_disabled; ?>>
                                                    <option value="">- Select Juice <?php echo $j; ?> -</option>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                    <?php endfor; ?>
                                <?php endif; ?>
                                
                                <td class="cmp-cell-status">
                                    <span class="cmp-mobile-label">Status</span>
                                    <div class="cmp-status-box">
                                        <span class="cmp-desktop-status-holder"><?php echo $status_badge; ?></span>
                                        <?php if ($can_view_pos_check): ?>
                                            <span class="cmp-desktop-pos-holder"><?php echo $desktop_pos_badge; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="cmp-cell-action">
                                    <span class="cmp-mobile-label">Action</span>
                                    <div class="cmp-action-box">
                                        <?php 
                                        if ($is_chef_override) {
                                            if ($saved_chefs_choice) {
                                                $chef_btn_text = ($is_chef_assigned) ? 'Update' : 'Save';
                                                $chef_btn_color = ($is_chef_assigned) ? '#1B5E20' : '#379237';
                                                echo '<button class="cmp-save-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" data-row="'.$i.'" style="background:'.$chef_btn_color.';">'.$chef_btn_text.'</button>';
                                                echo '<button type="button" class="cmp-undo-row" data-row="'.$i.'" title="Discard unsaved changes and restore previous selection (Shortcut: Esc)">'.$icon_undo.' <span>Undo</span></button>';
                                                if ($log_id > 0) {
                                                    echo '<div class="cmp-secondary-actions">';
                                                    echo '<button class="cmp-clear-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" title="Reset / Clear Selection">'.$icon_reset.'</button>';
                                                    echo '</div>';
                                                }
                                            } else {
                                                echo '<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center; width:100%; padding:10px 0;">Locked (Customer Pick)</span>';
                                            }
                                        } else {
                                            if ((!$is_time_locked || $can_bypass_date_lock) && !$is_void && (!$is_paused || $can_bypass_date_lock)) {
                                                $btn_txt = ($log_id > 0) ? 'Update' : 'Save';
                                                $btn_color = ($log_id > 0) ? '#1B5E20' : '#379237';
                                                echo '<button class="cmp-save-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" data-row="'.$i.'" style="background:'.$btn_color.';">'.$btn_txt.'</button>';
                                                echo '<button type="button" class="cmp-undo-row" data-row="'.$i.'" title="Discard unsaved changes and restore previous selection (Shortcut: Esc)">'.$icon_undo.' <span>Undo</span></button>';
                                                
                                                if ($log_id > 0) {
                                                    echo '<div class="cmp-secondary-actions">';
                                                    echo '<button class="cmp-clear-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" title="Reset Selection">'.$icon_reset.'</button>';
                                                    echo '<button class="cmp-cancel-day-row" data-sub-id="'.$sub->id.'" data-log-id="'.$log_id.'" title="Cancel Day">'.$icon_cancel.'</button>';
                                                    echo '</div>';
                                                }
                                            } else {
                                                $lock_reason = 'Cutoff Passed';
                                                if ($is_paused) {
                                                    $lock_reason = 'Paused';
                                                } elseif ($is_void) {
                                                    $lock_reason = 'Void';
                                                } elseif ($log && !empty($log->target_date) && $log->target_date <= $today_ymd) {
                                                    $lock_reason = ($log->target_date === $today_ymd) ? 'Today Locked' : 'Past Date';
                                                }
                                                echo '<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center; width:100%; padding:10px 0;">Locked (' . esc_html($lock_reason) . ')</span>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>

                                <!-- Compact 1-Line Summary Bar (Visible When Card is Collapsed on Desktop/Tablet) -->
                                <td class="cmp-collapsed-summary" title="<?php echo $is_void ? 'This day was cancelled' : 'Click to expand meal selections'; ?>">
                                    <span class="cmp-summary-text">Click to view or edit meal selections</span>
                                    <span class="cmp-expand-hint" style="color:#379237; font-size:0.8rem; font-weight:800; white-space:nowrap;">Expand &#9660;</span>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <!-- CONTEXTUAL SUBSCRIBE / RENEW MEAL PLAN BANNER -->
            <div class="cmp-renew-banner-card cmp-no-print">
                <div class="cmp-renew-banner-text">
                    <h4>Ready for Your Next Cycle or Adding Another Plan?</h4>
                    <p>Easily renew your meal plan, switch your meal quota, or subscribe for a family member anytime.</p>
                </div>
                <a href="<?php echo esc_url($subscribe_url); ?>" class="cmp-renew-cta-btn">
                    <?php echo $icon_plus; ?>
                    <span>Subscribe to a New Meal Plan</span>
                </a>
            </div>

            <!-- OPTION 4A: CUSTOMER EXPERIENCE FEEDBACK SURVEY (WITH SMART IDENTITY TOGGLE) -->
            <div class="cmp-feedback-card cmp-no-print" data-sub-id="<?php echo esc_attr($sub->id); ?>">
                <div class="cmp-feedback-header">
                    <h3>Share Your Experience</h3>
                    <p>Take 15 seconds to let us know how we are doing with your meals and online portal experience.</p>
                </div>

                <div class="cmp-feedback-form-body" id="feedback-body-<?php echo esc_attr($sub->id); ?>">
                    <div class="cmp-feedback-ratings-grid">
                        <!-- Rating 1: Meal Plan & Delivery Service -->
                        <div class="cmp-rating-box">
                            <span class="cmp-rating-box-label">1. Meal Plan &amp; Delivery Service</span>
                            <div class="cmp-star-group" data-rating-type="service" data-sub-id="<?php echo esc_attr($sub->id); ?>" data-rating="0">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <button type="button" class="cmp-star-btn" data-val="<?php echo $s; ?>" aria-label="Rate <?php echo $s; ?> out of 5">&#9733;</button>
                                <?php endfor; ?>
                                <span class="cmp-rating-score-txt">Tap to rate</span>
                            </div>
                        </div>

                        <!-- Rating 2: Online Portal Experience -->
                        <div class="cmp-rating-box">
                            <span class="cmp-rating-box-label">2. Online Portal Experience</span>
                            <div class="cmp-star-group" data-rating-type="portal" data-sub-id="<?php echo esc_attr($sub->id); ?>" data-rating="0">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <button type="button" class="cmp-star-btn" data-val="<?php echo $s; ?>" aria-label="Rate <?php echo $s; ?> out of 5">&#9733;</button>
                                <?php endfor; ?>
                                <span class="cmp-rating-score-txt">Tap to rate</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick One-Tap Highlights -->
                    <div class="cmp-feedback-tags-wrap">
                        <span class="cmp-feedback-tags-label">Quick Highlights (Optional — tap any that apply):</span>
                        <div class="cmp-feedback-tags-list" id="feedback-tags-<?php echo esc_attr($sub->id); ?>">
                            <button type="button" class="cmp-feedback-tag-pill" data-tag="Fresh & Tasty Meals">Fresh &amp; Tasty Meals</button>
                            <button type="button" class="cmp-feedback-tag-pill" data-tag="Great Portions">Great Portions</button>
                            <button type="button" class="cmp-feedback-tag-pill" data-tag="On-Time Delivery">On-Time Delivery</button>
                            <button type="button" class="cmp-feedback-tag-pill" data-tag="Clean Packaging">Clean Packaging</button>
                            <button type="button" class="cmp-feedback-tag-pill" data-tag="Easy Portal Scheduling">Easy Portal Scheduling</button>
                            <button type="button" class="cmp-feedback-tag-pill" data-tag="Mobile Friendly">Mobile Friendly</button>
                            <button type="button" class="cmp-feedback-tag-pill" data-tag="More Menu Variety">More Menu Variety</button>
                        </div>
                    </div>

                    <!-- Optional Additional Comments -->
                    <textarea id="feedback-comments-<?php echo esc_attr($sub->id); ?>" class="cmp-feedback-textarea" placeholder="Optional: Share any suggestions, favorite dishes, or areas we can improve..."></textarea>

                    <!-- Smart Customer Details Toggle (Option 4A) & Submit Button -->
                    <div class="cmp-feedback-footer">
                        <label class="cmp-identity-toggle-label">
                            <input type="checkbox" class="cmp-feedback-identity-chk" id="feedback-identity-<?php echo esc_attr($sub->id); ?>" data-sub-id="<?php echo esc_attr($sub->id); ?>" checked>
                            <span>Include my account details (<strong><?php echo esc_html($display_name); ?></strong>) so the team can follow up</span>
                            <span class="cmp-identity-status-pill" id="feedback-id-pill-<?php echo esc_attr($sub->id); ?>">Details Included</span>
                        </label>

                        <button type="button" class="cmp-submit-feedback-btn" data-sub-id="<?php echo esc_attr($sub->id); ?>">
                            Submit Feedback
                        </button>
                    </div>
                </div>
            </div>

        </div>
        <?php endforeach; ?>

        <!-- Floating Unsaved Changes Helper Bar -->
        <div id="cmp-unsaved-float-bar" class="cmp-unsaved-float-bar cmp-no-print" role="status" aria-live="polite">
            <span id="cmp-unsaved-float-text">Unsaved changes</span>
            <button type="button" id="cmp-float-jump-btn" class="cmp-float-btn">Jump to Day</button>
            <button type="button" id="cmp-float-undo-all-btn" class="cmp-float-btn cmp-float-undo-all">Undo All</button>
        </div>

        <!-- Small Back to Top Button (Positioned Directly Above WhatsApp Floating Button) -->
        <button type="button" id="cmp-back-to-top" class="cmp-back-to-top cmp-no-print" title="Back to Top" aria-label="Back to Top">
            <?php echo $icon_up; ?>
        </button>
        
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
        // Privilege Flags dynamically injected from WP Admin -> Meal Settings
        var canBypassDateLock = <?php echo $can_bypass_date_lock ? 'true' : 'false'; ?>;
        var canBypassQuota    = <?php echo $can_bypass_quota ? 'true' : 'false'; ?>;
        var isChefOverride    = <?php echo $is_chef_override ? 'true' : 'false'; ?>;
        var canViewPosCheck   = <?php echo $can_view_pos_check ? 'true' : 'false'; ?>;
        var chefsChoiceLabel  = "<?php echo esc_js($label_chefs_choice); ?>";
        var blackoutDates     = <?php echo json_encode($blackout_dates_array); ?>;
        var customerMinDate   = "<?php echo esc_js($customer_min_date); ?>";
        var todayYmd          = "<?php echo esc_js($today_ymd); ?>";

        // Detect iPhone / iOS WebKit & Touch Devices for Native Date Picker Handling
        var isIOSOrTouch = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
                           (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) ||
                           ('ontouchstart' in window && $(window).width() <= 1024);

        // Helper: Format YYYY-MM-DD into compact "Mon, 26 Oct" for Mobile Collapsed Header
        var shortDays   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        var shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        function formatShortMobileDate(dateStr) {
            if (!dateStr || dateStr === '0000-00-00') return '';
            var d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return '';
            var dayName = shortDays[d.getDay()];
            var dayNum  = String(d.getDate()).padStart(2, '0');
            var monName = shortMonths[d.getMonth()];
            return '• ' + dayName + ', ' + dayNum + ' ' + monName;
        }

        // Helper: Update Pending Selection Counter Badge on "Go to Pending Selection" Button
        function updatePendingCounts($container) {
            var $targets = ($container && $container.length) ? $container : $('.cmp-portal-container');
            $targets.each(function() {
                var $c = $(this);
                var pendingCount = $c.find('tr.cmp-day-row.status-pending').length;
                var $badge = $c.find('.cmp-pending-count-badge');
                if ($badge.length) {
                    if (pendingCount > 0) {
                        $badge.text(pendingCount + ' Left');
                    } else {
                        $badge.text('All Selected');
                    }
                }
            });
        }

        // OPTION 5A: Mobile Account & Active Logistics Collapsible Drawer Toggle
        $(document).on('click', '.cmp-mobile-info-toggle', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $drawer = $btn.closest('.cmp-info-drawer-wrap');
            var willOpen = !$drawer.hasClass('is-drawer-open');
            $drawer.toggleClass('is-drawer-open', willOpen);
            $btn.attr('aria-expanded', willOpen ? 'true' : 'false');
            $btn.find('.drawer-pill-txt').text(willOpen ? 'Hide' : 'Details');
            $btn.find('.drawer-pill-arrow').html(willOpen ? '&#9650;' : '&#9660;');
        });

        // =========================================================
        // UNSAVED STATE ("DIRTY STATE") & BEFOREUNLOAD EXIT GUARD
        // =========================================================
        var hasUnsavedChanges   = false;
        var isIntentionalReload = false;

        function captureRowSnapshot(rowElement) {
            if (!rowElement || !rowElement.length) return;
            var mealsMap = {};
            rowElement.find('[data-slot]').each(function() {
                var slot = $(this).data('slot');
                mealsMap[slot] = ($(this).val() || '').toString().trim();
            });

            var saveBtn = rowElement.find('.cmp-save-row');
            var logId   = saveBtn.length ? (parseInt(saveBtn.data('log-id')) || 0) : 0;
            var currentBadgeHtml = rowElement.find('.cmp-desktop-status-holder').html();

            rowElement.data('cmp-snapshot', {
                date: (rowElement.find('.cmp-date-picker').val() || '').toString().trim(),
                chefsChoice: rowElement.find('.cmp-chefs-choice').is(':checked'),
                meals: mealsMap,
                logId: logId,
                btnText: logId > 0 ? 'Update' : 'Save',
                btnBg: logId > 0 ? '#1B5E20' : '#379237',
                statusBadgeHtml: currentBadgeHtml
            });
        }

        function isRowDifferentFromSnapshot(rowElement) {
            var snap = rowElement.data('cmp-snapshot');
            if (!snap) return true;

            var currDate  = (rowElement.find('.cmp-date-picker').val() || '').toString().trim();
            var currChef  = rowElement.find('.cmp-chefs-choice').is(':checked');

            if (currDate !== snap.date) return true;
            if (currChef !== snap.chefsChoice) return true;

            var isDiff = false;
            rowElement.find('[data-slot]').each(function() {
                var slot = $(this).data('slot');
                var currVal = ($(this).val() || '').toString().trim();
                var snapVal = (snap.meals && snap.meals[slot] !== undefined) ? snap.meals[slot] : '';
                if (currVal !== snapVal) {
                    isDiff = true;
                    return false;
                }
            });
            return isDiff;
        }

        function updateGlobalUnsavedState() {
            var $unsavedRows = $('tr.cmp-day-row.is-unsaved');
            hasUnsavedChanges = ($unsavedRows.length > 0);

            var $floatBar = $('#cmp-unsaved-float-bar');
            if ($unsavedRows.length > 0) {
                var dayLabels = [];
                $unsavedRows.each(function() {
                    dayLabels.push('Day ' + $(this).data('row'));
                });
                var labelStr = dayLabels.length <= 3
                    ? dayLabels.join(', ')
                    : dayLabels.slice(0, 3).join(', ') + ' (+' + (dayLabels.length - 3) + ' more)';
                $('#cmp-unsaved-float-text').text('⚠️ Unsaved changes on ' + labelStr);
                $floatBar.addClass('is-active');
            } else {
                $floatBar.removeClass('is-active');
            }
        }

        function restoreRowCleanVisuals(rowElement) {
            if (!rowElement || !rowElement.length) return;
            rowElement.removeClass('is-unsaved');

            var snap    = rowElement.data('cmp-snapshot');
            var saveBtn = rowElement.find('.cmp-save-row');
            if (saveBtn.length && !saveBtn.is(':disabled') && snap) {
                saveBtn.text(snap.btnText).css('background', snap.btnBg);
            }
            if (snap && snap.statusBadgeHtml) {
                rowElement.find('.cmp-desktop-status-holder').html(snap.statusBadgeHtml);
                rowElement.find('.cmp-mobile-status').html(snap.statusBadgeHtml);
            }

            syncRowCompactAndSummary(rowElement);
            updateGlobalUnsavedState();
        }

        function markRowUnsaved(rowElement) {
            if (!rowElement || !rowElement.length) return;
            var saveBtn = rowElement.find('.cmp-save-row');
            if (!saveBtn.length || saveBtn.is(':disabled')) return;

            rowElement.addClass('is-unsaved');
            saveBtn.text('Save Required!');

            var unsavedBadge = '<span class="cmp-status-pill pill-unsaved">Unsaved!</span>';
            rowElement.find('.cmp-desktop-status-holder').html(unsavedBadge);
            rowElement.find('.cmp-mobile-status').html(unsavedBadge);

            syncRowCompactAndSummary(rowElement);
            updateGlobalUnsavedState();
        }

        function evaluateRowDirtyState(rowElement) {
            if (!rowElement || !rowElement.length) return;
            if (isRowDifferentFromSnapshot(rowElement)) {
                markRowUnsaved(rowElement);
            } else {
                restoreRowCleanVisuals(rowElement);
            }
        }

        function restoreRowFromSnapshot(rowElement) {
            if (!rowElement || !rowElement.length) return;
            var snap = rowElement.data('cmp-snapshot');
            if (!snap) return;

            rowElement.find('.cmp-date-picker').val(snap.date);
            rowElement.find('.cmp-chefs-choice').prop('checked', snap.chefsChoice);

            rowElement.find('[data-slot]').each(function() {
                var slot = $(this).data('slot');
                var origVal = (snap.meals && snap.meals[slot] !== undefined) ? snap.meals[slot] : '';
                if ($(this).is('select')) {
                    $(this).data('saved-val', origVal);
                } else {
                    $(this).val(origVal);
                }
            });

            var hasFood = populateDynamicDropdowns(rowElement);
            if (hasFood) {
                rowElement.find('[data-slot]').each(function() {
                    var slot = $(this).data('slot');
                    var origVal = (snap.meals && snap.meals[slot] !== undefined) ? snap.meals[slot] : '';
                    $(this).val(origVal);
                });

                var inputs = rowElement.find('select, input.cmp-meal-custom');
                if (snap.chefsChoice && !isChefOverride) {
                    inputs.prop('disabled', true).removeClass('quota-locked');
                } else {
                    inputs.prop('disabled', false);
                }
                calculateMacros(rowElement);
                enforceMealQuota(rowElement);
            }

            if (!snap.date) {
                rowElement.addClass('is-collapsed-desktop');
            }
            restoreRowCleanVisuals(rowElement);
        }

        function markRowSaved(rowElement) {
            if (!rowElement || !rowElement.length) return;
            rowElement.removeClass('is-unsaved');
            updateGlobalUnsavedState();
        }

        window.addEventListener('beforeunload', function(e) {
            if (hasUnsavedChanges && !isIntentionalReload) {
                e.preventDefault();
                e.returnValue = 'You have unsaved meal selections. Are you sure you want to leave without saving?';
                return e.returnValue;
            }
        });

        // Small Back to Top Button Visibility & Scroll Action
        var $backToTopBtn = $('#cmp-back-to-top');
        $(window).on('scroll', function() {
            if ($(this).scrollTop() > 280) {
                $backToTopBtn.addClass('is-visible');
            } else {
                $backToTopBtn.removeClass('is-visible');
            }
        });
        $backToTopBtn.on('click', function(e) {
            e.preventDefault();
            $('html, body').stop(true).animate({ scrollTop: 0 }, 350);
        });

        // Helper: Ensure a candidate YYYY-MM-DD date is not a blackout date (advances to next non-blackout day)
        function ensureNonBlackoutDate(dateStr) {
            if (!dateStr) return '';
            var d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return dateStr;
            var safety = 0;
            while (safety < 60) {
                var yyyy = d.getFullYear();
                var mm   = String(d.getMonth() + 1).padStart(2, '0');
                var dd   = String(d.getDate()).padStart(2, '0');
                var candidate = yyyy + '-' + mm + '-' + dd;
                if (!blackoutDates.includes(candidate)) {
                    return candidate;
                }
                d.setDate(d.getDate() + 1);
                safety++;
            }
            return dateStr;
        }

        // Helper: Calculate next valid non-blackout YYYY-MM-DD date for min attribute enforcement
        function getNextValidDeliveryDate(baseDateStr) {
            var d = new Date(baseDateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return '';
            var safety = 0;
            do {
                d.setDate(d.getDate() + 1);
                var yyyy = d.getFullYear();
                var mm   = String(d.getMonth() + 1).padStart(2, '0');
                var dd   = String(d.getDate()).padStart(2, '0');
                var candidate = yyyy + '-' + mm + '-' + dd;
                if (!canBypassDateLock && customerMinDate && candidate < customerMinDate) {
                    continue;
                }
                if (!blackoutDates.includes(candidate)) {
                    return candidate;
                }
                safety++;
            } while (safety < 60);
            return '';
        }

        // Helper: Compute the earliest valid date for a specific row
        function getRowEarliestValidDate(rowInput) {
            var container = rowInput.closest('.cmp-portal-container');
            var rowNumber = parseInt(rowInput.data('row')) || 1;
            var candidate = (!canBypassDateLock && customerMinDate) ? customerMinDate : todayYmd;

            var minAttr = rowInput.attr('min');
            if (minAttr && minAttr > candidate) {
                candidate = minAttr;
            }

            if (rowNumber > 1 && container.length) {
                for (var r = rowNumber - 1; r >= 1; r--) {
                    var $prevInput = container.find('.cmp-date-picker[data-row="' + r + '"]');
                    var $prevRow   = $prevInput.closest('tr.cmp-day-row');
                    if ($prevInput.length && !$prevRow.hasClass('status-void') && $prevInput.val()) {
                        var nextAfterPrev = getNextValidDeliveryDate($prevInput.val());
                        if (nextAfterPrev && nextAfterPrev > candidate) {
                            candidate = nextAfterPrev;
                        }
                        break;
                    }
                }
            }

            return ensureNonBlackoutDate(candidate);
        }

        // Helper: Cascade min date constraints to subsequent rows and refresh row UI
        function applyValidDateToRow(rowInput, selectedDate) {
            var container = rowInput.closest('.cmp-portal-container');
            var rowNumber = parseInt(rowInput.data('row'));
            var totalDays = parseInt(container.data('total-days'));

            if (selectedDate) {
                var nextDateString = getNextValidDeliveryDate(selectedDate);
                for (var i = rowNumber + 1; i <= (totalDays + 10); i++) {
                    var targetInput = container.find('.cmp-date-picker[data-row="' + i + '"]');
                    if (targetInput.length) {
                        if (!canBypassDateLock && nextDateString) {
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

            var rowElement = rowInput.closest('tr');
            rowElement.removeClass('is-collapsed-desktop');
            var hasFood = populateDynamicDropdowns(rowElement);
            if (hasFood) {
                calculateMacros(rowElement);
                enforceMealQuota(rowElement);
            } else {
                syncRowCompactAndSummary(rowElement);
            }
            evaluateRowDirtyState(rowElement);
        }

        // Helper: Sync Chef's Choice Compact Mode, Desktop Summary Text & Mobile Two-Line Micro-Card Preview (Option 2A)
        function syncRowCompactAndSummary(rowElement) {
            var summaryTextEl   = rowElement.find('.cmp-summary-text');
            var mobileDateBadge = rowElement.find('.cmp-mobile-date-badge');
            var mobilePreviewEl = rowElement.find('.cmp-mobile-meal-preview');
            var dateVal         = (rowElement.find('.cmp-date-picker').val() || '').toString().trim();

            // Update Mobile Date Badge in Collapsed Header
            var shortDateStr = formatShortMobileDate(dateVal);
            if (mobileDateBadge.length) {
                if (shortDateStr) {
                    mobileDateBadge.text(shortDateStr).addClass('has-date');
                } else {
                    mobileDateBadge.text('').removeClass('has-date');
                }
            }

            if (rowElement.hasClass('status-void')) {
                if (summaryTextEl.length) {
                    summaryTextEl.html('<span>This delivery was cancelled &mdash; a replacement day has been added to your schedule</span>');
                }
                if (mobilePreviewEl.length) {
                    mobilePreviewEl.text('Cancelled — Replacement day added below').addClass('has-preview');
                }
                return;
            }

            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
            var isUnsaved     = rowElement.hasClass('is-unsaved');
            var saveBtn       = rowElement.find('.cmp-save-row');
            var logId         = saveBtn.length ? (parseInt(saveBtn.data('log-id')) || 0) : 0;
            var hasValues     = false;
            var pickedNames   = [];

            rowElement.find('select.cmp-meal-select, select[class^="cmp-juice-"]').each(function() {
                var val = $(this).val();
                if (val && val !== "") {
                    hasValues = true;
                    var txt = $(this).find('option:selected').text().trim();
                    if (txt) pickedNames.push(txt);
                }
            });
            rowElement.find('input.cmp-meal-custom').each(function() {
                var val = $(this).val();
                if (val && val.trim() !== "") {
                    hasValues = true;
                    pickedNames.push(val.trim());
                }
            });

            if (isChefsChoice && !isChefOverride && !hasValues) {
                rowElement.addClass('is-chefs-choice-compact');
            } else {
                rowElement.removeClass('is-chefs-choice-compact');
            }

            // Desktop Collapsed Summary Bar
            if (summaryTextEl.length) {
                var unsavedPrefix = isUnsaved ? '<strong style="color:#d97706;">[Unsaved Changes &mdash; Click Save Required! or Undo]</strong> ' : '';
                if (isChefsChoice && !hasValues) {
                    summaryTextEl.html(unsavedPrefix + '<strong style="color:#b45309;">' + chefsChoiceLabel + ' Selected</strong> &mdash; Click to expand');
                } else if (pickedNames.length > 0) {
                    summaryTextEl.html(unsavedPrefix + '<strong style="color:#1B5E20;">Selected Meals:</strong> ' + pickedNames.join('  &bull;  '));
                } else if (logId > 0 && !isUnsaved) {
                    summaryTextEl.html('<strong style="color:#c2410c;">Date Reserved</strong> &mdash; Click to expand and select meals');
                } else {
                    summaryTextEl.html(unsavedPrefix + 'Pending selection — Click to expand and choose meals');
                }
            }

            // Option 2A: Mobile Collapsed 1-Line Meal Preview (Zero Congestion)
            if (mobilePreviewEl.length) {
                if (isChefsChoice && !hasValues) {
                    mobilePreviewEl.text(chefsChoiceLabel + ' Selected').addClass('has-preview');
                } else if (pickedNames.length > 0) {
                    var prefix = isChefsChoice ? ('[' + chefsChoiceLabel + '] ') : '';
                    mobilePreviewEl.text(prefix + pickedNames.join(' • ')).addClass('has-preview');
                } else if (logId > 0 && !isUnsaved) {
                    mobilePreviewEl.text('Date Reserved — Tap to choose meals').addClass('has-preview');
                } else {
                    mobilePreviewEl.text('').removeClass('has-preview');
                }
            }
        }

        // =========================================================
        // NON-BLOCKING TOAST & INLINE CONFIRMATION MODAL HELPERS
        // =========================================================
        function cmpShowNotice(message, type) {
            $('.cmp-toast-notice').remove();
            var bg = (type === 'success') ? '#1B5E20' : '#dc2626';
            var border = (type === 'success') ? '#68B946' : '#fca5a5';
            var $toast = $('<div>', {
                'class': 'cmp-toast-notice',
                'text': message,
                'css': {
                    position: 'fixed',
                    top: '22px',
                    left: '50%',
                    transform: 'translateX(-50%)',
                    background: bg,
                    color: '#ffffff',
                    border: '1.5px solid ' + border,
                    borderRadius: '10px',
                    padding: '11px 20px',
                    fontSize: '0.88rem',
                    fontWeight: '800',
                    boxShadow: '0 10px 25px rgba(15, 23, 42, 0.25)',
                    zIndex: 100000,
                    maxWidth: '92vw',
                    textAlign: 'center'
                }
            }).appendTo('body');

            setTimeout(function() {
                $toast.fadeOut(250, function() { $(this).remove(); });
            }, 3600);
        }

        function cmpConfirmAction(message, onConfirmCallback) {
            $('.cmp-confirm-modal-backdrop').remove();
            var $backdrop = $('<div>', {
                'class': 'cmp-confirm-modal-backdrop',
                'css': {
                    position: 'fixed',
                    inset: '0',
                    background: 'rgba(15, 23, 42, 0.55)',
                    backdropFilter: 'blur(4px)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 100001,
                    padding: '16px'
                }
            });

            var $box = $('<div>', {
                'css': {
                    background: '#ffffff',
                    border: '2px solid #DCECC9',
                    borderTop: '5px solid #379237',
                    borderRadius: '14px',
                    maxWidth: '420px',
                    width: '100%',
                    padding: '22px 20px',
                    boxShadow: '0 20px 40px rgba(15, 23, 42, 0.25)',
                    textAlign: 'center'
                }
            });

            var $msg = $('<p>', {
                'text': message,
                'css': {
                    margin: '0 0 18px 0',
                    color: '#1e293b',
                    fontSize: '0.95rem',
                    fontWeight: '700',
                    lineHeight: '1.45',
                    whiteSpace: 'pre-line'
                }
            });

            var $btnRow = $('<div>', {
                'css': {
                    display: 'flex',
                    gap: '10px',
                    justifyContent: 'center'
                }
            });

            var $cancelBtn = $('<button>', {
                'type': 'button',
                'text': 'Cancel',
                'css': {
                    flex: '1',
                    padding: '10px 14px',
                    borderRadius: '8px',
                    border: '1.5px solid #cbd5e1',
                    background: '#f8fafc',
                    color: '#334155',
                    fontWeight: '800',
                    cursor: 'pointer'
                }
            }).on('click', function() {
                $backdrop.remove();
            });

            var $okBtn = $('<button>', {
                'type': 'button',
                'text': 'Confirm',
                'css': {
                    flex: '1',
                    padding: '10px 14px',
                    borderRadius: '8px',
                    border: 'none',
                    background: '#379237',
                    color: '#ffffff',
                    fontWeight: '800',
                    cursor: 'pointer'
                }
            }).on('click', function() {
                $backdrop.remove();
                if (typeof onConfirmCallback === 'function') {
                    onConfirmCallback();
                }
            });

            $btnRow.append($cancelBtn, $okBtn);
            $box.append($msg, $btnRow);
            $backdrop.append($box).appendTo('body');
        }

        // =========================================================
        // FOH / STAFF INLINE LOGISTICS EDIT + HYBRID CUSTOM TIMING
        // =========================================================
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

        // Reveal custom input when "+ Enter Custom Timing..." or "+ Enter Custom Time Slot..." is chosen
        $(document).on('change', '.cmp-logistics-timing-select', function() {
            var subId = $(this).data('sub-id');
            if ($(this).val() === '__custom__') {
                $('#custom-timing-wrap-' + subId).slideDown(150);
                $('#custom-timing-input-' + subId).focus();
            } else {
                $('#custom-timing-wrap-' + subId).slideUp(150);
            }
        });

        $(document).on('change', '.cmp-logistics-slot-select', function() {
            var subId = $(this).data('sub-id');
            if ($(this).val() === '__custom__') {
                $('#custom-slot-wrap-' + subId).slideDown(150);
                $('#custom-slot-input-' + subId).focus();
            } else {
                $('#custom-slot-wrap-' + subId).slideUp(150);
            }
        });

        $('.save-logistics-btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var subId = btn.data('sub-id');
            var userId = btn.data('user-id');
            var orderId = btn.data('order-id');

            var timingSelect = $('#edit-timing-' + subId);
            var slotSelect   = $('#edit-timeslot-' + subId);
            var isCustomTiming = (timingSelect.val() === '__custom__');
            var isCustomSlot   = (slotSelect.val() === '__custom__');

            var timing   = isCustomTiming ? ($('#custom-timing-input-' + subId).val() || '').trim() : timingSelect.val();
            var timeSlot = isCustomSlot   ? ($('#custom-slot-input-' + subId).val() || '').trim()   : slotSelect.val();
            var allergies = $('#edit-allergies-' + subId).val();
            var saveCustomTiming = isCustomTiming && $('#save-custom-timing-' + subId).is(':checked') ? 1 : 0;
            var saveCustomSlot   = isCustomSlot   && $('#save-custom-slot-' + subId).is(':checked')   ? 1 : 0;

            if (!timing) {
                cmpShowNotice('Please enter or select a valid Delivery Timing (Receive By) option.', 'error');
                return;
            }
            if (!timeSlot) {
                cmpShowNotice('Please enter or select a valid Time Slot option.', 'error');
                return;
            }

            var origText = btn.text();
            btn.text('Saving...').prop('disabled', true);

            var data = {
                action: 'cmp_cp_update_customer_logistics',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                user_id: userId,
                order_id: orderId,
                sub_id: subId,
                delivery_timing: timing,
                time_slot: timeSlot,
                allergies: allergies,
                save_custom_timing: saveCustomTiming,
                save_custom_slot: saveCustomSlot
            };

            $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(res) {
                if (res.success) {
                    $('#timing-val-' + subId).text(res.data.timing);
                    $('#time-slot-val-' + subId).text(res.data.time_slot);
                    $('#allergies-val-' + subId).text(res.data.allergies);
                    $('#mobile-drawer-summary-' + subId).text(res.data.timing + ' (' + res.data.time_slot + ') • ' + res.data.allergies);

                    // Inject new custom option into the select dropdown and select it
                    if (isCustomTiming) {
                        if (timingSelect.find('option[value="' + res.data.timing.replace(/"/g, '\\"') + '"]').length === 0) {
                            $('<option>', { value: res.data.timing, text: res.data.timing }).insertBefore(timingSelect.find('option[value="__custom__"]'));
                        }
                        timingSelect.val(res.data.timing);
                        $('#custom-timing-wrap-' + subId).hide();
                        $('#custom-timing-input-' + subId).val('');
                    }
                    if (isCustomSlot) {
                        if (slotSelect.find('option[value="' + res.data.time_slot.replace(/"/g, '\\"') + '"]').length === 0) {
                            $('<option>', { value: res.data.time_slot, text: res.data.time_slot }).insertBefore(slotSelect.find('option[value="__custom__"]'));
                        }
                        slotSelect.val(res.data.time_slot);
                        $('#custom-slot-wrap-' + subId).hide();
                        $('#custom-slot-input-' + subId).val('');
                    }

                    btn.text('✓ Saved').css('background', '#1B5E20');
                    cmpShowNotice('Logistics updated successfully.', 'success');

                    setTimeout(function() {
                        $('#logistics-edit-' + subId).hide();
                        $('#logistics-view-' + subId).show();
                        $('.toggle-logistics-edit[data-sub-id="' + subId + '"]').text('Edit');
                        btn.text(origText).css('background', '#379237').prop('disabled', false);
                    }, 900);
                } else {
                    cmpShowNotice('Error: ' + res.data, 'error');
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
                cmpShowNotice('First Name and Address are required.', 'error');
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
                        $('#recipient-row-' + subId).css('display', 'inline-flex');
                    } else {
                        $('#recipient-row-' + subId).hide();
                    }

                    if (res.data.plan_name) {
                        $('#tab-title-' + subId).text(res.data.plan_name);
                    }

                    btn.text('✓ Saved').css('background', '#1B5E20');
                    cmpShowNotice('Account details saved.', 'success');

                    setTimeout(function() {
                        $('#account-edit-' + subId).hide();
                        $('#account-view-' + subId).show();
                        $('.toggle-account-edit[data-sub-id="' + subId + '"]').text('Edit');
                        btn.text(origText).css('background', '#379237').prop('disabled', false);
                    }, 1000);
                } else {
                    cmpShowNotice('Error: ' + res.data, 'error');
                    btn.text(origText).prop('disabled', false);
                }
            });
        });

        // PLAN DAYS CONTROLS: ADD / REMOVE DAYS LOGIC
        $('.admin-adjust-days-btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var subId = btn.data('sub-id');
            var op = btn.data('operation');
            
            var confirmMsg = op === 'add' ? "Are you sure you want to ADD an extra day to this plan?" : "Are you sure you want to REMOVE a day from this plan?";
            cmpConfirmAction(confirmMsg, function() {
                var origText = btn.text();
                btn.text('Updating...').prop('disabled', true);

                $.post('<?php echo admin_url("admin-ajax.php"); ?>', {
                    action: 'cmp_cp_admin_adjust_days',
                    nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                    sub_id: subId,
                    operation: op
                }, function(res) {
                    if(res.success) {
                        isIntentionalReload = true;
                        location.reload();
                    } else {
                        cmpShowNotice('Error: ' + res.data, 'error');
                        btn.text(origText).prop('disabled', false);
                    }
                }).fail(function() {
                    cmpShowNotice('Connection error.', 'error');
                    btn.text(origText).prop('disabled', false);
                });
            });
        });

        function populateDynamicDropdowns(rowElement) {
            var isCustom = rowElement.data('allow-custom') == 1;
            var dateVal = rowElement.find('.cmp-date-picker').val();
            var selects = rowElement.find('select.cmp-meal-select, select[class^="cmp-juice-"]');
            var inputs = rowElement.find('input.cmp-meal-custom');
            var snacksGrid = rowElement.find('.cmp-snacks-grid');
            
            var chefChoiceCell = rowElement.find('.cmp-cell-chef');
            var macroCell = rowElement.find('.cmp-cell-macros');
            var btn = rowElement.find('.cmp-save-row');

            rowElement.find('.boundary-overlay').remove();
            rowElement.find('.date-prompt-overlay').remove();

            var selectContainer = rowElement.find('td.cmp-cell-meal, td.cmp-cell-snack, td.cmp-cell-juice');

            if (!dateVal) {
                selects.hide();
                inputs.hide();
                snacksGrid.hide();
                chefChoiceCell.find('.cmp-chef-box').hide();
                macroCell.css('opacity', '0.45'); 

                selectContainer.append('<div class="date-prompt-overlay" style="color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; font-size:0.82em; font-style:italic; text-align:center; padding:8px;">&larr; Pick a date first to view meals.</div>');
                return false;
            }

            macroCell.css('opacity', '1');
            
            if (isCustom) {
                inputs.show();
                snacksGrid.show();
                chefChoiceCell.find('.cmp-chef-box').show();
                if (btn.text() === 'Menu Pending') {
                    var btnTxt = rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') > 0 ? 'Update' : 'Save');
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
                snacksGrid.hide();
                chefChoiceCell.find('.cmp-chef-box').show(); 

                selectContainer.append('<div class="boundary-overlay" style="color:#b45309; font-weight:bold; font-size:0.88em; background:#fef3c7; border:1px solid #fde68a; padding:11px; border-radius:8px; text-align:center; line-height:1.4;">Menu Pending Release</div>');

                if (isChefOverride) {
                    btn.text('Menu Pending').css({'background':'#94a3b8', 'color':'#fff', 'cursor':'not-allowed'}).prop('disabled', true);
                } else {
                    if (btn.text() === 'Menu Pending') {
                        var btnTxt = rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') > 0 ? 'Update' : 'Save');
                        btn.text(btnTxt).css({'background':'#379237', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
                    }
                }
                return false;
            }

            selects.show();
            snacksGrid.show();
            chefChoiceCell.find('.cmp-chef-box').show();

            if (btn.text() === 'Menu Pending') {
                var btnTxt = rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') > 0 ? 'Update' : 'Save');
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

        // Fade floating buttons on mobile while actively interacting with inputs/dropdowns
        $(document).on('focusin', '.cmp-day-row select, .cmp-day-row input, .cmp-day-row button', function() {
            if ($(window).width() <= 768) {
                $('body').addClass('cmp-mobile-input-active');
            }
        });
        $(document).on('focusout', '.cmp-day-row select, .cmp-day-row input, .cmp-day-row button', function() {
            setTimeout(function() {
                if (!$(document.activeElement).closest('.cmp-day-row').length) {
                    $('body').removeClass('cmp-mobile-input-active');
                }
            }, 150);
        });

        // Card Expand / Collapse Toggle (Mobile Single-Viewport Accordion & Desktop Toggle)
        $('.cmp-day-row td.cmp-cell-day, .cmp-day-row td.cmp-collapsed-summary').on('click', function() {
            var $row = $(this).closest('tr.cmp-day-row');
            if ($row.hasClass('status-void')) {
                return;
            }
            if ($(window).width() <= 768) {
                var willOpen = !$row.hasClass('is-open');
                if (willOpen) {
                    // Close other open cards unless they have unsaved edits
                    $row.siblings('tr.cmp-day-row.is-open:not(.is-unsaved)').removeClass('is-open');
                    $row.addClass('is-open');
                    setTimeout(function() {
                        $('html, body').stop(true).animate({
                            scrollTop: Math.max(0, $row.offset().top - 10)
                        }, 220);
                    }, 40);
                } else {
                    $row.removeClass('is-open');
                    $('body').removeClass('cmp-mobile-input-active');
                }
            } else {
                $row.toggleClass('is-collapsed-desktop');
            }
        });

        // Toolbar Buttons: Collapse All / Expand All (Desktop & Tablet)
        $('.cmp-collapse-all-btn').on('click', function() {
            var $container = $(this).closest('.cmp-portal-container');
            $container.find('tr.cmp-day-row').addClass('is-collapsed-desktop').removeClass('is-open');
        });

        $('.cmp-expand-all-btn').on('click', function() {
            var $container = $(this).closest('.cmp-portal-container');
            $container.find('tr.cmp-day-row:not(.status-void)').removeClass('is-collapsed-desktop');
        });

        // Unified Colourful Macro Tiles Renderer (Zero Duplication on Mobile/Tablet/Desktop)
        function calculateMacros(rowElement) {
            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
            var macroDisplay = rowElement.find('.cmp-macro-display');
            if (!macroDisplay.length) {
                syncRowCompactAndSummary(rowElement);
                return;
            }

            var isCustom = rowElement.data('allow-custom') == 1;
            var selects = rowElement.find('select');
            var inputs = rowElement.find('input.cmp-meal-custom');

            var hasValues = false;
            selects.each(function() { if ($(this).val() !== "") hasValues = true; });
            inputs.each(function() { if ($(this).val().trim() !== "") hasValues = true; });

            if (isChefsChoice && !hasValues) {
                macroDisplay.html(
                    '<div class="cmp-macro-banner-state" style="background:#fffbeb; border:1px solid #fde68a; color:#b45309;">' +
                    '<strong>' + chefsChoiceLabel + ' Selected</strong> &mdash; Macros will appear once assigned by the Chef.' +
                    '</div>'
                );
                syncRowCompactAndSummary(rowElement);
                return;
            }

            if (isCustom) {
                macroDisplay.html(
                    '<div class="cmp-macro-banner-state" style="background:#EEF7E3; border:1px solid #DCECC9; color:#1B5E20;">' +
                    'Custom Meal Plan &mdash; Standard Macro Calculation N/A' +
                    '</div>'
                );
                syncRowCompactAndSummary(rowElement);
                return;
            }

            var cal = 0, fat = 0, carbs = 0, pro = 0;
            selects.each(function() {
                var selected = $(this).find('option:selected');
                if (selected.length > 0 && selected.val() !== "") {
                    cal   += parseFloat(selected.data('cal'))   || 0;
                    fat   += parseFloat(selected.data('fat'))   || 0;
                    carbs += parseFloat(selected.data('carbs')) || 0;
                    pro   += parseFloat(selected.data('pro'))   || 0;
                }
            });

            cal   = Math.round(cal);
            pro   = Math.round(pro * 10) / 10;
            carbs = Math.round(carbs * 10) / 10;
            fat   = Math.round(fat * 10) / 10;

            var chefPrefix = (isChefsChoice && hasValues)
                ? '<div class="cmp-macro-chef-tag">Chef Assigned</div>'
                : '';

            var tilesHtml = chefPrefix +
                '<div class="cmp-macro-tiles-grid">' +
                    '<div class="cmp-macro-tile tile-cal"><span class="tile-label">Calories</span><span class="tile-val">' + cal + ' kcal</span></div>' +
                    '<div class="cmp-macro-tile tile-pro"><span class="tile-label">Protein</span><span class="tile-val">' + pro + 'g</span></div>' +
                    '<div class="cmp-macro-tile tile-carb"><span class="tile-label">Carbs</span><span class="tile-val">' + carbs + 'g</span></div>' +
                    '<div class="cmp-macro-tile tile-fat"><span class="tile-label">Fat</span><span class="tile-val">' + fat + 'g</span></div>' +
                '</div>';

            macroDisplay.html(tilesHtml);
            syncRowCompactAndSummary(rowElement);
        }

        function enforceMealQuota(rowElement) {
            var container = rowElement.closest('.cmp-portal-container');
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var isJuice = container.data('is-juice') === 1;

            if (isJuice || allowedMeals <= 0) return; 

            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
            if (isChefsChoice && !isChefOverride) return; 

            var mainSelects = rowElement.find('.cmp-main-meal');
            if (mainSelects.first().is(':disabled') && !mainSelects.first().hasClass('quota-locked') && !canBypassDateLock) {
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

        // Initial page load population (does not trigger unsaved state)
        $('.cmp-day-row').each(function() {
            var $row = $(this);
            var hasFood = populateDynamicDropdowns($row);
            if (hasFood) {
                calculateMacros($row);
                enforceMealQuota($row);
            } else {
                syncRowCompactAndSummary($row);
            }
            captureRowSnapshot($row);
        });
        updatePendingCounts();

        // Trigger smart dirty-state comparison & macro/quota calculation when meal dropdown or custom text changes
        $(document).on('change input', '.cmp-meal-select, .cmp-meal-custom, select[class^="cmp-juice-"]', function() { 
            var rowElement = $(this).closest('tr');
            if ($(this).is('select')) {
                $(this).data('saved-val', $(this).val());
            }
            calculateMacros(rowElement); 
            enforceMealQuota(rowElement);
            evaluateRowDirtyState(rowElement);
        });

        // Dedicated Undo Button Click Handler
        $(document).on('click', '.cmp-undo-row', function(e) {
            e.preventDefault();
            var rowElement = $(this).closest('tr.cmp-day-row');
            restoreRowFromSnapshot(rowElement);
        });

        // Floating Unsaved Bar Buttons: Jump to First Unsaved Day & Undo All
        $('#cmp-float-jump-btn').on('click', function(e) {
            e.preventDefault();
            var $firstUnsaved = $('tr.cmp-day-row.is-unsaved').first();
            if ($firstUnsaved.length) {
                focusRowFirstInput($firstUnsaved);
            }
        });

        $('#cmp-float-undo-all-btn').on('click', function(e) {
            e.preventDefault();
            $('tr.cmp-day-row.is-unsaved').each(function() {
                restoreRowFromSnapshot($(this));
            });
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
            evaluateRowDirtyState(rowElement);

            // If unchecked, expand card and focus the first meal selector for seamless keyboard flow
            if (!$(this).is(':checked')) {
                rowElement.removeClass('is-collapsed-desktop');
                var firstMeal = rowElement.find('select.cmp-meal-select:visible:enabled, input.cmp-meal-custom:visible:enabled').first();
                if (firstMeal.length) {
                    setTimeout(function() { firstMeal.focus(); }, 40);
                }
            }
        });

        // =========================================================
        // iPHONE (iOS SAFARI) & CROSS-BROWSER DATE PICKER ENGINE
        // =========================================================
        $(document).on('touchstart focus', '.cmp-date-picker', function() {
            var rowInput = $(this);
            if (rowInput.is(':disabled') || canBypassDateLock) return;

            if (!rowInput.val()) {
                var seedDate = getRowEarliestValidDate(rowInput);
                if (seedDate) {
                    rowInput.attr('min', seedDate);
                    rowInput.val(seedDate);
                    applyValidDateToRow(rowInput, seedDate);
                }
            }
        });

        function handleDatePickerValidation(rowInput, isBlurEvent) {
            var selectedDate = (rowInput.val() || '').toString().trim();
            var rowElement   = rowInput.closest('tr');

            if (!selectedDate) {
                rowElement.removeClass('is-collapsed-desktop');
                populateDynamicDropdowns(rowElement);
                syncRowCompactAndSummary(rowElement);
                evaluateRowDirtyState(rowElement);
                return;
            }

            var earliestForRow = getRowEarliestValidDate(rowInput);
            var isLockedDate   = !canBypassDateLock && (selectedDate <= todayYmd || (customerMinDate && selectedDate < customerMinDate) || (earliestForRow && selectedDate < earliestForRow));
            var isBlackout     = blackoutDates.includes(selectedDate);

            if (isLockedDate || isBlackout) {
                if (isIOSOrTouch && !isBlurEvent && rowInput.is(':focus')) {
                    return;
                }

                var snap         = rowElement.data('cmp-snapshot');
                var fallbackDate = (snap && snap.date && snap.date >= earliestForRow && !blackoutDates.includes(snap.date))
                    ? snap.date
                    : earliestForRow;

                if (isLockedDate) {
                    cmpShowNotice('Past dates, today\'s date, and dates past the cut-off time cannot be selected. Earliest available date: ' + (earliestForRow || customerMinDate), 'error');
                } else {
                    cmpShowNotice('The kitchen is closed on this date. Please select a different day.', 'error');
                }

                if (isIOSOrTouch && fallbackDate) {
                    rowInput.val(fallbackDate);
                    applyValidDateToRow(rowInput, fallbackDate);
                } else {
                    rowInput.val((snap && snap.date) ? snap.date : '');
                    applyValidDateToRow(rowInput, rowInput.val());
                }
                return;
            }

            applyValidDateToRow(rowInput, selectedDate);
        }

        $('.cmp-date-picker').on('change', function() {
            handleDatePickerValidation($(this), false);
        });

        $('.cmp-date-picker').on('blur', function() {
            handleDatePickerValidation($(this), true);
        });

        // =========================================================
        // DESKTOP SCROLL-TO-PENDING & KEYBOARD CONTINUOUS INPUT
        // =========================================================
        function focusRowFirstInput($targetRow) {
            if (!$targetRow || !$targetRow.length) return;
            $targetRow.removeClass('is-collapsed-desktop cmp-pulse-target');
            void $targetRow[0].offsetWidth;
            $targetRow.addClass('cmp-pulse-target');

            var scrollOffset = 110;
            if ($(window).width() <= 768) {
                $targetRow.siblings('tr.cmp-day-row.is-open:not(.is-unsaved)').removeClass('is-open');
                $targetRow.addClass('is-open');
                scrollOffset = 10;
            }

            $('html, body').stop(true).animate({
                scrollTop: Math.max(0, $targetRow.offset().top - scrollOffset)
            }, 280);

            setTimeout(function() {
                var $dp = $targetRow.find('.cmp-date-picker');
                if (!$dp.is(':disabled') && !$dp.val()) {
                    $dp.focus();
                } else {
                    var $firstMeal = $targetRow.find('select.cmp-meal-select:visible:enabled, input.cmp-meal-custom:visible:enabled').first();
                    if ($firstMeal.length) {
                        $firstMeal.focus();
                    } else if (!$dp.is(':disabled')) {
                        $dp.focus();
                    } else {
                        $targetRow.find('.cmp-save-row:enabled').focus();
                    }
                }
            }, 80);
        }

        // Scroll to Currently Pending Selection Button Handler (Toolbar Button)
        $(document).on('click', '.cmp-jump-pending-btn', function(e) {
            e.preventDefault();
            var $activeContainer = $('.cmp-tab-content.active');
            if (!$activeContainer.length) return;

            var $firstPending = $activeContainer.find('tr.cmp-day-row.status-pending, tr.cmp-day-row.is-unsaved').first();
            if ($firstPending.length) {
                focusRowFirstInput($firstPending);
            } else {
                cmpShowNotice('All days in this meal plan have already been scheduled!', 'success');
            }
        });

        // Auto-expand collapsed card if user Tabs into its meal/action controls
        $(document).on('focusin', 'tr.cmp-day-row', function() {
            $(this).removeClass('is-collapsed-desktop');
        });

        // Keyboard shortcuts inside any Day Card
        $(document).on('keydown', 'tr.cmp-day-row', function(e) {
            var $row = $(this);
            var $saveBtn = $row.find('.cmp-save-row:enabled');

            if (e.key === 'Escape' && $row.hasClass('is-unsaved')) {
                e.preventDefault();
                restoreRowFromSnapshot($row);
                return;
            }

            if (e.altKey && e.key === 'ArrowDown') {
                e.preventDefault();
                var $next = $row.nextAll('tr.cmp-day-row').first();
                focusRowFirstInput($next);
                return;
            }

            if (e.altKey && e.key === 'ArrowUp') {
                e.preventDefault();
                var $prev = $row.prevAll('tr.cmp-day-row').first();
                focusRowFirstInput($prev);
                return;
            }

            if (e.altKey && (e.key === 'c' || e.key === 'C')) {
                var $cc = $row.find('.cmp-chefs-choice:enabled');
                if ($cc.length) {
                    e.preventDefault();
                    $cc.prop('checked', !$cc.is(':checked')).trigger('change');
                }
                return;
            }

            var isCtrlEnter = (e.ctrlKey || e.metaKey) && e.key === 'Enter';
            var isDirectEnter = (e.key === 'Enter') && !$(e.target).is('select');

            if ((isCtrlEnter || isDirectEnter) && $saveBtn.length) {
                e.preventDefault();
                $saveBtn.data('kbd-advance', 1).trigger('click');
            }
        });

        $('.cmp-save-row').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var shouldKeyboardAdvance = (btn.data('kbd-advance') == 1);
            btn.data('kbd-advance', 0);

            var rowElement = btn.closest('tr'); 
            var container = btn.closest('.cmp-portal-container');

            var dateVal = rowElement.find('.cmp-date-picker').val();
            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked') ? 1 : 0;
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var snackQuota = parseInt(container.data('snack-quota')) || 0;
            var isJuice = container.data('is-juice') === 1;
            var isCustom = rowElement.data('allow-custom') == 1;

            if(!dateVal) {
                rowElement.removeClass('is-collapsed-desktop');
                cmpShowNotice('Please select a date from the calendar first.', 'error');
                return;
            }

            if (!canBypassDateLock && (dateVal <= todayYmd || (customerMinDate && dateVal < customerMinDate))) {
                cmpShowNotice('This date is locked. You may only save or update selections from ' + customerMinDate + ' onwards.', 'error');
                return;
            }

            var isMenuPending = rowElement.find('.boundary-overlay').length > 0;

            // STRICT MEAL QUOTA ENFORCEMENT (Governed by WP Admin -> Meal Settings)
            if (!canBypassQuota && !isMenuPending && !isChefsChoice && !isCustom) { 
                if (isJuice) {
                    var juiceSelects = rowElement.find('select[class^="cmp-juice-"]');
                    var missingJuice = false;
                    juiceSelects.each(function() { if ($(this).val() === '') missingJuice = true; });
                    if (missingJuice) {
                        rowElement.removeClass('is-collapsed-desktop');
                        cmpShowNotice('Quota Requirement: Please select all 3 juices to complete this day.', 'error');
                        return;
                    }
                } else {
                    var selectedMainCount = 0;
                    rowElement.find('.cmp-main-meal').each(function() {
                        if ($(this).val() !== "") selectedMainCount++;
                    });

                    if (selectedMainCount !== allowedMeals) {
                        rowElement.removeClass('is-collapsed-desktop');
                        cmpShowNotice('Quota Requirement: Please select exactly ' + allowedMeals + ' main meal(s) as allocated in this subscription before saving.', 'error');
                        return;
                    }

                    var selectedSnackCount = 0;
                    rowElement.find('select[data-cat="Snacks"]').each(function() {
                        if ($(this).val() !== "") selectedSnackCount++;
                    });

                    if (selectedSnackCount !== snackQuota) {
                        rowElement.removeClass('is-collapsed-desktop');
                        cmpShowNotice('Quota Requirement: Please select exactly ' + snackQuota + ' snack(s) as allocated in this subscription before saving.', 'error');
                        return;
                    }
                }
            } else if (!canBypassQuota && !isChefsChoice && isCustom) {
                if (isJuice) {
                    var juiceInputs = rowElement.find('.cmp-juice-1, .cmp-juice-2, .cmp-juice-3');
                    var missingJuice = false;
                    juiceInputs.each(function() { if ($(this).val().trim() === '') missingJuice = true; });
                    if (missingJuice) {
                        rowElement.removeClass('is-collapsed-desktop');
                        cmpShowNotice('Quota Requirement: Please complete all 3 juices to complete this day.', 'error');
                        return;
                    }
                } else {
                    var selectedMainCount = 0;
                    rowElement.find('.cmp-main-meal').each(function() {
                        if ($(this).val().trim() !== "") selectedMainCount++;
                    });
                    if (selectedMainCount !== allowedMeals) {
                        rowElement.removeClass('is-collapsed-desktop');
                        cmpShowNotice('Quota Requirement: Please provide exactly ' + allowedMeals + ' main meal(s) as allocated in this subscription before saving.', 'error');
                        return;
                    }

                    var selectedSnackCount = 0;
                    rowElement.find('.cmp-snack-1, .cmp-snack-2').each(function() {
                        if ($(this).val().trim() !== "") selectedSnackCount++;
                    });
                    if (selectedSnackCount !== snackQuota) {
                        rowElement.removeClass('is-collapsed-desktop');
                        cmpShowNotice('Quota Requirement: Please provide exactly ' + snackQuota + ' snack(s) as allocated in this subscription before saving.', 'error');
                        return;
                    }
                }
            } else if (isMenuPending && !isChefsChoice && !canBypassQuota && !isCustom) {
                rowElement.removeClass('is-collapsed-desktop');
                cmpShowNotice('The specific menu is not available yet. Please select "' + chefsChoiceLabel + '" to secure this date.', 'error');
                return;
            }

            btn.text('Saving...').prop('disabled', true);

            var data = {
                action: 'cmp_save_daily_log',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                log_id: btn.data('log-id'),
                sub_id: btn.data('sub-id'),
                date: dateVal,
                chefs_choice: isChefsChoice,
                is_override_mode: canBypassDateLock ? 1 : 0
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
                    if(response.data && response.data.new_log_id) {
                        btn.data('log-id', response.data.new_log_id);
                    }

                    // Determine if any meals were chosen or if only the date was reserved
                    var hasSavedMeals = false;
                    rowElement.find('select.cmp-meal-select, select[class^="cmp-juice-"], input.cmp-meal-custom').each(function() {
                        if (($(this).val() || '').toString().trim() !== '') {
                            hasSavedMeals = true;
                        }
                    });

                    var newBadge = (!isChefsChoice && !hasSavedMeals)
                        ? '<span class="cmp-status-pill pill-meals-pending">Meals Pending</span>'
                        : '<span class="cmp-status-pill pill-confirmed">Selected</span>';
                    rowElement.find('.cmp-desktop-status-holder').html(newBadge);
                    rowElement.find('.cmp-mobile-status').html(newBadge);

                    // Update Read-Only POS Check Indicator for Staff Roles
                    if (canViewPosCheck) {
                        var isPosChecked = (response.data && parseInt(response.data.pos_updated) === 1);
                        var desktopPosHtml = isPosChecked
                            ? '<span class="cmp-pos-readonly-pill pos-is-checked" title="POS Reconciled">✓ POS Checked</span>'
                            : '<span class="cmp-pos-readonly-pill pos-is-pending" title="Pending POS Check">POS Pending</span>';
                        var mobilePosHtml = isPosChecked
                            ? '<span class="cmp-pos-micro-pill pos-is-checked" title="POS Checked">POS ✓</span>'
                            : '<span class="cmp-pos-micro-pill pos-is-pending" title="Pending POS Check">POS •</span>';
                        rowElement.find('.cmp-desktop-pos-holder').html(desktopPosHtml);
                        rowElement.find('.cmp-mobile-pos-holder').html(mobilePosHtml);
                    }

                    // Clear unsaved state, refresh row snapshot to the newly saved values, and update global guard
                    markRowSaved(rowElement);
                    rowElement.removeClass('status-pending').addClass('status-saved is-collapsed-desktop');
                    captureRowSnapshot(rowElement);
                    syncRowCompactAndSummary(rowElement);
                    updatePendingCounts(container);

                    if (!canBypassDateLock) {
                        var isTimeLocked = (dateVal <= todayYmd || (customerMinDate && dateVal < customerMinDate));

                        if (isTimeLocked) {
                            rowElement.find('.cmp-date-picker, .cmp-chefs-choice, select, input.cmp-meal-custom').prop('disabled', true).removeClass('quota-locked');
                            rowElement.find('.cmp-action-box').html('<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center; width:100%; padding:10px 0;">Locked (Cutoff Passed)</span>');
                        } else {
                            btn.text('✓ Updated').css({'background':'#1B5E20'}).prop('disabled', false);
                        }
                    } else {
                        btn.text('✓ Updated').css({'background':'#1B5E20'}).prop('disabled', false); 
                    }

                    // Advance to Next Pending Row automatically
                    var $nextRow = rowElement.nextAll('tr.cmp-day-row.status-pending').first();
                    if ($(window).width() <= 768) {
                        rowElement.removeClass('is-open'); 
                        if ($nextRow.length) {
                            focusRowFirstInput($nextRow);
                        }
                    } else if (shouldKeyboardAdvance || $nextRow.length) {
                        focusRowFirstInput($nextRow);
                    }

                } else {
                    cmpShowNotice('Error: ' + response.data, 'error');
                    btn.text(rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') ? 'Update' : 'Save')).prop('disabled', false);
                }
            }).fail(function() {
                cmpShowNotice('Connection error. Please try saving again.', 'error');
                btn.text(rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') ? 'Update' : 'Save')).prop('disabled', false);
            });
        });

        // CLEAR / RESET SELECTION LOGIC (Governed by WP Admin -> Meal Settings Date Exemption)
        $(document).on('click', '.cmp-clear-row', function(e) {
            e.preventDefault();
            var btn = $(this);
            var rowElement = btn.closest('tr');
            var dateVal = rowElement.find('.cmp-date-picker').val();

            if (!canBypassDateLock && dateVal && (dateVal <= todayYmd || (customerMinDate && dateVal < customerMinDate))) {
                cmpShowNotice('Past dates, today\'s date, and dates past the cut-off time cannot be reset.', 'error');
                return;
            }
            
            cmpConfirmAction('Are you sure you want to clear this day? This will reset the selection and return the quota so a new date can be picked.', function() {
                var origHTML = btn.html();
                btn.html('...').prop('disabled', true);
                
                var data = {
                    action: 'cmp_clear_daily_log',
                    nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                    log_id: btn.data('log-id'),
                    sub_id: btn.data('sub-id'),
                    is_override_mode: canBypassDateLock ? 1 : 0
                };
                
                $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(response) {
                    if (response.success) {
                        markRowSaved(rowElement);
                        isIntentionalReload = true;
                        location.reload(); 
                    } else {
                        cmpShowNotice('Error: ' + response.data, 'error');
                        btn.html(origHTML).prop('disabled', false);
                    }
                }).fail(function() {
                    cmpShowNotice('Connection error.', 'error');
                    btn.html(origHTML).prop('disabled', false);
                });
            });
        });

        // CANCEL DAY LOGIC (Governed by WP Admin -> Meal Settings Date Exemption)
        $(document).on('click', '.cmp-cancel-day-row', function(e) {
            e.preventDefault();
            var btn = $(this);
            var rowElement = btn.closest('tr');
            var dateVal = rowElement.find('.cmp-date-picker').val();

            if (!canBypassDateLock && dateVal && (dateVal <= todayYmd || (customerMinDate && dateVal < customerMinDate))) {
                cmpShowNotice('Past dates, today\'s date, and dates past the cut-off time cannot be cancelled.', 'error');
                return;
            }
            
            cmpConfirmAction('Are you sure you want to CANCEL this specific day?\n\nThis will permanently void this date, display it as "Cancelled", and push a replacement day to the bottom of the plan.', function() {
                var origHTML = btn.html();
                btn.html('...').prop('disabled', true);
                
                var data = {
                    action: 'cmp_cancel_daily_log',
                    nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                    log_id: btn.data('log-id'),
                    sub_id: btn.data('sub-id'),
                    is_override_mode: canBypassDateLock ? 1 : 0
                };
                
                $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(response) {
                    if (response.success) {
                        markRowSaved(rowElement);
                        isIntentionalReload = true;
                        location.reload(); 
                    } else {
                        cmpShowNotice('Error: ' + response.data, 'error');
                        btn.html(origHTML).prop('disabled', false);
                    }
                }).fail(function() {
                    cmpShowNotice('Connection error.', 'error');
                    btn.html(origHTML).prop('disabled', false);
                });
            });
        });

        // =========================================================
        // OPTION 4A: CUSTOMER EXPERIENCE FEEDBACK SURVEY HANDLERS
        // =========================================================
        var ratingLabels = ['', '1/5 - Poor', '2/5 - Fair', '3/5 - Good', '4/5 - Very Good', '5/5 - Excellent'];

        $(document).on('click', '.cmp-star-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var val  = parseInt($btn.data('val')) || 0;
            var $grp = $btn.closest('.cmp-star-group');
            $grp.attr('data-rating', val).data('rating', val);

            $grp.find('.cmp-star-btn').each(function() {
                var sVal = parseInt($(this).data('val')) || 0;
                $(this).toggleClass('is-active', sVal <= val);
            });
            $grp.find('.cmp-rating-score-txt').text(ratingLabels[val] || '');
        });

        $(document).on('click', '.cmp-feedback-tag-pill', function(e) {
            e.preventDefault();
            $(this).toggleClass('is-selected');
        });

        $(document).on('change', '.cmp-feedback-identity-chk', function() {
            var subId = $(this).data('sub-id');
            var isChecked = $(this).is(':checked');
            var $pill = $('#feedback-id-pill-' + subId);
            if (isChecked) {
                $pill.text('Details Included').removeClass('is-anon');
            } else {
                $pill.text('Anonymous Mode').addClass('is-anon');
            }
        });

        $(document).on('click', '.cmp-submit-feedback-btn', function(e) {
            e.preventDefault();
            var $btn  = $(this);
            var subId = $btn.data('sub-id');
            var $body = $('#feedback-body-' + subId);

            var serviceRating = parseInt($body.find('.cmp-star-group[data-rating-type="service"]').attr('data-rating')) || 0;
            var portalRating  = parseInt($body.find('.cmp-star-group[data-rating-type="portal"]').attr('data-rating')) || 0;

            if (serviceRating < 1 && portalRating < 1) {
                cmpShowNotice('Please tap a star rating for Meal Plan Service or Online Portal Experience.', 'error');
                return;
            }

            var selectedTags = [];
            $('#feedback-tags-' + subId + ' .cmp-feedback-tag-pill.is-selected').each(function() {
                selectedTags.push($(this).data('tag'));
            });

            var comments       = ($('#feedback-comments-' + subId).val() || '').trim();
            var includeDetails = $('#feedback-identity-' + subId).is(':checked') ? 1 : 0;

            $btn.text('Submitting...').prop('disabled', true);

            $.post('<?php echo admin_url("admin-ajax.php"); ?>', {
                action: 'cmp_submit_customer_feedback',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                sub_id: subId,
                service_rating: serviceRating,
                portal_rating: portalRating,
                quick_tags: selectedTags.join(', '),
                comments: comments,
                include_details: includeDetails
            }, function(res) {
                if (res.success) {
                    $body.html(
                        '<div style="padding:18px; background:#f0fdf4; border:1.5px solid #86efac; border-radius:12px; text-align:center; color:#166534; font-weight:800;">' +
                        '✓ ' + res.data.msg +
                        '</div>'
                    );
                } else {
                    cmpShowNotice('Error: ' + res.data, 'error');
                    $btn.text('Submit Feedback').prop('disabled', false);
                }
            }).fail(function() {
                cmpShowNotice('Connection error. Please try again.', 'error');
                $btn.text('Submit Feedback').prop('disabled', false);
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
        $can_view_pos = current_user_can('manage_options') || current_user_can('foh_manager') || current_user_can('menu_manager');
        $user_id = get_current_user_id();
        $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", $sub_id));

        if (!$sub || (!$is_admin && $sub->user_id != $user_id)) wp_die('Unauthorized Access');

        $is_flexible  = isset($sub->is_flexible) ? intval($sub->is_flexible) : 0;
        $allow_custom = isset($sub->allow_custom) ? intval($sub->allow_custom) : 0;

        $order = function_exists('wc_get_order') ? wc_get_order($sub->wc_order_id) : false;
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
            $headers = array('Date', 'Juice 1', 'Juice 2', 'Juice 3', 'Chefs Choice', 'Delivery Status');
            if ($can_view_pos) $headers[] = 'POS Check';
            fputcsv($output, $headers); 
        } else { 
            $h1 = $is_flexible ? 'Meal 1' : 'Breakfast';
            $h2 = $is_flexible ? 'Meal 2' : 'Lunch';
            $h3 = $is_flexible ? 'Meal 3' : 'Dinner';
            $headers = array('Date', $h1, $h2, $h3, 'Snack 1', 'Snack 2', 'Chefs Choice', 'Delivery Status');
            if ($can_view_pos) $headers[] = 'POS Check';
            fputcsv($output, $headers); 
        }

        foreach ($logs as $log) {
            $chefs = $log->is_chefs_choice ? 'Yes' : 'No';

            $status = 'Pending';
            if ($log->delivery_result === 'Successful') { $status = 'Successful'; }
            elseif (in_array($log->delivery_result, ['Cancelled','Returned'])) { $status = $log->delivery_result; }
            elseif ($log->id > 0) { $status = 'Selected'; }

            $pos_status = (!empty($log->pos_updated)) ? 'Checked' : 'Pending';

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
                $row_out = array($log->target_date, $j1, $j2, $j3, $chefs, $status);
                if ($can_view_pos) $row_out[] = $pos_status;
                fputcsv($output, $row_out);

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
                $row_out = array($log->target_date, $b, $l, $d, $s1, $s2, $chefs, $status);
                if ($can_view_pos) $row_out[] = $pos_status;
                fputcsv($output, $row_out);
            }
        }
        fclose($output); exit;
    }
}
