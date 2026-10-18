<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// 1. PHASE 1: SCHEMA AUTO-PATCHER, WOO ORDER SYNC & CONSUMPTION CACHE
// ==========================================
function cmp_sa_ensure_schema_and_sync( $force = false ) {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    // 1.1 Ensure `used_days` column exists on wp_cmp_subscriptions
    $col_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_subs} LIKE 'used_days'" );
    if ( empty( $col_check ) ) {
        $wpdb->query( "ALTER TABLE {$table_subs} ADD used_days INT(11) NOT NULL DEFAULT 0 AFTER total_days" );
        $force = true;
    }

    // 1.2 Fast SQL-level sync of `used_days` & Deleted/Cancelled WooCommerce Orders
    $last_sync = intval( get_transient( 'cmp_sa_used_days_sync_lock' ) );
    if ( $force || ( time() - $last_sync ) > 60 ) {
        // Sync used_days count from Successful daily logs
        $wpdb->query(
            "UPDATE {$table_subs} s
             LEFT JOIN (
                 SELECT subscription_id, COUNT(id) AS cnt
                 FROM {$table_logs}
                 WHERE delivery_result = 'Successful'
                 GROUP BY subscription_id
             ) l ON s.id = l.subscription_id
             SET s.used_days = COALESCE(l.cnt, 0)"
        );

        // Mark subscriptions as 'deleted' if their WordPress user was deleted
        $wpdb->query(
            "UPDATE {$table_subs} s
             LEFT JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
             SET s.status = 'deleted'
             WHERE u.ID IS NULL AND s.status != 'deleted'"
        );

        // Verify WooCommerce-linked subscriptions: exclude trashed/deleted/cancelled/refunded WC orders & backfill prices
        $wc_subs = $wpdb->get_results(
            "SELECT id, wc_order_id, plan_price, status FROM {$table_subs} WHERE wc_order_id > 0 AND status NOT IN ('deleted', 'cancelled')"
        );
        if ( ! empty( $wc_subs ) && function_exists( 'wc_get_order' ) ) {
            foreach ( $wc_subs as $ws ) {
                $order = wc_get_order( $ws->wc_order_id );
                if ( ! $order ) {
                    // WooCommerce order was permanently deleted
                    $wpdb->update( $table_subs, array( 'status' => 'deleted' ), array( 'id' => intval( $ws->id ) ) );
                    continue;
                }

                $order_status = $order->get_status();
                if ( in_array( $order_status, array( 'trash', 'cancelled', 'refunded', 'failed' ), true ) ) {
                    // WooCommerce order was trashed, cancelled, refunded, or failed
                    $wpdb->update( $table_subs, array( 'status' => 'deleted' ), array( 'id' => intval( $ws->id ) ) );
                    continue;
                }

                // Backfill legacy 0.00 price from WooCommerce order if needed
                if ( floatval( $ws->plan_price ) <= 0 ) {
                    $sub_total = floatval( $order->get_subtotal() );
                    $disc_tot  = floatval( $order->get_discount_total() );
                    if ( $sub_total <= 0 ) {
                        $sub_total = floatval( $order->get_total() );
                    }
                    if ( $sub_total > 0 ) {
                        $wpdb->update(
                            $table_subs,
                            array(
                                'plan_price'      => $sub_total,
                                'discount_amount' => $disc_tot,
                            ),
                            array( 'id' => intval( $ws->id ) )
                        );
                    }
                }
            }
        }

        set_transient( 'cmp_sa_used_days_sync_lock', time(), 60 );
    }
}

// Bust cache immediately whenever logs or subscriptions are modified or deleted
add_action( 'wp_ajax_cmp_kitchen_action', 'cmp_sa_bust_consumption_cache_on_ajax', 1 );
add_action( 'wp_ajax_cmp_foh_bulk_cancel_days', 'cmp_sa_bust_consumption_cache_on_ajax', 1 );
add_action( 'wp_ajax_cmp_cancel_daily_log', 'cmp_sa_bust_consumption_cache_on_ajax', 1 );
add_action( 'wp_ajax_cmp_foh_delete_subscription', 'cmp_sa_bust_consumption_cache_on_ajax', 1 );
add_action( 'before_delete_post', 'cmp_sa_bust_consumption_cache_on_ajax', 1 );
add_action( 'wp_trash_post', 'cmp_sa_bust_consumption_cache_on_ajax', 1 );
add_action( 'woocommerce_order_status_changed', 'cmp_sa_bust_consumption_cache_on_ajax', 1 );
function cmp_sa_bust_consumption_cache_on_ajax() {
    delete_transient( 'cmp_sa_used_days_sync_lock' );
}

// AJAX Handler: Delete Subscription Directly from Executive Drill-Down Screen
add_action( 'wp_ajax_cmp_sa_delete_subscription', 'cmp_sa_ajax_delete_subscription' );
function cmp_sa_ajax_delete_subscription() {
    check_ajax_referer( 'cmp_sa_exec_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission Denied. Only Super Administrators can delete plans.' );
    }

    global $wpdb;
    $sub_id = isset( $_POST['sub_id'] ) ? intval( $_POST['sub_id'] ) : 0;
    if ( $sub_id <= 0 ) {
        wp_send_json_error( 'Invalid Subscription ID.' );
    }

    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    $wpdb->delete( $table_logs, array( 'subscription_id' => $sub_id ), array( '%d' ) );
    $deleted = $wpdb->delete( $table_subs, array( 'id' => $sub_id ), array( '%d' ) );

    delete_transient( 'cmp_sa_used_days_sync_lock' );

    if ( $deleted ) {
        wp_send_json_success();
    } else {
        wp_send_json_error( 'Failed to delete subscription from database.' );
    }
}

// ==========================================
// 2. UNIVERSAL SEMANTIC ICON & PILL HELPER (PHASE 3.2)
// ==========================================
function cmp_sa_semantic_pill( $type, $text, $extra_style = '' ) {
    $styles = array(
        'success' => array( 'bg' => '#dcfce7', 'col' => '#166534', 'bdr' => '#bbf7d0', 'icon' => 'check' ),
        'warning' => array( 'bg' => '#fef3c7', 'col' => '#92400e', 'bdr' => '#fde68a', 'icon' => 'clock' ),
        'danger'  => array( 'bg' => '#fee2e2', 'col' => '#991b1b', 'bdr' => '#fecaca', 'icon' => 'alert' ),
        'purple'  => array( 'bg' => '#ede9fe', 'col' => '#6d28d9', 'bdr' => '#ddd6fe', 'icon' => 'clock' ),
        'neutral' => array( 'bg' => '#f1f5f9', 'col' => '#334155', 'bdr' => '#e2e8f0', 'icon' => 'clock' ),
    );
    $cfg = isset( $styles[ $type ] ) ? $styles[ $type ] : $styles['neutral'];

    if ( $cfg['icon'] === 'check' ) {
        $svg = '<svg class="acru-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
    } elseif ( $cfg['icon'] === 'alert' ) {
        $svg = '<svg class="acru-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
    } else {
        $svg = '<svg class="acru-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
    }

    return sprintf(
        '<span class="sa-pill" style="background:%s; color:%s; border:1px solid %s; %s">%s <span>%s</span></span>',
        esc_attr( $cfg['bg'] ),
        esc_attr( $cfg['col'] ),
        esc_attr( $cfg['bdr'] ),
        esc_attr( $extra_style ),
        $svg,
        esc_html( $text )
    );
}

function cmp_sa_format_order_id( $wc_order_id, $sub_id = 0 ) {
    if ( $wc_order_id < 0 ) {
        return 'M-' . str_pad( abs( $wc_order_id ), 4, '0', STR_PAD_LEFT );
    } elseif ( $wc_order_id > 0 ) {
        return '#' . $wc_order_id;
    }
    return 'M-' . str_pad( intval( $sub_id ), 4, '0', STR_PAD_LEFT );
}

function cmp_sa_get_customer_contact( $user_id, $wc_order_id, $fallback_display = '', $fallback_email = '' ) {
    static $cache = array();
    $cache_key = intval( $user_id ) . '_' . intval( $wc_order_id );
    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $order = ( $wc_order_id > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( $wc_order_id ) : false;
    $fname = get_user_meta( $user_id, 'first_name', true ) ?: get_user_meta( $user_id, 'billing_first_name', true );
    $lname = get_user_meta( $user_id, 'last_name', true ) ?: get_user_meta( $user_id, 'billing_last_name', true );
    $name  = $order ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : trim( $fname . ' ' . $lname );
    if ( empty( $name ) ) {
        $name = $fallback_display ?: 'Customer #' . $user_id;
    }

    $email  = $order ? ( $order->get_billing_email() ?: $fallback_email ) : $fallback_email;
    $phone  = $order ? $order->get_billing_phone() : get_user_meta( $user_id, 'billing_phone', true );
    $timing = $order ? ( $order->get_meta( '_cmp_delivery_timing' ) ?: $order->get_meta( 'delivery_timing' ) ) : '';
    if ( empty( $timing ) ) {
        $timing = get_user_meta( $user_id, 'delivery_timing', true ) ?: 'Same Day';
    }
    $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );

    $cache[ $cache_key ] = array(
        'name'          => $name,
        'email'         => $email,
        'phone'         => $phone ?: 'N/A',
        'timing_label'  => $is_day_before ? 'Day Before' : 'Same Day',
        'is_day_before' => $is_day_before,
    );
    return $cache[ $cache_key ];
}

