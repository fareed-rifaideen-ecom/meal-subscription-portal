<?php
/**
 * Kitchen Command Center & Daily Prep Reporting Portal
 *
 * Complete Merged Architecture (Phase 3 & Phase 4 Upgrades):
 *  - Removed "Completed Assignments" Tab
 *  - Added "Pending Preparation" Tab with live dynamic counter badge
 *  - Option 5A: Added "Full Customer Roster" Tab showing all Active & Paused subscribers (including "No Food Selection Today")
 *  - Displays Cancelled status with Who cancelled it (Name & Role) and When (Date & Time)
 *  - Displays Driver Attribution (Option 3A Pill Badge / Option 3B Micro-Text Stamp) & Driver Notes under Delivery Status
 *  - Includes "Delivered By" and "Driver Note" columns in Kitchen CSV Exports
 *  - Inline Recipient Pill Badge next to Customer Name (without "For:")
 *  - Synchronized with Customer Activity Logs (Meal Portal > Customer Logs)
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
// 0. FALLBACK PER-PLAN LOGISTICS RESOLVER
//    (Guarantees standalone operation if loaded before customer-portal-backend.php)
// ==========================================
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
 * Formats the "Cancelled by [User] ([Role]) on [Date & Time]" string for a cancelled daily log row.
 */
if ( ! function_exists( 'cmp_get_kitchen_cancelled_stamp' ) ) {
    function cmp_get_kitchen_cancelled_stamp( $log ) {
        global $wpdb;

        $by_name = ! empty( $log->updated_by_name ) ? $log->updated_by_name : '';
        $by_role = ! empty( $log->updated_by_role ) ? $log->updated_by_role : '';
        $at_time = ( ! empty( $log->updated_at ) && $log->updated_at !== '0000-00-00 00:00:00' ) ? $log->updated_at : '';

        // Fallback lookup in wp_cmp_activity_logs if the row was cancelled before row-level columns existed
        if ( empty( $by_name ) && ! empty( $log->subscription_id ) && ! empty( $log->target_date ) ) {
            $table_activity = $wpdb->prefix . 'cmp_activity_logs';
            $act_exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_activity ) );
            if ( $act_exists === $table_activity ) {
                $act_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT actor_name, actor_role, created_at FROM {$table_activity} WHERE subscription_id = %d AND target_date = %s AND action_type LIKE %s ORDER BY id DESC LIMIT 1",
                    intval( $log->subscription_id ),
                    $log->target_date,
                    '%Cancel%'
                ) );
                if ( $act_row ) {
                    $by_name = $act_row->actor_name;
                    $by_role = $act_row->actor_role;
                    $at_time = $act_row->created_at;
                }
            }
        }

        if ( ! empty( $by_name ) ) {
            $role_str = ! empty( $by_role ) ? ' (' . $by_role . ')' : '';
            $time_str = ! empty( $at_time ) ? ' on ' . date( 'd M Y, h:i A', strtotime( $at_time ) ) : '';
            return 'Cancelled by ' . $by_name . $role_str . $time_str;
        }

        return 'Cancelled prior to cut-off';
    }
}

/**
 * Formats the Driver Attribution & Driver Note HTML displayed underneath the Delivery dropdown
 * in the Kitchen Portal according to Option 3A (Pill Badge) or Option 3B (Micro-Text Stamp).
 */
if ( ! function_exists( 'cmp_format_kitchen_driver_attribution' ) ) {
    function cmp_format_kitchen_driver_attribution( $log, $attr_mode = null ) {
        if ( empty( $log ) || is_null( $log ) ) {
            return '';
        }
        if ( is_array( $log ) ) {
            $log = (object) $log;
        }

        $del_res = ! empty( $log->delivery_result ) ? $log->delivery_result : 'Pending';
        if ( $del_res === 'Pending' ) {
            return '';
        }

        $by_name = ! empty( $log->delivered_by_name ) ? $log->delivered_by_name : ( ! empty( $log->updated_by_name ) ? $log->updated_by_name : '' );
        $by_role = ! empty( $log->delivered_by_role ) ? $log->delivered_by_role : ( ! empty( $log->updated_by_role ) ? $log->updated_by_role : 'Driver' );
        $at_raw  = ( ! empty( $log->delivered_at ) && $log->delivered_at !== '0000-00-00 00:00:00' )
            ? $log->delivered_at
            : ( ( ! empty( $log->updated_at ) && $log->updated_at !== '0000-00-00 00:00:00' ) ? $log->updated_at : '' );
        $d_note  = ! empty( $log->driver_note ) ? trim( (string) $log->driver_note ) : '';

        if ( empty( $by_name ) && empty( $d_note ) ) {
            return '';
        }

        if ( $attr_mode === null ) {
            $attr_mode = get_option( 'cmp_driver_kitchen_attr', '3A' );
        }

        $html = '';

        if ( ! empty( $by_name ) ) {
            if ( $attr_mode === '3B' ) {
                // Option 3B: Plain Micro-Text Stamp
                $time_fmt = ! empty( $at_raw ) ? ' • ' . date( 'd M, h:i A', strtotime( $at_raw ) ) : '';
                $role_fmt = ! empty( $by_role ) ? ' (' . $by_role . ')' : '';
                $html .= '<div class="kp-driver-micro">Updated by ' . esc_html( $by_name . $role_fmt . $time_fmt ) . '</div>';
            } else {
                // Option 3A (Default): Compact Color-Coded Pill Badge
                $time_short = ! empty( $at_raw ) ? ' • ' . date( 'h:i A', strtotime( $at_raw ) ) : '';
                $pill_tone  = ( $del_res === 'Successful' ) ? 'pill-success' : 'pill-issue';
                $html .= '<div class="kp-driver-pill ' . esc_attr( $pill_tone ) . '" title="Updated by ' . esc_attr( $by_name . ' (' . $by_role . ')' ) . '">🚚 ' . esc_html( $by_name . $time_short ) . '</div>';
            }
        }

        if ( ! empty( $d_note ) ) {
            $html .= '<div class="kp-driver-note-box"><strong>Driver Note:</strong> ' . esc_html( $d_note ) . '</div>';
        }

        return $html;
    }
}

// ==========================================
// 1. AJAX HANDLERS: KITCHEN ACTIONS & EXPORT
// ==========================================

