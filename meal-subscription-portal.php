<?php
/**
 * Plugin Name: Meal Subscription Portal
 * Description: A custom meal subscription and kitchen reporting engine.
 * Version: 2.9
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
        plan_price decimal(10,2) DEFAULT '0.00',
        discount_amount decimal(10,2) DEFAULT '0.00',
        start_date datetime DEFAULT CURRENT_TIMESTAMP,
        expiry_date datetime NOT NULL,
        status varchar(50) DEFAULT 'active',
        foh_remarks text NULL,
        is_flexible tinyint(1) DEFAULT 0,
        allow_custom tinyint(1) DEFAULT 0,
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
        custom_breakfast varchar(255) NULL,
        lunch_id mediumint(9),
        custom_lunch varchar(255) NULL,
        dinner_id mediumint(9),
        custom_dinner varchar(255) NULL,
        snack_1_id mediumint(9),
        custom_snack_1 varchar(255) NULL,
        snack_2_id mediumint(9),
        custom_snack_2 varchar(255) NULL,
        juice_1_id mediumint(9),
        custom_juice_1 varchar(255) NULL,
        juice_2_id mediumint(9),
        custom_juice_2 varchar(255) NULL,
        juice_3_id mediumint(9),
        custom_juice_3 varchar(255) NULL,
        dispatch_status tinyint(1) DEFAULT 0,
        delivery_result varchar(50) DEFAULT 'Pending',
        pos_updated tinyint(1) DEFAULT 0,
        is_locked tinyint(1) DEFAULT 0,
        plan_mode varchar(20) DEFAULT 'standard',
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_logs );

    cmp_register_custom_roles();
}

// ==========================================
// 1.5 AUTO-PATCHER (UPGRADES LIVE DATABASE SAFELY)
// ==========================================
add_action('admin_init', 'cmp_auto_patch_database');
function cmp_auto_patch_database() {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    
    // Patch 1: Pricing Columns
    $col_check_price = $wpdb->get_results("SHOW COLUMNS FROM {$table_subs} LIKE 'plan_price'");
    if (empty($col_check_price)) {
        $wpdb->query("ALTER TABLE {$table_subs} ADD plan_price DECIMAL(10,2) DEFAULT '0.00' AFTER allowed_categories");
        $wpdb->query("ALTER TABLE {$table_subs} ADD discount_amount DECIMAL(10,2) DEFAULT '0.00' AFTER plan_price");
    }

    // Patch 2: Phase 5 Advanced Personalization Flags (Subscriptions)
    $col_check_flex = $wpdb->get_results("SHOW COLUMNS FROM {$table_subs} LIKE 'is_flexible'");
    if (empty($col_check_flex)) {
        $wpdb->query("ALTER TABLE {$table_subs} ADD is_flexible TINYINT(1) DEFAULT 0 AFTER status");
        $wpdb->query("ALTER TABLE {$table_subs} ADD allow_custom TINYINT(1) DEFAULT 0 AFTER is_flexible");
    }

    // Patch 3: Phase 5 Custom Text Input Fields (Daily Logs)
    $col_check_custom = $wpdb->get_results("SHOW COLUMNS FROM {$table_logs} LIKE 'custom_breakfast'");
    if (empty($col_check_custom)) {
        $wpdb->query("ALTER TABLE {$table_logs} ADD custom_breakfast VARCHAR(255) NULL AFTER breakfast_id");
        $wpdb->query("ALTER TABLE {$table_logs} ADD custom_lunch VARCHAR(255) NULL AFTER lunch_id");
        $wpdb->query("ALTER TABLE {$table_logs} ADD custom_dinner VARCHAR(255) NULL AFTER dinner_id");
        $wpdb->query("ALTER TABLE {$table_logs} ADD custom_snack_1 VARCHAR(255) NULL AFTER snack_1_id");
        $wpdb->query("ALTER TABLE {$table_logs} ADD custom_snack_2 VARCHAR(255) NULL AFTER snack_2_id");
        $wpdb->query("ALTER TABLE {$table_logs} ADD custom_juice_1 VARCHAR(255) NULL AFTER juice_1_id");
        $wpdb->query("ALTER TABLE {$table_logs} ADD custom_juice_2 VARCHAR(255) NULL AFTER juice_2_id");
        $wpdb->query("ALTER TABLE {$table_logs} ADD custom_juice_3 VARCHAR(255) NULL AFTER juice_3_id");
    }

    // Patch 4: Phase 7 Log-Level Plan Mode Snapshot (Daily Logs)
    $col_check_mode = $wpdb->get_results("SHOW COLUMNS FROM {$table_logs} LIKE 'plan_mode'");
    if (empty($col_check_mode)) {
        $wpdb->query("ALTER TABLE {$table_logs} ADD plan_mode VARCHAR(20) DEFAULT 'standard' AFTER is_locked");
    }
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
// 4. CRON JOBS & SCHEDULING LOGIC
// ==========================================

// Helper Function: Dynamically schedule a daily event based on EXACT time setting
function cmp_schedule_exact_daily_cron($hook_name, $setting_key, $default_time) {
    $tz = new DateTimeZone('Asia/Dubai');
    $raw_val = get_option($setting_key, $default_time);
    
    // Auto-correct legacy integer settings (e.g. '19') to HTML5 time format ('19:00')
    $target_time = (strpos($raw_val, ':') !== false) ? $raw_val : str_pad($raw_val, 2, '0', STR_PAD_LEFT) . ':00';
    
    $next_scheduled = wp_next_scheduled($hook_name);
    $reschedule = false;

    // If already scheduled, check if the exact minute matches the current WP setting
    if ($next_scheduled) {
        $scheduled_date = new DateTime('@' . $next_scheduled);
        $scheduled_date->setTimezone($tz);
        if ($scheduled_date->format('H:i') !== $target_time) {
            $reschedule = true;
            wp_clear_scheduled_hook($hook_name); // Destroy the old schedule
        }
    }

    // Schedule the new exact time
    if (!$next_scheduled || $reschedule) {
        $date = new DateTime("today $target_time", $tz);
        if ($date < new DateTime('now', $tz)) {
            $date->modify('+1 day'); // Push to tomorrow if the time has passed today
        }
        wp_schedule_event( $date->getTimestamp(), 'daily', $hook_name );
    }
}

add_action('init', 'cmp_setup_crons');
function cmp_setup_crons() {
    
    // CLEANUP: Destroy old flawed Watchdogs & Legacy Hooks
    wp_clear_scheduled_hook('cmp_hourly_watchdog_cron_hook');
    wp_clear_scheduled_hook('cmp_foh_sameday_alert_hook');
    wp_clear_scheduled_hook('cmp_foh_nextday_alert_hook');
    wp_clear_scheduled_hook('cmp_kit_sameday_alert_hook');
    wp_clear_scheduled_hook('cmp_kit_nextday_alert_hook');

    // 1. Daily Digest Cron (UNTOUCHED)
    if ( ! wp_next_scheduled( 'cmp_daily_digest_cron_hook' ) ) {
        $tz = new DateTimeZone('Asia/Dubai');
        $cutoff_hour = intval(get_option('cmp_cutoff_time', '11'));
        $date = new DateTime("today $cutoff_hour:30", $tz);
        if ($date < new DateTime('now', $tz)) { $date->modify('+1 day'); }
        wp_schedule_event( $date->getTimestamp(), 'daily', 'cmp_daily_digest_cron_hook' );
    }

    // 2. Exact-Time Daily Crons for FOH & Kitchen (Down to the minute)
    cmp_schedule_exact_daily_cron('cmp_foh_alert_1_hook', 'cmp_pos_alert_time_1', '19:00');
    cmp_schedule_exact_daily_cron('cmp_foh_alert_2_hook', 'cmp_pos_alert_time_2', '10:00');
    cmp_schedule_exact_daily_cron('cmp_kit_alert_1_hook', 'cmp_kitchen_alert_time_1', '19:00');
    cmp_schedule_exact_daily_cron('cmp_kit_alert_2_hook', 'cmp_kitchen_alert_time_2', '10:00');
}


// --- DAILY DIGEST HANDLER (UNTOUCHED) ---
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


// ==========================================
// 5. ISOLATED COMPLIANCE ALERT HANDLERS
// ==========================================

// FOH: Alert 1 (Standard)
add_action( 'cmp_foh_alert_1_hook', 'cmp_run_foh_alert_1' );
function cmp_run_foh_alert_1() {
    $foh_emails = array_filter(array_map('trim', explode(',', get_option('cmp_pos_alert_emails', ''))));
    if (empty($foh_emails)) return;

    $tz = new DateTimeZone('Asia/Dubai');
    $now = new DateTime('now', $tz);
    $tomorrow = date('Y-m-d', strtotime('+1 day', $now->getTimestamp()));
    
    $foh_days = intval(get_option('cmp_pos_alert_days', '7'));
    $foh_start_date = date('Y-m-d', strtotime("-{$foh_days} days", $now->getTimestamp()));

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    $foh_missed = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM $table_logs WHERE target_date >= %s AND target_date <= %s AND is_locked = 1 AND pos_updated = 0 AND delivery_result != 'Pending'",
        $foh_start_date, $tomorrow
    ));

    if ($foh_missed > 0) {
        $subject = "ACTION REQUIRED: Pending POS Checks (Past {$foh_days} Days)";
        $message = "Hello FOH Team,\n\nYou have {$foh_missed} un-reconciled orders pending in the system from the past {$foh_days} days.\n\nPlease log in to the Kitchen Portal, verify the delivery statuses, and click the POS checkboxes to finalize reconciliation.\n\n" . site_url('/kitchen-command-center/');
        foreach ( $foh_emails as $recipient ) { wp_mail( $recipient, $subject, $message ); }
    }
}

// FOH: Alert 2 (Escalation)
add_action( 'cmp_foh_alert_2_hook', 'cmp_run_foh_alert_2' );
function cmp_run_foh_alert_2() {
    $foh_emails = array_filter(array_map('trim', explode(',', get_option('cmp_pos_alert_emails', ''))));
    if (empty($foh_emails)) return;

    $tz = new DateTimeZone('Asia/Dubai');
    $now = new DateTime('now', $tz);
    $tomorrow = date('Y-m-d', strtotime('+1 day', $now->getTimestamp()));
    
    $foh_days = intval(get_option('cmp_pos_alert_days', '7'));
    $foh_start_date = date('Y-m-d', strtotime("-{$foh_days} days", $now->getTimestamp()));

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    $foh_missed = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM $table_logs WHERE target_date >= %s AND target_date <= %s AND is_locked = 1 AND pos_updated = 0 AND delivery_result != 'Pending'",
        $foh_start_date, $tomorrow
    ));

    if ($foh_missed > 0) {
        $subject = "ESCALATION: Missed POS Checks Detected";
        $message = "Hello FOH Team,\n\nYou still have {$foh_missed} un-reconciled orders from the past {$foh_days} days.\n\nPlease log in to the Kitchen Portal immediately and finalize the POS checks so customer portals and accounting records update correctly.\n\n" . site_url('/kitchen-command-center/');
        foreach ( $foh_emails as $recipient ) { wp_mail( $recipient, $subject, $message ); }
    }
}


// KITCHEN: Alert 1 (Standard)
add_action( 'cmp_kit_alert_1_hook', 'cmp_run_kit_alert_1' );
function cmp_run_kit_alert_1() {
    $kitchen_emails = array_filter(array_map('trim', explode(',', get_option('cmp_kitchen_alert_emails', ''))));
    if (empty($kitchen_emails)) return;

    $tz = new DateTimeZone('Asia/Dubai');
    $now = new DateTime('now', $tz);
    $current_date = $now->format('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day', $now->getTimestamp()));
    
    $kit_days = intval(get_option('cmp_kitchen_alert_days', '7'));
    $kit_start_date = date('Y-m-d', strtotime("-{$kit_days} days", $now->getTimestamp()));

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

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

    if ($kitchen_missed > 0) {
        $subject = "ACTION REQUIRED: Pending Kitchen Logs (Past {$kit_days} Days)";
        $message = "Hello Kitchen Team,\n\nYou have {$kitchen_missed} incomplete logs in the system from the past {$kit_days} days.\n\nThis means meals have not been checked as 'Prepared', 'Dispatched', or given a final 'Delivery Status'. Please log in and finalize these records.\n\n" . site_url('/kitchen-command-center/');
        foreach ( $kitchen_emails as $recipient ) { wp_mail( $recipient, $subject, $message ); }
    }
}

// KITCHEN: Alert 2 (Escalation)
add_action( 'cmp_kit_alert_2_hook', 'cmp_run_kit_alert_2' );
function cmp_run_kit_alert_2() {
    $kitchen_emails = array_filter(array_map('trim', explode(',', get_option('cmp_kitchen_alert_emails', ''))));
    if (empty($kitchen_emails)) return;

    $tz = new DateTimeZone('Asia/Dubai');
    $now = new DateTime('now', $tz);
    $current_date = $now->format('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day', $now->getTimestamp()));
    
    $kit_days = intval(get_option('cmp_kitchen_alert_days', '7'));
    $kit_start_date = date('Y-m-d', strtotime("-{$kit_days} days", $now->getTimestamp()));

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

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

    if ($kitchen_missed > 0) {
        $subject = "ESCALATION: Missed Kitchen Operational Logs";
        $message = "Hello Kitchen Team,\n\nYou still have {$kitchen_missed} incomplete logs from the past {$kit_days} days.\n\nPlease log in immediately to ensure all meals from yesterday and the past {$kit_days} days are properly marked as Prepared, Dispatched, and Delivered.\n\n" . site_url('/kitchen-command-center/');
        foreach ( $kitchen_emails as $recipient ) { wp_mail( $recipient, $subject, $message ); }
    }
}


// ==========================================
// 6. UNIFIED PORTAL AUTHENTICATION & UI/UX ENGINE
// ==========================================

// 6.1 AJAX Login Handler (Supports Non-Logged-In & Role-Switch Attempts)
add_action( 'wp_ajax_nopriv_cmp_portal_login', 'cmp_handle_portal_ajax_login' );
add_action( 'wp_ajax_cmp_portal_login', 'cmp_handle_portal_ajax_login' );
function cmp_handle_portal_ajax_login() {
    if ( ! check_ajax_referer( 'cmp_portal_auth_nonce', 'nonce', false ) ) {
        wp_send_json_error( array(
            'message' => 'Session expired. Please refresh the page and try again.'
        ) );
    }

    // Ensure fallback redirect hook never fires during AJAX authentication
    remove_action( 'wp_login_failed', 'cmp_intercept_failed_portal_login' );

    $user_login    = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
    $user_password = isset( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '';
    $remember      = ! empty( $_POST['rememberme'] );
    $portal_title  = isset( $_POST['portal_title'] ) ? sanitize_text_field( wp_unslash( $_POST['portal_title'] ) ) : 'Portal';
    $required_caps = isset( $_POST['required_caps'] ) ? sanitize_text_field( wp_unslash( $_POST['required_caps'] ) ) : '';

    if ( empty( $user_login ) || empty( $user_password ) ) {
        wp_send_json_error( array(
            'message' => 'Please enter both your email/username and password.'
        ) );
    }

    // Authenticate credentials without setting cookies yet
    $user = wp_authenticate( $user_login, $user_password );

    if ( is_wp_error( $user ) ) {
        $error_code = $user->get_error_code();
        if ( in_array( $error_code, array( 'invalid_username', 'invalid_email' ), true ) ) {
            $friendly_msg = 'Invalid username or email address. No matching account was found.';
        } elseif ( $error_code === 'incorrect_password' ) {
            $friendly_msg = 'Incorrect password entered. Please try again or click "Forgot Password?" below.';
        } else {
            $friendly_msg = wp_strip_all_tags( $user->get_error_message() );
        }

        wp_send_json_error( array( 'message' => $friendly_msg ) );
    }

    // Verify Portal Role / Capability Clearance if specified
    if ( ! empty( $required_caps ) && $required_caps !== 'any' ) {
        $caps_array = array_filter( array_map( 'trim', explode( ',', $required_caps ) ) );
        $has_access = false;
        foreach ( $caps_array as $cap ) {
            if ( user_can( $user, $cap ) ) {
                $has_access = true;
                break;
            }
        }

        if ( ! $has_access ) {
            wp_send_json_error( array(
                'message' => "Access Denied: Your account ({$user->user_email}) does not have clearance to access the {$portal_title}."
            ) );
        }
    }

    // Credentials & Clearance verified -> Sign the user in cleanly
    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
    do_action( 'wp_login', $user->user_login, $user );

    wp_send_json_success( array(
        'message' => 'Signed in successfully! Loading dashboard...'
    ) );
}

// 6.2 AJAX Forgot Password Handler
add_action( 'wp_ajax_nopriv_cmp_portal_forgot_password', 'cmp_handle_portal_ajax_forgot_password' );
add_action( 'wp_ajax_cmp_portal_forgot_password', 'cmp_handle_portal_ajax_forgot_password' );
function cmp_handle_portal_ajax_forgot_password() {
    if ( ! check_ajax_referer( 'cmp_portal_auth_nonce', 'nonce', false ) ) {
        wp_send_json_error( array(
            'message' => 'Session expired. Please refresh the page and try again.'
        ) );
    }

    $user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

    if ( empty( $user_login ) ) {
        wp_send_json_error( array(
            'message' => 'Please enter your email address or username.'
        ) );
    }

    // Check if account exists by email or username
    $user_data = is_email( $user_login ) ? get_user_by( 'email', $user_login ) : get_user_by( 'login', $user_login );
    if ( ! $user_data ) {
        wp_send_json_error( array(
            'message' => 'No account was found matching that email address or username.'
        ) );
    }

    $errors = retrieve_password( $user_data->user_login );

    if ( true === $errors ) {
        wp_send_json_success( array(
            'message' => 'A password reset link has been sent to your email address. Please check your inbox (and spam folder).'
        ) );
    } else {
        $err_msg = is_wp_error( $errors ) ? wp_strip_all_tags( $errors->get_error_message() ) : 'Unable to send password reset email. Please contact an administrator.';
        wp_send_json_error( array( 'message' => $err_msg ) );
    }
}

// 6.3 Fallback Safety Net: Prevent wp-login.php screen ONLY on non-AJAX form submissions
add_action( 'wp_login_failed', 'cmp_intercept_failed_portal_login' );
function cmp_intercept_failed_portal_login( $username ) {
    if ( wp_doing_ajax() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
        return;
    }
    $referrer = wp_get_referer();
    if ( ! empty( $referrer ) && ! strstr( $referrer, 'wp-login' ) && ! strstr( $referrer, 'wp-admin' ) ) {
        wp_safe_redirect( add_query_arg( 'cmp_login', 'failed', $referrer ) );
        exit;
    }
}

// 6.4 Reusable Portal Login & Access-Denied UI/UX Renderer
function cmp_render_portal_login_ui( $args = array() ) {
    $defaults = array(
        'portal_id'      => 'portal',
        'title'          => 'Staff Portal',
        'subtitle'       => 'Sign in to access your dashboard',
        'required_caps'  => array( 'manage_options' ),
        'denied_message' => 'Your account does not have clearance to view this portal.',
        'accent_color'   => '#379237',
        'header_bg'      => '#379237',
    );
    $cfg = wp_parse_args( $args, $defaults );

    $uid           = esc_attr( $cfg['portal_id'] );
    $caps_csv      = is_array( $cfg['required_caps'] ) ? implode( ',', $cfg['required_caps'] ) : $cfg['required_caps'];
    $ajax_url      = esc_url( admin_url( 'admin-ajax.php' ) );
    $nonce         = wp_create_nonce( 'cmp_portal_auth_nonce' );
    $current_url   = esc_url( remove_query_arg( array( 'cmp_login' ) ) );
    $url_failed    = isset( $_GET['cmp_login'] ) && $_GET['cmp_login'] === 'failed';

    ob_start();
    ?>
    <style>
        .cmp-auth-outer { max-width: 440px; margin: 55px auto; padding: 0 15px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif; }
        .cmp-auth-card { background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 12px 30px rgba(55, 146, 55, 0.10); overflow: hidden; }
        .cmp-auth-header { background: <?php echo esc_attr( $cfg['header_bg'] ); ?>; color: #ffffff; padding: 28px 30px 24px; text-align: center; border-bottom: 4px solid rgba(0, 0, 0, 0.12); }
        .cmp-auth-badge { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 12px; background: rgba(255,255,255,0.18); margin-bottom: 12px; }
        .cmp-auth-header h2 { margin: 0; font-size: 1.55em; font-weight: 800; color: #ffffff; letter-spacing: -0.02em; }
        .cmp-auth-header p { margin: 6px 0 0 0; font-size: 0.9em; color: rgba(255, 255, 255, 0.88); }
        .cmp-auth-body { padding: 28px 30px 30px; }
        
        .cmp-auth-alert { display: none; padding: 12px 14px; border-radius: 8px; font-size: 0.88em; line-height: 1.45; margin-bottom: 18px; font-weight: 600; }
        .cmp-auth-alert.error { display: block !important; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-left: 4px solid #ef4444; }
        .cmp-auth-alert.success { display: block !important; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; border-left: 4px solid #379237; }
        
        @keyframes cmpShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }
        .cmp-shake { animation: cmpShake 0.35s ease-in-out; }

        .cmp-form-group { margin-bottom: 18px; }
        .cmp-form-group label { display: block; font-size: 0.85em; font-weight: 700; color: #334155; margin-bottom: 6px; }
        .cmp-input-wrap { position: relative; display: flex; align-items: center; }
        .cmp-input-wrap input { width: 100%; padding: 11px 14px; font-size: 0.95em; color: #1e293b; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; transition: all 0.2s ease; box-sizing: border-box; outline: none; }
        .cmp-input-wrap input:focus { background: #ffffff; border-color: <?php echo esc_attr( $cfg['accent_color'] ); ?>; box-shadow: 0 0 0 3px rgba(55, 146, 55, 0.18); }
        .cmp-pwd-toggle { position: absolute; right: 10px; background: none; border: none; color: #64748b; cursor: pointer; padding: 6px; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
        .cmp-pwd-toggle:hover { color: <?php echo esc_attr( $cfg['accent_color'] ); ?>; background: #f1f5f9; }

        .cmp-auth-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; font-size: 0.86em; }
        .cmp-remember-label { display: inline-flex; align-items: center; gap: 7px; color: #475569; cursor: pointer; font-weight: 500; margin: 0; }
        .cmp-remember-label input { width: 16px; height: 16px; accent-color: <?php echo esc_attr( $cfg['accent_color'] ); ?>; cursor: pointer; margin: 0; }
        .cmp-link-btn { background: none; border: none; color: <?php echo esc_attr( $cfg['accent_color'] ); ?>; font-weight: 700; cursor: pointer; padding: 0; font-size: 1em; text-decoration: none; }
        .cmp-link-btn:hover { text-decoration: underline; }

        .cmp-submit-btn { width: 100%; background: <?php echo esc_attr( $cfg['header_bg'] ); ?>; color: #ffffff; border: none; padding: 12px 18px; font-size: 0.96em; font-weight: 700; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; box-sizing: border-box; }
        .cmp-submit-btn:hover { filter: brightness(0.93); transform: translateY(-1px); color: #ffffff; }
        .cmp-submit-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }

        .cmp-spinner { width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #ffffff; border-radius: 50%; animation: cmpSpin 0.7s linear infinite; display: none; }
        @keyframes cmpSpin { to { transform: rotate(360deg); } }

        .cmp-user-pill { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; font-size: 0.88em; color: #334155; }
    </style>

    <div class="cmp-auth-outer">
        <div class="cmp-auth-card" id="cmp-auth-card-<?php echo $uid; ?>">
            <div class="cmp-auth-header">
                <div class="cmp-auth-badge">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <h2><?php echo esc_html( $cfg['title'] ); ?></h2>
                <p><?php echo esc_html( $cfg['subtitle'] ); ?></p>
            </div>

            <div class="cmp-auth-body">
                <?php if ( is_user_logged_in() ) : 
                    $curr_user = wp_get_current_user();
                ?>
                    <!-- LOGGED IN BUT LACKS CLEARANCE FOR THIS PORTAL -->
                    <div class="cmp-auth-alert error" style="display:block;">
                        <strong>Access Restricted:</strong> <?php echo esc_html( $cfg['denied_message'] ); ?>
                    </div>
                    <div class="cmp-user-pill">
                        Currently signed in as:<br>
                        <strong style="color:#1e293b;"><?php echo esc_html( $curr_user->display_name ); ?></strong>
                        <span style="color:#64748b;">(<?php echo esc_html( $curr_user->user_email ); ?>)</span>
                    </div>
                    <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="cmp-submit-btn">
                        Log Out &amp; Switch Account
                    </a>

                <?php else : ?>
                    <!-- VIEW 1: SIGN IN FORM -->
                    <div id="cmp-login-view-<?php echo $uid; ?>">
                        <div id="cmp-login-alert-<?php echo $uid; ?>" class="cmp-auth-alert <?php echo $url_failed ? 'error' : ''; ?>">
                            <?php if ( $url_failed ) echo 'Incorrect username/email or password. Please try again.'; ?>
                        </div>

                        <form id="cmp-login-form-<?php echo $uid; ?>" novalidate>
                            <div class="cmp-form-group">
                                <label for="cmp-user-<?php echo $uid; ?>">Email Address or Username</label>
                                <div class="cmp-input-wrap">
                                    <input type="text" id="cmp-user-<?php echo $uid; ?>" name="log" placeholder="name@company.com" autocomplete="username" required>
                                </div>
                            </div>

                            <div class="cmp-form-group">
                                <label for="cmp-pass-<?php echo $uid; ?>">Password</label>
                                <div class="cmp-input-wrap">
                                    <input type="password" id="cmp-pass-<?php echo $uid; ?>" name="pwd" placeholder="••••••••" autocomplete="current-password" style="padding-right: 42px;" required>
                                    <button type="button" class="cmp-pwd-toggle" id="cmp-pwd-toggle-<?php echo $uid; ?>" aria-label="Show password" title="Show / Hide Password">
                                        <svg id="cmp-eye-open-<?php echo $uid; ?>" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg id="cmp-eye-closed-<?php echo $uid; ?>" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="cmp-auth-meta">
                                <label class="cmp-remember-label">
                                    <input type="checkbox" id="cmp-remember-<?php echo $uid; ?>" name="rememberme" value="forever">
                                    <span>Remember me</span>
                                </label>
                                <button type="button" class="cmp-link-btn" id="cmp-show-forgot-<?php echo $uid; ?>">Forgot Password?</button>
                            </div>

                            <button type="submit" class="cmp-submit-btn" id="cmp-login-btn-<?php echo $uid; ?>">
                                <span class="cmp-spinner" id="cmp-login-spin-<?php echo $uid; ?>"></span>
                                <span id="cmp-login-btn-text-<?php echo $uid; ?>">Sign In to <?php echo esc_html( $cfg['title'] ); ?></span>
                            </button>
                        </form>
                    </div>

                    <!-- VIEW 2: FORGOT PASSWORD FORM -->
                    <div id="cmp-forgot-view-<?php echo $uid; ?>" style="display:none;">
                        <div id="cmp-forgot-alert-<?php echo $uid; ?>" class="cmp-auth-alert"></div>

                        <p style="margin: 0 0 16px 0; font-size: 0.9em; color: #475569; line-height: 1.5;">
                            Enter the email address or username associated with your account and we'll email you a password reset link.
                        </p>

                        <form id="cmp-forgot-form-<?php echo $uid; ?>" novalidate>
                            <div class="cmp-form-group">
                                <label for="cmp-reset-user-<?php echo $uid; ?>">Email Address or Username</label>
                                <div class="cmp-input-wrap">
                                    <input type="text" id="cmp-reset-user-<?php echo $uid; ?>" name="user_login" placeholder="name@company.com" autocomplete="username" required>
                                </div>
                            </div>

                            <button type="submit" class="cmp-submit-btn" id="cmp-forgot-btn-<?php echo $uid; ?>">
                                <span class="cmp-spinner" id="cmp-forgot-spin-<?php echo $uid; ?>"></span>
                                <span id="cmp-forgot-btn-text-<?php echo $uid; ?>">Send Password Reset Link</span>
                            </button>
                        </form>

                        <div style="text-align: center; margin-top: 18px;">
                            <button type="button" class="cmp-link-btn" id="cmp-back-login-<?php echo $uid; ?>">&larr; Back to Sign In</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ( ! is_user_logged_in() ) : ?>
    <script>
    (function() {
        const uid        = '<?php echo esc_js( $uid ); ?>';
        const ajaxUrl    = '<?php echo $ajax_url; ?>';
        const nonce      = '<?php echo esc_js( $nonce ); ?>';
        const reqCaps    = '<?php echo esc_js( $caps_csv ); ?>';
        const pTitle     = '<?php echo esc_js( $cfg['title'] ); ?>';
        const reloadUrl  = '<?php echo $current_url; ?>';

        const card       = document.getElementById('cmp-auth-card-' + uid);
        const loginView  = document.getElementById('cmp-login-view-' + uid);
        const forgotView = document.getElementById('cmp-forgot-view-' + uid);
        const loginAlert = document.getElementById('cmp-login-alert-' + uid);
        const forgotAlert= document.getElementById('cmp-forgot-alert-' + uid);

        function showAlert(el, type, message) {
            if (!el) return;
            el.textContent = message;
            el.className = 'cmp-auth-alert ' + type;
            el.style.display = 'block';
        }

        function hideAlert(el) {
            if (!el) return;
            el.textContent = '';
            el.className = 'cmp-auth-alert';
            el.style.display = 'none';
        }

        function triggerCardShake() {
            if (!card) return;
            card.classList.remove('cmp-shake');
            void card.offsetWidth;
            card.classList.add('cmp-shake');
        }

        // Password Visibility Toggle
        const passInput  = document.getElementById('cmp-pass-' + uid);
        const pwdToggle  = document.getElementById('cmp-pwd-toggle-' + uid);
        const eyeOpen    = document.getElementById('cmp-eye-open-' + uid);
        const eyeClosed  = document.getElementById('cmp-eye-closed-' + uid);

        if (pwdToggle && passInput) {
            pwdToggle.addEventListener('click', function() {
                const isPwd = passInput.type === 'password';
                passInput.type = isPwd ? 'text' : 'password';
                eyeOpen.style.display = isPwd ? 'none' : 'block';
                eyeClosed.style.display = isPwd ? 'block' : 'none';
                passInput.focus();
            });
        }

        // View Switching (Sign In <-> Forgot Password)
        const showForgotBtn = document.getElementById('cmp-show-forgot-' + uid);
        const backLoginBtn  = document.getElementById('cmp-back-login-' + uid);
        const loginUserInp  = document.getElementById('cmp-user-' + uid);
        const resetUserInp  = document.getElementById('cmp-reset-user-' + uid);

        if (showForgotBtn) {
            showForgotBtn.addEventListener('click', function() {
                hideAlert(forgotAlert);
                loginView.style.display = 'none';
                forgotView.style.display = 'block';
                if (loginUserInp && loginUserInp.value.trim() !== '') {
                    resetUserInp.value = loginUserInp.value.trim();
                }
                resetUserInp.focus();
            });
        }

        if (backLoginBtn) {
            backLoginBtn.addEventListener('click', function() {
                hideAlert(loginAlert);
                forgotView.style.display = 'none';
                loginView.style.display = 'block';
                loginUserInp.focus();
            });
        }

        // AJAX Sign In Submission
        const loginForm    = document.getElementById('cmp-login-form-' + uid);
        const loginBtn     = document.getElementById('cmp-login-btn-' + uid);
        const loginSpin    = document.getElementById('cmp-login-spin-' + uid);
        const loginBtnText = document.getElementById('cmp-login-btn-text-' + uid);

        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                hideAlert(loginAlert);

                const logVal = loginUserInp.value.trim();
                const pwdVal = passInput.value;
                const remVal = document.getElementById('cmp-remember-' + uid).checked ? '1' : '';

                if (!logVal || !pwdVal) {
                    showAlert(loginAlert, 'error', 'Please enter both your email/username and password.');
                    triggerCardShake();
                    return;
                }

                loginBtn.disabled = true;
                loginSpin.style.display = 'inline-block';
                loginBtnText.textContent = 'Verifying...';

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_portal_login');
                formData.append('nonce', nonce);
                formData.append('log', logVal);
                formData.append('pwd', pwdVal);
                formData.append('rememberme', remVal);
                formData.append('required_caps', reqCaps);
                formData.append('portal_title', pTitle);

                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(res => res.json())
                .then(response => {
                    if (response && response.success) {
                        showAlert(loginAlert, 'success', response.data.message);
                        loginBtnText.textContent = 'Redirecting...';
                        window.location.href = reloadUrl || window.location.href;
                    } else {
                        const errMsg = (response && response.data && response.data.message)
                            ? response.data.message
                            : 'Incorrect username/email or password. Please try again.';
                        showAlert(loginAlert, 'error', errMsg);
                        triggerCardShake();
                        loginBtn.disabled = false;
                        loginSpin.style.display = 'none';
                        loginBtnText.textContent = 'Sign In to ' + pTitle;
                        passInput.value = '';
                        passInput.focus();
                    }
                })
                .catch(() => {
                    showAlert(loginAlert, 'error', 'Unable to verify credentials right now. Please try again.');
                    triggerCardShake();
                    loginBtn.disabled = false;
                    loginSpin.style.display = 'none';
                    loginBtnText.textContent = 'Sign In to ' + pTitle;
                });
            });
        }

        // AJAX Forgot Password Submission
        const forgotForm    = document.getElementById('cmp-forgot-form-' + uid);
        const forgotBtn     = document.getElementById('cmp-forgot-btn-' + uid);
        const forgotSpin    = document.getElementById('cmp-forgot-spin-' + uid);
        const forgotBtnText = document.getElementById('cmp-forgot-btn-text-' + uid);

        if (forgotForm) {
            forgotForm.addEventListener('submit', function(e) {
                e.preventDefault();
                hideAlert(forgotAlert);

                const userVal = resetUserInp.value.trim();
                if (!userVal) {
                    showAlert(forgotAlert, 'error', 'Please enter your email address or username.');
                    triggerCardShake();
                    return;
                }

                forgotBtn.disabled = true;
                forgotSpin.style.display = 'inline-block';
                forgotBtnText.textContent = 'Sending Reset Link...';

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_portal_forgot_password');
                formData.append('nonce', nonce);
                formData.append('user_login', userVal);

                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(res => res.json())
                .then(response => {
                    if (response && response.success) {
                        showAlert(forgotAlert, 'success', response.data.message);
                        resetUserInp.value = '';
                    } else {
                        const errMsg = (response && response.data && response.data.message)
                            ? response.data.message
                            : 'Could not process password reset request.';
                        showAlert(forgotAlert, 'error', errMsg);
                        triggerCardShake();
                    }
                })
                .catch(() => {
                    showAlert(forgotAlert, 'error', 'Network error occurred. Please try again.');
                    triggerCardShake();
                })
                .finally(() => {
                    forgotBtn.disabled = false;
                    forgotSpin.style.display = 'none';
                    forgotBtnText.textContent = 'Send Password Reset Link';
                });
            });
        }
    })();
    </script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}