// ==========================================
// 3. PHASE 1 & 2: SQL-FIRST EXECUTIVE SUMMARY ENGINE
// ==========================================
function cmp_sa_get_dashboard_summary( $op_start, $op_end, $fin_start, $fin_end ) {
    global $wpdb;
    cmp_sa_ensure_schema_and_sync();

    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods = $wpdb->prefix . 'cmp_foods';
    $table_users = $wpdb->prefix . 'users';

    $today_time    = date( 'Y-m-d H:i:s' );
    $today_date    = date( 'Y-m-d' );
    $tomorrow_date = date( 'Y-m-d', strtotime( '+1 day' ) );

    // Calculate Previous Period (PP) Dates for Finance Delta (Step 2.1)
    $days_diff = max( 0, round( ( strtotime( $fin_end ) - strtotime( $fin_start ) ) / 86400 ) );
    $pp_end    = date( 'Y-m-d', strtotime( $fin_start . ' -1 day' ) );
    $pp_start  = date( 'Y-m-d', strtotime( $pp_end . " -{$days_diff} days" ) );

    // 1. Single SQL Aggregation for Subscriptions & Financials (Excludes Deleted Plans & Deleted Users)
    $sub_agg = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(CASE WHEN s.status = 'active' AND s.used_days < s.total_days AND s.expiry_date >= %s THEN 1 END) AS live_active_count,
            COUNT(CASE WHEN s.status = 'paused' THEN 1 END) AS live_paused_count,
            COUNT(CASE WHEN s.status = 'active' AND s.used_days < s.total_days AND s.expiry_date >= %s AND (s.total_days - s.used_days) BETWEEN 1 AND 3 THEN 1 END) AS expiring_soon_count,
            COUNT(CASE WHEN s.status = 'active' AND s.used_days < s.total_days AND s.expiry_date >= %s AND s.allow_custom = 1 THEN 1 END) AS active_custom_count,
            COUNT(CASE WHEN s.status = 'active' AND s.used_days < s.total_days AND s.expiry_date >= %s AND s.allow_custom = 0 AND s.is_flexible = 1 THEN 1 END) AS active_flex_count,
            COUNT(CASE WHEN s.status = 'active' AND s.used_days < s.total_days AND s.expiry_date >= %s AND s.allow_custom = 0 AND s.is_flexible = 0 THEN 1 END) AS active_std_count,
            COUNT(CASE WHEN DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s THEN 1 END) AS period_new_plans,
            COALESCE(SUM(CASE WHEN DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s THEN s.plan_price ELSE 0 END), 0) AS gross_turnover,
            COALESCE(SUM(CASE WHEN DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s THEN s.discount_amount ELSE 0 END), 0) AS total_discounts,
            COALESCE(SUM(CASE WHEN DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s THEN GREATEST(0, s.plan_price - s.discount_amount) ELSE 0 END), 0) AS net_turnover,
            COALESCE(SUM(CASE WHEN DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s THEN GREATEST(0, s.plan_price - s.discount_amount) ELSE 0 END), 0) AS pp_net_turnover
         FROM {$table_subs} s
         INNER JOIN {$table_users} u ON s.user_id = u.ID
         WHERE s.status IN ('active', 'paused', 'completed')",
        $today_time, $today_time, $today_time, $today_time, $today_time,
        $fin_start, $fin_end,
        $fin_start, $fin_end,
        $fin_start, $fin_end,
        $fin_start, $fin_end,
        $pp_start, $pp_end
    ) );

    $net_turnover    = floatval( $sub_agg->net_turnover ?? 0 );
    $pp_net_turnover = floatval( $sub_agg->pp_net_turnover ?? 0 );
    $new_plans_cnt   = intval( $sub_agg->period_new_plans ?? 0 );
    $avg_plan_value  = $new_plans_cnt > 0 ? ( $net_turnover / $new_plans_cnt ) : 0.0;

    if ( $pp_net_turnover > 0 ) {
        $fin_delta_pct = round( ( ( $net_turnover - $pp_net_turnover ) / $pp_net_turnover ) * 100, 1 );
    } elseif ( $net_turnover > 0 ) {
        $fin_delta_pct = 100.0;
    } else {
        $fin_delta_pct = 0.0;
    }

    // 2. Unrenewed Expired Customers & Cost of Churn SQL Aggregation (Step 2.2)
    // Strictly requires:
    // - Valid existing WP User (INNER JOIN wp_users)
    // - Legitimate non-deleted plan (status IN ('active', 'completed'))
    // - Customer actually used the plan (used_days > 0) before it expired/finished
    // - Customer has ZERO currently active or paused plans across their entire history
    $churn_agg = $wpdb->get_row( $wpdb->prepare(
        "SELECT 
            COUNT(s.id) AS churned_count,
            COALESCE(SUM(GREATEST(0, s.plan_price - s.discount_amount)), 0) AS revenue_at_risk
         FROM {$table_subs} s
         INNER JOIN {$table_users} u ON s.user_id = u.ID
         INNER JOIN (
             SELECT s2.user_id,
                    MAX(CASE WHEN s2.status IN ('active', 'completed') AND s2.used_days > 0 AND (s2.used_days >= s2.total_days OR s2.expiry_date < %s) THEN s2.id ELSE 0 END) AS latest_expired_id
             FROM {$table_subs} s2
             INNER JOIN {$table_users} u2 ON s2.user_id = u2.ID
             WHERE s2.status IN ('active', 'paused', 'completed')
             GROUP BY s2.user_id
             HAVING SUM(CASE WHEN s2.status = 'paused' OR (s2.status = 'active' AND s2.used_days < s2.total_days AND s2.expiry_date >= %s) THEN 1 ELSE 0 END) = 0
                AND MAX(CASE WHEN s2.status IN ('active', 'completed') AND s2.used_days > 0 AND (s2.used_days >= s2.total_days OR s2.expiry_date < %s) THEN s2.id ELSE 0 END) > 0
         ) u_churn ON s.id = u_churn.latest_expired_id",
        $today_time, $today_time, $today_time
    ) );

    // 3. Daily Sparkline Series for Finance Period (Step 3.1)
    $spark_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(s.start_date) AS sale_date, SUM(GREATEST(0, s.plan_price - s.discount_amount)) AS daily_net
         FROM {$table_subs} s
         INNER JOIN {$table_users} u ON s.user_id = u.ID
         WHERE s.status IN ('active', 'paused', 'completed')
         AND DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s
         GROUP BY DATE(s.start_date)
         ORDER BY sale_date ASC",
        $fin_start, $fin_end
    ) );
    $spark_map = array();
    if ( $spark_rows ) {
        foreach ( $spark_rows as $sr ) {
            $spark_map[ $sr->sale_date ] = floatval( $sr->daily_net );
        }
    }

    // 4. Single SQL Aggregation for Operational Pulse, POS Reconciliation & Aging Bottlenecks
    $prep_target_start = date( 'Y-m-d', strtotime( $op_start . ' +1 day' ) );
    $prep_target_end   = date( 'Y-m-d', strtotime( $op_end . ' +1 day' ) );
    $min_log_date      = min( $op_start, $today_date );
    $max_log_date      = max( $prep_target_end, $tomorrow_date );

    $log_agg = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND l.pos_updated = 0 AND l.delivery_result != 'Pending' THEN 1 END) AS pending_pos_count,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND l.pos_updated = 0 AND l.delivery_result != 'Pending' AND DATEDIFF(%s, l.target_date) > 2 THEN 1 END) AS overdue_pos_count,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND l.pos_updated = 1 THEN 1 END) AS completed_pos_count,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.target_date <= %s AND l.is_locked = 1 AND s.status = 'active' AND (l.delivery_result IS NULL OR l.delivery_result = 'Pending') THEN 1 END) AS pending_delivery_count,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.target_date <= %s AND l.is_locked = 1 AND s.status = 'active' AND (l.delivery_result IS NULL OR l.delivery_result = 'Pending') AND DATEDIFF(%s, l.target_date) > 2 THEN 1 END) AS overdue_delivery_count,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND l.delivery_result = 'Successful' AND l.pos_updated = 1 THEN 1 END) AS successful_deliveries,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND l.delivery_result IN ('Cancelled', 'Returned') THEN 1 END) AS failed_deliveries,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND s.status = 'active' AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned')) THEN 1 END) AS dispatch_load,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND s.status = 'active' AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned')) THEN 1 END) AS total_preps,
            COUNT(CASE WHEN l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND l.is_prepared = 1 AND s.status = 'active' AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned')) THEN 1 END) AS done_preps,
            COUNT(CASE WHEN l.target_date = %s AND l.is_locked = 1 AND s.status = 'active' AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned')) THEN 1 END) AS today_total_preps,
            COUNT(CASE WHEN l.target_date = %s AND l.is_locked = 1 AND l.is_prepared = 1 AND s.status = 'active' AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned')) THEN 1 END) AS today_done_preps,
            COUNT(CASE WHEN l.target_date = %s AND l.is_locked = 1 AND s.status = 'active' AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned')) THEN 1 END) AS today_dispatch
         FROM {$table_logs} l
         INNER JOIN {$table_subs} s ON l.subscription_id = s.id
         INNER JOIN {$table_users} u ON s.user_id = u.ID
         WHERE s.status IN ('active', 'paused', 'completed')
         AND l.target_date >= %s AND l.target_date <= %s",
        $op_start, $op_end,
        $op_start, $op_end, $today_date,
        $op_start, $op_end,
        $op_start, $op_end, $today_date,
        $op_start, $op_end, $today_date, $today_date,
        $op_start, $op_end,
        $op_start, $op_end,
        $op_start, $op_end,
        $prep_target_start, $prep_target_end,
        $prep_target_start, $prep_target_end,
        $tomorrow_date,
        $tomorrow_date,
        $today_date,
        $min_log_date, $max_log_date
    ) );

    // 5. Pending Chef Assignments Count + Urgent (<48h) Split
    $active_food_ranges  = $wpdb->get_results( "SELECT DISTINCT valid_from, valid_until FROM {$table_foods} WHERE is_active = 1" );
    $unassigned_chef_raw = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.id, l.target_date, l.plan_mode, s.allow_custom
         FROM {$table_logs} l
         INNER JOIN {$table_subs} s ON l.subscription_id = s.id
         INNER JOIN {$table_users} u ON s.user_id = u.ID
         WHERE l.is_chefs_choice = 1
         AND s.status = 'active'
         AND l.target_date >= %s
         AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))
         AND (l.breakfast_id IS NULL OR l.breakfast_id = 0)
         AND (l.lunch_id IS NULL OR l.lunch_id = 0)
         AND (l.dinner_id IS NULL OR l.dinner_id = 0)
         AND (l.juice_1_id IS NULL OR l.juice_1_id = 0)
         AND (l.custom_breakfast IS NULL OR l.custom_breakfast = '')
         AND (l.custom_lunch IS NULL OR l.custom_lunch = '')
         AND (l.custom_dinner IS NULL OR l.custom_dinner = '')
         AND (l.custom_juice_1 IS NULL OR l.custom_juice_1 = '')",
        min( $op_start, $today_date )
    ) );

    $pending_chef_count = 0;
    $urgent_chef_count  = 0;
    $urgent_cutoff_date = date( 'Y-m-d', strtotime( '+2 days' ) );

    if ( $unassigned_chef_raw ) {
        foreach ( $unassigned_chef_raw as $cr ) {
            $is_custom   = ( ! empty( $cr->plan_mode ) && $cr->plan_mode === 'custom' ) || ! empty( $cr->allow_custom );
            $menu_exists = false;
            if ( $is_custom ) {
                $menu_exists = true;
            } else {
                foreach ( $active_food_ranges as $r ) {
                    if ( empty( $r->valid_from ) || empty( $r->valid_until ) || ( $cr->target_date >= $r->valid_from && $cr->target_date <= $r->valid_until ) ) {
                        $menu_exists = true;
                        break;
                    }
                }
            }
            if ( $menu_exists ) {
                $pending_chef_count++;
                if ( $cr->target_date <= $urgent_cutoff_date ) {
                    $urgent_chef_count++;
                }
            }
        }
    }

    $succ_del     = intval( $log_agg->successful_deliveries ?? 0 );
    $fail_del     = intval( $log_agg->failed_deliveries ?? 0 );
    $tot_resolved = $succ_del + $fail_del;
    $success_rate = $tot_resolved > 0 ? round( ( $succ_del / $tot_resolved ) * 100, 1 ) : 0.0;

    $tot_preps = intval( $log_agg->total_preps ?? 0 );
    $dn_preps  = intval( $log_agg->done_preps ?? 0 );
    $prep_pct  = $tot_preps > 0 ? round( ( $dn_preps / $tot_preps ) * 100 ) : 0;

    return array(
        'live_active_count'      => intval( $sub_agg->live_active_count ?? 0 ),
        'live_paused_count'      => intval( $sub_agg->live_paused_count ?? 0 ),
        'expiring_soon_count'    => intval( $sub_agg->expiring_soon_count ?? 0 ),
        'active_std_count'       => intval( $sub_agg->active_std_count ?? 0 ),
        'active_flex_count'      => intval( $sub_agg->active_flex_count ?? 0 ),
        'active_custom_count'    => intval( $sub_agg->active_custom_count ?? 0 ),
        'period_new_plans'       => $new_plans_cnt,
        'gross_turnover'         => floatval( $sub_agg->gross_turnover ?? 0 ),
        'total_discounts'        => floatval( $sub_agg->total_discounts ?? 0 ),
        'net_turnover'           => $net_turnover,
        'pp_net_turnover'        => $pp_net_turnover,
        'fin_delta_pct'          => $fin_delta_pct,
        'pp_start'               => $pp_start,
        'pp_end'                 => $pp_end,
        'avg_plan_value'         => $avg_plan_value,
        'spark_map'              => $spark_map,
        'unrenewed_total_count'  => intval( $churn_agg->churned_count ?? 0 ),
        'revenue_at_risk'        => floatval( $churn_agg->revenue_at_risk ?? 0 ),
        'pending_pos_count'      => intval( $log_agg->pending_pos_count ?? 0 ),
        'overdue_pos_count'      => intval( $log_agg->overdue_pos_count ?? 0 ),
        'completed_pos_count'    => intval( $log_agg->completed_pos_count ?? 0 ),
        'pending_delivery_count' => intval( $log_agg->pending_delivery_count ?? 0 ),
        'overdue_delivery_count' => intval( $log_agg->overdue_delivery_count ?? 0 ),
        'successful_deliveries'  => $succ_del,
        'failed_deliveries'      => $fail_del,
        'success_rate'           => $success_rate,
        'dispatch_load'          => intval( $log_agg->dispatch_load ?? 0 ),
        'total_preps'            => $tot_preps,
        'done_preps'             => $dn_preps,
        'prep_percentage'        => $prep_pct,
        'today_total_preps'      => intval( $log_agg->today_total_preps ?? 0 ),
        'today_done_preps'       => intval( $log_agg->today_done_preps ?? 0 ),
        'today_dispatch'         => intval( $log_agg->today_dispatch ?? 0 ),
        'pending_chef_count'     => $pending_chef_count,
        'urgent_chef_count'      => $urgent_chef_count,
    );
}

// ==========================================
// 4. SVG SPARKLINE RENDERER (PHASE 3.1)
// ==========================================
function cmp_sa_render_sparkline_svg( $spark_map, $fin_start, $fin_end ) {
    $points_data = array();
    $curr        = strtotime( $fin_start );
    $end_ts      = strtotime( $fin_end );
    $max_days    = 60;
    $step_days   = max( 1, ceil( ( ( $end_ts - $curr ) / 86400 ) / $max_days ) );

    while ( $curr <= $end_ts ) {
        $d_str         = date( 'Y-m-d', $curr );
        $val           = isset( $spark_map[ $d_str ] ) ? floatval( $spark_map[ $d_str ] ) : 0.0;
        $points_data[] = $val;
        $curr          = strtotime( "+{$step_days} days", $curr );
    }

    if ( count( $points_data ) < 2 ) {
        $points_data = array( 0, end( $points_data ) ?: 0 );
    }

    $max_val = max( $points_data );
    if ( $max_val <= 0 ) {
        $max_val = 1;
    }

    $width  = 320;
    $height = 48;
    $pad_y  = 6;
    $count  = count( $points_data );
    $coords = array();

    foreach ( $points_data as $idx => $v ) {
        $x        = round( ( $idx / ( $count - 1 ) ) * $width, 1 );
        $y        = round( $height - $pad_y - ( ( $v / $max_val ) * ( $height - ( $pad_y * 2 ) ) ), 1 );
        $coords[] = "{$x},{$y}";
    }

    $polyline_pts = implode( ' ', $coords );
    $polygon_pts  = "0,{$height} " . $polyline_pts . " {$width},{$height}";

    return '
    <div class="acru-sparkline-wrap" title="Daily Net Financial Velocity (' . esc_attr( date( 'd M', strtotime( $fin_start ) ) . ' - ' . date( 'd M', strtotime( $fin_end ) ) ) . ')">
        <svg class="acru-sparkline-svg" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">
            <defs>
                <linearGradient id="acruSparkGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#DCECC9" stop-opacity="0.48"/>
                    <stop offset="100%" stop-color="#DCECC9" stop-opacity="0.0"/>
                </linearGradient>
            </defs>
            <polygon points="' . esc_attr( $polygon_pts ) . '" fill="url(#acruSparkGrad)"></polygon>
            <polyline points="' . esc_attr( $polyline_pts ) . '" fill="none" stroke="#DCECC9" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></polyline>
        </svg>
    </div>';
}

