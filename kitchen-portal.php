<?php
/**
 * Kitchen Command Center & Daily Prep Reporting Portal
 *
 * Phase 1 Upgrades & Fixes Included:
 *  - Option PREP-C (Same-Day Preparation Engine):
 *      * Auto-detects Same-Day Preparation when Receive By = "Same Day" AND Time Slot starts at or after 4:00 PM (16:00+)
 *      * Supports per-plan manual override (`prep_timing`: 'auto', 'same_day', 'day_before') for FOH, Menu Manager & Admin (never shown to customers)
 *      * Queries both Today ($selected_date) for Same-Day Prep orders and Tomorrow ($target_date) for Advance Prep orders
 *      * Displays "SAME-DAY PREP" badge without adding "(Today)" or "(Tomorrow)" after dates
 *  - Default 3-Tier Priority Sorting:
 *      1. Same Day Delivery - Same Day Preparation (Eating 10-Nov, Prep 10-Nov, Delivery 10-Nov)
 *      2. Day Before Delivery (Eating 11-Nov, Prep 10-Nov, Delivery 10-Nov)
 *      3. Same Day Delivery - Advance Preparation (Eating 11-Nov, Prep 10-Nov, Delivery 11-Nov)
 *      4. Pre-Prep Cancelled Orders (Collapsed at bottom of scheduled orders)
 *      5. Unscheduled Roster Customers (Bottom of Full Customer Roster tab)
 *  - Option UI-1 (Unified Full-Width Single-Line Banner for Collapsed Cancelled Rows):
 *      * Collapsed cancelled rows render as a clean 1-line horizontal banner across the table with [+ Expand Details ▼]
 *      * Expanded view includes [- Collapse Row ▲] and compact status pills that never overflow table borders
 *  - Responsive Desktop, Tablet & Mobile Optimizations:
 *      * Prevents header word-splitting ("Prepared", "Dispatch") on Tablet
 *      * Prevents Delivery Date badge line-wrapping ("Delivery: Thu, Nov 5")
 *      * Resets theme button letter-spacing so filter tabs and search bar align cleanly
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// Load shared per-plan logistics & database helpers from customer-portal-backend.php if available
if ( file_exists( __DIR__ . '/customer-portal-backend.php' ) ) {
    require_once __DIR__ . '/customer-portal-backend.php';
}

// ==========================================
// 0. SCHEMA & SAME-DAY PREPARATION HELPERS (OPTION PREP-C)
// ==========================================

/**
 * Ensures `prep_timing` column exists on wp_cmp_subscriptions ('auto', 'same_day', 'day_before').
 */
if ( ! function_exists( 'cmp_ensure_prep_timing_column' ) ) {
    function cmp_ensure_prep_timing_column() {
        global $wpdb;
        static $checked = false;
        if ( $checked ) {
            return;
        }
        $checked    = true;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';
        $col_check  = $wpdb->get_results( "SHOW COLUMNS FROM {$table_subs} LIKE 'prep_timing'" );
        if ( empty( $col_check ) ) {
            $wpdb->query( "ALTER TABLE {$table_subs} ADD COLUMN prep_timing VARCHAR(30) DEFAULT 'auto'" );
        }
    }
}

/**
 * Fallback Per-Plan Logistics Resolver
 */
if ( ! function_exists( 'cmp_get_subscription_logistics' ) ) {
    function cmp_get_subscription_logistics( $sub ) {
        $order    = ( ! empty( $sub->wc_order_id ) && function_exists( 'wc_get_order' ) ) ? wc_get_order( $sub->wc_order_id ) : false;
        $user_id  = intval( $sub->user_id ?? 0 );
        $sub_user = $user_id > 0 ? get_userdata( $user_id ) : false;

        $timing = ! empty( $sub->delivery_timing ) ? $sub->delivery_timing : '';
        if ( empty( $timing ) && $order ) {
            $timing = $order->get_meta( '_cmp_delivery_timing' ) ?: $order->get_meta( 'delivery_timing' );
        }
        if ( empty( $timing ) && $user_id > 0 ) {
            $timing = get_user_meta( $user_id, 'delivery_timing', true );
        }

        $time_slot = ! empty( $sub->time_slot ) ? $sub->time_slot : '';
        if ( empty( $time_slot ) && $order ) {
            $time_slot = $order->get_meta( '_cmp_time_slot' ) ?: $order->get_meta( 'time_slot' );
        }
        if ( empty( $time_slot ) && $user_id > 0 ) {
            $time_slot = get_user_meta( $user_id, 'time_slot', true );
        }

        $method = ! empty( $sub->delivery_method ) ? $sub->delivery_method : '';
        if ( empty( $method ) && $order ) {
            $method = $order->get_meta( '_cmp_logistics_method' ) ?: $order->get_meta( 'delivery_method' );
        }
        if ( empty( $method ) && $user_id > 0 ) {
            $method = get_user_meta( $user_id, 'delivery_method', true );
        }

        $pickup = ! empty( $sub->pickup_location ) ? $sub->pickup_location : '';
        if ( empty( $pickup ) && $order ) {
            $pickup = $order->get_meta( '_cmp_pickup_location' ) ?: $order->get_meta( 'pickup_location' );
        }
        if ( empty( $pickup ) && $user_id > 0 ) {
            $pickup = get_user_meta( $user_id, 'pickup_location', true );
        }

        $allergies = '';
        if ( isset( $sub->allergies ) && $sub->allergies !== null && $sub->allergies !== '' ) {
            $allergies = ( $sub->allergies === '__NONE__' ) ? '' : $sub->allergies;
        } else {
            if ( $order ) {
                $allergies = $order->get_meta( 'allergies' ) ?: $order->get_customer_note();
            }
            if ( empty( $allergies ) && $user_id > 0 ) {
                $allergies = get_user_meta( $user_id, 'allergies', true );
            }
        }

        $address = ! empty( $sub->delivery_address ) ? $sub->delivery_address : '';
        if ( empty( $address ) && $order ) {
            $addr_parts = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city() ) );
            if ( empty( $addr_parts ) ) {
                $addr_parts = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city() ) );
            }
            if ( ! empty( $addr_parts ) ) {
                $address = implode( ', ', $addr_parts );
            }
        }
        if ( empty( $address ) && $user_id > 0 ) {
            $addr_parts = array_filter( array(
                get_user_meta( $user_id, 'billing_address_1', true ),
                get_user_meta( $user_id, 'billing_address_2', true ),
                get_user_meta( $user_id, 'billing_city', true ),
            ) );
            if ( ! empty( $addr_parts ) ) {
                $address = implode( ', ', $addr_parts );
            }
        }
        if ( empty( $address ) ) {
            $address = 'Address not provided';
        }

        $fname = $user_id > 0 ? ( get_user_meta( $user_id, 'first_name', true ) ?: get_user_meta( $user_id, 'billing_first_name', true ) ) : '';
        $lname = $user_id > 0 ? ( get_user_meta( $user_id, 'last_name', true ) ?: get_user_meta( $user_id, 'billing_last_name', true ) ) : '';
        if ( $order ) {
            $fname = $order->get_billing_first_name() ?: $fname;
            $lname = $order->get_billing_last_name() ?: $lname;
        }
        $full_name = trim( $fname . ' ' . $lname );
        if ( empty( $full_name ) ) {
            $full_name = $sub_user ? $sub_user->display_name : ( $sub->display_name ?? 'Customer' );
        }

        $email = $order ? $order->get_billing_email() : ( $sub_user ? $sub_user->user_email : ( $sub->user_email ?? '' ) );
        $phone = $order ? $order->get_billing_phone() : ( $user_id > 0 ? get_user_meta( $user_id, 'billing_phone', true ) : '' );

        return array(
            'fname'     => $fname,
            'lname'     => $lname,
            'full_name' => $full_name,
            'email'     => $email,
            'phone'     => $phone,
            'method'    => $method,
            'pickup'    => $pickup,
            'timing'    => $timing,
            'time_slot' => $time_slot,
            'allergies' => $allergies,
            'address'   => $address,
        );
    }
}

/**
 * Checks whether a time slot string starts at or after 4:00 PM (16:00).
 * Examples returning true: "4:00 PM to 5:00 PM", "5:00 PM to 6:00 PM", "5:00 PM to 8:00 PM", "16:00 - 17:00"
 */
if ( ! function_exists( 'cmp_is_time_slot_after_4pm' ) ) {
    function cmp_is_time_slot_after_4pm( $time_slot ) {
        $time_slot = trim( (string) $time_slot );
        if ( $time_slot === '' ) {
            return false;
        }
        // Match the first time token (start of the slot)
        if ( preg_match( '/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?/i', $time_slot, $m ) ) {
            $hour = intval( $m[1] );
            $ampm = isset( $m[3] ) ? strtoupper( trim( $m[3] ) ) : '';

            if ( $ampm === 'PM' && $hour < 12 ) {
                $hour += 12;
            } elseif ( $ampm === 'AM' && $hour === 12 ) {
                $hour = 0;
            }
            return ( $hour >= 16 );
        }
        return false;
    }
}

/**
 * Option PREP-C Resolver: Determines if a subscription is on Same-Day Preparation.
 * - If `prep_timing` is explicitly 'same_day' -> returns true (Manual Override)
 * - If `prep_timing` is explicitly 'day_before' -> returns false (Manual Override)
 * - Otherwise ('auto' or empty) -> returns true if Receive By is Same Day AND Time Slot is >= 4:00 PM
 */
if ( ! function_exists( 'cmp_is_same_day_prep_subscription' ) ) {
    function cmp_is_same_day_prep_subscription( $sub, $resolved = null ) {
        if ( ! is_array( $resolved ) ) {
            $resolved = cmp_get_subscription_logistics( $sub );
        }

        $prep_override = isset( $sub->prep_timing ) ? strtolower( trim( (string) $sub->prep_timing ) ) : 'auto';
        if ( $prep_override === 'same_day' ) {
            return true;
        }
        if ( $prep_override === 'day_before' ) {
            return false;
        }

        $timing        = isset( $resolved['timing'] ) ? (string) $resolved['timing'] : '';
        $time_slot     = isset( $resolved['time_slot'] ) ? (string) $resolved['time_slot'] : '';
        $is_same_day   = ( stripos( $timing, 'Same Day' ) !== false );
        $is_after_4pm  = cmp_is_time_slot_after_4pm( $time_slot );

        return ( $is_same_day && $is_after_4pm );
    }
}

/**
 * Option 2B Helper: Returns structured context-aware cancellation / return details.
 */
if ( ! function_exists( 'cmp_get_kitchen_status_context' ) ) {
    function cmp_get_kitchen_status_context( $log ) {
        global $wpdb;

        $del_res = ! empty( $log->delivery_result ) ? $log->delivery_result : 'Pending';
        $is_prep = ! empty( $log->is_prepared ) ? 1 : 0;
        $is_disp = ! empty( $log->dispatch_status ) ? 1 : 0;

        $by_name = ! empty( $log->updated_by_name ) ? $log->updated_by_name : '';
        $by_role = ! empty( $log->updated_by_role ) ? $log->updated_by_role : '';
        $at_time = ( ! empty( $log->updated_at ) && $log->updated_at !== '0000-00-00 00:00:00' ) ? $log->updated_at : '';

        if ( empty( $by_name ) && ! empty( $log->subscription_id ) && ! empty( $log->target_date ) && in_array( $del_res, array( 'Cancelled', 'Returned' ), true ) ) {
            $table_activity = $wpdb->prefix . 'cmp_activity_logs';
            $act_exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_activity ) );
            if ( $act_exists === $table_activity ) {
                $like_term = ( $del_res === 'Returned' ) ? '%Return%' : '%Cancel%';
                $act_row   = $wpdb->get_row( $wpdb->prepare(
                    "SELECT actor_name, actor_role, created_at FROM {$table_activity} WHERE subscription_id = %d AND target_date = %s AND (action_type LIKE %s OR new_status LIKE %s) ORDER BY id DESC LIMIT 1",
                    intval( $log->subscription_id ),
                    $log->target_date,
                    $like_term,
                    $like_term
                ) );
                if ( $act_row ) {
                    $by_name = $act_row->actor_name;
                    $by_role = $act_row->actor_role;
                    $at_time = $act_row->created_at;
                }
            }
        }

        $is_kitchen_actor   = ( strtolower( $by_role ) === 'kitchen' );
        $is_pre_prep_cancel = ( $del_res === 'Cancelled' && $is_prep === 0 && $is_disp === 0 && ! $is_kitchen_actor );

        if ( $del_res === 'Returned' ) {
            $headline = 'DELIVERY RETURNED';
            $verb     = 'Marked Returned by ';
        } elseif ( $del_res === 'Cancelled' ) {
            $verb = 'Cancelled by ';
            if ( $is_pre_prep_cancel ) {
                $headline = 'DO NOT PREPARE';
            } elseif ( $is_prep === 1 || $is_disp === 1 ) {
                $headline = 'CANCELLED AFTER PREP';
            } else {
                $headline = 'CANCELLED BY KITCHEN';
            }
        } else {
            return array(
                'headline'           => '',
                'byline'             => '',
                'full_stamp'         => '',
                'is_pre_prep_cancel' => false,
            );
        }

        if ( ! empty( $by_name ) ) {
            $role_str = ! empty( $by_role ) ? ' (' . $by_role . ')' : '';
            $time_str = ! empty( $at_time ) ? ' on ' . date( 'd M Y, h:i A', strtotime( $at_time ) ) : '';
            $byline   = $verb . $by_name . $role_str . $time_str;
        } else {
            $byline = ( $del_res === 'Returned' ) ? 'Order returned after dispatch' : 'Cancelled prior to cut-off';
        }

        return array(
            'headline'           => $headline,
            'byline'             => $byline,
            'full_stamp'         => $headline . ' — ' . $byline,
            'is_pre_prep_cancel' => $is_pre_prep_cancel,
        );
    }
}

if ( ! function_exists( 'cmp_get_kitchen_cancelled_stamp' ) ) {
    function cmp_get_kitchen_cancelled_stamp( $log ) {
        $ctx = cmp_get_kitchen_status_context( $log );
        return ! empty( $ctx['full_stamp'] ) ? $ctx['full_stamp'] : 'Cancelled prior to cut-off';
    }
}

// ==========================================
// 1. AJAX HANDLERS: KITCHEN ACTIONS, PREP MODE OVERRIDE & EXPORT
// ==========================================

add_action( 'wp_ajax_cmp_update_prep_timing', 'cmp_ajax_update_prep_timing' );
function cmp_ajax_update_prep_timing() {
    check_ajax_referer( 'cmp_kitchen_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) {
        wp_send_json_error( 'Permission Denied. Only FOH, Menu Manager, or Admin can change preparation timing.' );
    }

    cmp_ensure_prep_timing_column();

    global $wpdb;
    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $sub_id      = isset( $_POST['sub_id'] ) ? intval( $_POST['sub_id'] ) : 0;
    $prep_timing = isset( $_POST['prep_timing'] ) ? sanitize_text_field( wp_unslash( $_POST['prep_timing'] ) ) : 'auto';

    if ( $sub_id <= 0 || ! in_array( $prep_timing, array( 'auto', 'same_day', 'day_before' ), true ) ) {
        wp_send_json_error( 'Invalid parameters.' );
    }

    $old_sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", $sub_id ) );
    if ( ! $old_sub ) {
        wp_send_json_error( 'Subscription not found.' );
    }

    $labels = array(
        'auto'       => 'Auto (Same-Day Prep if Same Day >= 4:00 PM)',
        'same_day'   => 'Same-Day Preparation (Manual Override)',
        'day_before' => 'Day-Before Preparation (Manual Override)',
    );
    $old_mode = ! empty( $old_sub->prep_timing ) ? $old_sub->prep_timing : 'auto';

    $wpdb->update( $table_subs, array( 'prep_timing' => $prep_timing ), array( 'id' => $sub_id ) );

    if ( $old_mode !== $prep_timing && function_exists( 'cmp_record_customer_activity_log' ) ) {
        cmp_record_customer_activity_log( array(
            'subscription_id' => $sub_id,
            'action_type'     => 'Kitchen Prep Schedule Updated',
            'target_date'     => date( 'Y-m-d' ),
            'old_status'      => 'Prep Schedule: ' . ( $labels[ $old_mode ] ?? $old_mode ),
            'new_status'      => 'Prep Schedule: ' . ( $labels[ $prep_timing ] ?? $prep_timing ),
        ) );
    }

    if ( $old_mode !== $prep_timing && function_exists( 'cmp_record_kitchen_activity_log' ) ) {
        cmp_record_kitchen_activity_log( array(
            'log_id'          => 0,
            'subscription_id' => $sub_id,
            'user_id'         => intval( $old_sub->user_id ),
            'target_date'     => date( 'Y-m-d' ),
            'action_type'     => 'Prep Schedule Override',
            'old_status'      => 'Prep Schedule: ' . ( $labels[ $old_mode ] ?? $old_mode ),
            'new_status'      => 'Prep Schedule: ' . ( $labels[ $prep_timing ] ?? $prep_timing ),
        ) );
    }

    wp_send_json_success( array(
        'prep_timing' => $prep_timing,
        'label'       => $labels[ $prep_timing ] ?? $prep_timing,
    ) );
}

