<?php
/**
 * Plugin Name: Meal Subscription Portal
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/meal-subscription-portal
 * Description: A custom meal subscription and kitchen reporting engine.
 * Version: 3.0.0
 * Author: RM Dev Team | Customised by Fareed M Rifaideen
 * Author URI: https://fareed-rifaideen.netlify.app
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CMP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Flush PHP OPcache for plugin files if active on server
if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
    if ( file_exists( CMP_PLUGIN_DIR . 'accounts-portal.php' ) ) {
        @opcache_invalidate( CMP_PLUGIN_DIR . 'accounts-portal.php', true );
    }
}

// ==========================================
// 0. PREVENT SERVER/BROWSER CACHING ON PORTAL PAGES
// ==========================================
add_action( 'template_redirect', 'cmp_disable_portal_page_caching', 1 );
function cmp_disable_portal_page_caching() {
    if ( is_singular() ) {
        global $post;
        if ( $post && (
            has_shortcode( $post->post_content, 'meal_accounts_portal' ) ||
            has_shortcode( $post->post_content, 'meal_kitchen_portal' ) ||
            has_shortcode( $post->post_content, 'meal_foh_portal' ) ||
            has_shortcode( $post->post_content, 'meal_super_admin_portal' ) ||
            has_shortcode( $post->post_content, 'meal_customer_portal' )
        ) ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
            if ( ! defined( 'DONOTCACHEOBJECT' ) ) { define( 'DONOTCACHEOBJECT', true ); }
            if ( ! defined( 'DONOTCACHEDB' ) ) { define( 'DONOTCACHEDB', true ); }
            nocache_headers();
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
        }
    }
}

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
        updated_by_id bigint(20) DEFAULT 0,
        updated_by_name varchar(150) NULL,
        updated_by_role varchar(100) NULL,
        last_action_type varchar(50) NULL,
        updated_at datetime NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_logs );

    $table_activity = $wpdb->prefix . 'cmp_activity_logs';
    $sql_activity = "CREATE TABLE $table_activity (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        subscription_id mediumint(9) NOT NULL DEFAULT 0,
        wc_order_id bigint(20) NOT NULL DEFAULT 0,
        user_id bigint(20) NOT NULL DEFAULT 0,
        customer_name varchar(255) NOT NULL DEFAULT '',
        plan_name varchar(255) NOT NULL DEFAULT '',
        action_type varchar(100) NOT NULL DEFAULT '',
        target_date varchar(50) DEFAULT '',
        old_status text NULL,
        new_status text NULL,
        actor_id bigint(20) NOT NULL DEFAULT 0,
        actor_name varchar(150) NOT NULL DEFAULT '',
        actor_role varchar(100) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY sub_idx (subscription_id),
        KEY order_idx (wc_order_id),
        KEY action_idx (action_type),
        KEY created_idx (created_at)
    ) $charset_collate;";
    dbDelta( $sql_activity );

    cmp_register_custom_roles();
}

// ==========================================
// 1.5 AUTO-PATCHER (UPGRADES LIVE DATABASE SAFELY)
// ==========================================
add_action( 'init', 'cmp_auto_patch_database', 5 );
function cmp_auto_patch_database() {
    global $wpdb;
    $table_subs     = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs     = $wpdb->prefix . 'cmp_daily_logs';
    $table_activity = $wpdb->prefix . 'cmp_activity_logs';

    // Run full check if schema version has not reached 3.0.0 or when inside wp-admin
    $schema_ver = get_option( 'cmp_db_schema_version', '' );
    if ( $schema_ver === '3.0.0' && ! is_admin() ) {
        return;
    }

    // Patch 1: Pricing Columns
    $col_check_price = $wpdb->get_results( "SHOW COLUMNS FROM {$table_subs} LIKE 'plan_price'" );
    if ( empty( $col_check_price ) ) {
        $wpdb->query( "ALTER TABLE {$table_subs} ADD plan_price DECIMAL(10,2) DEFAULT '0.00' AFTER allowed_categories" );
        $wpdb->query( "ALTER TABLE {$table_subs} ADD discount_amount DECIMAL(10,2) DEFAULT '0.00' AFTER plan_price" );
    }

    // Patch 2: Phase 5 Advanced Personalization Flags (Subscriptions)
    $col_check_flex = $wpdb->get_results( "SHOW COLUMNS FROM {$table_subs} LIKE 'is_flexible'" );
    if ( empty( $col_check_flex ) ) {
        $wpdb->query( "ALTER TABLE {$table_subs} ADD is_flexible TINYINT(1) DEFAULT 0 AFTER status" );
        $wpdb->query( "ALTER TABLE {$table_subs} ADD allow_custom TINYINT(1) DEFAULT 0 AFTER is_flexible" );
    }

    // Patch 3: Phase 5 Custom Text Input Fields (Daily Logs)
    $col_check_custom = $wpdb->get_results( "SHOW COLUMNS FROM {$table_logs} LIKE 'custom_breakfast'" );
    if ( empty( $col_check_custom ) ) {
        $wpdb->query( "ALTER TABLE {$table_logs} ADD custom_breakfast VARCHAR(255) NULL AFTER breakfast_id" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD custom_lunch VARCHAR(255) NULL AFTER lunch_id" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD custom_dinner VARCHAR(255) NULL AFTER dinner_id" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD custom_snack_1 VARCHAR(255) NULL AFTER snack_1_id" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD custom_snack_2 VARCHAR(255) NULL AFTER snack_2_id" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD custom_juice_1 VARCHAR(255) NULL AFTER juice_1_id" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD custom_juice_2 VARCHAR(255) NULL AFTER juice_2_id" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD custom_juice_3 VARCHAR(255) NULL AFTER juice_3_id" );
    }

    // Patch 4: Phase 7 Log-Level Plan Mode Snapshot (Daily Logs)
    $col_check_mode = $wpdb->get_results( "SHOW COLUMNS FROM {$table_logs} LIKE 'plan_mode'" );
    if ( empty( $col_check_mode ) ) {
        $wpdb->query( "ALTER TABLE {$table_logs} ADD plan_mode VARCHAR(20) DEFAULT 'standard' AFTER is_locked" );
    }

    // Patch 5: Row-Level Audit Stamp Columns (Who Updated/Cancelled & When)
    $col_check_audit = $wpdb->get_results( "SHOW COLUMNS FROM {$table_logs} LIKE 'updated_by_name'" );
    if ( empty( $col_check_audit ) ) {
        $wpdb->query( "ALTER TABLE {$table_logs} ADD updated_by_id BIGINT(20) DEFAULT 0 AFTER plan_mode" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD updated_by_name VARCHAR(150) NULL AFTER updated_by_id" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD updated_by_role VARCHAR(100) NULL AFTER updated_by_name" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD last_action_type VARCHAR(50) NULL AFTER updated_by_role" );
        $wpdb->query( "ALTER TABLE {$table_logs} ADD updated_at DATETIME NULL AFTER last_action_type" );
    }

    // Patch 6: Customer Activity Logs Table (For Meal Portal > Customer Logs)
    $activity_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_activity ) );
    if ( $activity_exists !== $table_activity ) {
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $charset_collate = $wpdb->get_charset_collate();
        $sql_activity = "CREATE TABLE $table_activity (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subscription_id mediumint(9) NOT NULL DEFAULT 0,
            wc_order_id bigint(20) NOT NULL DEFAULT 0,
            user_id bigint(20) NOT NULL DEFAULT 0,
            customer_name varchar(255) NOT NULL DEFAULT '',
            plan_name varchar(255) NOT NULL DEFAULT '',
            action_type varchar(100) NOT NULL DEFAULT '',
            target_date varchar(50) DEFAULT '',
            old_status text NULL,
            new_status text NULL,
            actor_id bigint(20) NOT NULL DEFAULT 0,
            actor_name varchar(150) NOT NULL DEFAULT '',
            actor_role varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY sub_idx (subscription_id),
            KEY order_idx (wc_order_id),
            KEY action_idx (action_type),
            KEY created_idx (created_at)
        ) $charset_collate;";
        dbDelta( $sql_activity );
    }

    update_option( 'cmp_db_schema_version', '3.0.0' );
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
// 2.5 GLOBAL AUDIT & CUSTOMER LOGGING HELPERS
// ==========================================

/**
 * Returns the current logged-in user's ID, clean display name, and friendly role label.
 */
