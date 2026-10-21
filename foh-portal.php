<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// FRONT OF HOUSE (FOH) ADMIN PORTAL
// ==========================================
add_shortcode( 'meal_foh_portal', 'cmp_render_foh_portal' );

// 1. AJAX HANDLER: Update Expiry Date
add_action('wp_ajax_cmp_foh_update_expiry', 'cmp_ajax_foh_update_expiry');
function cmp_ajax_foh_update_expiry() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $wpdb->update($table_subs, array('expiry_date' => sanitize_text_field($_POST['new_expiry']) . ' 23:59:59'), array('id' => intval($_POST['sub_id'])));
    wp_send_json_success();
}

// 2. AJAX HANDLER: Toggle Pause/Resume Status (Global Plan Level)
add_action('wp_ajax_cmp_foh_toggle_status', 'cmp_ajax_foh_toggle_status');
function cmp_ajax_foh_toggle_status() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $wpdb->update($table_subs, array('status' => sanitize_text_field($_POST['new_status'])), array('id' => intval($_POST['sub_id'])));
    wp_send_json_success();
}

// 3. AJAX HANDLER: Save FOH Remarks
add_action('wp_ajax_cmp_foh_save_remark', 'cmp_ajax_foh_save_remark');
function cmp_ajax_foh_save_remark() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $remark = sanitize_textarea_field($_POST['remark']);
    $wpdb->update($table_subs, array('foh_remarks' => $remark), array('id' => intval($_POST['sub_id'])));
    wp_send_json_success();
}

// 4. AJAX HANDLER: Update Super Admin & Menu Manager Financials
add_action('wp_ajax_cmp_foh_update_financials', 'cmp_ajax_foh_update_financials');
function cmp_ajax_foh_update_financials() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    
    $sub_id   = intval($_POST['sub_id']);
    $price    = floatval($_POST['plan_price']);
    $discount = floatval($_POST['discount_amount']);
    
    $wpdb->update($table_subs, array('plan_price' => $price, 'discount_amount' => $discount), array('id' => $sub_id));
    wp_send_json_success(array('price' => $price, 'discount' => $discount));
}

// 5. AJAX HANDLER: Update Account Details & Plan Mode (FOH, Menu Manager, Admin)
add_action('wp_ajax_cmp_foh_update_account_details', 'cmp_ajax_foh_update_account_details');
function cmp_ajax_foh_update_account_details() {
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    
    if ( ! current_user_can('manage_options') && ! current_user_can('foh_manager') && ! current_user_can('menu_manager') ) {
        wp_send_json_error('Permission Denied.');
    }

    $user_id   = intval($_POST['user_id']);
    $order_id  = intval($_POST['order_id']);
    $sub_id    = isset($_POST['sub_id']) ? intval($_POST['sub_id']) : 0;
    
    $first_name     = sanitize_text_field($_POST['first_name']);
    $last_name      = sanitize_text_field($_POST['last_name']);
    $recipient_name = sanitize_text_field($_POST['recipient_name']);
    
    $plan_mode    = sanitize_text_field($_POST['plan_mode'] ?? 'standard');
    $is_flexible  = ($plan_mode === 'flexible') ? 1 : 0;
    $allow_custom = ($plan_mode === 'custom') ? 1 : 0;

    if ($user_id > 0) {
        wp_update_user(['ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name]);
        update_user_meta($user_id, 'billing_first_name', $first_name);
        update_user_meta($user_id, 'billing_last_name', $last_name);
    }

    if ($order_id > 0) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->set_billing_first_name($first_name);
            $order->set_billing_last_name($last_name);
            $order->set_shipping_first_name($first_name);
            $order->set_shipping_last_name($last_name);
            $order->save();
        }
    }

    $new_plan_name = '';
    if ($sub_id > 0) {
        global $wpdb;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';
        
        $wpdb->update(
            $table_subs, 
            array(
                'is_flexible'  => $is_flexible,
                'allow_custom' => $allow_custom
            ), 
            array('id' => $sub_id)
        );

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
        'name'      => trim($first_name . ' ' . $last_name),
        'recipient' => $recipient_name,
        'plan_name' => $new_plan_name,
        'plan_mode' => $plan_mode
    ));
}

// 6. AJAX HANDLER: Delete Subscription Plan (Strictly Super Admin)
add_action('wp_ajax_cmp_foh_delete_subscription', 'cmp_ajax_foh_delete_subscription');
function cmp_ajax_foh_delete_subscription() {
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('Permission Denied. Only Super Administrators can delete plans.');
    }

    global $wpdb;
    $sub_id = intval($_POST['sub_id']);
    if ($sub_id <= 0) {
        wp_send_json_error('Invalid Subscription ID.');
    }

    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    // Delete associated daily meal logs
    $wpdb->delete($table_logs, array('subscription_id' => $sub_id), array('%d'));

    // Delete the subscription record
    $deleted = $wpdb->delete($table_subs, array('id' => $sub_id), array('%d'));

    if ($deleted) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to delete subscription from database.');
    }
}

// 7. AJAX HANDLER: Export CSV
add_action('wp_ajax_cmp_foh_export_csv', 'cmp_foh_export_csv');
function cmp_foh_export_csv() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { 
        wp_die('Access Denied'); 
    }

    global $wpdb;
    date_default_timezone_set('Asia/Dubai');
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $today = date('Y-m-d H:i:s');
    $today_date = date('Y-m-d');
    $today_ts = strtotime($today_date);

    $all_subs = $wpdb->get_results("SELECT s.*, u.display_name, u.user_email FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.status != 'pending' ORDER BY s.id DESC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="FOH_Subscriptions_Export_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

    fputcsv($output, array(
        'Order ID', 'Customer Name', 'Recipient Name', 'Email', 'Phone', 
        'Plan Details', 'Plan Mode', 'Base Price (AED)', 'Discount (AED)', 'Final Paid (AED)', 
        'Status', 'Bag Status', 'Total Days', 'Filled Days', 'Pending to Fill', 'Over-Quota Days', 
        'Last Scheduled Date', 'Schedule Alert', 'Used Days', 'Balance Days', 
        'Start Date', 'Expiry Date', 'FOH Remarks'
    ));

    foreach ($all_subs as $sub) {
        $order = wc_get_order($sub->wc_order_id);
        
        $fname = get_user_meta($sub->user_id, 'first_name', true) ?: get_user_meta($sub->user_id, 'billing_first_name', true);
        $lname = get_user_meta($sub->user_id, 'last_name', true) ?: get_user_meta($sub->user_id, 'billing_last_name', true);
        $fallback_name = trim($fname . ' ' . $lname);
        if (empty($fallback_name)) $fallback_name = $sub->display_name;
        
        $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $fallback_name;
        if (empty(trim($full_name))) { $full_name = $fallback_name; }

        $phone = $order ? $order->get_billing_phone() : (get_user_meta($sub->user_id, 'billing_phone', true) ?: 'N/A');

        $active_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_logs WHERE subscription_id = %d AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))",
            $sub->id
        ));

        $filled_days = count($active_logs);
        $usage_days = 0;
        $last_scheduled_date = '';
        $unpicked_meals_count = 0;

        foreach ($active_logs as $log_row) {
            if ($log_row->delivery_result === 'Successful') {
                $usage_days++;
            }
            if (!empty($log_row->target_date) && ($last_scheduled_date === '' || $log_row->target_date > $last_scheduled_date)) {
                $last_scheduled_date = $log_row->target_date;
            }
            if (!empty($log_row->target_date) && $log_row->target_date >= $today_date) {
                $is_assigned = (
                    !empty($log_row->breakfast_id) || !empty($log_row->lunch_id) || !empty($log_row->dinner_id) || !empty($log_row->juice_1_id) ||
                    !empty($log_row->custom_breakfast) || !empty($log_row->custom_lunch) || !empty($log_row->custom_dinner) || !empty($log_row->custom_juice_1) ||
                    !empty($log_row->is_chefs_choice)
                );
                if (!$is_assigned) {
                    $unpicked_meals_count++;
                }
            }
        }

        $total_days_int = intval($sub->total_days);
        $pending_fill = max(0, $total_days_int - $filled_days);
        $over_quota = max(0, $filled_days - $total_days_int);
        $balance = max(0, $total_days_int - $usage_days);

        $status_label = 'Active';
        if ($sub->status === 'paused') {
            $status_label = 'Paused';
        } elseif ($usage_days >= $total_days_int || $sub->expiry_date < $today) {
            $status_label = 'Inactive/Expired';
        }

        $schedule_alert = 'OK';
        if ($status_label === 'Active') {
            $alerts = array();
            if ($over_quota > 0) {
                $alerts[] = "OVER-QUOTA (+{$over_quota} Days)";
            } elseif ($pending_fill > 0) {
                if (empty($last_scheduled_date)) {
                    $alerts[] = "URGENT: No Days Scheduled ({$pending_fill}d Unfilled)";
                } else {
                    $days_until_end = (int) round((strtotime($last_scheduled_date) - $today_ts) / 86400);
                    if ($days_until_end < 0) {
                        $alerts[] = "URGENT: Schedule Ended ({$pending_fill}d Unfilled)";
                    } elseif ($days_until_end === 0) {
                        $alerts[] = "URGENT: Schedule Ends Today ({$pending_fill}d Unfilled)";
                    } elseif ($days_until_end === 1) {
                        $alerts[] = "URGENT: Schedule Ends Tomorrow ({$pending_fill}d Unfilled)";
                    } elseif ($days_until_end <= 3) {
                        $alerts[] = "WARNING: Ends in {$days_until_end} Days ({$pending_fill}d Unfilled)";
                    }
                }
            }
            if ($unpicked_meals_count > 0) {
                $alerts[] = "{$unpicked_meals_count} Date(s) Missing Meals";
            }
            if (!empty($alerts)) {
                $schedule_alert = implode(' | ', $alerts);
            }
        }

        $remarks = !empty($sub->foh_remarks) ? wp_strip_all_tags($sub->foh_remarks) : '';

        // Extract Recipient
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

        $mode_label = 'Standard';
        if (!empty($sub->is_flexible)) $mode_label = 'Flexible';
        if (!empty($sub->allow_custom)) $mode_label = 'Custom';

        $base_price = isset($sub->plan_price) ? (float) $sub->plan_price : 0;
        $discount   = isset($sub->discount_amount) ? (float) $sub->discount_amount : 0;
        if ($base_price == 0 && $order) {
            $base_price = (float) $order->get_subtotal();
            $discount = (float) $order->get_discount_total();
        }
        $final_price = max(0, $base_price - $discount);

        $deposit_held = get_user_meta($sub->user_id, '_cmp_deposit_held', true);
        if ($deposit_held === '') {
            $past_plans = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d", $sub->user_id));
            $deposit_held = (intval($past_plans) > 0) ? 'yes' : 'no';
        }
        $bag_status_text = 'Refunded / No Deposit';
        if ($deposit_held === 'yes') { $bag_status_text = 'Bag Deposit Held'; }
        if ($deposit_held === 'own_bag') { $bag_status_text = "Customer's Own Bag"; }

        $display_order_id = 'Manual';
        if ($sub->wc_order_id < 0) { 
            $display_order_id = 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT); 
        } elseif ($sub->wc_order_id > 0) { 
            $display_order_id = '#' . $sub->wc_order_id; 
        } else {
            $display_order_id = 'M-' . str_pad($sub->id, 4, '0', STR_PAD_LEFT);
        }

        fputcsv($output, array(
            $display_order_id,
            $full_name,
            $recipient_name,
            $sub->user_email,
            $phone,
            $core_plan_name,
            $mode_label,
            $base_price,
            $discount,
            $final_price,
            $status_label,
            $bag_status_text,
            $total_days_int,
            $filled_days,
            $pending_fill,
            $over_quota,
            $last_scheduled_date ?: 'None',
            $schedule_alert,
            $usage_days,
            $balance,
            date('Y-m-d', strtotime($sub->start_date)),
            date('Y-m-d', strtotime($sub->expiry_date)),
            $remarks
        ));
    }
    
    fclose($output); 
    exit;
}

