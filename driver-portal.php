<?php
/**
 * Driver Portal & Mobile PWA Fulfillment Engine
 * Shortcode: [meal_driver_portal]
 *
 * Implements Phase 2 of the Driver Portal SOP Blueprint:
 *  - Option 1A / 1B: Kitchen Batch Date Sync + Shift Tabs vs. Strict Physical Delivery Date
 *  - Option 2A / 2B: Large 1-Tap Mobile Action Buttons vs. Touch-Friendly Status Dropdown
 *  - Option 4A / 4B: Quick Issue/Return Reason Modal with Preset Tags vs. Immediate Status Update
 *  - Option 5A / 5B: Standalone Fullscreen PWA App Shell (<35KB) vs. Standard WP Page Wrapper
 *  - Option 6A / 6B: Offline "Store & Forward" Queue (localStorage + Batch Sync) vs. Online-Only Mode
 *  - Option 7A / 7B: Ultra-Lite Flat UI + ~2KB Delta JSON Sync + Rolling Cache Auto-Purge (<100KB)
 *  - Option 8A / 8B: High-Contrast Daylight Theme + 1-Tap Night/Day Shift Header Toggle
 *  - Option 9A / 9B: Driver Personal "View & Data" Settings Drawer vs. Strict Admin Lock
 *  - Option 10A/B/C: Smart Auto-Adaptive (navigator.connection & deviceMemory), Lite, or Rich Default
 *  - Strict 3-Stage Dispatch Handshake: Kitchen Dispatch -> Driver Delivery Status -> FOH POS Lock
 *  - Zero Thermal Bag Deposit Exposure
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// Load shared per-plan logistics helpers from customer-portal-backend.php if available
if ( file_exists( __DIR__ . '/customer-portal-backend.php' ) ) {
    require_once __DIR__ . '/customer-portal-backend.php';
}

// ==========================================
// 0. FALLBACK HELPERS (STANDALONE SAFETY)
// ==========================================
if ( ! function_exists( 'cmp_get_driver_portal_settings' ) ) {
    function cmp_get_driver_portal_settings() {
        $presets_raw = get_option(
            'cmp_driver_reason_presets',
            'Customer Unreachable, Gate/Security Access Issue, Wrong Address, Customer Requested Hold'
        );
        $presets_arr = array_values( array_filter( array_map( 'trim', explode( ',', (string) $presets_raw ) ) ) );
        if ( empty( $presets_arr ) ) {
            $presets_arr = array( 'Customer Unreachable', 'Gate/Security Access Issue', 'Wrong Address', 'Customer Requested Hold' );
        }

        return array(
            'date_mode'      => get_option( 'cmp_driver_date_mode', '1A' ),
            'action_ui'      => get_option( 'cmp_driver_action_ui', '2A' ),
            'kitchen_attr'   => get_option( 'cmp_driver_kitchen_attr', '3A' ),
            'issue_flow'     => get_option( 'cmp_driver_issue_flow', '4A' ),
            'shell_mode'     => get_option( 'cmp_driver_shell_mode', '5A' ),
            'offline_mode'   => get_option( 'cmp_driver_offline_mode', '6A' ),
            'datasaver'      => get_option( 'cmp_driver_datasaver', '7A' ),
            'theme_mode'     => get_option( 'cmp_driver_theme_mode', '8A' ),
            'allow_custom'   => get_option( 'cmp_driver_allow_custom', '9A' ),
            'default_prof'   => get_option( 'cmp_driver_default_prof', '10A' ),
            'map_app'        => get_option( 'cmp_driver_map_app', 'both' ),
            'show_pickups'   => get_option( 'cmp_driver_show_pickups', '1' ),
            'reason_presets' => $presets_raw,
            'presets_array'  => $presets_arr,
        );
    }
}

/**
 * Checks if the current user has clearance to access the Driver Portal.
 */
function cmp_user_has_driver_portal_access() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    return current_user_can( 'manage_options' )
        || current_user_can( 'delivery_driver' )
        || current_user_can( 'kitchen_staff' )
        || current_user_can( 'foh_manager' )
        || current_user_can( 'menu_manager' );
}

/**
 * Retrieves the logged-in driver's personal UI preferences merged with Admin defaults.
 */
function cmp_get_driver_user_ui_prefs( $user_id = 0 ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    $cfg = cmp_get_driver_portal_settings();

    $default_profile = 'auto';
    if ( $cfg['default_prof'] === '10B' || $cfg['datasaver'] === '7A' ) {
        $default_profile = 'lite';
    } elseif ( $cfg['default_prof'] === '10C' ) {
        $default_profile = 'rich';
    }
    if ( $cfg['default_prof'] === '10A' ) {
        $default_profile = 'auto';
    }

    $defaults = array(
        'profile'   => $default_profile, // 'lite', 'rich', or 'auto'
        'density'   => ( $default_profile === 'rich' ) ? 'expanded' : 'compact',
        'action_ui' => $cfg['action_ui'], // '2A' or '2B'
        'theme'     => 'daylight',        // 'daylight', 'night', or 'glass'
        'auto_sync' => ( $default_profile === 'rich' ) ? 1 : 0,
        'map_app'   => $cfg['map_app'],   // 'both', 'google', or 'waze'
    );

    // If Option 9B (Strict Admin Lock) is active, always enforce global Admin settings
    if ( $cfg['allow_custom'] === '9B' || $user_id <= 0 ) {
        return $defaults;
    }

    $saved = get_user_meta( $user_id, '_cmp_driver_ui_prefs', true );
    if ( ! is_array( $saved ) ) {
        return $defaults;
    }

    return array(
        'profile'   => in_array( $saved['profile'] ?? '', array( 'lite', 'rich', 'auto' ), true ) ? $saved['profile'] : $defaults['profile'],
        'density'   => in_array( $saved['density'] ?? '', array( 'compact', 'expanded' ), true ) ? $saved['density'] : $defaults['density'],
        'action_ui' => in_array( $saved['action_ui'] ?? '', array( '2A', '2B' ), true ) ? $saved['action_ui'] : $defaults['action_ui'],
        'theme'     => in_array( $saved['theme'] ?? '', array( 'daylight', 'night', 'glass' ), true ) ? $saved['theme'] : $defaults['theme'],
        'auto_sync' => isset( $saved['auto_sync'] ) ? intval( $saved['auto_sync'] ) : $defaults['auto_sync'],
        'map_app'   => in_array( $saved['map_app'] ?? '', array( 'both', 'google', 'waze' ), true ) ? $saved['map_app'] : $defaults['map_app'],
    );
}

// ==========================================
// 1. MANIFEST DATA BUILDER (OPTION 1A & 1B)
// ==========================================

/**
 * Fetches and formats all stops for the selected date according to Option 1A or Option 1B.
 * Excludes paused subscriptions, expired plans, and Thermal Bag Deposit data.
 */