add_action( 'wp_ajax_cmp_kitchen_action', 'cmp_ajax_kitchen_action' );
function cmp_ajax_kitchen_action() {
    check_ajax_referer( 'cmp_kitchen_nonce', 'nonce' );
    global $wpdb;

    if ( function_exists( 'cmp_auto_patch_database' ) ) {
        cmp_auto_patch_database();
    }

    $log_id = intval( $_POST['log_id'] );
    $field  = sanitize_text_field( wp_unslash( $_POST['field'] ?? '' ) );
    $value  = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

    $is_admin = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' );
    $is_chef  = current_user_can( 'kitchen_staff' );
    $is_foh   = current_user_can( 'foh_manager' );

    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $current_log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_logs} WHERE id = %d", $log_id ) );
    if ( ! $current_log ) {
        wp_send_json_error( 'Log entry not found.' );
    }

    if ( $field === 'dispatch_status' || $field === 'delivery_result' ) {
        if ( ! $is_admin && ! $is_chef ) {
            wp_send_json_error( 'Permission Denied' );
        }
        if ( $current_log && intval( $current_log->pos_updated ) === 1 ) {
            wp_send_json_error( 'Locked: FOH has already completed the POS Check.' );
        }
    } elseif ( $field === 'pos_updated' ) {
        if ( ! $is_admin && ! $is_foh ) {
            wp_send_json_error( 'Permission Denied' );
        }
    } elseif ( $field === 'is_prepared' ) {
        if ( ! $is_admin && ! $is_chef ) {
            wp_send_json_error( 'Permission Denied' );
        }
    } else {
        wp_send_json_error( 'Invalid field.' );
    }

    $actor = function_exists( 'cmp_get_current_actor_info' )
        ? cmp_get_current_actor_info( $current_log->user_id )
        : array(
            'id'   => get_current_user_id(),
            'name' => wp_get_current_user()->display_name,
            'role' => $is_admin ? 'Admin' : ( $is_foh ? 'FOH' : 'Kitchen' ),
        );

    $tz           = new DateTimeZone( 'Asia/Dubai' );
    $now_datetime = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

    $update_data = array(
        $field            => $value,
        'updated_by_id'   => $actor['id'],
        'updated_by_name' => $actor['name'],
        'updated_by_role' => $actor['role'],
        'updated_at'      => $now_datetime,
    );

    $status_headline    = '';
    $status_byline      = '';
    $is_pre_prep_cancel = false;

    if ( $field === 'delivery_result' ) {
        $action_label                    = ( $value === 'Cancelled' ) ? 'Cancelled' : 'Delivery: ' . $value;
        $update_data['last_action_type'] = $action_label;

        if ( in_array( $value, array( 'Cancelled', 'Returned' ), true ) ) {
            $update_data['pos_updated'] = 0;
        }

        $sim_log                  = clone $current_log;
        $sim_log->delivery_result = $value;
        $sim_log->updated_by_name = $actor['name'];
        $sim_log->updated_by_role = $actor['role'];
        $sim_log->updated_at      = $now_datetime;

        $ctx                = cmp_get_kitchen_status_context( $sim_log );
        $status_headline    = $ctx['headline'];
        $status_byline      = $ctx['byline'];
        $is_pre_prep_cancel = $ctx['is_pre_prep_cancel'];

        if ( function_exists( 'cmp_record_customer_activity_log' ) && $current_log->delivery_result !== $value ) {
            $old_snap = function_exists( 'cmp_format_log_meal_snapshot' )
                ? cmp_format_log_meal_snapshot( $current_log )
                : 'Delivery: ' . ( $current_log->delivery_result ?: 'Pending' );

            $new_snap_label = ( $value === 'Cancelled' )
                ? $status_headline . ' (' . $current_log->target_date . ')'
                : 'Delivery Status: ' . $value . ' (' . $current_log->target_date . ')';

            cmp_record_customer_activity_log( array(
                'subscription_id' => intval( $current_log->subscription_id ),
                'action_type'     => ( $value === 'Cancelled' ) ? 'Meal Cancelled' : ( $value === 'Returned' ? 'Delivery Returned' : 'Delivery Status Updated' ),
                'target_date'     => $current_log->target_date,
                'old_status'      => $old_snap,
                'new_status'      => $new_snap_label,
                'actor_id'        => $actor['id'],
                'actor_name'      => $actor['name'],
                'actor_role'      => $actor['role'],
            ) );
        }
    }

    $wpdb->update( $table_logs, $update_data, array( 'id' => $log_id ) );

    wp_send_json_success( array(
        'status_headline'    => $status_headline,
        'status_byline'      => $status_byline,
        'cancelled_stamp'    => $status_byline,
        'is_pre_prep_cancel' => $is_pre_prep_cancel ? 1 : 0,
    ) );
}