// 8. AJAX HANDLER: Toggle Deposit Status
add_action('wp_ajax_cmp_foh_toggle_deposit', 'cmp_ajax_foh_toggle_deposit');
function cmp_ajax_foh_toggle_deposit() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    
    $user_id = intval($_POST['user_id']);
    $new_status = sanitize_text_field($_POST['new_status']); 
    
    update_user_meta($user_id, '_cmp_deposit_held', $new_status);
    wp_send_json_success();
}

// 9. AJAX HANDLER: Fetch Upcoming Days for Modal (Excludes Same Day)
add_action('wp_ajax_cmp_foh_fetch_upcoming_meals', 'cmp_foh_fetch_upcoming_meals');
function cmp_foh_fetch_upcoming_meals() {
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    if ( ! current_user_can('manage_options') && ! current_user_can('foh_manager') && ! current_user_can('menu_manager') ) {
        wp_send_json_error('Permission Denied');
    }
    
    global $wpdb;
    date_default_timezone_set('Asia/Dubai');
    $sub_id = intval($_POST['sub_id']);
    $today = date('Y-m-d');

    // ONLY fetch days STRICTLY GREATER than today. Same-day cancellations via FOH modal are blocked.
    $logs = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}cmp_daily_logs 
        WHERE subscription_id = %d 
        AND target_date > %s 
        AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))
        ORDER BY target_date ASC
    ", $sub_id, $today));

    // Determine cutoffs to flag warnings
    $current_hour = (int) date('H');
    $cutoff_hour = intval( get_option('cmp_cutoff_time', '11') );
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $day_after = date('Y-m-d', strtotime('+2 days'));
    $earliest_allowed = ($current_hour >= $cutoff_hour) ? $day_after : $tomorrow;

    $data = [];
    foreach($logs as $log) {
        $is_assigned = ($log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1 || $log->is_chefs_choice);
        $status_text = $is_assigned ? 'Assigned' : 'Pending Selection';
        $past_cutoff = ($log->target_date < $earliest_allowed);

        $data[] = [
            'id' => $log->id,
            'date' => date('D, M j, Y', strtotime($log->target_date)),
            'status' => $status_text,
            'past_cutoff' => $past_cutoff
        ];
    }
    wp_send_json_success($data);
}

// 10. AJAX HANDLER: Bulk Cancel Selected Days (Excludes Same Day)
add_action('wp_ajax_cmp_foh_bulk_cancel_days', 'cmp_foh_bulk_cancel_days');
function cmp_foh_bulk_cancel_days() {
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    if ( ! current_user_can('manage_options') && ! current_user_can('foh_manager') && ! current_user_can('menu_manager') ) {
        wp_send_json_error('Permission Denied');
    }
    
    global $wpdb;
    date_default_timezone_set('Asia/Dubai');
    $sub_id = intval($_POST['sub_id']);
    $log_ids = isset($_POST['log_ids']) ? array_map('intval', $_POST['log_ids']) : [];

    if (empty($log_ids)) wp_send_json_error('No days selected.');

    $today = date('Y-m-d');
    $ids_placeholder = implode(',', array_fill(0, count($log_ids), '%d'));
    
    // Extra security check: enforce target_date > $today so same-day records cannot be affected.
    $query_params = array_merge([$sub_id], $log_ids, [$today]);
    $query = $wpdb->prepare("UPDATE {$wpdb->prefix}cmp_daily_logs SET delivery_result = 'Cancelled' WHERE subscription_id = %d AND id IN ($ids_placeholder) AND target_date > %s", $query_params);
    $wpdb->query($query);

    $updated_subs_queue = get_option('cmp_daily_updated_subs', array());
    if (!is_array($updated_subs_queue)) { $updated_subs_queue = array(); }
    if (!in_array($sub_id, $updated_subs_queue)) {
        $updated_subs_queue[] = $sub_id;
        update_option('cmp_daily_updated_subs', $updated_subs_queue);
    }

    wp_send_json_success();
}