// ==========================================
// 5. PHASE 4: SERVER-SIDE DRILL-DOWN QUERY ENGINE (SQL SEARCH + PAGINATION)
// ==========================================
function cmp_sa_query_drilldown_records( $sa_view, $op_start, $op_end, $fin_start, $fin_end, $search = '', $del_filter = 'all', $paged = 1, $per_page = 50 ) {
    global $wpdb;
    cmp_sa_ensure_schema_and_sync();

    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $table_users = $wpdb->prefix . 'users';
    $table_umeta = $wpdb->prefix . 'usermeta';
    $today_time  = date( 'Y-m-d H:i:s' );
    $today_date  = date( 'Y-m-d' );

    $paged  = max( 1, intval( $paged ) );
    $offset = ( $paged - 1 ) * $per_page;

    // Build Server-Side Search Clause (Step 4.2)
    $search_sql = '';
    if ( ! empty( $search ) ) {
        $clean_s  = trim( $search );
        $like_str = '%' . $wpdb->esc_like( $clean_s ) . '%';
        $num_only = preg_replace( '/[^0-9]/', '', $clean_s );

        if ( ! empty( $num_only ) ) {
            $neg_id     = -1 * intval( $num_only );
            $search_sql = $wpdb->prepare(
                " AND (
                    u.display_name LIKE %s 
                    OR u.user_email LIKE %s 
                    OR s.plan_name LIKE %s 
                    OR CAST(s.wc_order_id AS CHAR) LIKE %s 
                    OR s.wc_order_id = %d
                    OR s.id = %d
                    OR s.user_id IN (
                        SELECT user_id FROM {$table_umeta} 
                        WHERE meta_key IN ('first_name','last_name','billing_first_name','billing_last_name','billing_phone') 
                        AND meta_value LIKE %s
                    )
                )",
                $like_str, $like_str, $like_str, $like_str, $neg_id, intval( $num_only ), $like_str
            );
        } else {
            $search_sql = $wpdb->prepare(
                " AND (
                    u.display_name LIKE %s 
                    OR u.user_email LIKE %s 
                    OR s.plan_name LIKE %s 
                    OR s.user_id IN (
                        SELECT user_id FROM {$table_umeta} 
                        WHERE meta_key IN ('first_name','last_name','billing_first_name','billing_last_name','billing_phone') 
                        AND meta_value LIKE %s
                    )
                )",
                $like_str, $like_str, $like_str, $like_str
            );
        }
    }

    $limit_sql = ( $per_page > 0 ) ? $wpdb->prepare( " LIMIT %d OFFSET %d", $per_page, $offset ) : '';

    // GROUP A: SUBSCRIPTION-BASED VIEWS (STRICTLY EXCLUDES DELETED PLANS & DELETED USERS)
    if ( in_array( $sa_view, array( 'unrenewed_expired', 'active_plans', 'paused_plans', 'expiring_soon', 'period_sales' ), true ) ) {
        if ( $sa_view === 'unrenewed_expired' ) {
            // Only real customers (INNER JOIN wp_users) who used >= 1 meal on an expired plan and have 0 active/paused plans
            $where_sql = $wpdb->prepare(
                "s.id IN (
                    SELECT MAX(CASE WHEN s2.status IN ('active', 'completed') AND s2.used_days > 0 AND (s2.used_days >= s2.total_days OR s2.expiry_date < %s) THEN s2.id ELSE 0 END)
                    FROM {$table_subs} s2
                    INNER JOIN {$table_users} u2 ON s2.user_id = u2.ID
                    WHERE s2.status IN ('active', 'paused', 'completed')
                    GROUP BY s2.user_id
                    HAVING SUM(CASE WHEN s2.status = 'paused' OR (s2.status = 'active' AND s2.used_days < s2.total_days AND s2.expiry_date >= %s) THEN 1 ELSE 0 END) = 0
                       AND MAX(CASE WHEN s2.status IN ('active', 'completed') AND s2.used_days > 0 AND (s2.used_days >= s2.total_days OR s2.expiry_date < %s) THEN s2.id ELSE 0 END) > 0
                )",
                $today_time, $today_time, $today_time
            );
            $order_by = "s.expiry_date DESC, s.id DESC";
        } elseif ( $sa_view === 'active_plans' ) {
            $where_sql = $wpdb->prepare( "s.status = 'active' AND s.used_days < s.total_days AND s.expiry_date >= %s", $today_time );
            $order_by  = "s.id DESC";
        } elseif ( $sa_view === 'paused_plans' ) {
            $where_sql = "s.status = 'paused'";
            $order_by  = "s.id DESC";
        } elseif ( $sa_view === 'expiring_soon' ) {
            $where_sql = $wpdb->prepare( "s.status = 'active' AND s.used_days < s.total_days AND s.expiry_date >= %s AND (s.total_days - s.used_days) BETWEEN 1 AND 3", $today_time );
            $order_by  = "(s.total_days - s.used_days) ASC, s.expiry_date ASC";
        } else {
            // period_sales uses the dedicated Finance Date Filter and excludes deleted plans
            $where_sql = $wpdb->prepare( "s.status IN ('active', 'paused', 'completed') AND DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s", $fin_start, $fin_end );
            $order_by  = "s.start_date DESC, s.id DESC";
        }

        $total_rows = intval( $wpdb->get_var(
            "SELECT COUNT(s.id)
             FROM {$table_subs} s
             INNER JOIN {$table_users} u ON s.user_id = u.ID
             WHERE {$where_sql} {$search_sql}"
        ) );

        $raw_rows = $wpdb->get_results(
            "SELECT s.*, u.display_name, u.user_email
             FROM {$table_subs} s
             INNER JOIN {$table_users} u ON s.user_id = u.ID
             WHERE {$where_sql} {$search_sql}
             ORDER BY {$order_by} {$limit_sql}"
        );

        $formatted = array();
        if ( $raw_rows ) {
            foreach ( $raw_rows as $sub ) {
                $uid        = intval( $sub->user_id );
                $sub_id     = intval( $sub->id );
                $used_days  = intval( $sub->used_days );
                $total_days = intval( $sub->total_days );
                $balance    = max( 0, $total_days - $used_days );
                $contact    = cmp_sa_get_customer_contact( $uid, $sub->wc_order_id, $sub->display_name, $sub->user_email );

                $price      = floatval( $sub->plan_price );
                $discount   = floatval( $sub->discount_amount );
                $final_paid = max( 0.0, $price - $discount );

                $is_completed = ( $used_days >= $total_days || $sub->expiry_date < $today_time );
                if ( $is_completed ) {
                    $status_key   = 'expired';
                    $status_label = 'Completed / Expired';
                } elseif ( $sub->status === 'paused' ) {
                    $status_key   = 'paused';
                    $status_label = 'Paused';
                } else {
                    $status_key   = 'active';
                    $status_label = 'Active';
                }

                $formatted[] = array(
                    'sub_id'       => $sub_id,
                    'user_id'      => $uid,
                    'order_id'     => cmp_sa_format_order_id( $sub->wc_order_id, $sub_id ),
                    'name'         => $contact['name'],
                    'email'        => $contact['email'],
                    'phone'        => $contact['phone'],
                    'plan_name'    => $sub->plan_name,
                    'status_key'   => $status_key,
                    'status_label' => $status_label,
                    'used_days'    => $used_days,
                    'total_days'   => $total_days,
                    'balance'      => $balance,
                    'price'        => $price,
                    'discount'     => $discount,
                    'paid'         => $final_paid,
                    'start_date'   => substr( $sub->start_date, 0, 10 ),
                    'expiry_date'  => substr( $sub->expiry_date, 0, 10 ),
                );
            }
        }

        return array(
            'rows'        => $formatted,
            'total_rows'  => $total_rows,
            'total_pages' => $per_page > 0 ? max( 1, ceil( $total_rows / $per_page ) ) : 1,
            'paged'       => $paged,
        );
    }

    // GROUP B: DAILY LOGS-BASED VIEWS (WITH STEP 2.3 AGING BOTTLENECK FLAGS)
    if ( $sa_view === 'pending_pos' ) {
        $where_sql = $wpdb->prepare(
            "s.status IN ('active', 'paused', 'completed') AND l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND l.pos_updated = 0 AND l.delivery_result != 'Pending'",
            $op_start, $op_end
        );
        $order_by = "(DATEDIFF('{$today_date}', l.target_date) > 2) DESC, l.target_date ASC, l.id DESC";
    } elseif ( $sa_view === 'completed_pos' ) {
        $where_sql = $wpdb->prepare(
            "s.status IN ('active', 'paused', 'completed') AND l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1 AND l.pos_updated = 1",
            $op_start, $op_end
        );
        $order_by = "l.target_date DESC, l.id DESC";
    } elseif ( $sa_view === 'pending_delivery' ) {
        $where_sql = $wpdb->prepare(
            "s.status = 'active' AND l.target_date >= %s AND l.target_date <= %s AND l.target_date <= %s AND l.is_locked = 1 AND (l.delivery_result IS NULL OR l.delivery_result = 'Pending')",
            $op_start, $op_end, $today_date
        );
        $order_by = "(DATEDIFF('{$today_date}', l.target_date) > 2) DESC, l.target_date ASC, l.id DESC";
    } elseif ( $sa_view === 'pending_chef' ) {
        $where_sql = $wpdb->prepare(
            "l.is_chefs_choice = 1 AND s.status = 'active' AND l.target_date >= %s
             AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))
             AND (l.breakfast_id IS NULL OR l.breakfast_id = 0)
             AND (l.lunch_id IS NULL OR l.lunch_id = 0)
             AND (l.dinner_id IS NULL OR l.dinner_id = 0)
             AND (l.juice_1_id IS NULL OR l.juice_1_id = 0)
             AND (l.custom_breakfast IS NULL OR l.custom_breakfast = '')
             AND (l.custom_lunch IS NULL OR l.custom_lunch = '')
             AND (l.custom_dinner IS NULL OR l.custom_dinner = '')
             AND (l.custom_juice_1 IS NULL OR l.custom_juice_1 = '')",
            min( $op_start, $today_date )
        );
        $order_by = "l.target_date ASC, l.id ASC";
    } else {
        // delivery_logs
        $where_sql = $wpdb->prepare(
            "s.status IN ('active', 'paused', 'completed') AND l.target_date >= %s AND l.target_date <= %s AND l.is_locked = 1",
            $op_start, $op_end
        );
        if ( $del_filter === 'Successful' ) {
            $where_sql .= " AND l.delivery_result = 'Successful'";
        } elseif ( $del_filter === 'Failed' ) {
            $where_sql .= " AND l.delivery_result IN ('Cancelled', 'Returned')";
        } elseif ( $del_filter === 'Pending' ) {
            $where_sql .= " AND (l.delivery_result IS NULL OR l.delivery_result = 'Pending')";
        }
        $order_by = "l.target_date DESC, l.id DESC";
    }

    $total_rows = intval( $wpdb->get_var(
        "SELECT COUNT(l.id)
         FROM {$table_logs} l
         INNER JOIN {$table_subs} s ON l.subscription_id = s.id
         INNER JOIN {$table_users} u ON s.user_id = u.ID
         WHERE {$where_sql} {$search_sql}"
    ) );

    $raw_logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, u.display_name, u.user_email,
                DATEDIFF(%s, l.target_date) AS age_days
         FROM {$table_logs} l
         INNER JOIN {$table_subs} s ON l.subscription_id = s.id
         INNER JOIN {$table_users} u ON s.user_id = u.ID
         WHERE {$where_sql} {$search_sql}
         ORDER BY {$order_by} {$limit_sql}",
        $today_date
    ) );

    $formatted = array();
    if ( $raw_logs ) {
        foreach ( $raw_logs as $l ) {
            $contact  = cmp_sa_get_customer_contact( $l->user_id, $l->wc_order_id, $l->display_name, $l->user_email );
            $prep_dt  = date( 'Y-m-d', strtotime( $l->target_date . ' -1 day' ) );
            $del_date = $contact['is_day_before'] ? $prep_dt : $l->target_date;
            $del_res  = ! empty( $l->delivery_result ) ? $l->delivery_result : 'Pending';
            $age_days = intval( $l->age_days );

            $formatted[] = array(
                'log_id'          => intval( $l->id ),
                'sub_id'          => intval( $l->subscription_id ),
                'order_id'        => cmp_sa_format_order_id( $l->wc_order_id, $l->subscription_id ),
                'name'            => $contact['name'],
                'email'           => $contact['email'],
                'phone'           => $contact['phone'],
                'plan_name'       => $l->plan_name,
                'target_date'     => $l->target_date,
                'delivery_date'   => $del_date,
                'timing_label'    => $contact['timing_label'],
                'is_prepared'     => intval( $l->is_prepared ),
                'dispatch_status' => intval( $l->dispatch_status ),
                'delivery_result' => $del_res,
                'pos_updated'     => intval( $l->pos_updated ),
                'age_days'        => $age_days,
                'is_overdue'      => ( $age_days > 2 ),
            );
        }
    }

    return array(
        'rows'        => $formatted,
        'total_rows'  => $total_rows,
        'total_pages' => $per_page > 0 ? max( 1, ceil( $total_rows / $per_page ) ) : 1,
        'paged'       => $paged,
    );
}