function cmp_get_driver_manifest_stops( $selected_date, $cfg = null ) {
    global $wpdb;
    date_default_timezone_set( 'Asia/Dubai' );

    if ( ! $cfg ) {
        $cfg = cmp_get_driver_portal_settings();
    }

    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $table_foods = $wpdb->prefix . 'cmp_foods';

    $date_mode    = $cfg['date_mode']; // '1A' or '1B'
    $show_pickups = ( $cfg['show_pickups'] === '1' );

    if ( $date_mode === '1B' ) {
        // Option 1B: Strict Physical Calendar Delivery Date
        // Pulls logs where Eating Date is either $selected_date (for Same-Day Morning deliveries)
        // OR $selected_date + 1 day (for Day-Before Evening deliveries).
        $eat_date_same_day   = $selected_date;
        $eat_date_day_before = date( 'Y-m-d', strtotime( $selected_date . ' + 1 day' ) );

        $logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, s.foh_remarks, s.expiry_date, s.is_flexible, s.allow_custom,
                    s.delivery_method, s.pickup_location, s.delivery_timing, s.time_slot, s.allergies, s.delivery_address,
                    u.display_name, u.user_email
             FROM {$table_logs} l
             INNER JOIN {$table_subs} s ON l.subscription_id = s.id
             INNER JOIN {$wpdb->prefix}users u ON l.user_id = u.ID
             WHERE l.target_date IN (%s, %s)
               AND l.is_locked = 1
               AND s.status = 'active'
             ORDER BY l.id ASC",
            $eat_date_same_day,
            $eat_date_day_before
        ) );
    } else {
        // Option 1A (Default): Synced with Kitchen Prep Batch Date (target_date = prep_date + 1 day)
        $target_date = date( 'Y-m-d', strtotime( $selected_date . ' + 1 day' ) );

        $logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, s.foh_remarks, s.expiry_date, s.is_flexible, s.allow_custom,
                    s.delivery_method, s.pickup_location, s.delivery_timing, s.time_slot, s.allergies, s.delivery_address,
                    u.display_name, u.user_email
             FROM {$table_logs} l
             INNER JOIN {$table_subs} s ON l.subscription_id = s.id
             INNER JOIN {$wpdb->prefix}users u ON l.user_id = u.ID
             WHERE l.target_date = %s
               AND l.is_locked = 1
               AND s.status = 'active'
             ORDER BY l.id ASC",
            $target_date
        ) );
    }

    if ( empty( $logs ) ) {
        return array();
    }

    // Build food lookup map
    $foods    = $wpdb->get_results( "SELECT id, food_name FROM {$table_foods}" );
    $food_map = array();
    if ( $foods ) {
        foreach ( $foods as $f ) {
            $food_map[ intval( $f->id ) ] = $f->food_name;
        }
    }

    $stops = array();

    foreach ( $logs as $log ) {
        // Skip expired plans
        $target_ymd = date( 'Y-m-d', strtotime( $log->target_date ) );
        $expiry_ymd = date( 'Y-m-d', strtotime( $log->expiry_date ) );
        if ( $target_ymd > $expiry_ymd ) {
            continue;
        }

        // Resolve per-plan logistics (Subscription Row -> WC Order Meta -> User Meta)
        $resolved = function_exists( 'cmp_get_subscription_logistics' )
            ? cmp_get_subscription_logistics( $log )
            : array(
                'full_name' => $log->display_name ?: 'Customer',
                'phone'     => get_user_meta( $log->user_id, 'billing_phone', true ),
                'email'     => $log->user_email,
                'address'   => $log->delivery_address ?: 'Address not provided',
                'method'    => $log->delivery_method ?: 'Delivery',
                'pickup'    => $log->pickup_location ?: '',
                'timing'    => $log->delivery_timing ?: 'Deliver Same Day',
                'time_slot' => $log->time_slot ?: '8:00 AM to 9:00 AM',
                'allergies' => ( $log->allergies && $log->allergies !== '__NONE__' ) ? $log->allergies : '',
            );

        $timing_raw    = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'Same Day';
        $timing_short  = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing_raw );
        $is_day_before = ( stripos( $timing_short, 'Day Before' ) !== false );

        // Calculate actual physical delivery date for this log
        $prep_for_log  = date( 'Y-m-d', strtotime( $log->target_date . ' - 1 day' ) );
        $delivery_date = $is_day_before ? $prep_for_log : $log->target_date;

        // If Option 1B (Strict Calendar Delivery Date), only keep rows whose physical delivery_date matches $selected_date
        if ( $date_mode === '1B' && $delivery_date !== $selected_date ) {
            continue;
        }

        $method_raw = ! empty( $resolved['method'] ) ? $resolved['method'] : 'Delivery';
        $is_pickup  = ( strcasecmp( $method_raw, 'Pickup' ) === 0 );

        // If Admin hid Store Pick-ups completely, skip pickup rows
        if ( $is_pickup && ! $show_pickups ) {
            continue;
        }

        $pickup_branch  = $resolved['pickup'] ?? '';
        $method_display = ( $is_pickup && ! empty( $pickup_branch ) ) ? 'Pickup (' . $pickup_branch . ')' : $method_raw;

        // Extract Recipient Name (Option 1A style without "For:")
        $plan_parts          = explode( ' - ', $log->plan_name );
        $potential_recipient = end( $plan_parts );
        $recipient_name      = '';
        if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', $potential_recipient ) && count( $plan_parts ) > 1 ) {
            $recipient_name = trim( $potential_recipient );
            array_pop( $plan_parts );
            $core_plan_name = implode( ' - ', $plan_parts );
        } else {
            $core_plan_name = $log->plan_name;
        }

        // Format Order ID
        if ( intval( $log->wc_order_id ) < 0 ) {
            $display_order_id = 'M-' . str_pad( abs( intval( $log->wc_order_id ) ), 4, '0', STR_PAD_LEFT );
        } elseif ( intval( $log->wc_order_id ) > 0 ) {
            $display_order_id = '#' . intval( $log->wc_order_id );
        } else {
            $display_order_id = 'M-' . str_pad( intval( $log->subscription_id ), 4, '0', STR_PAD_LEFT );
        }

        // Build Meal Checklist for Bag Verification
        $log_mode    = ! empty( $log->plan_mode ) ? $log->plan_mode : ( ! empty( $log->allow_custom ) ? 'custom' : ( ! empty( $log->is_flexible ) ? 'flexible' : 'standard' ) );
        $is_flexible = ( $log_mode === 'flexible' );
        $l_bf        = $is_flexible ? 'Meal 1' : 'Breakfast';
        $l_lu        = $is_flexible ? 'Meal 2' : 'Lunch';
        $l_di        = $is_flexible ? 'Meal 3' : 'Dinner';

        $meals_list  = array();
        $is_assigned = ( $log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1 );

        if ( $log->is_chefs_choice && ! $is_assigned ) {
            $meals_list[] = "Chef's Choice (Pending Kitchen Assignment)";
        } elseif ( $log->juice_1_id || $log->custom_juice_1 ) {
            if ( ! empty( $log->custom_juice_1 ) ) $meals_list[] = 'Juice 1: ' . $log->custom_juice_1;
            elseif ( $log->juice_1_id )            $meals_list[] = 'Juice 1: ' . ( $food_map[ intval( $log->juice_1_id ) ] ?? 'Juice' );

            if ( ! empty( $log->custom_juice_2 ) ) $meals_list[] = 'Juice 2: ' . $log->custom_juice_2;
            elseif ( $log->juice_2_id )            $meals_list[] = 'Juice 2: ' . ( $food_map[ intval( $log->juice_2_id ) ] ?? 'Juice' );

            if ( ! empty( $log->custom_juice_3 ) ) $meals_list[] = 'Juice 3: ' . $log->custom_juice_3;
            elseif ( $log->juice_3_id )            $meals_list[] = 'Juice 3: ' . ( $food_map[ intval( $log->juice_3_id ) ] ?? 'Juice' );
        } else {
            if ( ! empty( $log->custom_breakfast ) ) $meals_list[] = $l_bf . ': ' . $log->custom_breakfast;
            elseif ( $log->breakfast_id )            $meals_list[] = $l_bf . ': ' . ( $food_map[ intval( $log->breakfast_id ) ] ?? 'Meal' );

            if ( ! empty( $log->custom_lunch ) )     $meals_list[] = $l_lu . ': ' . $log->custom_lunch;
            elseif ( $log->lunch_id )                $meals_list[] = $l_lu . ': ' . ( $food_map[ intval( $log->lunch_id ) ] ?? 'Meal' );

            if ( ! empty( $log->custom_dinner ) )    $meals_list[] = $l_di . ': ' . $log->custom_dinner;
            elseif ( $log->dinner_id )               $meals_list[] = $l_di . ': ' . ( $food_map[ intval( $log->dinner_id ) ] ?? 'Meal' );

            if ( ! empty( $log->custom_snack_1 ) )   $meals_list[] = 'Snack 1: ' . $log->custom_snack_1;
            elseif ( $log->snack_1_id )              $meals_list[] = 'Snack 1: ' . ( $food_map[ intval( $log->snack_1_id ) ] ?? 'Snack' );

            if ( ! empty( $log->custom_snack_2 ) )   $meals_list[] = 'Snack 2: ' . $log->custom_snack_2;
            elseif ( $log->snack_2_id )              $meals_list[] = 'Snack 2: ' . ( $food_map[ intval( $log->snack_2_id ) ] ?? 'Snack' );
        }

        $clean_phone = preg_replace( '/[^0-9]/', '', (string) $resolved['phone'] );
        if ( substr( $clean_phone, 0, 1 ) === '0' && strlen( $clean_phone ) >= 9 ) {
            $clean_phone = '971' . substr( $clean_phone, 1 );
        }

        $delivered_at_short = '';
        if ( ! empty( $log->delivered_at ) && $log->delivered_at !== '0000-00-00 00:00:00' ) {
            $delivered_at_short = date( 'h:i A', strtotime( $log->delivered_at ) );
        }

        $shift_group = 'morning';
        if ( $is_pickup ) {
            $shift_group = 'pickup';
        } elseif ( $is_day_before ) {
            $shift_group = 'evening';
        }

        $stops[] = array(
            'log_id'             => intval( $log->id ),
            'sub_id'             => intval( $log->subscription_id ),
            'order_id'           => $display_order_id,
            'customer_name'      => $resolved['full_name'] ?: 'Customer',
            'recipient'          => $recipient_name,
            'phone'              => $resolved['phone'] ?: '',
            'clean_phone'        => $clean_phone,
            'address'            => $resolved['address'] ?: 'Address not provided',
            'method_display'     => $method_display,
            'is_pickup'          => $is_pickup ? 1 : 0,
            'timing_short'       => $timing_short,
            'is_day_before'      => $is_day_before ? 1 : 0,
            'shift_group'        => $shift_group,
            'time_slot'          => $resolved['time_slot'] ?: 'N/A',
            'delivery_date'      => $delivery_date,
            'delivery_date_fmt'  => date( 'D, d M', strtotime( $delivery_date ) ),
            'target_date'        => $log->target_date,
            'target_date_fmt'    => date( 'D, d M', strtotime( $log->target_date ) ),
            'core_plan_name'     => $core_plan_name,
            'allergies'          => ! empty( $resolved['allergies'] ) ? $resolved['allergies'] : '',
            'foh_remarks'        => ! empty( $log->foh_remarks ) ? wp_strip_all_tags( $log->foh_remarks ) : '',
            'meals'              => $meals_list,
            'meal_count'         => count( $meals_list ),
            'is_prepared'        => intval( $log->is_prepared ),
            'dispatch_status'    => intval( $log->dispatch_status ),
            'delivery_result'    => ! empty( $log->delivery_result ) ? $log->delivery_result : 'Pending',
            'pos_updated'        => intval( $log->pos_updated ),
            'delivered_by_name'  => ! empty( $log->delivered_by_name ) ? $log->delivered_by_name : '',
            'delivered_by_role'  => ! empty( $log->delivered_by_role ) ? $log->delivered_by_role : '',
            'delivered_at_short' => $delivered_at_short,
            'driver_note'        => ! empty( $log->driver_note ) ? $log->driver_note : '',
        );
    }

    // Sort stops: Evening Day-Before runs first, then Morning Same-Day runs, then Pickups
    usort( $stops, function( $a, $b ) {
        if ( $a['is_pickup'] !== $b['is_pickup'] ) {
            return $a['is_pickup'] - $b['is_pickup'];
        }
        if ( $a['is_day_before'] !== $b['is_day_before'] ) {
            return $b['is_day_before'] - $a['is_day_before'];
        }
        return $a['log_id'] - $b['log_id'];
    } );

    return $stops;
}


// ==========================================
// 2. AJAX ENDPOINTS (SINGLE UPDATE, BATCH SYNC, DELTA SYNC & UI PREFS)
// ==========================================

/**
 * Helper: Processes a single delivery status update for a log row (used by both live update and offline batch sync).
 */
function cmp_process_single_driver_status_update( $log_id, $new_status, $driver_note = '', $client_timestamp = '' ) {
    global $wpdb;
    if ( function_exists( 'cmp_auto_patch_database' ) ) {
        cmp_auto_patch_database();
    }

    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $log_row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_logs} WHERE id = %d", $log_id ) );

    if ( ! $log_row ) {
        return new WP_Error( 'not_found', 'Delivery log #' . $log_id . ' not found.' );
    }

    // Stage 3 Guard: Cannot alter once FOH has completed POS verification
    if ( intval( $log_row->pos_updated ) === 1 ) {
        return new WP_Error( 'pos_locked', 'Locked: FOH has already verified this order in POS.' );
    }

    // Stage 1 Guard: Driver cannot update delivery status until Kitchen checks Dispatch
    if ( intval( $log_row->dispatch_status ) === 0 && ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'not_dispatched', 'Bag is still in the Kitchen (Dispatch checkbox not checked yet).' );
    }

    $allowed_statuses = array( 'Pending', 'Successful', 'Returned', 'Cancelled' );
    if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
        return new WP_Error( 'invalid_status', 'Invalid delivery status.' );
    }

    $actor = function_exists( 'cmp_get_current_actor_info' )
        ? cmp_get_current_actor_info( $log_row->user_id )
        : array(
            'id'   => get_current_user_id(),
            'name' => wp_get_current_user()->display_name ?: 'Driver',
            'role' => 'Driver',
        );

    $tz = new DateTimeZone( 'Asia/Dubai' );
    $now_datetime = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

    // Preserve accurate door-drop timestamp from Offline Queue if valid
    $effective_timestamp = $now_datetime;
    if ( ! empty( $client_timestamp ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $client_timestamp ) ) {
        $effective_timestamp = $client_timestamp;
    }

    $old_status_val = ! empty( $log_row->delivery_result ) ? $log_row->delivery_result : 'Pending';
    $clean_note     = sanitize_textarea_field( $driver_note );

    $update_data = array(
        'delivery_result'   => $new_status,
        'delivered_by_id'   => $new_status === 'Pending' ? 0 : $actor['id'],
        'delivered_by_name' => $new_status === 'Pending' ? '' : $actor['name'],
        'delivered_by_role' => $new_status === 'Pending' ? '' : $actor['role'],
        'delivered_at'      => $new_status === 'Pending' ? null : $effective_timestamp,
        'driver_note'       => $new_status === 'Pending' ? null : ( $clean_note !== '' ? $clean_note : $log_row->driver_note ),
        'updated_by_id'     => $actor['id'],
        'updated_by_name'   => $actor['name'],
        'updated_by_role'   => $actor['role'],
        'last_action_type'  => ( $new_status === 'Cancelled' ) ? 'Cancelled' : 'Delivery: ' . $new_status,
        'updated_at'        => $effective_timestamp,
    );

    $wpdb->update( $table_logs, $update_data, array( 'id' => $log_id ) );

    // Bust Super Admin consumption cache so KPIs reflect immediately
    delete_transient( 'cmp_sa_used_days_sync_lock' );

    // Record in Meal Portal > Customer Logs (Section 6.2 of SOP)
    if ( function_exists( 'cmp_record_customer_activity_log' ) && ( $old_status_val !== $new_status || $clean_note !== '' ) ) {
        $action_type_log = ( $new_status === 'Cancelled' ) ? 'Meal Cancelled (Driver)' : 'Delivery Status Updated (Driver)';
        $new_status_log  = 'Delivery Status: ' . $new_status . ' (' . $log_row->target_date . ')';
        if ( $clean_note !== '' ) {
            $new_status_log .= ' — Note: ' . $clean_note;
        }

        cmp_record_customer_activity_log( array(
            'subscription_id' => intval( $log_row->subscription_id ),
            'action_type'     => $action_type_log,
            'target_date'     => $log_row->target_date,
            'old_status'      => 'Delivery Status: ' . $old_status_val . ' (Dispatched)',
            'new_status'      => $new_status_log,
            'actor_id'        => $actor['id'],
            'actor_name'      => $actor['name'],
            'actor_role'      => $actor['role'],
        ) );
    }

    return array(
        'log_id'             => $log_id,
        'delivery_result'    => $new_status,
        'delivered_by_name'  => $update_data['delivered_by_name'],
        'delivered_by_role'  => $update_data['delivered_by_role'],
        'delivered_at_short' => $new_status === 'Pending' ? '' : date( 'h:i A', strtotime( $effective_timestamp ) ),
        'driver_note'        => (string) $update_data['driver_note'],
    );
}

