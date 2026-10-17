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
// 6. UNIFIED PORTAL AUTHENTICATION & GLASS UI/UX ENGINE
// ==========================================

// 6.1 AJAX Login Handler
add_action( 'wp_ajax_nopriv_cmp_portal_login', 'cmp_handle_portal_ajax_login' );
add_action( 'wp_ajax_cmp_portal_login', 'cmp_handle_portal_ajax_login' );
function cmp_handle_portal_ajax_login() {
    if ( ! check_ajax_referer( 'cmp_portal_auth_nonce', 'nonce', false ) ) {
        wp_send_json_error( array(
            'message' => 'Session expired. Please refresh the page and try again.'
        ) );
    }

    // Prevent fallback redirect hook from firing during AJAX authentication
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

    $user_data = is_email( $user_login ) ? get_user_by( 'email', $user_login ) : get_user_by( 'login', $user_login );
    if ( ! $user_data ) {
        wp_send_json_error( array(
            'message' => 'No account was found matching that email address or username.'
        ) );
    }

    $errors = retrieve_password( $user_data->user_login );

    if ( true === $errors ) {
        wp_send_json_success( array(
            'message' => 'Password reset link sent! Please check your email inbox (and spam folder).'
        ) );
    } else {
        $err_msg = is_wp_error( $errors ) ? wp_strip_all_tags( $errors->get_error_message() ) : 'Unable to send password reset email. Please contact support.';
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

// 6.4 Reusable Glassmorphism Portal Login & Access-Denied UI/UX Renderer
function cmp_render_portal_login_ui( $args = array() ) {
    $defaults = array(
        'portal_id'      => 'portal',
        'title'          => 'Staff Portal',
        'subtitle'       => 'Sign in to access your dashboard',
        'required_caps'  => array( 'manage_options' ),
        'denied_message' => 'Your account does not have clearance to view this portal.',
        'accent_color'   => '#379237',
        'header_bg'      => '#1B5E20',
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
        /* =========================================================
           THE CYCLE BISTRO — BRAND GLASSMORPHISM LOGIN UI/UX
           Palette:
           - Deep Forest Gear:  #1B5E20
           - Core Brand Green:  #379237
           - Fresh Leaf Lime:   #68B946
           - Soft Pistachio:    #DCECC9 / #EEF7E3
           - Bistro Charcoal:   #231F20
           ========================================================= */
        #cmp-auth-stage-<?php echo $uid; ?>,
        #cmp-auth-stage-<?php echo $uid; ?> * {
            box-sizing: border-box !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
            letter-spacing: normal !important;
        }

        /* Ambient Stage Container for True Glass Refraction */
        #cmp-auth-stage-<?php echo $uid; ?> {
            position: relative !important;
            width: 100% !important;
            max-width: 520px !important;
            margin: 32px auto 60px auto !important;
            padding: 24px 18px !important;
            z-index: 1 !important;
        }

        /* Decorative Brand Glow Orbs Behind Glass Card */
        #cmp-auth-stage-<?php echo $uid; ?>::before,
        #cmp-auth-stage-<?php echo $uid; ?>::after {
            content: "" !important;
            position: absolute !important;
            border-radius: 50% !important;
            filter: blur(42px) !important;
            z-index: -1 !important;
            pointer-events: none !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?>::before {
            width: 230px !important;
            height: 230px !important;
            top: -10px !important;
            left: -15px !important;
            background: radial-gradient(circle, rgba(104, 185, 70, 0.42) 0%, rgba(55, 146, 55, 0.12) 70%, transparent 100%) !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?>::after {
            width: 250px !important;
            height: 250px !important;
            bottom: -15px !important;
            right: -15px !important;
            background: radial-gradient(circle, rgba(220, 236, 201, 0.85) 0%, rgba(27, 94, 32, 0.18) 70%, transparent 100%) !important;
        }

        /* Main Frosted Glass Card */
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-card {
            position: relative !important;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.84) 0%, rgba(244, 250, 238, 0.74) 100%) !important;
            backdrop-filter: blur(22px) saturate(170%) !important;
            -webkit-backdrop-filter: blur(22px) saturate(170%) !important;
            border-radius: 22px !important;
            border: 1.5px solid rgba(255, 255, 255, 0.85) !important;
            box-shadow:
                0 22px 48px rgba(27, 94, 32, 0.14),
                0 6px 16px rgba(35, 31, 32, 0.06),
                inset 0 1px 1px rgba(255, 255, 255, 0.95) !important;
            overflow: hidden !important;
            transition: all 0.3s ease !important;
        }

        /* =========================================================
           DESKTOP HEADER STYLING (> 992px)
           Deep Forest (#1B5E20) -> Core (#379237) -> Leaf (#68B946)
           ========================================================= */
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header {
            position: relative !important;
            background: linear-gradient(135deg, #1B5E20 0%, #379237 58%, #68B946 100%) !important;
            color: #ffffff !important;
            padding: 32px 30px 26px 30px !important;
            text-align: center !important;
            border-bottom: 3px solid #DCECC9 !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            overflow: hidden !important;
        }

        /* Subtle Glass Sheen Overlay inside Header */
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header::after {
            content: "" !important;
            position: absolute !important;
            top: -50% !important;
            right: -20% !important;
            width: 220px !important;
            height: 220px !important;
            background: radial-gradient(circle, rgba(220, 236, 201, 0.28) 0%, transparent 70%) !important;
            pointer-events: none !important;
        }

        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 56px !important;
            height: 56px !important;
            border-radius: 16px !important;
            background: rgba(255, 255, 255, 0.18) !important;
            backdrop-filter: blur(8px) !important;
            -webkit-backdrop-filter: blur(8px) !important;
            border: 1.5px solid rgba(220, 236, 201, 0.55) !important;
            margin: 0 auto 8px auto !important;
            box-shadow: 0 8px 20px rgba(15, 46, 18, 0.22) !important;
            flex-shrink: 0 !important;
        }

        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header-text {
            text-align: center !important;
        }

        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 {
            margin: 0 !important;
            padding: 0 !important;
            font-size: 25px !important;
            line-height: 1.2 !important;
            font-weight: 800 !important;
            color: #ffffff !important;
            text-transform: none !important;
            text-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
        }

        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header p {
            margin: 6px 0 0 0 !important;
            padding: 0 !important;
            font-size: 13.5px !important;
            line-height: 1.45 !important;
            color: #EEF7E3 !important;
            font-weight: 500 !important;
        }

        /* =========================================================
           FORM BODY & GLASS INPUT CONTROLS
           ========================================================= */
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body {
            padding: 28px 30px 32px 30px !important;
            background: transparent !important;
        }

        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert {
            display: none;
            padding: 12px 15px !important;
            border-radius: 12px !important;
            font-size: 13.5px !important;
            line-height: 1.45 !important;
            margin: 0 0 18px 0 !important;
            font-weight: 600 !important;
            backdrop-filter: blur(10px) !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert.error {
            display: block !important;
            background: rgba(254, 242, 242, 0.92) !important;
            color: #991b1b !important;
            border: 1px solid #fecaca !important;
            border-left: 4px solid #dc2626 !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert.success {
            display: block !important;
            background: rgba(238, 247, 227, 0.95) !important;
            color: #1B5E20 !important;
            border: 1px solid #b7e1a1 !important;
            border-left: 4px solid #379237 !important;
        }

        @keyframes cmpShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }
        .cmp-shake {
            animation: cmpShake 0.35s ease-in-out;
        }

        #cmp-auth-stage-<?php echo $uid; ?> .cmp-form-group {
            margin: 0 0 18px 0 !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-form-group label {
            display: block !important;
            font-size: 13.5px !important;
            line-height: 1.3 !important;
            font-weight: 700 !important;
            color: #231F20 !important;
            margin: 0 0 7px 0 !important;
            text-transform: none !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            width: 100% !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-icon {
            position: absolute !important;
            left: 14px !important;
            color: #379237 !important;
            pointer-events: none !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            z-index: 2 !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="text"],
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="password"],
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="email"] {
            width: 100% !important;
            height: 48px !important;
            padding: 10px 44px 10px 42px !important;
            font-size: 15px !important;
            line-height: 1.4 !important;
            color: #231F20 !important;
            background: rgba(255, 255, 255, 0.82) !important;
            border: 1.5px solid rgba(55, 146, 55, 0.28) !important;
            border-radius: 12px !important;
            transition: all 0.2s ease !important;
            outline: none !important;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.03) !important;
            margin: 0 !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input:focus {
            background: #ffffff !important;
            border-color: #379237 !important;
            box-shadow: 0 0 0 4px rgba(104, 185, 70, 0.22) !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle {
            position: absolute !important;
            right: 8px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            color: #526352 !important;
            cursor: pointer !important;
            padding: 6px !important;
            margin: 0 !important;
            min-height: 0 !important;
            min-width: 0 !important;
            width: 34px !important;
            height: 34px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 8px !important;
            z-index: 2 !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle:hover,
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle:focus {
            color: #1B5E20 !important;
            background: rgba(220, 236, 201, 0.6) !important;
            outline: none !important;
        }

        /* Remember Me & Forgot Password Row */
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-meta {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            gap: 10px !important;
            flex-wrap: nowrap !important;
            margin: 4px 0 22px 0 !important;
            width: 100% !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label {
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            color: #231F20 !important;
            cursor: pointer !important;
            font-size: 13.5px !important;
            line-height: 1.2 !important;
            font-weight: 600 !important;
            margin: 0 !important;
            white-space: nowrap !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label input[type="checkbox"] {
            width: 17px !important;
            height: 17px !important;
            min-width: 17px !important;
            accent-color: #379237 !important;
            cursor: pointer !important;
            margin: 0 !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action {
            background: transparent !important;
            border: none !important;
            color: #1B5E20 !important;
            font-weight: 700 !important;
            font-size: 13.5px !important;
            line-height: 1.2 !important;
            cursor: pointer !important;
            padding: 4px 8px !important;
            margin: 0 !important;
            border-radius: 6px !important;
            text-decoration: none !important;
            white-space: nowrap !important;
            display: inline-block !important;
            transition: all 0.2s ease !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action:hover {
            color: #1B5E20 !important;
            background: rgba(220, 236, 201, 0.65) !important;
            text-decoration: none !important;
        }

        /* Primary Brand Submit Button */
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn,
        #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn {
            width: 100% !important;
            min-height: 50px !important;
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important;
            background-color: #379237 !important;
            color: #ffffff !important;
            border: 1px solid rgba(220, 236, 201, 0.4) !important;
            padding: 13px 20px !important;
            font-size: 15.5px !important;
            line-height: 1.3 !important;
            font-weight: 700 !important;
            text-transform: none !important;
            border-radius: 12px !important;
            cursor: pointer !important;
            transition: all 0.25s ease !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 9px !important;
            text-decoration: none !important;
            box-shadow: 0 8px 20px rgba(55, 146, 55, 0.30) !important;
            margin: 0 !important;
            white-space: nowrap !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn:hover,
        #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn:hover {
            background: linear-gradient(135deg, #68B946 0%, #379237 100%) !important;
            background-color: #287028 !important;
            color: #ffffff !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 10px 24px rgba(55, 146, 55, 0.38) !important;
        }
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn:disabled {
            opacity: 0.68 !important;
            cursor: not-allowed !important;
            transform: none !important;
        }

        #cmp-auth-stage-<?php echo $uid; ?> .cmp-spinner {
            width: 17px !important;
            height: 17px !important;
            border: 2.5px solid rgba(255,255,255,0.35) !important;
            border-top-color: #ffffff !important;
            border-radius: 50% !important;
            animation: cmpSpin 0.7s linear infinite !important;
            display: none;
            flex-shrink: 0 !important;
        }
        @keyframes cmpSpin { to { transform: rotate(360deg); } }

        #cmp-auth-stage-<?php echo $uid; ?> .cmp-user-pill {
            background: rgba(238, 247, 227, 0.75) !important;
            border: 1px solid rgba(55, 146, 55, 0.28) !important;
            border-radius: 12px !important;
            padding: 14px !important;
            margin-bottom: 18px !important;
            font-size: 14px !important;
            line-height: 1.5 !important;
            color: #231F20 !important;
        }

        /* =========================================================
           TABLET HEADER & CARD STYLING (601px to 991px)
           Vibrant Leaf-to-Forest Glass Header + Soft Sage Rim
           ========================================================= */
        @media (min-width: 601px) and (max-width: 991px) {
            #cmp-auth-stage-<?php echo $uid; ?> {
                max-width: 460px !important;
                margin: 24px auto 48px auto !important;
                padding: 18px 16px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header {
                background: linear-gradient(120deg, #246A28 0%, #379237 48%, #68B946 100%) !important;
                padding: 26px 24px 22px 24px !important;
                border-bottom: 4px solid #82CD47 !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge {
                width: 50px !important;
                height: 50px !important;
                border-radius: 14px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 {
                font-size: 23px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body {
                padding: 24px 24px 28px 24px !important;
            }
        }

        /* =========================================================
           MOBILE HEADER & CARD STYLING (<= 600px)
           Compact Horizontal Glass Header Banner + Touch Ergonomics
           ========================================================= */
        @media (max-width: 600px) {
            #cmp-auth-stage-<?php echo $uid; ?> {
                max-width: 100% !important;
                margin: 12px auto 32px auto !important;
                padding: 10px 12px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-card {
                border-radius: 18px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header {
                background: linear-gradient(160deg, #379237 0%, #1B5E20 100%) !important;
                padding: 18px 18px !important;
                flex-direction: row !important;
                text-align: left !important;
                justify-content: flex-start !important;
                align-items: center !important;
                gap: 14px !important;
                border-bottom: 3px solid #68B946 !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge {
                width: 46px !important;
                height: 46px !important;
                border-radius: 12px !important;
                margin: 0 !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header-text {
                text-align: left !important;
                flex: 1 !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 {
                font-size: 20px !important;
                line-height: 1.2 !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header p {
                font-size: 12.5px !important;
                margin-top: 3px !important;
                line-height: 1.35 !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body {
                padding: 20px 18px 22px 18px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="text"],
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="password"],
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="email"] {
                font-size: 16px !important; /* Prevents iOS auto-zoom */
                height: 48px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-meta {
                gap: 6px !important;
                margin-bottom: 18px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label,
            #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action {
                font-size: 13px !important;
            }
            #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn,
            #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn {
                font-size: 15px !important;
                min-height: 48px !important;
                padding: 12px 16px !important;
            }
        }
    </style>

    <div id="cmp-auth-stage-<?php echo $uid; ?>">
        <div class="cmp-auth-card" id="cmp-auth-card-<?php echo $uid; ?>">
            <div class="cmp-auth-header" style="background: linear-gradient(135deg, #1B5E20 0%, #379237 58%, #68B946 100%);">
                <div class="cmp-auth-badge">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#EEF7E3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <div class="cmp-auth-header-text">
                    <h2 style="color:#ffffff !important;"><?php echo esc_html( $cfg['title'] ); ?></h2>
                    <p style="color:#EEF7E3 !important;"><?php echo esc_html( $cfg['subtitle'] ); ?></p>
                </div>
            </div>

            <div class="cmp-auth-body">
                <?php if ( is_user_logged_in() ) : 
                    $curr_user = wp_get_current_user();
                ?>
                    <!-- LOGGED IN BUT LACKS CLEARANCE FOR THIS PORTAL -->
                    <div class="cmp-auth-alert error" style="display:block !important;">
                        <strong>Access Restricted:</strong> <?php echo esc_html( $cfg['denied_message'] ); ?>
                    </div>
                    <div class="cmp-user-pill">
                        Currently signed in as:<br>
                        <strong style="color:#231F20;"><?php echo esc_html( $curr_user->display_name ); ?></strong>
                        <span style="color:#526352;">(<?php echo esc_html( $curr_user->user_email ); ?>)</span>
                    </div>
                    <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="cmp-submit-btn" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: #ffffff;">
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
                                    <span class="cmp-input-icon">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="12" cy="7" r="4"></circle>
                                        </svg>
                                    </span>
                                    <input type="text" id="cmp-user-<?php echo $uid; ?>" name="log" placeholder="name@thecyclebistro.com" autocomplete="username" required>
                                </div>
                            </div>

                            <div class="cmp-form-group">
                                <label for="cmp-pass-<?php echo $uid; ?>">Password</label>
                                <div class="cmp-input-wrap">
                                    <span class="cmp-input-icon">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                        </svg>
                                    </span>
                                    <input type="password" id="cmp-pass-<?php echo $uid; ?>" name="pwd" placeholder="Enter your password" autocomplete="current-password" required>
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
                                <a href="#forgot-password" class="cmp-link-action" id="cmp-show-forgot-<?php echo $uid; ?>" style="color:#1B5E20;">Forgot Password?</a>
                            </div>

                            <button type="submit" class="cmp-submit-btn" id="cmp-login-btn-<?php echo $uid; ?>" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: #ffffff;">
                                <span class="cmp-spinner" id="cmp-login-spin-<?php echo $uid; ?>"></span>
                                <span id="cmp-login-btn-text-<?php echo $uid; ?>">Sign In</span>
                                <svg id="cmp-login-arrow-<?php echo $uid; ?>" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                        </form>
                    </div>

                    <!-- VIEW 2: FORGOT PASSWORD FORM -->
                    <div id="cmp-forgot-view-<?php echo $uid; ?>" style="display:none;">
                        <div id="cmp-forgot-alert-<?php echo $uid; ?>" class="cmp-auth-alert"></div>

                        <p style="margin: 0 0 16px 0 !important; font-size: 13.5px !important; color: #231F20 !important; line-height: 1.5 !important;">
                            Enter your account email or username and we will send you a password reset link.
                        </p>

                        <form id="cmp-forgot-form-<?php echo $uid; ?>" novalidate>
                            <div class="cmp-form-group">
                                <label for="cmp-reset-user-<?php echo $uid; ?>">Email Address or Username</label>
                                <div class="cmp-input-wrap">
                                    <span class="cmp-input-icon">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                            <polyline points="22,6 12,13 2,6"></polyline>
                                        </svg>
                                    </span>
                                    <input type="text" id="cmp-reset-user-<?php echo $uid; ?>" name="user_login" placeholder="name@thecyclebistro.com" autocomplete="username" required>
                                </div>
                            </div>

                            <button type="submit" class="cmp-submit-btn" id="cmp-forgot-btn-<?php echo $uid; ?>" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: #ffffff;">
                                <span class="cmp-spinner" id="cmp-forgot-spin-<?php echo $uid; ?>"></span>
                                <span id="cmp-forgot-btn-text-<?php echo $uid; ?>">Send Reset Link</span>
                            </button>
                        </form>

                        <div style="text-align: center !important; margin-top: 18px !important;">
                            <a href="#sign-in" class="cmp-link-action" id="cmp-back-login-<?php echo $uid; ?>" style="color:#1B5E20;">&larr; Back to Sign In</a>
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
            el.style.setProperty('display', 'block', 'important');
        }

        function hideAlert(el) {
            if (!el) return;
            el.textContent = '';
            el.className = 'cmp-auth-alert';
            el.style.setProperty('display', 'none', 'important');
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
            pwdToggle.addEventListener('click', function(e) {
                e.preventDefault();
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
            showForgotBtn.addEventListener('click', function(e) {
                e.preventDefault();
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
            backLoginBtn.addEventListener('click', function(e) {
                e.preventDefault();
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
        const loginArrow   = document.getElementById('cmp-login-arrow-' + uid);

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
                if (loginArrow) loginArrow.style.display = 'none';
                loginBtnText.textContent = 'Signing in...';

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
                        loginBtnText.textContent = 'Loading...';
                        window.location.href = reloadUrl || window.location.href;
                    } else {
                        const errMsg = (response && response.data && response.data.message)
                            ? response.data.message
                            : 'Incorrect username/email or password. Please try again.';
                        showAlert(loginAlert, 'error', errMsg);
                        triggerCardShake();
                        loginBtn.disabled = false;
                        loginSpin.style.display = 'none';
                        if (loginArrow) loginArrow.style.display = 'inline-block';
                        loginBtnText.textContent = 'Sign In';
                        passInput.value = '';
                        passInput.focus();
                    }
                })
                .catch(() => {
                    showAlert(loginAlert, 'error', 'Unable to verify credentials right now. Please try again.');
                    triggerCardShake();
                    loginBtn.disabled = false;
                    loginSpin.style.display = 'none';
                    if (loginArrow) loginArrow.style.display = 'inline-block';
                    loginBtnText.textContent = 'Sign In';
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
                forgotBtnText.textContent = 'Sending...';

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
                    forgotBtnText.textContent = 'Send Reset Link';
                });
            });
        }
    })();
    </script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}