// ==========================================
// 6. AJAX CSV EXPORT FOR DRILL-DOWN VIEWS
// ==========================================
add_action( 'wp_ajax_cmp_sa_export_drilldown', 'cmp_sa_export_drilldown_csv' );
function cmp_sa_export_drilldown_csv() {
    if ( ! is_user_logged_in() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) ) {
        wp_die( 'Permission Denied' );
    }

    date_default_timezone_set( 'Asia/Dubai' );
    $view       = isset( $_GET['sa_view'] ) ? sanitize_text_field( $_GET['sa_view'] ) : 'unrenewed_expired';
    $op_start   = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'Y-m-d', strtotime( '-29 days' ) );
    $op_end     = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'Y-m-d' );
    $fin_start  = isset( $_GET['fin_start'] ) ? sanitize_text_field( $_GET['fin_start'] ) : $op_start;
    $fin_end    = isset( $_GET['fin_end'] ) ? sanitize_text_field( $_GET['fin_end'] ) : $op_end;
    $search     = isset( $_GET['sa_search'] ) ? sanitize_text_field( wp_unslash( $_GET['sa_search'] ) ) : '';
    $del_filter = isset( $_GET['del_filter'] ) ? sanitize_text_field( $_GET['del_filter'] ) : 'all';

    $result = cmp_sa_query_drilldown_records( $view, $op_start, $op_end, $fin_start, $fin_end, $search, $del_filter, 1, 0 );
    $rows   = $result['rows'];

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="Executive_' . ucfirst( $view ) . '_' . date( 'Y-m-d' ) . '.csv"' );
    $output = fopen( 'php://output', 'w' );
    fputs( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

    if ( $view === 'unrenewed_expired' ) {
        fputcsv( $output, array( 'Last Order ID', 'Customer Name', 'Email', 'Phone', 'Last Plan Name', 'Days Used', 'Total Days', 'Last Plan Paid (AED)', 'Start Date', 'Expired / Completed Date' ) );
        foreach ( $rows as $c ) {
            fputcsv( $output, array(
                $c['order_id'], $c['name'], $c['email'], $c['phone'], $c['plan_name'],
                $c['used_days'], $c['total_days'], number_format( $c['paid'], 2, '.', '' ),
                $c['start_date'], $c['expiry_date']
            ) );
        }
    } elseif ( in_array( $view, array( 'active_plans', 'paused_plans', 'expiring_soon', 'period_sales' ), true ) ) {
        fputcsv( $output, array( 'Order ID', 'Customer Name', 'Email', 'Phone', 'Plan Name', 'Status', 'Used Days', 'Total Days', 'Balance Days', 'Gross Price (AED)', 'Discount (AED)', 'Net Paid (AED)', 'Start Date', 'Expiry Date' ) );
        foreach ( $rows as $s ) {
            fputcsv( $output, array(
                $s['order_id'], $s['name'], $s['email'], $s['phone'], $s['plan_name'], $s['status_label'],
                $s['used_days'], $s['total_days'], $s['balance'],
                number_format( $s['price'], 2, '.', '' ), number_format( $s['discount'], 2, '.', '' ), number_format( $s['paid'], 2, '.', '' ),
                $s['start_date'], $s['expiry_date']
            ) );
        }
    } else {
        fputcsv( $output, array( 'Eating Date', 'Delivery Date', 'Timing', 'Age (Days)', 'Order ID', 'Customer Name', 'Email', 'Phone', 'Plan Name', 'Prepared', 'Dispatched', 'Delivery Result', 'POS Reconciled' ) );
        foreach ( $rows as $l ) {
            fputcsv( $output, array(
                $l['target_date'], $l['delivery_date'], $l['timing_label'], $l['age_days'], $l['order_id'],
                $l['name'], $l['email'], $l['phone'], $l['plan_name'],
                $l['is_prepared'] ? 'Yes' : 'No',
                $l['dispatch_status'] ? 'Yes' : 'No',
                $l['delivery_result'],
                $l['pos_updated'] ? 'Yes' : 'No'
            ) );
        }
    }

    fclose( $output );
    exit;
}

// ==========================================
// 7. UNIFIED SUPER ADMIN COMMAND CENTER ROUTER
// ==========================================
add_shortcode( 'meal_super_admin', 'cmp_render_super_admin_portal' );

