<?php
/**
 * Plugin Name: Meal Subscription Portal
 * Description: A custom meal subscription and kitchen reporting engine.
 * Version: 2.0
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
    // NEW ROLE ADDED HERE
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
    'chef-assignment-portal.php' // <-- THIS LINE ACTIVATES THE DASHBOARD
);

foreach ( $files_to_include as $file ) {
    if ( file_exists( CMP_PLUGIN_DIR . $file ) ) {
        require_once CMP_PLUGIN_DIR . $file;
    }
}

// ==========================================
// 4. DAILY DIGEST CRON JOB (SENT AFTER CUTOFF)
// ==========================================

// Schedule the Cron Event dynamically based on your Cutoff Time
add_action('init', 'cmp_setup_daily_digest_cron');
function cmp_setup_daily_digest_cron() {
    if ( ! wp_next_scheduled( 'cmp_daily_digest_cron_hook' ) ) {
        $tz = new DateTimeZone('Asia/Dubai');
        // Fetch the active cutoff hour, default to 11
        $cutoff_hour = intval(get_option('cmp_cutoff_time', '11'));
        
        // Schedule it for exactly 30 minutes past the cutoff time (e.g., 11:30 AM)
        $date = new DateTime("today $cutoff_hour:30", $tz);
        
        // If that time has already passed today, schedule for tomorrow
        if ($date < new DateTime('now', $tz)) {
            $date->modify('+1 day');
        }
        
        wp_schedule_event( $date->getTimestamp(), 'daily', 'cmp_daily_digest_cron_hook' );
    }
}

// The function that actually builds and sends the email
add_action( 'cmp_daily_digest_cron_hook', 'cmp_send_daily_digest_email' );
function cmp_send_daily_digest_email() {
    // Check if anyone updated their meals today
    $updated_subs = get_option('cmp_daily_updated_subs', array());
    if (empty($updated_subs) || !is_array($updated_subs)) {
        return; // Nobody updated, send no email
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    
    $customer_list = "";
    
    // De-duplicate in case a customer saved multiple times
    $updated_subs = array_unique($updated_subs);

    // Build the list of names
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

    // Get recipients from the Admin Settings
    $emails = get_option('cmp_digest_emails', get_option('admin_email'));
    $to = array_filter(array_map('trim', explode(',', $emails)));

    if (!empty($to)) {
        // Construct the Email
        $subject = "Meal Selections Updated - Daily Digest";
        $message = "The following customers have selected or modified their meal calendars in the last 24 hours:\n\n";
        $message .= $customer_list;
        $message .= "\nLog in to the Kitchen Command Center to view their exact meal assignments.\n";
        $message .= site_url('/kitchen-command-center/');

        // Send it
        wp_mail($to, $subject, $message);
    }

    // Empty the queue so it starts fresh for tomorrow
    update_option('cmp_daily_updated_subs', array());
}