function cmp_get_current_actor_info( $subscription_owner_id = 0 ) {
    if ( ! is_user_logged_in() ) {
        return array(
            'id'   => 0,
            'name' => 'System',
            'role' => 'System',
        );
    }

    $current_user = wp_get_current_user();
    $actor_id     = intval( $current_user->ID );
    $fname        = get_user_meta( $actor_id, 'first_name', true );
    $lname        = get_user_meta( $actor_id, 'last_name', true );
    $actor_name   = trim( $fname . ' ' . $lname );
    if ( empty( $actor_name ) ) {
        $actor_name = $current_user->display_name ?: $current_user->user_login;
    }

    $roles      = (array) $current_user->roles;
    $actor_role = 'Customer';

    if ( in_array( 'administrator', $roles, true ) || current_user_can( 'manage_options' ) ) {
        $actor_role = 'Admin';
    } elseif ( in_array( 'menu_manager', $roles, true ) ) {
        $actor_role = 'Menu Manager';
    } elseif ( in_array( 'foh_manager', $roles, true ) ) {
        $actor_role = 'FOH';
    } elseif ( in_array( 'kitchen_staff', $roles, true ) ) {
        $actor_role = 'Kitchen';
    } elseif ( in_array( 'accounts_team', $roles, true ) ) {
        $actor_role = 'Accounts';
    } elseif ( intval( $subscription_owner_id ) > 0 && $actor_id !== intval( $subscription_owner_id ) ) {
        $actor_role = 'Staff';
    }

    return array(
        'id'   => $actor_id,
        'name' => $actor_name,
        'role' => $actor_role,
    );
}

/**
 * Builds a concise human-readable snapshot of a daily log row (Status + Date + Meals)
 * for recording "Old Status" and "Current Status" in Customer Logs.
 */
function cmp_format_log_meal_snapshot( $log_row ) {
    if ( empty( $log_row ) ) {
        return 'Pending Selection (Unscheduled)';
    }

    if ( is_array( $log_row ) ) {
        $log_row = (object) $log_row;
    }

    global $wpdb;
    static $food_names_cache = null;
    if ( $food_names_cache === null ) {
        $food_names_cache = array();
        $table_foods      = $wpdb->prefix . 'cmp_foods';
        $rows             = $wpdb->get_results( "SELECT id, food_name FROM {$table_foods}" );
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $food_names_cache[ intval( $r->id ) ] = $r->food_name;
            }
        }
    }

    $date_str = ! empty( $log_row->target_date ) ? $log_row->target_date : 'No Date';
    $del_res  = ! empty( $log_row->delivery_result ) ? $log_row->delivery_result : 'Pending';

    if ( $del_res === 'Cancelled' ) {
        return "Cancelled ({$date_str})";
    }
    if ( $del_res === 'Returned' ) {
        return "Returned ({$date_str})";
    }

    $parts = array();

    $resolve_slot = function( $id_prop, $custom_prop, $label ) use ( $log_row, $food_names_cache ) {
        $custom_val = isset( $log_row->{$custom_prop} ) ? trim( (string) $log_row->{$custom_prop} ) : '';
        $id_val     = isset( $log_row->{$id_prop} ) ? intval( $log_row->{$id_prop} ) : 0;
        if ( $custom_val !== '' ) {
            return $label . ': ' . $custom_val . ' (Custom)';
        }
        if ( $id_val > 0 && isset( $food_names_cache[ $id_val ] ) ) {
            return $label . ': ' . $food_names_cache[ $id_val ];
        }
        return '';
    };

    $slots = array(
        array( 'breakfast_id', 'custom_breakfast', 'B' ),
        array( 'lunch_id',     'custom_lunch',     'L' ),
        array( 'dinner_id',    'custom_dinner',    'D' ),
        array( 'snack_1_id',   'custom_snack_1',   'S1' ),
        array( 'snack_2_id',   'custom_snack_2',   'S2' ),
        array( 'juice_1_id',   'custom_juice_1',   'J1' ),
        array( 'juice_2_id',   'custom_juice_2',   'J2' ),
        array( 'juice_3_id',   'custom_juice_3',   'J3' ),
    );

    foreach ( $slots as $s ) {
        $resolved = $resolve_slot( $s[0], $s[1], $s[2] );
        if ( $resolved !== '' ) {
            $parts[] = $resolved;
        }
    }

    $is_cc = ! empty( $log_row->is_chefs_choice );
    if ( $is_cc && empty( $parts ) ) {
        return "Selected ({$date_str}) | Chef's Choice (Pending Assignment)";
    } elseif ( $is_cc && ! empty( $parts ) ) {
        return "Selected ({$date_str}) | Chef's Choice [" . implode( ' • ', $parts ) . ']';
    }

    if ( empty( $parts ) ) {
        return "Pending Selection ({$date_str})";
    }

    return "Selected ({$date_str}) | " . implode( ' • ', $parts );
}

/**
 * Inserts an audit entry into wp_cmp_activity_logs.
 */