function cmp_render_super_admin_portal() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    date_default_timezone_set( 'Asia/Dubai' );

    $has_exec_access = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' );

    // Security & Custom Glassmorphism UI/UX Login Screen
    if ( ! is_user_logged_in() || ! $has_exec_access ) {
        if ( function_exists( 'cmp_render_portal_login_ui' ) ) {
            return cmp_render_portal_login_ui( array(
                'portal_id'      => 'super_admin',
                'title'          => 'Executive Portal',
                'subtitle'       => 'Master Command Center & Business Analytics',
                'required_caps'  => array( 'manage_options', 'menu_manager' ),
                'denied_message' => 'Executive or Menu Manager clearance is required to access this portal.',
            ) );
        }
    }

    $today_date        = date( 'Y-m-d' );
    $preset_7d         = date( 'Y-m-d', strtotime( '-6 days' ) );
    $preset_30d        = date( 'Y-m-d', strtotime( '-29 days' ) );
    $preset_this_month = date( 'Y-m-01' );
    $preset_last_m_s   = date( 'Y-m-01', strtotime( 'first day of last month' ) );
    $preset_last_m_e   = date( 'Y-m-t', strtotime( 'last day of last month' ) );
    $preset_90d        = date( 'Y-m-d', strtotime( '-89 days' ) );
    $preset_ytd        = date( 'Y-01-01' );

    // 1. Operational Date Filter (Top Bar)
    $op_start = isset( $_GET['start_date'] ) && ! empty( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : $preset_30d;
    $op_end   = isset( $_GET['end_date'] ) && ! empty( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : $today_date;
    if ( $op_start > $op_end ) {
        $tmp      = $op_start;
        $op_start = $op_end;
        $op_end   = $tmp;
    }

    // 2. Separate Finance Date Filter (Hero Card)
    $fin_start = isset( $_GET['fin_start'] ) && ! empty( $_GET['fin_start'] ) ? sanitize_text_field( $_GET['fin_start'] ) : $preset_30d;
    $fin_end   = isset( $_GET['fin_end'] ) && ! empty( $_GET['fin_end'] ) ? sanitize_text_field( $_GET['fin_end'] ) : $today_date;
    if ( $fin_start > $fin_end ) {
        $tmp       = $fin_start;
        $fin_start = $fin_end;
        $fin_end   = $tmp;
    }

    $sa_view = isset( $_GET['sa_view'] ) ? sanitize_text_field( $_GET['sa_view'] ) : '';
    $summary = cmp_sa_get_dashboard_summary( $op_start, $op_end, $fin_start, $fin_end );

    if ( ! empty( $sa_view ) ) {
        return cmp_sa_render_drilldown_screen( $sa_view, $summary, $op_start, $op_end, $fin_start, $fin_end );
    }

    $current_user = wp_get_current_user();
    $first_name   = ! empty( $current_user->user_firstname ) ? $current_user->user_firstname : $current_user->display_name;

    $op_preset = 'custom';
    if ( $op_end === $today_date ) {
        if ( $op_start === $today_date )            { $op_preset = 'today'; }
        elseif ( $op_start === $preset_7d )         { $op_preset = '7d'; }
        elseif ( $op_start === $preset_30d )        { $op_preset = '30d'; }
        elseif ( $op_start === $preset_this_month ) { $op_preset = 'month'; }
        elseif ( $op_start === $preset_90d )        { $op_preset = '90d'; }
    }

    $fin_preset = 'custom';
    if ( $fin_start === $preset_this_month && $fin_end === $today_date ) {
        $fin_preset = 'this_month';
    } elseif ( $fin_start === $preset_last_m_s && $fin_end === $preset_last_m_e ) {
        $fin_preset = 'last_month';
    } elseif ( $fin_start === $preset_30d && $fin_end === $today_date ) {
        $fin_preset = '30d';
    } elseif ( $fin_start === $preset_90d && $fin_end === $today_date ) {
        $fin_preset = '90d';
    } elseif ( $fin_start === $preset_ytd && $fin_end === $today_date ) {
        $fin_preset = 'ytd';
    }

    $base_args = array(
        'start_date' => $op_start,
        'end_date'   => $op_end,
        'fin_start'  => $fin_start,
        'fin_end'    => $fin_end,
    );

    ob_start();
    ?>
    <style>
        .acru-dashboard, .acru-dashboard * { box-sizing: border-box; }
        .acru-dashboard svg.acru-icon,
        .acru-dashboard img.emoji,
        .acru-dashboard img.wp-smiley {
            width: 14px !important;
            height: 14px !important;
            max-width: 16px !important;
            max-height: 16px !important;
            display: inline-block !important;
            vertical-align: -2px !important;
            flex-shrink: 0 !important;
        }
        .acru-dashboard {
            background: linear-gradient(145deg, rgba(244, 248, 242, 0.94) 0%, rgba(238, 247, 227, 0.65) 100%);
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #231F20;
            border-radius: 22px;
            border: 1px solid #DCECC9;
            max-width: 1350px;
            margin: 0 auto 40px auto;
            box-shadow: 0 12px 34px rgba(27, 94, 32, 0.06);
        }

        /* Top Glass Header */
        .acru-top-bar {
            background: linear-gradient(135deg, rgba(27, 94, 32, 0.95) 0%, rgba(55, 146, 55, 0.89) 55%, rgba(104, 185, 70, 0.85) 100%) !important;
            backdrop-filter: blur(18px) !important;
            -webkit-backdrop-filter: blur(18px) !important;
            padding: 22px 26px !important;
            border-radius: 18px !important;
            border: 1.5px solid #DCECC9 !important;
            border-bottom: 4px solid #68B946 !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-bottom: 22px !important;
            flex-wrap: wrap !important;
            gap: 16px !important;
            box-shadow: 0 12px 28px rgba(27, 94, 32, 0.14) !important;
        }
        .acru-welcome h1 { font-size: 1.75em !important; font-weight: 800 !important; margin: 0 !important; color: #ffffff !important; letter-spacing: -0.02em !important; }
        .acru-welcome p { font-size: 0.93em !important; color: #EEF7E3 !important; margin: 4px 0 0 0 !important; font-weight: 500 !important; }

        .acru-controls-wrap { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .acru-preset-pills { display: inline-flex; background: rgba(255, 255, 255, 0.16); padding: 4px; border-radius: 10px; border: 1px solid rgba(220, 236, 201, 0.4); gap: 4px; flex-wrap: wrap; align-items: center; }
        .acru-preset-pill { padding: 5px 10px; border-radius: 7px; font-size: 0.78em; font-weight: 700; color: #EEF7E3 !important; text-decoration: none !important; transition: 0.2s; white-space: nowrap; }
        .acru-preset-pill:hover { background: rgba(255, 255, 255, 0.24); color: #ffffff !important; }
        .acru-preset-pill.active { background: #ffffff; color: #1B5E20 !important; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }

        .acru-global-filter { display: flex; background: rgba(255, 255, 255, 0.96); padding: 5px 10px; border-radius: 10px; border: 1px solid #DCECC9; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); flex-wrap: wrap; }
        .acru-global-filter input[type="date"] { border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 8px; font-size: 0.82em; color: #231F20; outline: none; background: #fff; font-weight: 600; }
        .acru-global-filter button { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: #fff; border: none; padding: 6px 12px; border-radius: 7px; font-weight: 700; font-size: 0.82em; cursor: pointer; transition: 0.2s; }
        .acru-global-filter button:hover { background: linear-gradient(135deg, #68B946 0%, #379237 100%); }

        .acru-logout-btn { background: #dc2626; color: #ffffff !important; padding: 8px 15px; border-radius: 10px; text-decoration: none !important; font-weight: 700; font-size: 0.86em; border: 1px solid rgba(255,255,255,0.3); transition: 0.2s; white-space: nowrap; }
        .acru-logout-btn:hover { background: #b91c1c; color: #ffffff !important; }

        /* Top Priority Highlight Strip */
        .acru-priority-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .acru-priority-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 18px 20px;
            border: 1.5px solid #e2e8f0;
            border-top: 5px solid #379237;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
            text-decoration: none !important;
            color: inherit !important;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
        }
        .acru-priority-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(27, 94, 32, 0.12);
        }
        .acru-priority-card.urgent-red {
            border-color: #fecaca;
            border-top: 5px solid #dc2626;
            background: linear-gradient(180deg, #fff5f5 0%, #ffffff 65%);
        }
        .acru-priority-card.urgent-amber {
            border-color: #fde68a;
            border-top: 5px solid #f59e0b;
            background: linear-gradient(180deg, #fffbeb 0%, #ffffff 65%);
        }
        .acru-priority-card.brand-green {
            border-color: #DCECC9;
            border-top: 5px solid #379237;
            background: linear-gradient(180deg, #f4fbf0 0%, #ffffff 65%);
        }
        .acru-priority-card.retarget-purple {
            border-color: #ddd6fe;
            border-top: 5px solid #7c3aed;
            background: linear-gradient(180deg, #f5f3ff 0%, #ffffff 65%);
        }
        .priority-badge-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            margin-bottom: 10px;
        }
        .priority-title {
            font-size: 0.8em;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .priority-val {
            font-size: 2.35em;
            font-weight: 900;
            line-height: 1.05;
            color: #1e293b;
            margin: 0 0 12px 0;
            display: flex;
            align-items: baseline;
            gap: 8px;
            flex-wrap: nowrap;
        }
        .priority-foot {
            padding-top: 10px;
            border-top: 1px dashed #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .priority-foot-meta {
            font-size: 0.8em;
            color: #64748b;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .priority-foot-cta {
            font-size: 0.82em;
            font-weight: 800;
            color: #379237;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .sa-pill {
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 0.74em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap !important;
            line-height: 1.2;
        }

        /* 12-Column Bento Grid */
        .acru-bento-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 20px; }
        .acru-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 16px;
            padding: 22px 24px;
            border: 1px solid #DCECC9;
            box-shadow: 0 6px 18px rgba(27, 94, 32, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        .col-span-7 { grid-column: span 7; }
        .col-span-5 { grid-column: span 5; }
        .col-span-4 { grid-column: span 4; }
        .col-span-6 { grid-column: span 6; }
        .col-span-12 { grid-column: span 12; }

        .card-label { font-size: 0.82em; font-weight: 800; color: #1B5E20; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .card-value { font-size: 2.25em; font-weight: 900; color: #231F20; line-height: 1.1; }
        .card-subtext { font-size: 0.84em; color: #64748b; margin-top: 8px; font-weight: 500; }

        /* Dedicated Finance Filter Bar inside Hero Card */
        .hero-fin-filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            background: rgba(0, 0, 0, 0.18);
            padding: 6px 10px;
            border-radius: 10px;
            border: 1px solid rgba(220, 236, 201, 0.25);
            margin-bottom: 14px;
        }
        .hero-fin-pills { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
        .hero-fin-pill {
            padding: 4px 9px;
            border-radius: 6px;
            font-size: 0.74em;
            font-weight: 700;
            color: #DCECC9 !important;
            text-decoration: none !important;
            transition: 0.2s;
            white-space: nowrap;
        }
        .hero-fin-pill:hover { background: rgba(255, 255, 255, 0.16); color: #ffffff !important; }
        .hero-fin-pill.active { background: #EEF7E3; color: #1B5E20 !important; font-weight: 800; }

        .hero-fin-custom-form { display: inline-flex; align-items: center; gap: 5px; margin: 0; }
        .hero-fin-custom-form input[type="date"] {
            background: rgba(255, 255, 255, 0.92);
            border: none;
            border-radius: 5px;
            padding: 3px 6px;
            font-size: 0.75em;
            color: #1B5E20;
            font-weight: 700;
            width: 112px;
        }
        .hero-fin-custom-form button {
            background: #68B946;
            color: #ffffff;
            border: none;
            border-radius: 5px;
            padding: 4px 9px;
            font-size: 0.75em;
            font-weight: 800;
            cursor: pointer;
        }

        /* Sparkline Container */
        .acru-sparkline-wrap { width: 100%; height: 46px; margin: 8px 0 4px 0; overflow: hidden; }
        .acru-sparkline-svg { width: 100% !important; height: 46px !important; max-width: none !important; max-height: none !important; display: block !important; }

        /* Hero Financial Sub-Grids */
        .hero-fin-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 8px;
            padding: 12px 14px;
            background: rgba(0, 0, 0, 0.18);
            border-radius: 12px;
            border: 1px solid rgba(220, 236, 201, 0.25);
        }
        .hero-health-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.22);
        }
        .hero-stat-link {
            text-decoration: none !important;
            color: #ffffff !important;
            padding: 4px 6px;
            border-radius: 8px;
            transition: background 0.2s;
            display: block;
            min-width: 0;
        }
        .hero-stat-link:hover { background: rgba(255, 255, 255, 0.14); }
        .hero-stat-val {
            font-size: 1.02em;
            font-weight: 800;
            color: #ffffff;
            white-space: nowrap !important;
            display: block;
            margin-top: 2px;
        }

        /* 4-Portal Quick Launch Grid */
        .quick-launch-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 10px; }
        .ql-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: #f8fcf5;
            border: 1.5px solid #DCECC9;
            border-radius: 12px;
            color: #1B5E20 !important;
            font-weight: 800;
            text-decoration: none !important;
            font-size: 0.92em;
            transition: all 0.2s ease;
        }
        .ql-btn:hover {
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
            color: #ffffff !important;
            border-color: #1B5E20;
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(55, 146, 55, 0.22);
        }

        /* Progress Bars & Metric Rows */
        .progress-container { background: #e2e8f0; border-radius: 999px; height: 10px; width: 100%; overflow: hidden; margin: 12px 0 6px 0; }
        .progress-bar-fill { background: linear-gradient(90deg, #68B946, #379237); height: 100%; border-radius: 999px; transition: width 0.4s ease; }

        .metric-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #f1f5f9; gap: 8px; }
        .metric-row:last-child { border-bottom: none; }
        .metric-badge-link { text-decoration: none !important; padding: 4px 10px; border-radius: 8px; font-weight: 800; font-size: 0.84em; transition: 0.2s; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .metric-badge-link:hover { transform: translateY(-1px); filter: brightness(0.95); }

        @media (max-width: 1120px) {
            .acru-priority-strip { grid-template-columns: repeat(2, 1fr); }
            .col-span-7, .col-span-5 { grid-column: span 12; }
            .col-span-4 { grid-column: span 6; }
            .acru-bento-grid > .col-span-4:last-child { grid-column: span 12; }
        }
        @media (max-width: 640px) {
            .acru-dashboard { padding: 14px; }
            .acru-priority-strip { grid-template-columns: 1fr; }
            .col-span-7, .col-span-5, .col-span-4, .col-span-6 { grid-column: span 12; }
            .hero-fin-strip, .hero-health-strip { grid-template-columns: repeat(2, 1fr); }
            .quick-launch-grid { grid-template-columns: 1fr; }
        }
    </style>

    <div class="acru-dashboard">
        
        <!-- Top Navigation & Operational Date Filter Bar -->
        <div class="acru-top-bar">
            <div class="acru-welcome">
                <h1>Executive Command Center</h1>
                <p>Welcome back, <strong><?php echo esc_html( $first_name ); ?></strong>. Click any metric card to inspect paginated records.</p>
            </div>
            
            <div class="acru-controls-wrap">
                <div class="acru-preset-pills">
                    <span style="font-size:0.72em; font-weight:800; color:#DCECC9; padding:0 6px; text-transform:uppercase;">Ops Filter:</span>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'start_date' => $today_date, 'end_date' => $today_date ) ) ) ); ?>" class="acru-preset-pill <?php echo $op_preset === 'today' ? 'active' : ''; ?>">Today</a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'start_date' => $preset_7d, 'end_date' => $today_date ) ) ) ); ?>" class="acru-preset-pill <?php echo $op_preset === '7d' ? 'active' : ''; ?>">7D</a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'start_date' => $preset_30d, 'end_date' => $today_date ) ) ) ); ?>" class="acru-preset-pill <?php echo $op_preset === '30d' ? 'active' : ''; ?>">30D</a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'start_date' => $preset_this_month, 'end_date' => $today_date ) ) ) ); ?>" class="acru-preset-pill <?php echo $op_preset === 'month' ? 'active' : ''; ?>">This Month</a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'start_date' => $preset_90d, 'end_date' => $today_date ) ) ) ); ?>" class="acru-preset-pill <?php echo $op_preset === '90d' ? 'active' : ''; ?>">90D</a>
                </div>

                <form method="GET" class="acru-global-filter">
                    <input type="hidden" name="fin_start" value="<?php echo esc_attr( $fin_start ); ?>">
                    <input type="hidden" name="fin_end" value="<?php echo esc_attr( $fin_end ); ?>">
                    <input type="date" name="start_date" value="<?php echo esc_attr( $op_start ); ?>">
                    <span style="color: #64748b; font-weight: 700; font-size: 0.82em;">to</span>
                    <input type="date" name="end_date" value="<?php echo esc_attr( $op_end ); ?>">
                    <button type="submit">Apply</button>
                </form>

                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="acru-logout-btn">Log Out</a>
            </div>
        </div>

        <!-- =========================================================
             TOP PRIORITY HIGHLIGHT ROW: POS CHECKS, DELIVERY STATUS & CHURN COST
             ========================================================= -->
        <div class="acru-priority-strip">
            <!-- Priority 1: Pending POS Checks -->
            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'pending_pos' ) ) ) ); ?>" class="acru-priority-card <?php echo $summary['pending_pos_count'] > 0 ? 'urgent-red' : 'brand-green'; ?>">
                <div>
                    <div class="priority-badge-row">
                        <?php
                        if ( $summary['overdue_pos_count'] > 0 ) {
                            echo cmp_sa_semantic_pill( 'danger', $summary['overdue_pos_count'] . ' Overdue (>48h)' );
                        } elseif ( $summary['pending_pos_count'] > 0 ) {
                            echo cmp_sa_semantic_pill( 'warning', 'Needs FOH Action' );
                        } else {
                            echo cmp_sa_semantic_pill( 'success', '100% Reconciled' );
                        }
                        ?>
                    </div>
                    <div class="priority-title">Pending POS Checks</div>
                    <div class="priority-val" style="color: <?php echo $summary['pending_pos_count'] > 0 ? '#dc2626' : '#166534'; ?>;">
                        <span><?php echo number_format( $summary['pending_pos_count'] ); ?></span>
                    </div>
                </div>
                <div class="priority-foot">
                    <span class="priority-foot-meta"><?php echo number_format( $summary['completed_pos_count'] ); ?> Verified in period</span>
                    <span class="priority-foot-cta">Inspect POS Queue &rarr;</span>
                </div>
            </a>

            <!-- Priority 2: Pending Delivery Status (Kitchen) -->
            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'pending_delivery' ) ) ) ); ?>" class="acru-priority-card <?php echo $summary['pending_delivery_count'] > 0 ? 'urgent-amber' : 'brand-green'; ?>">
                <div>
                    <div class="priority-badge-row">
                        <?php
                        if ( $summary['overdue_delivery_count'] > 0 ) {
                            echo cmp_sa_semantic_pill( 'danger', $summary['overdue_delivery_count'] . ' Critical Overdue' );
                        } elseif ( $summary['pending_delivery_count'] > 0 ) {
                            echo cmp_sa_semantic_pill( 'warning', 'Unlogged Status' );
                        } else {
                            echo cmp_sa_semantic_pill( 'success', 'All Logged' );
                        }
                        ?>
                    </div>
                    <div class="priority-title">Pending Delivery Status</div>
                    <div class="priority-val" style="color: <?php echo $summary['pending_delivery_count'] > 0 ? '#d97706' : '#166534'; ?>;">
                        <span><?php echo number_format( $summary['pending_delivery_count'] ); ?></span>
                    </div>
                </div>
                <div class="priority-foot">
                    <span class="priority-foot-meta">Past/Today meals still 'Pending'</span>
                    <span class="priority-foot-cta">Inspect Unlogged Meals &rarr;</span>
                </div>
            </a>

            <!-- Priority 3: Delivery Status Summary -->
            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'delivery_logs' ) ) ) ); ?>" class="acru-priority-card brand-green">
                <div>
                    <div class="priority-badge-row">
                        <?php echo cmp_sa_semantic_pill( 'success', $summary['success_rate'] . '% Success Rate' ); ?>
                    </div>
                    <div class="priority-title">Delivery Status Summary</div>
                    <div class="priority-val" style="color: #1B5E20;">
                        <span><?php echo number_format( $summary['successful_deliveries'] ); ?></span>
                        <span style="font-size: 0.42em; color: #dc2626; font-weight: 800; white-space: nowrap;">(<?php echo number_format( $summary['failed_deliveries'] ); ?> Ret/Canc)</span>
                    </div>
                </div>
                <div class="priority-foot">
                    <span class="priority-foot-meta">Verified deliveries vs failures</span>
                    <span class="priority-foot-cta">Inspect Delivery Logs &rarr;</span>
                </div>
            </a>

            <!-- Priority 4: Unrenewed Expired Customers & Cost of Churn (Step 2.2) -->
            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'unrenewed_expired' ) ) ) ); ?>" class="acru-priority-card retarget-purple">
                <div>
                    <div class="priority-badge-row">
                        <?php echo cmp_sa_semantic_pill( 'purple', 'AED ' . number_format( $summary['revenue_at_risk'], 0 ) . ' At Risk' ); ?>
                    </div>
                    <div class="priority-title">Unrenewed Expired</div>
                    <div class="priority-val" style="color: #6d28d9;">
                        <span><?php echo number_format( $summary['unrenewed_total_count'] ); ?></span>
                        <span style="font-size: 0.4em; color: #64748b; font-weight: 700; white-space: nowrap;">Churned</span>
                    </div>
                </div>
                <div class="priority-foot">
                    <span class="priority-foot-meta">Completed plans &amp; 0 active</span>
                    <span class="priority-foot-cta" style="color:#6d28d9;">Open Retargeting List &rarr;</span>
                </div>
            </a>
        </div>

        <!-- =========================================================
             MAIN BENTO GRID
             ========================================================= -->
        <div class="acru-bento-grid">

            <!-- Card 1: Net Financial Turnover with Separate Date Filter Pills, PP Delta & SVG Sparkline -->
            <div class="acru-card col-span-7" style="background: linear-gradient(135deg, #1B5E20 0%, #379237 100%); color: #fff; border-color: #1B5E20;">
                <div>
                    <!-- Dedicated Finance Date Filter Pills -->
                    <div class="hero-fin-filter-bar">
                        <div class="hero-fin-pills">
                            <span style="font-size:0.7em; font-weight:800; color:#DCECC9; text-transform:uppercase; margin-right:3px;">Finance:</span>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'fin_start' => $preset_this_month, 'fin_end' => $today_date ) ) ) ); ?>" class="hero-fin-pill <?php echo $fin_preset === 'this_month' ? 'active' : ''; ?>">This Month</a>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'fin_start' => $preset_last_m_s, 'fin_end' => $preset_last_m_e ) ) ) ); ?>" class="hero-fin-pill <?php echo $fin_preset === 'last_month' ? 'active' : ''; ?>">Last Month</a>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'fin_start' => $preset_30d, 'fin_end' => $today_date ) ) ) ); ?>" class="hero-fin-pill <?php echo $fin_preset === '30d' ? 'active' : ''; ?>">30D</a>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'fin_start' => $preset_90d, 'fin_end' => $today_date ) ) ) ); ?>" class="hero-fin-pill <?php echo $fin_preset === '90d' ? 'active' : ''; ?>">90D</a>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'fin_start' => $preset_ytd, 'fin_end' => $today_date ) ) ) ); ?>" class="hero-fin-pill <?php echo $fin_preset === 'ytd' ? 'active' : ''; ?>">YTD</a>
                        </div>
                        <form method="GET" class="hero-fin-custom-form">
                            <input type="hidden" name="start_date" value="<?php echo esc_attr( $op_start ); ?>">
                            <input type="hidden" name="end_date" value="<?php echo esc_attr( $op_end ); ?>">
                            <input type="date" name="fin_start" value="<?php echo esc_attr( $fin_start ); ?>">
                            <input type="date" name="fin_end" value="<?php echo esc_attr( $fin_end ); ?>">
                            <button type="submit">Go</button>
                        </form>
                    </div>

                    <div class="card-label" style="color: #DCECC9;">
                        <span>Period Net Financial Turnover (<?php echo esc_html( date( 'd M Y', strtotime( $fin_start ) ) . ' – ' . date( 'd M Y', strtotime( $fin_end ) ) ); ?>)</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" style="color:#EEF7E3; font-size:0.95em; font-weight:700; text-decoration:underline; white-space:nowrap;">Sales Breakdown &rarr;</a>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:12px; margin-top: 2px;">
                        <div class="card-value" style="color: #fff; font-size: 2.55em; white-space: nowrap;">AED <?php echo number_format( $summary['net_turnover'], 2 ); ?></div>
                        
                        <?php
                        $delta_val   = $summary['fin_delta_pct'];
                        $is_positive = ( $delta_val >= 0 );
                        $delta_arrow = $is_positive ? '▲ +' : '▼ ';
                        $delta_bg    = $is_positive ? 'rgba(220, 252, 231, 0.22)' : 'rgba(254, 226, 226, 0.25)';
                        $delta_col   = $is_positive ? '#bbf7d0' : '#fecaca';
                        ?>
                        <div style="background: <?php echo $delta_bg; ?>; color: <?php echo $delta_col; ?>; border: 1px solid rgba(255,255,255,0.28); padding: 4px 10px; border-radius: 999px; font-size: 0.8em; font-weight: 800; white-space: nowrap;" title="Compared against Previous Period (<?php echo esc_attr( $summary['pp_start'] . ' to ' . $summary['pp_end'] ); ?>: AED <?php echo number_format( $summary['pp_net_turnover'], 2 ); ?>)">
                            <?php echo esc_html( $delta_arrow . $delta_val . '% vs prev period' ); ?>
                        </div>
                    </div>

                    <?php echo cmp_sa_render_sparkline_svg( $summary['spark_map'], $fin_start, $fin_end ); ?>
                    
                    <div class="hero-fin-strip">
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" class="hero-stat-link">
                            <span style="font-size: 0.74em; color: #DCECC9; display: block;">New Plans Sold</span>
                            <span class="hero-stat-val"><?php echo number_format( $summary['period_new_plans'] ); ?> &rarr;</span>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" class="hero-stat-link">
                            <span style="font-size: 0.74em; color: #DCECC9; display: block;">Gross Value</span>
                            <span class="hero-stat-val">AED <?php echo number_format( $summary['gross_turnover'], 2 ); ?></span>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" class="hero-stat-link">
                            <span style="font-size: 0.74em; color: #DCECC9; display: block;">Discounts Given</span>
                            <span class="hero-stat-val" style="color: #fde68a;">AED <?php echo number_format( $summary['total_discounts'], 2 ); ?></span>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" class="hero-stat-link">
                            <span style="font-size: 0.74em; color: #DCECC9; display: block;">Avg Plan Value</span>
                            <span class="hero-stat-val">AED <?php echo number_format( $summary['avg_plan_value'], 2 ); ?></span>
                        </a>
                    </div>
                </div>

                <div class="hero-health-strip">
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'active_plans' ) ) ) ); ?>" class="hero-stat-link">
                        <span style="font-size: 0.76em; color: #DCECC9; display: block;">Live Active Plans</span>
                        <strong style="font-size: 1.3em; color: #fff; white-space: nowrap;"><?php echo number_format( $summary['live_active_count'] ); ?> &rarr;</strong>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'paused_plans' ) ) ) ); ?>" class="hero-stat-link" style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 10px;">
                        <span style="font-size: 0.76em; color: #DCECC9; display: block;">Live Paused</span>
                        <strong style="font-size: 1.3em; color: #fde68a; white-space: nowrap;"><?php echo number_format( $summary['live_paused_count'] ); ?> &rarr;</strong>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'expiring_soon' ) ) ) ); ?>" class="hero-stat-link" style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 10px;">
                        <span style="font-size: 0.76em; color: #DCECC9; display: block;">Expiring (&le;3d)</span>
                        <strong style="font-size: 1.3em; color: #fed7aa; white-space: nowrap;"><?php echo number_format( $summary['expiring_soon_count'] ); ?> &rarr;</strong>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'unrenewed_expired' ) ) ) ); ?>" class="hero-stat-link" style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 10px;">
                        <span style="font-size: 0.76em; color: #DCECC9; display: block;">Unrenewed Pool</span>
                        <strong style="font-size: 1.3em; color: #fecaca; white-space: nowrap;"><?php echo number_format( $summary['unrenewed_total_count'] ); ?> &rarr;</strong>
                    </a>
                </div>
            </div>

            <!-- Card 2: 4-Portal Quick Launch (Strictly Required Portals) -->
            <div class="acru-card col-span-5">
                <div>
                    <div class="card-label">
                        <span>Quick Launch Portals</span>
                        <span style="color: #379237;">4 Workstations</span>
                    </div>
                    <div class="quick-launch-grid">
                        <a href="<?php echo esc_url( site_url( '/foh-admin-portal/' ) ); ?>" target="_blank" class="ql-btn">
                            <span>FOH Command</span>
                            <span>&nearr;</span>
                        </a>
                        <a href="<?php echo esc_url( site_url( '/kitchen-command-center/' ) ); ?>" target="_blank" class="ql-btn">
                            <span>Kitchen Report</span>
                            <span>&nearr;</span>
                        </a>
                        <a href="<?php echo esc_url( site_url( '/menu-manager-portal/' ) ); ?>" target="_blank" class="ql-btn">
                            <span>Menu Manager</span>
                            <span>&nearr;</span>
                        </a>
                        <a href="<?php echo esc_url( site_url( '/admin-tools/' ) ); ?>" target="_blank" class="ql-btn">
                            <span>Admin Tools</span>
                            <span>&nearr;</span>
                        </a>
                    </div>
                </div>
                <div class="card-subtext" style="margin-top: 15px;">Direct one-click access to core operational workstations.</div>
            </div>

            <!-- Card 3: Operational Pulse (Period Prep + Live Today Pulse) -->
            <div class="acru-card col-span-4">
                <div>
                    <div class="card-label">
                        <span>Operational Pulse</span>
                        <span style="color: #379237;"><?php echo $summary['prep_percentage']; ?>% Prepped</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px;">
                        <span class="card-value"><?php echo number_format( $summary['done_preps'] ); ?> <small style="font-size: 0.5em; color: #64748b;">/ <?php echo number_format( $summary['total_preps'] ); ?></small></span>
                        <span style="font-weight: 700; color: #64748b; font-size: 0.85em;">Period Preps</span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar-fill" style="width: <?php echo $summary['prep_percentage']; ?>%;"></div>
                    </div>
                    <div class="card-subtext">Period Dispatch Load: <strong><?php echo number_format( $summary['dispatch_load'] ); ?> meals</strong></div>
                </div>

                <div style="margin-top: 14px; font-size: 0.86em; background: #EEF7E3; border: 1px solid #DCECC9; padding: 10px 12px; border-radius: 10px; color: #1B5E20;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span><strong>Today's Prep</strong> (For Tomorrow):</span>
                        <strong><?php echo number_format( $summary['today_done_preps'] ); ?> / <?php echo number_format( $summary['today_total_preps'] ); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span><strong>Today's Dispatch Load:</strong></span>
                        <strong><?php echo number_format( $summary['today_dispatch'] ); ?> meals</strong>
                    </div>
                </div>
            </div>

            <!-- Card 4: Delivery Analytics & Status Breakdown -->
            <div class="acru-card col-span-4">
                <div>
                    <div class="card-label">
                        <span>Logistics &amp; Delivery Status</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'delivery_logs' ) ) ) ); ?>" style="color: #379237; text-decoration: underline;">View All &rarr;</a>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px;">
                        <span class="card-value"><?php echo $summary['success_rate']; ?>%</span>
                        <span style="font-size: 0.85em; font-weight: 700; color: #379237;">Success Rate</span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar-fill" style="width: <?php echo $summary['success_rate']; ?>%;"></div>
                    </div>
                </div>
                <div style="margin-top: 12px;">
                    <div class="metric-row">
                        <span style="font-size: 0.9em; color: #475569;">Successful (POS Verified)</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'delivery_logs', 'del_filter' => 'Successful' ) ) ) ); ?>" class="metric-badge-link" style="background:#dcfce7; color:#166534;">
                            <?php echo number_format( $summary['successful_deliveries'] ); ?> &rarr;
                        </a>
                    </div>
                    <div class="metric-row">
                        <span style="font-size: 0.9em; color: #475569;">Returns / Cancelled</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'delivery_logs', 'del_filter' => 'Failed' ) ) ) ); ?>" class="metric-badge-link" style="background:#fee2e2; color:#dc2626;">
                            <?php echo number_format( $summary['failed_deliveries'] ); ?> &rarr;
                        </a>
                    </div>
                    <div class="metric-row">
                        <span style="font-size: 0.9em; color: #475569;">Unlogged / Pending Status</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'pending_delivery' ) ) ) ); ?>" class="metric-badge-link" style="background:#fef3c7; color:#b45309;">
                            <?php echo number_format( $summary['pending_delivery_count'] ); ?> &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Card 5: Non-Repeating Kitchen, Renewal & Personalization Bottlenecks -->
            <div class="acru-card col-span-4">
                <div>
                    <div class="card-label">
                        <span>Kitchen &amp; Renewal Bottlenecks</span>
                        <span>Actionable</span>
                    </div>
                    <div style="margin-top: 8px;">
                        <div class="metric-row">
                            <div>
                                <span style="font-size: 0.9em; color: #231F20; font-weight: 700; display: block;">Pending Chef Assignments</span>
                                <?php if ( $summary['urgent_chef_count'] > 0 ) : ?>
                                    <span style="font-size: 0.76em; color: #dc2626; font-weight: 800;"><?php echo number_format( $summary['urgent_chef_count'] ); ?> Urgent (&le;48h eating date)</span>
                                <?php else : ?>
                                    <span style="font-size: 0.76em; color: #64748b;">Upcoming Chef's Choice days</span>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'pending_chef' ) ) ) ); ?>" class="metric-badge-link" style="background: <?php echo ( $summary['pending_chef_count'] > 0 ) ? '#fee2e2' : '#dcfce7'; ?>; color: <?php echo ( $summary['pending_chef_count'] > 0 ) ? '#dc2626' : '#166534'; ?>;">
                                <?php echo number_format( $summary['pending_chef_count'] ); ?> &rarr;
                            </a>
                        </div>

                        <div class="metric-row">
                            <div>
                                <span style="font-size: 0.9em; color: #231F20; font-weight: 700; display: block;">Renewal Radar (&le;3 Days Left)</span>
                                <span style="font-size: 0.76em; color: #64748b;">Active plans nearing completion</span>
                            </div>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'sa_view' => 'expiring_soon' ) ) ) ); ?>" class="metric-badge-link" style="background: <?php echo ( $summary['expiring_soon_count'] > 0 ) ? '#ffedd5' : '#f1f5f9'; ?>; color: <?php echo ( $summary['expiring_soon_count'] > 0 ) ? '#c2410c' : '#64748b'; ?>;">
                                <?php echo number_format( $summary['expiring_soon_count'] ); ?> &rarr;
                            </a>
                        </div>

                        <div class="metric-row" style="padding-top: 10px;">
                            <span style="font-size: 0.85em; color: #475569; font-weight: 700;">Active Plan Modes:</span>
                            <div style="display:flex; gap:5px; flex-wrap:wrap; justify-content:flex-end;">
                                <span style="background:#f1f5f9; color:#334155; font-size:0.75em; font-weight:800; padding:3px 8px; border-radius:6px;">Std: <?php echo number_format( $summary['active_std_count'] ); ?></span>
                                <span style="background:#EEF7E3; color:#1B5E20; font-size:0.75em; font-weight:800; padding:3px 8px; border-radius:6px;">Flex: <?php echo number_format( $summary['active_flex_count'] ); ?></span>
                                <span style="background:#ede9fe; color:#6d28d9; font-size:0.75em; font-weight:800; padding:3px 8px; border-radius:6px;">Custom: <?php echo number_format( $summary['active_custom_count'] ); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-subtext">Prioritized by urgency and subscription lifecycle.</div>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ==========================================