// 2.1 AJAX: Single Delivery Status Update
add_action( 'wp_ajax_cmp_driver_update_status', 'cmp_ajax_driver_update_status' );
function cmp_ajax_driver_update_status() {
    check_ajax_referer( 'cmp_driver_nonce', 'nonce' );

    if ( ! cmp_user_has_driver_portal_access() ) {
        wp_send_json_error( 'Access Denied.' );
    }

    $log_id           = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;
    $delivery_result  = isset( $_POST['delivery_result'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_result'] ) ) : 'Pending';
    $driver_note      = isset( $_POST['driver_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['driver_note'] ) ) : '';
    $client_timestamp = isset( $_POST['client_timestamp'] ) ? sanitize_text_field( wp_unslash( $_POST['client_timestamp'] ) ) : '';

    $res = cmp_process_single_driver_status_update( $log_id, $delivery_result, $driver_note, $client_timestamp );
    if ( is_wp_error( $res ) ) {
        wp_send_json_error( $res->get_error_message() );
    }

    wp_send_json_success( $res );
}

// 2.2 AJAX: Offline Queue Batch Sync (Option 6A)
add_action( 'wp_ajax_cmp_driver_batch_sync', 'cmp_ajax_driver_batch_sync' );
function cmp_ajax_driver_batch_sync() {
    check_ajax_referer( 'cmp_driver_nonce', 'nonce' );

    if ( ! cmp_user_has_driver_portal_access() ) {
        wp_send_json_error( 'Access Denied.' );
    }

    $queue_raw = isset( $_POST['queue'] ) ? wp_unslash( $_POST['queue'] ) : '[]';
    $queue     = json_decode( $queue_raw, true );

    if ( ! is_array( $queue ) ) {
        wp_send_json_error( 'Invalid sync queue payload.' );
    }

    $synced_items = array();
    $errors       = array();

    foreach ( $queue as $item ) {
        $log_id     = isset( $item['log_id'] ) ? intval( $item['log_id'] ) : 0;
        $status_val = isset( $item['delivery_result'] ) ? sanitize_text_field( $item['delivery_result'] ) : '';
        $note_val   = isset( $item['driver_note'] ) ? sanitize_textarea_field( $item['driver_note'] ) : '';
        $ts_val     = isset( $item['client_timestamp'] ) ? sanitize_text_field( $item['client_timestamp'] ) : '';

        if ( $log_id <= 0 || empty( $status_val ) ) {
            continue;
        }

        $res = cmp_process_single_driver_status_update( $log_id, $status_val, $note_val, $ts_val );
        if ( is_wp_error( $res ) ) {
            $errors[] = array(
                'log_id' => $log_id,
                'error'  => $res->get_error_message(),
            );
        } else {
            $synced_items[] = $res;
        }
    }

    wp_send_json_success( array(
        'synced' => $synced_items,
        'errors' => $errors,
    ) );
}

// 2.3 AJAX: Lightweight Delta JSON Sync (~2KB Payload for Option 7A)
add_action( 'wp_ajax_cmp_driver_delta_sync', 'cmp_ajax_driver_delta_sync' );
function cmp_ajax_driver_delta_sync() {
    check_ajax_referer( 'cmp_driver_nonce', 'nonce' );

    if ( ! cmp_user_has_driver_portal_access() ) {
        wp_send_json_error( 'Access Denied.' );
    }

    date_default_timezone_set( 'Asia/Dubai' );
    $selected_date = isset( $_POST['selected_date'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_date'] ) ) : date( 'Y-m-d' );
    $stops         = cmp_get_driver_manifest_stops( $selected_date );

    $deltas = array();
    foreach ( $stops as $st ) {
        $deltas[ $st['log_id'] ] = array(
            'log_id'             => $st['log_id'],
            'is_prepared'        => $st['is_prepared'],
            'dispatch_status'    => $st['dispatch_status'],
            'delivery_result'    => $st['delivery_result'],
            'pos_updated'        => $st['pos_updated'],
            'delivered_by_name'  => $st['delivered_by_name'],
            'delivered_by_role'  => $st['delivered_by_role'],
            'delivered_at_short' => $st['delivered_at_short'],
            'driver_note'        => $st['driver_note'],
        );
    }

    wp_send_json_success( array(
        'server_time' => date( 'h:i:s A' ),
        'deltas'      => $deltas,
    ) );
}

// 2.4 AJAX: Save Driver Personal UI Preferences (Option 9A)
add_action( 'wp_ajax_cmp_driver_save_ui_prefs', 'cmp_ajax_driver_save_ui_prefs' );
function cmp_ajax_driver_save_ui_prefs() {
    check_ajax_referer( 'cmp_driver_nonce', 'nonce' );

    if ( ! cmp_user_has_driver_portal_access() ) {
        wp_send_json_error( 'Access Denied.' );
    }

    $user_id = get_current_user_id();
    $prefs   = array(
        'profile'   => in_array( $_POST['profile'] ?? '', array( 'lite', 'rich', 'auto' ), true ) ? sanitize_text_field( $_POST['profile'] ) : 'auto',
        'density'   => in_array( $_POST['density'] ?? '', array( 'compact', 'expanded' ), true ) ? sanitize_text_field( $_POST['density'] ) : 'compact',
        'action_ui' => in_array( $_POST['action_ui'] ?? '', array( '2A', '2B' ), true ) ? sanitize_text_field( $_POST['action_ui'] ) : '2A',
        'theme'     => in_array( $_POST['theme'] ?? '', array( 'daylight', 'night', 'glass' ), true ) ? sanitize_text_field( $_POST['theme'] ) : 'daylight',
        'auto_sync' => ! empty( $_POST['auto_sync'] ) ? 1 : 0,
        'map_app'   => in_array( $_POST['map_app'] ?? '', array( 'both', 'google', 'waze' ), true ) ? sanitize_text_field( $_POST['map_app'] ) : 'both',
    );

    update_user_meta( $user_id, '_cmp_driver_ui_prefs', $prefs );
    wp_send_json_success( $prefs );
}


// ==========================================
// 3. OPTION 5A / 5B: STANDALONE PWA APP SHELL & SCRIPT DEQUEUING
// ==========================================
add_action( 'template_redirect', 'cmp_driver_portal_standalone_pwa_shell', 5 );
function cmp_driver_portal_standalone_pwa_shell() {
    if ( ! is_singular() ) {
        return;
    }
    global $post;
    if ( ! $post || ! has_shortcode( $post->post_content, 'meal_driver_portal' ) ) {
        return;
    }

    // Only intercept for Standalone Fullscreen PWA Shell when Option 5A is active and user is authenticated
    $cfg = cmp_get_driver_portal_settings();
    if ( $cfg['shell_mode'] !== '5A' || isset( $_GET['drv_embed'] ) ) {
        return;
    }

    if ( ! cmp_user_has_driver_portal_access() ) {
        return; // Let standard page render the Glassmorphism login screen smoothly
    }

    // Inline Web App Manifest for "Add to Home Screen" PWA experience
    $manifest_json = wp_json_encode( array(
        'name'             => 'Cycle Bistro Driver Portal',
        'short_name'       => 'CB Driver',
        'start_url'        => get_permalink( $post->ID ),
        'display'          => 'standalone',
        'background_color' => '#f8fafc',
        'theme_color'      => '#1B5E20',
    ) );
    $manifest_data_uri = 'data:application/manifest+json;charset=utf-8,' . rawurlencode( $manifest_json );

    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#1B5E20" id="cmp-drv-meta-theme">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="CB Driver">
    <link rel="manifest" href="<?php echo esc_attr( $manifest_data_uri ); ?>">
    <title>Driver Portal &mdash; <?php bloginfo( 'name' ); ?></title>
    <style>
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100%;
            min-height: 100vh;
            background: #f1f5f9;
            -webkit-font-smoothing: antialiased;
        }
    </style>
</head>
<body>
    <?php echo cmp_render_driver_portal(); ?>
</body>
</html><?php
    exit;
}

// Option 5B: Dequeue non-essential WooCommerce scripts when rendering inside standard WP theme wrapper
add_action( 'wp_enqueue_scripts', 'cmp_driver_portal_dequeue_bloat_5b', 99 );
function cmp_driver_portal_dequeue_bloat_5b() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'meal_driver_portal' ) ) {
        wp_dequeue_style( 'wc-blocks-style' );
        wp_dequeue_script( 'wc-cart-fragments' );
        wp_dequeue_script( 'woocommerce' );
    }
}


// ==========================================
// 4. SHORTCODE RENDERER: [meal_driver_portal]
// ==========================================
add_shortcode( 'meal_driver_portal', 'cmp_render_driver_portal' );
function cmp_render_driver_portal() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    date_default_timezone_set( 'Asia/Dubai' );

    // Security & Custom Glassmorphism UI/UX Login Screen
    if ( ! is_user_logged_in() || ! cmp_user_has_driver_portal_access() ) {
        if ( function_exists( 'cmp_render_portal_login_ui' ) ) {
            return cmp_render_portal_login_ui( array(
                'portal_id'      => 'driver',
                'title'          => 'Driver Dispatch Portal',
                'subtitle'       => 'Daily Route Manifest, Navigation & Delivery Status',
                'required_caps'  => array( 'manage_options', 'delivery_driver', 'kitchen_staff', 'foh_manager', 'menu_manager' ),
                'denied_message' => 'Delivery Driver, Kitchen, FOH, or Administrator clearance is required to access this portal.',
            ) );
        }
        return '<p>Please log in with an authorized Driver account.</p>';
    }

    if ( function_exists( 'cmp_auto_patch_database' ) ) {
        cmp_auto_patch_database();
    }
    if ( function_exists( 'cmp_ensure_subscription_logistics_columns' ) ) {
        cmp_ensure_subscription_logistics_columns();
    }

    $cfg          = cmp_get_driver_portal_settings();
    $current_user = wp_get_current_user();
    $ui_prefs     = cmp_get_driver_user_ui_prefs( $current_user->ID );

    $today_ymd     = date( 'Y-m-d' );
    $selected_date = isset( $_GET['drv_date'] ) ? sanitize_text_field( $_GET['drv_date'] ) : ( isset( $_GET['prep_date'] ) ? sanitize_text_field( $_GET['prep_date'] ) : $today_ymd );
    $prev_date     = date( 'Y-m-d', strtotime( $selected_date . ' - 1 day' ) );
    $next_date     = date( 'Y-m-d', strtotime( $selected_date . ' + 1 day' ) );

    $stops = cmp_get_driver_manifest_stops( $selected_date, $cfg );

    // Calculate initial KPI counters
    $kpi_total      = count( $stops );
    $kpi_ready      = 0;
    $kpi_delivered  = 0;
    $kpi_issues     = 0;
    $count_evening  = 0;
    $count_morning  = 0;
    $count_pickups  = 0;

    foreach ( $stops as $st ) {
        if ( $st['shift_group'] === 'evening' ) $count_evening++;
        elseif ( $st['shift_group'] === 'morning' ) $count_morning++;
        elseif ( $st['shift_group'] === 'pickup' ) $count_pickups++;

        if ( $st['delivery_result'] === 'Successful' ) {
            $kpi_delivered++;
        } elseif ( in_array( $st['delivery_result'], array( 'Returned', 'Cancelled' ), true ) ) {
            $kpi_issues++;
        } elseif ( $st['dispatch_status'] === 1 ) {
            $kpi_ready++;
        }
    }

    $date_mode_label = ( $cfg['date_mode'] === '1B' )
        ? 'Delivery Date: ' . date( 'D, d M Y', strtotime( $selected_date ) )
        : 'Prep Batch: ' . date( 'D, d M Y', strtotime( $selected_date ) );

    $ajax_url     = admin_url( 'admin-ajax.php' );
    $driver_nonce = wp_create_nonce( 'cmp_driver_nonce' );

    ob_start();
    ?>
    <style>
        /* =========================================================
           TWO-TIER ADAPTIVE UI ENGINE (LITE, RICH/GLASS & NIGHT SHIFT)
           ========================================================= */
        #cmp-drv-app {
            --drv-bg: #f1f5f9;
            --drv-surface: #ffffff;
            --drv-surface-alt: #f8fafc;
            --drv-text: #0f172a;
            --drv-text-muted: #475569;
            --drv-border: #cbd5e1;
            --drv-brand: #1B5E20;
            --drv-brand-mid: #379237;
            --drv-brand-light: #EEF7E3;
            --drv-shadow: none;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--drv-bg);
            color: var(--drv-text);
            max-width: 780px;
            margin: 0 auto;
            padding: 0 10px 90px 10px;
            box-sizing: border-box;
            min-height: 100vh;
        }
        #cmp-drv-app *, #cmp-drv-app *::before, #cmp-drv-app *::after {
            box-sizing: border-box;
            letter-spacing: normal;
        }

        /* Rich / Pro Profile Enhancements (Enabled on 4G/5G/Good Phones) */
        #cmp-drv-app.drv-profile-rich {
            --drv-shadow: 0 6px 18px rgba(15, 23, 42, 0.07);
        }
        #cmp-drv-app.drv-profile-rich .drv-stop-card {
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }

        /* Night Shift Dark Theme (Option 8A) */
        #cmp-drv-app.drv-theme-night {
            --drv-bg: #0b1120;
            --drv-surface: #1e293b;
            --drv-surface-alt: #0f172a;
            --drv-text: #f8fafc;
            --drv-text-muted: #94a3b8;
            --drv-border: #334155;
            --drv-brand: #22c55e;
            --drv-brand-mid: #16a34a;
            --drv-brand-light: rgba(34, 197, 94, 0.14);
        }

        /* Cycle Bistro Brand Glass Theme */
        #cmp-drv-app.drv-theme-glass {
            --drv-bg: #eef5eb;
            --drv-surface: rgba(255, 255, 255, 0.92);
            --drv-surface-alt: #f4fbf0;
            --drv-border: #DCECC9;
        }

        /* Sticky Mobile Route Header */
        .drv-sticky-top {
            position: sticky;
            top: 0;
            z-index: 90;
            background: #1B5E20;
            color: #ffffff;
            padding: 12px 14px;
            border-radius: 0 0 16px 16px;
            margin: 0 -10px 12px -10px;
            border-bottom: 3px solid #68B946;
        }
        #cmp-drv-app.drv-theme-night .drv-sticky-top {
            background: #0f172a;
            border-bottom-color: #22c55e;
        }
        .drv-top-row-1 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .drv-brand-title {
            font-size: 1.12rem;
            font-weight: 900;
            margin: 0;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .drv-mode-badge {
            font-size: 0.68rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 999px;
            background: rgba(220, 236, 201, 0.22);
            color: #DCECC9;
            border: 1px solid rgba(220, 236, 201, 0.45);
            text-transform: uppercase;
        }
        .drv-hdr-Controls {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .drv-icon-btn {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            height: 36px;
            padding: 0 10px;
            font-size: 0.78rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            text-decoration: none;
            white-space: nowrap;
        }
        .drv-icon-btn:active {
            transform: scale(0.97);
        }
        .drv-icon-btn.btn-sync {
            background: #68B946;
            color: #0f172a;
            border-color: #bbf7d0;
        }

        /* Offline / Network Status Banner (Option 6A) */
        .drv-net-banner {
            display: none;
            margin-top: 8px;
            padding: 7px 10px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 800;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .drv-net-banner.is-offline {
            display: flex;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }
        .drv-net-banner.has-queue {
            display: flex;
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #60a5fa;
        }

        /* Progress Bar & KPI Strip */
        .drv-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 6px;
            margin-top: 10px;
        }
        .drv-kpi-box {
            background: rgba(0, 0, 0, 0.24);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 8px;
            padding: 6px 4px;
            text-align: center;
        }
        .drv-kpi-num {
            display: block;
            font-size: 1.1rem;
            font-weight: 900;
            line-height: 1.1;
            color: #ffffff;
        }
        .drv-kpi-lbl {
            display: block;
            font-size: 0.64rem;
            font-weight: 700;
            color: #DCECC9;
            text-transform: uppercase;
            margin-top: 2px;
        }
        .drv-progress-track {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 999px;
            margin-top: 8px;
            overflow: hidden;
        }
        .drv-progress-fill {
            height: 100%;
            background: #68B946;
            border-radius: 999px;
            width: 0%;
            transition: width 0.25s ease;
        }

        /* Date Nav & Search Bar */
        .drv-date-nav-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            background: var(--drv-surface);
            border: 1.5px solid var(--drv-border);
            border-radius: 12px;
            padding: 8px 10px;
            margin-bottom: 10px;
        }
        .drv-date-btn {
            background: var(--drv-surface-alt);
            color: var(--drv-text);
            border: 1px solid var(--drv-border);
            border-radius: 8px;
            padding: 7px 11px;
            font-size: 0.8rem;
            font-weight: 800;
            text-decoration: none;
            white-space: nowrap;
        }
        .drv-date-input {
            flex: 1;
            min-width: 0;
            height: 36px;
            border: 1px solid var(--drv-border);
            border-radius: 8px;
            padding: 0 8px;
            font-size: 0.88rem;
            font-weight: 800;
            color: var(--drv-text);
            background: var(--drv-surface-alt);
            text-align: center;
        }

        /* Shift & Status Filter Tabs */
        .drv-tabs-scroll {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 4px;
            margin-bottom: 10px;
            scrollbar-width: none;
        }
        .drv-tabs-scroll::-webkit-scrollbar { display: none; }
        .drv-tab-pill {
            flex: 0 0 auto;
            background: var(--drv-surface);
            color: var(--drv-text-muted);
            border: 1.5px solid var(--drv-border);
            border-radius: 999px;
            padding: 7px 13px;
            font-size: 0.78rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .drv-tab-pill.active {
            background: #379237;
            color: #ffffff;
            border-color: #1B5E20;
        }

        .drv-search-input {
            width: 100%;
            height: 42px;
            background: var(--drv-surface);
            color: var(--drv-text);
            border: 1.5px solid var(--drv-border);
            border-radius: 10px;
            padding: 0 12px;
            font-size: 0.92rem;
            font-weight: 600;
            margin-bottom: 12px;
        }

        /* Stop Cards */
        .drv-stop-card {
            background: var(--drv-surface);
            border: 1.5px solid var(--drv-border);
            border-left: 5px solid #379237;
            border-radius: 14px;
            padding: 13px 14px;
            margin-bottom: 12px;
            box-shadow: var(--drv-shadow);
        }
        .drv-stop-card.state-awaiting-dispatch {
            border-left-color: #94a3b8;
            opacity: 0.92;
        }
        .drv-stop-card.state-ready {
            border-left-color: #f59e0b;
        }
        .drv-stop-card.state-delivered {
            border-left-color: #16a34a;
        }
        .drv-stop-card.state-issue {
            border-left-color: #dc2626;
        }
        .drv-stop-card.drv-hidden {
            display: none !important;
        }

        .drv-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 8px;
        }
        .drv-stop-title-wrap {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }
        .drv-stop-num {
            background: #1e293b;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 900;
            padding: 2px 7px;
            border-radius: 6px;
        }
        .drv-order-badge {
            background: var(--drv-surface-alt);
            color: var(--drv-text);
            border: 1px solid var(--drv-border);
            font-size: 0.75rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 6px;
        }
        .drv-cust-name {
            font-size: 1.04rem;
            font-weight: 900;
            color: var(--drv-text);
        }
        .drv-recipient-pill {
            background: #EEF7E3;
            color: #1B5E20;
            border: 1px solid #b7e1a1;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 800;
        }

        /* Status Badge on Card Top-Right */
        .drv-state-pill {
            font-size: 0.71rem;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 999px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .drv-state-pill.pill-kitchen { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .drv-state-pill.pill-ready   { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
        .drv-state-pill.pill-done    { background: #dcfce7; color: #166534; border: 1px solid #4ade80; }
        .drv-state-pill.pill-issue   { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .drv-state-pill.pill-queued  { background: #dbeafe; color: #1e40af; border: 1px solid #60a5fa; }

        /* Logistics & Address Box */
        .drv-logistics-box {
            background: var(--drv-surface-alt);
            border: 1px solid var(--drv-border);
            border-radius: 10px;
            padding: 9px 11px;
            font-size: 0.85rem;
            line-height: 1.4;
            margin-bottom: 9px;
        }
        .drv-address-line {
            font-size: 0.94rem;
            font-weight: 800;
            color: var(--drv-text);
            margin-bottom: 5px;
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }
        .drv-slot-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .drv-chip {
            background: var(--drv-surface);
            border: 1px solid var(--drv-border);
            padding: 2px 7px;
            border-radius: 6px;
            color: var(--drv-text-muted);
        }
        .drv-foh-note {
            margin-top: 7px;
            padding: 6px 9px;
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fcd34d;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        /* 1-Tap Communication & Navigation Bar */
        .drv-comm-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(85px, 1fr));
            gap: 6px;
            margin-bottom: 9px;
        }
        .drv-comm-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            height: 38px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 800;
            text-decoration: none !important;
            border: 1px solid var(--drv-border);
            background: var(--drv-surface-alt);
            color: var(--drv-text);
        }
        .drv-comm-btn.btn-call { background: #f0fdf4; color: #166534; border-color: #86efac; }
        .drv-comm-btn.btn-wa   { background: #ecfdf5; color: #047857; border-color: #6ee7b7; }
        .drv-comm-btn.btn-gmap { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }
        .drv-comm-btn.btn-waze { background: #f5f3ff; color: #6d28d9; border-color: #c4b5fd; }

        /* Collapsible Bag Contents Checklist */
        .drv-bag-toggle {
            width: 100%;
            background: var(--drv-surface-alt);
            color: var(--drv-text);
            border: 1px dashed var(--drv-border);
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 0.8rem;
            font-weight: 800;
            text-align: left;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 9px;
        }
        .drv-bag-drawer {
            display: none;
            background: var(--drv-surface-alt);
            border: 1px solid var(--drv-border);
            border-radius: 8px;
            padding: 8px 12px;
            margin-top: -5px;
            margin-bottom: 9px;
            font-size: 0.82rem;
        }
        #cmp-drv-app.drv-density-expanded .drv-bag-drawer,
        .drv-stop-card.bag-is-open .drv-bag-drawer {
            display: block;
        }
        .drv-meal-ul {
            margin: 0;
            padding-left: 16px;
            line-height: 1.45;
            font-weight: 600;
        }
        .drv-allergy-pill {
            display: inline-block;
            margin-top: 6px;
            padding: 2px 8px;
            background: #fff1f2;
            color: #be123c;
            border: 1px solid #fda4af;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 800;
        }

        /* Stage 1/2/3 Delivery Status Controls (Option 2A & 2B) */
        .drv-action-zone {
            padding-top: 8px;
            border-top: 1px solid var(--drv-border);
        }
        .drv-stage-lock-banner {
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            font-size: 0.82rem;
            font-weight: 800;
        }
        .drv-stage-lock-banner.lock-kitchen {
            background: #f1f5f9;
            color: #475569;
            border: 1px dashed #94a3b8;
        }
        .drv-stage-lock-banner.lock-pos {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        /* Option 2A: Large 1-Tap Action Buttons */
        .drv-ui-2a {
            display: flex;
            gap: 8px;
            align-items: stretch;
        }
        .drv-btn-deliver {
            flex: 2;
            min-height: 46px;
            background: #16a34a;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.92rem;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .drv-btn-deliver.is-done {
            background: #15803d;
            outline: 2px solid #86efac;
        }
        .drv-btn-issue {
            flex: 1;
            min-height: 46px;
            background: #fff1f2;
            color: #be123c;
            border: 1.5px solid #fda4af;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 800;
            cursor: pointer;
        }
        .drv-btn-issue.is-active-issue {
            background: #dc2626;
            color: #ffffff;
            border-color: #991b1b;
        }

        /* Option 2B: Full-Width Select Dropdown */
        .drv-ui-2b {
            display: none;
        }
        #cmp-drv-app.drv-action-2b .drv-ui-2a { display: none !important; }
        #cmp-drv-app.drv-action-2b .drv-ui-2b { display: block !important; }

        .drv-status-select {
            width: 100%;
            height: 46px;
            border: 2px solid var(--drv-border);
            border-radius: 10px;
            padding: 0 12px;
            font-size: 0.95rem;
            font-weight: 800;
            background: var(--drv-surface-alt);
            color: var(--drv-text);
        }

        .drv-stamp-foot {
            margin-top: 6px;
            font-size: 0.74rem;
            font-weight: 700;
            color: var(--drv-text-muted);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        /* Modals (Option 4A Issue Prompt & Option 9A Settings Drawer) */
        .drv-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            z-index: 10020;
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }
        .drv-modal-backdrop.is-open {
            display: flex;
        }
        .drv-modal-sheet {
            background: var(--drv-surface);
            color: var(--drv-text);
            width: 100%;
            max-width: 600px;
            border-radius: 18px 18px 0 0;
            padding: 18px 16px 24px 16px;
            border-top: 4px solid #379237;
            max-height: 90vh;
            overflow-y: auto;
        }
        .drv-preset-chip {
            display: inline-block;
            background: var(--drv-surface-alt);
            color: var(--drv-text);
            border: 1.5px solid var(--drv-border);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: 700;
            margin: 0 6px 6px 0;
            cursor: pointer;
        }
        .drv-preset-chip.selected {
            background: #fee2e2;
            color: #991b1b;
            border-color: #ef4444;
        }
    </style>

    <div id="cmp-drv-app"
         class="drv-profile-<?php echo esc_attr( $ui_prefs['profile'] === 'rich' ? 'rich' : 'lite' ); ?> drv-theme-<?php echo esc_attr( $ui_prefs['theme'] ); ?> drv-density-<?php echo esc_attr( $ui_prefs['density'] ); ?> <?php echo $ui_prefs['action_ui'] === '2B' ? 'drv-action-2b' : 'drv-action-2a'; ?>"
         data-selected-date="<?php echo esc_attr( $selected_date ); ?>">

        <!-- STICKY TOP BAR -->
        <div class="drv-sticky-top">
            <div class="drv-top-row-1">
                <div>
                    <h1 class="drv-brand-title">
                        <span>🚚 Driver Dispatch</span>
                        <span class="drv-mode-badge" id="drv-active-profile-badge"><?php echo esc_html( strtoupper( $ui_prefs['profile'] ) ); ?></span>
                    </h1>
                    <div style="font-size:0.75rem; color:#DCECC9; font-weight:600; margin-top:2px;">
                        <?php echo esc_html( $date_mode_label ); ?> &bull; <span id="drv-last-sync-txt">Live</span>
                    </div>
                </div>

                <div class="drv-hdr-Controls">
                    <button type="button" class="drv-icon-btn btn-sync" id="drv-sync-btn" title="Lightweight Delta Sync (~2KB)">
                        &#8635; Sync
                    </button>

                    <?php if ( $cfg['theme_mode'] === '8A' ) : ?>
                        <button type="button" class="drv-icon-btn" id="drv-theme-toggle-btn" title="Toggle Daylight / Night Shift Theme">
                            <?php echo $ui_prefs['theme'] === 'night' ? '☀️' : '🌙'; ?>
                        </button>
                    <?php endif; ?>

                    <?php if ( $cfg['allow_custom'] === '9A' ) : ?>
                        <button type="button" class="drv-icon-btn" id="drv-open-settings-btn" title="Driver View & Data Settings">
                            ⚙️
                        </button>
                    <?php endif; ?>

                    <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="drv-icon-btn" style="background:rgba(220,38,38,0.85); border-color:#fca5a5;" title="Log Out">
                        Exit
                    </a>
                </div>
            </div>

            <!-- Offline / Pending Sync Queue Banner (Option 6A) -->
            <div class="drv-net-banner" id="drv-net-banner">
                <span id="drv-net-banner-text">Offline Mode Active — Changes saved to phone</span>
                <button type="button" id="drv-flush-queue-btn" style="background:#1e40af; color:#fff; border:none; border-radius:6px; padding:4px 10px; font-size:0.75rem; font-weight:800; cursor:pointer;">
                    Sync Queue Now
                </button>
            </div>

            <!-- Live Route KPIs -->
            <div class="drv-kpi-grid">
                <div class="drv-kpi-box">
                    <span class="drv-kpi-num" id="drv-kpi-total"><?php echo intval( $kpi_total ); ?></span>
                    <span class="drv-kpi-lbl">Total Stops</span>
                </div>
                <div class="drv-kpi-box">
                    <span class="drv-kpi-num" id="drv-kpi-ready" style="color:#fde68a;"><?php echo intval( $kpi_ready ); ?></span>
                    <span class="drv-kpi-lbl">Dispatched</span>
                </div>
                <div class="drv-kpi-box">
                    <span class="drv-kpi-num" id="drv-kpi-done" style="color:#86efac;"><?php echo intval( $kpi_delivered ); ?></span>
                    <span class="drv-kpi-lbl">Delivered</span>
                </div>
                <div class="drv-kpi-box">
                    <span class="drv-kpi-num" id="drv-kpi-issues" style="color:#fca5a5;"><?php echo intval( $kpi_issues ); ?></span>
                    <span class="drv-kpi-lbl">Ret / Canc</span>
                </div>
            </div>

            <div class="drv-progress-track">
                <?php $init_pct = $kpi_total > 0 ? round( ( ( $kpi_delivered + $kpi_issues ) / $kpi_total ) * 100 ) : 0; ?>
                <div class="drv-progress-fill" id="drv-progress-fill" style="width:<?php echo intval( $init_pct ); ?>%;"></div>
            </div>
        </div>

        <!-- DATE SELECTOR BAR -->
        <form method="GET" id="drv-date-form" class="drv-date-nav-bar">
            <a href="?drv_date=<?php echo esc_attr( $prev_date ); ?>" class="drv-date-btn">&laquo; Prev</a>
            <input type="date" name="drv_date" value="<?php echo esc_attr( $selected_date ); ?>" class="drv-date-input" onchange="document.getElementById('drv-date-form').submit();">
            <a href="?drv_date=<?php echo esc_attr( $next_date ); ?>" class="drv-date-btn">Next &raquo;</a>
        </form>

        <!-- SHIFT & STATUS FILTER PILLS -->
        <div class="drv-tabs-scroll">
            <button type="button" class="drv-tab-pill active" data-filter="all">All Stops (<?php echo intval( $kpi_total ); ?>)</button>
            <button type="button" class="drv-tab-pill" data-filter="pending_drop">Pending Drop-Off</button>
            <button type="button" class="drv-tab-pill" data-filter="evening">Evening / Day Before (<?php echo intval( $count_evening ); ?>)</button>
            <button type="button" class="drv-tab-pill" data-filter="morning">Morning / Same Day (<?php echo intval( $count_morning ); ?>)</button>
            <?php if ( $cfg['show_pickups'] === '1' && $count_pickups > 0 ) : ?>
                <button type="button" class="drv-tab-pill" data-filter="pickup">Branch Pick-ups (<?php echo intval( $count_pickups ); ?>)</button>
            <?php endif; ?>
            <button type="button" class="drv-tab-pill" data-filter="completed">Completed</button>
        </div>

        <!-- INSTANT SEARCH -->
        <input type="search" id="drv-search-input" class="drv-search-input" placeholder="🔍 Search stop by Order ID, Customer, Recipient, Area, or Phone...">

        <!-- STOPS LIST -->
        <div id="drv-stops-container">
            <?php if ( empty( $stops ) ) : ?>
                <div style="background:var(--drv-surface); border:1.5px dashed var(--drv-border); border-radius:14px; padding:36px 20px; text-align:center; color:var(--drv-text-muted); font-weight:700;">
                    No scheduled deliveries found for this date.
                </div>
            <?php else : ?>
                <?php
                $stop_index = 0;
                foreach ( $stops as $st ) :
                    $stop_index++;
                    $log_id      = $st['log_id'];
                    $is_disp     = ( $st['dispatch_status'] === 1 );
                    $is_pos      = ( $st['pos_updated'] === 1 );
                    $del_res     = $st['delivery_result'];
                    $is_done     = ( $del_res === 'Successful' );
                    $is_issue    = in_array( $del_res, array( 'Returned', 'Cancelled' ), true );

                    $card_state_class = 'state-awaiting-dispatch';
                    $pill_class       = 'pill-kitchen';
                    $pill_text        = '⏳ In Kitchen';

                    if ( $is_done ) {
                        $card_state_class = 'state-delivered';
                        $pill_class       = 'pill-done';
                        $pill_text        = $is_pos ? '✓ POS Verified' : '✓ Delivered';
                    } elseif ( $is_issue ) {
                        $card_state_class = 'state-issue';
                        $pill_class       = 'pill-issue';
                        $pill_text        = '✕ ' . $del_res;
                    } elseif ( $is_disp ) {
                        $card_state_class = 'state-ready';
                        $pill_class       = 'pill-ready';
                        $pill_text        = '🚚 Dispatched';
                    }

                    $encoded_addr = rawurlencode( $st['address'] . ', Dubai, UAE' );
                    $gmap_url     = 'https://www.google.com/maps/search/?api=1&query=' . $encoded_addr;
                    $waze_url     = 'https://waze.com/ul?q=' . $encoded_addr . '&navigate=yes';
                    $wa_text      = rawurlencode( 'Hi ' . $st['customer_name'] . ', your meal delivery from The Cycle Bistro is on the way!' );

                    $search_blob = strtolower(
                        $st['order_id'] . ' ' .
                        $st['customer_name'] . ' ' .
                        $st['recipient'] . ' ' .
                        $st['phone'] . ' ' .
                        $st['address'] . ' ' .
                        $st['time_slot']
                    );
                ?>
                <div class="drv-stop-card <?php echo esc_attr( $card_state_class ); ?>"
                     id="drv-card-<?php echo esc_attr( $log_id ); ?>"
                     data-log-id="<?php echo esc_attr( $log_id ); ?>"
                     data-sub-id="<?php echo esc_attr( $st['sub_id'] ); ?>"
                     data-shift="<?php echo esc_attr( $st['shift_group'] ); ?>"
                     data-dispatched="<?php echo esc_attr( $st['dispatch_status'] ); ?>"
                     data-pos="<?php echo esc_attr( $st['pos_updated'] ); ?>"
                     data-status="<?php echo esc_attr( $del_res ); ?>"
                     data-search="<?php echo esc_attr( $search_blob ); ?>">

                    <!-- Top Identity Row -->
                    <div class="drv-card-top">
                        <div class="drv-stop-title-wrap">
                            <span class="drv-stop-num">#<?php echo intval( $stop_index ); ?></span>
                            <span class="drv-order-badge"><?php echo esc_html( $st['order_id'] ); ?></span>
                            <span class="drv-cust-name"><?php echo esc_html( $st['customer_name'] ); ?></span>
                            <?php if ( ! empty( $st['recipient'] ) ) : ?>
                                <span class="drv-recipient-pill"><?php echo esc_html( $st['recipient'] ); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="drv-state-pill <?php echo esc_attr( $pill_class ); ?>" id="drv-pill-<?php echo esc_attr( $log_id ); ?>">
                            <?php echo esc_html( $pill_text ); ?>
                        </span>
                    </div>

                    <!-- Address, Shift & FOH Remarks Box -->
                    <div class="drv-logistics-box">
                        <div class="drv-address-line">
                            <span>📍</span>
                            <span><?php echo esc_html( $st['address'] ); ?></span>
                        </div>
                        <div class="drv-slot-chips">
                            <span class="drv-chip"><strong><?php echo esc_html( $st['method_display'] ); ?></strong></span>
                            <span class="drv-chip"><?php echo esc_html( $st['timing_short'] ); ?> (<?php echo esc_html( $st['delivery_date_fmt'] ); ?>)</span>
                            <span class="drv-chip">🕒 <?php echo esc_html( $st['time_slot'] ); ?></span>
                        </div>
                        <?php if ( ! empty( $st['foh_remarks'] ) ) : ?>
                            <div class="drv-foh-note">
                                📋 <strong>Note:</strong> <?php echo esc_html( $st['foh_remarks'] ); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 1-Tap Call, WhatsApp & GPS Map Bar -->
                    <div class="drv-comm-bar">
                        <?php if ( ! empty( $st['phone'] ) ) : ?>
                            <a href="tel:<?php echo esc_attr( $st['phone'] ); ?>" class="drv-comm-btn btn-call">📞 Call</a>
                        <?php endif; ?>
                        <?php if ( ! empty( $st['clean_phone'] ) ) : ?>
                            <a href="https://wa.me/<?php echo esc_attr( $st['clean_phone'] ); ?>?text=<?php echo esc_attr( $wa_text ); ?>" target="_blank" rel="noopener" class="drv-comm-btn btn-wa">💬 WhatsApp</a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( $gmap_url ); ?>" target="_blank" rel="noopener" class="drv-comm-btn btn-gmap drv-map-google" <?php echo ( $ui_prefs['map_app'] === 'waze' ) ? 'style="display:none;"' : ''; ?>>📍 Google Map</a>
                        <a href="<?php echo esc_url( $waze_url ); ?>" target="_blank" rel="noopener" class="drv-comm-btn btn-waze drv-map-waze" <?php echo ( $ui_prefs['map_app'] === 'google' ) ? 'style="display:none;"' : ''; ?>>🚗 Waze</a>
                    </div>

                    <!-- Collapsible Bag Meal Contents & Allergy Check -->
                    <button type="button" class="drv-bag-toggle" data-log-id="<?php echo esc_attr( $log_id ); ?>">
                        <span>🛍️ Bag Contents: <?php echo esc_html( $st['core_plan_name'] ); ?> (<?php echo intval( $st['meal_count'] ); ?> items)</span>
                        <span class="drv-bag-arrow">&#9660;</span>
                    </button>
                    <div class="drv-bag-drawer" id="drv-bag-<?php echo esc_attr( $log_id ); ?>">
                        <?php if ( ! empty( $st['meals'] ) ) : ?>
                            <ul class="drv-meal-ul">
                                <?php foreach ( $st['meals'] as $m_item ) : ?>
                                    <li><?php echo esc_html( $m_item ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <div style="color:var(--drv-text-muted); font-style:italic;">No specific items listed.</div>
                        <?php endif; ?>

                        <?php if ( ! empty( $st['allergies'] ) && $st['allergies'] !== 'No Allergies' ) : ?>
                            <div><span class="drv-allergy-pill">⚠️ Allergies: <?php echo esc_html( $st['allergies'] ); ?></span></div>
                        <?php endif; ?>
                    </div>

                    <!-- 3-STAGE DELIVERY ACTION ZONE -->
                    <div class="drv-action-zone" id="drv-action-zone-<?php echo esc_attr( $log_id ); ?>">
                        <!-- Stage 1 Banner: Awaiting Kitchen Dispatch -->
                        <div class="drv-stage-lock-banner lock-kitchen" id="drv-lock-kitchen-<?php echo esc_attr( $log_id ); ?>" style="display:<?php echo ( ! $is_disp && ! $is_pos && $del_res === 'Pending' ) ? 'block' : 'none'; ?>;">
                            ⏳ In Kitchen &mdash; Awaiting Kitchen Dispatch Check
                        </div>

                        <!-- Stage 3 Banner: POS Reconciled by FOH -->
                        <div class="drv-stage-lock-banner lock-pos" id="drv-lock-pos-<?php echo esc_attr( $log_id ); ?>" style="display:<?php echo $is_pos ? 'block' : 'none'; ?>;">
                            ✓ Completed &amp; POS Verified by FOH
                        </div>

                        <!-- Stage 2 Active Controls (Unlocked when Dispatched & not POS-locked) -->
                        <div class="drv-active-controls" id="drv-controls-<?php echo esc_attr( $log_id ); ?>" style="display:<?php echo ( ( $is_disp || $del_res !== 'Pending' ) && ! $is_pos ) ? 'block' : 'none'; ?>;">
                            <!-- Option 2A: Large 1-Tap Buttons -->
                            <div class="drv-ui-2a">
                                <button type="button"
                                        class="drv-btn-deliver <?php echo $is_done ? 'is-done' : ''; ?>"
                                        data-log-id="<?php echo esc_attr( $log_id ); ?>">
                                    <?php echo $is_done ? '✓ Delivered (Tap to Undo)' : '✓ Mark Delivered'; ?>
                                </button>
                                <button type="button"
                                        class="drv-btn-issue <?php echo $is_issue ? 'is-active-issue' : ''; ?>"
                                        data-log-id="<?php echo esc_attr( $log_id ); ?>"
                                        data-cust-name="<?php echo esc_attr( $st['customer_name'] ); ?>">
                                    <?php echo $is_issue ? esc_html( $del_res ) : '⚠️ Issue / Return'; ?>
                                </button>
                            </div>

                            <!-- Option 2B: Full-Width Dropdown -->
                            <div class="drv-ui-2b">
                                <select class="drv-status-select" data-log-id="<?php echo esc_attr( $log_id ); ?>" data-cust-name="<?php echo esc_attr( $st['customer_name'] ); ?>">
                                    <option value="Pending" <?php selected( $del_res, 'Pending' ); ?>>⏳ Pending Delivery</option>
                                    <option value="Successful" <?php selected( $del_res, 'Successful' ); ?>>✓ Delivered (Successful)</option>
                                    <option value="Returned" <?php selected( $del_res, 'Returned' ); ?>>⚠️ Returned</option>
                                    <option value="Cancelled" <?php selected( $del_res, 'Cancelled' ); ?>>✕ Cancelled</option>
                                </select>
                            </div>
                        </div>

                        <!-- Attribution & Note Footer -->
                        <div class="drv-stamp-foot" id="drv-foot-<?php echo esc_attr( $log_id ); ?>" style="display:<?php echo ( ! empty( $st['delivered_by_name'] ) || ! empty( $st['driver_note'] ) ) ? 'flex' : 'none'; ?>;">
                            <span id="drv-stamp-txt-<?php echo esc_attr( $log_id ); ?>">
                                <?php if ( ! empty( $st['delivered_by_name'] ) ) : ?>
                                    Updated by <?php echo esc_html( $st['delivered_by_name'] ); ?><?php echo ! empty( $st['delivered_at_short'] ) ? ' • ' . esc_html( $st['delivered_at_short'] ) : ''; ?>
                                <?php endif; ?>
                            </span>
                            <span id="drv-note-txt-<?php echo esc_attr( $log_id ); ?>" style="color:#be123c;">
                                <?php if ( ! empty( $st['driver_note'] ) ) : ?>
                                    Note: <?php echo esc_html( $st['driver_note'] ); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- OPTION 4A: ISSUE / RETURN REASON MODAL -->
        <div class="drv-modal-backdrop" id="drv-issue-modal">
            <div class="drv-modal-sheet">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h3 style="margin:0; font-size:1.08rem;" id="drv-issue-modal-title">Report Delivery Issue</h3>
                    <button type="button" id="drv-close-issue-modal" style="background:none; border:none; font-size:1.4rem; color:var(--drv-text-muted); cursor:pointer;">&times;</button>
                </div>

                <div style="display:flex; gap:8px; margin-bottom:12px;">
                    <label style="flex:1; padding:10px; border:2px solid #f59e0b; border-radius:10px; background:#fffbeb; color:#92400e; font-weight:800; text-align:center; cursor:pointer;">
                        <input type="radio" name="drv_issue_status_type" value="Returned" checked style="accent-color:#d97706;"> ⚠️ Returned
                    </label>
                    <label style="flex:1; padding:10px; border:2px solid #fda4af; border-radius:10px; background:#fff1f2; color:#991b1b; font-weight:800; text-align:center; cursor:pointer;">
                        <input type="radio" name="drv_issue_status_type" value="Cancelled" style="accent-color:#dc2626;"> ✕ Cancelled
                    </label>
                </div>

                <?php if ( $cfg['issue_flow'] === '4A' ) : ?>
                    <div style="margin-bottom:10px;">
                        <div style="font-size:0.78rem; font-weight:800; color:var(--drv-text-muted); margin-bottom:6px; text-transform:uppercase;">Tap a Reason Preset (Optional):</div>
                        <div id="drv-preset-chips-wrap">
                            <?php foreach ( $cfg['presets_array'] as $preset_tag ) : ?>
                                <button type="button" class="drv-preset-chip" data-reason="<?php echo esc_attr( $preset_tag ); ?>"><?php echo esc_html( $preset_tag ); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <textarea id="drv-issue-note-input" rows="2" placeholder="Or type a short delivery note for Kitchen / FOH..." style="width:100%; padding:10px; border:1.5px solid var(--drv-border); border-radius:10px; font-family:inherit; font-size:0.9rem; margin-bottom:12px; background:var(--drv-surface-alt); color:var(--drv-text);"></textarea>
                <?php endif; ?>

                <div style="display:flex; gap:8px;">
                    <button type="button" id="drv-revert-pending-btn" style="flex:1; padding:12px; border:1px solid var(--drv-border); border-radius:10px; background:var(--drv-surface-alt); color:var(--drv-text); font-weight:800; cursor:pointer;">
                        Revert to Pending
                    </button>
                    <button type="button" id="drv-confirm-issue-btn" style="flex:2; padding:12px; border:none; border-radius:10px; background:#dc2626; color:#ffffff; font-weight:900; font-size:0.95rem; cursor:pointer;">
                        Save Issue Status
                    </button>
                </div>
            </div>
        </div>

        <!-- OPTION 9A: DRIVER PERSONAL "VIEW & DATA" SETTINGS DRAWER -->
        <?php if ( $cfg['allow_custom'] === '9A' ) : ?>
        <div class="drv-modal-backdrop" id="drv-settings-modal">
            <div class="drv-modal-sheet">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h3 style="margin:0; font-size:1.08rem;">⚙️ Driver View &amp; Data Settings</h3>
                    <button type="button" id="drv-close-settings-modal" style="background:none; border:none; font-size:1.4rem; color:var(--drv-text-muted); cursor:pointer;">&times;</button>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="font-size:0.8rem; font-weight:800; display:block; margin-bottom:5px;">1. Phone Performance &amp; Data Mode</label>
                    <select id="drv-pref-profile" style="width:100%; height:40px; border-radius:8px; border:1px solid var(--drv-border); padding:0 10px; font-weight:700; background:var(--drv-surface-alt); color:var(--drv-text);">
                        <option value="auto" <?php selected( $ui_prefs['profile'], 'auto' ); ?>>🧠 Smart Auto-Adapt (Detects Signal &amp; RAM)</option>
                        <option value="lite" <?php selected( $ui_prefs['profile'], 'lite' ); ?>>⚡ Lite / Data-Saver Mode (Fastest for Budget Phones)</option>
                        <option value="rich" <?php selected( $ui_prefs['profile'], 'rich' ); ?>>✨ Rich / Pro Mode (Full UI for Fast Phones)</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="font-size:0.8rem; font-weight:800; display:block; margin-bottom:5px;">2. Meal Bag Checklist View</label>
                    <select id="drv-pref-density" style="width:100%; height:40px; border-radius:8px; border:1px solid var(--drv-border); padding:0 10px; font-weight:700; background:var(--drv-surface-alt); color:var(--drv-text);">
                        <option value="compact" <?php selected( $ui_prefs['density'], 'compact' ); ?>>Compact (Tap bag to view meals)</option>
                        <option value="expanded" <?php selected( $ui_prefs['density'], 'expanded' ); ?>>Expanded (Always show meals &amp; allergies)</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="font-size:0.8rem; font-weight:800; display:block; margin-bottom:5px;">3. Delivery Status Controls</label>
                    <select id="drv-pref-action-ui" style="width:100%; height:40px; border-radius:8px; border:1px solid var(--drv-border); padding:0 10px; font-weight:700; background:var(--drv-surface-alt); color:var(--drv-text);">
                        <option value="2A" <?php selected( $ui_prefs['action_ui'], '2A' ); ?>>Large 1-Tap Buttons (Option 2A)</option>
                        <option value="2B" <?php selected( $ui_prefs['action_ui'], '2B' ); ?>>Full-Width Dropdown (Option 2B)</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="font-size:0.8rem; font-weight:800; display:block; margin-bottom:5px;">4. Color &amp; Readability Theme</label>
                    <select id="drv-pref-theme" style="width:100%; height:40px; border-radius:8px; border:1px solid var(--drv-border); padding:0 10px; font-weight:700; background:var(--drv-surface-alt); color:var(--drv-text);">
                        <option value="daylight" <?php selected( $ui_prefs['theme'], 'daylight' ); ?>>☀️ Daylight High-Contrast</option>
                        <option value="night" <?php selected( $ui_prefs['theme'], 'night' ); ?>>🌙 Night Shift Dark Mode</option>
                        <option value="glass" <?php selected( $ui_prefs['theme'], 'glass' ); ?>>🌿 Cycle Bistro Brand Glass</option>
                    </select>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="font-size:0.8rem; font-weight:800; display:block; margin-bottom:5px;">5. Preferred GPS Map App</label>
                    <select id="drv-pref-map" style="width:100%; height:40px; border-radius:8px; border:1px solid var(--drv-border); padding:0 10px; font-weight:700; background:var(--drv-surface-alt); color:var(--drv-text);">
                        <option value="both" <?php selected( $ui_prefs['map_app'], 'both' ); ?>>Show Both Google Maps &amp; Waze</option>
                        <option value="google" <?php selected( $ui_prefs['map_app'], 'google' ); ?>>Google Maps Only</option>
                        <option value="waze" <?php selected( $ui_prefs['map_app'], 'waze' ); ?>>Waze Only</option>
                    </select>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:800; cursor:pointer;">
                        <input type="checkbox" id="drv-pref-autosync" value="1" <?php checked( $ui_prefs['auto_sync'], 1 ); ?> style="width:18px; height:18px; accent-color:#379237;">
                        <span>Auto-Sync Kitchen Dispatch every 60 seconds</span>
                    </label>
                </div>

                <button type="button" id="drv-save-settings-btn" style="width:100%; padding:12px; background:#379237; color:#ffffff; border:none; border-radius:10px; font-size:0.95rem; font-weight:900; cursor:pointer;">
                    Save My Preferences
                </button>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script>
    (function() {
        const ajaxUrl       = '<?php echo esc_js( $ajax_url ); ?>';
        const drvNonce      = '<?php echo esc_js( $driver_nonce ); ?>';
        const selectedDate  = '<?php echo esc_js( $selected_date ); ?>';
        const offlineMode   = '<?php echo esc_js( $cfg['offline_mode'] ); ?>'; // '6A' or '6B'
        const datasaverMode = '<?php echo esc_js( $cfg['datasaver'] ); ?>';    // '7A' or '7B'
        const issueFlowMode = '<?php echo esc_js( $cfg['issue_flow'] ); ?>';   // '4A' or '4B'

        const appRoot       = document.getElementById('cmp-drv-app');
        const syncBtn       = document.getElementById('drv-sync-btn');
        const lastSyncTxt   = document.getElementById('drv-last-sync-txt');
        const netBanner     = document.getElementById('drv-net-banner');
        const netBannerTxt  = document.getElementById('drv-net-banner-text');
        const flushQueueBtn = document.getElementById('drv-flush-queue-btn');

        const QUEUE_KEY     = 'cmp_drv_sync_queue';
        const MANIFEST_PFX  = 'cmp_drv_manifest_';

        let activeFilter    = 'all';
        let activeIssueLog  = 0;
        let autoSyncTimer   = null;
        let autoSyncEnabled = <?php echo intval( $ui_prefs['auto_sync'] ) === 1 ? 'true' : 'false'; ?>;

        // Non-blocking toast notice
        function drvToast(msg, isSuccess) {
            document.querySelectorAll('.drv-toast-el').forEach(el => el.remove());
            const t = document.createElement('div');
            t.className = 'drv-toast-el';
            Object.assign(t.style, {
                position: 'fixed',
                bottom: '22px',
                left: '50%',
                transform: 'translateX(-50%)',
                background: isSuccess ? '#1B5E20' : '#dc2626',
                color: '#ffffff',
                padding: '10px 18px',
                borderRadius: '999px',
                fontSize: '0.84rem',
                fontWeight: '800',
                boxShadow: '0 8px 20px rgba(0,0,0,0.28)',
                zIndex: '10099',
                maxWidth: '90vw',
                textAlign: 'center'
            });
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3000);
        }

        // =========================================================
        // 1. ROLLING CACHE AUTO-PURGE (<100KB LIMIT - OPTION 7A)
        // =========================================================
        function manageRollingManifestCache() {
            try {
                if (datasaverMode === '7A') {
                    Object.keys(localStorage).forEach(k => {
                        if (k.indexOf(MANIFEST_PFX) === 0 && k !== (MANIFEST_PFX + selectedDate)) {
                            localStorage.removeItem(k);
                        }
                    });
                }
                if (offlineMode === '6A') {
                    const snapshot = {};
                    document.querySelectorAll('.drv-stop-card').forEach(card => {
                        const lid = card.getAttribute('data-log-id');
                        snapshot[lid] = {
                            dispatched: card.getAttribute('data-dispatched'),
                            pos: card.getAttribute('data-pos'),
                            status: card.getAttribute('data-status')
                        };
                    });
                    localStorage.setItem(MANIFEST_PFX + selectedDate, JSON.stringify(snapshot));
                }
            } catch (e) {}
        }
        manageRollingManifestCache();

        // =========================================================
        // 2. SMART AUTO-ADAPTIVE HARDWARE & SIGNAL DETECTION (10A)
        // =========================================================
        function evaluateAutoAdaptiveProfile() {
            const badge = document.getElementById('drv-active-profile-badge');
            let prefProfile = localStorage.getItem('cmp_drv_profile') || '<?php echo esc_js( $ui_prefs['profile'] ); ?>';

            if (prefProfile === 'auto') {
                const conn    = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                const slowNet = conn && (conn.saveData || conn.effectiveType === 'slow-2g' || conn.effectiveType === '2g' || conn.effectiveType === '3g');
                const lowRam  = navigator.deviceMemory && navigator.deviceMemory < 4;

                if (!navigator.onLine || slowNet || lowRam) {
                    appRoot.classList.remove('drv-profile-rich');
                    appRoot.classList.add('drv-profile-lite');
                    if (badge) badge.textContent = 'AUTO • LITE';
                } else {
                    appRoot.classList.remove('drv-profile-lite');
                    appRoot.classList.add('drv-profile-rich');
                    if (badge) badge.textContent = 'AUTO • RICH';
                }
            } else {
                appRoot.classList.toggle('drv-profile-rich', prefProfile === 'rich');
                appRoot.classList.toggle('drv-profile-lite', prefProfile !== 'rich');
                if (badge) badge.textContent = prefProfile.toUpperCase();
            }
        }
        evaluateAutoAdaptiveProfile();

        // =========================================================
        // 3. OFFLINE "STORE & FORWARD" SYNC QUEUE (OPTION 6A)
        // =========================================================
        function getOfflineQueue() {
            try {
                const raw = localStorage.getItem(QUEUE_KEY);
                return raw ? JSON.parse(raw) : [];
            } catch (e) {
                return [];
            }
        }

        function saveOfflineQueue(q) {
            try {
                localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
            } catch (e) {}
            updateNetworkBanner();
        }

        function enqueueOfflineAction(logId, subId, statusVal, noteVal) {
            const q = getOfflineQueue().filter(item => parseInt(item.log_id) !== parseInt(logId));
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const clientTs = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + ' ' +
                             pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

            q.push({
                log_id: parseInt(logId),
                sub_id: parseInt(subId || 0),
                delivery_result: statusVal,
                driver_note: noteVal || '',
                client_timestamp: clientTs
            });
            saveOfflineQueue(q);

            applyStopDomState(logId, {
                delivery_result: statusVal,
                driver_note: noteVal || '',
                delivered_by_name: 'Queued Offline ⏳',
                delivered_at_short: ''
            }, true);

            drvToast('Saved to Offline Queue (' + q.length + ' pending). Will sync automatically.', true);
        }

        function updateNetworkBanner() {
            const q = getOfflineQueue();
            if (!navigator.onLine) {
                netBanner.className = 'drv-net-banner is-offline';
                netBannerTxt.textContent = '📡 Offline Mode — ' + q.length + ' delivery update(s) queued on phone';
                flushQueueBtn.style.display = 'none';
            } else if (q.length > 0) {
                netBanner.className = 'drv-net-banner has-queue';
                netBannerTxt.textContent = '⏳ ' + q.length + ' offline delivery update(s) ready to sync';
                flushQueueBtn.style.display = 'inline-block';
            } else {
                netBanner.className = 'drv-net-banner';
            }
        }

        function flushOfflineQueue(callback) {
            const q = getOfflineQueue();
            if (q.length === 0 || !navigator.onLine) {
                if (typeof callback === 'function') callback();
                return;
            }

            flushQueueBtn.textContent = 'Syncing...';
            flushQueueBtn.disabled = true;

            const fd = new URLSearchParams();
            fd.append('action', 'cmp_driver_batch_sync');
            fd.append('nonce', drvNonce);
            fd.append('queue', JSON.stringify(q));

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (res && res.success) {
                    saveOfflineQueue([]);
                    if (res.data && Array.isArray(res.data.synced)) {
                        res.data.synced.forEach(item => {
                            applyStopDomState(item.log_id, item, false);
                        });
                    }
                    drvToast('✓ All queued offline deliveries synced!', true);
                }
            })
            .catch(() => {})
            .finally(() => {
                flushQueueBtn.textContent = 'Sync Queue Now';
                flushQueueBtn.disabled = false;
                updateNetworkBanner();
                if (typeof callback === 'function') callback();
            });
        }

        window.addEventListener('online', function() {
            evaluateAutoAdaptiveProfile();
            updateNetworkBanner();
            flushOfflineQueue(runDeltaSync);
        });
        window.addEventListener('offline', function() {
            evaluateAutoAdaptiveProfile();
            updateNetworkBanner();
        });
        if (flushQueueBtn) {
            flushQueueBtn.addEventListener('click', function() {
                flushOfflineQueue(runDeltaSync);
            });
        }
        updateNetworkBanner();

        // =========================================================
        // 4. DOM STATE UPDATER & KPI RECALCULATOR
        // =========================================================
        function recalculateKpisAndFilters() {
            let total = 0, ready = 0, done = 0, issues = 0;
            const searchVal = (document.getElementById('drv-search-input').value || '').toLowerCase().trim();

            document.querySelectorAll('.drv-stop-card').forEach(card => {
                total++;
                const disp   = parseInt(card.getAttribute('data-dispatched')) === 1;
                const status = card.getAttribute('data-status') || 'Pending';
                const shift  = card.getAttribute('data-shift') || 'morning';
                const sBlob  = card.getAttribute('data-search') || '';

                if (status === 'Successful') done++;
                else if (status === 'Returned' || status === 'Cancelled') issues++;
                else if (disp) ready++;

                let matchTab = false;
                if (activeFilter === 'all') matchTab = true;
                else if (activeFilter === 'pending_drop') matchTab = (status === 'Pending');
                else if (activeFilter === 'completed') matchTab = (status !== 'Pending');
                else if (activeFilter === shift) matchTab = true;

                const matchSearch = (searchVal === '' || sBlob.indexOf(searchVal) !== -1);
                card.classList.toggle('drv-hidden', !(matchTab && matchSearch));
            });

            document.getElementById('drv-kpi-total').textContent  = total;
            document.getElementById('drv-kpi-ready').textContent  = ready;
            document.getElementById('drv-kpi-done').textContent   = done;
            document.getElementById('drv-kpi-issues').textContent = issues;

            const pct = total > 0 ? Math.round(((done + issues) / total) * 100) : 0;
            document.getElementById('drv-progress-fill').style.width = pct + '%';
        }

        function applyStopDomState(logId, data, isOfflineQueued) {
            const card = document.getElementById('drv-card-' + logId);
            if (!card) return;

            if (typeof data.dispatch_status !== 'undefined') {
                card.setAttribute('data-dispatched', data.dispatch_status);
            }
            if (typeof data.pos_updated !== 'undefined') {
                card.setAttribute('data-pos', data.pos_updated);
            }
            if (typeof data.delivery_result !== 'undefined') {
                card.setAttribute('data-status', data.delivery_result);
            }

            const isDisp = parseInt(card.getAttribute('data-dispatched')) === 1;
            const isPos  = parseInt(card.getAttribute('data-pos')) === 1;
            const status = card.getAttribute('data-status') || 'Pending';

            card.classList.remove('state-awaiting-dispatch', 'state-ready', 'state-delivered', 'state-issue');
            const pill = document.getElementById('drv-pill-' + logId);
            if (pill) {
                pill.className = 'drv-state-pill';
                if (isOfflineQueued) {
                    card.classList.add(status === 'Successful' ? 'state-delivered' : 'state-issue');
                    pill.classList.add('pill-queued');
                    pill.textContent = '⏳ ' + status + ' (Queued)';
                } else if (status === 'Successful') {
                    card.classList.add('state-delivered');
                    pill.classList.add('pill-done');
                    pill.textContent = isPos ? '✓ POS Verified' : '✓ Delivered';
                } else if (status === 'Returned' || status === 'Cancelled') {
                    card.classList.add('state-issue');
                    pill.classList.add('pill-issue');
                    pill.textContent = '✕ ' + status;
                } else if (isDisp) {
                    card.classList.add('state-ready');
                    pill.classList.add('pill-ready');
                    pill.textContent = '🚚 Dispatched';
                } else {
                    card.classList.add('state-awaiting-dispatch');
                    pill.classList.add('pill-kitchen');
                    pill.textContent = '⏳ In Kitchen';
                }
            }

            // Toggle Stage 1 / Stage 2 / Stage 3 containers
            const lockKitchen = document.getElementById('drv-lock-kitchen-' + logId);
            const lockPos     = document.getElementById('drv-lock-pos-' + logId);
            const controls    = document.getElementById('drv-controls-' + logId);

            if (lockKitchen) lockKitchen.style.display = (!isDisp && !isPos && status === 'Pending') ? 'block' : 'none';
            if (lockPos)     lockPos.style.display     = isPos ? 'block' : 'none';
            if (controls)    controls.style.display    = ((isDisp || status !== 'Pending') && !isPos) ? 'block' : 'none';

            // Update Option 2A buttons & Option 2B select
            const btnDeliver = card.querySelector('.drv-btn-deliver');
            const btnIssue   = card.querySelector('.drv-btn-issue');
            const selStatus  = card.querySelector('.drv-status-select');

            if (btnDeliver) {
                btnDeliver.classList.toggle('is-done', status === 'Successful');
                btnDeliver.textContent = (status === 'Successful') ? '✓ Delivered (Tap to Undo)' : '✓ Mark Delivered';
            }
            if (btnIssue) {
                const isIss = (status === 'Returned' || status === 'Cancelled');
                btnIssue.classList.toggle('is-active-issue', isIss);
                btnIssue.textContent = isIss ? status : '⚠️ Issue / Return';
            }
            if (selStatus) {
                selStatus.value = status;
            }

            // Update Attribution & Note Footer
            const foot     = document.getElementById('drv-foot-' + logId);
            const stampTxt = document.getElementById('drv-stamp-txt-' + logId);
            const noteTxt  = document.getElementById('drv-note-txt-' + logId);

            if (foot && stampTxt && noteTxt) {
                const byName = data.delivered_by_name || '';
                const atTime = data.delivered_at_short || '';
                const dNote  = data.driver_note || '';

                stampTxt.textContent = byName ? ('Updated by ' + byName + (atTime ? ' • ' + atTime : '')) : '';
                noteTxt.textContent  = dNote ? ('Note: ' + dNote) : '';
                foot.style.display   = (byName || dNote) ? 'flex' : 'none';
            }

            recalculateKpisAndFilters();
        }

        // =========================================================
        // 5. SEND DELIVERY STATUS UPDATE (LIVE AJAX OR OFFLINE QUEUE)
        // =========================================================
        function submitDriverStatus(logId, newStatus, driverNote) {
            const card  = document.getElementById('drv-card-' + logId);
            const subId = card ? card.getAttribute('data-sub-id') : 0;

            if (!navigator.onLine) {
                if (offlineMode === '6A') {
                    enqueueOfflineAction(logId, subId, newStatus, driverNote);
                } else {
                    drvToast('No internet connection. Please reconnect and try again.', false);
                }
                return;
            }

            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const clientTs = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + ' ' +
                             pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

            const fd = new URLSearchParams();
            fd.append('action', 'cmp_driver_update_status');
            fd.append('nonce', drvNonce);
            fd.append('log_id', logId);
            fd.append('delivery_result', newStatus);
            fd.append('driver_note', driverNote || '');
            fd.append('client_timestamp', clientTs);

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (res && res.success) {
                    applyStopDomState(logId, res.data, false);
                    drvToast('Stop updated: ' + newStatus, true);
                    manageRollingManifestCache();
                } else {
                    drvToast((res && res.data) ? res.data : 'Update failed.', false);
                }
            })
            .catch(() => {
                if (offlineMode === '6A') {
                    enqueueOfflineAction(logId, subId, newStatus, driverNote);
                } else {
                    drvToast('Network error occurred.', false);
                }
            });
        }

        // =========================================================
        // 6. LIGHTWEIGHT DELTA JSON SYNC (~2KB - OPTION 7A)
        // =========================================================
        function runDeltaSync() {
            if (!navigator.onLine) {
                updateNetworkBanner();
                return;
            }
            if (datasaverMode === '7B') {
                window.location.reload();
                return;
            }

            if (syncBtn) {
                syncBtn.textContent = '...';
                syncBtn.disabled = true;
            }

            const fd = new URLSearchParams();
            fd.append('action', 'cmp_driver_delta_sync');
            fd.append('nonce', drvNonce);
            fd.append('selected_date', selectedDate);

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (res && res.success && res.data && res.data.deltas) {
                    const queuedIds = getOfflineQueue().map(i => parseInt(i.log_id));
                    Object.values(res.data.deltas).forEach(d => {
                        if (!queuedIds.includes(parseInt(d.log_id))) {
                            applyStopDomState(d.log_id, d, false);
                        }
                    });
                    if (lastSyncTxt && res.data.server_time) {
                        lastSyncTxt.textContent = 'Synced ' + res.data.server_time;
                    }
                    manageRollingManifestCache();
                }
            })
            .catch(() => {})
            .finally(() => {
                if (syncBtn) {
                    syncBtn.innerHTML = '&#8635; Sync';
                    syncBtn.disabled = false;
                }
            });
        }

        if (syncBtn) {
            syncBtn.addEventListener('click', function() {
                flushOfflineQueue(runDeltaSync);
            });
        }

        function configureAutoSyncTimer() {
            if (autoSyncTimer) {
                clearInterval(autoSyncTimer);
                autoSyncTimer = null;
            }
            if (autoSyncEnabled) {
                autoSyncTimer = setInterval(function() {
                    if (navigator.onLine && document.visibilityState === 'visible') {
                        flushOfflineQueue(runDeltaSync);
                    }
                }, 60000);
            }
        }
        configureAutoSyncTimer();

        // =========================================================
        // 7. CARD INTERACTIONS (2A BUTTONS, 2B DROPDOWN & 4A MODAL)
        // =========================================================
        const issueModal     = document.getElementById('drv-issue-modal');
        const issueNoteInput = document.getElementById('drv-issue-note-input');

        function openIssueModal(logId, custName, preselectStatus) {
            activeIssueLog = logId;
            document.getElementById('drv-issue-modal-title').textContent = 'Issue / Return — ' + (custName || 'Customer');
            if (issueNoteInput) issueNoteInput.value = '';
            document.querySelectorAll('.drv-preset-chip').forEach(c => c.classList.remove('selected'));

            const targetRadio = document.querySelector('input[name="drv_issue_status_type"][value="' + (preselectStatus || 'Returned') + '"]');
            if (targetRadio) targetRadio.checked = true;

            issueModal.classList.add('is-open');
        }

        document.addEventListener('click', function(e) {
            // Bag checklist expand/collapse
            const bagBtn = e.target.closest('.drv-bag-toggle');
            if (bagBtn) {
                const card = bagBtn.closest('.drv-stop-card');
                card.classList.toggle('bag-is-open');
                return;
            }

            // Option 2A: Mark Delivered (or toggle back to Pending)
            const delBtn = e.target.closest('.drv-btn-deliver');
            if (delBtn) {
                const logId = delBtn.getAttribute('data-log-id');
                const card  = document.getElementById('drv-card-' + logId);
                const curr  = card ? card.getAttribute('data-status') : 'Pending';
                const nextS = (curr === 'Successful') ? 'Pending' : 'Successful';
                submitDriverStatus(logId, nextS, '');
                return;
            }

            // Option 2A: Issue / Return Button
            const issBtn = e.target.closest('.drv-btn-issue');
            if (issBtn) {
                const logId    = issBtn.getAttribute('data-log-id');
                const custName = issBtn.getAttribute('data-cust-name');
                if (issueFlowMode === '4B') {
                    submitDriverStatus(logId, 'Returned', '');
                } else {
                    openIssueModal(logId, custName, 'Returned');
                }
                return;
            }

            // Option 4A Preset Reason Chip
            const chip = e.target.closest('.drv-preset-chip');
            if (chip) {
                chip.classList.toggle('selected');
                const picked = [];
                document.querySelectorAll('.drv-preset-chip.selected').forEach(el => picked.push(el.getAttribute('data-reason')));
                if (issueNoteInput) {
                    issueNoteInput.value = picked.join(', ');
                }
                return;
            }
        });

        // Option 2B Dropdown Change
        document.querySelectorAll('.drv-status-select').forEach(sel => {
            sel.addEventListener('change', function() {
                const logId    = this.getAttribute('data-log-id');
                const custName = this.getAttribute('data-cust-name');
                const val      = this.value;
                if ((val === 'Returned' || val === 'Cancelled') && issueFlowMode === '4A') {
                    openIssueModal(logId, custName, val);
                } else {
                    submitDriverStatus(logId, val, '');
                }
            });
        });

        const closeIssueBtn = document.getElementById('drv-close-issue-modal');
        if (closeIssueBtn) {
            closeIssueBtn.addEventListener('click', () => issueModal.classList.remove('is-open'));
        }

        const confirmIssueBtn = document.getElementById('drv-confirm-issue-btn');
        if (confirmIssueBtn) {
            confirmIssueBtn.addEventListener('click', function() {
                if (!activeIssueLog) return;
                const radio = document.querySelector('input[name="drv_issue_status_type"]:checked');
                const stVal = radio ? radio.value : 'Returned';
                const note  = issueNoteInput ? issueNoteInput.value.trim() : '';
                issueModal.classList.remove('is-open');
                submitDriverStatus(activeIssueLog, stVal, note);
            });
        }

        const revertPendingBtn = document.getElementById('drv-revert-pending-btn');
        if (revertPendingBtn) {
            revertPendingBtn.addEventListener('click', function() {
                if (!activeIssueLog) return;
                issueModal.classList.remove('is-open');
                submitDriverStatus(activeIssueLog, 'Pending', '');
            });
        }

        // =========================================================
        // 8. FILTER TABS, SEARCH & THEME / SETTINGS DRAWER (8A & 9A)
        // =========================================================
        document.querySelectorAll('.drv-tab-pill').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.drv-tab-pill').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                activeFilter = this.getAttribute('data-filter') || 'all';
                recalculateKpisAndFilters();
            });
        });

        document.getElementById('drv-search-input').addEventListener('input', recalculateKpisAndFilters);

        const themeToggleBtn = document.getElementById('drv-theme-toggle-btn');
        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', function() {
                const isNight = appRoot.classList.contains('drv-theme-night');
                appRoot.classList.remove('drv-theme-daylight', 'drv-theme-night', 'drv-theme-glass');
                const nextTheme = isNight ? 'daylight' : 'night';
                appRoot.classList.add('drv-theme-' + nextTheme);
                this.textContent = nextTheme === 'night' ? '☀️' : '🌙';
                const prefThemeSel = document.getElementById('drv-pref-theme');
                if (prefThemeSel) prefThemeSel.value = nextTheme;
            });
        }

        const settingsModal   = document.getElementById('drv-settings-modal');
        const openSettingsBtn = document.getElementById('drv-open-settings-btn');
        const closeSettings   = document.getElementById('drv-close-settings-modal');
        const saveSettingsBtn = document.getElementById('drv-save-settings-btn');

        if (openSettingsBtn && settingsModal) {
            openSettingsBtn.addEventListener('click', () => settingsModal.classList.add('is-open'));
        }
        if (closeSettings && settingsModal) {
            closeSettings.addEventListener('click', () => settingsModal.classList.remove('is-open'));
        }
        if (saveSettingsBtn && settingsModal) {
            saveSettingsBtn.addEventListener('click', function() {
                const prof    = document.getElementById('drv-pref-profile').value;
                const density = document.getElementById('drv-pref-density').value;
                const actUi   = document.getElementById('drv-pref-action-ui').value;
                const theme   = document.getElementById('drv-pref-theme').value;
                const mapApp  = document.getElementById('drv-pref-map').value;
                const autoS   = document.getElementById('drv-pref-autosync').checked ? 1 : 0;

                try {
                    localStorage.setItem('cmp_drv_profile', prof);
                } catch (e) {}

                evaluateAutoAdaptiveProfile();

                appRoot.classList.remove('drv-density-compact', 'drv-density-expanded');
                appRoot.classList.add('drv-density-' + density);

                appRoot.classList.remove('drv-action-2a', 'drv-action-2b');
                appRoot.classList.add(actUi === '2B' ? 'drv-action-2b' : 'drv-action-2a');

                appRoot.classList.remove('drv-theme-daylight', 'drv-theme-night', 'drv-theme-glass');
                appRoot.classList.add('drv-theme-' + theme);
                if (themeToggleBtn) themeToggleBtn.textContent = (theme === 'night') ? '☀️' : '🌙';

                document.querySelectorAll('.drv-map-google').forEach(el => {
                    el.style.display = (mapApp === 'waze') ? 'none' : '';
                });
                document.querySelectorAll('.drv-map-waze').forEach(el => {
                    el.style.display = (mapApp === 'google') ? 'none' : '';
                });

                autoSyncEnabled = (autoS === 1);
                configureAutoSyncTimer();

                settingsModal.classList.remove('is-open');
                drvToast('✓ View & Data preferences applied!', true);

                if (navigator.onLine) {
                    const fd = new URLSearchParams();
                    fd.append('action', 'cmp_driver_save_ui_prefs');
                    fd.append('nonce', drvNonce);
                    fd.append('profile', prof);
                    fd.append('density', density);
                    fd.append('action_ui', actUi);
                    fd.append('theme', theme);
                    fd.append('map_app', mapApp);
                    fd.append('auto_sync', autoS);
                    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(() => {});
                }
            });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
