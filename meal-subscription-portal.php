<?php
/**
 * Plugin Name: Meal Subscription Portal
 * Description: A custom meal subscription and kitchen reporting engine.
 * Version: 2.1
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

    // Table 1: Foods Database
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

    // Table 2: Subscriptions Database
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

    // Table 3: Daily Meal Logs Database
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

    // Generate Custom Roles upon activation
    cmp_register_custom_roles();
}

// ==========================================
// 2. CREATE CUSTOM ROLES
// ==========================================
add_action('init', 'cmp_register_custom_roles');
function cmp_register_custom_roles() {
    if (!get_role('foh_manager')) {
        add_role('foh_manager', 'FOH Manager', array('read' => true));
    }
    if (!get_role('kitchen_staff')) {
        add_role('kitchen_staff', 'Kitchen Staff', array('read' => true));
    }
    if (!get_role('menu_manager')) {
        add_role('menu_manager', 'Menu Manager', array('read' => true));
    }
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
    'chef-assignment-portal.php' 
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
    // Daily Digest Cron
    if ( ! wp_next_scheduled( 'cmp_daily_digest_cron_hook' ) ) {
        $tz = new DateTimeZone('Asia/Dubai');
        $cutoff_hour = intval(get_option('cmp_cutoff_time', '11'));
        $date = new DateTime("today $cutoff_hour:30", $tz);
        if ($date < new DateTime('now', $tz)) {
            $date->modify('+1 day');
        }
        wp_schedule_event( $date->getTimestamp(), 'daily', 'cmp_daily_digest_cron_hook' );
    }

    // Hourly Watchdog (For FOH POS Alerts)
    if ( ! wp_next_scheduled( 'cmp_hourly_watchdog_cron_hook' ) ) {
        wp_schedule_event( time(), 'hourly', 'cmp_hourly_watchdog_cron_hook' );
    }
}

// Daily Digest Handler
add_action( 'cmp_daily_digest_cron_hook', 'cmp_send_daily_digest_email' );
function cmp_send_daily_digest_email() {
    $updated_subs = get_option('cmp_daily_updated_subs', array());
    if (empty($updated_subs) || !is_array($updated_subs)) {
        return; 
    }

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
        $message .= "\nLog in to the Kitchen Command Center to view their exact meal assignments.\n";
        $message .= site_url('/kitchen-command-center/');
        wp_mail($to, $subject, $message);
    }
    update_option('cmp_daily_updated_subs', array());
}

// NEW: Hourly Watchdog for POS Alerts Handler
add_action( 'cmp_hourly_watchdog_cron_hook', 'cmp_run_hourly_watchdog' );
function cmp_run_hourly_watchdog() {
    $emails_raw = get_option('cmp_pos_alert_emails', '');
    if (empty(trim($emails_raw))) return; 

    $to = array_filter(array_map('trim', explode(',', $emails_raw)));
    if (empty($to)) return;

    $tz = new DateTimeZone('Asia/Dubai');
    $now = new DateTime('now', $tz);
    $current_date = $now->format('Y-m-d');
    $current_hour = (int) $now->format('H');

    $time_1 = intval(get_option('cmp_pos_alert_time_1', '18'));
    $time_2 = intval(get_option('cmp_pos_alert_time_2', '10'));

    $last_alert_1 = get_option('cmp_last_pos_alert_1_date', '');
    $last_alert_2 = get_option('cmp_last_pos_alert_2_date', '');

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    // ALERT 1: Same Day check (Runs at designated hour e.g., 18:00)
    if ($current_hour >= $time_1 && $last_alert_1 !== $current_date) {
        // Find Target Date = Tomorrow (Prepared Today)
        $target_date_obj = clone $now;
        $target_date_obj->modify('+1 day');
        $target_date_str = $target_date_obj->format('Y-m-d');
        
        // FIXED LOGIC: Only count if delivery result is NOT 'Pending'
        $missed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM $table_logs WHERE target_date = %s AND is_locked = 1 AND pos_updated = 0 AND delivery_result != 'Pending'",
            $target_date_str
        ));

        if ($missed_count > 0) {
            $subject = "ACTION REQUIRED: Pending POS Checks (Prepared Today)";
            $message = "Hello FOH Team,\n\nThere are {$missed_count} un-reconciled orders from today's Kitchen Preparation batch.\n\nPlease log in to the Kitchen Portal, verify the delivery statuses, and click the POS checkboxes to finalize the daily reconciliation.\n\n" . site_url('/kitchen-command-center/');
            wp_mail($to, $subject, $message);
        }
        update_option('cmp_last_pos_alert_1_date', $current_date);
    }

    // ALERT 2: Next Day check (Runs at designated hour e.g., 10:00 AM)
    if ($current_hour >= $time_2 && $last_alert_2 !== $current_date) {
        // Find Target Date = Today (Prepared Yesterday)
        $target_date_str = $current_date;
        
        // FIXED LOGIC: Only count if delivery result is NOT 'Pending'
        $missed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM $table_logs WHERE target_date = %s AND is_locked = 1 AND pos_updated = 0 AND delivery_result != 'Pending'",
            $target_date_str
        ));

        if ($missed_count > 0) {
            $subject = "ESCALATION: Missed POS Checks from Yesterday";
            $message = "Hello FOH Team,\n\nThere are STILL {$missed_count} un-reconciled orders from yesterday's food batch.\n\nPlease log in to the Kitchen Portal immediately and finalize the POS checks so customer portals update correctly.\n\n" . site_url('/kitchen-command-center/?prep_date=' . date('Y-m-d', strtotime('-1 day')));
            wp_mail($to, $subject, $message);
        }
        update_option('cmp_last_pos_alert_2_date', $current_date);
    }
}