// 8. DEDICATED DRILL-DOWN SCREEN (PHASE 3.3 EMPTY STATES + PHASE 4 PAGINATION)
// ==========================================
function cmp_sa_render_drilldown_screen( $sa_view, $summary, $op_start, $op_end, $fin_start, $fin_end ) {
    $search   = isset( $_GET['sa_search'] ) ? sanitize_text_field( wp_unslash( $_GET['sa_search'] ) ) : '';
    $del_filt = isset( $_GET['del_filter'] ) ? sanitize_text_field( $_GET['del_filter'] ) : 'all';
    $paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $per_page = 50;

    $back_url   = remove_query_arg( array( 'sa_view', 'del_filter', 'sa_search', 'paged' ) );
    $export_url = add_query_arg( array(
        'action'     => 'cmp_sa_export_drilldown',
        'sa_view'    => $sa_view,
        'start_date' => $op_start,
        'end_date'   => $op_end,
        'fin_start'  => $fin_start,
        'fin_end'    => $fin_end,
        'sa_search'  => $search,
        'del_filter' => $del_filt,
    ), admin_url( 'admin-ajax.php' ) );

    $result      = cmp_sa_query_drilldown_records( $sa_view, $op_start, $op_end, $fin_start, $fin_end, $search, $del_filt, $paged, $per_page );
    $rows        = $result['rows'];
    $total_rows  = $result['total_rows'];
    $total_pages = $result['total_pages'];

    $titles = array(
        'pending_pos'       => array( 'Pending POS Reconciliations', 'Delivered or voided meals waiting for FOH POS verification (Overdue >48h elevated to top)', '#d97706' ),
        'completed_pos'     => array( 'Completed POS Reconciliations', 'Orders verified and reconciled in POS for the selected period', '#379237' ),
        'pending_delivery'  => array( 'Pending Delivery Status Logs', 'Locked meals (up to today) where delivery status is still Pending (Overdue >48h elevated to top)', '#dc2626' ),
        'delivery_logs'     => array( 'Delivery Status Breakdown', 'Complete log of Successful, Cancelled, Returned, and Pending deliveries', '#1B5E20' ),
        'unrenewed_expired' => array( 'Unrenewed Expired Customers — AED ' . number_format( $summary['revenue_at_risk'], 2 ) . ' Revenue at Risk', 'Real customers who consumed meals on a past plan, finished/expired, and have NOT subscribed to a new active plan', '#6d28d9' ),
        'expiring_soon'     => array( 'Renewal Radar: Plans Expiring Soon (<= 3 Balance Days)', 'Active customers with 1 to 3 days remaining — prime candidates for renewal outreach', '#c2410c' ),
        'active_plans'      => array( 'Live Active Subscriptions', 'All currently active customer meal plans across the system', '#379237' ),
        'paused_plans'      => array( 'Live Paused Subscriptions', 'All currently paused customer meal plans', '#f59e0b' ),
        'period_sales'      => array( 'Period Financial Turnover & New Plans Sold', 'All subscriptions acquired between ' . date( 'd M Y', strtotime( $fin_start ) ) . ' and ' . date( 'd M Y', strtotime( $fin_end ) ), '#1B5E20' ),
        'pending_chef'      => array( "Pending Chef's Choice Assignments", 'Active Chef Choice days waiting for meal selection (Sorted chronologically by urgency)', '#dc2626' ),
    );

    $meta = isset( $titles[ $sa_view ] ) ? $titles[ $sa_view ] : $titles['unrenewed_expired'];

    $empty_states = array(
        'pending_pos'       => array( 'title' => 'All caught up! 100% of POS checks are reconciled.', 'sub' => 'Every delivered and voided meal in this period has been verified by FOH.', 'tone' => 'success' ),
        'pending_delivery'  => array( 'title' => 'Zero unlogged deliveries! Kitchen logs are 100% up to date.', 'sub' => 'All locked meals up to today have a confirmed delivery outcome.', 'tone' => 'success' ),
        'unrenewed_expired' => array( 'title' => 'Excellent retention! All recent expirations have renewed.', 'sub' => 'There are currently zero unrenewed expired customers in the database.', 'tone' => 'success' ),
        'pending_chef'      => array( 'title' => "Chef's Desk is 100% prepped!", 'sub' => 'All active Chef Choice days with released menus have been assigned.', 'tone' => 'success' ),
        'expiring_soon'     => array( 'title' => 'No active plans are within 3 days of expiring.', 'sub' => 'All active subscribers currently have 4 or more balance days remaining.', 'tone' => 'neutral' ),
    );
    $empty_cfg = isset( $empty_states[ $sa_view ] ) ? $empty_states[ $sa_view ] : array(
        'title' => 'No matching records found for this filter.',
        'sub'   => 'Try adjusting your date range or clearing your search query.',
        'tone'  => 'neutral',
    );

    $sa_nonce = wp_create_nonce( 'cmp_sa_exec_nonce' );
    $ajax_url = admin_url( 'admin-ajax.php' );

    ob_start();
    ?>
    <style>
        .sa-drill-wrap, .sa-drill-wrap * { box-sizing: border-box; }
        .sa-drill-wrap svg.acru-icon { width: 14px !important; height: 14px !important; max-width: 16px !important; max-height: 16px !important; display: inline-block !important; vertical-align: -2px !important; }
        .sa-drill-wrap {
            max-width: 1350px;
            margin: 0 auto 40px auto;
            padding: 24px;
            background: linear-gradient(145deg, rgba(244, 248, 242, 0.94) 0%, rgba(238, 247, 227, 0.65) 100%);
            border-radius: 22px;
            border: 1px solid #DCECC9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #231F20;
        }
        .sa-drill-top { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; }
        .sa-back-btn { display: inline-flex; align-items: center; gap: 6px; background: #ffffff; color: #1B5E20 !important; border: 1.5px solid #379237; padding: 9px 16px; border-radius: 10px; font-weight: 800; text-decoration: none !important; font-size: 0.92em; transition: 0.2s; }
        .sa-back-btn:hover { background: #379237; color: #ffffff !important; }
        .sa-drill-header { background: #ffffff; border-radius: 16px; padding: 22px 26px; border: 1px solid #DCECC9; border-left: 6px solid <?php echo esc_attr( $meta[2] ); ?>; box-shadow: 0 6px 18px rgba(27, 94, 32, 0.05); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .sa-drill-header h2 { margin: 0; font-size: 1.5em; color: #231F20; font-weight: 800; }
        .sa-drill-header p { margin: 5px 0 0 0; color: #64748b; font-size: 0.93em; }
        .sa-drill-toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; background: #ffffff; padding: 14px 18px; border-radius: 12px; border: 1px solid #DCECC9; }
        .sa-drill-search-form { display: flex; flex: 1; min-width: 260px; gap: 8px; margin: 0; }
        .sa-drill-search { flex: 1; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 0.93em; outline: none; }
        .sa-drill-search:focus { border-color: #379237; }
        .sa-drill-table { width: 100%; border-collapse: collapse; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(27, 94, 32, 0.05); font-size: 0.91em; }
        .sa-drill-table th { background: #EEF7E3; color: #1B5E20; padding: 13px 12px; text-align: left; border-bottom: 2px solid #DCECC9; font-weight: 800; }
        .sa-drill-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .sa-drill-table tr:hover td { background: #f8fcf5; }
        .sa-pill { padding: 4px 10px; border-radius: 999px; font-weight: 800; font-size: 0.78em; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap !important; }
        .sa-pagination-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 18px; padding: 14px 18px; background: #ffffff; border-radius: 12px; border: 1px solid #DCECC9; }
        .sa-page-btn { padding: 6px 12px; border-radius: 7px; border: 1px solid #cbd5e1; background: #ffffff; color: #231F20 !important; text-decoration: none !important; font-weight: 700; font-size: 0.88em; }
        .sa-page-btn.active { background: #379237; color: #ffffff !important; border-color: #379237; }
        .sa-empty-box { text-align: center; padding: 48px 24px; background: #ffffff; border-radius: 14px; border: 1.5px dashed #b7e1a1; margin-top: 8px; }
    </style>

    <div class="sa-drill-wrap">
        <div class="sa-drill-top">
            <a href="<?php echo esc_url( $back_url ); ?>" class="sa-back-btn">&larr; Back to Executive Command Center</a>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <span style="font-size:0.88em; font-weight:700; color:#475569; background:#fff; padding:8px 14px; border-radius:8px; border:1px solid #DCECC9;">
                    Showing <strong><?php echo count( $rows ); ?></strong> of <strong><?php echo number_format( $total_rows ); ?></strong> records
                </span>
                <a href="<?php echo esc_url( $export_url ); ?>" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color:#fff; text-decoration:none; padding:9px 18px; border-radius:10px; font-weight:700; font-size:0.9em;">Export Full CSV</a>
                <button onclick="window.print()" style="background:#475569; color:#fff; border:none; padding:9px 16px; border-radius:10px; font-weight:700; font-size:0.9em; cursor:pointer;">Print / PDF</button>
            </div>
        </div>

        <div class="sa-drill-header">
            <div>
                <h2><?php echo esc_html( $meta[0] ); ?></h2>
                <p><?php echo esc_html( $meta[1] ); ?></p>
            </div>
            <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="sa_view" value="<?php echo esc_attr( $sa_view ); ?>">
                <input type="hidden" name="sa_search" value="<?php echo esc_attr( $search ); ?>">
                <?php if ( $sa_view === 'period_sales' ) : ?>
                    <input type="hidden" name="start_date" value="<?php echo esc_attr( $op_start ); ?>">
                    <input type="hidden" name="end_date" value="<?php echo esc_attr( $op_end ); ?>">
                    <input type="date" name="fin_start" value="<?php echo esc_attr( $fin_start ); ?>" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                    <span style="color:#64748b; font-weight:700;">to</span>
                    <input type="date" name="fin_end" value="<?php echo esc_attr( $fin_end ); ?>" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                <?php else : ?>
                    <input type="hidden" name="fin_start" value="<?php echo esc_attr( $fin_start ); ?>">
                    <input type="hidden" name="fin_end" value="<?php echo esc_attr( $fin_end ); ?>">
                    <input type="date" name="start_date" value="<?php echo esc_attr( $op_start ); ?>" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                    <span style="color:#64748b; font-weight:700;">to</span>
                    <input type="date" name="end_date" value="<?php echo esc_attr( $op_end ); ?>" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                <?php endif; ?>
                <button type="submit" style="background:#379237; color:#fff; border:none; padding:8px 15px; border-radius:8px; font-weight:700; cursor:pointer;">Update Period</button>
            </form>
        </div>

        <div class="sa-drill-toolbar">
            <form method="GET" class="sa-drill-search-form">
                <input type="hidden" name="sa_view" value="<?php echo esc_attr( $sa_view ); ?>">
                <input type="hidden" name="start_date" value="<?php echo esc_attr( $op_start ); ?>">
                <input type="hidden" name="end_date" value="<?php echo esc_attr( $op_end ); ?>">
                <input type="hidden" name="fin_start" value="<?php echo esc_attr( $fin_start ); ?>">
                <input type="hidden" name="fin_end" value="<?php echo esc_attr( $fin_end ); ?>">
                <input type="hidden" name="del_filter" value="<?php echo esc_attr( $del_filt ); ?>">
                <input type="text" name="sa_search" value="<?php echo esc_attr( $search ); ?>" class="sa-drill-search" placeholder="Server Search by Order ID, Customer Name, Email, Phone, or Plan...">
                <button type="submit" style="background:#1B5E20; color:#fff; border:none; padding:10px 18px; border-radius:8px; font-weight:700; cursor:pointer;">Search DB</button>
                <?php if ( ! empty( $search ) ) : ?>
                    <a href="<?php echo esc_url( remove_query_arg( array( 'sa_search', 'paged' ) ) ); ?>" style="background:#fee2e2; color:#991b1b; text-decoration:none; padding:10px 14px; border-radius:8px; font-weight:700; font-size:0.9em;">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ( $sa_view === 'delivery_logs' ) : ?>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <a href="<?php echo esc_url( add_query_arg( array( 'del_filter' => 'all', 'paged' => 1 ) ) ); ?>" class="sa-pill" style="text-decoration:none; background:<?php echo $del_filt === 'all' ? '#1B5E20' : '#f1f5f9'; ?>; color:<?php echo $del_filt === 'all' ? '#fff' : '#475569'; ?>;">All Logs</a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'del_filter' => 'Successful', 'paged' => 1 ) ) ); ?>" class="sa-pill" style="text-decoration:none; background:<?php echo $del_filt === 'Successful' ? '#166534' : '#dcfce7'; ?>; color:<?php echo $del_filt === 'Successful' ? '#fff' : '#166534'; ?>;">Successful</a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'del_filter' => 'Failed', 'paged' => 1 ) ) ); ?>" class="sa-pill" style="text-decoration:none; background:<?php echo $del_filt === 'Failed' ? '#dc2626' : '#fee2e2'; ?>; color:<?php echo $del_filt === 'Failed' ? '#fff' : '#dc2626'; ?>;">Cancelled / Returned</a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'del_filter' => 'Pending', 'paged' => 1 ) ) ); ?>" class="sa-pill" style="text-decoration:none; background:<?php echo $del_filt === 'Pending' ? '#d97706' : '#fef3c7'; ?>; color:<?php echo $del_filt === 'Pending' ? '#fff' : '#92400e'; ?>;">Pending</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <div class="sa-empty-box">
                <div style="width:52px; height:52px; border-radius:50%; background:<?php echo $empty_cfg['tone'] === 'success' ? '#dcfce7' : '#f1f5f9'; ?>; color:<?php echo $empty_cfg['tone'] === 'success' ? '#166534' : '#64748b'; ?>; display:inline-flex; align-items:center; justify-content:center; margin-bottom:14px;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:26px !important; height:26px !important;">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <h3 style="margin:0 0 6px 0; color:#1B5E20; font-size:1.3em;"><?php echo esc_html( ! empty( $search ) ? 'No records match "' . $search . '"' : $empty_cfg['title'] ); ?></h3>
                <p style="margin:0; color:#64748b; font-size:0.95em;"><?php echo esc_html( ! empty( $search ) ? 'Try clearing your search query or searching by another keyword.' : $empty_cfg['sub'] ); ?></p>
            </div>
        <?php else : ?>
            <div style="overflow-x:auto;">
                <?php if ( $sa_view === 'unrenewed_expired' ) : ?>
                    <!-- TABLE A: UNRENEWED EXPIRED CUSTOMERS (RETARGETING LIST) -->
                    <table class="sa-drill-table">
                        <thead>
                            <tr>
                                <th>Last Order</th>
                                <th>Customer Details</th>
                                <th>Last Plan Subscribed</th>
                                <th>Consumption</th>
                                <th>Last Plan Paid</th>
                                <th>Completed / Expired</th>
                                <th style="text-align:center;">Retarget &amp; Manage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $rows as $c ) : 
                                $clean_phone = preg_replace( '/[^0-9]/', '', $c['phone'] );
                                if ( substr( $clean_phone, 0, 1 ) === '0' ) {
                                    $clean_phone = '971' . substr( $clean_phone, 1 );
                                }
                                $wa_msg = urlencode( "Hi " . $c['name'] . ", we noticed your meal plan (" . $c['plan_name'] . ") with The Cycle Bistro has finished. Would you like to renew your plan for the upcoming week?" );
                            ?>
                                <tr id="sa-sub-row-<?php echo intval( $c['sub_id'] ); ?>">
                                    <td><strong style="color:#231F20;"><?php echo esc_html( $c['order_id'] ); ?></strong></td>
                                    <td>
                                        <strong style="color:#379237; font-size:1.05em;"><?php echo esc_html( $c['name'] ); ?></strong><br>
                                        <span style="color:#64748b; font-size:0.88em;"><?php echo esc_html( $c['email'] ); ?></span><br>
                                        <span style="color:#334155; font-size:0.88em; font-weight:600;"><?php echo esc_html( $c['phone'] ); ?></span>
                                    </td>
                                    <td><strong><?php echo esc_html( $c['plan_name'] ); ?></strong></td>
                                    <td><?php echo cmp_sa_semantic_pill( 'neutral', intval( $c['used_days'] ) . ' / ' . intval( $c['total_days'] ) . ' Days' ); ?></td>
                                    <td><strong style="white-space:nowrap;">AED <?php echo number_format( $c['paid'], 2 ); ?></strong></td>
                                    <td><?php echo cmp_sa_semantic_pill( 'purple', date( 'd M Y', strtotime( $c['expiry_date'] ) ) ); ?></td>
                                    <td style="text-align:center;">
                                        <div style="display:flex; gap:6px; justify-content:center; align-items:center; flex-wrap:wrap;">
                                            <?php if ( ! empty( $clean_phone ) ) : ?>
                                                <a href="https://wa.me/<?php echo esc_attr( $clean_phone ); ?>?text=<?php echo $wa_msg; ?>" target="_blank" style="background:#25d366; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.84em; white-space:nowrap;">WhatsApp</a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url( site_url( '/my-meal-portal/?admin_edit_sub=' . $c['sub_id'] ) ); ?>" target="_blank" style="background:#379237; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.84em; white-space:nowrap;">View Plan &nearr;</a>
                                            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                                                <button type="button" class="sa-delete-sub-btn" data-sub-id="<?php echo intval( $c['sub_id'] ); ?>" data-order-id="<?php echo esc_attr( $c['order_id'] ); ?>" style="background:#fff; color:#dc2626; border:1px solid #fca5a5; padding:6px 9px; border-radius:6px; cursor:pointer; font-weight:700; font-size:0.82em;" title="Permanently Delete Plan">Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php elseif ( in_array( $sa_view, array( 'active_plans', 'paused_plans', 'expiring_soon', 'period_sales' ), true ) ) : ?>
                    <!-- TABLE B: SUBSCRIPTIONS & FINANCIALS DRILL-DOWN -->
                    <table class="sa-drill-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer Info</th>
                                <th>Plan Name</th>
                                <th>Status</th>
                                <th>Progress &amp; Balance</th>
                                <th>Financials (AED)</th>
                                <th>Dates</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $rows as $s ) : 
                                $p_type = $s['status_key'] === 'active' ? 'success' : ( $s['status_key'] === 'paused' ? 'warning' : 'danger' );
                            ?>
                                <tr id="sa-sub-row-<?php echo intval( $s['sub_id'] ); ?>">
                                    <td><strong><?php echo esc_html( $s['order_id'] ); ?></strong></td>
                                    <td>
                                        <strong style="color:#379237;"><?php echo esc_html( $s['name'] ); ?></strong><br>
                                        <span style="color:#64748b; font-size:0.88em;"><?php echo esc_html( $s['email'] ); ?></span><br>
                                        <span style="color:#334155; font-size:0.88em;"><?php echo esc_html( $s['phone'] ); ?></span>
                                    </td>
                                    <td><strong><?php echo esc_html( $s['plan_name'] ); ?></strong></td>
                                    <td><?php echo cmp_sa_semantic_pill( $p_type, $s['status_label'] ); ?></td>
                                    <td>
                                        Used: <strong><?php echo intval( $s['used_days'] ); ?> / <?php echo intval( $s['total_days'] ); ?></strong><br>
                                        <span style="color:<?php echo $s['balance'] <= 3 ? '#dc2626' : '#1B5E20'; ?>; font-weight:800;">Balance: <?php echo intval( $s['balance'] ); ?> Days</span>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        Net: <strong style="color:#1B5E20;">AED <?php echo number_format( $s['paid'], 2 ); ?></strong><br>
                                        <?php if ( $s['discount'] > 0 ) : ?>
                                            <span style="font-size:0.82em; color:#dc2626;">Disc: -AED <?php echo number_format( $s['discount'], 2 ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.88em; color:#475569; white-space:nowrap;">
                                        Start: <?php echo esc_html( date( 'd M Y', strtotime( $s['start_date'] ) ) ); ?><br>
                                        Exp: <?php echo esc_html( date( 'd M Y', strtotime( $s['expiry_date'] ) ) ); ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex; gap:6px; justify-content:center; align-items:center; flex-wrap:wrap;">
                                            <a href="<?php echo esc_url( site_url( '/my-meal-portal/?admin_edit_sub=' . $s['sub_id'] ) ); ?>" target="_blank" style="background:#379237; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.84em; display:inline-block; white-space:nowrap;">Open Plan &nearr;</a>
                                            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                                                <button type="button" class="sa-delete-sub-btn" data-sub-id="<?php echo intval( $s['sub_id'] ); ?>" data-order-id="<?php echo esc_attr( $s['order_id'] ); ?>" style="background:#fff; color:#dc2626; border:1px solid #fca5a5; padding:6px 9px; border-radius:6px; cursor:pointer; font-weight:700; font-size:0.82em;" title="Permanently Delete Plan">Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php else : ?>
                    <!-- TABLE C: DAILY OPERATIONAL, DELIVERY & POS RECONCILIATION LOGS -->
                    <table class="sa-drill-table">
                        <thead>
                            <tr>
                                <th>Eating Date</th>
                                <th>Delivery Date</th>
                                <th>Order ID</th>
                                <th>Customer Details</th>
                                <th>Plan</th>
                                <th style="text-align:center;">Prep / Dispatch</th>
                                <th style="text-align:center;">Delivery Status</th>
                                <th style="text-align:center;">POS Check</th>
                                <th style="text-align:center;">Resolve</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $rows as $l ) : 
                                $prep_date_param = date( 'Y-m-d', strtotime( $l['target_date'] . ' -1 day' ) );
                                $del_type = 'warning';
                                if ( $l['delivery_result'] === 'Successful' ) {
                                    $del_type = 'success';
                                } elseif ( in_array( $l['delivery_result'], array( 'Cancelled', 'Returned' ), true ) ) {
                                    $del_type = 'danger';
                                }
                            ?>
                                <tr style="<?php echo ( $l['is_overdue'] && in_array( $sa_view, array( 'pending_pos', 'pending_delivery' ), true ) ) ? 'background:#fff5f5;' : ''; ?>">
                                    <td style="white-space:nowrap;">
                                        <strong><?php echo esc_html( date( 'd M Y', strtotime( $l['target_date'] ) ) ); ?></strong><br>
                                        <span style="font-size:0.8em; color:#64748b;">(<?php echo esc_html( $l['timing_label'] ); ?>)</span>
                                        <?php if ( $l['is_overdue'] && in_array( $sa_view, array( 'pending_pos', 'pending_delivery' ), true ) ) : ?>
                                            <div style="margin-top:4px;"><?php echo cmp_sa_semantic_pill( 'danger', 'CRITICAL OVERDUE (' . $l['age_days'] . 'd)' ); ?></div>
                                        <?php elseif ( $sa_view === 'pending_chef' && $l['age_days'] >= -2 ) : ?>
                                            <div style="margin-top:4px;"><?php echo cmp_sa_semantic_pill( 'danger', 'URGENT (<48h)' ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:700; color:#1B5E20; white-space:nowrap;"><?php echo esc_html( date( 'd M Y', strtotime( $l['delivery_date'] ) ) ); ?></td>
                                    <td><strong><?php echo esc_html( $l['order_id'] ); ?></strong></td>
                                    <td>
                                        <strong style="color:#379237;"><?php echo esc_html( $l['name'] ); ?></strong><br>
                                        <span style="color:#64748b; font-size:0.88em;"><?php echo esc_html( $l['phone'] ); ?></span>
                                    </td>
                                    <td><?php echo esc_html( $l['plan_name'] ); ?></td>
                                    <td style="text-align:center; white-space:nowrap;">
                                        <?php echo cmp_sa_semantic_pill( $l['is_prepared'] ? 'success' : 'danger', 'Prep: ' . ( $l['is_prepared'] ? 'Yes' : 'No' ) ); ?>
                                        <?php echo cmp_sa_semantic_pill( $l['dispatch_status'] ? 'success' : 'danger', 'Disp: ' . ( $l['dispatch_status'] ? 'Yes' : 'No' ) ); ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php echo cmp_sa_semantic_pill( $del_type, $l['delivery_result'] ); ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php echo cmp_sa_semantic_pill( $l['pos_updated'] ? 'success' : 'warning', $l['pos_updated'] ? 'Verified' : 'Pending' ); ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ( $sa_view === 'pending_chef' ) : ?>
                                            <a href="<?php echo esc_url( site_url( '/my-meal-portal/?chef_override_sub=' . $l['sub_id'] ) ); ?>" target="_blank" style="background:#379237; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.84em; display:inline-block; white-space:nowrap;">Assign Meals &nearr;</a>
                                        <?php else : ?>
                                            <a href="<?php echo esc_url( site_url( '/kitchen-command-center/?prep_date=' . $prep_date_param ) ); ?>" target="_blank" style="background:#1B5E20; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.84em; display:inline-block; white-space:nowrap;">Open Prep Date &nearr;</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Step 4.1: Server-Side Pagination Strip -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="sa-pagination-bar">
                    <span style="font-size:0.88em; color:#64748b; font-weight:600;">
                        Page <strong><?php echo intval( $paged ); ?></strong> of <strong><?php echo intval( $total_pages ); ?></strong> (<?php echo number_format( $total_rows ); ?> total records)
                    </span>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        <?php if ( $paged > 1 ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>" class="sa-page-btn">&laquo; Prev</a>
                        <?php endif; ?>
                        <?php
                        $start_p = max( 1, $paged - 2 );
                        $end_p   = min( $total_pages, $paged + 2 );
                        for ( $p = $start_p; $p <= $end_p; $p++ ) :
                        ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>" class="sa-page-btn <?php echo $p === $paged ? 'active' : ''; ?>"><?php echo intval( $p ); ?></a>
                        <?php endfor; ?>
                        <?php if ( $paged < $total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>" class="sa-page-btn">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const saAjaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        const saNonce   = '<?php echo esc_js( $sa_nonce ); ?>';

        document.querySelectorAll('.sa-delete-sub-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const subId   = this.getAttribute('data-sub-id');
                const orderId = this.getAttribute('data-order-id');
                if (!confirm('Are you sure you want to permanently delete Plan (' + orderId + ')?\n\nThis will remove the subscription and its meal logs from the database.')) {
                    return;
                }
                const origText = this.textContent;
                this.textContent = '...';
                this.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_sa_delete_subscription');
                formData.append('nonce', saNonce);
                formData.append('sub_id', subId);

                fetch(saAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if (response && response.success) {
                        const row = document.getElementById('sa-sub-row-' + subId);
                        if (row) {
                            row.style.opacity = '0.3';
                            setTimeout(() => row.remove(), 250);
                        } else {
                            window.location.reload();
                        }
                    } else {
                        alert('Error: ' + ((response && response.data) ? response.data : 'Could not delete plan.'));
                        this.textContent = origText;
                        this.disabled = false;
                    }
                })
                .catch(() => {
                    alert('Network error occurred.');
                    this.textContent = origText;
                    this.disabled = false;
                });
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