add_action( 'wp_ajax_cmp_assign_chef_meals', 'cmp_ajax_assign_chef_meals' );
function cmp_ajax_assign_chef_meals() {
    check_ajax_referer( 'cmp_kitchen_nonce', 'nonce' );
    global $wpdb;

    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'kitchen_staff' ) && ! current_user_can( 'menu_manager' ) ) {
        wp_send_json_error( 'Permission Denied' );
    }

    if ( function_exists( 'cmp_auto_patch_database' ) ) {
        cmp_auto_patch_database();
    }

    $log_id      = intval( $_POST['log_id'] );
    $table_foods = $wpdb->prefix . 'cmp_foods';
    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';

    $log_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_logs} WHERE id = %d", $log_id ) );
    if ( ! $log_row ) {
        wp_send_json_error( 'Log entry not found.' );
    }

    $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", $log_row->subscription_id ) );

    $log_mode = ! empty( $log_row->plan_mode ) ? $log_row->plan_mode : null;
    if ( ! $log_mode && $sub ) {
        if ( ! empty( $sub->allow_custom ) )    $log_mode = 'custom';
        elseif ( ! empty( $sub->is_flexible ) ) $log_mode = 'flexible';
        else                                    $log_mode = 'standard';
    }

    $has_custom = ! empty( $_POST['custom_breakfast'] ) || ! empty( $_POST['custom_lunch'] ) || ! empty( $_POST['custom_dinner'] ) || ! empty( $_POST['custom_snack_1'] ) || ! empty( $_POST['custom_snack_2'] ) || ! empty( $_POST['custom_juice_1'] );
    $has_meals  = ! empty( $_POST['breakfast'] ) || ! empty( $_POST['lunch'] ) || ! empty( $_POST['dinner'] ) || ! empty( $_POST['snack_1'] ) || ! empty( $_POST['snack_2'] ) || ! empty( $_POST['juice_1'] );

    if ( $has_meals && ! $has_custom ) {
        $food_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_foods WHERE is_active = 1 AND valid_from <= %s AND valid_until >= %s", $log_row->target_date, $log_row->target_date ) );
        if ( intval( $food_exists ) === 0 ) {
            wp_send_json_error( 'The menu for this date is pending release. Meals cannot be assigned yet.' );
        }
    }

    if ( $sub ) {
        $is_juice = ( stripos( $sub->allowed_categories, 'Juices' ) !== false || stripos( $sub->plan_name, 'juice' ) !== false || stripos( $sub->plan_name, 'cleanse' ) !== false );
        preg_match( '/(\d+)\s*Meal/i', $sub->plan_name, $m );
        $allowed_quota = isset( $m[1] ) ? intval( $m[1] ) : 0;

        if ( ! $is_juice && $allowed_quota > 0 ) {
            $submitted_count = 0;
            if ( ! empty( $_POST['breakfast'] ) || ! empty( $_POST['custom_breakfast'] ) ) $submitted_count++;
            if ( ! empty( $_POST['lunch'] )     || ! empty( $_POST['custom_lunch'] ) )     $submitted_count++;
            if ( ! empty( $_POST['dinner'] )    || ! empty( $_POST['custom_dinner'] ) )    $submitted_count++;

            if ( $submitted_count > $allowed_quota ) {
                wp_send_json_error( "Quota Exceeded! This customer's plan only allows {$allowed_quota} main meal(s)." );
            }
        }
    }

    $old_snapshot = function_exists( 'cmp_format_log_meal_snapshot' )
        ? cmp_format_log_meal_snapshot( $log_row )
        : "Chef's Choice (Pending Assignment)";

    $actor = function_exists( 'cmp_get_current_actor_info' )
        ? cmp_get_current_actor_info( $log_row->user_id )
        : array(
            'id'   => get_current_user_id(),
            'name' => wp_get_current_user()->display_name,
            'role' => 'Kitchen',
        );

    $tz           = new DateTimeZone( 'Asia/Dubai' );
    $now_datetime = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

    $data = array(
        'plan_mode'        => $log_mode ?: 'standard',
        'breakfast_id'     => ! empty( $_POST['breakfast'] ) ? intval( $_POST['breakfast'] ) : null,
        'lunch_id'         => ! empty( $_POST['lunch'] ) ? intval( $_POST['lunch'] ) : null,
        'dinner_id'        => ! empty( $_POST['dinner'] ) ? intval( $_POST['dinner'] ) : null,
        'snack_1_id'       => ! empty( $_POST['snack_1'] ) ? intval( $_POST['snack_1'] ) : null,
        'snack_2_id'       => ! empty( $_POST['snack_2'] ) ? intval( $_POST['snack_2'] ) : null,
        'juice_1_id'       => ! empty( $_POST['juice_1'] ) ? intval( $_POST['juice_1'] ) : null,
        'juice_2_id'       => ! empty( $_POST['juice_2'] ) ? intval( $_POST['juice_2'] ) : null,
        'juice_3_id'       => ! empty( $_POST['juice_3'] ) ? intval( $_POST['juice_3'] ) : null,

        'custom_breakfast' => ! empty( $_POST['custom_breakfast'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_breakfast'] ) ) : null,
        'custom_lunch'     => ! empty( $_POST['custom_lunch'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_lunch'] ) ) : null,
        'custom_dinner'    => ! empty( $_POST['custom_dinner'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_dinner'] ) ) : null,
        'custom_snack_1'   => ! empty( $_POST['custom_snack_1'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_snack_1'] ) ) : null,
        'custom_snack_2'   => ! empty( $_POST['custom_snack_2'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_snack_2'] ) ) : null,
        'custom_juice_1'   => ! empty( $_POST['custom_juice_1'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_juice_1'] ) ) : null,
        'custom_juice_2'   => ! empty( $_POST['custom_juice_2'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_juice_2'] ) ) : null,
        'custom_juice_3'   => ! empty( $_POST['custom_juice_3'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_juice_3'] ) ) : null,

        'updated_by_id'    => $actor['id'],
        'updated_by_name'  => $actor['name'],
        'updated_by_role'  => $actor['role'],
        'last_action_type' => 'Chef Assigned',
        'updated_at'       => $now_datetime,
    );

    $wpdb->update( $table_logs, $data, array( 'id' => $log_id ) );

    $updated_log  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_logs} WHERE id = %d", $log_id ) );
    $new_snapshot = function_exists( 'cmp_format_log_meal_snapshot' )
        ? cmp_format_log_meal_snapshot( $updated_log )
        : 'Chef Assigned (' . $log_row->target_date . ')';

    if ( function_exists( 'cmp_record_customer_activity_log' ) ) {
        cmp_record_customer_activity_log( array(
            'subscription_id' => intval( $log_row->subscription_id ),
            'action_type'     => "Chef's Choice Assigned",
            'target_date'     => $log_row->target_date,
            'old_status'      => $old_snapshot,
            'new_status'      => $new_snapshot,
            'actor_id'        => $actor['id'],
            'actor_name'      => $actor['name'],
            'actor_role'      => $actor['role'],
        ) );
    }

    wp_send_json_success();
}

add_action( 'wp_ajax_cmp_export_kitchen_csv', 'cmp_export_kitchen_csv' );
function cmp_export_kitchen_csv() {
    if ( ! is_user_logged_in() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'kitchen_staff' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) ) {
        wp_die( 'Permission Denied' );
    }

    if ( function_exists( 'cmp_ensure_subscription_logistics_columns' ) ) {
        cmp_ensure_subscription_logistics_columns();
    }
    cmp_ensure_prep_timing_column();

    global $wpdb;
    date_default_timezone_set( 'Asia/Dubai' );

    $prep_date   = isset( $_GET['prep_date'] ) ? sanitize_text_field( $_GET['prep_date'] ) : date( 'Y-m-d' );
    $export_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'meals';
    $target_date = date( 'Y-m-d', strtotime( $prep_date . ' + 1 day' ) );
    $today_dt    = date( 'Y-m-d H:i:s' );

    // Fetch candidate logs for both $prep_date (for Same-Day Prep plans) and $target_date (for Advance Prep plans)
    $raw_logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, s.expiry_date, s.foh_remarks, s.is_flexible, s.allow_custom,
                s.delivery_method, s.pickup_location, s.delivery_timing, s.time_slot, s.allergies, s.delivery_address, s.prep_timing,
                u.display_name, u.user_email 
         FROM {$wpdb->prefix}cmp_daily_logs l 
         JOIN {$wpdb->prefix}cmp_subscriptions s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON l.user_id = u.ID 
         WHERE l.target_date IN (%s, %s) AND l.is_locked = 1 AND s.status != 'paused'
         ORDER BY l.id ASC",
        $prep_date,
        $target_date
    ) );

    $foods    = $wpdb->get_results( "SELECT id, food_name FROM {$wpdb->prefix}cmp_foods" );
    $food_map = array();
    foreach ( $foods as $f ) {
        $food_map[ $f->id ] = $f->food_name;
    }

    $filename_suffix = 'Meal_Plans';
    if ( $export_type === 'juices' ) {
        $filename_suffix = 'Juice_Plans';
    } elseif ( $export_type === 'roster' ) {
        $filename_suffix = 'Full_Customer_Roster';
    }

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="Kitchen_Report_' . $filename_suffix . '_' . $prep_date . '.csv"' );
    $output = fopen( 'php://output', 'w' );
    fputs( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

    if ( $export_type === 'juices' ) {
        fputcsv( $output, array( 'Order ID', 'Customer Name', 'Recipient Name', 'Email', 'Phone', 'Prep Schedule', 'Eating Date', 'Address', 'Method', 'Receive By', 'Delivery Date', 'Time Slot', 'Allergies', 'FOH Note', 'Plan', 'Juice 1', 'Juice 2', 'Juice 3', 'Prepared', 'Dispatched', 'Delivery / Cancellation Info', 'POS Check' ) );
    } else {
        fputcsv( $output, array( 'Order ID', 'Customer Name', 'Recipient Name', 'Email', 'Phone', 'Prep Schedule', 'Eating Date', 'Address', 'Method', 'Receive By', 'Delivery Date', 'Time Slot', 'Allergies', 'FOH Note', 'Plan', 'Breakfast / Meal 1', 'Lunch / Meal 2', 'Dinner / Meal 3', 'Snack 1', 'Snack 2', 'Prepared', 'Dispatched', 'Delivery / Cancellation Info', 'POS Check' ) );
    }

    $export_rows       = array();
    $scheduled_sub_ids = array();

    foreach ( $raw_logs as $log ) {
        $resolved         = cmp_get_subscription_logistics( $log );
        $is_same_day_prep = cmp_is_same_day_prep_subscription( $log, $resolved );

        // Match log target_date to the subscription's Prep Schedule for this $prep_date
        if ( $is_same_day_prep && $log->target_date !== $prep_date ) {
            continue;
        }
        if ( ! $is_same_day_prep && $log->target_date !== $target_date ) {
            continue;
        }

        $scheduled_sub_ids[ intval( $log->subscription_id ) ] = true;
        $is_juice_plan = ( stripos( $log->allowed_categories, 'Juices' ) !== false || stripos( $log->plan_name, 'juice' ) !== false || stripos( $log->plan_name, 'cleanse' ) !== false );

        if ( $export_type === 'juices' && ! $is_juice_plan ) continue;
        if ( $export_type === 'meals'  && $is_juice_plan )   continue;

        $full_name = $resolved['full_name'];
        $email     = $resolved['email'];
        $phone     = $resolved['phone'];
        $address   = $resolved['address'];

        $timing        = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'N/A';
        $timing        = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing );
        $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );
        $delivery_date = $is_same_day_prep ? $prep_date : ( $is_day_before ? $prep_date : $log->target_date );
        $prep_label    = $is_same_day_prep ? 'Same-Day Prep' : 'Advance Prep (Day Before)';

        $time_slot = ! empty( $resolved['time_slot'] ) ? $resolved['time_slot'] : 'N/A';
        $method    = ! empty( $resolved['method'] ) ? $resolved['method'] : 'N/A';
        $pickup    = $resolved['pickup'];
        if ( $method === 'Pickup' && ! empty( $pickup ) ) {
            $method .= ' (' . $pickup . ')';
        }

        $allergies    = ! empty( $resolved['allergies'] ) ? $resolved['allergies'] : 'No Allergies';
        $is_assigned  = ( $log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1 );
        $is_cancelled = ( $log->delivery_result === 'Cancelled' );
        $is_returned  = ( $log->delivery_result === 'Returned' );
        $status_ctx   = cmp_get_kitchen_status_context( $log );
        $is_pre_canc  = $status_ctx['is_pre_prep_cancel'];

        $chef_tag    = $log->is_chefs_choice ? ' (Chef)' : '';
        $pending_tag = '[Pending Chef]';

        $target_ymd = date( 'Y-m-d', strtotime( $log->target_date ) );
        $expiry_ymd = date( 'Y-m-d', strtotime( $log->expiry_date ) );
        $is_expired = ( $target_ymd > $expiry_ymd );

        $prepared   = $is_pre_canc ? 'Exempt' : ( $log->is_prepared ? 'Yes' : 'No' );
        $dispatched = $is_pre_canc ? 'Exempt' : ( $log->dispatch_status ? 'Yes' : 'No' );
        $delivery   = ( $is_cancelled || $is_returned ) ? $status_ctx['full_stamp'] : ( $log->delivery_result ?: 'Pending' );
        $pos_check  = ( $is_cancelled || $is_returned ) ? 'No POS Required' : ( $log->pos_updated ? 'Yes' : 'No' );

        $day_number = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(id) FROM {$wpdb->prefix}cmp_daily_logs 
             WHERE subscription_id = %d AND target_date <= %s 
             AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))",
            $log->subscription_id,
            $log->target_date
        ) );

        $plan_parts          = explode( ' - ', $log->plan_name );
        $potential_recipient = end( $plan_parts );
        $recipient_name      = '';
        if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', $potential_recipient ) && count( $plan_parts ) > 1 ) {
            $recipient_name = $potential_recipient;
            array_pop( $plan_parts );
            $core_plan_name = implode( ' - ', $plan_parts );
        } else {
            $core_plan_name = $log->plan_name;
        }

        $plan_string = $core_plan_name . " ({$day_number}/{$log->total_days})";

        $order_id_display = 'Manual';
        if ( $log->wc_order_id < 0 ) {
            $order_id_display = 'M-' . str_pad( abs( $log->wc_order_id ), 4, '0', STR_PAD_LEFT );
        } elseif ( $log->wc_order_id > 0 ) {
            $order_id_display = '#' . $log->wc_order_id;
        } else {
            $order_id_display = 'M-' . str_pad( $log->subscription_id, 4, '0', STR_PAD_LEFT );
        }

        $foh_note = ! empty( $log->foh_remarks ) ? wp_strip_all_tags( $log->foh_remarks ) : 'None';

        // 3-Tier Priority Group: 1 = Same-Day Prep, 2 = Day Before Delivery, 3 = Same Day Delivery (Advance Prep)
        $priority_tier = $is_same_day_prep ? 1 : ( $is_day_before ? 2 : 3 );

        if ( $export_type === 'juices' ) {
            $j1 = '-';
            $j2 = '-';
            $j3 = '-';
            if ( $is_cancelled ) {
                $j1 = $status_ctx['headline'];
                $j2 = $status_ctx['headline'];
                $j3 = $status_ctx['headline'];
            } elseif ( $is_expired ) {
                $j1 = 'PLAN EXPIRED';
                $j2 = 'PLAN EXPIRED';
                $j3 = 'PLAN EXPIRED';
            } elseif ( $log->is_chefs_choice && ! $is_assigned ) {
                $j1 = $pending_tag;
                $j2 = $pending_tag;
                $j3 = $pending_tag;
            } else {
                if ( $log->custom_juice_1 ) $j1 = $log->custom_juice_1 . $chef_tag;
                elseif ( $log->juice_1_id ) $j1 = ( $food_map[ $log->juice_1_id ] ?? 'Unknown' ) . $chef_tag;

                if ( $log->custom_juice_2 ) $j2 = $log->custom_juice_2 . $chef_tag;
                elseif ( $log->juice_2_id ) $j2 = ( $food_map[ $log->juice_2_id ] ?? 'Unknown' ) . $chef_tag;

                if ( $log->custom_juice_3 ) $j3 = $log->custom_juice_3 . $chef_tag;
                elseif ( $log->juice_3_id ) $j3 = ( $food_map[ $log->juice_3_id ] ?? 'Unknown' ) . $chef_tag;
            }
            $row_data = array( $order_id_display, $full_name, $recipient_name, $email, $phone, $prep_label, $log->target_date, $address, $method, $timing, $delivery_date, $time_slot, $allergies, $foh_note, $plan_string, $j1, $j2, $j3, $prepared, $dispatched, $delivery, $pos_check );
        } else {
            $b  = '-';
            $l  = '-';
            $d  = '-';
            $s1 = '-';
            $s2 = '-';
            if ( $is_cancelled ) {
                $b  = $status_ctx['headline'];
                $l  = $status_ctx['headline'];
                $d  = $status_ctx['headline'];
                $s1 = $status_ctx['headline'];
                $s2 = $status_ctx['headline'];
            } elseif ( $is_expired ) {
                $b  = 'PLAN EXPIRED';
                $l  = 'PLAN EXPIRED';
                $d  = 'PLAN EXPIRED';
                $s1 = 'PLAN EXPIRED';
                $s2 = 'PLAN EXPIRED';
            } elseif ( $log->is_chefs_choice && ! $is_assigned ) {
                $b  = $pending_tag;
                $l  = $pending_tag;
                $d  = $pending_tag;
                $s1 = $pending_tag;
                $s2 = $pending_tag;
            } else {
                $b  = ! empty( $log->custom_breakfast ) ? $log->custom_breakfast : ( $log->breakfast_id ? ( $food_map[ $log->breakfast_id ] ?? 'Unknown' ) . $chef_tag : '-' );
                $l  = ! empty( $log->custom_lunch )     ? $log->custom_lunch     : ( $log->lunch_id     ? ( $food_map[ $log->lunch_id ]     ?? 'Unknown' ) . $chef_tag : '-' );
                $d  = ! empty( $log->custom_dinner )    ? $log->custom_dinner    : ( $log->dinner_id    ? ( $food_map[ $log->dinner_id ]    ?? 'Unknown' ) . $chef_tag : '-' );
                $s1 = ! empty( $log->custom_snack_1 )   ? $log->custom_snack_1   : ( $log->snack_1_id   ? ( $food_map[ $log->snack_1_id ]   ?? 'Unknown' ) . $chef_tag : '-' );
                $s2 = ! empty( $log->custom_snack_2 )   ? $log->custom_snack_2   : ( $log->snack_2_id   ? ( $food_map[ $log->snack_2_id ]   ?? 'Unknown' ) . $chef_tag : '-' );
            }
            $row_data = array( $order_id_display, $full_name, $recipient_name, $email, $phone, $prep_label, $log->target_date, $address, $method, $timing, $delivery_date, $time_slot, $allergies, $foh_note, $plan_string, $b, $l, $d, $s1, $s2, $prepared, $dispatched, $delivery, $pos_check );
        }

        $export_rows[] = array(
            'data'          => $row_data,
            'priority_tier' => $priority_tier,
            'is_scheduled'  => 1,
            'is_pre_canc'   => $is_pre_canc ? 1 : 0,
        );
    }

    if ( $export_type === 'roster' ) {
        $roster_subs = $wpdb->get_results(
            "SELECT s.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}cmp_subscriptions s
             JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
             WHERE s.status IN ('active', 'paused')
             ORDER BY s.id DESC"
        );

        if ( $roster_subs ) {
            foreach ( $roster_subs as $sub ) {
                if ( isset( $scheduled_sub_ids[ intval( $sub->id ) ] ) ) {
                    continue;
                }
                $is_paused_sub = ( $sub->status === 'paused' );
                $usage_days    = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}cmp_daily_logs WHERE subscription_id = %d AND delivery_result = 'Successful'",
                    $sub->id
                ) );

                if ( ! $is_paused_sub && ( $usage_days >= intval( $sub->total_days ) || $sub->expiry_date < $today_dt ) ) {
                    continue;
                }

                $resolved          = cmp_get_subscription_logistics( $sub );
                $is_same_day_prep  = cmp_is_same_day_prep_subscription( $sub, $resolved );
                $expected_eat_date = $is_same_day_prep ? $prep_date : $target_date;
                $prep_label        = $is_same_day_prep ? 'Same-Day Prep' : 'Advance Prep (Day Before)';

                $timing        = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'N/A';
                $timing        = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing );
                $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );
                $delivery_date = $is_same_day_prep ? $prep_date : ( $is_day_before ? $prep_date : $target_date );
                $time_slot     = ! empty( $resolved['time_slot'] ) ? $resolved['time_slot'] : 'N/A';
                $method        = ! empty( $resolved['method'] ) ? $resolved['method'] : 'N/A';
                if ( $method === 'Pickup' && ! empty( $resolved['pickup'] ) ) {
                    $method .= ' (' . $resolved['pickup'] . ')';
                }
                $allergies = ! empty( $resolved['allergies'] ) ? $resolved['allergies'] : 'No Allergies';

                $plan_parts          = explode( ' - ', $sub->plan_name );
                $potential_recipient = end( $plan_parts );
                $recipient_name      = '';
                if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', $potential_recipient ) && count( $plan_parts ) > 1 ) {
                    $recipient_name = $potential_recipient;
                    array_pop( $plan_parts );
                    $core_plan_name = implode( ' - ', $plan_parts );
                } else {
                    $core_plan_name = $sub->plan_name;
                }
                $plan_string = $core_plan_name . " ({$usage_days}/{$sub->total_days} Used)" . ( $is_paused_sub ? ' [PAUSED]' : '' );

                $order_id_display = ( $sub->wc_order_id < 0 )
                    ? 'M-' . str_pad( abs( $sub->wc_order_id ), 4, '0', STR_PAD_LEFT )
                    : ( $sub->wc_order_id > 0 ? '#' . $sub->wc_order_id : 'M-' . str_pad( $sub->id, 4, '0', STR_PAD_LEFT ) );

                $foh_note          = ! empty( $sub->foh_remarks ) ? wp_strip_all_tags( $sub->foh_remarks ) : 'None';
                $roster_status_txt = $is_paused_sub ? 'Plan Paused (No Food Selection Today)' : 'No Food Selection Today';
                $priority_tier     = $is_same_day_prep ? 1 : ( $is_day_before ? 2 : 3 );

                $row_data = array(
                    $order_id_display,
                    $resolved['full_name'],
                    $recipient_name,
                    $resolved['email'],
                    $resolved['phone'],
                    $prep_label,
                    $expected_eat_date,
                    $resolved['address'],
                    $method,
                    $timing,
                    $delivery_date,
                    $time_slot,
                    $allergies,
                    $foh_note,
                    $plan_string,
                    $roster_status_txt,
                    '-',
                    '-',
                    '-',
                    '-',
                    'N/A',
                    'N/A',
                    $roster_status_txt,
                    'N/A',
                );

                $export_rows[] = array(
                    'data'          => $row_data,
                    'priority_tier' => $priority_tier,
                    'is_scheduled'  => 0,
                    'is_pre_canc'   => 0,
                );
            }
        }
    }

    usort( $export_rows, function( $a, $b ) {
        if ( $a['is_scheduled'] !== $b['is_scheduled'] ) {
            return $b['is_scheduled'] - $a['is_scheduled'];
        }
        if ( $a['is_pre_canc'] !== $b['is_pre_canc'] ) {
            return $a['is_pre_canc'] - $b['is_pre_canc'];
        }
        return $a['priority_tier'] - $b['priority_tier'];
    } );

    foreach ( $export_rows as $er ) {
        fputcsv( $output, $er['data'] );
    }
    fclose( $output );
    exit;
}