add_action( 'wp_ajax_cmp_kitchen_action', 'cmp_ajax_kitchen_action' );
function cmp_ajax_kitchen_action() {
    check_ajax_referer( 'cmp_kitchen_nonce', 'nonce' );
    global $wpdb;

    if ( function_exists( 'cmp_auto_patch_database' ) ) {
        cmp_auto_patch_database();
    }

    $log_id = intval( $_POST['log_id'] );
    $field  = sanitize_text_field( $_POST['field'] );
    $value  = sanitize_text_field( $_POST['value'] );

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
        if ( $current_log && $current_log->pos_updated == 1 ) {
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

    $update_data      = array( $field => $value );
    $cancelled_stamp  = '';
    $driver_attr_html = '';

    // Stamp Actor & Record Activity Log when delivery_result is updated (e.g. Cancelled, Returned, Successful)
    if ( $field === 'delivery_result' ) {
        $actor = function_exists( 'cmp_get_current_actor_info' )
            ? cmp_get_current_actor_info( $current_log->user_id )
            : array(
                'id'   => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
                'role' => $is_admin ? 'Admin' : 'Kitchen',
            );

        $tz           = new DateTimeZone( 'Asia/Dubai' );
        $now_datetime = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        $action_label = ( $value === 'Cancelled' ) ? 'Cancelled' : 'Delivery: ' . $value;

        $update_data['updated_by_id']     = $actor['id'];
        $update_data['updated_by_name']   = $actor['name'];
        $update_data['updated_by_role']   = $actor['role'];
        $update_data['last_action_type']  = $action_label;
        $update_data['updated_at']        = $now_datetime;
        $update_data['delivered_by_id']   = ( $value === 'Pending' ) ? 0 : $actor['id'];
        $update_data['delivered_by_name'] = ( $value === 'Pending' ) ? '' : $actor['name'];
        $update_data['delivered_by_role'] = ( $value === 'Pending' ) ? '' : $actor['role'];
        $update_data['delivered_at']      = ( $value === 'Pending' ) ? null : $now_datetime;
        if ( $value === 'Pending' ) {
            $update_data['driver_note'] = null;
        }

        if ( $value === 'Cancelled' ) {
            $cancelled_stamp = sprintf(
                'Cancelled by %s (%s) on %s',
                $actor['name'],
                $actor['role'],
                date( 'd M Y, h:i A', strtotime( $now_datetime ) )
            );
        }

        $mock_log         = (object) array_merge( (array) $current_log, $update_data );
        $driver_attr_html = cmp_format_kitchen_driver_attribution( $mock_log );

        if ( function_exists( 'cmp_record_customer_activity_log' ) && $current_log->delivery_result !== $value ) {
            $old_snap = function_exists( 'cmp_format_log_meal_snapshot' )
                ? cmp_format_log_meal_snapshot( $current_log )
                : 'Delivery: ' . ( $current_log->delivery_result ?: 'Pending' );

            cmp_record_customer_activity_log( array(
                'subscription_id' => intval( $current_log->subscription_id ),
                'action_type'     => ( $value === 'Cancelled' ) ? 'Meal Cancelled' : 'Delivery Status Updated',
                'target_date'     => $current_log->target_date,
                'old_status'      => $old_snap,
                'new_status'      => 'Delivery Status: ' . $value . ' (' . $current_log->target_date . ')',
                'actor_id'        => $actor['id'],
                'actor_name'      => $actor['name'],
                'actor_role'      => $actor['role'],
            ) );
        }
    }

    $wpdb->update( $table_logs, $update_data, array( 'id' => $log_id ) );
    wp_send_json_success( array(
        'cancelled_stamp'  => $cancelled_stamp,
        'driver_attr_html' => $driver_attr_html,
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

    $log_row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_logs} WHERE id = %d", $log_id ) );
    $log_mode = null;

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

        'custom_breakfast' => ! empty( $_POST['custom_breakfast'] ) ? sanitize_text_field( $_POST['custom_breakfast'] ) : null,
        'custom_lunch'     => ! empty( $_POST['custom_lunch'] ) ? sanitize_text_field( $_POST['custom_lunch'] ) : null,
        'custom_dinner'    => ! empty( $_POST['custom_dinner'] ) ? sanitize_text_field( $_POST['custom_dinner'] ) : null,
        'custom_snack_1'   => ! empty( $_POST['custom_snack_1'] ) ? sanitize_text_field( $_POST['custom_snack_1'] ) : null,
        'custom_snack_2'   => ! empty( $_POST['custom_snack_2'] ) ? sanitize_text_field( $_POST['custom_snack_2'] ) : null,
        'custom_juice_1'   => ! empty( $_POST['custom_juice_1'] ) ? sanitize_text_field( $_POST['custom_juice_1'] ) : null,
        'custom_juice_2'   => ! empty( $_POST['custom_juice_2'] ) ? sanitize_text_field( $_POST['custom_juice_2'] ) : null,
        'custom_juice_3'   => ! empty( $_POST['custom_juice_3'] ) ? sanitize_text_field( $_POST['custom_juice_3'] ) : null,

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

    global $wpdb;
    date_default_timezone_set( 'Asia/Dubai' );

    $prep_date   = isset( $_GET['prep_date'] ) ? sanitize_text_field( $_GET['prep_date'] ) : date( 'Y-m-d' );
    $export_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'meals';
    $target_date = date( 'Y-m-d', strtotime( $prep_date . ' + 1 day' ) );
    $today_dt    = date( 'Y-m-d H:i:s' );

    // Include scheduled logs for active subscriptions (including Cancelled so staff have full visibility in CSV)
    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, s.expiry_date, s.foh_remarks, s.is_flexible, s.allow_custom,
                s.delivery_method, s.pickup_location, s.delivery_timing, s.time_slot, s.allergies, s.delivery_address,
                u.display_name, u.user_email 
         FROM {$wpdb->prefix}cmp_daily_logs l 
         JOIN {$wpdb->prefix}cmp_subscriptions s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON l.user_id = u.ID 
         WHERE l.target_date = %s AND l.is_locked = 1 AND s.status != 'paused'",
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
        fputcsv( $output, array( 'Order ID', 'Customer Name', 'Recipient Name', 'Email', 'Phone', 'Eating Date', 'Address', 'Method', 'Receive By', 'Delivery Date', 'Time Slot', 'Allergies', 'FOH Note', 'Plan', 'Juice 1', 'Juice 2', 'Juice 3', 'Prepared', 'Dispatched', 'Delivery / Cancellation Info', 'Delivered By', 'Driver Note', 'POS Check' ) );
    } else {
        fputcsv( $output, array( 'Order ID', 'Customer Name', 'Recipient Name', 'Email', 'Phone', 'Eating Date', 'Address', 'Method', 'Receive By', 'Delivery Date', 'Time Slot', 'Allergies', 'FOH Note', 'Plan', 'Breakfast / Meal 1', 'Lunch / Meal 2', 'Dinner / Meal 3', 'Snack 1', 'Snack 2', 'Prepared', 'Dispatched', 'Delivery / Cancellation Info', 'Delivered By', 'Driver Note', 'POS Check' ) );
    }

    $export_rows       = array();
    $scheduled_sub_ids = array();

    foreach ( $logs as $log ) {
        $scheduled_sub_ids[ intval( $log->subscription_id ) ] = true;
        $is_juice_plan = ( stripos( $log->allowed_categories, 'Juices' ) !== false || stripos( $log->plan_name, 'juice' ) !== false || stripos( $log->plan_name, 'cleanse' ) !== false );

        if ( $export_type === 'juices' && ! $is_juice_plan ) continue;
        if ( $export_type === 'meals'  && $is_juice_plan )   continue;

        $resolved  = cmp_get_subscription_logistics( $log );
        $full_name = $resolved['full_name'];
        $email     = $resolved['email'];
        $phone     = $resolved['phone'];
        $address   = $resolved['address'];

        $timing        = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'N/A';
        $timing        = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing );
        $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );
        $delivery_date = $is_day_before ? $prep_date : $log->target_date;

        $time_slot = ! empty( $resolved['time_slot'] ) ? $resolved['time_slot'] : 'N/A';
        $method    = ! empty( $resolved['method'] ) ? $resolved['method'] : 'N/A';
        $pickup    = $resolved['pickup'];
        if ( $method === 'Pickup' && ! empty( $pickup ) ) {
            $method .= ' (' . $pickup . ')';
        }

        $allergies    = ! empty( $resolved['allergies'] ) ? $resolved['allergies'] : 'No Allergies';
        $is_assigned  = ( $log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1 );
        $is_cancelled = ( $log->delivery_result === 'Cancelled' );
        $chef_tag     = $log->is_chefs_choice ? ' (Chef)' : '';
        $pending_tag  = '[Pending Chef]';

        $target_ymd = date( 'Y-m-d', strtotime( $log->target_date ) );
        $expiry_ymd = date( 'Y-m-d', strtotime( $log->expiry_date ) );
        $is_expired = ( $target_ymd > $expiry_ymd );

        $prepared   = $is_cancelled ? 'N/A' : ( $log->is_prepared ? 'Yes' : 'No' );
        $dispatched = $is_cancelled ? 'N/A' : ( $log->dispatch_status ? 'Yes' : 'No' );
        $delivery   = $is_cancelled ? cmp_get_kitchen_cancelled_stamp( $log ) : ( $log->delivery_result ?: 'Pending' );
        $pos_check  = $is_cancelled ? 'N/A' : ( $log->pos_updated ? 'Yes' : 'No' );

        $delivered_by_col = '-';
        if ( ! empty( $log->delivered_by_name ) && $log->delivery_result !== 'Pending' ) {
            $del_time_str = ( ! empty( $log->delivered_at ) && $log->delivered_at !== '0000-00-00 00:00:00' )
                ? ' (' . date( 'd M h:i A', strtotime( $log->delivered_at ) ) . ')'
                : '';
            $delivered_by_col = $log->delivered_by_name . $del_time_str;
        }
        $driver_note_col = ! empty( $log->driver_note ) ? wp_strip_all_tags( $log->driver_note ) : '-';

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

        if ( $export_type === 'juices' ) {
            $j1 = '-';
            $j2 = '-';
            $j3 = '-';
            if ( $is_cancelled ) {
                $j1 = 'CANCELLED';
                $j2 = 'CANCELLED';
                $j3 = 'CANCELLED';
            } elseif ( $is_expired ) {
                $j1 = 'PLAN EXPIRED';
                $j2 = 'PLAN EXPIRED';
                $j3 = 'PLAN EXPIRED';
            } elseif ( $log->is_chefs_choice && ! $is_assigned ) {
                $j1 = $pending_tag;
                $j2 = $pending_tag;
                $j3 = $pending_tag;
            } else {
                if ( $log->custom_juice_1 )      $j1 = $log->custom_juice_1 . $chef_tag;
                elseif ( $log->juice_1_id )      $j1 = ( $food_map[ $log->juice_1_id ] ?? 'Unknown' ) . $chef_tag;

                if ( $log->custom_juice_2 )      $j2 = $log->custom_juice_2 . $chef_tag;
                elseif ( $log->juice_2_id )      $j2 = ( $food_map[ $log->juice_2_id ] ?? 'Unknown' ) . $chef_tag;

                if ( $log->custom_juice_3 )      $j3 = $log->custom_juice_3 . $chef_tag;
                elseif ( $log->juice_3_id )      $j3 = ( $food_map[ $log->juice_3_id ] ?? 'Unknown' ) . $chef_tag;
            }
            $row_data = array( $order_id_display, $full_name, $recipient_name, $email, $phone, $log->target_date, $address, $method, $timing, $delivery_date, $time_slot, $allergies, $foh_note, $plan_string, $j1, $j2, $j3, $prepared, $dispatched, $delivery, $delivered_by_col, $driver_note_col, $pos_check );
        } else {
            $b  = '-';
            $l  = '-';
            $d  = '-';
            $s1 = '-';
            $s2 = '-';
            if ( $is_cancelled ) {
                $b  = 'CANCELLED';
                $l  = 'CANCELLED';
                $d  = 'CANCELLED';
                $s1 = 'CANCELLED';
                $s2 = 'CANCELLED';
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
            $row_data = array( $order_id_display, $full_name, $recipient_name, $email, $phone, $log->target_date, $address, $method, $timing, $delivery_date, $time_slot, $allergies, $foh_note, $plan_string, $b, $l, $d, $s1, $s2, $prepared, $dispatched, $delivery, $delivered_by_col, $driver_note_col, $pos_check );
        }

        $export_rows[] = array(
            'data'          => $row_data,
            'is_day_before' => $is_day_before ? 1 : 0,
            'is_scheduled'  => 1,
        );
    }

    // If exporting Full Customer Roster (Option 5A), append all unscheduled Active & Paused customers
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

                // Match FOH Portal logic: Paused subscriptions are always included; Active subscriptions must not be expired/completed
                if ( ! $is_paused_sub && ( $usage_days >= intval( $sub->total_days ) || $sub->expiry_date < $today_dt ) ) {
                    continue;
                }

                $resolved      = cmp_get_subscription_logistics( $sub );
                $timing        = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'N/A';
                $timing        = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing );
                $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );
                $delivery_date = $is_day_before ? $prep_date : $target_date;
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

                $row_data = array(
                    $order_id_display,
                    $resolved['full_name'],
                    $recipient_name,
                    $resolved['email'],
                    $resolved['phone'],
                    $target_date,
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
                    '-',
                    '-',
                    'N/A',
                );

                $export_rows[] = array(
                    'data'          => $row_data,
                    'is_day_before' => $is_day_before ? 1 : 0,
                    'is_scheduled'  => 0,
                );
            }
        }
    }

    usort( $export_rows, function( $a, $b ) {
        if ( $a['is_scheduled'] !== $b['is_scheduled'] ) {
            return $b['is_scheduled'] - $a['is_scheduled'];
        }
        return $b['is_day_before'] - $a['is_day_before'];
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

    // Security & Custom Glassmorphism UI/UX Login Screen
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

    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $today_ymd     = date( 'Y-m-d' );
    $today_dt      = date( 'Y-m-d H:i:s' );
    $selected_date = isset( $_GET['prep_date'] ) ? sanitize_text_field( $_GET['prep_date'] ) : $today_ymd;
    $target_date   = date( 'Y-m-d', strtotime( $selected_date . ' + 1 day' ) );
    $prev_date     = date( 'Y-m-d', strtotime( $selected_date . ' - 1 day' ) );
    $next_date     = date( 'Y-m-d', strtotime( $selected_date . ' + 1 day' ) );

    // Fetch all locked daily logs for target_date (excluding paused subscriptions from active prep, but INCLUDING Cancelled rows so the Kitchen sees who & when cancelled)
    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.allowed_categories, s.total_days, s.foh_remarks, s.expiry_date, s.is_flexible, s.allow_custom,
                s.delivery_method, s.pickup_location, s.delivery_timing, s.time_slot, s.allergies, s.delivery_address,
                u.display_name, u.user_email 
         FROM {$table_logs} l 
         JOIN {$table_subs} s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON l.user_id = u.ID 
         WHERE l.target_date = %s AND l.is_locked = 1 AND s.status != 'paused'
         ORDER BY l.id ASC",
        $target_date
    ) );

    $foods    = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cmp_foods WHERE is_active = 1 ORDER BY category_name, food_name" );
    $food_map = array();
    foreach ( $foods as $f ) {
        $food_map[ $f->id ] = $f->food_name;
    }

    $customers         = array();
    $scheduled_sub_ids = array();

    foreach ( $logs as $log ) {
        $scheduled_sub_ids[ intval( $log->subscription_id ) ] = true;

        $resolved  = cmp_get_subscription_logistics( $log );
        $full_name = $resolved['full_name'];
        $email     = $resolved['email'];
        $phone     = $resolved['phone'];
        $address   = $resolved['address'];

        $timing        = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'N/A';
        $timing        = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing );
        $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );
        $delivery_date = $is_day_before ? $selected_date : $log->target_date;

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

        // Inline Recipient Pill Badge without "For:"
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

        $cancelled_stamp  = $is_cancelled ? cmp_get_kitchen_cancelled_stamp( $log ) : '';
        $driver_attr_html = cmp_format_kitchen_driver_attribution( $log );

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
            'cancelled_stamp'    => $cancelled_stamp,
            'driver_attr_html'   => $driver_attr_html,
            'is_unscheduled'     => false,
            'is_paused'          => false,
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

    // =========================================================================
    // OPTION 5A: FETCH ALL ACTIVE & PAUSED SUBSCRIBERS FOR FULL CUSTOMER ROSTER
    // =========================================================================
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

            // Match FOH Portal logic: Paused subscriptions are always included; Active subscriptions must not be expired/completed
            if ( ! $is_paused_sub && ( $usage_days >= intval( $sub->total_days ) || $sub->expiry_date < $today_dt ) ) {
                continue;
            }

            $resolved  = cmp_get_subscription_logistics( $sub );
            $full_name = $resolved['full_name'];
            $email     = $resolved['email'];
            $phone     = $resolved['phone'];
            $address   = $resolved['address'];

            $timing        = ! empty( $resolved['timing'] ) ? $resolved['timing'] : 'N/A';
            $timing        = str_ireplace( array( 'Deliver Day Before', 'Deliver Same Day' ), array( 'Day Before', 'Same Day' ), $timing );
            $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );
            $delivery_date = $is_day_before ? $selected_date : $target_date;

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
                'cancelled_stamp'    => '',
                'driver_attr_html'   => '',
                'is_unscheduled'     => true,
                'is_paused'          => $is_paused_sub,
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
                'target_date'        => $target_date,
                'expiry_date'        => $sub->expiry_date,
                'is_day_before'      => $is_day_before ? 1 : 0,
                'raw_log'            => null,
            );
        }
    }

    // Sort: Scheduled rows first, then Unscheduled rows; within each group, "Day Before" deliveries first
    usort( $customers, function( $a, $b ) {
        $sched_a = $a['is_unscheduled'] ? 0 : 1;
        $sched_b = $b['is_unscheduled'] ? 0 : 1;
        if ( $sched_a !== $sched_b ) {
            return $sched_b - $sched_a;
        }
        return $b['is_day_before'] - $a['is_day_before'];
    } );

    $chef_disabled = ( $is_admin || $is_chef ) ? '' : 'disabled';
    $foh_disabled  = ( $is_admin || $is_foh ) ? '' : 'disabled';

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
            max-width: 1400px;
            margin: 0 auto;
            font-family: inherit;
            padding: 0 12px;
            box-sizing: border-box;
        }
        #kp-portal-root *, #kp-portal-root *::before, #kp-portal-root *::after {
            box-sizing: border-box;
        }

        /* ==========================================
           OPTION 1A: DYNAMIC STICKY STACKING
           ========================================== */
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
        }
        .k-table thead th:first-child {
            border-top-left-radius: 10px;
        }
        .k-table thead th:last-child {
            border-top-right-radius: 10px;
        }
        .k-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .chk-large {
            width: 28px;
            height: 28px;
            transform: scale(1.25);
            cursor: pointer;
            margin: 6px auto;
            accent-color: #379237;
            display: inline-block;
            vertical-align: middle;
        }
        .chk-large:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }

        /* Inline Recipient Pill Badge next to Customer Name */
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

        /* Cancelled Meal Alert Banner inside Kitchen Portal */
        .kp-cancelled-alert-box {
            background: #fff1f2;
            border: 1.5px solid #fda4af;
            border-left: 5px solid #e11d48;
            border-radius: 8px;
            padding: 10px 12px;
            color: #9f1239;
            margin-bottom: 8px;
        }
        .kp-cancelled-title {
            font-size: 0.96em;
            font-weight: 800;
            color: #be123c;
            letter-spacing: 0.02em;
            margin-bottom: 4px;
        }
        .kp-cancelled-meta {
            font-size: 0.84em;
            font-weight: 700;
            color: #881337;
            line-height: 1.35;
        }
        .k-row.row-is-cancelled {
            background: #fffafa;
        }

        /* Option 5A: "No Food Selection Today" & "Plan Paused" Badges inside Full Customer Roster */
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

        /* ==========================================
           PHASE 3: DRIVER ATTRIBUTION (OPTION 3A & 3B) & DRIVER NOTE
           ========================================== */
        .kp-driver-attr-wrap {
            margin-top: 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            text-align: left;
        }
        .kp-driver-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 0.76em;
            font-weight: 800;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
        .kp-driver-pill.pill-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        .kp-driver-pill.pill-issue {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .kp-driver-micro {
            font-size: 10.5px;
            font-weight: 700;
            color: #475569;
            line-height: 1.25;
            text-align: center;
        }
        .kp-driver-note-box {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
            border-left: 3px solid #f59e0b;
            border-radius: 5px;
            padding: 4px 6px;
            font-size: 0.76em;
            line-height: 1.3;
            word-break: break-word;
        }

        /* ==========================================
           OPTION 2C: SMART COLOR-CODED POS CUE
           ========================================== */
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
        
        .logistics-box { margin-top: 10px; padding: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; line-height: 1.3; }
        
        .k-tab-btn { background: #ffffff; border: 1px solid #cbd5e1; padding: 10px 18px; border-radius: 8px; font-weight: bold; color: #231F20; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .k-tab-btn:hover { border-color: #379237; color: #1B5E20; }
        .k-tab-btn.active { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; color: #fff !important; border-color: #1B5E20 !important; box-shadow: 0 4px 12px rgba(55, 146, 55, 0.25); }
        .k-tab-pill-count { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.8em; font-weight: 800; line-height: 1.1; }

        .k-glass-toolbar { background: linear-gradient(135deg, rgba(220, 236, 201, 0.78) 0%, rgba(238, 247, 227, 0.72) 100%); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 20px 24px; border-radius: 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border: 1.5px solid #DCECC9; box-shadow: 0 10px 26px rgba(27, 94, 32, 0.08); flex-wrap: wrap; gap: 15px; }

        .k-filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            background: #f8fafc;
            padding: 15px;
            border: 1px solid #DCECC9;
            border-radius: 10px;
        }

        .kp-mob-cell-label {
            display: none;
        }

        /* ==========================================
           TABLET PORTRAIT & LANDSCAPE (768px - 1180px)
           ========================================== */
        @media screen and (max-width: 1180px) {
            #kp-portal-root {
                padding: 0 8px;
            }
            .k-glass-toolbar {
                padding: 14px 16px;
                gap: 12px;
            }
            .k-table {
                font-size: 0.81em;
            }
            .k-table thead th,
            .k-table td {
                padding: 8px 6px;
            }
            .k-table thead th:nth-child(1) { width: 21% !important; }
            .k-table thead th:nth-child(2) { width: 17% !important; }
            .k-table thead th:nth-child(3) { width: 26% !important; }
            .k-table thead th:nth-child(4) { width: 8% !important; }
            .k-table thead th:nth-child(5) { width: 8% !important; }
            .k-table thead th:nth-child(6) { width: 11% !important; }
            .k-table thead th:nth-child(7) { width: 9% !important; }

            .chk-large {
                width: 24px;
                height: 24px;
                transform: scale(1.15);
            }
            .kp-pos-cue {
                font-size: 9.5px;
                padding: 2px 5px;
            }
        }

        /* ==========================================
           SHORT HEIGHT LANDSCAPE (Mobile & Small Tablet Landscape)
           ========================================== */
        @media screen and (orientation: landscape) and (max-height: 540px) {
            #kp-sticky-banner {
                padding: 6px 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 14px;
                flex-wrap: wrap;
                margin-bottom: 10px;
            }
            #kp-sticky-banner h1 {
                font-size: 1.15em;
            }
            #kp-sticky-banner h3 {
                margin: 0;
                font-size: 1em;
            }
            .k-table {
                font-size: 0.78em;
            }
            .k-table thead th {
                padding: 7px 5px;
                font-size: 0.92em;
            }
            .k-table td {
                padding: 6px 5px;
            }
            .chk-large {
                width: 22px;
                height: 22px;
                transform: scale(1.1);
                margin: 3px auto;
            }
            .kp-pos-cue {
                font-size: 8.5px;
                padding: 2px 4px;
            }
        }

        /* ==========================================
           OPTION 3A: MOBILE PORTRAIT CARDS (<= 767px, Portrait)
           ========================================== */
        @media screen and (max-width: 767px) and (orientation: portrait) {
            #kp-sticky-banner {
                padding: 10px 8px 8px 8px;
                margin-bottom: 14px;
            }
            #kp-sticky-banner h1 {
                font-size: 1.35em;
            }
            #kp-sticky-banner h3 {
                font-size: 0.98em;
                margin-top: 3px;
            }

            .k-glass-toolbar {
                padding: 14px;
                flex-direction: column;
                align-items: stretch;
            }
            .k-glass-toolbar form {
                width: 100%;
                justify-content: space-between;
            }
            .k-glass-toolbar form label {
                width: 100%;
                margin-bottom: 4px;
            }
            .k-glass-toolbar > div {
                width: 100%;
                display: grid !important;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px !important;
            }
            .k-glass-toolbar > div a,
            .k-glass-toolbar > div button {
                text-align: center;
                padding: 10px 8px !important;
                font-size: 0.85em;
            }

            .k-filter-bar {
                padding: 12px;
                gap: 10px;
            }
            .k-tabs-container {
                width: 100%;
                display: flex;
                overflow-x: auto;
                flex-wrap: nowrap !important;
                padding-bottom: 2px;
            }
            .k-tab-btn {
                flex: 1 0 auto;
                padding: 8px 12px;
                font-size: 0.85em;
                white-space: nowrap;
            }

            .k-table {
                display: block;
                background: transparent;
                box-shadow: none;
                border-radius: 0;
                font-size: 0.92em;
            }
            .k-table thead {
                display: none !important;
            }
            .k-table tbody {
                display: block;
                width: 100%;
            }

            .k-table tbody tr.k-row {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                background: #ffffff;
                border: 1.5px solid #cbd5e1;
                border-radius: 14px;
                margin-bottom: 16px;
                box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
                overflow: hidden;
            }
            .k-table tbody tr.k-row.k-row-hidden {
                display: none !important;
            }

            .k-table tbody tr.k-row > td:nth-child(1),
            .k-table tbody tr.k-row > td:nth-child(2),
            .k-table tbody tr.k-row > td:nth-child(3) {
                grid-column: 1 / -1;
                display: block;
                width: 100%;
                padding: 12px 14px;
                border-bottom: 1px solid #e2e8f0;
            }
            .k-table tbody tr.k-row > td:nth-child(1) {
                background: linear-gradient(135deg, rgba(238, 247, 227, 0.55) 0%, rgba(255, 255, 255, 1) 100%);
                border-left: 4px solid #379237;
            }

            .k-table tbody tr.k-row > td:nth-child(4),
            .k-table tbody tr.k-row > td:nth-child(5),
            .k-table tbody tr.k-row > td:nth-child(6),
            .k-table tbody tr.k-row > td:nth-child(7) {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 12px 10px;
                min-height: 86px;
                border-bottom: 1px solid #e2e8f0;
            }
            .k-table tbody tr.k-row > td:nth-child(4),
            .k-table tbody tr.k-row > td:nth-child(6) {
                border-right: 1px solid #e2e8f0;
                border-left: none !important;
            }
            .k-table tbody tr.k-row > td:nth-child(6),
            .k-table tbody tr.k-row > td:nth-child(7) {
                border-bottom: none;
            }

            .kp-mob-cell-label {
                display: block;
                font-size: 0.72em;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #475569;
                margin-bottom: 6px;
                text-align: center;
            }

            .chk-large {
                width: 28px;
                height: 28px;
                transform: scale(1.15);
                margin: 2px auto;
            }
            .cmp-delivery-box {
                min-height: 38px;
                font-weight: 600;
            }
        }

        @media print {
            .cmp-no-print, .kp-pos-cue, .kp-mob-cell-label { display: none !important; }
            #kp-sticky-banner, .k-table thead th { position: static !important; box-shadow: none !important; }
            body, html { background: #fff !important; margin: 0 !important; padding: 0 !important; }
            .k-table { box-shadow: none !important; border: 1px solid #000; border-collapse: collapse !important; }
            .k-table th, .k-table td { border: 1px solid #000; padding: 6px; color: #000 !important; background: transparent !important; }
            select { appearance: none; border: none; background: transparent; }
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

        <!-- PHASE 4 FILTER TABS:
             1. All Orders
             2. Pending Preparation (New)
             3. Pending Chef's Choice
             4. Full Customer Roster (Option 5A - Includes Active & Paused with "No Food Selection Today")
             ("Completed Assignments" Tab Removed) -->
        <div class="cmp-no-print k-filter-bar">
            <div class="k-tabs-container" style="display:flex; gap:10px; flex-wrap:wrap;">
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
            <div style="flex-grow:1; min-width:220px; max-width:360px;">
                <input type="text" id="k-search" placeholder="🔍 Search by ID, name, recipient, email, phone, or allergy..." style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:1em;">
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
                            <th style="width: 30%;">The Food</th>
                            <th style="width: 8%; text-align:center;">Prepared</th>
                            <th style="width: 8%; text-align:center;">Dispatch</th>
                            <th style="width: 10%;">Delivery</th>
                            <th style="width: 7%; text-align:center;">POS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $customers as $c ) :
                            $is_unscheduled = ! empty( $c['is_unscheduled'] );
                            $is_paused_cust = ! empty( $c['is_paused'] );
                            $is_cancelled   = ! empty( $c['is_cancelled'] );
                            $is_expired     = ! empty( $c['is_expired'] );

                            $allergy_style = ( $c['allergies'] !== 'No Allergies' )
                                ? 'background:#fff3cd; color:#856404; font-weight:bold; padding:2px 6px; border-radius:3px; display:inline-block; margin-top:3px;'
                                : 'color:#666; font-style:italic; display:inline-block; margin-top:3px;';

                            $prepared_disabled = ( $is_unscheduled || $is_cancelled || ! empty( $chef_disabled ) || $c['dispatch'] || $is_expired ) ? 'disabled' : '';
                            $dispatch_disabled = ( $is_unscheduled || $is_cancelled || ! empty( $chef_disabled ) || $c['pos'] || $is_expired || ! $c['prepared'] ) ? 'disabled' : '';
                            $delivery_disabled = ( $is_unscheduled || ( ! $c['dispatch'] && ! $is_cancelled ) || ! empty( $chef_disabled ) || $c['pos'] || $is_expired ) ? 'disabled' : '';
                            $pos_disabled      = ( $is_unscheduled || $is_cancelled || ! $c['dispatch'] || $c['delivery'] === 'Pending' || ! empty( $foh_disabled ) || $is_expired ) ? 'disabled' : '';

                            $row_classes = array( 'k-row' );
                            if ( $is_unscheduled ) {
                                $row_classes[] = 'filter-unscheduled';
                                if ( $is_paused_cust ) {
                                    $row_classes[] = 'row-is-paused';
                                }
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
                                $c['order_id'] . ' ' .
                                $c['name'] . ' ' .
                                $c['raw_recipient'] . ' ' .
                                $c['email'] . ' ' .
                                $c['phone'] . ' ' .
                                $c['allergies'] . ' ' .
                                $c['timing'] . ' ' .
                                ( $is_unscheduled ? 'no food selection today unscheduled' : '' ) . ' ' .
                                ( $is_paused_cust ? 'paused plan on hold' : '' ) . ' ' .
                                ( $is_cancelled ? 'cancelled ' . $c['cancelled_stamp'] : '' )
                            ) );

                            $plan_duration = $c['core_plan_name'];
                            $plan_parts    = explode( ' - ', $c['plan'] );
                            $plan_meals    = isset( $plan_parts[1] ) ? $plan_parts[1] : '';
                            if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', end( $plan_parts ) ) ) {
                                $plan_meals = count( $plan_parts ) > 2 ? $plan_parts[1] : '';
                            }

                            // Option 2C: Smart Color-Coded "Post on [Date]" Cue (Customer Eating Date)
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
                            data-name="<?php echo $search_name; ?>"
                            data-in-orders="<?php echo esc_attr( $in_orders ); ?>"
                            data-in-roster="<?php echo esc_attr( $in_roster ); ?>"
                            data-pending-prep="<?php echo esc_attr( $needs_prep ); ?>"
                            data-is-cancelled="<?php echo $is_cancelled ? '1' : '0'; ?>"
                            data-is-expired="<?php echo $is_expired ? '1' : '0'; ?>">
                            <td>
                                <div class="kp-name-inline-wrap">
                                    <a href="<?php echo esc_url( site_url( '/my-meal-portal/?admin_edit_sub=' . $c['sub_id'] ) ); ?>" target="_blank" style="text-decoration:none;" title="Open Customer Portal">
                                        <strong style="color: #379237; font-size: 1.08em; border-bottom: 1px dotted #379237;"><?php echo esc_html( $c['name'] ); ?></strong>
                                    </a>
                                    <?php echo $c['recipient_badge']; ?>
                                </div>
                                
                                <div style="margin-top:6px; font-size:0.95em; line-height:1.4;">
                                    <span class="k-customer-meta" style="color: #231F20; font-weight: bold;">
                                        Order: <?php echo esc_html( $c['order_id'] ); ?>
                                    </span>
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
                                </div>
                                <div style="<?php echo $allergy_style; ?>"><?php echo esc_html( $c['allergies'] ); ?></div>
                            </td>
                            
                            <td>
                                <div class="logistics-box" style="margin-top:0; margin-bottom:8px;">
                                    <span style="color: #475569;"><strong>Method:</strong> <?php echo esc_html( $c['method'] ); ?></span><br>
                                    <span style="color: #475569;"><strong>Receive By:</strong> <?php echo esc_html( $c['timing'] ); ?></span><br>
                                    <span style="color: #475569;"><strong>Time:</strong> <?php echo esc_html( $c['time_slot'] ); ?></span>
                                </div>
                                <div style="text-align: left;">
                                    <div style="background: <?php echo $is_paused_cust ? '#d97706' : ( $is_unscheduled ? '#64748b' : ( $is_cancelled ? '#e11d48' : '#379237' ) ); ?>; color: white; font-size: 0.9em; font-weight:bold; padding: 4px 8px; border-radius: 4px; display:inline-block; margin-bottom: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                                        Delivery: <?php echo date( 'D, M j', strtotime( $c['delivery_date'] ) ); ?>
                                    </div>
                                    <br>
                                    <span style="color: #64748b; font-size: 0.85em; font-weight: 600;">
                                        Eating: <?php echo date( 'D, M j', strtotime( $c['target_date'] ) ); ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <?php if ( $is_unscheduled ) : ?>
                                    <!-- OPTION 5A: Active or Paused Customer with No Food Selection Today -->
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
                                    <!-- PHASE 4: Cancelled Meal Banner showing Who & When Cancelled -->
                                    <div class="kp-cancelled-alert-box" id="kp-cancel-banner-<?php echo esc_attr( $c['log_id'] ); ?>">
                                        <div class="kp-cancelled-title">&#10005; MEAL CANCELLED &mdash; DO NOT PREP</div>
                                        <div class="kp-cancelled-meta" id="kp-cancel-meta-<?php echo esc_attr( $c['log_id'] ); ?>">
                                            <?php echo esc_html( $c['cancelled_stamp'] ); ?>
                                        </div>
                                        <?php if ( ! empty( $c['meals'] ) ) : ?>
                                            <div style="margin-top:6px; padding-top:5px; border-top:1px dashed #fda4af; font-size:0.82em; color:#9f1239; opacity:0.8; text-decoration:line-through;">
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
                                    $raw = $c['raw_log'];

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
                                        
                                        <button type="button" class="save-chef-assign" style="width:100%; background:#dba617; color:#fff; border:none; padding:6px; border-radius:4px; font-weight:bold; cursor:pointer; margin-top:4px; transition: 0.2s;">Save</button>
                                    </div>
                                    
                                <?php else : ?>
                                    <ul style="margin:0; padding-left:15px; line-height: 1.4;">
                                        <?php foreach ( $c['meals'] as $meal ) echo "<li>$meal</li>"; ?>
                                    </ul>
                                <?php endif; ?>

                                <!-- Placeholder for dynamically cancelled banner if changed live via dropdown -->
                                <?php if ( ! $is_cancelled && ! $is_unscheduled ) : ?>
                                    <div class="kp-cancelled-alert-box" id="kp-cancel-banner-<?php echo esc_attr( $c['log_id'] ); ?>" style="display:none; margin-top:8px;">
                                        <div class="kp-cancelled-title">&#10005; MEAL CANCELLED &mdash; DO NOT PREP</div>
                                        <div class="kp-cancelled-meta" id="kp-cancel-meta-<?php echo esc_attr( $c['log_id'] ); ?>"></div>
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
                            
                            <td style="text-align:center; background:#f0fdf4; border-left:1px solid #ddd;">
                                <span class="kp-mob-cell-label">Prepared</span>
                                <?php if ( $is_unscheduled ) : ?>
                                    <span style="color:#94a3b8; font-weight:700;">&mdash;</span>
                                <?php else : ?>
                                    <input type="checkbox" class="chk-large cmp-prepared-box cmp-no-print" data-id="<?php echo esc_attr( $c['log_id'] ); ?>" <?php echo $c['prepared'] ? 'checked' : ''; ?> <?php echo $prepared_disabled; ?>>
                                    <?php if ( $c['prepared'] ) echo '<span style="display:none; color:#166534; font-weight:bold;" class="print-only">Yes</span>'; ?>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:center; background:#fcfcfc;">
                                <span class="kp-mob-cell-label">Dispatch</span>
                                <?php if ( $is_unscheduled ) : ?>
                                    <span style="color:#94a3b8; font-weight:700;">&mdash;</span>
                                <?php else : ?>
                                    <input type="checkbox" class="chk-large cmp-dispatch-box cmp-no-print" data-id="<?php echo esc_attr( $c['log_id'] ); ?>" <?php echo $c['dispatch'] ? 'checked' : ''; ?> <?php echo $dispatch_disabled; ?>>
                                    <?php if ( $c['dispatch'] ) echo '<span style="display:none; color:green; font-weight:bold;" class="print-only">Yes</span>'; ?>
                                <?php endif; ?>
                            </td>

                            <td style="background:#fcfcfc;">
                                <span class="kp-mob-cell-label">Delivery</span>
                                <?php if ( $is_unscheduled ) : ?>
                                    <span style="color:<?php echo $is_paused_cust ? '#b45309' : '#94a3b8'; ?>; font-size:0.85em; font-weight:700; display:block; text-align:center;">
                                        <?php echo $is_paused_cust ? 'Plan Paused' : 'No Selection'; ?>
                                    </span>
                                <?php else : ?>
                                    <select class="cmp-delivery-box" data-id="<?php echo esc_attr( $c['log_id'] ); ?>" style="width:100%; padding:6px; font-size:0.9em; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" <?php echo $delivery_disabled; ?>>
                                        <option value="Pending" <?php selected( $c['delivery'], 'Pending' ); ?>>Pending</option>
                                        <option value="Successful" <?php selected( $c['delivery'], 'Successful' ); ?>>Successful</option>
                                        <option value="Cancelled" <?php selected( $c['delivery'], 'Cancelled' ); ?>>Cancelled</option>
                                        <option value="Returned" <?php selected( $c['delivery'], 'Returned' ); ?>>Returned</option>
                                    </select>
                                    <!-- PHASE 3: Driver Attribution Badge & Driver Note -->
                                    <div id="kp-driver-attr-<?php echo esc_attr( $c['log_id'] ); ?>" class="kp-driver-attr-wrap">
                                        <?php echo $c['driver_attr_html']; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:center; background:#f4f8fa;">
                                <span class="kp-mob-cell-label">POS Check</span>
                                <?php if ( $is_unscheduled ) : ?>
                                    <span style="color:#94a3b8; font-weight:700;">&mdash;</span>
                                <?php else : ?>
                                    <div class="kp-pos-cell-inner">
                                        <span class="kp-pos-cue <?php echo esc_attr( $active_cue_class ); ?> cmp-no-print"
                                              data-id="<?php echo esc_attr( $c['log_id'] ); ?>"
                                              data-base-class="<?php echo esc_attr( $base_cue_class ); ?>"
                                              data-eat-short="<?php echo esc_attr( $eat_short ); ?>"
                                              title="Post in POS on Customer Eating Date: <?php echo esc_attr( $eat_full ); ?>">
                                            <?php echo esc_html( $pos_cue_text ); ?>
                                        </span>
                                        <input type="checkbox" class="chk-large cmp-pos-box cmp-no-print" data-id="<?php echo esc_attr( $c['log_id'] ); ?>" <?php echo $c['pos'] ? 'checked' : ''; ?> <?php echo $pos_disabled; ?>>
                                    </div>
                                    <?php if ( $c['pos'] ) echo '<span style="display:none; color:green; font-weight:bold;" class="print-only">Yes</span>'; ?>
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

        // Non-Blocking Toast Notice Helper
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

        // ==========================================
        // OPTION 1A: DYNAMIC STICKY STACKING ENGINE
        // ==========================================
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
        if (typeof ResizeObserver !== 'undefined') {
            var bannerEl = document.getElementById('kp-sticky-banner');
            if (bannerEl) {
                var ro = new ResizeObserver(function() {
                    syncKitchenStickyOffsets();
                });
                ro.observe(bannerEl);
            }
        }

        function populateDynamicDropdowns(container) {
            var isCustom = container.data('allow-custom') == 1;
            var dateVal  = container.closest('tr').find('.cmp-date-picker').length ? container.closest('tr').find('.cmp-date-picker').val() : "<?php echo esc_js( $target_date ); ?>";
            var selects  = container.find('select.chef-meal-select, select[class^="cmp-juice-"]');
            var inputs   = container.find('input.cmp-meal-custom');
            var btn      = container.find('.save-chef-assign');
            
            container.find('.boundary-overlay').remove();

            if (isCustom) {
                inputs.show();
                selects.hide();
                if (btn.text() === 'Menu Pending') {
                    btn.text('Save').css({'background':'#dba617', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
                }
                return true;
            }

            var availableFoods = cmpGlobalFoodData.filter(function(food) {
                if (!food.valid_from || !food.valid_until) return true; 
                return (dateVal >= food.valid_from && dateVal <= food.valid_until);
            });

            if (availableFoods.length === 0) {
                selects.hide();
                inputs.hide();
                container.prepend('<div class="boundary-overlay" style="color:#b45309; font-weight:bold; font-size:0.9em; background:#fef3c7; padding:10px; border-radius:4px; text-align:center; line-height:1.4;">Menu Pending Release</div>');
                btn.text('Menu Pending').css({'background':'#94a3b8', 'color':'#fff', 'cursor':'not-allowed'}).prop('disabled', true);
                return false;
            }

            selects.show();
            inputs.hide();
            if (btn.text() === 'Menu Pending') {
                btn.text('Save').css({'background':'#dba617', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
            }

            selects.each(function() {
                var select      = $(this);
                var category    = select.data('cat');
                var savedVal    = select.data('saved-val');
                var placeholder = select.find('option').first().text();
                
                select.empty().append('<option value="">' + placeholder + '</option>');
                
                availableFoods.forEach(function(food) {
                    if (category === 'Any' && (food.category_name === 'Breakfast' || food.category_name === 'Lunch' || food.category_name === 'Dinner')) {
                        var isSelected = (savedVal == food.id) ? 'selected' : '';
                        var label      = '[' + food.category_name + '] ' + food.food_name;
                        select.append('<option value="'+food.id+'" '+isSelected+'>'+label+'</option>');
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

        // Recalculates the live Pending Preparation counter badge
        function updatePendingPrepBadgeCount() {
            var count = 0;
            $('.k-table tbody tr.k-row').each(function() {
                if ($(this).attr('data-pending-prep') === '1') {
                    count++;
                }
            });
            var $badge = $('#k-pending-prep-count');
            if ($badge.length) {
                $badge.text(count);
                $badge.css('background', count > 0 ? '#f59e0b' : '#16a34a');
            }
        }

        // --- Tab Memory & Search Logic (All Orders, Pending Preparation, Pending Chef's Choice, Full Customer Roster) ---
        var urlParams  = new URLSearchParams(window.location.search);
        var initialTab = urlParams.get('k_tab') || 'all';
        if ($('.k-tab-btn[data-filter="' + initialTab + '"]').length === 0) {
            initialTab = 'all';
        }
        
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

        $('.chef-assign-form').each(function() {
            enforceChefMealQuota($(this));
        });

        $(document).on('change keyup', '.cmp-main-meal', function() {
            enforceChefMealQuota($(this).closest('.chef-assign-form'));
        });

        $('.edit-chef-assign').on('click', function() {
            var cell = $(this).closest('td');
            cell.find('.chef-assigned-view').hide();
            cell.find('.chef-assign-form').show();
        });

        $('.save-chef-assign').on('click', function() {
            var btn          = $(this);
            var container    = btn.closest('.chef-assign-form');
            var logId        = container.data('log-id');
            var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
            var snackQuota   = parseInt(container.data('snack-quota')) || 0;
            var isJuice      = container.data('is-juice') == '1';
            var isCustom     = container.data('allow-custom') == '1';
            
            var isMenuPending = container.find('.boundary-overlay').length > 0;
            if (isMenuPending && !isCustom) {
                kpShowNotice('The specific menu is not available yet.', 'error');
                return;
            }

            if (isCustom) {
                if (isJuice) {
                    var juiceInputs  = container.find('.cmp-juice-1, .cmp-juice-2, .cmp-juice-3');
                    var missingJuice = false;
                    juiceInputs.each(function() { if ($(this).val().trim() === '') missingJuice = true; });
                    if (missingJuice) {
                        kpShowNotice('Please complete all 3 juices to complete the assignment.', 'error');
                        return;
                    }
                } else {
                    var selectedMainCount = 0;
                    container.find('.cmp-main-meal').each(function() {
                        if ($(this).val().trim() !== "") selectedMainCount++;
                    });
                    if (selectedMainCount !== allowedMeals) {
                        kpShowNotice('Please provide exactly ' + allowedMeals + ' main meal(s) to complete the assignment.', 'error'); 
                        return; 
                    }
                    
                    var selectedSnackCount = 0;
                    container.find('.cmp-snack-1, .cmp-snack-2').each(function() {
                        if ($(this).val().trim() !== "") selectedSnackCount++;
                    });
                    if (selectedSnackCount !== snackQuota) {
                        kpShowNotice('Please provide exactly ' + snackQuota + ' snack(s) to complete the assignment.', 'error'); 
                        return; 
                    }
                }
            } else {
                if (!isJuice && allowedMeals > 0) {
                    var selectedMainCount = 0;
                    container.find('.cmp-main-meal').each(function() {
                        if ($(this).val() !== "") selectedMainCount++;
                    });
                    if (selectedMainCount < allowedMeals) {
                        kpShowNotice('Please select exactly ' + allowedMeals + ' main meal(s) to complete the assignment.', 'error'); 
                        return; 
                    }

                    var selectedSnackCount = 0;
                    container.find('select[data-cat="Snacks"]').each(function() {
                        if ($(this).val() !== "") selectedSnackCount++;
                    });
                    if (selectedSnackCount !== snackQuota) {
                        kpShowNotice('Please select exactly ' + snackQuota + ' snack(s) to complete the assignment.', 'error'); 
                        return; 
                    }
                } else if (isJuice) {
                    var missingJuice = false;
                    container.find('select').each(function() {
                        if ($(this).data('cat') === 'Juices' && $(this).val() === '') missingJuice = true;
                    });
                    if (missingJuice) { 
                        kpShowNotice('Please select all juices to complete the assignment.', 'error'); 
                        return; 
                    }
                }
            }
            
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
                if (res.success) {
                    location.reload(); 
                } else {
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
                    var $banner   = $('#kp-cancel-banner-' + logId);
                    var $meta     = $('#kp-cancel-meta-' + logId);
                    var $attrWrap = $('#kp-driver-attr-' + logId);
                    var $row      = $('tr.k-row[data-log-id="' + logId + '"]');
                    if ($attrWrap.length && res.data && typeof res.data.driver_attr_html !== 'undefined') {
                        $attrWrap.html(res.data.driver_attr_html);
                    }
                    if (val === 'Cancelled') {
                        if (res.data && res.data.cancelled_stamp && $meta.length) {
                            $meta.text(res.data.cancelled_stamp);
                        }
                        $banner.slideDown(150);
                        $row.addClass('row-is-cancelled').attr('data-is-cancelled', '1').attr('data-pending-prep', '0');
                    } else {
                        $banner.slideUp(150);
                        $row.removeClass('row-is-cancelled').attr('data-is-cancelled', '0');
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
            
            var isPrepared     = preparedBox.is(':checked');
            var isDispatched   = dispatchBox.is(':checked');
            var deliveryResult = deliveryBox.val();
            var isPosChecked   = posBox.is(':checked');
            var isExpired      = $row.attr('data-is-expired') === '1';
            var isCancelled    = (deliveryResult === 'Cancelled');

            // Update Pending Preparation state on the row & badge counter
            if (!isCancelled && !isExpired && !isPrepared) {
                $row.attr('data-pending-prep', '1');
            } else {
                $row.attr('data-pending-prep', '0');
            }
            updatePendingPrepBadgeCount();
            
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
                if (isDispatched && deliveryResult !== 'Pending' && !isCancelled) {
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