function cmp_render_foh_portal() {
    date_default_timezone_set('Asia/Dubai');

    $has_foh_access = current_user_can( 'manage_options' ) || current_user_can( 'foh_manager' ) || current_user_can( 'menu_manager' );

    // Security & Custom Glassmorphism UI/UX Login Screen
    if ( ! is_user_logged_in() || ! $has_foh_access ) {
        if ( function_exists( 'cmp_render_portal_login_ui' ) ) {
            return cmp_render_portal_login_ui( array(
                'portal_id'      => 'foh',
                'title'          => 'FOH Command Center',
                'subtitle'       => 'Front of House & Subscription Management',
                'required_caps'  => array( 'manage_options', 'foh_manager', 'menu_manager' ),
                'denied_message' => 'FOH Manager, Menu Manager, or Administrator clearance is required to access this portal.',
            ) );
        }
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    $col_check = $wpdb->get_results("SHOW COLUMNS FROM {$table_subs} LIKE 'foh_remarks'");
    if (empty($col_check)) { $wpdb->query("ALTER TABLE {$table_subs} ADD foh_remarks TEXT NULL AFTER status"); }

    $all_subs = $wpdb->get_results("SELECT s.*, u.display_name, u.user_email FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.status != 'pending' ORDER BY s.id DESC");
    
    $tabs = array('active' => array(), 'paused' => array(), 'inactive' => array());
    $today = date('Y-m-d H:i:s');
    $today_date = date('Y-m-d');
    $today_ts = strtotime($today_date);
    $followup_count = 0;

    foreach ($all_subs as $sub) {
        $active_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_logs WHERE subscription_id = %d AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))",
            $sub->id
        ));

        $filled_days = count($active_logs);
        $usage_days = 0;
        $last_scheduled_date = '';
        $unpicked_meals_count = 0;

        foreach ($active_logs as $log_row) {
            if ($log_row->delivery_result === 'Successful') {
                $usage_days++;
            }
            if (!empty($log_row->target_date) && ($last_scheduled_date === '' || $log_row->target_date > $last_scheduled_date)) {
                $last_scheduled_date = $log_row->target_date;
            }
            if (!empty($log_row->target_date) && $log_row->target_date >= $today_date) {
                $is_assigned = (
                    !empty($log_row->breakfast_id) || !empty($log_row->lunch_id) || !empty($log_row->dinner_id) || !empty($log_row->juice_1_id) ||
                    !empty($log_row->custom_breakfast) || !empty($log_row->custom_lunch) || !empty($log_row->custom_dinner) || !empty($log_row->custom_juice_1) ||
                    !empty($log_row->is_chefs_choice)
                );
                if (!$is_assigned) {
                    $unpicked_meals_count++;
                }
            }
        }
        
        $total_days_int = intval($sub->total_days);
        $sub->filled = $filled_days;
        $sub->usage = $usage_days;
        $sub->balance = max(0, $total_days_int - $usage_days);
        $sub->pending_fill = max(0, $total_days_int - $filled_days);
        $sub->over_quota = max(0, $filled_days - $total_days_int);
        $sub->last_scheduled_date = $last_scheduled_date;
        $sub->unpicked_meals_count = $unpicked_meals_count;
        $sub->days_until_end = !empty($last_scheduled_date) ? (int) round((strtotime($last_scheduled_date) - $today_ts) / 86400) : -999;
        $sub->needs_followup = false;

        // Calculate Sort Timestamp for "Schedule Ending Soonest (Urgent Follow-Up)"
        if ($sub->over_quota > 0) {
            $sub->sort_end_ts = 1;
        } elseif ($sub->pending_fill > 0) {
            $sub->sort_end_ts = !empty($last_scheduled_date) ? strtotime($last_scheduled_date) : 2;
        } else {
            $sub->sort_end_ts = 9999999999 + (!empty($last_scheduled_date) ? strtotime($last_scheduled_date) : 0);
        }
        
        if ($sub->status === 'paused') {
            $tabs['paused'][] = $sub;
        } elseif ($usage_days >= $total_days_int || $sub->expiry_date < $today) {
            $tabs['inactive'][] = $sub;
        } else {
            if (
                $sub->over_quota > 0 ||
                ($sub->pending_fill > 0 && (empty($last_scheduled_date) || $sub->days_until_end <= 3)) ||
                $sub->unpicked_meals_count > 0
            ) {
                $sub->needs_followup = true;
                $followup_count++;
            }
            $tabs['active'][] = $sub;
        }
    }

    // Action SVG Icons
    $icon_stop = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect></svg>';
    $icon_play = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>';
    $icon_pause = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>';
    $icon_edit = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';

    ob_start();
    ?>
    <style>
        /* Desktop FOH Glass Header (> 992px) */
        .foh-header-banner {
            background: linear-gradient(135deg, rgba(27, 94, 32, 0.92) 0%, rgba(55, 146, 55, 0.86) 55%, rgba(104, 185, 70, 0.82) 100%) !important;
            backdrop-filter: blur(18px) !important;
            -webkit-backdrop-filter: blur(18px) !important;
            color: #fff !important;
            padding: 24px 28px !important;
            border-radius: 18px !important;
            border: 1.5px solid #DCECC9 !important;
            border-bottom: 4px solid #68B946 !important;
            box-shadow: 0 14px 34px rgba(27, 94, 32, 0.14) !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 15px !important;
            margin-bottom: 25px !important;
        }
        .foh-header-banner h2 { margin: 0 !important; color: #ffffff !important; font-weight: 800 !important; }
        .foh-header-banner p { margin: 5px 0 0 0 !important; color: #EEF7E3 !important; font-weight: 500 !important; }

        /* Tablet FOH Glass Header (601px - 991px) */
        @media (min-width: 601px) and (max-width: 991px) {
            .foh-header-banner {
                background: linear-gradient(125deg, rgba(220, 236, 201, 0.92) 0%, rgba(104, 185, 70, 0.42) 100%) !important;
                border: 1.5px solid #379237 !important;
                padding: 22px 24px !important;
            }
            .foh-header-banner h2 { color: #231F20 !important; }
            .foh-header-banner p { color: #1B5E20 !important; }
        }

        /* Mobile FOH Glass Header (<= 600px) */
        @media (max-width: 600px) {
            .foh-header-banner {
                background: linear-gradient(145deg, rgba(55, 146, 55, 0.95) 0%, rgba(27, 94, 32, 0.92) 100%) !important;
                border: 1.5px solid #68B946 !important;
                border-radius: 16px !important;
                padding: 18px !important;
            }
            .foh-header-banner h2 { color: #ffffff !important; font-size: 1.45em !important; }
            .foh-header-banner p { color: #DCECC9 !important; font-size: 0.9em !important; }
        }

        .foh-tile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .foh-tile {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(12px);
            padding: 18px 22px;
            border-radius: 12px;
            border: 1px solid #DCECC9;
            box-shadow: 0 4px 12px rgba(27, 94, 32, 0.05);
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
            position: relative;
            user-select: none;
        }
        .foh-tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(27, 94, 32, 0.10);
        }
        .foh-tile.tile-active   { border-left: 5px solid #379237; }
        .foh-tile.tile-followup { border-left: 5px solid #ea580c; }
        .foh-tile.tile-paused   { border-left: 5px solid #f59e0b; }
        .foh-tile.tile-inactive { border-left: 5px solid #dc2626; }
        
        .foh-tile.active {
            box-shadow: 0 0 0 2px #379237, 0 6px 16px rgba(55, 146, 55, 0.12);
            border-color: #379237;
            background: #ffffff;
        }
        .foh-tile.tile-active.active {
            box-shadow: 0 0 0 2px #379237, 0 6px 16px rgba(55, 146, 55, 0.18);
            border-color: #379237;
        }
        .foh-tile.tile-followup.active {
            box-shadow: 0 0 0 2px #ea580c, 0 6px 16px rgba(234, 88, 12, 0.18);
            border-color: #ea580c;
        }
        .foh-tile.tile-paused.active {
            box-shadow: 0 0 0 2px #f59e0b, 0 6px 16px rgba(245, 158, 11, 0.15);
            border-color: #f59e0b;
        }
        .foh-tile.tile-inactive.active {
            box-shadow: 0 0 0 2px #dc2626, 0 6px 16px rgba(220, 38, 38, 0.15);
            border-color: #dc2626;
        }

        .foh-tile-indicator {
            display: none;
            font-size: 0.75em;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .foh-tile.active .foh-tile-indicator { display: inline-block; }
        .foh-tile.tile-active.active .foh-tile-indicator   { background: #EEF7E3; color: #1B5E20; }
        .foh-tile.tile-followup.active .foh-tile-indicator { background: #ffedd5; color: #9a3412; }
        .foh-tile.tile-paused.active .foh-tile-indicator   { background: #fef3c7; color: #92400e; }
        .foh-tile.tile-inactive.active .foh-tile-indicator { background: #fee2e2; color: #991b1b; }

        .foh-tab-content { display: none; }
        .foh-tab-content.active { display: block; }
        
        .sub-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; font-size: 0.95em; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 14px rgba(27, 94, 32, 0.05); }
        .sub-table th { padding: 12px; border-bottom: 2px solid #DCECC9; text-align: left; background: #EEF7E3; color: #1B5E20; }
        .sub-table td { padding: 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
        .foh-row { transition: background-color 0.2s ease; }
        .foh-row:hover { background-color: #f8fafc !important; }
        
        .remark-edit-box { transition: all 0.3s ease; overflow: hidden; }
        .metric-progress-bar { width: 100%; background-color: #e2e8f0; border-radius: 4px; height: 6px; margin: 6px 0; overflow: hidden; }
        .metric-progress-fill { background-color: #379237; height: 100%; border-radius: 4px; transition: width 0.3s ease; }
        .edit-financials-btn:hover { color: #379237 !important; transform: scale(1.1); }

        @keyframes fohPulseUrgent {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.72; }
        }
        .foh-badge-urgent {
            display: block;
            margin-top: 6px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.78em;
            font-weight: 800;
            text-align: center;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            animation: fohPulseUrgent 2s infinite;
        }
        .foh-badge-warning {
            display: block;
            margin-top: 6px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.78em;
            font-weight: 800;
            text-align: center;
            background: #ffedd5;
            color: #9a3412;
            border: 1px solid #fdba74;
        }
        .foh-badge-meals {
            display: block;
            margin-top: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.76em;
            font-weight: 700;
            text-align: center;
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde047;
        }
    </style>

    <div style="max-width: 1200px; margin: 0 auto; font-family: inherit; padding: 0 12px;">
        
        <!-- HEADER -->
        <div class="foh-header-banner">
            <div>
                <h2>FOH Command Center</h2>
                <p>The Cycle Bistro &mdash; Manage Customer Subscriptions</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_foh_export_csv')); ?>" style="background: #379237; color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; border: 1px solid rgba(220,236,201,0.5); transition: background 0.2s;">Export CSV</a>
                <a href="<?php echo site_url('/kitchen-command-center/'); ?>" target="_blank" style="background: #1B5E20; color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; border: 1px solid rgba(220,236,201,0.5);">View Live Kitchen Report</a>
                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" style="background: #dc2626; color: white; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight:bold;">Log Out</a>
            </div>
        </div>

        <!-- INTERACTIVE KPI FILTER TILES -->
        <div class="foh-tile-grid">
            <div class="foh-tile tile-active active" onclick="switchTab(event, 'active-tab', false)">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="color:#1B5E20; font-size:0.85em; font-weight:800; text-transform:uppercase; letter-spacing:0.5px;">Active Customers</div>
                    <span class="foh-tile-indicator">Viewing</span>
                </div>
                <div style="font-size:2.2em; font-weight:900; color:#231F20; margin-top:4px; line-height:1;"><?php echo count($tabs['active']); ?></div>
            </div>

            <div class="foh-tile tile-followup" onclick="switchTab(event, 'active-tab', true)" title="Filter Active Customers whose schedule ends within 3 days, have 0 days scheduled, missing meal selections, or over-quota logs">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="color:#c2410c; font-size:0.82em; font-weight:800; text-transform:uppercase; letter-spacing:0.5px;">&#9888;&#65039; Schedule Ending / Unfilled</div>
                    <span class="foh-tile-indicator">Filtered</span>
                </div>
                <div style="display:flex; align-items:baseline; gap:8px; margin-top:4px;">
                    <div style="font-size:2.2em; font-weight:900; color:#ea580c; line-height:1;"><?php echo $followup_count; ?></div>
                    <span style="font-size:0.78em; color:#9a3412; font-weight:700;">Needs Follow-Up</span>
                </div>
            </div>

            <div class="foh-tile tile-paused" onclick="switchTab(event, 'paused-tab', false)">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="color:#64748b; font-size:0.85em; font-weight:800; text-transform:uppercase; letter-spacing:0.5px;">Paused Customers</div>
                    <span class="foh-tile-indicator">Viewing</span>
                </div>
                <div style="font-size:2.2em; font-weight:900; color:#231F20; margin-top:4px; line-height:1;"><?php echo count($tabs['paused']); ?></div>
            </div>

            <div class="foh-tile tile-inactive" onclick="switchTab(event, 'inactive-tab', false)">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="color:#64748b; font-size:0.85em; font-weight:800; text-transform:uppercase; letter-spacing:0.5px;">Inactive / Expired</div>
                    <span class="foh-tile-indicator">Viewing</span>
                </div>
                <div style="font-size:2.2em; font-weight:900; color:#231F20; margin-top:4px; line-height:1;"><?php echo count($tabs['inactive']); ?></div>
            </div>
        </div>

        <!-- SEARCH AND SORT BAR -->
        <div style="background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #DCECC9; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 250px;">
                <input type="text" id="fohSearch" placeholder="🔍 Search by Order ID, Name, Email, Phone, or Plan..." style="width: 100%; padding: 12px 15px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 1.05em; box-shadow: inset 0 1px 3px rgba(0,0,0,0.03); box-sizing: border-box;">
            </div>
            <div style="min-width: 240px;">
                <select id="fohSort" style="width: 100%; padding: 12px 15px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 1.05em; background: #fff; cursor:pointer; box-sizing: border-box;">
                    <option value="default">Sort By: Default (Newest)</option>
                    <option value="schedule_ending_asc">Schedule Ending Soonest (Urgent Follow-Up)</option>
                    <option value="pending_fill_desc">Pending Fill Days (High to Low)</option>
                    <option value="balance_asc">Balance Days (Low to High)</option>
                    <option value="balance_desc">Balance Days (High to Low)</option>
                    <option value="filled_desc">Filled Days (High to Low)</option>
                    <option value="used_desc">Used Days (High to Low)</option>
                </select>
            </div>
        </div>

        <?php foreach(array('active', 'paused', 'inactive') as $tab_key): ?>
        <div id="<?php echo $tab_key; ?>-tab" class="foh-tab-content <?php echo $tab_key == 'active' ? 'active' : ''; ?>">
            <div style="overflow-x: auto;">
                <table class="sub-table">
                    <thead>
                        <tr>
                            <th style="width: 8%;">Order ID</th>
                            <th style="width: 22%;">Customer Info</th>
                            <th style="width: 22%;">Plan Details</th>
                            <th style="width: 19%; text-align: center;">Tracking Metrics</th>
                            <th style="width: 13%;">Expiry</th>
                            <th style="width: 16%; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($tabs[$tab_key])): ?>
                            <tr class="empty-row"><td colspan="6" style="text-align:center; padding:30px; color:#666;">No subscriptions found in this category.</td></tr>
                        <?php else: foreach($tabs[$tab_key] as $sub): 
                            $order = wc_get_order($sub->wc_order_id);
                            
                            $fname = get_user_meta($sub->user_id, 'first_name', true) ?: get_user_meta($sub->user_id, 'billing_first_name', true);
                            $lname = get_user_meta($sub->user_id, 'last_name', true) ?: get_user_meta($sub->user_id, 'billing_last_name', true);
                            $fallback_name = trim($fname . ' ' . $lname);
                            if (empty($fallback_name)) $fallback_name = $sub->display_name;
                            
                            $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $fallback_name;
                            if (empty(trim($full_name))) { $full_name = $fallback_name; }

                            $phone = $order ? $order->get_billing_phone() : (get_user_meta($sub->user_id, 'billing_phone', true) ?: 'N/A');
                            $is_paused = ($sub->status === 'paused');
                            
                            // Recipient Extraction
                            $plan_parts = explode(' - ', $sub->plan_name);
                            $potential_recipient = end($plan_parts);
                            $recipient_badge = '';
                            $raw_recipient = '';
                            
                            if (!preg_match('/(Meal|Day|Cleanse|Plans)/i', $potential_recipient) && count($plan_parts) > 1) {
                                $raw_recipient = $potential_recipient;
                                $recipient_badge = ' <span style="background:#EEF7E3; color:#1B5E20; border:1px solid #DCECC9; padding:2px 8px; border-radius:12px; font-size:0.85em; margin-left:8px; display:inline-block; margin-bottom: 5px;">For: <strong>' . esc_html($potential_recipient) . '</strong></span>';
                                array_pop($plan_parts);
                                $core_plan_name = implode(' - ', $plan_parts);
                            } else {
                                $core_plan_name = $sub->plan_name;
                            }

                            // Dynamic Sequence M-0000
                            $display_order_id = '';
                            if ($sub->wc_order_id < 0) {
                                $display_order_id = 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT);
                            } elseif ($sub->wc_order_id > 0) {
                                $display_order_id = '#' . esc_html($sub->wc_order_id);
                            } else {
                                $display_order_id = 'M-' . str_pad($sub->id, 4, '0', STR_PAD_LEFT);
                            }

                            $search_data = esc_attr(strtolower($display_order_id . ' ' . $full_name . ' ' . $sub->user_email . ' ' . $phone . ' ' . $raw_recipient . ' ' . $core_plan_name));
                            $plan_data = esc_attr($core_plan_name);

                            $base_price = isset($sub->plan_price) ? (float) $sub->plan_price : 0;
                            $discount   = isset($sub->discount_amount) ? (float) $sub->discount_amount : 0;
                            
                            if ($base_price == 0 && $order) {
                                $base_price = (float) $order->get_subtotal();
                                $discount = (float) $order->get_discount_total();
                            }
                            
                            $final_price = max(0, $base_price - $discount);
                            $currency = $order ? get_woocommerce_currency_symbol($order->get_currency()) : 'AED';
                            
                            if ($discount > 0 && $base_price > 0) {
                                $discount_percentage = round(($discount / $base_price) * 100);
                                $price_display = $currency . ' ' . number_format($final_price, 2) . ' <span style="color:#d63638; font-weight:bold; font-size:0.9em;">(-' . $discount_percentage . '%)</span>';
                            } else {
                                $price_display = $currency . ' ' . number_format($final_price, 2);
                            }

                            $remark_text = !empty($sub->foh_remarks) ? $sub->foh_remarks : '';
                            $short_remark = mb_strimwidth($remark_text, 0, 32, '...');
                            $preview_html = empty($remark_text) ? '<span style="color:#94a3b8; font-style:italic; font-weight:normal;">No remarks</span>' : esc_html($short_remark);
                            
                            $usage_percent = ($sub->total_days > 0) ? min(100, round(($sub->usage / $sub->total_days) * 100)) : 0;
                            
                            $deposit_held = get_user_meta($sub->user_id, '_cmp_deposit_held', true);
                            if ($deposit_held === '') { $deposit_held = 'yes'; } 
                            
                            $status_color = '#dc2626'; $status_bg = '#fee2e2'; $status_text = 'Refunded / No Deposit';
                            if ($deposit_held === 'yes') { $status_color = '#379237'; $status_bg = '#dcfce7'; $status_text = 'Bag Deposit Held'; }
                            if ($deposit_held === 'own_bag') { $status_color = '#8b5cf6'; $status_bg = '#ede9fe'; $status_text = "Customer's Own Bag"; }

                            // Mutually Exclusive Modes
                            $is_flexible = isset($sub->is_flexible) ? intval($sub->is_flexible) : 0;
                            $allow_custom = isset($sub->allow_custom) ? intval($sub->allow_custom) : 0;
                            $current_mode = 'standard';
                            if ($is_flexible) $current_mode = 'flexible';
                            if ($allow_custom) $current_mode = 'custom';

                            $can_edit_details = current_user_can('manage_options') || current_user_can('foh_manager') || current_user_can('menu_manager');

                            // Build Schedule Urgency Badges
                            $schedule_badges_html = '';
                            if ($sub->over_quota > 0) {
                                $schedule_badges_html .= '<span class="foh-badge-urgent" title="Customer has more scheduled rows in database than their current Total Days. Open Customer Portal to reset excess days.">&#9888;&#65039; Over-Quota (+' . intval($sub->over_quota) . 'd) &mdash; Clear in Portal</span>';
                            } elseif ($tab_key === 'active' && $sub->pending_fill > 0) {
                                if (empty($sub->last_scheduled_date)) {
                                    $schedule_badges_html .= '<span class="foh-badge-urgent">&#9888;&#65039; No Days Scheduled (' . intval($sub->pending_fill) . 'd Unfilled)</span>';
                                } elseif ($sub->days_until_end < 0) {
                                    $schedule_badges_html .= '<span class="foh-badge-urgent">&#9888;&#65039; Schedule Ended (' . intval($sub->pending_fill) . 'd Unfilled)</span>';
                                } elseif ($sub->days_until_end === 0) {
                                    $schedule_badges_html .= '<span class="foh-badge-urgent">&#9888;&#65039; Ends Today (' . intval($sub->pending_fill) . 'd Unfilled)</span>';
                                } elseif ($sub->days_until_end === 1) {
                                    $schedule_badges_html .= '<span class="foh-badge-urgent">&#9888;&#65039; Ends Tomorrow (' . intval($sub->pending_fill) . 'd Unfilled)</span>';
                                } elseif ($sub->days_until_end <= 3) {
                                    $schedule_badges_html .= '<span class="foh-badge-warning">&#9203; Ends in ' . intval($sub->days_until_end) . ' Days (' . intval($sub->pending_fill) . 'd Unfilled)</span>';
                                }
                            }
                            if ($tab_key === 'active' && $sub->unpicked_meals_count > 0) {
                                $schedule_badges_html .= '<span class="foh-badge-meals">&#127869;&#65039; ' . intval($sub->unpicked_meals_count) . ' Date(s) Missing Meals</span>';
                            }
                        ?>
                        <tr class="foh-row" 
                            data-search="<?php echo $search_data; ?>" 
                            data-plan="<?php echo $plan_data; ?>" 
                            data-balance="<?php echo esc_attr($sub->balance); ?>" 
                            data-filled="<?php echo esc_attr($sub->filled); ?>" 
                            data-used="<?php echo esc_attr($sub->usage); ?>" 
                            data-pending-fill="<?php echo esc_attr($sub->pending_fill); ?>"
                            data-last-scheduled-ts="<?php echo esc_attr($sub->sort_end_ts); ?>"
                            data-followup="<?php echo $sub->needs_followup ? '1' : '0'; ?>"
                            id="sub-row-<?php echo $sub->id; ?>">
                            
                            <td style="font-size: 1.1em; font-weight: bold; color: #231F20;">
                                <?php echo $display_order_id; ?>
                            </td>
                            
                            <td>
                                <div style="display: flex; flex-direction: column;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <div>
                                            <a href="<?php echo site_url('/my-meal-portal/?admin_edit_sub=' . $sub->id); ?>" target="_blank" style="text-decoration: none;" title="Open Customer Portal in New Tab">
                                                <strong style="font-size: 1.1em; color: #379237; border-bottom: 1px dotted #379237; transition: color 0.2s;" id="name-val-<?php echo $sub->id; ?>" onmouseover="this.style.color='#1B5E20'" onmouseout="this.style.color='#379237'"><?php echo esc_html($full_name ?: 'Customer'); ?></strong>
                                            </a>
                                        </div>
                                        <?php if ($can_edit_details): ?>
                                            <button class="toggle-account-edit" data-sub-id="<?php echo $sub->id; ?>" style="background:none; border:none; color:#379237; cursor:pointer; padding:0; display:inline-flex; align-items:center; transition:color 0.2s;" title="Edit Account & Mode">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div id="recipient-row-<?php echo $sub->id; ?>" style="display:<?php echo !empty($recipient_badge) ? 'block' : 'none'; ?>">
                                        <span id="recipient-val-<?php echo $sub->id; ?>"><?php echo $recipient_badge; ?></span>
                                    </div>
                                    <span style="color: #555;"><?php echo esc_html($sub->user_email); ?></span>
                                    <span style="color: #555; display: inline-flex; align-items: center; gap: 5px; margin-top: 3px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#379237" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                        <?php echo esc_html($phone); ?>
                                    </span>
                                </div>
                                
                                <!-- ACCOUNT & MUTUALLY EXCLUSIVE PLAN MODE EDIT BOX -->
                                <?php if ($can_edit_details): ?>
                                <div id="account-edit-<?php echo $sub->id; ?>" style="display:none; margin-top:10px; background:#f8fafc; padding:10px; border:1px solid #cbd5e1; border-radius:4px;">
                                    <div style="display:flex; gap:6px; margin-bottom:8px;">
                                        <input type="text" id="edit-fname-<?php echo $sub->id; ?>" value="<?php echo esc_attr($fname); ?>" placeholder="First Name" style="width:50%; padding:4px; font-size:0.85em; border:1px solid #cbd5e1; border-radius:3px;">
                                        <input type="text" id="edit-lname-<?php echo $sub->id; ?>" value="<?php echo esc_attr($lname); ?>" placeholder="Last Name" style="width:50%; padding:4px; font-size:0.85em; border:1px solid #cbd5e1; border-radius:3px;">
                                    </div>
                                    <div style="margin-bottom:8px;">
                                        <input type="text" id="edit-recipient-<?php echo $sub->id; ?>" value="<?php echo esc_attr($raw_recipient); ?>" placeholder="Recipient Name (Optional)" style="width:100%; padding:4px; font-size:0.85em; border:1px solid #cbd5e1; border-radius:3px;">
                                    </div>
                                    
                                    <div style="margin-bottom:10px; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
                                        <label style="font-size:0.8em; font-weight:bold; display:block; margin-bottom: 4px; color:#334155;">Plan Mode (Menu Type):</label>
                                        <select id="edit-plan-mode-<?php echo $sub->id; ?>" style="width:100%; padding:4px; font-size:0.85em; border:1px solid #cbd5e1; border-radius:3px;">
                                            <option value="standard" <?php selected($current_mode, 'standard'); ?>>Standard Menu (Strict Quotas)</option>
                                            <option value="flexible" <?php selected($current_mode, 'flexible'); ?>>Flexible Menu (Universal Dropdowns)</option>
                                            <option value="custom" <?php selected($current_mode, 'custom'); ?>>Bespoke Custom (Text Inputs)</option>
                                        </select>
                                    </div>

                                    <button class="save-account-btn" data-sub-id="<?php echo $sub->id; ?>" data-user-id="<?php echo $sub->user_id; ?>" data-order-id="<?php echo $sub->wc_order_id; ?>" style="background:#379237; color:#fff; border:none; padding:4px 8px; border-radius:3px; font-size:0.85em; font-weight:bold; cursor:pointer; width:100%;">Save Details</button>
                                </div>
                                <?php endif; ?>

                            </td>
                            <td>
                                <strong style="color: #231F20;" id="tab-title-<?php echo $sub->id; ?>"><?php echo esc_html($core_plan_name); ?></strong><br>
                                
                                <div style="margin-top:5px; display:flex; align-items:center; flex-wrap:wrap; gap:8px;">
                                    <span style="color: #1B5E20; font-weight:bold;" id="price-display-<?php echo $sub->id; ?>">Price: <?php echo $price_display; ?></span>
                                    
                                    <?php if (current_user_can('manage_options') || current_user_can('menu_manager')): ?>
                                        <button class="edit-financials-btn" data-target="fin-edit-<?php echo $sub->id; ?>" style="background:none; border:none; color:#94a3b8; cursor:pointer; padding:0; display:inline-flex; align-items:center; transition:color 0.2s;" title="Edit Pricing">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if (current_user_can('manage_options') || current_user_can('menu_manager')): ?>
                                    <div id="fin-edit-<?php echo $sub->id; ?>" class="fin-edit-box" style="display:none; margin-top:8px; background:#f8fafc; padding:10px; border:1px solid #cbd5e1; border-radius:4px;">
                                        <label style="font-size:0.8em; font-weight:bold;">Base Price (AED)</label>
                                        <input type="number" step="0.01" id="fin-price-<?php echo $sub->id; ?>" value="<?php echo esc_attr($base_price); ?>" style="width:100%; padding:4px; margin-bottom:5px; font-size:0.9em;">
                                        <label style="font-size:0.8em; font-weight:bold;">Discount (AED)</label>
                                        <input type="number" step="0.01" id="fin-discount-<?php echo $sub->id; ?>" value="<?php echo esc_attr($discount); ?>" style="width:100%; padding:4px; margin-bottom:8px; font-size:0.9em;">
                                        <button class="save-financials-btn" data-sub-id="<?php echo $sub->id; ?>" style="background:#379237; color:#fff; border:none; padding:4px 8px; border-radius:3px; font-size:0.85em; cursor:pointer; width:100%;">Save Financials</button>
                                    </div>
                                <?php endif; ?>
                                
                                <div style="margin-top: 8px; background: #f8fafc; padding: 8px; border-radius: 4px; border: 1px solid #e2e8f0;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <span style="font-size:0.9em; color:#475569;"><strong id="remark-preview-<?php echo $sub->id; ?>"><?php echo $preview_html; ?></strong></span>
                                        <button class="toggle-remark-btn" data-target="remark-box-<?php echo $sub->id; ?>" style="background:none; border:none; color:#379237; cursor:pointer; font-size:0.85em; font-weight:bold; padding:0;">Edit</button>
                                    </div>
                                    <div id="remark-box-<?php echo $sub->id; ?>" class="remark-edit-box" style="display:none; margin-top:8px;">
                                        <textarea id="remark-text-<?php echo $sub->id; ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; font-size:0.9em; min-height:60px; box-sizing:border-box;" placeholder="Add specific delivery or kitchen notes here..."><?php echo esc_textarea($remark_text); ?></textarea>
                                        <button class="save-remark-btn" data-sub-id="<?php echo $sub->id; ?>" style="margin-top:4px; background:#379237; color:#fff; border:none; padding:4px 8px; border-radius:3px; font-size:0.85em; cursor:pointer; width:100%;">Save Note</button>
                                    </div>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <div style="background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid <?php echo $sub->over_quota > 0 ? '#fca5a5' : '#e2e8f0'; ?>; text-align: left; width: 100%; box-sizing: border-box;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.85em; margin-bottom: 2px;">
                                        <span style="color: <?php echo $sub->over_quota > 0 ? '#dc2626' : '#64748b'; ?>;">Filled: <strong><?php echo $sub->filled; ?>/<?php echo $sub->total_days; ?></strong></span>
                                        <span style="color: #379237;">Used: <strong><?php echo $sub->usage; ?></strong></span>
                                    </div>
                                    <div class="metric-progress-bar">
                                        <div class="metric-progress-fill" style="width: <?php echo $usage_percent; ?>%;"></div>
                                    </div>

                                    <!-- Pending Fill & Last Scheduled Date -->
                                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.8em; color: #475569; margin: 6px 0 4px 0; padding-top: 4px; border-top: 1px dashed #e2e8f0;">
                                        <span>
                                            <?php if ($sub->over_quota > 0): ?>
                                                <strong style="color:#dc2626;">Over: +<?php echo intval($sub->over_quota); ?>d</strong>
                                            <?php elseif ($sub->pending_fill > 0): ?>
                                                Pending Fill: <strong style="color:#d97706;"><?php echo intval($sub->pending_fill); ?>d</strong>
                                            <?php else: ?>
                                                <strong style="color:#15803d;">All Filled &#10003;</strong>
                                            <?php endif; ?>
                                        </span>
                                        <span title="Last Scheduled Delivery Date">
                                            Ends: <strong><?php echo !empty($sub->last_scheduled_date) ? date('M j', strtotime($sub->last_scheduled_date)) : '&mdash;'; ?></strong>
                                        </span>
                                    </div>

                                    <div style="color: #1B5E20; font-size: 1.05em; font-weight: bold; text-align: center; border-top: 1px solid #cbd5e1; padding-top: 6px; margin-top: 4px;">
                                        Balance: <?php echo $sub->balance; ?> Days
                                    </div>

                                    <?php echo $schedule_badges_html; ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-size: 0.85em; color: #666;">Started: <?php echo date('M j, Y', strtotime($sub->start_date)); ?></span><br>
                                <form class="ajax-expiry-form" style="display: flex; flex-direction: column; gap: 5px; margin-top: 5px;">
                                    <input type="hidden" name="sub_id" value="<?php echo $sub->id; ?>">
                                    <input type="date" name="new_expiry" value="<?php echo date('Y-m-d', strtotime($sub->expiry_date)); ?>" style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
                                    <button type="submit" class="expiry-btn" style="width: 100%; background: #379237; color: white; border: none; padding: 6px 10px; border-radius: 4px; font-weight: bold; cursor: pointer; transition: background 0.2s;">Update</button>
                                </form>
                            </td>
                            <td style="vertical-align: middle;">
                                <div style="display: flex; flex-direction: column; gap: 8px; align-items: center; width: 100%;">
                                    
                                    <div style="display: flex; gap: 5px; width: 100%;">
                                        <?php if($tab_key !== 'inactive'): ?>
                                        <form class="ajax-status-form" style="margin: 0; flex: 1; display: flex;">
                                            <input type="hidden" name="sub_id" value="<?php echo $sub->id; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $is_paused ? 'active' : 'paused'; ?>">
                                            <button type="submit" class="status-btn" style="flex: 1; height: 32px; display: flex; align-items: center; justify-content: center; background: <?php echo $is_paused ? '#379237' : '#dc2626'; ?>; color: white; border: none; padding: 0 6px; border-radius: 4px; font-weight: bold; cursor: pointer; box-sizing: border-box; transition: background 0.2s;" title="<?php echo $is_paused ? 'Resume Plan' : 'Stop Plan'; ?>">
                                                <?php echo $is_paused ? $icon_play : $icon_stop; ?>
                                            </button>
                                        </form>
                                        <!-- BULK CANCEL DAYS BUTTON -->
                                        <button class="manage-days-btn" data-sub-id="<?php echo $sub->id; ?>" style="flex: 1; height: 32px; background: #f59e0b; color: white; border: none; padding: 0 6px; border-radius: 4px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s;" title="Hold / Pause Specific Upcoming Days">
                                            <?php echo $icon_pause; ?>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <a href="<?php echo site_url('/my-meal-portal/?admin_edit_sub=' . $sub->id); ?>" target="_blank" style="flex: 1; height: 32px; display: flex; align-items: center; justify-content: center; background: #1B5E20; color: white; text-decoration: none; padding: 0 6px; border-radius: 4px; font-weight: bold; box-sizing: border-box;" title="Edit / View in Customer Portal">
                                            <?php echo $icon_edit; ?>
                                        </a>
                                        
                                        <!-- SUPER ADMIN ONLY: PERMANENT SUBSCRIPTION DELETE KILL-SWITCH -->
                                        <?php if (current_user_can('manage_options')): ?>
                                        <button class="delete-sub-btn" data-sub-id="<?php echo $sub->id; ?>" data-order-id="<?php echo esc_attr($display_order_id); ?>" style="flex: 0 0 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: #fff; color: #dc2626; border: 1px solid #fca5a5; border-radius: 4px; cursor: pointer; transition: 0.2s; padding: 0;" title="Delete Plan" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fff'">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="width: 100%; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
                                        <div class="deposit-pill-wrapper" data-user-id="<?php echo $sub->user_id; ?>">
                                            <span class="deposit-status-pill" style="color: <?php echo $status_color; ?>; font-weight: bold; background: <?php echo $status_bg; ?>; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; display: inline-block; text-align: center; cursor: pointer; border: 1px solid <?php echo $status_color; ?>; transition: 0.2s; width: 100%; box-sizing: border-box;" title="Click to Change Status">
                                                <?php echo $status_text; ?> &#9662;
                                            </span>
                                            <select class="deposit-status-select" style="display:none; width: 100%; padding: 4px; font-size: 0.85em; border-radius: 4px; border: 1px solid #cbd5e1;">
                                                <option value="yes" <?php selected($deposit_held, 'yes'); ?>>Held (Waived)</option>
                                                <option value="own_bag" <?php selected($deposit_held, 'own_bag'); ?>>Own Bag (Waived)</option>
                                                <option value="no" <?php selected($deposit_held, 'no'); ?>>Refunded / No Deposit</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <div id="fohPagination" style="margin-top: 20px; display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;"></div>
    </div>

    <!-- MANAGE DAYS AJAX MODAL -->
    <div id="foh-manage-days-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center;">
        <div style="background:#fff; width:90%; max-width:600px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.2); display:flex; flex-direction:column; max-height:90vh; overflow:hidden;">
            <div style="padding:20px; border-bottom:1px solid #DCECC9; background:#EEF7E3; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; color:#1B5E20;">Hold Upcoming Days</h3>
                <button class="close-modal-btn" style="background:none; border:none; font-size:1.5em; cursor:pointer; color:#1B5E20;">&times;</button>
            </div>
            <div style="padding:20px; overflow-y:auto; flex:1;" id="foh-modal-body">
                <p style="text-align:center; color:#64748b;">Loading...</p>
            </div>
            <div style="padding:20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:6px; font-size:0.85em; color:#64748b;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> 
                    = Past Kitchen Cut-off
                </div>
                <div style="display:flex; gap:10px;">
                    <button class="close-modal-btn" style="background:#e2e8f0; color:#334155; border:none; padding:10px 15px; border-radius:6px; font-weight:bold; cursor:pointer;">Close</button>
                    <button id="foh-confirm-cancel-btn" data-sub-id="" style="display:none; background:#dc2626; color:#fff; border:none; padding:10px 15px; border-radius:6px; font-weight:bold; cursor:pointer; transition:0.2s;">Hold Selected Days</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    const fohAjaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
    const fohNonce   = '<?php echo wp_create_nonce("cmp_foh_nonce"); ?>';

    // Core FOH Action SVGs mapped for JavaScript toggles
    const stopIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect></svg>';
    const playIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>';

    document.addEventListener("DOMContentLoaded", function() {
        
        // Expiry Form
        document.querySelectorAll('.ajax-expiry-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = this.querySelector('.expiry-btn');
                const subId = this.querySelector('input[name="sub_id"]').value;
                const newExpiry = this.querySelector('input[name="new_expiry"]').value;

                const originalText = btn.innerText;
                const originalBg = btn.style.background;

                btn.innerText = '...';
                btn.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_update_expiry');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);
                formData.append('new_expiry', newExpiry);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        btn.innerText = '✓ Saved';
                        btn.style.background = '#1B5E20';
                        setTimeout(() => {
                            btn.innerText = originalText;
                            btn.style.background = originalBg;
                            btn.disabled = false;
                        }, 2000);
                    } else {
                        alert('Error updating expiry.');
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                }).catch(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
            });
        });

        // Status Form (Stop/Resume)
        document.querySelectorAll('.ajax-status-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = this.querySelector('.status-btn');
                const subId = this.querySelector('input[name="sub_id"]').value;
                const statusInput = this.querySelector('input[name="new_status"]');
                const newStatus = statusInput.value;

                btn.disabled = true;
                btn.style.opacity = '0.7';

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_toggle_status');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);
                formData.append('new_status', newStatus);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        if (newStatus === 'active') {
                            btn.innerHTML = stopIcon;
                            btn.style.background = '#dc2626';
                            btn.title = 'Stop Plan';
                            statusInput.value = 'paused';
                        } else {
                            btn.innerHTML = playIcon;
                            btn.style.background = '#379237';
                            btn.title = 'Resume Plan';
                            statusInput.value = 'active';
                        }
                        btn.style.opacity = '1';
                        btn.disabled = false;
                    } else {
                        alert('Error updating status.');
                        btn.style.opacity = '1';
                        btn.disabled = false;
                    }
                }).catch(() => {
                    btn.style.opacity = '1';
                    btn.disabled = false;
                });
            });
        });

        // Interactive Bag Status Pill
        document.querySelectorAll('.deposit-status-pill').forEach(pill => {
            pill.addEventListener('click', function(e) {
                let wrapper = this.closest('.deposit-pill-wrapper');
                let select = wrapper.querySelector('.deposit-status-select');
                this.style.display = 'none';
                select.style.display = 'block';
                select.focus();
            });
        });

        document.querySelectorAll('.deposit-status-select').forEach(select => {
            select.addEventListener('change', function(e) {
                let wrapper = this.closest('.deposit-pill-wrapper');
                let pill = wrapper.querySelector('.deposit-status-pill');
                let userId = wrapper.getAttribute('data-user-id');
                let newStatus = this.value;

                this.disabled = true;
                pill.innerHTML = 'Updating...';
                pill.style.display = 'inline-block';
                this.style.display = 'none';

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_toggle_deposit');
                formData.append('nonce', fohNonce);
                formData.append('user_id', userId);
                formData.append('new_status', newStatus);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        if (newStatus === 'yes') {
                            pill.innerHTML = 'Bag Deposit Held &#9662;';
                            pill.style.background = '#dcfce7'; pill.style.color = '#379237'; pill.style.borderColor = '#379237';
                        } else if (newStatus === 'own_bag') {
                            pill.innerHTML = "Customer's Own Bag &#9662;";
                            pill.style.background = '#ede9fe'; pill.style.color = '#8b5cf6'; pill.style.borderColor = '#8b5cf6';
                        } else {
                            pill.innerHTML = 'Refunded / No Deposit &#9662;';
                            pill.style.background = '#fee2e2'; pill.style.color = '#dc2626'; pill.style.borderColor = '#dc2626';
                        }
                        
                        document.querySelectorAll(`.deposit-pill-wrapper[data-user-id="${userId}"]`).forEach(otherWrap => {
                            otherWrap.querySelector('.deposit-status-select').value = newStatus;
                            let otherPill = otherWrap.querySelector('.deposit-status-pill');
                            otherPill.innerHTML = pill.innerHTML;
                            otherPill.style.background = pill.style.background;
                            otherPill.style.color = pill.style.color;
                            otherPill.style.borderColor = pill.style.borderColor;
                        });

                    } else {
                        alert('Error updating status.');
                        pill.innerHTML = 'Error &#9662;';
                    }
                }).catch(() => {
                    alert('Connection error.');
                }).finally(() => {
                    this.disabled = false;
                });
            });
            
            select.addEventListener('blur', function() {
                this.style.display = 'none';
                let wrapper = this.closest('.deposit-pill-wrapper');
                wrapper.querySelector('.deposit-status-pill').style.display = 'inline-block';
            });
        });

        // Edit Financials Logic
        document.querySelectorAll('.edit-financials-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('data-target');
                const box = document.getElementById(targetId);
                if (box.style.display === 'none') {
                    box.style.display = 'block';
                    this.innerHTML = '<span style="font-size:0.85em; text-decoration:underline;">Close</span>';
                } else {
                    box.style.display = 'none';
                    this.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
                }
            });
        });

        document.querySelectorAll('.save-financials-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const subId = this.getAttribute('data-sub-id');
                const priceVal = document.getElementById('fin-price-' + subId).value;
                const discountVal = document.getElementById('fin-discount-' + subId).value;
                const originalText = this.innerText;
                
                this.innerText = 'Saving...';
                this.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_update_financials');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);
                formData.append('plan_price', priceVal);
                formData.append('discount_amount', discountVal);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        this.innerText = '✓ Saved';
                        this.style.background = '#1B5E20';
                        
                        let p = parseFloat(response.data.price);
                        let d = parseFloat(response.data.discount);
                        let fp = Math.max(0, p - d);
                        
                        let displayHtml = 'Price: AED ' + fp.toFixed(2);
                        if (d > 0 && p > 0) {
                            let pct = Math.round((d / p) * 100);
                            displayHtml += ' <span style="color:#d63638; font-weight:bold; font-size:0.9em;">(-' + pct + '%)</span>';
                        }
                        document.getElementById('price-display-' + subId).innerHTML = displayHtml;

                        setTimeout(() => {
                            this.innerText = 'Save Financials';
                            this.style.background = '#379237';
                            this.disabled = false;
                            document.getElementById('fin-edit-' + subId).style.display = 'none';
                            document.querySelector('.edit-financials-btn[data-target="fin-edit-' + subId + '"]').innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
                        }, 1000);
                    } else {
                        alert('Error saving financials.');
                        this.innerText = originalText;
                        this.disabled = false;
                    }
                }).catch(() => {
                    this.innerText = originalText;
                    this.disabled = false;
                });
            });
        });

        // Remarks Toggle Logic
        document.querySelectorAll('.toggle-remark-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('data-target');
                const box = document.getElementById(targetId);
                if (box.style.display === 'none') {
                    box.style.display = 'block';
                    this.innerText = 'Close';
                } else {
                    box.style.display = 'none';
                    this.innerText = 'Edit';
                }
            });
        });

        document.querySelectorAll('.save-remark-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const subId = this.getAttribute('data-sub-id');
                const textVal = document.getElementById('remark-text-' + subId).value;
                const originalText = this.innerText;
                
                this.innerText = 'Saving...';
                this.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_save_remark');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);
                formData.append('remark', textVal);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        this.innerText = '✓ Saved';
                        this.style.background = '#1B5E20';
                        
                        let displayTxt = textVal.trim();
                        let newPreview = displayTxt.length > 0 
                            ? (displayTxt.length > 32 ? displayTxt.substring(0, 32).replace(/</g, "&lt;").replace(/>/g, "&gt;") + '...' : displayTxt.replace(/</g, "&lt;").replace(/>/g, "&gt;")) 
                            : '<span style="color:#94a3b8; font-style:italic; font-weight:normal;">No remarks</span>';
                        
                        document.getElementById('remark-preview-' + subId).innerHTML = newPreview;

                        setTimeout(() => {
                            this.innerText = 'Save Note';
                            this.style.background = '#379237';
                            this.disabled = false;
                            
                            document.getElementById('remark-box-' + subId).style.display = 'none';
                            document.querySelector('.toggle-remark-btn[data-target="remark-box-' + subId + '"]').innerText = 'Edit';
                        }, 1000);
                    } else {
                        alert('Error saving note.');
                        this.innerText = originalText;
                        this.disabled = false;
                    }
                }).catch(() => {
                    this.innerText = originalText;
                    this.disabled = false;
                });
            });
        });

        // Account Details Edit Toggle
        document.querySelectorAll('.toggle-account-edit').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const subId = this.getAttribute('data-sub-id');
                const editDiv = document.getElementById('account-edit-' + subId);
                
                if (editDiv.style.display === 'none') {
                    editDiv.style.display = 'block';
                    this.innerHTML = '<span style="font-size:0.85em; text-decoration:underline;">Close</span>';
                } else {
                    editDiv.style.display = 'none';
                    this.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
                }
            });
        });

        // Save Account Details
        document.querySelectorAll('.save-account-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const subId = this.getAttribute('data-sub-id');
                const userId = this.getAttribute('data-user-id');
                const orderId = this.getAttribute('data-order-id');
                
                const fName = document.getElementById('edit-fname-' + subId).value.trim();
                const lName = document.getElementById('edit-lname-' + subId).value.trim();
                const recipient = document.getElementById('edit-recipient-' + subId).value.trim();
                const planMode = document.getElementById('edit-plan-mode-' + subId).value;

                const originalText = this.innerText;

                if (!fName) {
                    alert("First Name is required.");
                    return;
                }

                this.innerText = 'Saving...';
                this.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_update_account_details');
                formData.append('nonce', fohNonce);
                formData.append('user_id', userId);
                formData.append('order_id', orderId);
                formData.append('sub_id', subId);
                formData.append('first_name', fName);
                formData.append('last_name', lName);
                formData.append('recipient_name', recipient);
                formData.append('plan_mode', planMode);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        document.getElementById('name-val-' + subId).innerText = response.data.name;
                        
                        let badgeContainer = document.getElementById('recipient-row-' + subId);
                        let badgeVal = document.getElementById('recipient-val-' + subId);
                        
                        if (response.data.recipient) {
                            badgeVal.innerHTML = ' <span style="background:#EEF7E3; color:#1B5E20; border:1px solid #DCECC9; padding:2px 8px; border-radius:12px; font-size:0.85em; font-weight:bold; margin-left:8px; vertical-align:middle; display:inline-block;">For: ' + response.data.recipient + '</span>';
                            badgeContainer.style.display = 'block';
                        } else {
                            badgeContainer.style.display = 'none';
                        }

                        if (response.data.plan_name) {
                            document.getElementById('tab-title-' + subId).innerText = response.data.plan_name;
                        }
                        
                        this.innerText = '✓ Saved';
                        this.style.background = '#1B5E20';
                        
                        setTimeout(() => {
                            document.getElementById('account-edit-' + subId).style.display = 'none';
                            let editBtn = document.querySelector('.toggle-account-edit[data-sub-id="' + subId + '"]');
                            if(editBtn) editBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
                            this.innerText = originalText;
                            this.style.background = '#379237';
                            this.disabled = false;
                        }, 1000);
                    } else {
                        alert('Error: ' + response.data);
                        this.innerText = originalText;
                        this.disabled = false;
                    }
                }).catch(() => {
                    this.innerText = originalText;
                    this.disabled = false;
                });
            });
        });

        // MANAGE DAYS AJAX MODAL LOGIC
        const modal = document.getElementById('foh-manage-days-modal');
        const modalBody = document.getElementById('foh-modal-body');
        const confirmBtn = document.getElementById('foh-confirm-cancel-btn');

        const warningSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-left: 5px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';

        jQuery('.manage-days-btn').on('click', function(e) {
            e.preventDefault();
            const subId = jQuery(this).data('sub-id');
            modal.style.display = 'flex';
            modalBody.innerHTML = '<p style="text-align:center; padding:20px; color:#64748b;">Fetching upcoming schedule...</p>';
            confirmBtn.dataset.subId = subId;
            confirmBtn.style.display = 'none';

            var data = {
                action: 'cmp_foh_fetch_upcoming_meals',
                nonce: fohNonce,
                sub_id: subId
            };

            jQuery.post(fohAjaxUrl, data, function(res) {
                if(res.success) {
                    if(res.data.length === 0) {
                        modalBody.innerHTML = '<p style="text-align:center; padding:20px; color:#64748b;">No upcoming scheduled days available to manage.</p>';
                    } else {
                        let html = '<div style="margin-bottom:10px;"><label style="font-weight:bold; cursor:pointer;"><input type="checkbox" id="modal-select-all"> Select All Dates</label></div>';
                        html += '<table style="width:100%; border-collapse:collapse; font-size:0.95em;">';
                        html += '<thead><tr style="background:#EEF7E3; color:#1B5E20; border-bottom:2px solid #DCECC9;"><th style="padding:10px; text-align:left; width:40px;"></th><th style="padding:10px; text-align:left;">Date</th><th style="padding:10px; text-align:left;">Status</th></tr></thead><tbody>';
                        
                        res.data.forEach(function(day) {
                            let warning = day.past_cutoff ? ' <span title="Past Cut-off Time. Kitchen may have already started prepping this." style="cursor:help; display:inline-block;">' + warningSvg + '</span>' : '';
                            html += '<tr style="border-bottom:1px solid #e2e8f0;">';
                            html += '<td style="padding:10px;"><input type="checkbox" class="modal-day-checkbox" value="'+day.id+'"></td>';
                            html += '<td style="padding:10px;">' + day.date + warning + '</td>';
                            html += '<td style="padding:10px; color:#64748b;">' + day.status + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                        
                        modalBody.innerHTML = html;
                        confirmBtn.style.display = 'block';

                        jQuery('#modal-select-all').on('change', function() {
                            jQuery('.modal-day-checkbox').prop('checked', jQuery(this).is(':checked'));
                        });
                        jQuery('.modal-day-checkbox').on('change', function() {
                            if (!jQuery(this).is(':checked')) {
                                jQuery('#modal-select-all').prop('checked', false);
                            } else if (jQuery('.modal-day-checkbox:not(:checked)').length === 0) {
                                jQuery('#modal-select-all').prop('checked', true);
                            }
                        });
                    }
                } else {
                    modalBody.innerHTML = '<p style="color:#dc2626; text-align:center;">Error loading data.</p>';
                }
            });
        });

        jQuery('.close-modal-btn').on('click', function() {
            modal.style.display = 'none';
        });

        jQuery('#foh-confirm-cancel-btn').on('click', function() {
            const subId = jQuery(this).data('sub-id');
            const selectedIds = [];
            jQuery('.modal-day-checkbox:checked').each(function() {
                selectedIds.push(jQuery(this).val());
            });

            if(selectedIds.length === 0) {
                alert('Please select at least one day to hold.');
                return;
            }

            if(!confirm('Are you sure you want to HOLD these ' + selectedIds.length + ' day(s)?\n\nThey will be voided and the quota will be shifted to the end of the customer\'s plan.')) {
                return;
            }

            const origText = jQuery(this).text();
            jQuery(this).text('Processing...').prop('disabled', true);

            var data = {
                action: 'cmp_foh_bulk_cancel_days',
                nonce: fohNonce,
                sub_id: subId,
                log_ids: selectedIds
            };

            jQuery.post(fohAjaxUrl, data, function(res) {
                if(res.success) {
                    location.reload();
                } else {
                    alert('Error: ' + res.data);
                    jQuery('#foh-confirm-cancel-btn').text(origText).prop('disabled', false);
                }
            }).fail(function() {
                alert('Connection error.');
                jQuery('#foh-confirm-cancel-btn').text(origText).prop('disabled', false);
            });
        });

        // SUPER ADMIN PERMANENT SUBSCRIPTION DELETE KILL-SWITCH
        document.querySelectorAll('.delete-sub-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const subId = this.getAttribute('data-sub-id');
                const orderId = this.getAttribute('data-order-id');
                
                const confirmDelete = confirm('⚠️ WARNING: Are you sure you want to permanently delete Plan (' + orderId + ')?\n\nThis will completely delete the subscription and all associated daily meal logs from the database.\n\nThis action CANNOT be undone.');
                if (!confirmDelete) return;

                const originalHTML = btn.innerHTML;
                btn.innerHTML = '...';
                btn.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_delete_subscription');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        alert('Plan ' + orderId + ' has been permanently deleted.');
                        const row = document.getElementById('sub-row-' + subId);
                        if (row) {
                            row.style.transition = 'opacity 0.3s';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 300);
                        } else {
                            location.reload();
                        }
                    } else {
                        alert('Error: ' + (response.data || 'Could not delete subscription.'));
                        btn.innerHTML = originalHTML;
                        btn.disabled = false;
                    }
                }).catch(() => {
                    alert('Network or server error.');
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                });
            });
        });

        // Tracking Metrics JavaScript Sorting Engine & Search Filtering
        document.getElementById('fohSearch').addEventListener('keyup', function() {
            currentPage = 1;
            renderTable();
        });
        
        document.getElementById('fohSort').addEventListener('change', function() {
            currentPage = 1;
            renderTable();
        });
        
        renderTable();
    });

    const rowsPerPage = 10;
    let currentPage = 1;
    let currentTab = 'active-tab';
    let followupFilterOnly = false;

    function switchTab(evt, tabId, filterFollowup) {
        var contents = document.getElementsByClassName("foh-tab-content");
        for (var i = 0; i < contents.length; i++) contents[i].classList.remove("active");
        
        var tiles = document.getElementsByClassName("foh-tile");
        for (var i = 0; i < tiles.length; i++) tiles[i].classList.remove("active");
        
        var targetContent = document.getElementById(tabId);
        if (targetContent) targetContent.classList.add("active");
        
        if (evt && evt.currentTarget) {
            evt.currentTarget.classList.add("active");
        }
        
        currentTab = tabId;
        followupFilterOnly = Boolean(filterFollowup);
        currentPage = 1;

        if (followupFilterOnly) {
            document.getElementById('fohSort').value = 'schedule_ending_asc';
        }

        renderTable();
    }

    function renderTable() {
        const query = document.getElementById('fohSearch').value.toLowerCase();
        const sortVal = document.getElementById('fohSort').value;
        const tbody = document.querySelector('#' + currentTab + ' tbody');
        
        let allRows = Array.from(tbody.querySelectorAll('.foh-row'));
        
        let filtered = allRows.filter(row => {
            const searchData = row.getAttribute('data-search');
            const matchesSearch = query === '' || searchData.includes(query);
            const matchesFollowup = !followupFilterOnly || row.getAttribute('data-followup') === '1';
            return matchesSearch && matchesFollowup;
        });

        // Numeric Sorting Engine
        if (sortVal !== 'default') {
            filtered.sort((a, b) => {
                let valA = 0, valB = 0;
                
                if (sortVal === 'balance_asc' || sortVal === 'balance_desc') {
                    valA = parseInt(a.getAttribute('data-balance')) || 0;
                    valB = parseInt(b.getAttribute('data-balance')) || 0;
                } else if (sortVal === 'filled_desc') {
                    valA = parseInt(a.getAttribute('data-filled')) || 0;
                    valB = parseInt(b.getAttribute('data-filled')) || 0;
                } else if (sortVal === 'used_desc') {
                    valA = parseInt(a.getAttribute('data-used')) || 0;
                    valB = parseInt(b.getAttribute('data-used')) || 0;
                } else if (sortVal === 'pending_fill_desc') {
                    valA = parseInt(a.getAttribute('data-pending-fill')) || 0;
                    valB = parseInt(b.getAttribute('data-pending-fill')) || 0;
                } else if (sortVal === 'schedule_ending_asc') {
                    valA = parseInt(a.getAttribute('data-last-scheduled-ts')) || 0;
                    valB = parseInt(b.getAttribute('data-last-scheduled-ts')) || 0;
                }

                if (sortVal.includes('_asc')) {
                    return valA - valB;
                } else {
                    return valB - valA;
                }
            });
        } else {
            // Restore default sort (Descending ID)
            filtered.sort((a, b) => {
                let idA = parseInt(a.id.replace('sub-row-', '')) || 0;
                let idB = parseInt(b.id.replace('sub-row-', '')) || 0;
                return idB - idA;
            });
        }

        // Hide all rows initially
        allRows.forEach(row => row.style.display = 'none');

        // Re-append sorted rows to physical DOM to preserve ordering in the UI
        filtered.forEach(row => tbody.appendChild(row));

        // Paginate the sorted output
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        filtered.slice(start, end).forEach(row => row.style.display = '');

        // Generate or relocate the empty state notice
        let noResultsRow = tbody.querySelector('.no-search-results');
        if (!noResultsRow) {
            noResultsRow = document.createElement('tr');
            noResultsRow.className = 'no-search-results';
            noResultsRow.innerHTML = '<td colspan="6" style="text-align:center; padding:30px; color:#666;">No customers match your search criteria.</td>';
            tbody.appendChild(noResultsRow);
        } else {
            tbody.appendChild(noResultsRow);
        }
        
        if (filtered.length === 0 && allRows.length > 0) {
            noResultsRow.style.display = '';
        } else {
            noResultsRow.style.display = 'none';
        }

        renderPagination(filtered.length);
    }

    function renderPagination(totalItems) {
        const paginationContainer = document.getElementById('fohPagination');
        paginationContainer.innerHTML = '';
        
        const totalPages = Math.ceil(totalItems / rowsPerPage);
        if(totalPages <= 1) return;

        for(let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('button');
            btn.innerText = i;
            btn.style.padding = "6px 14px";
            btn.style.border = "1px solid #cbd5e1";
            btn.style.background = (i === currentPage) ? "#379237" : "#fff";
            btn.style.color = (i === currentPage) ? "#fff" : "#231F20";
            btn.style.cursor = "pointer";
            btn.style.borderRadius = "6px";
            btn.style.margin = "0 2px";
            btn.style.fontWeight = "bold";
            
            btn.onclick = function(e) {
                e.preventDefault();
                currentPage = i;
                renderTable();
            };
            paginationContainer.appendChild(btn);
        }
    }
    </script>
    <?php
    return ob_get_clean();
}