// ==========================================
// 2. KITCHEN REPORTING PORTAL
// ==========================================
add_shortcode( 'meal_kitchen_portal', 'cmp_render_kitchen_portal' );
function cmp_render_kitchen_portal() {
    date_default_timezone_set( 'Asia/Dubai' );

    $is_admin = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' );
    $is_chef  = current_user_can( 'kitchen_staff' );
    $is_foh   = current_user_can( 'foh_manager' );

    if ( ! is_user_logged_in() || ( ! $is_admin && ! $is_chef && ! $is_foh ) ) {
        if ( function_exists( 'cmp_render_portal_login_ui' ) ) {
            return cmp_render_portal_login_ui( array(
                'portal_id'      => 'kitchen',
                'title'          => 'Kitchen Portal',
                'subtitle'       => 'Daily Meal Prep, Chef Assignments & Dispatch',
                'required_caps'  => array( 'manage_options', 'menu_manager', 'kitchen_staff', 'foh_manager' ),
                'denied_message' => 'Kitchen Staff, Menu Manager, or FOH clearance is required to access this portal.',
            ) );
        }
    }

    if ( function_exists( 'cmp_auto_patch_database' ) ) {
        cmp_auto_patch_database();
    }
    if ( function_exists( 'cmp_ensure_subscription_logistics_columns' ) ) {
        cmp_ensure_subscription_logistics_columns();
    }
    cmp_ensure_prep_timing_column();

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $today_ymd     = date( 'Y-m-d' );
    $today_dt      = date( 'Y-m-d H:i:s' );
    $selected_date = isset( $_GET['prep_date'] ) ? sanitize_text_field( $_GET['prep_date'] ) : $today_ymd;
    $target_date   = date( 'Y-m-d', strtotime( $selected_date . ' + 1 day' ) );
    $prev_date     = date( 'Y-m-d', strtotime( $selected_date . ' - 1 day' ) );
    $next_date     = date( 'Y-m-d', strtotime( $selected_date . ' + 1 day' ) );

    // Fetch candidate locked logs for $selected_date (Same-Day Prep) and $target_date (Advance Prep)
    $raw_logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, s.foh_remarks, s.expiry_date, s.is_flexible, s.allow_custom,
                s.delivery_method, s.pickup_location, s.delivery_timing, s.time_slot, s.allergies, s.delivery_address, s.prep_timing,
                u.display_name, u.user_email 
         FROM {$table_logs} l 
         JOIN {$table_subs} s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON l.user_id = u.ID 
         WHERE l.target_date IN (%s, %s) AND l.is_locked = 1 AND s.status != 'paused'
         ORDER BY l.id ASC",
        $selected_date,
        $target_date
    ) );

    $foods    = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cmp_foods WHERE is_active = 1 ORDER BY category_name, food_name" );
    $food_map = array();
    foreach ( $foods as $f ) {
        $food_map[ $f->id ] = $f->food_name;
    }

    $customers         = array();
    $scheduled_sub_ids = array();

    foreach ( $raw_logs as $log ) {
        $resolved         = cmp_get_subscription_logistics( $log );
        $is_same_day_prep = cmp_is_same_day_prep_subscription( $log, $resolved );

        // Option PREP-C Filtering:
        // - If subscription is Same-Day Prep, it is prepared on the same day as its eating date ($selected_date).
        // - If subscription is Advance Prep (Day Before), it is prepared 1 day before its eating date ($target_date).
        if ( $is_same_day_prep && $log->target_date !== $selected_date ) {
            continue;
        }
        if ( ! $is_same_day_prep && $log->target_date !== $target_date ) {
            continue;
        }

        $scheduled_sub_ids[ intval( $log->subscription_id ) ] = true;

        $full_name = $resolved['full_name'];
        $email     = $resolved['email'];
        $phone     = $resolved['phone'];
        $address   = $resolved['address'];

        $timing        = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'N/A';
        $timing        = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing );
        $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );
        $delivery_date = $is_same_day_prep ? $selected_date : ( $is_day_before ? $selected_date : $log->target_date );

        $time_slot = ! empty( $resolved['time_slot'] ) ? $resolved['time_slot'] : 'N/A';
        $method    = ! empty( $resolved['method'] ) ? $resolved['method'] : 'N/A';
        $pickup    = $resolved['pickup'];
        if ( $method === 'Pickup' && ! empty( $pickup ) ) {
            $method .= ' (' . $pickup . ')';
        }

        $allergies = ! empty( $resolved['allergies'] ) ? $resolved['allergies'] : 'No Allergies';

        $log_mode = ! empty( $log->plan_mode ) ? $log->plan_mode : null;
        if ( $log_mode ) {
            $is_flexible  = ( $log_mode === 'flexible' ) ? 1 : 0;
            $allow_custom = ( $log_mode === 'custom' ) ? 1 : 0;
        } else {
            $is_flexible  = isset( $log->is_flexible ) ? intval( $log->is_flexible ) : 0;
            $allow_custom = isset( $log->allow_custom ) ? intval( $log->allow_custom ) : 0;
        }

        $meals_list   = array();
        $is_assigned  = ( $log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1 );
        $is_cancelled = ( $log->delivery_result === 'Cancelled' );
        $is_returned  = ( $log->delivery_result === 'Returned' );

        $l_bf = $is_flexible ? 'Meal 1' : 'Breakfast';
        $l_lu = $is_flexible ? 'Meal 2' : 'Lunch';
        $l_di = $is_flexible ? 'Meal 3' : 'Dinner';

        if ( $log->is_chefs_choice && ! $is_assigned ) {
            $meals_list[] = "Chef's Choice (Pending Assignment)";
        } elseif ( $log->juice_1_id || $log->custom_juice_1 ) {
            if ( $log->custom_juice_1 ) $meals_list[] = 'Juice 1: ' . esc_html( $log->custom_juice_1 );
            elseif ( $log->juice_1_id ) $meals_list[] = 'Juice 1: ' . ( $food_map[ $log->juice_1_id ] ?? 'Unknown' );

            if ( $log->custom_juice_2 ) $meals_list[] = 'Juice 2: ' . esc_html( $log->custom_juice_2 );
            elseif ( $log->juice_2_id ) $meals_list[] = 'Juice 2: ' . ( $food_map[ $log->juice_2_id ] ?? 'Unknown' );

            if ( $log->custom_juice_3 ) $meals_list[] = 'Juice 3: ' . esc_html( $log->custom_juice_3 );
            elseif ( $log->juice_3_id ) $meals_list[] = 'Juice 3: ' . ( $food_map[ $log->juice_3_id ] ?? 'Unknown' );
        } else {
            if ( $log->custom_breakfast ) $meals_list[] = "$l_bf: " . esc_html( $log->custom_breakfast );
            elseif ( $log->breakfast_id ) $meals_list[] = "$l_bf: " . ( $food_map[ $log->breakfast_id ] ?? 'Unknown' );

            if ( $log->custom_lunch )     $meals_list[] = "$l_lu: " . esc_html( $log->custom_lunch );
            elseif ( $log->lunch_id )     $meals_list[] = "$l_lu: " . ( $food_map[ $log->lunch_id ] ?? 'Unknown' );

            if ( $log->custom_dinner )    $meals_list[] = "$l_di: " . esc_html( $log->custom_dinner );
            elseif ( $log->dinner_id )    $meals_list[] = "$l_di: " . ( $food_map[ $log->dinner_id ] ?? 'Unknown' );

            if ( $log->custom_snack_1 )   $meals_list[] = 'Snack 1: ' . esc_html( $log->custom_snack_1 );
            elseif ( $log->snack_1_id )   $meals_list[] = 'Snack 1: ' . ( $food_map[ $log->snack_1_id ] ?? 'Unknown' );

            if ( $log->custom_snack_2 )   $meals_list[] = 'Snack 2: ' . esc_html( $log->custom_snack_2 );
            elseif ( $log->snack_2_id )   $meals_list[] = 'Snack 2: ' . ( $food_map[ $log->snack_2_id ] ?? 'Unknown' );
        }

        $day_number = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(id) FROM {$table_logs} 
             WHERE subscription_id = %d AND target_date <= %s 
             AND (delivery_result IS NULL OR delivery_result NOT IN ('Cancelled', 'Returned'))",
            $log->subscription_id,
            $log->target_date
        ) );

        $target_ymd = date( 'Y-m-d', strtotime( $log->target_date ) );
        $expiry_ymd = date( 'Y-m-d', strtotime( $log->expiry_date ) );
        $is_expired = ( $target_ymd > $expiry_ymd );

        $plan_parts          = explode( ' - ', $log->plan_name );
        $potential_recipient = end( $plan_parts );
        $recipient_badge     = '';
        $raw_recipient       = '';
        if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', $potential_recipient ) && count( $plan_parts ) > 1 ) {
            $raw_recipient   = $potential_recipient;
            $recipient_badge = '<span class="kp-recipient-pill">' . esc_html( $potential_recipient ) . '</span>';
            array_pop( $plan_parts );
            $core_plan_name = implode( ' - ', $plan_parts );
        } else {
            $core_plan_name = $log->plan_name;
        }

        $display_order_id = 'Manual';
        if ( $log->wc_order_id < 0 ) {
            $display_order_id = 'M-' . str_pad( abs( $log->wc_order_id ), 4, '0', STR_PAD_LEFT );
        } elseif ( $log->wc_order_id > 0 ) {
            $display_order_id = '#' . $log->wc_order_id;
        } else {
            $display_order_id = 'M-' . str_pad( $log->subscription_id, 4, '0', STR_PAD_LEFT );
        }

        $status_ctx = cmp_get_kitchen_status_context( $log );

        // 3-Tier Priority Group:
        // 1 = Same Day Delivery - Same Day Preparation
        // 2 = Day Before Delivery (Advance Prep)
        // 3 = Same Day Delivery (Advance Prep)
        $priority_tier = $is_same_day_prep ? 1 : ( $is_day_before ? 2 : 3 );

        $customers[] = array(
            'log_id'             => $log->id,
            'sub_id'             => intval( $log->subscription_id ),
            'order_id'           => $display_order_id,
            'name'               => $full_name,
            'raw_recipient'      => $raw_recipient,
            'phone'              => $phone,
            'email'              => $email,
            'address'            => $address,
            'method'             => $method,
            'timing'             => $timing,
            'delivery_date'      => $delivery_date,
            'time_slot'          => $time_slot,
            'plan'               => $log->plan_name,
            'core_plan_name'     => $core_plan_name,
            'recipient_badge'    => $recipient_badge,
            'total_days'         => $log->total_days,
            'day_number'         => $day_number,
            'allergies'          => $allergies,
            'foh_remarks'        => ! empty( $log->foh_remarks ) ? wp_strip_all_tags( $log->foh_remarks ) : '',
            'is_expired'         => $is_expired,
            'is_cancelled'       => $is_cancelled,
            'is_returned'        => $is_returned,
            'is_pre_prep_cancel' => $status_ctx['is_pre_prep_cancel'],
            'status_headline'    => $status_ctx['headline'],
            'status_byline'      => $status_ctx['byline'],
            'cancelled_stamp'    => $status_ctx['byline'],
            'is_unscheduled'     => false,
            'is_paused'          => false,
            'is_same_day_prep'   => $is_same_day_prep,
            'prep_timing_mode'   => ! empty( $log->prep_timing ) ? $log->prep_timing : 'auto',
            'priority_tier'      => $priority_tier,
            'prepared'           => $log->is_prepared,
            'dispatch'           => $log->dispatch_status,
            'delivery'           => $log->delivery_result,
            'pos'                => $log->pos_updated,
            'meals'              => $meals_list,
            'is_chefs_choice'    => $log->is_chefs_choice,
            'is_assigned'        => $is_assigned,
            'is_flexible'        => $is_flexible,
            'allow_custom'       => $allow_custom,
            'allowed_categories' => $log->allowed_categories,
            'target_date'        => $log->target_date,
            'expiry_date'        => $log->expiry_date,
            'is_day_before'      => $is_day_before ? 1 : 0,
            'raw_log'            => $log,
        );
    }

    $roster_subs = $wpdb->get_results(
        "SELECT s.*, u.display_name, u.user_email
         FROM {$table_subs} s
         JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
         WHERE s.status IN ('active', 'paused')
         ORDER BY s.id DESC"
    );

    if ( $roster_subs ) {
        foreach ( $roster_subs as $sub ) {
            if ( isset( $scheduled_sub_ids[ intval( $sub->id ) ] ) ) {
                continue;
            }

            $is_paused_sub = ( $sub->status === 'paused' );
            $usage_days    = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_logs} WHERE subscription_id = %d AND delivery_result = 'Successful'",
                $sub->id
            ) );

            if ( ! $is_paused_sub && ( $usage_days >= intval( $sub->total_days ) || $sub->expiry_date < $today_dt ) ) {
                continue;
            }

            $resolved          = cmp_get_subscription_logistics( $sub );
            $is_same_day_prep  = cmp_is_same_day_prep_subscription( $sub, $resolved );
            $expected_eat_date = $is_same_day_prep ? $selected_date : $target_date;

            $full_name = $resolved['full_name'];
            $email     = $resolved['email'];
            $phone     = $resolved['phone'];
            $address   = $resolved['address'];

            $timing        = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'N/A';
            $timing        = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing );
            $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );
            $delivery_date = $is_same_day_prep ? $selected_date : ( $is_day_before ? $selected_date : $target_date );

            $time_slot = ! empty( $resolved['time_slot'] ) ? $resolved['time_slot'] : 'N/A';
            $method    = ! empty( $resolved['method'] ) ? $resolved['method'] : 'N/A';
            $pickup    = $resolved['pickup'];
            if ( $method === 'Pickup' && ! empty( $pickup ) ) {
                $method .= ' (' . $pickup . ')';
            }

            $allergies = ! empty( $resolved['allergies'] ) ? $resolved['allergies'] : 'No Allergies';

            $plan_parts          = explode( ' - ', $sub->plan_name );
            $potential_recipient = end( $plan_parts );
            $recipient_badge     = '';
            $raw_recipient       = '';
            if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', $potential_recipient ) && count( $plan_parts ) > 1 ) {
                $raw_recipient   = $potential_recipient;
                $recipient_badge = '<span class="kp-recipient-pill">' . esc_html( $potential_recipient ) . '</span>';
                array_pop( $plan_parts );
                $core_plan_name = implode( ' - ', $plan_parts );
            } else {
                $core_plan_name = $sub->plan_name;
            }

            $display_order_id = 'Manual';
            if ( $sub->wc_order_id < 0 ) {
                $display_order_id = 'M-' . str_pad( abs( $sub->wc_order_id ), 4, '0', STR_PAD_LEFT );
            } elseif ( $sub->wc_order_id > 0 ) {
                $display_order_id = '#' . $sub->wc_order_id;
            } else {
                $display_order_id = 'M-' . str_pad( $sub->id, 4, '0', STR_PAD_LEFT );
            }

            $priority_tier = $is_same_day_prep ? 1 : ( $is_day_before ? 2 : 3 );

            $customers[] = array(
                'log_id'             => 0,
                'sub_id'             => intval( $sub->id ),
                'order_id'           => $display_order_id,
                'name'               => $full_name,
                'raw_recipient'      => $raw_recipient,
                'phone'              => $phone,
                'email'              => $email,
                'address'            => $address,
                'method'             => $method,
                'timing'             => $timing,
                'delivery_date'      => $delivery_date,
                'time_slot'          => $time_slot,
                'plan'               => $sub->plan_name,
                'core_plan_name'     => $core_plan_name,
                'recipient_badge'    => $recipient_badge,
                'total_days'         => $sub->total_days,
                'day_number'         => $usage_days,
                'allergies'          => $allergies,
                'foh_remarks'        => ! empty( $sub->foh_remarks ) ? wp_strip_all_tags( $sub->foh_remarks ) : '',
                'is_expired'         => false,
                'is_cancelled'       => false,
                'is_returned'        => false,
                'is_pre_prep_cancel' => false,
                'status_headline'    => '',
                'status_byline'      => '',
                'cancelled_stamp'    => '',
                'is_unscheduled'     => true,
                'is_paused'          => $is_paused_sub,
                'is_same_day_prep'   => $is_same_day_prep,
                'prep_timing_mode'   => ! empty( $sub->prep_timing ) ? $sub->prep_timing : 'auto',
                'priority_tier'      => $priority_tier,
                'prepared'           => 0,
                'dispatch'           => 0,
                'delivery'           => 'Pending',
                'pos'                => 0,
                'meals'              => array(),
                'is_chefs_choice'    => 0,
                'is_assigned'        => false,
                'is_flexible'        => isset( $sub->is_flexible ) ? intval( $sub->is_flexible ) : 0,
                'allow_custom'       => isset( $sub->allow_custom ) ? intval( $sub->allow_custom ) : 0,
                'allowed_categories' => $sub->allowed_categories,
                'target_date'        => $expected_eat_date,
                'expiry_date'        => $sub->expiry_date,
                'is_day_before'      => $is_day_before ? 1 : 0,
                'raw_log'            => null,
            );
        }
    }

    // =========================================================================
    // DEFAULT SORTING ENGINE:
    //  1. Scheduled Orders before Unscheduled Roster rows
    //  2. Active Orders before Pre-Prep Cancelled Orders
    //  3. Priority Tier 1 (Same Day Delivery - Same Day Preparation)
    //  4. Priority Tier 2 (Day Before Delivery - Eating 11-Nov, Prep 10-Nov, Delivery 10-Nov)
    //  5. Priority Tier 3 (Same Day Delivery - Eating 11-Nov, Prep 10-Nov, Delivery 11-Nov)
    // =========================================================================
    usort( $customers, function( $a, $b ) {
        $sched_a = $a['is_unscheduled'] ? 0 : 1;
        $sched_b = $b['is_unscheduled'] ? 0 : 1;
        if ( $sched_a !== $sched_b ) {
            return $sched_b - $sched_a;
        }
        $canc_a = ! empty( $a['is_pre_prep_cancel'] ) ? 1 : ( $a['is_cancelled'] ? 1 : 0 );
        $canc_b = ! empty( $b['is_pre_prep_cancel'] ) ? 1 : ( $b['is_cancelled'] ? 1 : 0 );
        if ( $canc_a !== $canc_b ) {
            return $canc_a - $canc_b;
        }
        return $a['priority_tier'] - $b['priority_tier'];
    } );

    $chef_disabled    = ( $is_admin || $is_chef ) ? '' : 'disabled';
    $foh_disabled     = ( $is_admin || $is_foh ) ? '' : 'disabled';
    $can_override_prep = ( $is_admin || $is_foh ); // Strictly FOH, Menu Manager, and Admin

    $count_all_orders   = 0;
    $count_pending_prep = 0;
    $count_pending_chef = 0;
    $count_full_roster  = count( $customers );
    $count_unscheduled  = 0;

    foreach ( $customers as $c ) {
        if ( $c['is_unscheduled'] ) {
            $count_unscheduled++;
            continue;
        }
        $count_all_orders++;

        if ( ! $c['is_cancelled'] && ! $c['is_expired'] && empty( $c['prepared'] ) ) {
            $count_pending_prep++;
        }
        if ( $c['is_chefs_choice'] && ! $c['is_assigned'] && ! $c['is_cancelled'] && ! $c['is_expired'] ) {
            $count_pending_chef++;
        }
    }

    ob_start();
    ?>
    <script>
        var cmpGlobalFoodData = <?php echo wp_json_encode( $foods ); ?>;
    </script>

    <style>
        #kp-portal-root {
            --kp-admin-offset: 0px;
            --kp-banner-height: 82px;
            max-width: 1420px;
            margin: 0 auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 0 12px;
            box-sizing: border-box;
            letter-spacing: normal;
        }
        #kp-portal-root *, #kp-portal-root *::before, #kp-portal-root *::after {
            box-sizing: border-box;
            letter-spacing: normal;
        }
        #kp-portal-root button,
        #kp-portal-root a,
        #kp-portal-root input,
        #kp-portal-root select {
            letter-spacing: normal !important;
            text-transform: none !important;
        }

        #kp-sticky-banner {
            position: sticky;
            top: var(--kp-admin-offset, 0px);
            z-index: 100;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            text-align: center;
            border-bottom: 3px solid #379237;
            margin-bottom: 16px;
            padding: 14px 10px 10px 10px;
            transition: padding 0.2s ease, box-shadow 0.2s ease;
        }
        #kp-sticky-banner.kp-is-scrolled {
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.08);
        }
        #kp-sticky-banner h1 {
            margin: 0;
            font-size: 2em;
            line-height: 1.2;
            color: #231F20;
            transition: font-size 0.2s ease;
        }
        #kp-sticky-banner h3 {
            margin: 5px 0 0 0;
            font-size: 1.25em;
            line-height: 1.25;
            color: #379237;
            transition: font-size 0.2s ease, margin 0.2s ease;
        }

        .k-table-wrap {
            width: 100%;
            overflow: visible;
        }

        .k-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #fff;
            font-size: 0.85em;
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.06);
            border-radius: 10px;
            table-layout: fixed;
            border: 1px solid #cbd5e1;
        }
        .k-table thead th {
            position: sticky;
            top: calc(var(--kp-admin-offset, 0px) + var(--kp-banner-height, 82px));
            z-index: 95;
            background: linear-gradient(135deg, #1B5E20 0%, #379237 100%);
            color: #fff;
            padding: 12px 8px;
            text-align: left;
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(27, 94, 32, 0.16);
            border-bottom: 2px solid #1B5E20;
            white-space: nowrap !important;
            word-break: keep-all !important;
            overflow-wrap: normal !important;
        }
        .k-table thead th:first-child {
            border-top-left-radius: 9px;
        }
        .k-table thead th:last-child {
            border-top-right-radius: 9px;
        }
        .k-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .chk-large {
            width: 26px;
            height: 26px;
            transform: scale(1.18);
            cursor: pointer;
            margin: 4px auto;
            accent-color: #379237;
            display: inline-block;
            vertical-align: middle;
        }
        .chk-large:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }

        .kp-name-inline-wrap {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            line-height: 1.3;
        }
        .kp-recipient-pill {
            background: #EEF7E3;
            color: #1B5E20;
            border: 1px solid #DCECC9;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.8em;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            line-height: 1.2;
            white-space: nowrap;
        }

        /* ==========================================
           SAME-DAY PREP PRIORITY BADGE & STAFF SELECTOR
           ========================================== */
        .kp-sameday-prep-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border: 1px solid #f59e0b;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 0.78em;
            font-weight: 800;
            line-height: 1.2;
            white-space: nowrap;
            margin-bottom: 6px;
            box-shadow: 0 1px 2px rgba(245, 158, 11, 0.15);
        }
        .k-row.row-is-sameday-prep:not(.row-is-cancelled) > td:first-child {
            border-left: 4px solid #f59e0b;
        }
        .kp-delivery-date-badge {
            color: #ffffff;
            font-size: 0.86em;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 5px;
            display: inline-block;
            margin-bottom: 4px;
            white-space: nowrap !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        .kp-eating-date-label {
            color: #64748b;
            font-size: 0.84em;
            font-weight: 600;
            white-space: nowrap !important;
            display: inline-block;
        }
        .kp-prep-override-wrap {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px dashed #cbd5e1;
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }
        .kp-prep-override-select {
            font-size: 0.78em !important;
            padding: 2px 6px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 4px !important;
            background: #ffffff !important;
            color: #334155 !important;
            font-weight: 700 !important;
            max-width: 100%;
            cursor: pointer;
        }

        /* ==========================================
           OPTION UI-1: UNIFIED FULL-WIDTH SINGLE-LINE COLLAPSED BANNER
           ========================================== */
        .k-row.row-is-cancelled {
            background: #fffafa;
        }
        .k-row .kp-collapsed-banner-cell {
            display: none;
            padding: 7px 12px !important;
            background: #fff8f8;
            border-bottom: 1px solid #fecdd3;
        }
        .k-row.row-is-cancelled:not(.is-expanded) > td.kp-collapsed-banner-cell {
            display: table-cell !important;
        }
        .k-row.row-is-cancelled:not(.is-expanded) > td.kp-regular-cell {
            display: none !important;
        }
        .k-row.row-is-cancelled.is-expanded > td.kp-collapsed-banner-cell {
            display: none !important;
        }

        .kp-unified-cancel-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-left: 4px solid #e11d48;
            border-radius: 8px;
            padding: 7px 12px;
            cursor: pointer;
            transition: background 0.18s ease, box-shadow 0.18s ease;
        }
        .kp-unified-cancel-bar:hover {
            background: #ffe4e6;
            box-shadow: 0 2px 8px rgba(225, 29, 72, 0.08);
        }
        .kp-unified-cancel-bar.is-post-prep {
            background: #fffbeb;
            border-color: #fde68a;
            border-left-color: #d97706;
        }
        .kp-unified-bar-left {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            min-width: 0;
        }
        .kp-unified-bar-center {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            flex: 1;
            min-width: 200px;
        }
        .kp-unified-bar-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .kp-compact-order-tag {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.8em;
            font-weight: 800;
            white-space: nowrap;
        }
        .kp-compact-deliv-tag {
            background: #e11d48;
            color: #ffffff;
            padding: 2px 8px;
            border-radius: 5px;
            font-size: 0.78em;
            font-weight: 700;
            white-space: nowrap;
        }
        .kp-unified-status-headline {
            font-size: 0.9em;
            font-weight: 800;
            color: #be123c;
            white-space: nowrap;
        }
        .kp-unified-cancel-bar.is-post-prep .kp-unified-status-headline {
            color: #b45309;
        }
        .kp-unified-status-byline {
            font-size: 0.82em;
            font-weight: 600;
            color: #64748b;
        }
        .kp-unified-exempt-summary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75em;
            font-weight: 800;
            white-space: nowrap;
        }
        .kp-cancelled-expand-btn {
            background: rgba(225, 29, 72, 0.1);
            color: #be123c;
            border: 1px solid #fda4af;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 0.78em;
            font-weight: 800;
            white-space: nowrap;
            cursor: pointer;
        }
        .kp-cancelled-expanded-box {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-left: 4px solid #e11d48;
            border-radius: 7px;
            padding: 9px 11px;
            margin-bottom: 8px;
        }
        .kp-cancelled-expanded-box.is-post-prep {
            background: #fffbeb;
            border-color: #fde68a;
            border-left-color: #d97706;
        }
        .kp-status-line-1 {
            font-size: 0.92em;
            font-weight: 800;
            color: #be123c;
        }
        .kp-cancelled-expanded-box.is-post-prep .kp-status-line-1 {
            color: #b45309;
        }
        .kp-status-line-2 {
            font-size: 0.81em;
            font-weight: 600;
            color: #64748b;
            margin-top: 2px;
        }

        /* Option 2B Returned Two-Line Status Box */
        .kp-returned-status-box {
            margin-top: 8px;
            background: #f5f3ff;
            border: 1px solid #ddd6fe;
            border-left: 4px solid #7c3aed;
            border-radius: 6px;
            padding: 7px 10px;
            line-height: 1.3;
        }

        /* Option 4A Compact Status Pills that Fit 100% Inside Columns */
        .kp-exempt-pill {
            display: inline-block;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: 0.73em;
            font-weight: 800;
            line-height: 1.15;
            text-align: center;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .kp-no-selection-box {
            background: #f8fafc;
            border: 1.5px dashed #cbd5e1;
            border-left: 4px solid #64748b;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            color: #334155;
        }
        .kp-no-selection-box.is-paused-box {
            background: #fffbeb;
            border: 1.5px dashed #fcd34d;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        .kp-no-selection-badge {
            display: inline-block;
            background: #e2e8f0;
            color: #1e293b;
            font-weight: 800;
            font-size: 0.92em;
            padding: 4px 12px;
            border-radius: 999px;
            margin-bottom: 5px;
        }
        .kp-paused-roster-badge {
            display: inline-block;
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #f59e0b;
            font-weight: 800;
            font-size: 0.9em;
            padding: 4px 12px;
            border-radius: 999px;
            margin-bottom: 5px;
        }
        .k-row.filter-unscheduled {
            background: #fcfcfd;
        }
        .k-row.row-is-paused {
            background: #fffdf5;
        }

        .kp-pos-cell-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        .kp-pos-cue {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            font-size: 10px;
            font-weight: 800;
            line-height: 1.15;
            padding: 3px 6px;
            border-radius: 999px;
            white-space: nowrap;
            letter-spacing: 0.01em;
            border: 1px solid transparent;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
            max-width: 100%;
        }
        .kp-pos-cue.cue-upcoming {
            background: #fffbeb;
            color: #b45309;
            border-color: #fde68a;
        }
        .kp-pos-cue.cue-today {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }
        .kp-pos-cue.cue-overdue {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
        }
        .kp-pos-cue.cue-done {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
            opacity: 0.88;
        }

        .k-customer-meta {
            margin: 2px 0;
            color: #555;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .k-customer-meta svg {
            width: 13px !important;
            height: 13px !important;
            min-width: 13px !important;
            max-width: 13px !important;
            max-height: 13px !important;
            flex-shrink: 0 !important;
            color: #379237 !important;
            display: inline-block !important;
        }
        
        .logistics-box { margin-top: 10px; padding: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; line-height: 1.35; }
        
        .k-tab-btn { background: #ffffff; border: 1px solid #cbd5e1; padding: 9px 15px; border-radius: 8px; font-weight: 700; font-size: 0.92em; color: #231F20; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .k-tab-btn:hover { border-color: #379237; color: #1B5E20; }
        .k-tab-btn.active { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; color: #fff !important; border-color: #1B5E20 !important; box-shadow: 0 4px 12px rgba(55, 146, 55, 0.25); }
        .k-tab-pill-count { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.8em; font-weight: 800; line-height: 1.1; }

        .k-glass-toolbar { background: linear-gradient(135deg, rgba(220, 236, 201, 0.78) 0%, rgba(238, 247, 227, 0.72) 100%); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 18px 22px; border-radius: 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border: 1.5px solid #DCECC9; box-shadow: 0 10px 26px rgba(27, 94, 32, 0.08); flex-wrap: wrap; gap: 14px; }

        .k-filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            background: #f8fafc;
            padding: 13px 15px;
            border: 1px solid #DCECC9;
            border-radius: 10px;
        }

        .kp-mob-cell-label {
            display: none;
        }

        /* ==========================================
           TABLET OPTIMIZATION (768px - 1180px)
           ========================================== */
        @media screen and (max-width: 1180px) {
            #kp-portal-root { padding: 0 8px; }
            .k-glass-toolbar { padding: 14px 16px; gap: 12px; }
            .k-table { font-size: 0.8em; }
            .k-table thead th {
                padding: 9px 5px;
                font-size: 0.9em;
            }
            .k-table td { padding: 8px 6px; }
            .k-table thead th:nth-child(1) { width: 20% !important; }
            .k-table thead th:nth-child(2) { width: 17% !important; }
            .k-table thead th:nth-child(3) { width: 27% !important; }
            .k-table thead th:nth-child(4) { width: 8.5% !important; }
            .k-table thead th:nth-child(5) { width: 8.5% !important; }
            .k-table thead th:nth-child(6) { width: 10.5% !important; }
            .k-table thead th:nth-child(7) { width: 8.5% !important; }
            .chk-large { width: 22px; height: 22px; transform: scale(1.1); }
            .kp-delivery-date-badge { font-size: 0.8em; padding: 3px 6px; }
            .kp-exempt-pill { font-size: 0.69em; padding: 2px 5px; }
            .kp-pos-cue { font-size: 9px; padding: 2px 4px; }
        }

        /* ==========================================
           MOBILE PORTRAIT CARDS (<= 767px, Portrait)
           ========================================== */
        @media screen and (max-width: 767px) and (orientation: portrait) {
            #kp-sticky-banner { padding: 10px 8px 8px 8px; margin-bottom: 14px; }
            #kp-sticky-banner h1 { font-size: 1.35em; }
            #kp-sticky-banner h3 { font-size: 0.98em; margin-top: 3px; }
            .k-glass-toolbar { padding: 14px; flex-direction: column; align-items: stretch; }
            .k-glass-toolbar form { width: 100%; justify-content: space-between; }
            .k-filter-bar { padding: 12px; gap: 10px; }
            .k-tabs-container { width: 100%; display: flex; overflow-x: auto; flex-wrap: nowrap !important; }
            .k-tab-btn { flex: 1 0 auto; padding: 8px 12px; font-size: 0.85em; white-space: nowrap; }
            .k-table { display: block; background: transparent; box-shadow: none; border-radius: 0; border: none; font-size: 0.92em; }
            .k-table thead { display: none !important; }
            .k-table tbody { display: block; width: 100%; }
            .k-table tbody tr.k-row { display: grid; grid-template-columns: repeat(2, 1fr); background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 14px; margin-bottom: 16px; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06); overflow: hidden; }
            .k-table tbody tr.k-row.k-row-hidden { display: none !important; }

            /* Collapsed Cancelled Row on Mobile Portrait */
            .k-table tbody tr.k-row.row-is-cancelled:not(.is-expanded) > td.kp-collapsed-banner-cell {
                grid-column: 1 / -1;
                display: block !important;
                width: 100%;
                padding: 8px !important;
                border-bottom: none;
            }

            /* Expanded / Regular Cells on Mobile Portrait */
            .k-table tbody tr.k-row > td.kp-regular-cell:nth-of-type(2),
            .k-table tbody tr.k-row > td.kp-regular-cell:nth-of-type(3),
            .k-table tbody tr.k-row > td.kp-regular-cell:nth-of-type(4) {
                grid-column: 1 / -1;
                display: block;
                width: 100%;
                padding: 12px 14px;
                border-bottom: 1px solid #e2e8f0;
            }
            .k-table tbody tr.k-row > td.kp-regular-cell:nth-of-type(2) {
                background: linear-gradient(135deg, rgba(238, 247, 227, 0.55) 0%, rgba(255, 255, 255, 1) 100%);
                border-left: 4px solid #379237;
            }
            .k-table tbody tr.k-row > td.kp-regular-cell:nth-of-type(5),
            .k-table tbody tr.k-row > td.kp-regular-cell:nth-of-type(6),
            .k-table tbody tr.k-row > td.kp-regular-cell:nth-of-type(7),
            .k-table tbody tr.k-row > td.kp-regular-cell:nth-of-type(8) {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 12px 10px;
                min-height: 86px;
                border-bottom: 1px solid #e2e8f0;
            }
            .kp-mob-cell-label { display: block; font-size: 0.72em; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; margin-bottom: 6px; text-align: center; }
            .chk-large { width: 28px; height: 28px; transform: scale(1.15); margin: 2px auto; }
        }

        @media print {
            .cmp-no-print, .kp-pos-cue, .kp-mob-cell-label, .kp-prep-override-wrap { display: none !important; }
            #kp-sticky-banner, .k-table thead th { position: static !important; box-shadow: none !important; }
            body, html { background: #fff !important; margin: 0 !important; padding: 0 !important; }
            .k-table { box-shadow: none !important; border: 1px solid #000; border-collapse: collapse !important; }
            .k-table th, .k-table td { border: 1px solid #000; padding: 6px; color: #000 !important; background: transparent !important; }
        }
    </style>

    <div id="kp-portal-root">
        
        <div class="cmp-no-print k-glass-toolbar">
            <form method="GET" id="cmp-kitchen-date-form" style="margin: 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <label style="font-weight: 800; font-size: 1.05em; color: #1B5E20;">Food Preparation Date:</label>
                <a href="?prep_date=<?php echo esc_attr( $prev_date ); ?>" style="background: #ffffff; color: #1B5E20; border: 1px solid #b7e1a1; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: bold;">&laquo; Prev</a>
                <input type="date" name="prep_date" value="<?php echo esc_attr( $selected_date ); ?>" style="padding: 8px 12px; border: 1.5px solid #379237; border-radius: 8px; background: #fff; color: #231F20; font-weight: 600;" onchange="document.getElementById('cmp-kitchen-date-form').submit();">
                <a href="?prep_date=<?php echo esc_attr( $next_date ); ?>" style="background: #ffffff; color: #1B5E20; border: 1px solid #b7e1a1; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-weight: bold;">Next &raquo;</a>
            </form>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo esc_url( site_url( '/chef-dashboard/' ) ); ?>" target="_blank" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: bold; text-decoration: none;">Chef's Assignment ⇗</a>
                <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=cmp_export_kitchen_csv&type=meals&prep_date=' . $selected_date ) ); ?>" style="background: #1d6f42; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: bold; text-decoration: none;">Export Meals CSV</a>
                <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=cmp_export_kitchen_csv&type=juices&prep_date=' . $selected_date ) ); ?>" style="background: #0ea5e9; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: bold; text-decoration: none;">Export Juices CSV</a>
                <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=cmp_export_kitchen_csv&type=roster&prep_date=' . $selected_date ) ); ?>" style="background: #0f766e; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: bold; text-decoration: none;" title="Export all active and paused customers including No Food Selection Today">Export Full Roster CSV</a>
                <button onclick="window.print()" style="background: #475569; color: white; border: none; padding: 10px 16px; border-radius: 8px; cursor: pointer; font-weight: bold;">Print PDF</button>
                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" style="background: #dc2626; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: bold; text-decoration: none;">Log Out</a>
            </div>
        </div>

        <div id="kp-sticky-banner">
            <h1>Order Preparation Report</h1>
            <h3>Food Orders to Prep on: <?php echo date( 'l, d/m/Y', strtotime( $selected_date ) ); ?></h3>
        </div>

        <div class="cmp-no-print k-filter-bar">
            <div class="k-tabs-container" style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" class="k-tab-btn active" data-filter="all">
                    <span>All Orders</span>
                    <span class="k-tab-pill-count" style="background:#e2e8f0; color:#1e293b;"><?php echo intval( $count_all_orders ); ?></span>
                </button>
                <button type="button" class="k-tab-btn" data-filter="pending_prep">
                    <span>Pending Preparation</span>
                    <span id="k-pending-prep-count" class="k-tab-pill-count" style="background:<?php echo $count_pending_prep > 0 ? '#f59e0b' : '#16a34a'; ?>; color:#fff;"><?php echo intval( $count_pending_prep ); ?></span>
                </button>
                <button type="button" class="k-tab-btn" data-filter="pending">
                    <span>Pending Chef's Choice</span>
                    <?php if ( $count_pending_chef > 0 ) : ?>
                        <span class="k-tab-pill-count" style="background:#dc2626; color:#fff;"><?php echo intval( $count_pending_chef ); ?></span>
                    <?php else : ?>
                        <span class="k-tab-pill-count" style="background:#e2e8f0; color:#475569;">0</span>
                    <?php endif; ?>
                </button>
                <button type="button" class="k-tab-btn" data-filter="roster" title="View all active and paused customers, including those with No Food Selection Today">
                    <span>Full Customer Roster</span>
                    <span class="k-tab-pill-count" style="background:#0f766e; color:#fff;"><?php echo intval( $count_full_roster ); ?></span>
                </button>
            </div>
            <div style="flex: 1 1 240px; max-width: 340px;">
                <input type="text" id="k-search" placeholder="🔍 Search by ID, name, recipient, email, phone..." style="width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.95em; background:#fff;">
            </div>
        </div>

        <?php if ( empty( $customers ) ) : ?>
            <p style="text-align: center; font-size: 1.2em; color: #666; padding: 40px; border: 1px dashed #ccc; border-radius: 10px;">No active customers or orders found for this prep date.</p>
        <?php else : ?>
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Customer Details</th>
                            <th style="width: 17%;">Logistics</th>
                            <th style="width: 28%;">The Food</th>
                            <th style="width: 8.5%; text-align:center;">Prepared</th>
                            <th style="width: 8.5%; text-align:center;">Dispatch</th>
                            <th style="width: 10%;">Delivery</th>
                            <th style="width: 8%; text-align:center;">POS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $customers as $c ) :
                            $is_unscheduled     = ! empty( $c['is_unscheduled'] );
                            $is_paused_cust     = ! empty( $c['is_paused'] );
                            $is_cancelled       = ! empty( $c['is_cancelled'] );
                            $is_returned        = ! empty( $c['is_returned'] );
                            $is_pre_prep_cancel = ! empty( $c['is_pre_prep_cancel'] );
                            $is_expired         = ! empty( $c['is_expired'] );
                            $is_same_day_prep   = ! empty( $c['is_same_day_prep'] );
                            $prep_timing_mode   = ! empty( $c['prep_timing_mode'] ) ? $c['prep_timing_mode'] : 'auto';

                            $allergy_style = ( $c['allergies'] !== 'No Allergies' )
                                ? 'background:#fff3cd; color:#856404; font-weight:bold; padding:2px 6px; border-radius:3px; display:inline-block; margin-top:3px;'
                                : 'color:#666; font-style:italic; display:inline-block; margin-top:3px;';

                            // Option 4A: Smart Checkbox Exemptions
                            $prepared_disabled = ( $is_unscheduled || $is_cancelled || ! empty( $chef_disabled ) || $c['dispatch'] || $is_expired ) ? 'disabled' : '';
                            $dispatch_disabled = ( $is_unscheduled || $is_cancelled || ! empty( $chef_disabled ) || $c['pos'] || $is_expired || ! $c['prepared'] ) ? 'disabled' : '';
                            $delivery_disabled = ( $is_unscheduled || ( ! $c['dispatch'] && ! $is_cancelled ) || ! empty( $chef_disabled ) || $c['pos'] || $is_expired ) ? 'disabled' : '';
                            $pos_disabled      = ( $is_unscheduled || $is_cancelled || $is_returned || ! $c['dispatch'] || $c['delivery'] === 'Pending' || ! empty( $foh_disabled ) || $is_expired ) ? 'disabled' : '';

                            $row_classes = array( 'k-row' );
                            if ( $is_same_day_prep ) {
                                $row_classes[] = 'row-is-sameday-prep';
                            }
                            if ( $is_unscheduled ) {
                                $row_classes[] = 'filter-unscheduled';
                                if ( $is_paused_cust ) $row_classes[] = 'row-is-paused';
                            } elseif ( $is_cancelled ) {
                                $row_classes[] = 'row-is-cancelled';
                            } elseif ( $c['is_chefs_choice'] && ! $is_expired ) {
                                $row_classes[] = $c['is_assigned'] ? 'filter-assigned' : 'filter-pending';
                            } else {
                                $row_classes[] = 'filter-standard';
                            }

                            $needs_prep = ( ! $is_unscheduled && ! $is_cancelled && ! $is_expired && empty( $c['prepared'] ) ) ? '1' : '0';
                            $in_orders  = $is_unscheduled ? '0' : '1';
                            $in_roster  = '1';

                            $search_name = esc_attr( strtolower(
                                $c['order_id'] . ' ' . $c['name'] . ' ' . $c['raw_recipient'] . ' ' . $c['email'] . ' ' . $c['phone'] . ' ' . $c['allergies'] . ' ' . $c['timing'] . ' ' .
                                ( $is_same_day_prep ? 'same day prep sameday' : '' ) . ' ' .
                                ( $is_unscheduled ? 'no food selection today unscheduled' : '' ) . ' ' .
                                ( $is_paused_cust ? 'paused plan on hold' : '' ) . ' ' .
                                ( $is_cancelled ? 'cancelled ' . $c['status_headline'] . ' ' . $c['status_byline'] : '' ) . ' ' .
                                ( $is_returned ? 'returned ' . $c['status_headline'] . ' ' . $c['status_byline'] : '' )
                            ) );

                            $plan_duration = $c['core_plan_name'];
                            $plan_parts    = explode( ' - ', $c['plan'] );
                            $plan_meals    = isset( $plan_parts[1] ) ? $plan_parts[1] : '';
                            if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', end( $plan_parts ) ) ) {
                                $plan_meals = count( $plan_parts ) > 2 ? $plan_parts[1] : '';
                            }

                            $eat_ymd   = date( 'Y-m-d', strtotime( $c['target_date'] ) );
                            $eat_short = date( 'd/m', strtotime( $c['target_date'] ) );
                            $eat_full  = date( 'l, d/m/Y', strtotime( $c['target_date'] ) );

                            if ( $eat_ymd > $today_ymd ) {
                                $base_cue_class = 'cue-upcoming';
                            } elseif ( $eat_ymd === $today_ymd ) {
                                $base_cue_class = 'cue-today';
                            } else {
                                $base_cue_class = 'cue-overdue';
                            }
                            $active_cue_class = $c['pos'] ? 'cue-done' : $base_cue_class;
                            $pos_cue_text     = 'Post on ' . $eat_short . ( $c['pos'] ? ' ✓' : '' );
                        ?>
                        <tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
                            data-log-id="<?php echo esc_attr( $c['log_id'] ); ?>"
                            data-sub-id="<?php echo esc_attr( $c['sub_id'] ); ?>"
                            data-name="<?php echo $search_name; ?>"
                            data-in-orders="<?php echo esc_attr( $in_orders ); ?>"
                            data-in-roster="<?php echo esc_attr( $in_roster ); ?>"
                            data-pending-prep="<?php echo esc_attr( $needs_prep ); ?>"
                            data-is-cancelled="<?php echo $is_cancelled ? '1' : '0'; ?>"
                            data-pre-prep-cancel="<?php echo $is_pre_prep_cancel ? '1' : '0'; ?>"
                            data-is-expired="<?php echo $is_expired ? '1' : '0'; ?>">

                            <!-- ====================================================
                                 OPTION UI-1: UNIFIED FULL-WIDTH SINGLE-LINE COLLAPSED BANNER
                                 (Shown by default when row-is-cancelled is collapsed)
                                 ==================================================== -->
                            <td colspan="7" class="kp-collapsed-banner-cell">
                                <?php if ( ! $is_unscheduled ) : ?>
                                <div class="kp-unified-cancel-bar <?php echo ! $is_pre_prep_cancel ? 'is-post-prep' : ''; ?>"
                                     onclick="const tr = this.closest('tr.k-row'); tr.classList.add('is-expanded');">
                                    <div class="kp-unified-bar-left">
                                        <span class="kp-compact-order-tag"><?php echo esc_html( $c['order_id'] ); ?></span>
                                        <strong style="color:#1B5E20; font-size:1.02em;"><?php echo esc_html( $c['name'] ); ?></strong>
                                        <?php echo $c['recipient_badge']; ?>
                                        <span class="kp-compact-deliv-tag">Delivery: <?php echo esc_html( date( 'D, M j', strtotime( $c['delivery_date'] ) ) ); ?></span>
                                        <?php if ( $is_same_day_prep ) : ?>
                                            <span class="kp-sameday-prep-pill" style="margin-bottom:0;">&#9889; SAME-DAY PREP</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="kp-unified-bar-center">
                                        <span class="kp-unified-status-headline" id="kp-bar-headline-<?php echo esc_attr( $c['log_id'] ); ?>">
                                            &#10005; <?php echo esc_html( $c['status_headline'] ?: 'DO NOT PREPARE' ); ?>
                                        </span>
                                        <span class="kp-unified-status-byline" id="kp-bar-byline-<?php echo esc_attr( $c['log_id'] ); ?>">
                                            &mdash; <?php echo esc_html( $c['status_byline'] ); ?>
                                        </span>
                                    </div>
                                    <div class="kp-unified-bar-right">
                                        <span class="kp-unified-exempt-summary">
                                            <?php echo $is_pre_prep_cancel ? 'Exempt &bull; No POS' : 'No POS Req.'; ?>
                                        </span>
                                        <span class="kp-cancelled-expand-btn">[+ Expand Details ▼]</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- ====================================================
                                 REGULAR 7 TABLE CELLS (Visible for Active Rows & Expanded Cancelled Rows)
                                 ==================================================== -->
                            <td class="kp-regular-cell">
                                <div class="kp-name-inline-wrap">
                                    <a href="<?php echo esc_url( site_url( '/my-meal-portal/?admin_edit_sub=' . $c['sub_id'] ) ); ?>" target="_blank" style="text-decoration:none;" title="Open Customer Portal">
                                        <strong style="color: #379237; font-size: 1.08em; border-bottom: 1px dotted #379237;"><?php echo esc_html( $c['name'] ); ?></strong>
                                    </a>
                                    <?php echo $c['recipient_badge']; ?>
                                </div>
                                
                                <div style="margin-top:6px; font-size:0.95em; line-height:1.4;">
                                    <span class="k-customer-meta" style="color: #231F20; font-weight: bold;">Order: <?php echo esc_html( $c['order_id'] ); ?></span>
                                    <span class="k-customer-meta">
                                        <svg width="13" height="13" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                        <?php echo esc_html( $c['email'] ); ?>
                                    </span>
                                    <span class="k-customer-meta">
                                        <svg width="13" height="13" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                        <?php echo esc_html( $c['phone'] ); ?>
                                    </span>
                                    <span class="k-customer-meta" style="align-items: flex-start;">
                                        <svg width="13" height="13" xmlns="http://www.w3.org/2000/svg" style="margin-top: 2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                        <?php echo esc_html( $c['address'] ); ?>
                                    </span>
                                    <div style="<?php echo $allergy_style; ?>"><?php echo esc_html( $c['allergies'] ); ?></div>
                                </div>
                            </td>
                            
                            <td class="kp-regular-cell">
                                <?php if ( $is_same_day_prep ) : ?>
                                    <div>
                                        <span class="kp-sameday-prep-pill">&#9889; SAME-DAY PREP</span>
                                    </div>
                                <?php endif; ?>
                                <div class="logistics-box" style="margin-top:0; margin-bottom:8px;">
                                    <span style="color: #475569;"><strong>Method:</strong> <?php echo esc_html( $c['method'] ); ?></span><br>
                                    <span style="color: #475569;"><strong>Receive By:</strong> <?php echo esc_html( $c['timing'] ); ?></span><br>
                                    <span style="color: #475569;"><strong>Time:</strong> <?php echo esc_html( $c['time_slot'] ); ?></span>

                                    <?php if ( $can_override_prep ) : ?>
                                    <!-- Staff-Only Prep Schedule Override (FOH, Menu Manager & Admin Only — Never visible to Customers) -->
                                    <div class="kp-prep-override-wrap cmp-no-print">
                                        <label style="font-size:0.74em; font-weight:800; color:#475569; text-transform:uppercase;">Prep:</label>
                                        <select class="kp-prep-override-select" data-sub-id="<?php echo esc_attr( $c['sub_id'] ); ?>" title="FOH / Menu Manager / Admin Override: Change Kitchen Prep Schedule for this Plan">
                                            <option value="auto" <?php selected( $prep_timing_mode, 'auto' ); ?>>Auto (4PM+ Rule)</option>
                                            <option value="same_day" <?php selected( $prep_timing_mode, 'same_day' ); ?>>Same-Day Prep</option>
                                            <option value="day_before" <?php selected( $prep_timing_mode, 'day_before' ); ?>>Day-Before Prep</option>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="text-align: left;">
                                    <div class="kp-delivery-date-badge" style="background: <?php echo $is_paused_cust ? '#d97706' : ( $is_unscheduled ? '#64748b' : ( $is_cancelled ? '#e11d48' : '#379237' ) ); ?>;">
                                        Delivery: <?php echo date( 'D, M j', strtotime( $c['delivery_date'] ) ); ?>
                                    </div>
                                    <br>
                                    <span class="kp-eating-date-label">
                                        Eating: <?php echo date( 'D, M j', strtotime( $c['target_date'] ) ); ?>
                                    </span>
                                </div>
                            </td>

                            <td class="kp-regular-cell">
                                <?php if ( $is_unscheduled ) : ?>
                                    <div class="kp-no-selection-box <?php echo $is_paused_cust ? 'is-paused-box' : ''; ?>">
                                        <?php if ( $is_paused_cust ) : ?>
                                            <span class="kp-paused-roster-badge">&#9208; Plan Paused &mdash; No Food Selection Today</span>
                                            <div style="font-size:0.84em; color:#92400e; margin-top:2px;">
                                                Subscription is currently <strong>Paused</strong> for Eating Date: <strong><?php echo esc_html( date( 'D, M j', strtotime( $c['target_date'] ) ) ); ?></strong>
                                            </div>
                                        <?php else : ?>
                                            <span class="kp-no-selection-badge">No Food Selection Today</span>
                                            <div style="font-size:0.84em; color:#64748b; margin-top:2px;">
                                                No meal scheduled for Eating Date: <strong><?php echo esc_html( date( 'D, M j', strtotime( $c['target_date'] ) ) ); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <div class="cmp-no-print" style="margin-top:7px;">
                                            <a href="<?php echo esc_url( site_url( '/my-meal-portal/?admin_edit_sub=' . $c['sub_id'] ) ); ?>" target="_blank" style="font-size:0.82em; color:#379237; font-weight:700; text-decoration:underline;">Open Customer Portal ⇗</a>
                                        </div>
                                    </div>

                                <?php elseif ( $is_cancelled ) : ?>
                                    <!-- Expanded Cancelled View inside The Food Cell -->
                                    <div class="kp-cancelled-expanded-box <?php echo ! $is_pre_prep_cancel ? 'is-post-prep' : ''; ?>">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; flex-wrap:wrap;">
                                            <div>
                                                <div class="kp-status-line-1" id="kp-cancel-headline-<?php echo esc_attr( $c['log_id'] ); ?>">
                                                    &#10005; <?php echo esc_html( $c['status_headline'] ?: 'DO NOT PREPARE' ); ?>
                                                </div>
                                                <div class="kp-status-line-2" id="kp-cancel-meta-<?php echo esc_attr( $c['log_id'] ); ?>">
                                                    <?php echo esc_html( $c['status_byline'] ); ?>
                                                </div>
                                            </div>
                                            <button type="button" class="kp-cancelled-expand-btn cmp-no-print"
                                                    onclick="this.closest('tr.k-row').classList.remove('is-expanded');">
                                                [- Collapse Row ▲]
                                            </button>
                                        </div>
                                        <?php if ( ! empty( $c['meals'] ) ) : ?>
                                            <div style="margin-top:7px; padding-top:6px; border-top:1px dashed #fda4af; font-size:0.82em; color:#9f1239; opacity:0.85; text-decoration:line-through; line-height:1.35;">
                                                <?php echo implode( ' &bull; ', $c['meals'] ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ( $is_expired ) : ?>
                                    <div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; text-align:center; border:1px solid #f87171;">
                                        <strong style="font-size:1.1em; display:block; margin-bottom:5px;">PLAN EXPIRED</strong>
                                        <span style="font-size:0.9em;">Do not prep meals. Plan ended on <?php echo date( 'M j, Y', strtotime( $c['expiry_date'] ) ); ?>.</span>
                                    </div>

                                <?php elseif ( $c['is_chefs_choice'] ) : ?>
                                    <?php
                                    $display_style = $c['is_assigned'] ? 'block' : 'none';
                                    $form_style    = $c['is_assigned'] ? 'none' : 'block';
                                    ?>
                                    <div class="chef-assigned-view" style="display: <?php echo $display_style; ?>;">
                                        <ul style="margin:0; padding-left:15px; line-height: 1.4;">
                                            <?php foreach ( $c['meals'] as $meal ) echo "<li>$meal <span style='color:#379237; font-weight:bold; font-size:0.85em;'>(Chef)</span></li>"; ?>
                                        </ul>
                                        <button type="button" class="edit-chef-assign cmp-no-print" style="margin-top:8px; font-size:0.85em; background:#e2e8f0; color:#334155; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">Edit Selection</button>
                                    </div>
                                    
                                    <?php
                                    $is_juice_plan = ( stripos( $c['allowed_categories'], 'Juices' ) !== false || stripos( $c['plan'], 'juice' ) !== false || stripos( $c['plan'], 'cleanse' ) !== false );
                                    preg_match( '/(\d+)\s*Meal/i', $c['plan'], $m );
                                    $allowed_meals = isset( $m[1] ) ? intval( $m[1] ) : 0;

                                    if ( ! $is_juice_plan ) {
                                        $allowed_cats = array( 'Breakfast', 'Lunch', 'Dinner', 'Snacks' );
                                        $snack_count  = ( $allowed_meals >= 2 ) ? 2 : 1;
                                    } else {
                                        $allowed_cats = array( 'Juices' );
                                        $snack_count  = 0;
                                    }
                                    $raw          = $c['raw_log'];
                                    $is_flexible  = isset( $c['is_flexible'] ) ? intval( $c['is_flexible'] ) : 0;
                                    $allow_custom = isset( $c['allow_custom'] ) ? intval( $c['allow_custom'] ) : 0;
                                    ?>
                                    <div class="chef-assign-form cmp-no-print cmp-portal-container" data-log-id="<?php echo esc_attr( $c['log_id'] ); ?>" data-target-date="<?php echo esc_attr( $c['target_date'] ); ?>" data-allowed-meals="<?php echo esc_attr( $allowed_meals ); ?>" data-snack-quota="<?php echo esc_attr( $snack_count ); ?>" data-is-juice="<?php echo $is_juice_plan ? '1' : '0'; ?>" data-allow-custom="<?php echo esc_attr( $allow_custom ); ?>" style="display: <?php echo $form_style; ?>; background:#fffbdd; padding:10px; border:1px dashed #eab308; border-radius:5px;">
                                        <div style="font-size:0.9em; font-weight:bold; color:#b45309; margin-bottom:8px;">Assign Chef's Choice:</div>
                                        <?php if ( ! $is_juice_plan ) : ?>
                                            <?php foreach ( array( 'Breakfast', 'Lunch', 'Dinner' ) as $cat ) : if ( in_array( $cat, $allowed_cats, true ) ) :
                                                $slot       = strtolower( $cat );
                                                $label      = $is_flexible ? ( $slot === 'breakfast' ? 'Meal 1' : ( $slot === 'lunch' ? 'Meal 2' : 'Meal 3' ) ) : $cat;
                                                $cat_filter = $is_flexible ? 'Any' : $cat;
                                            ?>
                                                <?php if ( $allow_custom ) : ?>
                                                    <input type="text" class="cmp-meal-custom cmp-main-meal" data-slot="<?php echo esc_attr( $slot ); ?>" value="<?php echo esc_attr( $raw->{ 'custom_' . $slot } ); ?>" placeholder="Type <?php echo esc_attr( $label ); ?>..." style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                <?php else : ?>
                                                    <select class="chef-meal-select cmp-main-meal" data-cat="<?php echo esc_attr( $cat_filter ); ?>" data-slot="<?php echo esc_attr( $slot ); ?>" data-saved-val="<?php echo esc_attr( $raw->{ $slot . '_id' } ); ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                        <option value="">- <?php echo esc_html( $label ); ?> -</option>
                                                    </select>
                                                <?php endif; ?>
                                            <?php endif; endforeach; ?>
                                            
                                            <?php if ( $snack_count > 0 ) : ?>
                                                <?php if ( $allow_custom ) : ?>
                                                    <input type="text" class="cmp-meal-custom cmp-snack-1" data-slot="snack_1" value="<?php echo esc_attr( $raw->custom_snack_1 ); ?>" placeholder="Type Snack 1..." style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                    <?php if ( $snack_count == 2 ) : ?>
                                                        <input type="text" class="cmp-meal-custom cmp-snack-2" data-slot="snack_2" value="<?php echo esc_attr( $raw->custom_snack_2 ); ?>" placeholder="Type Snack 2..." style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                    <?php endif; ?>
                                                <?php else : ?>
                                                    <select class="chef-meal-select cmp-snack-1" data-cat="Snacks" data-slot="snack_1" data-saved-val="<?php echo esc_attr( $raw->snack_1_id ); ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                        <option value="">- Snack 1 -</option>
                                                    </select>
                                                    <?php if ( $snack_count == 2 ) : ?>
                                                        <select class="chef-meal-select cmp-snack-2" data-cat="Snacks" data-slot="snack_2" data-saved-val="<?php echo esc_attr( $raw->snack_2_id ); ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                            <option value="">- Snack 2 -</option>
                                                        </select>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <?php for ( $j = 1; $j <= 3; $j++ ) : ?>
                                                <?php if ( $allow_custom ) : ?>
                                                    <input type="text" class="cmp-meal-custom cmp-juice-<?php echo esc_attr( $j ); ?>" data-slot="juice_<?php echo esc_attr( $j ); ?>" value="<?php echo esc_attr( $raw->{ 'custom_juice_' . $j } ); ?>" placeholder="Type Juice <?php echo esc_attr( $j ); ?>..." style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                <?php else : ?>
                                                    <select class="chef-meal-select cmp-juice-<?php echo esc_attr( $j ); ?>" data-cat="Juices" data-slot="juice_<?php echo esc_attr( $j ); ?>" data-saved-val="<?php echo esc_attr( $raw->{ 'juice_' . $j . '_id' } ); ?>" style="width:100%; margin-bottom:4px; padding:4px; font-size:0.9em; border:1px solid #ccc; border-radius:3px;">
                                                        <option value="">- Juice <?php echo esc_html( $j ); ?> -</option>
                                                    </select>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        <?php endif; ?>
                                        <button type="button" class="save-chef-assign" style="width:100%; background:#dba617; color:#fff; border:none; padding:6px; border-radius:4px; font-weight:bold; cursor:pointer; margin-top:4px;">Save</button>
                                    </div>
                                    
                                <?php else : ?>
                                    <ul style="margin:0; padding-left:15px; line-height: 1.4;">
                                        <?php foreach ( $c['meals'] as $meal ) echo "<li>$meal</li>"; ?>
                                    </ul>
                                <?php endif; ?>

                                <!-- Option 2B: Dynamic Two-Line Status Banner when Cancelled or Returned live in Kitchen Portal -->
                                <?php if ( ! $is_cancelled && ! $is_unscheduled ) : ?>
                                    <div class="kp-returned-status-box" id="kp-live-status-box-<?php echo esc_attr( $c['log_id'] ); ?>" style="display:<?php echo $is_returned ? 'block' : 'none'; ?>;">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:6px;">
                                            <div>
                                                <div class="kp-status-line-1" id="kp-live-headline-<?php echo esc_attr( $c['log_id'] ); ?>" style="color:#6d28d9;">
                                                    <?php echo esc_html( $c['status_headline'] ?: 'DELIVERY RETURNED' ); ?>
                                                </div>
                                                <div class="kp-status-line-2" id="kp-live-byline-<?php echo esc_attr( $c['log_id'] ); ?>">
                                                    <?php echo esc_html( $c['status_byline'] ); ?>
                                                </div>
                                            </div>
                                            <button type="button" class="kp-cancelled-expand-btn kp-live-collapse-btn cmp-no-print" style="display:none;"
                                                    onclick="this.closest('tr.k-row').classList.remove('is-expanded');">
                                                [- Collapse ▲]
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #ccc;">
                                    <div style="margin-bottom: 5px;">
                                        <strong>
                                            <?php echo esc_html( $plan_duration ); ?>
                                            (<?php echo esc_html( $c['day_number'] ); ?>/<?php echo esc_html( $c['total_days'] ); ?><?php echo $is_unscheduled ? ' Used' : ''; ?>)
                                        </strong><br>
                                        <span style="color:#64748b; font-size:0.9em;"><?php echo esc_html( $plan_meals ); ?></span>
                                    </div>
                                    <?php if ( ! empty( $c['foh_remarks'] ) ) : ?>
                                        <div style="background:#EEF7E3; color:#1B5E20; padding:6px 8px; border-radius:4px; font-size:0.85em; margin-top:8px; border-left:3px solid #379237; line-height:1.3;">
                                            <strong>FOH Note:</strong> <?php echo esc_html( $c['foh_remarks'] ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td class="kp-regular-cell" style="text-align:center; background:#f0fdf4; border-left:1px solid #ddd;">
                                <span class="kp-mob-cell-label">Prepared</span>
                                <?php if ( $is_unscheduled ) : ?>
                                    <span style="color:#94a3b8; font-weight:700;">&mdash;</span>
                                <?php elseif ( $is_pre_prep_cancel ) : ?>
                                    <span class="kp-exempt-pill" title="Exempt: Cancelled before preparation">Exempt</span>
                                <?php else : ?>
                                    <input type="checkbox" class="chk-large cmp-prepared-box cmp-no-print" data-id="<?php echo esc_attr( $c['log_id'] ); ?>" <?php echo $c['prepared'] ? 'checked' : ''; ?> <?php echo $prepared_disabled; ?>>
                                    <?php if ( $c['prepared'] ) echo '<span style="display:none; color:#166534; font-weight:bold;" class="print-only">Yes</span>'; ?>
                                <?php endif; ?>
                            </td>

                            <td class="kp-regular-cell" style="text-align:center; background:#fcfcfc;">
                                <span class="kp-mob-cell-label">Dispatch</span>
                                <?php if ( $is_unscheduled ) : ?>
                                    <span style="color:#94a3b8; font-weight:700;">&mdash;</span>
                                <?php elseif ( $is_pre_prep_cancel ) : ?>
                                    <span class="kp-exempt-pill" title="Exempt: Cancelled before dispatch">Exempt</span>
                                <?php else : ?>
                                    <input type="checkbox" class="chk-large cmp-dispatch-box cmp-no-print" data-id="<?php echo esc_attr( $c['log_id'] ); ?>" <?php echo $c['dispatch'] ? 'checked' : ''; ?> <?php echo $dispatch_disabled; ?>>
                                    <?php if ( $c['dispatch'] ) echo '<span style="display:none; color:green; font-weight:bold;" class="print-only">Yes</span>'; ?>
                                <?php endif; ?>
                            </td>

                            <td class="kp-regular-cell" style="background:#fcfcfc; text-align:center;">
                                <span class="kp-mob-cell-label">Delivery</span>
                                <?php if ( $is_unscheduled ) : ?>
                                    <span style="color:<?php echo $is_paused_cust ? '#b45309' : '#94a3b8'; ?>; font-size:0.85em; font-weight:700; display:block; text-align:center;">
                                        <?php echo $is_paused_cust ? 'Plan Paused' : 'No Selection'; ?>
                                    </span>
                                <?php elseif ( $is_pre_prep_cancel ) : ?>
                                    <span class="kp-exempt-pill" style="background:#fff1f2; color:#be123c; border-color:#fda4af;" title="Cancelled prior to preparation">Cancelled</span>
                                <?php else : ?>
                                    <select class="cmp-delivery-box" data-id="<?php echo esc_attr( $c['log_id'] ); ?>" style="width:100%; padding:5px 4px; font-size:0.88em; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" <?php echo $delivery_disabled; ?>>
                                        <option value="Pending" <?php selected( $c['delivery'], 'Pending' ); ?>>Pending</option>
                                        <option value="Successful" <?php selected( $c['delivery'], 'Successful' ); ?>>Successful</option>
                                        <option value="Cancelled" <?php selected( $c['delivery'], 'Cancelled' ); ?>>Cancelled</option>
                                        <option value="Returned" <?php selected( $c['delivery'], 'Returned' ); ?>>Returned</option>
                                    </select>
                                <?php endif; ?>
                            </td>

                            <td class="kp-regular-cell" style="text-align:center; background:#f4f8fa;">
                                <span class="kp-mob-cell-label">POS Check</span>
                                <?php if ( $is_unscheduled ) : ?>
                                    <span style="color:#94a3b8; font-weight:700;">&mdash;</span>
                                <?php else : ?>
                                    <!-- Option 4A: Compact Status Pill when Cancelled or Returned (Fits 100% inside POS Column) -->
                                    <span class="kp-exempt-pill kp-no-pos-pill" id="kp-no-pos-pill-<?php echo esc_attr( $c['log_id'] ); ?>" style="display:<?php echo ( $is_cancelled || $is_returned ) ? 'inline-block' : 'none'; ?>;" title="No POS reconciliation required for Cancelled or Returned meals">No POS Req.</span>
                                    <div class="kp-pos-cell-inner" id="kp-pos-wrap-<?php echo esc_attr( $c['log_id'] ); ?>" style="display:<?php echo ( $is_cancelled || $is_returned ) ? 'none' : 'flex'; ?>;">
                                        <span class="kp-pos-cue <?php echo esc_attr( $active_cue_class ); ?> cmp-no-print"
                                              data-id="<?php echo esc_attr( $c['log_id'] ); ?>"
                                              data-base-class="<?php echo esc_attr( $base_cue_class ); ?>"
                                              data-eat-short="<?php echo esc_attr( $eat_short ); ?>"
                                              title="Post in POS on Customer Eating Date: <?php echo esc_attr( $eat_full ); ?>">
                                            <?php echo esc_html( $pos_cue_text ); ?>
                                        </span>
                                        <input type="checkbox" class="chk-large cmp-pos-box cmp-no-print" data-id="<?php echo esc_attr( $c['log_id'] ); ?>" <?php echo $c['pos'] ? 'checked' : ''; ?> <?php echo $pos_disabled; ?>>
                                    </div>
                                    <?php if ( $c['pos'] && ! $is_cancelled && ! $is_returned ) echo '<span style="display:none; color:green; font-weight:bold;" class="print-only">Yes</span>'; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var canEditDelivery = <?php echo empty( $chef_disabled ) ? 'true' : 'false'; ?>;
        var canEditPos      = <?php echo empty( $foh_disabled ) ? 'true' : 'false'; ?>;

        function kpShowNotice(message, type) {
            $('.kp-toast-notice').remove();
            var isSuccess = (type === 'success');
            var $toast = $('<div>', {
                'class': 'kp-toast-notice',
                'text': message,
                'css': {
                    position: 'fixed',
                    top: '22px',
                    left: '50%',
                    transform: 'translateX(-50%)',
                    background: isSuccess ? '#1B5E20' : '#dc2626',
                    color: '#ffffff',
                    border: '1.5px solid ' + (isSuccess ? '#68B946' : '#fca5a5'),
                    borderRadius: '10px',
                    padding: '11px 20px',
                    fontSize: '0.88rem',
                    fontWeight: '800',
                    boxShadow: '0 10px 25px rgba(15, 23, 42, 0.25)',
                    zIndex: 100099,
                    maxWidth: '92vw',
                    textAlign: 'center'
                }
            }).appendTo('body');

            setTimeout(function() {
                $toast.fadeOut(250, function() { $(this).remove(); });
            }, 3500);
        }

        function syncKitchenStickyOffsets() {
            var rootEl   = document.getElementById('kp-portal-root');
            var bannerEl = document.getElementById('kp-sticky-banner');
            if (!rootEl || !bannerEl) return;

            var adminBar    = document.getElementById('wpadminbar');
            var adminOffset = 0;
            if (adminBar) {
                var adminStyle = window.getComputedStyle(adminBar);
                if (adminStyle.position === 'fixed') {
                    adminOffset = adminBar.offsetHeight || 0;
                }
            }

            var bannerHeight = bannerEl.offsetHeight || 82;
            rootEl.style.setProperty('--kp-admin-offset', adminOffset + 'px');
            rootEl.style.setProperty('--kp-banner-height', bannerHeight + 'px');

            if (window.scrollY > 40) {
                bannerEl.classList.add('kp-is-scrolled');
            } else {
                bannerEl.classList.remove('kp-is-scrolled');
            }
        }

        syncKitchenStickyOffsets();
        $(window).on('resize orientationchange scroll', syncKitchenStickyOffsets);

        // Staff-Only Prep Schedule Override Handler (FOH, Menu Manager & Admin)
        $('.kp-prep-override-select').on('change', function() {
            var $sel       = $(this);
            var subId      = $sel.data('sub-id');
            var prepTiming = $sel.val();
            $sel.prop('disabled', true);

            $.post("<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>", {
                action: 'cmp_update_prep_timing',
                nonce: "<?php echo esc_js( wp_create_nonce( 'cmp_kitchen_nonce' ) ); ?>",
                sub_id: subId,
                prep_timing: prepTiming
            }, function(res) {
                if (res && res.success) {
                    kpShowNotice('Kitchen Prep Schedule updated! Reloading list...', 'success');
                    setTimeout(function() { location.reload(); }, 650);
                } else {
                    kpShowNotice((res && res.data) ? res.data : 'Error updating prep schedule.', 'error');
                    $sel.prop('disabled', false);
                }
            }).fail(function() {
                kpShowNotice('Network error while updating prep schedule.', 'error');
                $sel.prop('disabled', false);
            });
        });

        function populateDynamicDropdowns(container) {
            var isCustom = container.data('allow-custom') == 1;
            var dateVal  = container.attr('data-target-date') || "<?php echo esc_js( $target_date ); ?>";
            var selects  = container.find('select.chef-meal-select, select[class^="cmp-juice-"]');
            var inputs   = container.find('input.cmp-meal-custom');
            var btn      = container.find('.save-chef-assign');
            
            container.find('.boundary-overlay').remove();

            if (isCustom) {
                inputs.show();
                selects.hide();
                return true;
            }

            var availableFoods = cmpGlobalFoodData.filter(function(food) {
                if (!food.valid_from || !food.valid_until) return true; 
                return (dateVal >= food.valid_from && dateVal <= food.valid_until);
            });

            if (availableFoods.length === 0) {
                selects.hide();
                inputs.hide();
                container.prepend('<div class="boundary-overlay" style="color:#b45309; font-weight:bold; font-size:0.9em; background:#fef3c7; padding:10px; border-radius:4px; text-align:center;">Menu Pending Release</div>');
                btn.text('Menu Pending').css({'background':'#94a3b8', 'color':'#fff', 'cursor':'not-allowed'}).prop('disabled', true);
                return false;
            }

            selects.show();
            inputs.hide();
            selects.each(function() {
                var select      = $(this);
                var category    = select.data('cat');
                var savedVal    = select.data('saved-val');
                var placeholder = select.find('option').first().text();
                
                select.empty().append('<option value="">' + placeholder + '</option>');
                
                availableFoods.forEach(function(food) {
                    if (category === 'Any' && (food.category_name === 'Breakfast' || food.category_name === 'Lunch' || food.category_name === 'Dinner')) {
                        var isSelected = (savedVal == food.id) ? 'selected' : '';
                        select.append('<option value="'+food.id+'" '+isSelected+'>['+food.category_name+'] '+food.food_name+'</option>');
                    } else if (food.category_name === category) {
                        var isSelected = (savedVal == food.id) ? 'selected' : '';
                        select.append('<option value="'+food.id+'" '+isSelected+'>'+food.food_name+'</option>');
                    }
                });
            });
            return true;
        }

        $('.chef-assign-form').each(function() {
            populateDynamicDropdowns($(this));
        });

        function updatePendingPrepBadgeCount() {
            var count = 0;
            $('.k-table tbody tr.k-row').each(function() {
                if ($(this).attr('data-pending-prep') === '1') count++;
            });
            var $badge = $('#k-pending-prep-count');
            if ($badge.length) {
                $badge.text(count);
                $badge.css('background', count > 0 ? '#f59e0b' : '#16a34a');
            }
        }

        var urlParams  = new URLSearchParams(window.location.search);
        var initialTab = urlParams.get('k_tab') || 'all';
        if ($('.k-tab-btn[data-filter="' + initialTab + '"]').length === 0) initialTab = 'all';
        
        $('.k-tab-btn').removeClass('active');
        $('.k-tab-btn[data-filter="' + initialTab + '"]').addClass('active');

        function applyKitchenFilters() {
            var activeFilter = $('.k-tab-btn.active').data('filter') || 'all';
            var searchQuery  = ($('#k-search').val() || '').toLowerCase();
            var visibleCount = 0;

            $('.k-table tbody tr.k-row').each(function() {
                var row          = $(this);
                var customerName = (row.data('name') || '').toString();
                var inOrders     = row.attr('data-in-orders') === '1';
                var inRoster     = row.attr('data-in-roster') === '1';
                var pendingPrep  = row.attr('data-pending-prep') === '1';
                
                var matchTab = false;
                if (activeFilter === 'all' && inOrders) {
                    matchTab = true;
                } else if (activeFilter === 'pending_prep' && pendingPrep) {
                    matchTab = true;
                } else if (activeFilter === 'pending' && row.hasClass('filter-pending')) {
                    matchTab = true;
                } else if (activeFilter === 'roster' && inRoster) {
                    matchTab = true;
                }

                var matchSearch = customerName.indexOf(searchQuery) > -1;

                if (matchTab && matchSearch) {
                    row.removeClass('k-row-hidden').show();
                    visibleCount++;
                } else {
                    row.addClass('k-row-hidden').hide();
                }
            });

            if (visibleCount === 0) {
                if ($('#k-empty-msg').length === 0) {
                    $('.k-table tbody').append('<tr id="k-empty-msg"><td colspan="7" style="text-align:center; padding:30px; color:#666; font-size:1.1em;">No customers match your criteria in this view.</td></tr>');
                } else {
                    $('#k-empty-msg').show();
                }
            } else {
                $('#k-empty-msg').hide();
            }
        }

        applyKitchenFilters();

        $('.k-tab-btn').on('click', function() {
            $('.k-tab-btn').removeClass('active');
            $(this).addClass('active');
            var url = new URL(window.location);
            url.searchParams.set('k_tab', $(this).data('filter'));
            window.history.pushState({}, '', url);
            applyKitchenFilters();
        });

        $('#k-search').on('keyup', function() {
            applyKitchenFilters();
        });

        function enforceChefMealQuota(container) {
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var isJuice      = container.data('is-juice') == '1';
            if (allowedMeals <= 0 || isJuice) return; 

            var mainSelects   = container.find('.cmp-main-meal');
            var selectedCount = 0;
            mainSelects.each(function() { if ($(this).val().trim() !== "") selectedCount++; });

            if (selectedCount >= allowedMeals) {
                mainSelects.each(function() {
                    if ($(this).val().trim() === "") $(this).prop('disabled', true).addClass('quota-locked');
                    else $(this).prop('disabled', false).removeClass('quota-locked');
                });
            } else {
                mainSelects.each(function() { $(this).prop('disabled', false).removeClass('quota-locked'); });
            }
        }

        $('.chef-assign-form').each(function() { enforceChefMealQuota($(this)); });
        $(document).on('change keyup', '.cmp-main-meal', function() { enforceChefMealQuota($(this).closest('.chef-assign-form')); });

        $('.edit-chef-assign').on('click', function() {
            var cell = $(this).closest('td');
            cell.find('.chef-assigned-view').hide();
            cell.find('.chef-assign-form').show();
        });

        $('.save-chef-assign').on('click', function() {
            var btn          = $(this);
            var container    = btn.closest('.chef-assign-form');
            var logId        = container.data('log-id');
            var isCustom     = container.data('allow-custom') == '1';
            
            btn.text('Saving...').prop('disabled', true).css('opacity', '0.7');
            
            var data = {
                action: 'cmp_assign_chef_meals',
                nonce: '<?php echo esc_js( wp_create_nonce( "cmp_kitchen_nonce" ) ); ?>',
                log_id: logId
            };

            if (isCustom) {
                data.custom_breakfast = container.find('[data-slot="breakfast"]').val() || null;
                data.custom_lunch     = container.find('[data-slot="lunch"]').val() || null;
                data.custom_dinner    = container.find('[data-slot="dinner"]').val() || null;
                data.custom_snack_1   = container.find('[data-slot="snack_1"]').val() || null;
                data.custom_snack_2   = container.find('[data-slot="snack_2"]').val() || null;
                data.custom_juice_1   = container.find('[data-slot="juice_1"]').val() || null;
                data.custom_juice_2   = container.find('[data-slot="juice_2"]').val() || null;
                data.custom_juice_3   = container.find('[data-slot="juice_3"]').val() || null;
            } else {
                data.breakfast = container.find('[data-slot="breakfast"]').val() || null;
                data.lunch     = container.find('[data-slot="lunch"]').val() || null;
                data.dinner    = container.find('[data-slot="dinner"]').val() || null;
                data.snack_1   = container.find('[data-slot="snack_1"]').val() || null;
                data.snack_2   = container.find('[data-slot="snack_2"]').val() || null;
                data.juice_1   = container.find('[data-slot="juice_1"]').val() || null;
                data.juice_2   = container.find('[data-slot="juice_2"]').val() || null;
                data.juice_3   = container.find('[data-slot="juice_3"]').val() || null;
            }
            
            $.post("<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>", data, function(res) {
                if (res.success) { location.reload(); }
                else {
                    kpShowNotice(res.data || 'Error saving assignment.', 'error');
                    btn.text('Save').prop('disabled', false).css('opacity', '1');
                }
            });
        });

        function saveAction(logId, field, val) {
            $.post("<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>", {
                action: 'cmp_kitchen_action',
                nonce: "<?php echo esc_js( wp_create_nonce( 'cmp_kitchen_nonce' ) ); ?>",
                log_id: logId,
                field: field,
                value: val
            }, function(res) {
                if (!res.success) { 
                    kpShowNotice(res.data || 'Permission Denied.', 'error'); 
                    setTimeout(function() { location.reload(); }, 900);
                } else if (field === 'delivery_result') {
                    var $row         = $('tr.k-row[data-log-id="' + logId + '"]');
                    var $liveBox     = $('#kp-live-status-box-' + logId);
                    var $liveHead    = $('#kp-live-headline-' + logId);
                    var $liveBy      = $('#kp-live-byline-' + logId);
                    var $barHead     = $('#kp-bar-headline-' + logId);
                    var $barBy       = $('#kp-bar-byline-' + logId);
                    var $meta        = $('#kp-cancel-meta-' + logId);
                    var $collapseBtn = $liveBox.find('.kp-live-collapse-btn');

                    if (val === 'Cancelled' || val === 'Returned') {
                        if (res.data && res.data.status_headline) {
                            if ($liveHead.length) $liveHead.text(res.data.status_headline).css('color', val === 'Returned' ? '#6d28d9' : '#b45309');
                            if ($barHead.length) $barHead.text('✕ ' + res.data.status_headline);
                        }
                        if (res.data && res.data.status_byline) {
                            if ($liveBy.length) $liveBy.text(res.data.status_byline);
                            if ($barBy.length) $barBy.text('— ' + res.data.status_byline);
                            if ($meta.length) $meta.text(res.data.status_byline);
                        }
                        if ($liveBox.length) $liveBox.slideDown(150);
                        if (val === 'Cancelled') {
                            if ($collapseBtn.length) $collapseBtn.show();
                            $row.addClass('row-is-cancelled is-expanded').attr('data-is-cancelled', '1').attr('data-pending-prep', '0');
                        } else {
                            if ($collapseBtn.length) $collapseBtn.hide();
                            $row.removeClass('row-is-cancelled is-expanded').attr('data-is-cancelled', '0');
                        }
                    } else {
                        if ($liveBox.length) $liveBox.slideUp(150);
                        $row.removeClass('row-is-cancelled is-expanded').attr('data-is-cancelled', '0');
                    }
                    updatePendingPrepBadgeCount();
                    applyKitchenFilters();
                }
            });
        }

        function updatePosCueBadge(logId, isPosChecked) {
            var cueEl = $('.kp-pos-cue[data-id="' + logId + '"]');
            if (!cueEl.length) return;
            var baseClass = cueEl.data('base-class') || 'cue-upcoming';
            var eatShort  = cueEl.data('eat-short') || '';
            cueEl.removeClass('cue-upcoming cue-today cue-overdue cue-done');
            if (isPosChecked) {
                cueEl.addClass('cue-done').text('Post on ' + eatShort + ' ✓');
            } else {
                cueEl.addClass(baseClass).text('Post on ' + eatShort);
            }
        }

        function updateRowLogic(logId) {
            var $row        = $('tr.k-row[data-log-id="' + logId + '"]');
            var preparedBox = $('.cmp-prepared-box[data-id="' + logId + '"]');
            var dispatchBox = $('.cmp-dispatch-box[data-id="' + logId + '"]');
            var deliveryBox = $('.cmp-delivery-box[data-id="' + logId + '"]');
            var posBox      = $('.cmp-pos-box[data-id="' + logId + '"]');
            var posWrap     = $('#kp-pos-wrap-' + logId);
            var noPosPill   = $('#kp-no-pos-pill-' + logId);
            
            var isPrepared     = preparedBox.is(':checked');
            var isDispatched   = dispatchBox.is(':checked');
            var deliveryResult = deliveryBox.val();
            var isPosChecked   = posBox.is(':checked');
            var isExpired      = $row.attr('data-is-expired') === '1';
            var isCancelled    = (deliveryResult === 'Cancelled');
            var isReturned     = (deliveryResult === 'Returned');

            if (!isCancelled && !isExpired && !isPrepared) {
                $row.attr('data-pending-prep', '1');
            } else {
                $row.attr('data-pending-prep', '0');
            }
            updatePendingPrepBadgeCount();

            if (isCancelled || isReturned) {
                posWrap.hide();
                noPosPill.show();
                if (isPosChecked) {
                    posBox.prop('checked', false);
                    isPosChecked = false;
                }
            } else {
                noPosPill.hide();
                posWrap.css('display', 'flex');
            }
            
            if (isPosChecked) {
                preparedBox.prop('disabled', true);
                dispatchBox.prop('disabled', true);
                deliveryBox.prop('disabled', true);
            } else {
                if (canEditDelivery) {
                    if (isDispatched) {
                        preparedBox.prop('disabled', true);
                        deliveryBox.prop('disabled', false);
                    } else {
                        preparedBox.prop('disabled', isCancelled);
                        if (!isCancelled) {
                            deliveryBox.prop('disabled', true);
                            if (deliveryResult !== 'Pending') {
                                deliveryBox.val('Pending');
                                saveAction(logId, 'delivery_result', 'Pending');
                                deliveryResult = 'Pending';
                            }
                        }
                    }
                    
                    if (isPrepared && !isCancelled) {
                        dispatchBox.prop('disabled', false);
                    } else {
                        dispatchBox.prop('disabled', true);
                        if (isDispatched && !isCancelled) {
                            dispatchBox.prop('checked', false);
                            saveAction(logId, 'dispatch_status', 0);
                            isDispatched = false;
                            deliveryBox.prop('disabled', true);
                        }
                    }
                }
            }
            
            if (canEditPos) {
                if (isDispatched && deliveryResult === 'Successful') {
                    posBox.prop('disabled', false);
                } else {
                    posBox.prop('disabled', true);
                    if (isPosChecked) {
                        posBox.prop('checked', false);
                        saveAction(logId, 'pos_updated', 0);
                        isPosChecked = false;
                    }
                }
            }

            updatePosCueBadge(logId, isPosChecked);
            applyKitchenFilters();
        }

        $('.cmp-prepared-box').on('change', function() {
            var logId = $(this).data('id');
            saveAction(logId, 'is_prepared', $(this).is(':checked') ? 1 : 0);
            updateRowLogic(logId);
        });

        $('.cmp-dispatch-box').on('change', function() {
            var logId = $(this).data('id');
            saveAction(logId, 'dispatch_status', $(this).is(':checked') ? 1 : 0);
            updateRowLogic(logId);
        });

        $('.cmp-delivery-box').on('change', function() {
            var logId = $(this).data('id');
            saveAction(logId, 'delivery_result', $(this).val());
            updateRowLogic(logId);
        });

        $('.cmp-pos-box').on('change', function() {
            var logId = $(this).data('id');
            saveAction(logId, 'pos_updated', $(this).is(':checked') ? 1 : 0);
            updateRowLogic(logId);
        });

    });
    </script>
    <?php
    return ob_get_clean();
}
