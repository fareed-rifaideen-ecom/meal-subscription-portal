<?php
/**
 * Plugin Name: Meal Subscription Portal
 * Description: A custom meal subscription and kitchen reporting engine.
 * Version: 2.4
 * Author: RM Dev Team | Customised by Fareed M Rifaideen
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CMP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// ==========================================
// 1. PLUGIN ACTIVATION (DATABASE CREATION)
// ==========================================
register_activation_hook( __FILE__, 'cmp_activate_plugin' );
function cmp_activate_plugin() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    $charset_collate = $wpdb->get_charset_collate();

    $table_foods = $wpdb->prefix . 'cmp_foods';
    $sql_foods = "CREATE TABLE $table_foods (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        category_name varchar(100) NOT NULL,
        food_name varchar(255) NOT NULL,
        description text,
        calories int(11),
        total_fat decimal(5,1),
        carbohydrates decimal(5,1),
        protein decimal(5,1),
        valid_from DATE NULL,
        valid_until DATE NULL,
        is_active tinyint(1) DEFAULT 1,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_foods );

    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $sql_subs = "CREATE TABLE $table_subs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        wc_order_id bigint(20) NOT NULL,
        plan_name varchar(255) NOT NULL,
        total_days int(11) NOT NULL,
        allowed_categories varchar(255) NOT NULL,
        start_date datetime DEFAULT CURRENT_TIMESTAMP,
        expiry_date datetime NOT NULL,
        status varchar(50) DEFAULT 'active',
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_subs );

    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        subscription_id mediumint(9) NOT NULL,
        target_date date NOT NULL,
        is_chefs_choice tinyint(1) DEFAULT 0,
        is_prepared tinyint(1) DEFAULT 0,
        breakfast_id mediumint(9),
        lunch_id mediumint(9),
        dinner_id mediumint(9),
        snack_1_id mediumint(9),
        snack_2_id mediumint(9),
        juice_1_id mediumint(9),
        juice_2_id mediumint(9),
        juice_3_id mediumint(9),
        dispatch_status tinyint(1) DEFAULT 0,
        delivery_result varchar(50) DEFAULT 'Pending',
        pos_updated tinyint(1) DEFAULT 0,
        is_locked tinyint(1) DEFAULT 0,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_logs );

    cmp_register_custom_roles();
}

// ==========================================
// 2. CREATE CUSTOM ROLES
// ==========================================
add_action('init', 'cmp_register_custom_roles');
function cmp_register_custom_roles() {
    if (!get_role('foh_manager')) { add_role('foh_manager', 'FOH Manager', array('read' => true)); }
    if (!get_role('kitchen_staff')) { add_role('kitchen_staff', 'Kitchen Staff', array('read' => true)); }
    if (!get_role('menu_manager')) { add_role('menu_manager', 'Menu Manager', array('read' => true)); }
    if (!get_role('accounts_team')) { add_role('accounts_team', 'Accounts Team', array('read' => true)); }
}

// ==========================================
// 3. SAFE FILE INCLUSION
// ==========================================
$files_to_include = array(
    'admin-settings.php',
    'admin-menu.php',
    'public-menu.php',
    'woo-bridge.php',
    'customer-portal.php',
    'kitchen-portal.php',
    'foh-portal.php',
    'menu-manager-portal.php',
    'super-admin-portal.php',
    'chef-assignment-portal.php',
    'accounts-portal.php' 
);

foreach ( $files_to_include as $file ) {
    if ( file_exists( CMP_PLUGIN_DIR . $file ) ) {
        require_once CMP_PLUGIN_DIR . $file;
    }
}

// ==========================================
// 4. CRON JOBS: DAILY DIGEST & HOURLY WATCHDOG
// ==========================================
add_action('init', 'cmp_setup_crons');
function cmp_setup_crons() {
    if ( ! wp_next_scheduled( 'cmp_daily_digest_cron_hook' ) ) {
        $tz = new DateTimeZone('Asia/Dubai');
        $cutoff_hour = intval(get_option('cmp_cutoff_time', '11'));
        $date = new DateTime("today $cutoff_hour:30", $tz);
        if ($date < new DateTime('now', $tz)) { $date->modify('+1 day'); }
        wp_schedule_event( $date->getTimestamp(), 'daily', 'cmp_daily_digest_cron_hook' );
    }

    if ( ! wp_next_scheduled( 'cmp_hourly_watchdog_cron_hook' ) ) {
        wp_schedule_event( time(), 'hourly', 'cmp_hourly_watchdog_cron_hook' );
    }
}

// --- DAILY DIGEST HANDLER ---
add_action( 'cmp_daily_digest_cron_hook', 'cmp_send_daily_digest_email' );
function cmp_send_daily_digest_email() {
    $updated_subs = get_option('cmp_daily_updated_subs', array());
    if (empty($updated_subs) || !is_array($updated_subs)) { return; }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $customer_list = "";
    $updated_subs = array_unique($updated_subs);

    foreach ($updated_subs as $sub_id) {
        $sub = $wpdb->get_row($wpdb->prepare("SELECT user_id, wc_order_id, plan_name FROM $table_subs WHERE id = %d", $sub_id));
        if ($sub) {
            $user = get_userdata($sub->user_id);
            $fname = get_user_meta($sub->user_id, 'first_name', true) ?: get_user_meta($sub->user_id, 'billing_first_name', true);
            $lname = get_user_meta($sub->user_id, 'last_name', true) ?: get_user_meta($sub->user_id, 'billing_last_name', true);
            $name = trim($fname . ' ' . $lname);
            if (empty($name)) { $name = $user ? $user->display_name : 'Customer'; }
            $customer_list .= "• {$name} (Order #{$sub->wc_order_id}) - {$sub->plan_name}\n";
        }
    }

    $emails = get_option('cmp_digest_emails', get_option('admin_email'));
    $to = array_filter(array_map('trim', explode(',', $emails)));

    if (!empty($to)) {
        $subject = "Meal Selections Updated - Daily Digest";
        $message = "The following customers have selected or modified their meal calendars in the last 24 hours:\n\n";
        $message .= $customer_list;
        $message .= "\nLog in to the Kitchen Command Center to view their exact meal assignments.\n" . site_url('/kitchen-command-center/');
        wp_mail($to, $subject, $message);
    }
    update_option('cmp_daily_updated_subs', array());
}

// --- HOURLY WATCHDOG (FOH & KITCHEN ALERTS) ---
add_action( 'cmp_hourly_watchdog_cron_hook', 'cmp_run_hourly_watchdog' );
function cmp_run_hourly_watchdog() {
    
    $tz = new DateTimeZone('Asia/Dubai');
    $now = new DateTime('now', $tz);
    $current_date = $now->format('Y-m-d');
    $current_hour = (int) $now->format('H');

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $tomorrow   = date('Y-m-d', strtotime('+1 day', $now->getTimestamp()));

    // ==========================================
    // 1. FOH POS REMINDERS (Dynamic Lookback)
    // ==========================================
    $foh_emails = array_filter(array_map('trim', explode(',', get_option('cmp_pos_alert_emails', ''))));
    if (!empty($foh_emails)) {
        $foh_days = intval(get_option('cmp_pos_alert_days', '7'));
        $foh_start_date = date('Y-m-d', strtotime("-{$foh_days} days", $now->getTimestamp()));

        $foh_t1 = intval(get_option('cmp_pos_alert_time_1', '18'));
        $foh_t2 = intval(get_option('cmp_pos_alert_time_2', '10'));
        $last_foh_1 = get_option('cmp_last_pos_alert_1_date', '');
        $last_foh_2 = get_option('cmp_last_pos_alert_2_date', '');

        // Check Logic: Unchecked POS from dynamic past X days up until tomorrow
        $foh_missed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM $table_logs WHERE target_date >= %s AND target_date <= %s AND is_locked = 1 AND pos_updated = 0 AND delivery_result != 'Pending'",
            $foh_start_date, $tomorrow
        ));

        // Alert 1 (Same Day)
        if ($current_hour >= $foh_t1 && $last_foh_1 !== $current_date && $foh_missed > 0) {
            $subject = "ACTION REQUIRED: Pending POS Checks (Past {$foh_days} Days)";
            $message = "Hello FOH Team,\n\nYou have {$foh_missed} un-reconciled orders pending in the system from the past {$foh_days} days.\n\nPlease log in to the Kitchen Portal, verify the delivery statuses, and click the POS checkboxes to finalize reconciliation.\n\n" . site_url('/kitchen-command-center/');
            wp_mail($foh_emails, $subject, $message);
            update_option('cmp_last_pos_alert_1_date', $current_date);
        }
        // Alert 2 (Next Day / Escalation)
        elseif ($current_hour >= $foh_t2 && $current_hour < $foh_t1 && $last_foh_2 !== $current_date && $foh_missed > 0) {
            $subject = "ESCALATION: Missed POS Checks Detected";
            $message = "Hello FOH Team,\n\nYou still have {$foh_missed} un-reconciled orders from the past {$foh_days} days.\n\nPlease log in to the Kitchen Portal immediately and finalize the POS checks so customer portals and accounting records update correctly.\n\n" . site_url('/kitchen-command-center/');
            wp_mail($foh_emails, $subject, $message);
            update_option('cmp_last_pos_alert_2_date', $current_date);
        }
    }

    // ==========================================
    // 2. KITCHEN OPERATIONAL ALERTS (Dynamic Lookback)
    // ==========================================
    $kitchen_emails = array_filter(array_map('trim', explode(',', get_option('cmp_kitchen_alert_emails', ''))));
    if (!empty($kitchen_emails)) {
        $kit_days = intval(get_option('cmp_kitchen_alert_days', '7'));
        $kit_start_date = date('Y-m-d', strtotime("-{$kit_days} days", $now->getTimestamp()));

        $kit_t1 = intval(get_option('cmp_kitchen_alert_time_1', '18'));
        $kit_t2 = intval(get_option('cmp_kitchen_alert_time_2', '10'));
        $last_kit_1 = get_option('cmp_last_kitchen_alert_1_date', '');
        $last_kit_2 = get_option('cmp_last_kitchen_alert_2_date', '');

        // Check Logic: 
        // A) Any day from dynamic past X days to Today where Prep, Dispatch, OR Delivery is missing.
        // B) Tomorrow where Prep is missing (Tomorrow's food is prepped today).
        $kitchen_missed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM $table_logs 
             WHERE is_locked = 1 
             AND (
                (target_date >= %s AND target_date <= %s AND (is_prepared = 0 OR dispatch_status = 0 OR delivery_result = 'Pending'))
                OR 
                (target_date = %s AND is_prepared = 0)
             )",
            $kit_start_date, $current_date, $tomorrow
        ));

        // Alert 1 (Same Day)
        if ($current_hour >= $kit_t1 && $last_kit_1 !== $current_date && $kitchen_missed > 0) {
            $subject = "ACTION REQUIRED: Pending Kitchen Logs (Past {$kit_days} Days)";
            $message = "Hello Kitchen Team,\n\nYou have {$kitchen_missed} incomplete logs in the system from the past {$kit_days} days.\n\nThis means meals have not been checked as 'Prepared', 'Dispatched', or given a final 'Delivery Status'. Please log in and finalize these records for today and the past {$kit_days} days.\n\n" . site_url('/kitchen-command-center/');
            wp_mail($kitchen_emails, $subject, $message);
            update_option('cmp_last_kitchen_alert_1_date', $current_date);
        }
        // Alert 2 (Next Day / Escalation)
        elseif ($current_hour >= $kit_t2 && $current_hour < $kit_t1 && $last_kit_2 !== $current_date && $kitchen_missed > 0) {
            $subject = "ESCALATION: Missed Kitchen Operational Logs";
            $message = "Hello Kitchen Team,\n\nYou still have {$kitchen_missed} incomplete logs from the past {$kit_days} days.\n\nPlease log in immediately to ensure all meals from yesterday and the past {$kit_days} days are properly marked as Prepared, Dispatched, and Delivered.\n\n" . site_url('/kitchen-command-center/');
            wp_mail($kitchen_emails, $subject, $message);
            update_option('cmp_last_kitchen_alert_2_date', $current_date);
        }
    }
}