function cmp_record_customer_activity_log( $args = array() ) {
    global $wpdb;
    $table_activity = $wpdb->prefix . 'cmp_activity_logs';
    $table_subs     = $wpdb->prefix . 'cmp_subscriptions';

    $sub_id      = isset( $args['subscription_id'] ) ? intval( $args['subscription_id'] ) : 0;
    $wc_order_id = isset( $args['wc_order_id'] ) ? intval( $args['wc_order_id'] ) : 0;
    $user_id     = isset( $args['user_id'] ) ? intval( $args['user_id'] ) : 0;
    $cust_name   = isset( $args['customer_name'] ) ? sanitize_text_field( $args['customer_name'] ) : '';
    $plan_name   = isset( $args['plan_name'] ) ? sanitize_text_field( $args['plan_name'] ) : '';

    if ( $sub_id > 0 && ( $user_id === 0 || empty( $plan_name ) ) ) {
        $sub_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", $sub_id ) );
        if ( $sub_row ) {
            $user_id     = intval( $sub_row->user_id );
            $wc_order_id = intval( $sub_row->wc_order_id );
            $plan_name   = $sub_row->plan_name;
        }
    }

    if ( empty( $cust_name ) && $user_id > 0 ) {
        $fname = get_user_meta( $user_id, 'first_name', true ) ?: get_user_meta( $user_id, 'billing_first_name', true );
        $lname = get_user_meta( $user_id, 'last_name', true ) ?: get_user_meta( $user_id, 'billing_last_name', true );
        $cust_name = trim( $fname . ' ' . $lname );
        if ( empty( $cust_name ) ) {
            $u = get_userdata( $user_id );
            $cust_name = $u ? $u->display_name : 'Customer #' . $user_id;
        }
        // Append Recipient Name if present in plan_name
        if ( strpos( $plan_name, ' - ' ) !== false ) {
            $plan_parts = explode( ' - ', $plan_name );
            $candidate  = trim( end( $plan_parts ) );
            if ( ! empty( $candidate ) && ! preg_match( '/(\d+\s*Day|\d+\s*Meal|Cleanse|Booster|Plan)/i', $candidate ) ) {
                $cust_name .= ' [' . $candidate . ']';
            }
        }
    }

    $actor = cmp_get_current_actor_info( $user_id );
    $tz    = new DateTimeZone( 'Asia/Dubai' );
    $now   = new DateTime( 'now', $tz );

    $wpdb->insert(
        $table_activity,
        array(
            'subscription_id' => $sub_id,
            'wc_order_id'     => $wc_order_id,
            'user_id'         => $user_id,
            'customer_name'   => $cust_name,
            'plan_name'       => $plan_name,
            'action_type'     => isset( $args['action_type'] ) ? sanitize_text_field( $args['action_type'] ) : 'Updated',
            'target_date'     => isset( $args['target_date'] ) ? sanitize_text_field( $args['target_date'] ) : '',
            'old_status'      => isset( $args['old_status'] ) ? wp_kses_post( $args['old_status'] ) : '',
            'new_status'      => isset( $args['new_status'] ) ? wp_kses_post( $args['new_status'] ) : '',
            'actor_id'        => isset( $args['actor_id'] ) ? intval( $args['actor_id'] ) : $actor['id'],
            'actor_name'      => isset( $args['actor_name'] ) ? sanitize_text_field( $args['actor_name'] ) : $actor['name'],
            'actor_role'      => isset( $args['actor_role'] ) ? sanitize_text_field( $args['actor_role'] ) : $actor['role'],
            'created_at'      => $now->format( 'Y-m-d H:i:s' ),
        )
    );
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
function cmp_schedule_exact_daily_cron($hook_name, $setting_key, $default_time) {
    $tz = new DateTimeZone('Asia/Dubai');
    $raw_val = get_option($setting_key, $default_time);
    $target_time = (strpos($raw_val, ':') !== false) ? $raw_val : str_pad($raw_val, 2, '0', STR_PAD_LEFT) . ':00';
    
    $next_scheduled = wp_next_scheduled($hook_name);
    $reschedule = false;

    if ($next_scheduled) {
        $scheduled_date = new DateTime('@' . $next_scheduled);
        $scheduled_date->setTimezone($tz);
        if ($scheduled_date->format('H:i') !== $target_time) {
            $reschedule = true;
            wp_clear_scheduled_hook($hook_name);
        }
    }

    if (!$next_scheduled || $reschedule) {
        $date = new DateTime("today $target_time", $tz);
        if ($date < new DateTime('now', $tz)) {
            $date->modify('+1 day');
        }
        wp_schedule_event( $date->getTimestamp(), 'daily', $hook_name );
    }
}

add_action('init', 'cmp_setup_crons');
function cmp_setup_crons() {
    wp_clear_scheduled_hook('cmp_hourly_watchdog_cron_hook');
    wp_clear_scheduled_hook('cmp_foh_sameday_alert_hook');
    wp_clear_scheduled_hook('cmp_foh_nextday_alert_hook');
    wp_clear_scheduled_hook('cmp_kit_sameday_alert_hook');
    wp_clear_scheduled_hook('cmp_kit_nextday_alert_hook');

    if ( ! wp_next_scheduled( 'cmp_daily_digest_cron_hook' ) ) {
        $tz = new DateTimeZone('Asia/Dubai');
        $cutoff_hour = intval(get_option('cmp_cutoff_time', '11'));
        $date = new DateTime("today $cutoff_hour:30", $tz);
        if ($date < new DateTime('now', $tz)) { $date->modify('+1 day'); }
        wp_schedule_event( $date->getTimestamp(), 'daily', 'cmp_daily_digest_cron_hook' );
    }

    cmp_schedule_exact_daily_cron('cmp_foh_alert_1_hook', 'cmp_pos_alert_time_1', '19:00');
    cmp_schedule_exact_daily_cron('cmp_foh_alert_2_hook', 'cmp_pos_alert_time_2', '10:00');
    cmp_schedule_exact_daily_cron('cmp_kit_alert_1_hook', 'cmp_kitchen_alert_time_1', '19:00');
    cmp_schedule_exact_daily_cron('cmp_kit_alert_2_hook', 'cmp_kitchen_alert_time_2', '10:00');
}

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
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    $defaults = array(
        'portal_id'      => 'portal',
        'title'          => 'Staff Portal',
        'subtitle'       => 'Sign in to access your dashboard',
        'required_caps'  => array( 'manage_options' ),
        'denied_message' => 'Your account does not have clearance to view this portal.',
    );
    $cfg = wp_parse_args( $args, $defaults );

    $uid         = esc_attr( $cfg['portal_id'] );
    $caps_csv    = is_array( $cfg['required_caps'] ) ? implode( ',', $cfg['required_caps'] ) : $cfg['required_caps'];
    $ajax_url    = esc_url( admin_url( 'admin-ajax.php' ) );
    $nonce       = wp_create_nonce( 'cmp_portal_auth_nonce' );
    $current_url = esc_url( remove_query_arg( array( 'cmp_login' ) ) );
    $url_failed  = isset( $_GET['cmp_login'] ) && $_GET['cmp_login'] === 'failed';

    ob_start();
    ?>
    <style>
        #cmp-auth-stage-<?php echo $uid; ?>, #cmp-auth-stage-<?php echo $uid; ?> * { box-sizing: border-box !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important; letter-spacing: normal !important; }
        #cmp-auth-stage-<?php echo $uid; ?> { position: relative !important; width: 100% !important; max-width: 500px !important; margin: 28px auto 60px auto !important; padding: 26px 20px !important; border-radius: 28px !important; background: radial-gradient(circle at 15% 15%, rgba(104, 185, 70, 0.28) 0%, rgba(220, 236, 201, 0.55) 48%, rgba(27, 94, 32, 0.12) 100%) !important; box-shadow: inset 0 1px 2px rgba(255,255,255,0.9) !important; z-index: 1 !important; overflow: hidden !important; }
        #cmp-auth-stage-<?php echo $uid; ?>::before, #cmp-auth-stage-<?php echo $uid; ?>::after { content: "" !important; position: absolute !important; border-radius: 50% !important; filter: blur(32px) !important; z-index: -1 !important; pointer-events: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?>::before { width: 220px !important; height: 220px !important; top: -30px !important; left: -30px !important; background: rgba(55, 146, 55, 0.45) !important; }
        #cmp-auth-stage-<?php echo $uid; ?>::after { width: 240px !important; height: 240px !important; bottom: -40px !important; right: -30px !important; background: rgba(104, 185, 70, 0.45) !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-card { position: relative !important; background: rgba(255, 255, 255, 0.68) !important; backdrop-filter: blur(24px) saturate(190%) !important; -webkit-backdrop-filter: blur(24px) saturate(190%) !important; border-radius: 22px !important; border: 1.5px solid rgba(255, 255, 255, 0.92) !important; box-shadow: 0 20px 45px rgba(27, 94, 32, 0.16), 0 4px 12px rgba(35, 31, 32, 0.06), inset 0 1px 2px rgba(255, 255, 255, 0.98) !important; overflow: hidden !important; transition: all 0.3s ease !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-brand-ribbon { height: 6px !important; width: 100% !important; background: linear-gradient(90deg, #1B5E20 0%, #379237 40%, #68B946 75%, #DCECC9 100%) !important; }
        /* DESKTOP HEADER (> 991px): Deep Forest (#1B5E20) -> Core Green (#379237) -> Fresh Leaf (#68B946) Glass */
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header { position: relative !important; background: linear-gradient(135deg, rgba(27, 94, 32, 0.92) 0%, rgba(55, 146, 55, 0.86) 55%, rgba(104, 185, 70, 0.82) 100%) !important; backdrop-filter: blur(18px) !important; -webkit-backdrop-filter: blur(18px) !important; padding: 28px 28px 24px 28px !important; text-align: center !important; border-bottom: 3px solid #DCECC9 !important; display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; gap: 8px !important; margin: 0 !important; border-radius: 0 !important; transition: all 0.3s ease !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 58px !important; height: 58px !important; border-radius: 18px !important; background: rgba(255, 255, 255, 0.20) !important; border: 1.5px solid #DCECC9 !important; margin: 0 auto 4px auto !important; box-shadow: 0 8px 20px rgba(15, 46, 18, 0.24) !important; flex-shrink: 0 !important; color: #ffffff !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-kicker { display: inline-block !important; padding: 4px 12px !important; border-radius: 999px !important; font-size: 11px !important; font-weight: 800 !important; text-transform: uppercase !important; letter-spacing: 0.06em !important; background: rgba(220, 236, 201, 0.25) !important; color: #EEF7E3 !important; border: 1px solid rgba(220, 236, 201, 0.55) !important; margin-bottom: 2px !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header-text { text-align: center !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 { margin: 0 !important; padding: 0 !important; font-size: 25px !important; line-height: 1.2 !important; font-weight: 800 !important; color: #ffffff !important; text-transform: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header p { margin: 5px 0 0 0 !important; padding: 0 !important; font-size: 13.5px !important; line-height: 1.45 !important; color: #EEF7E3 !important; font-weight: 500 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body { padding: 26px 28px 30px 28px !important; background: transparent !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert { display: none; padding: 12px 15px !important; border-radius: 12px !important; font-size: 13.5px !important; line-height: 1.45 !important; margin: 0 0 18px 0 !important; font-weight: 600 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert.error { display: block !important; background: rgba(254, 242, 242, 0.95) !important; color: #991b1b !important; border: 1px solid #fecaca !important; border-left: 4px solid #dc2626 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert.success { display: block !important; background: rgba(238, 247, 227, 0.96) !important; color: #1B5E20 !important; border: 1px solid #b7e1a1 !important; border-left: 4px solid #379237 !important; }
        @keyframes cmpShake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }
        .cmp-shake { animation: cmpShake 0.35s ease-in-out; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-form-group { margin: 0 0 18px 0 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-form-group label { display: block !important; font-size: 13.5px !important; line-height: 1.3 !important; font-weight: 700 !important; color: #231F20 !important; margin: 0 0 7px 0 !important; text-transform: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap { position: relative !important; display: flex !important; align-items: center !important; width: 100% !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-icon { position: absolute !important; left: 14px !important; color: #379237 !important; pointer-events: none !important; display: flex !important; align-items: center !important; justify-content: center !important; z-index: 2 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="text"], #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="password"], #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="email"] { width: 100% !important; height: 48px !important; padding: 10px 44px 10px 42px !important; font-size: 15px !important; line-height: 1.4 !important; color: #231F20 !important; background: rgba(255, 255, 255, 0.85) !important; border: 1.5px solid rgba(55, 146, 55, 0.32) !important; border-radius: 12px !important; transition: all 0.2s ease !important; outline: none !important; box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.03) !important; margin: 0 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input:focus { background: #ffffff !important; border-color: #379237 !important; box-shadow: 0 0 0 4px rgba(104, 185, 70, 0.25) !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle { position: absolute !important; right: 8px !important; top: 50% !important; transform: translateY(-50%) !important; background: transparent !important; border: none !important; box-shadow: none !important; color: #526352 !important; cursor: pointer !important; padding: 6px !important; margin: 0 !important; min-height: 0 !important; min-width: 0 !important; width: 34px !important; height: 34px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 8px !important; z-index: 2 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle:hover, #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle:focus { color: #1B5E20 !important; background: rgba(220, 236, 201, 0.7) !important; outline: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-meta { display: flex !important; justify-content: space-between !important; align-items: center !important; gap: 10px !important; flex-wrap: nowrap !important; margin: 4px 0 22px 0 !important; width: 100% !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label { display: inline-flex !important; align-items: center !important; gap: 8px !important; color: #231F20 !important; cursor: pointer !important; font-size: 13.5px !important; line-height: 1.2 !important; font-weight: 600 !important; margin: 0 !important; white-space: nowrap !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label input[type="checkbox"] { width: 17px !important; height: 17px !important; min-width: 17px !important; accent-color: #379237 !important; cursor: pointer !important; margin: 0 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action { background: transparent !important; border: none !important; color: #1B5E20 !important; font-weight: 700 !important; font-size: 13.5px !important; line-height: 1.2 !important; cursor: pointer !important; padding: 4px 8px !important; margin: 0 !important; border-radius: 6px !important; text-decoration: none !important; white-space: nowrap !important; display: inline-block !important; transition: all 0.2s ease !important; }
        #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action:hover { color: #1B5E20 !important; background: rgba(220, 236, 201, 0.75) !important; text-decoration: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn, #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn { width: 100% !important; min-height: 50px !important; background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; background-color: #379237 !important; color: #ffffff !important; border: 1px solid rgba(220, 236, 201, 0.45) !important; padding: 13px 20px !important; font-size: 15.5px !important; line-height: 1.3 !important; font-weight: 700 !important; text-transform: none !important; border-radius: 12px !important; cursor: pointer !important; transition: all 0.25s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 9px !important; text-decoration: none !important; box-shadow: 0 8px 20px rgba(55, 146, 55, 0.30) !important; margin: 0 !important; white-space: nowrap !important; }
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn:hover, #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn:hover { background: linear-gradient(135deg, #68B946 0%, #379237 100%) !important; background-color: #287028 !important; color: #ffffff !important; transform: translateY(-1px) !important; box-shadow: 0 10px 24px rgba(55, 146, 55, 0.38) !important; }
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn:disabled { opacity: 0.68 !important; cursor: not-allowed !important; transform: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-spinner { width: 17px !important; height: 17px !important; border: 2.5px solid rgba(255,255,255,0.35) !important; border-top-color: #ffffff !important; border-radius: 50% !important; animation: cmpSpin 0.7s linear infinite !important; display: none; flex-shrink: 0 !important; }
        @keyframes cmpSpin { to { transform: rotate(360deg); } }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-user-pill { background: rgba(238, 247, 227, 0.85) !important; border: 1px solid rgba(55, 146, 55, 0.3) !important; border-radius: 12px !important; padding: 14px !important; margin-bottom: 18px !important; font-size: 14px !important; line-height: 1.5 !important; color: #231F20 !important; }
        /* TABLET HEADER (601px to 991px) */
        @media (min-width: 601px) and (max-width: 991px) {
            #cmp-auth-stage-<?php echo $uid; ?> { max-width: 470px !important; margin: 22px auto 46px auto !important; padding: 20px 16px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header { background: linear-gradient(125deg, rgba(220, 236, 201, 0.92) 0%, rgba(104, 185, 70, 0.42) 100%) !important; flex-direction: row !important; text-align: left !important; justify-content: flex-start !important; align-items: center !important; gap: 16px !important; padding: 24px 26px !important; border-bottom: 3px solid #379237 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; border-color: #DCECC9 !important; width: 54px !important; height: 54px !important; margin: 0 !important; color: #ffffff !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header-text { text-align: left !important; flex: 1 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-kicker { background: rgba(27, 94, 32, 0.14) !important; color: #1B5E20 !important; border-color: rgba(27, 94, 32, 0.3) !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 { color: #231F20 !important; font-size: 23px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header p { color: #1B5E20 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body { padding: 24px 24px 28px 24px !important; }
        }
        /* MOBILE HEADER (<= 600px) */
        @media (max-width: 600px) {
            #cmp-auth-stage-<?php echo $uid; ?> { max-width: 100% !important; margin: 10px auto 30px auto !important; padding: 14px 12px !important; border-radius: 22px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-card { border-radius: 20px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header { background: linear-gradient(145deg, rgba(55, 146, 55, 0.95) 0%, rgba(27, 94, 32, 0.92) 100%) !important; margin: 12px 12px 0 12px !important; border-radius: 16px !important; padding: 16px 16px !important; flex-direction: row !important; text-align: left !important; justify-content: flex-start !important; align-items: center !important; gap: 13px !important; border: 1.5px solid #68B946 !important; box-shadow: 0 8px 20px rgba(27, 94, 32, 0.20) !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge { width: 46px !important; height: 46px !important; border-radius: 13px !important; margin: 0 !important; background: rgba(220, 236, 201, 0.22) !important; border-color: #DCECC9 !important; color: #ffffff !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header-text { text-align: left !important; flex: 1 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-kicker { background: rgba(220, 236, 201, 0.24) !important; color: #DCECC9 !important; border-color: rgba(220, 236, 201, 0.45) !important; font-size: 10px !important; padding: 2px 9px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 { color: #ffffff !important; font-size: 19.5px !important; line-height: 1.2 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header p { color: #DCECC9 !important; font-size: 12px !important; margin-top: 3px !important; line-height: 1.35 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body { padding: 18px 16px 22px 16px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="text"], #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="password"], #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="email"] { font-size: 16px !important; height: 48px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-meta { gap: 6px !important; margin-bottom: 18px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label, #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action { font-size: 13px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn, #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn { font-size: 15px !important; min-height: 48px !important; padding: 12px 16px !important; }
        }
    </style>

    <div id="cmp-auth-stage-<?php echo $uid; ?>">
        <div class="cmp-auth-card" id="cmp-auth-card-<?php echo $uid; ?>">
            <div class="cmp-brand-ribbon"></div>
            <div class="cmp-auth-header" id="cmp-auth-header-<?php echo $uid; ?>">
                <div class="cmp-auth-badge" id="cmp-auth-badge-<?php echo $uid; ?>">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                </div>
                <div class="cmp-auth-header-text" id="cmp-auth-htext-<?php echo $uid; ?>">
                    <span class="cmp-auth-kicker" id="cmp-auth-kicker-<?php echo $uid; ?>">The Cycle Bistro</span>
                    <h2 id="cmp-auth-title-<?php echo $uid; ?>"><?php echo esc_html( $cfg['title'] ); ?></h2>
                    <p id="cmp-auth-sub-<?php echo $uid; ?>"><?php echo esc_html( $cfg['subtitle'] ); ?></p>
                </div>
            </div>

            <div class="cmp-auth-body">
                <?php if ( is_user_logged_in() ) : 
                    $curr_user = wp_get_current_user();
                ?>
                    <div class="cmp-auth-alert error" style="display:block !important;">
                        <strong>Access Restricted:</strong> <?php echo esc_html( $cfg['denied_message'] ); ?>
                    </div>
                    <div class="cmp-user-pill">
                        Currently signed in as:<br>
                        <strong style="color:#231F20;"><?php echo esc_html( $curr_user->display_name ); ?></strong>
                        <span style="color:#526352;">(<?php echo esc_html( $curr_user->user_email ); ?>)</span>
                    </div>
                    <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="cmp-submit-btn">
                        Log Out &amp; Switch Account
                    </a>

                <?php else : ?>
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
                                <a href="#forgot-password" class="cmp-link-action" id="cmp-show-forgot-<?php echo $uid; ?>">Forgot Password?</a>
                            </div>

                            <button type="submit" class="cmp-submit-btn" id="cmp-login-btn-<?php echo $uid; ?>">
                                <span class="cmp-spinner" id="cmp-login-spin-<?php echo $uid; ?>"></span>
                                <span id="cmp-login-btn-text-<?php echo $uid; ?>">Sign In</span>
                                <svg id="cmp-login-arrow-<?php echo $uid; ?>" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                        </form>
                    </div>

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

                            <button type="submit" class="cmp-submit-btn" id="cmp-forgot-btn-<?php echo $uid; ?>">
                                <span class="cmp-spinner" id="cmp-forgot-spin-<?php echo $uid; ?>"></span>
                                <span id="cmp-forgot-btn-text-<?php echo $uid; ?>">Send Reset Link</span>
                            </button>
                        </form>

                        <div style="text-align: center !important; margin-top: 18px !important;">
                            <a href="#sign-in" class="cmp-link-action" id="cmp-back-login-<?php echo $uid; ?>">&larr; Back to Sign In</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const uid      = '<?php echo esc_js( $uid ); ?>';
        const headerEl = document.getElementById('cmp-auth-header-' + uid);
        const badgeEl  = document.getElementById('cmp-auth-badge-' + uid);
        const htextEl  = document.getElementById('cmp-auth-htext-' + uid);
        const kickerEl = document.getElementById('cmp-auth-kicker-' + uid);
        const titleEl  = document.getElementById('cmp-auth-title-' + uid);
        const subEl    = document.getElementById('cmp-auth-sub-' + uid);

        function applyResponsiveHeaderTheme() {
            if (!headerEl) return;
            const w = window.innerWidth;
            if (w <= 600) {
                headerEl.style.setProperty('background', 'linear-gradient(145deg, rgba(55, 146, 55, 0.95) 0%, rgba(27, 94, 32, 0.92) 100%)', 'important');
                headerEl.style.setProperty('flex-direction', 'row', 'important');
                headerEl.style.setProperty('text-align', 'left', 'important');
                headerEl.style.setProperty('margin', '12px 12px 0 12px', 'important');
                headerEl.style.setProperty('border-radius', '16px', 'important');
                headerEl.style.setProperty('padding', '16px', 'important');
                if (badgeEl) badgeEl.style.setProperty('background', 'rgba(220, 236, 201, 0.22)', 'important');
                if (htextEl) htextEl.style.setProperty('text-align', 'left', 'important');
                if (titleEl) titleEl.style.setProperty('color', '#ffffff', 'important');
                if (subEl) subEl.style.setProperty('color', '#DCECC9', 'important');
                if (kickerEl) {
                    kickerEl.style.setProperty('background', 'rgba(220, 236, 201, 0.24)', 'important');
                    kickerEl.style.setProperty('color', '#DCECC9', 'important');
                }
            } else if (w <= 991) {
                headerEl.style.setProperty('background', 'linear-gradient(125deg, rgba(220, 236, 201, 0.92) 0%, rgba(104, 185, 70, 0.42) 100%)', 'important');
                headerEl.style.setProperty('flex-direction', 'row', 'important');
                headerEl.style.setProperty('text-align', 'left', 'important');
                headerEl.style.setProperty('margin', '0', 'important');
                headerEl.style.setProperty('border-radius', '0', 'important');
                headerEl.style.setProperty('padding', '24px 26px', 'important');
                if (badgeEl) badgeEl.style.setProperty('background', 'linear-gradient(135deg, #379237 0%, #1B5E20 100%)', 'important');
                if (htextEl) htextEl.style.setProperty('text-align', 'left', 'important');
                if (titleEl) titleEl.style.setProperty('color', '#231F20', 'important');
                if (subEl) subEl.style.setProperty('color', '#1B5E20', 'important');
                if (kickerEl) {
                    kickerEl.style.setProperty('background', 'rgba(27, 94, 32, 0.14)', 'important');
                    kickerEl.style.setProperty('color', '#1B5E20', 'important');
                }
            } else {
                headerEl.style.setProperty('background', 'linear-gradient(135deg, rgba(27, 94, 32, 0.92) 0%, rgba(55, 146, 55, 0.86) 55%, rgba(104, 185, 70, 0.82) 100%)', 'important');
                headerEl.style.setProperty('flex-direction', 'column', 'important');
                headerEl.style.setProperty('text-align', 'center', 'important');
                headerEl.style.setProperty('margin', '0', 'important');
                headerEl.style.setProperty('border-radius', '0', 'important');
                headerEl.style.setProperty('padding', '28px 28px 24px 28px', 'important');
                if (badgeEl) badgeEl.style.setProperty('background', 'rgba(255, 255, 255, 0.20)', 'important');
                if (htextEl) htextEl.style.setProperty('text-align', 'center', 'important');
                if (titleEl) titleEl.style.setProperty('color', '#ffffff', 'important');
                if (subEl) subEl.style.setProperty('color', '#EEF7E3', 'important');
                if (kickerEl) {
                    kickerEl.style.setProperty('background', 'rgba(220, 236, 201, 0.25)', 'important');
                    kickerEl.style.setProperty('color', '#EEF7E3', 'important');
                }
            }
        }

        applyResponsiveHeaderTheme();
        window.addEventListener('resize', applyResponsiveHeaderTheme);

        <?php if ( ! is_user_logged_in() ) : ?>
        const ajaxUrl     = '<?php echo $ajax_url; ?>';
        const nonce       = '<?php echo esc_js( $nonce ); ?>';
        const reqCaps     = '<?php echo esc_js( $caps_csv ); ?>';
        const pTitle      = '<?php echo esc_js( $cfg['title'] ); ?>';
        const reloadUrl   = '<?php echo $current_url; ?>';

        const card        = document.getElementById('cmp-auth-card-' + uid);
        const loginView   = document.getElementById('cmp-login-view-' + uid);
        const forgotView  = document.getElementById('cmp-forgot-view-' + uid);
        const loginAlert  = document.getElementById('cmp-login-alert-' + uid);
        const forgotAlert = document.getElementById('cmp-forgot-alert-' + uid);

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

        const passInput = document.getElementById('cmp-pass-' + uid);
        const pwdToggle = document.getElementById('cmp-pwd-toggle-' + uid);
        const eyeOpen   = document.getElementById('cmp-eye-open-' + uid);
        const eyeClosed = document.getElementById('cmp-eye-closed-' + uid);

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
        <?php endif; ?>
    })();
    </script>
    <?php
    return ob_get_clean();
}


// ==========================================
// 7. WP ADMIN DASHBOARD: MEAL PORTAL > CUSTOMER LOGS (OPTION 4B)
// ==========================================
add_action( 'admin_menu', 'cmp_register_customer_logs_submenu', 55 );
function cmp_register_customer_logs_submenu() {
    add_submenu_page(
        'cmp-menu-manager',
        'Customer Activity Logs',
        'Customer Logs',
        'read',
        'cmp-customer-logs',
        'cmp_render_customer_logs_admin_page'
    );
}

/**
 * Helper to build WHERE clauses and parameters for Customer Logs filtering & CSV export
 */
function cmp_build_customer_logs_query_filter() {
    global $wpdb;
    $where  = array( '1=1' );
    $params = array();

    $search      = isset( $_GET['s_log'] ) ? sanitize_text_field( wp_unslash( $_GET['s_log'] ) ) : '';
    $action_type = isset( $_GET['f_action'] ) ? sanitize_text_field( wp_unslash( $_GET['f_action'] ) ) : '';
    $actor_role  = isset( $_GET['f_role'] ) ? sanitize_text_field( wp_unslash( $_GET['f_role'] ) ) : '';
    $date_from   = isset( $_GET['f_from'] ) ? sanitize_text_field( wp_unslash( $_GET['f_from'] ) ) : '';
    $date_to     = isset( $_GET['f_to'] ) ? sanitize_text_field( wp_unslash( $_GET['f_to'] ) ) : '';

    if ( $search !== '' ) {
        $like     = '%' . $wpdb->esc_like( $search ) . '%';
        $clean_id = preg_replace( '/[^0-9\-]/', '', $search );
        if ( $clean_id !== '' && is_numeric( $clean_id ) ) {
            $where[]  = '(customer_name LIKE %s OR plan_name LIKE %s OR actor_name LIKE %s OR old_status LIKE %s OR new_status LIKE %s OR wc_order_id = %d OR subscription_id = %d)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = intval( $clean_id );
            $params[] = absint( $clean_id );
        } else {
            $where[]  = '(customer_name LIKE %s OR plan_name LIKE %s OR actor_name LIKE %s OR old_status LIKE %s OR new_status LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
    }

    if ( $action_type !== '' ) {
        $where[]  = 'action_type = %s';
        $params[] = $action_type;
    }

    if ( $actor_role !== '' ) {
        $where[]  = 'actor_role = %s';
        $params[] = $actor_role;
    }

    if ( $date_from !== '' ) {
        $where[]  = 'DATE(created_at) >= %s';
        $params[] = $date_from;
    }

    if ( $date_to !== '' ) {
        $where[]  = 'DATE(created_at) <= %s';
        $params[] = $date_to;
    }

    return array(
        'where_sql' => implode( ' AND ', $where ),
        'params'    => $params,
    );
}

/**
 * CSV Exporter for Customer Logs (Option 4B)
 */
add_action( 'admin_init', 'cmp_export_customer_activity_logs_csv' );
function cmp_export_customer_activity_logs_csv() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'cmp-customer-logs' || ! isset( $_GET['cmp_export_activity_logs'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'accounts_team' ) ) {
        wp_die( 'Unauthorized Access' );
    }

    check_admin_referer( 'cmp_customer_logs_export', 'export_nonce' );

    global $wpdb;
    $table_activity = $wpdb->prefix . 'cmp_activity_logs';
    $filter         = cmp_build_customer_logs_query_filter();
    $sql            = "SELECT * FROM {$table_activity} WHERE {$filter['where_sql']} ORDER BY created_at DESC, id DESC";
    $rows           = ! empty( $filter['params'] ) ? $wpdb->get_results( $wpdb->prepare( $sql, $filter['params'] ) ) : $wpdb->get_results( $sql );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="Customer_Activity_Logs_' . date( 'Y-m-d_His' ) . '.csv"' );
    $output = fopen( 'php://output', 'w' );
    fputs( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

    fputcsv( $output, array(
        'Log ID',
        'Date & Time (GST)',
        'Order ID',
        'Subscription ID',
        'Customer Name',
        'Plan Name',
        'Target Date',
        'Action Type',
        'Old Status (Before Update)',
        'Current Status (After Update)',
        'Updated By',
        'Role',
    ) );

    if ( $rows ) {
        foreach ( $rows as $r ) {
            $order_disp = ( intval( $r->wc_order_id ) < 0 ) ? 'Manual #' . absint( $r->wc_order_id ) : ( intval( $r->wc_order_id ) > 0 ? '#' . $r->wc_order_id : 'Manual' );
            fputcsv( $output, array(
                $r->id,
                $r->created_at,
                $order_disp,
                $r->subscription_id,
                $r->customer_name,
                $r->plan_name,
                $r->target_date ?: '-',
                $r->action_type,
                wp_strip_all_tags( $r->old_status ),
                wp_strip_all_tags( $r->new_status ),
                $r->actor_name,
                $r->actor_role,
            ) );
        }
    }

    fclose( $output );
    exit;
}

/**
 * Renders the Meal Portal > Customer Logs Admin Page (Option 4B)
 */
function cmp_render_customer_logs_admin_page() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'accounts_team' ) ) {
        wp_die( 'Access Denied: You do not have permission to view Customer Logs.' );
    }

    cmp_auto_patch_database();

    global $wpdb;
    $table_activity = $wpdb->prefix . 'cmp_activity_logs';
    $notice         = '';

    // 1. Handle Single Row Delete
    if ( isset( $_GET['delete_log_id'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'cmp_del_single_log_' . intval( $_GET['delete_log_id'] ) ) ) {
        $del_id = intval( $_GET['delete_log_id'] );
        $wpdb->delete( $table_activity, array( 'id' => $del_id ), array( '%d' ) );
        $notice = '<div class="notice notice-success is-dismissible"><p>Log entry #' . esc_html( $del_id ) . ' deleted successfully.</p></div>';
    }

    // 2. Handle Bulk Delete Selected Logs
    if ( isset( $_POST['cmp_bulk_delete_logs'] ) && check_admin_referer( 'cmp_customer_logs_bulk_action', 'cmp_logs_bulk_nonce' ) ) {
        if ( ! empty( $_POST['log_ids'] ) && is_array( $_POST['log_ids'] ) ) {
            $ids_clean = array_filter( array_map( 'intval', $_POST['log_ids'] ) );
            if ( ! empty( $ids_clean ) ) {
                $ids_sql = implode( ',', $ids_clean );
                $deleted = $wpdb->query( "DELETE FROM {$table_activity} WHERE id IN ({$ids_sql})" );
                $notice  = '<div class="notice notice-success is-dismissible"><p>Deleted <strong>' . intval( $deleted ) . '</strong> selected log entries.</p></div>';
            }
        } else {
            $notice = '<div class="notice notice-warning is-dismissible"><p>Please select at least one log entry to delete.</p></div>';
        }
    }

    // 3. Handle Clear All Logs / Purge Older Logs
    if ( isset( $_POST['cmp_clear_all_logs'] ) && check_admin_referer( 'cmp_customer_logs_clear_action', 'cmp_logs_clear_nonce' ) ) {
        if ( current_user_can( 'manage_options' ) ) {
            $wpdb->query( "TRUNCATE TABLE {$table_activity}" );
            $notice = '<div class="notice notice-success is-dismissible"><p><strong>All customer activity logs have been cleared.</strong></p></div>';
        } else {
            $notice = '<div class="notice notice-error is-dismissible"><p>Only Administrators can clear the entire log history.</p></div>';
        }
    }

    // Build Filters & Pagination
    $filter     = cmp_build_customer_logs_query_filter();
    $per_page   = 50;
    $paged      = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $offset     = ( $paged - 1 ) * $per_page;

    $count_sql  = "SELECT COUNT(*) FROM {$table_activity} WHERE {$filter['where_sql']}";
    $total_rows = ! empty( $filter['params'] ) ? intval( $wpdb->get_var( $wpdb->prepare( $count_sql, $filter['params'] ) ) ) : intval( $wpdb->get_var( $count_sql ) );
    $total_pages = max( 1, ceil( $total_rows / $per_page ) );

    $data_sql   = "SELECT * FROM {$table_activity} WHERE {$filter['where_sql']} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
    $query_args = array_merge( $filter['params'], array( $per_page, $offset ) );
    $logs       = $wpdb->get_results( $wpdb->prepare( $data_sql, $query_args ) );

    // Fetch distinct Action Types for filter dropdown
    $distinct_actions = $wpdb->get_col( "SELECT DISTINCT action_type FROM {$table_activity} WHERE action_type != '' ORDER BY action_type ASC" );

    $search_val  = isset( $_GET['s_log'] ) ? sanitize_text_field( wp_unslash( $_GET['s_log'] ) ) : '';
    $action_val  = isset( $_GET['f_action'] ) ? sanitize_text_field( wp_unslash( $_GET['f_action'] ) ) : '';
    $role_val    = isset( $_GET['f_role'] ) ? sanitize_text_field( wp_unslash( $_GET['f_role'] ) ) : '';
    $from_val    = isset( $_GET['f_from'] ) ? sanitize_text_field( wp_unslash( $_GET['f_from'] ) ) : '';
    $to_val      = isset( $_GET['f_to'] ) ? sanitize_text_field( wp_unslash( $_GET['f_to'] ) ) : '';

    $export_url = wp_nonce_url(
        add_query_arg( array( 'cmp_export_activity_logs' => '1' ) ),
        'cmp_customer_logs_export',
        'export_nonce'
    );
    ?>
    <style>
        .cmp-logs-wrap { max-width: 1450px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .cmp-logs-header { background: linear-gradient(135deg, #1B5E20 0%, #379237 100%); color: #fff; padding: 22px 28px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(27,94,32,0.15); }
        .cmp-logs-header h1 { color: #fff; margin: 0; font-size: 1.6rem; font-weight: 800; }
        .cmp-logs-header p { color: #DCECC9; margin: 4px 0 0 0; font-size: 0.92rem; }
        .cmp-logs-filter-bar { background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .cmp-filter-group { display: flex; flex-direction: column; gap: 5px; }
        .cmp-filter-group label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.4px; }
        .cmp-filter-group input, .cmp-filter-group select { height: 38px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; min-width: 150px; }
        .cmp-logs-table-card { background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
        .cmp-logs-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .cmp-logs-table th { background: #f8fafc; color: #1e293b; font-weight: 700; text-align: left; padding: 13px 14px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
        .cmp-logs-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: top; color: #334155; line-height: 1.45; }
        .cmp-logs-table tr:hover td { background: #f8fafc; }
        .cmp-action-badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
        .cmp-action-select { background: #dcfce7; color: #166534; }
        .cmp-action-update { background: #dbeafe; color: #1e40af; }
        .cmp-action-cancel { background: #fee2e2; color: #991b1b; }
        .cmp-action-reset  { background: #fef3c7; color: #92400e; }
        .cmp-action-plan   { background: #f3e8ff; color: #6b21a8; }
        .cmp-role-pill { display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 700; background: #e2e8f0; color: #334155; margin-top: 3px; }
        .cmp-status-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 7px 10px; font-size: 12.5px; color: #1e293b; max-width: 340px; word-break: break-word; }
        .cmp-status-box.old { border-left: 3px solid #94a3b8; color: #64748b; }
        .cmp-status-box.new { border-left: 3px solid #379237; background: #f4fdf4; }
    </style>

    <div class="wrap cmp-logs-wrap">
        <?php echo $notice; ?>

        <div class="cmp-logs-header">
            <div>
                <h1>Customer Activity &amp; Audit Logs</h1>
                <p>Complete history of meal selections, edits, cancellations, admin resets, and plan status updates.</p>
            </div>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <a href="<?php echo esc_url( $export_url ); ?>" class="button" style="background:#ffffff; color:#1B5E20; border:none; font-weight:700; padding:6px 16px; height:auto; border-radius:6px;">
                    &#128190; Export Filtered Logs to CSV
                </a>
                <?php if ( current_user_can( 'manage_options' ) && $total_rows > 0 ) : ?>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to permanently clear ALL customer activity logs?');">
                    <?php wp_nonce_field( 'cmp_customer_logs_clear_action', 'cmp_logs_clear_nonce' ); ?>
                    <button type="submit" name="cmp_clear_all_logs" class="button" style="background:#dc2626; color:#fff; border:none; font-weight:700; padding:6px 14px; height:auto; border-radius:6px; cursor:pointer;">
                        Clear All Logs
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- SEARCH & FILTER BAR (OPTION 4B) -->
        <form method="GET" action="" class="cmp-logs-filter-bar">
            <input type="hidden" name="page" value="cmp-customer-logs">
            
            <div class="cmp-filter-group" style="flex: 2; min-width: 240px;">
                <label>Search Order ID, Customer, Recipient, or Meal</label>
                <input type="text" name="s_log" value="<?php echo esc_attr( $search_val ); ?>" placeholder="e.g. #1045, John Doe, Sarah, Acai Bowl...">
            </div>

            <div class="cmp-filter-group">
                <label>Action Type</label>
                <select name="f_action">
                    <option value="">All Actions</option>
                    <?php foreach ( $distinct_actions as $act ) : ?>
                        <option value="<?php echo esc_attr( $act ); ?>" <?php selected( $action_val, $act ); ?>><?php echo esc_html( $act ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cmp-filter-group">
                <label>Updated By Role</label>
                <select name="f_role">
                    <option value="">All Roles</option>
                    <option value="Customer" <?php selected( $role_val, 'Customer' ); ?>>Customer</option>
                    <option value="FOH" <?php selected( $role_val, 'FOH' ); ?>>FOH</option>
                    <option value="Kitchen" <?php selected( $role_val, 'Kitchen' ); ?>>Kitchen</option>
                    <option value="Menu Manager" <?php selected( $role_val, 'Menu Manager' ); ?>>Menu Manager</option>
                    <option value="Admin" <?php selected( $role_val, 'Admin' ); ?>>Admin</option>
                </select>
            </div>

            <div class="cmp-filter-group">
                <label>From Date</label>
                <input type="date" name="f_from" value="<?php echo esc_attr( $from_val ); ?>">
            </div>

            <div class="cmp-filter-group">
                <label>To Date</label>
                <input type="date" name="f_to" value="<?php echo esc_attr( $to_val ); ?>">
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="button button-primary" style="background:#379237; border-color:#379237; height:38px; padding:0 18px; font-weight:700; border-radius:6px;">Filter</button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmp-customer-logs' ) ); ?>" class="button" style="height:38px; line-height:36px; padding:0 14px; border-radius:6px;">Reset</a>
            </div>
        </form>

        <!-- BULK DELETE & LOGS TABLE -->
        <form method="POST" action="">
            <?php wp_nonce_field( 'cmp_customer_logs_bulk_action', 'cmp_logs_bulk_nonce' ); ?>
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <button type="submit" name="cmp_bulk_delete_logs" class="button" style="color:#dc2626; border-color:#fca5a5; background:#fff1f2; font-weight:600;" onclick="return confirm('Delete all selected log entries?');">
                        &#128465; Delete Selected Logs
                    </button>
                    <span style="color:#64748b; font-size:13px;">Showing <strong><?php echo intval( count( $logs ) ); ?></strong> of <strong><?php echo intval( $total_rows ); ?></strong> total logs</span>
                </div>

                <?php if ( $total_pages > 1 ) : ?>
                <div style="display:flex; gap:6px; align-items:center;">
                    <?php
                    for ( $p = 1; $p <= $total_pages; $p++ ) {
                        $page_url = add_query_arg( 'paged', $p );
                        $active_s = ( $p === $paged ) ? 'background:#379237; color:#fff; border-color:#379237; font-weight:700;' : '';
                        echo '<a href="' . esc_url( $page_url ) . '" class="button" style="' . esc_attr( $active_s ) . '">' . intval( $p ) . '</a>';
                    }
                    ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="cmp-logs-table-card">
                <table class="cmp-logs-table">
                    <thead>
                        <tr>
                            <th style="width:32px;"><input type="checkbox" id="cmp-select-all-logs"></th>
                            <th style="width:145px;">Date &amp; Time (GST)</th>
                            <th style="width:95px;">Order ID</th>
                            <th style="width:200px;">Customer Name</th>
                            <th style="width:145px;">Action</th>
                            <th>Old Status (Before Update)</th>
                            <th>Current Status (After Update)</th>
                            <th style="width:150px;">Updated By</th>
                            <th style="width:75px; text-align:center;">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $logs ) ) : ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding:40px; color:#64748b; font-size:14px;">
                                    No customer activity logs found matching your filter criteria.
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $logs as $log ) :
                                $order_badge = ( intval( $log->wc_order_id ) < 0 )
                                    ? 'Manual #' . absint( $log->wc_order_id )
                                    : ( intval( $log->wc_order_id ) > 0 ? '#' . intval( $log->wc_order_id ) : 'Manual' );

                                $act_lower   = strtolower( $log->action_type );
                                $badge_class = 'cmp-action-update';
                                if ( strpos( $act_lower, 'select' ) !== false ) {
                                    $badge_class = 'cmp-action-select';
                                } elseif ( strpos( $act_lower, 'cancel' ) !== false && strpos( $act_lower, 'reset' ) === false ) {
                                    $badge_class = 'cmp-action-cancel';
                                } elseif ( strpos( $act_lower, 'reset' ) !== false ) {
                                    $badge_class = 'cmp-action-reset';
                                } elseif ( strpos( $act_lower, 'pause' ) !== false || strpos( $act_lower, 'resume' ) !== false || strpos( $act_lower, 'stop' ) !== false ) {
                                    $badge_class = 'cmp-action-plan';
                                }

                                $del_url = wp_nonce_url(
                                    add_query_arg( array( 'delete_log_id' => $log->id ) ),
                                    'cmp_del_single_log_' . $log->id
                                );
                            ?>
                            <tr>
                                <td><input type="checkbox" name="log_ids[]" value="<?php echo esc_attr( $log->id ); ?>" class="cmp-log-row-cb"></td>
                                <td style="white-space:nowrap; font-size:12.5px; color:#475569;">
                                    <strong><?php echo esc_html( date( 'd M Y', strtotime( $log->created_at ) ) ); ?></strong><br>
                                    <?php echo esc_html( date( 'h:i A', strtotime( $log->created_at ) ) ); ?>
                                </td>
                                <td>
                                    <span style="background:#e2e8f0; color:#1e293b; padding:3px 8px; border-radius:4px; font-weight:700; font-size:12px;">
                                        <?php echo esc_html( $order_badge ); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color:#0f172a; font-size:13.5px;"><?php echo esc_html( $log->customer_name ); ?></strong><br>
                                    <span style="font-size:12px; color:#64748b;"><?php echo esc_html( $log->plan_name ); ?></span>
                                </td>
                                <td>
                                    <span class="cmp-action-badge <?php echo esc_attr( $badge_class ); ?>">
                                        <?php echo esc_html( $log->action_type ); ?>
                                    </span>
                                    <?php if ( ! empty( $log->target_date ) ) : ?>
                                        <div style="font-size:11.5px; color:#64748b; margin-top:4px; font-weight:600;">
                                            Date: <?php echo esc_html( $log->target_date ); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="cmp-status-box old">
                                        <?php echo ! empty( $log->old_status ) ? esc_html( $log->old_status ) : '<em>None</em>'; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="cmp-status-box new">
                                        <?php echo ! empty( $log->new_status ) ? esc_html( $log->new_status ) : '<em>None</em>'; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong style="color:#0f172a;"><?php echo esc_html( $log->actor_name ); ?></strong><br>
                                    <span class="cmp-role-pill"><?php echo esc_html( $log->actor_role ); ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <a href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('Delete this log entry?');" style="color:#dc2626; text-decoration:none; font-weight:700; font-size:12px; padding:4px 8px; border:1px solid #fecaca; border-radius:4px; background:#fff1f2; display:inline-block;">
                                        Delete
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectAll = document.getElementById('cmp-select-all-logs');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                document.querySelectorAll('.cmp-log-row-cb').forEach(cb => {
                    cb.checked = selectAll.checked;
                });
            });
        }
    });
    </script>
    <?php
}
