<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// UNIFIED SUPER ADMIN COMMAND CENTER (BENTO UI)
// ==========================================
add_shortcode( 'meal_super_admin', 'cmp_render_super_admin_portal' );

function cmp_render_super_admin_portal() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    date_default_timezone_set('Asia/Dubai');

    $has_exec_access = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' );

    // 1. SECURITY & CUSTOM GLASSMORPHISM UI/UX LOGIN SCREEN
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

    // Grab current user
    $current_user = wp_get_current_user();
    $first_name   = ! empty( $current_user->user_firstname ) ? $current_user->user_firstname : $current_user->display_name;

    // ==========================================
    // DATA GATHERING & ANALYTICS CALCULATION
    // ==========================================
    global $wpdb;
    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods = $wpdb->prefix . 'cmp_foods';
    
    $today_time    = date('Y-m-d H:i:s');
    $today_date    = date('Y-m-d');
    $tomorrow_date = date('Y-m-d', strtotime('+1 day'));

    // --- UNIFIED GLOBAL DATE FILTER & PRESETS ---
    $preset_7d    = date('Y-m-d', strtotime('-6 days'));
    $preset_30d   = date('Y-m-d', strtotime('-29 days'));
    $preset_month = date('Y-m-01');
    $preset_90d   = date('Y-m-d', strtotime('-89 days'));

    $global_start = isset($_GET['start_date']) && !empty($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : $preset_30d;
    $global_end   = isset($_GET['end_date']) && !empty($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : $today_date;

    if ( $global_start > $global_end ) {
        $temp         = $global_start;
        $global_start = $global_end;
        $global_end   = $temp;
    }

    // Determine active preset pill
    $active_preset = 'custom';
    if ( $global_end === $today_date ) {
        if ( $global_start === $today_date )        { $active_preset = 'today'; }
        elseif ( $global_start === $preset_7d )     { $active_preset = '7d'; }
        elseif ( $global_start === $preset_30d )    { $active_preset = '30d'; }
        elseif ( $global_start === $preset_month )  { $active_preset = 'month'; }
        elseif ( $global_start === $preset_90d )    { $active_preset = '90d'; }
    }

    // --- 1. SINGLE PRE-AGGREGATED USAGE QUERY (ELIMINATES N+1 QUERIES) ---
    $usage_rows = $wpdb->get_results(
        "SELECT subscription_id, COUNT(id) AS used_cnt 
         FROM $table_logs 
         WHERE delivery_result = 'Successful' 
         GROUP BY subscription_id"
    );
    $usage_map = array();
    if ( $usage_rows ) {
        foreach ( $usage_rows as $ur ) {
            $usage_map[ intval( $ur->subscription_id ) ] = intval( $ur->used_cnt );
        }
    }

    // --- 2. GLOBAL SUBSCRIPTION HEALTH & PERIOD FINANCIALS ---
    $all_subs_global = $wpdb->get_results(
        "SELECT id, user_id, wc_order_id, total_days, start_date, expiry_date, status, plan_price, discount_amount 
         FROM $table_subs 
         WHERE status != 'pending'"
    );

    $live_active_count   = 0;
    $live_paused_count   = 0;
    $renewal_radar_count = 0; // Active plans with <= 3 balance days remaining

    $period_new_plans    = 0;
    $gross_turnover      = 0.0;
    $total_discounts     = 0.0;
    $new_turnover        = 0.0;

    $user_global_status  = array();
    $period_user_ids     = array();

    foreach ( $all_subs_global as $sub ) {
        $uid        = intval( $sub->user_id );
        $sub_id     = intval( $sub->id );
        $used_days  = isset( $usage_map[ $sub_id ] ) ? $usage_map[ $sub_id ] : 0;
        $total_days = intval( $sub->total_days );
        $balance    = max( 0, $total_days - $used_days );

        if ( ! isset( $user_global_status[ $uid ] ) ) {
            $user_global_status[ $uid ] = array( 'has_active' => false, 'has_completed' => false );
        }

        $is_completed = ( $used_days >= $total_days || $sub->expiry_date < $today_time );

        if ( $sub->status === 'paused' ) {
            $user_global_status[ $uid ]['has_active'] = true;
            $live_paused_count++;
        } elseif ( $is_completed ) {
            $user_global_status[ $uid ]['has_completed'] = true;
        } else {
            $user_global_status[ $uid ]['has_active'] = true;
            $live_active_count++;
            if ( $balance >= 1 && $balance <= 3 ) {
                $renewal_radar_count++;
            }
        }

        // Check if plan originated within the selected filter period
        $sub_start_ymd = substr( $sub->start_date, 0, 10 );
        if ( $sub_start_ymd >= $global_start && $sub_start_ymd <= $global_end ) {
            $period_new_plans++;
            $period_user_ids[ $uid ] = true;

            $price      = isset( $sub->plan_price ) ? floatval( $sub->plan_price ) : 0.0;
            $discount   = isset( $sub->discount_amount ) ? floatval( $sub->discount_amount ) : 0.0;
            $final_paid = max( 0.0, $price - $discount );

            // Legacy Fallback for older WooCommerce orders without DB price
            if ( $final_paid == 0 && $sub->wc_order_id > 0 && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $sub->wc_order_id );
                if ( $order ) {
                    $price      = floatval( $order->get_subtotal() );
                    $discount   = floatval( $order->get_discount_total() );
                    $final_paid = floatval( $order->get_total() );
                }
            }

            $gross_turnover  += $price;
            $total_discounts += $discount;
            $new_turnover    += $final_paid;
        }
    }

    // Calculate True Churn (Customers from the selected period who completed their plan and have no active/paused renewal)
    $true_churn_count = 0;
    foreach ( array_keys( $period_user_ids ) as $uid ) {
        if ( isset( $user_global_status[ $uid ] ) && $user_global_status[ $uid ]['has_completed'] && ! $user_global_status[ $uid ]['has_active'] ) {
            $true_churn_count++;
        }
    }

    $avg_plan_value = ( $period_new_plans > 0 ) ? ( $new_turnover / $period_new_plans ) : 0.0;

    // --- 3. FOH RECONCILIATION (SELECTED PERIOD) ---
    $pending_pos_checks = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.target_date >= %s AND l.target_date <= %s 
         AND l.is_locked = 1 AND l.pos_updated = 0 AND l.delivery_result != 'Pending'",
        $global_start, $global_end
    ) ) );

    $completed_pos_checks = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.target_date >= %s AND l.target_date <= %s 
         AND l.is_locked = 1 AND l.pos_updated = 1",
        $global_start, $global_end
    ) ) );

    // --- 4. OPERATIONAL PULSE (SELECTED PERIOD + LIVE TODAY PULSE) ---
    $prep_target_start = date('Y-m-d', strtotime($global_start . ' +1 day'));
    $prep_target_end   = date('Y-m-d', strtotime($global_end . ' +1 day'));

    // Period Prep Totals (Excludes paused plans and cancelled/returned days)
    $total_preps = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.target_date >= %s AND l.target_date <= %s 
         AND l.is_locked = 1 AND s.status != 'paused'
         AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))",
        $prep_target_start, $prep_target_end
    ) ) );

    $done_preps = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.target_date >= %s AND l.target_date <= %s 
         AND l.is_locked = 1 AND l.is_prepared = 1 AND s.status != 'paused'
         AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))",
        $prep_target_start, $prep_target_end
    ) ) );
    $prep_percentage = ( $total_preps > 0 ) ? round( ( $done_preps / $total_preps ) * 100 ) : 0;

    // Live Today's Prep (Meals to prep today for tomorrow's eating date)
    $today_total_preps = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.target_date = %s AND l.is_locked = 1 AND s.status != 'paused'
         AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))",
        $tomorrow_date
    ) ) );
    $today_done_preps = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.target_date = %s AND l.is_locked = 1 AND l.is_prepared = 1 AND s.status != 'paused'
         AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))",
        $tomorrow_date
    ) ) );

    // Dispatch Load (Period & Today)
    $dispatch_load = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.target_date >= %s AND l.target_date <= %s 
         AND l.is_locked = 1 AND s.status != 'paused'
         AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))",
        $global_start, $global_end
    ) ) );

    $today_dispatch_load = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.target_date = %s AND l.is_locked = 1 AND s.status != 'paused'
         AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))",
        $today_date
    ) ) );

    // --- 5. ACCURATE PENDING CHEF ASSIGNMENTS (SUPPORTS CUSTOM & FLEXIBLE PLANS) ---
    $chef_logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.allow_custom 
         FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         WHERE l.is_chefs_choice = 1 
         AND s.status = 'active'
         AND (l.target_date >= %s OR l.target_date >= %s)
         AND (l.delivery_result IS NULL OR l.delivery_result NOT IN ('Cancelled', 'Returned'))",
        $global_start, $today_date
    ) );
    $active_food_ranges = $wpdb->get_results( "SELECT DISTINCT valid_from, valid_until FROM $table_foods WHERE is_active = 1" );
    $pending_chef_count = 0;
    
    if ( $chef_logs ) {
        foreach ( $chef_logs as $log ) {
            $is_assigned = (
                ! empty( $log->breakfast_id ) || ! empty( $log->lunch_id ) || ! empty( $log->dinner_id ) || ! empty( $log->juice_1_id ) ||
                ! empty( $log->custom_breakfast ) || ! empty( $log->custom_lunch ) || ! empty( $log->custom_dinner ) || ! empty( $log->custom_juice_1 )
            );

            if ( ! $is_assigned ) {
                $row_allow_custom = ( ! empty( $log->plan_mode ) && $log->plan_mode === 'custom' ) || ! empty( $log->allow_custom );
                $menu_exists      = false;

                if ( $row_allow_custom ) {
                    $menu_exists = true;
                } else {
                    foreach ( $active_food_ranges as $range ) {
                        if ( empty( $range->valid_from ) || empty( $range->valid_until ) ) {
                            $menu_exists = true;
                            break;
                        }
                        if ( $log->target_date >= $range->valid_from && $log->target_date <= $range->valid_until ) {
                            $menu_exists = true;
                            break;
                        }
                    }
                }
                if ( $menu_exists ) {
                    $pending_chef_count++;
                }
            }
        }
    }

    // --- 6. DELIVERY ANALYTICS ---
    $successful_deliveries = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(id) FROM $table_logs WHERE delivery_result = 'Successful' AND pos_updated = 1 AND target_date >= %s AND target_date <= %s",
        $global_start, $global_end
    ) ) );
    $failed_deliveries = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(id) FROM $table_logs WHERE delivery_result IN ('Cancelled', 'Returned') AND target_date >= %s AND target_date <= %s",
        $global_start, $global_end
    ) ) );
    $total_deliveries_period = $successful_deliveries + $failed_deliveries;
    $success_rate = ( $total_deliveries_period > 0 ) ? round( ( $successful_deliveries / $total_deliveries_period ) * 100, 1 ) : 0;

    $formatted_turnover  = function_exists('wc_price') ? wc_price($new_turnover) : 'AED ' . number_format($new_turnover, 2);
    $formatted_gross     = 'AED ' . number_format($gross_turnover, 2);
    $formatted_discounts = 'AED ' . number_format($total_discounts, 2);
    $formatted_aov       = 'AED ' . number_format($avg_plan_value, 2);

    ob_start();
    ?>
    
    <style>
        .acru-dashboard, .acru-dashboard * { box-sizing: border-box; }
        .acru-dashboard {
            background: linear-gradient(145deg, rgba(244, 248, 242, 0.9) 0%, rgba(238, 247, 227, 0.55) 100%);
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #231F20;
            border-radius: 22px;
            border: 1px solid #DCECC9;
            max-width: 1350px;
            margin: 0 auto 40px auto;
            box-shadow: 0 12px 34px rgba(27, 94, 32, 0.06);
        }

        /* =========================================================
           RESPONSIVE CYCLE BISTRO GLASS HEADER (DESKTOP, TABLET, MOBILE)
           ========================================================= */
        .acru-top-bar {
            background: linear-gradient(135deg, rgba(27, 94, 32, 0.94) 0%, rgba(55, 146, 55, 0.88) 55%, rgba(104, 185, 70, 0.84) 100%) !important;
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
        .acru-welcome p { font-size: 0.95em !important; color: #EEF7E3 !important; margin: 4px 0 0 0 !important; font-weight: 500 !important; }

        .acru-controls-wrap { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .acru-preset-pills { display: inline-flex; background: rgba(255, 255, 255, 0.16); padding: 4px; border-radius: 10px; border: 1px solid rgba(220, 236, 201, 0.4); gap: 4px; flex-wrap: wrap; }
        .acru-preset-pill { padding: 5px 10px; border-radius: 7px; font-size: 0.8em; font-weight: 700; color: #EEF7E3 !important; text-decoration: none !important; transition: 0.2s; white-space: nowrap; }
        .acru-preset-pill:hover { background: rgba(255, 255, 255, 0.22); color: #ffffff !important; }
        .acru-preset-pill.active { background: #ffffff; color: #1B5E20 !important; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }

        .acru-global-filter { display: flex; background: rgba(255, 255, 255, 0.95); padding: 6px 12px; border-radius: 10px; border: 1px solid #DCECC9; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); flex-wrap: wrap; }
        .acru-global-filter input[type="date"] { border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 8px; font-size: 0.85em; color: #231F20; outline: none; background: #fff; font-weight: 600; }
        .acru-global-filter button { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: #fff; border: none; padding: 6px 14px; border-radius: 7px; font-weight: 700; font-size: 0.85em; cursor: pointer; transition: 0.2s; }
        .acru-global-filter button:hover { background: linear-gradient(135deg, #68B946 0%, #379237 100%); }

        .acru-logout-btn { background: rgba(220, 38, 38, 0.9); color: #ffffff !important; padding: 9px 16px; border-radius: 10px; text-decoration: none !important; font-weight: 700; font-size: 0.88em; border: 1px solid rgba(255,255,255,0.3); transition: 0.2s; white-space: nowrap; }
        .acru-logout-btn:hover { background: #b91c1c; color: #ffffff !important; }

        /* Tablet Header Breakpoint (601px - 1024px) */
        @media (min-width: 601px) and (max-width: 1024px) {
            .acru-top-bar {
                background: linear-gradient(125deg, rgba(220, 236, 201, 0.95) 0%, rgba(104, 185, 70, 0.45) 100%) !important;
                border: 1.5px solid #379237 !important;
            }
            .acru-welcome h1 { color: #231F20 !important; font-size: 1.55em !important; }
            .acru-welcome p { color: #1B5E20 !important; }
            .acru-preset-pills { background: rgba(27, 94, 32, 0.10); border-color: rgba(27, 94, 32, 0.25); }
            .acru-preset-pill { color: #1B5E20 !important; }
            .acru-preset-pill.active { background: #1B5E20; color: #ffffff !important; }
        }

        /* Mobile Header Breakpoint (<= 600px) */
        @media (max-width: 600px) {
            .acru-dashboard { padding: 14px; border-radius: 16px; }
            .acru-top-bar {
                background: linear-gradient(145deg, rgba(55, 146, 55, 0.96) 0%, rgba(27, 94, 32, 0.93) 100%) !important;
                padding: 18px !important;
                border-radius: 16px !important;
            }
            .acru-welcome h1 { font-size: 1.4em !important; }
            .acru-global-filter { width: 100%; justify-content: space-between; }
            .acru-global-filter input[type="date"] { flex: 1; min-width: 115px; }
            .acru-Global-btn { width: 100%; }
        }

        /* =========================================================
           12-COLUMN BENTO GRID SYSTEM (3-TIER RESPONSIVE)
           ========================================================= */
        .acru-bento-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 20px; }
        
        .acru-card {
            background: rgba(255, 255, 255, 0.90);
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

        /* Tablet Bento Grid: 2-Column Balanced Layout instead of full-width stack */
        @media (min-width: 601px) and (max-width: 1024px) {
            .col-span-7, .col-span-5 { grid-column: span 12; }
            .col-span-4 { grid-column: span 6; }
            .acru-bento-grid > .col-span-4:last-child { grid-column: span 12; }
        }

        /* Mobile Bento Grid: Single-Column Stack */
        @media (max-width: 600px) {
            .col-span-7, .col-span-5, .col-span-4, .col-span-6 { grid-column: span 12; }
            .acru-card { padding: 18px; }
        }

        /* Card Typography & Elements */
        .card-label { font-size: 0.82em; font-weight: 800; color: #1B5E20; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; }
        .card-value { font-size: 2.25em; font-weight: 900; color: #231F20; line-height: 1.1; }
        .card-subtext { font-size: 0.84em; color: #64748b; margin-top: 8px; font-weight: 500; }

        /* Hero Financial Sub-Grid */
        .hero-fin-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(125px, 1fr)); gap: 12px; margin-top: 18px; padding: 12px 14px; background: rgba(0, 0, 0, 0.16); border-radius: 12px; border: 1px solid rgba(220, 236, 201, 0.25); }
        .hero-health-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(115px, 1fr)); gap: 12px; margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(255, 255, 255, 0.22); }

        /* 4-Portal Quick Launch Grid */
        .quick-launch-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 10px; }
        .ql-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 16px;
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

        /* Progress Bars */
        .progress-container { background: #e2e8f0; border-radius: 999px; height: 10px; width: 100%; overflow: hidden; margin: 12px 0 6px 0; }
        .progress-bar-fill { background: linear-gradient(90deg, #68B946, #379237); height: 100%; border-radius: 999px; transition: width 0.4s ease; }

        /* Clickable Action Alert Banner */
        .alert-box {
            background: #fffbeb;
            border: 1.5px solid #fde68a;
            border-left: 5px solid #f59e0b;
            padding: 13px 18px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            color: #92400e;
        }
        .alert-box.danger {
            background: #fef2f2;
            border-color: #fecaca;
            border-left: 5px solid #dc2626;
            color: #991b1b;
        }
        .alert-action-link {
            color: inherit !important;
            font-weight: 800;
            text-decoration: underline !important;
            margin: 0 4px;
        }

        /* Metric Split Rows */
        .metric-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #f1f5f9; }
        .metric-row:last-child { border-bottom: none; }
        .metric-badge-link { text-decoration: none !important; padding: 3px 10px; border-radius: 8px; font-weight: 800; font-size: 0.85em; transition: 0.2s; display: inline-block; }
        .metric-badge-link:hover { transform: scale(1.05); }
    </style>

    <div class="acru-dashboard">
        
        <!-- Top Navigation & Global Filter Bar -->
        <div class="acru-top-bar">
            <div class="acru-welcome">
                <h1>Executive Command Center</h1>
                <p>Welcome back, <strong><?php echo esc_html($first_name); ?></strong>. Here is your operational overview.</p>
            </div>
            
            <div class="acru-controls-wrap">
                <!-- 1-Click Date Preset Pills -->
                <div class="acru-preset-pills">
                    <a href="?start_date=<?php echo $today_date; ?>&end_date=<?php echo $today_date; ?>" class="acru-preset-pill <?php echo $active_preset === 'today' ? 'active' : ''; ?>">Today</a>
                    <a href="?start_date=<?php echo $preset_7d; ?>&end_date=<?php echo $today_date; ?>" class="acru-preset-pill <?php echo $active_preset === '7d' ? 'active' : ''; ?>">7D</a>
                    <a href="?start_date=<?php echo $preset_30d; ?>&end_date=<?php echo $today_date; ?>" class="acru-preset-pill <?php echo $active_preset === '30d' ? 'active' : ''; ?>">30D</a>
                    <a href="?start_date=<?php echo $preset_month; ?>&end_date=<?php echo $today_date; ?>" class="acru-preset-pill <?php echo $active_preset === 'month' ? 'active' : ''; ?>">This Month</a>
                    <a href="?start_date=<?php echo $preset_90d; ?>&end_date=<?php echo $today_date; ?>" class="acru-preset-pill <?php echo $active_preset === '90d' ? 'active' : ''; ?>">90D</a>
                </div>

                <!-- Custom Date Range Picker -->
                <form method="GET" class="acru-global-filter">
                    <input type="date" name="start_date" value="<?php echo esc_attr($global_start); ?>">
                    <span style="color: #64748b; font-weight: 700; font-size: 0.85em;">to</span>
                    <input type="date" name="end_date" value="<?php echo esc_attr($global_end); ?>">
                    <button type="submit">Apply</button>
                </form>

                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="acru-logout-btn">Log Out</a>
            </div>
        </div>

        <!-- Conditional Action-Required Alert Banner with Direct Deep-Links -->
        <?php if ( $pending_pos_checks > 0 || $pending_chef_count > 0 ) : ?>
        <div class="alert-box <?php echo ( $pending_pos_checks > 0 ) ? 'danger' : ''; ?>">
            <div>
                <strong>⚠️ Action Required:</strong> 
                <?php if ( $pending_pos_checks > 0 ) : ?>
                    <a href="<?php echo esc_url( site_url('/kitchen-command-center/') ); ?>" target="_blank" class="alert-action-link"><?php echo number_format( $pending_pos_checks ); ?> Un-reconciled POS Checks ↗</a>
                <?php endif; ?>
                <?php if ( $pending_pos_checks > 0 && $pending_chef_count > 0 ) : ?> &nbsp;|&nbsp; <?php endif; ?>
                <?php if ( $pending_chef_count > 0 ) : ?>
                    <a href="<?php echo esc_url( site_url('/chef-dashboard/') ); ?>" target="_blank" class="alert-action-link"><?php echo number_format( $pending_chef_count ); ?> Pending Chef Assignments ↗</a>
                <?php endif; ?>
            </div>
            <span style="font-size: 0.85em; font-weight: 700;">Click an alert to resolve &rarr;</span>
        </div>
        <?php endif; ?>

        <!-- 12-Column Bento Grid Layout -->
        <div class="acru-bento-grid">

            <!-- Card 1: New Financial Turnover & Live Subscription Health (Hero Card) -->
            <div class="acru-card col-span-7" style="background: linear-gradient(135deg, #1B5E20 0%, #379237 100%); color: #fff; border-color: #1B5E20;">
                <div>
                    <div class="card-label" style="color: #DCECC9;">
                        <span>Period Net Financial Turnover</span>
                        <span style="background: rgba(220,236,201,0.2); padding: 3px 9px; border-radius: 999px; font-size: 0.85em; color: #EEF7E3;"><?php echo esc_html( date('d M', strtotime($global_start)) . ' – ' . date('d M Y', strtotime($global_end)) ); ?></span>
                    </div>
                    <div class="card-value" style="color: #fff; font-size: 2.75em; margin-top: 4px;"><?php echo $formatted_turnover; ?></div>
                    
                    <!-- Period Financial Breakdown Strip -->
                    <div class="hero-fin-strip">
                        <div>
                            <span style="font-size: 0.75em; color: #DCECC9; display: block;">New Plans Sold</span>
                            <strong style="font-size: 1.15em; color: #fff;"><?php echo number_format($period_new_plans); ?></strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75em; color: #DCECC9; display: block;">Gross Value</span>
                            <strong style="font-size: 1.05em; color: #fff;"><?php echo esc_html($formatted_gross); ?></strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75em; color: #DCECC9; display: block;">Discounts Given</span>
                            <strong style="font-size: 1.05em; color: #fde68a;"><?php echo esc_html($formatted_discounts); ?></strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75em; color: #DCECC9; display: block;">Avg Plan Value</span>
                            <strong style="font-size: 1.05em; color: #fff;"><?php echo esc_html($formatted_aov); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Live Global Subscription Health Strip -->
                <div class="hero-health-strip">
                    <div>
                        <span style="font-size: 0.78em; color: #DCECC9; display: block;">Live Active Plans</span>
                        <strong style="font-size: 1.35em; color: #fff;"><?php echo number_format($live_active_count); ?></strong>
                    </div>
                    <div style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 12px;">
                        <span style="font-size: 0.78em; color: #DCECC9; display: block;">Live Paused</span>
                        <strong style="font-size: 1.35em; color: #fde68a;"><?php echo number_format($live_paused_count); ?></strong>
                    </div>
                    <div style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 12px;">
                        <span style="font-size: 0.78em; color: #DCECC9; display: block;">Expiring (&le;3d)</span>
                        <strong style="font-size: 1.35em; color: #fed7aa;"><?php echo number_format($renewal_radar_count); ?></strong>
                    </div>
                    <div style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 12px;">
                        <span style="font-size: 0.78em; color: #DCECC9; display: block;">Period Churned</span>
                        <strong style="font-size: 1.35em; color: #fecaca;"><?php echo number_format($true_churn_count); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Card 2: 4-Portal Quick Launch -->
            <div class="acru-card col-span-5">
                <div>
                    <div class="card-label">
                        <span>Quick Launch Portals</span>
                        <span style="color: #379237;">4 Workstations</span>
                    </div>
                    <div class="quick-launch-grid">
                        <a href="<?php echo esc_url( site_url('/foh-admin-portal/') ); ?>" target="_blank" class="ql-btn">
                            <span>FOH Command</span>
                            <span>↗</span>
                        </a>
                        <a href="<?php echo esc_url( site_url('/kitchen-command-center/') ); ?>" target="_blank" class="ql-btn">
                            <span>Kitchen Report</span>
                            <span>↗</span>
                        </a>
                        <a href="<?php echo esc_url( site_url('/menu-manager-portal/') ); ?>" target="_blank" class="ql-btn">
                            <span>Menu Manager</span>
                            <span>↗</span>
                        </a>
                        <a href="<?php echo esc_url( site_url('/admin-tools/') ); ?>" target="_blank" class="ql-btn">
                            <span>Admin Tools</span>
                            <span>↗</span>
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
                        <span style="color: #379237;"><?php echo $prep_percentage; ?>% Prepped</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px;">
                        <span class="card-value"><?php echo number_format($done_preps); ?> <small style="font-size: 0.5em; color: #64748b;">/ <?php echo number_format($total_preps); ?></small></span>
                        <span style="font-weight: 700; color: #64748b; font-size: 0.85em;">Period Preps</span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar-fill" style="width: <?php echo $prep_percentage; ?>%;"></div>
                    </div>
                    <div class="card-subtext">Period Dispatch Load: <strong><?php echo number_format($dispatch_load); ?> meals</strong></div>
                </div>

                <div style="margin-top: 14px; font-size: 0.86em; background: #EEF7E3; border: 1px solid #DCECC9; padding: 10px 12px; border-radius: 10px; color: #1B5E20;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span><strong>Today's Prep</strong> (For Tomorrow):</span>
                        <strong><?php echo number_format($today_done_preps); ?> / <?php echo number_format($today_total_preps); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span><strong>Today's Dispatch Load:</strong></span>
                        <strong><?php echo number_format($today_dispatch_load); ?> meals</strong>
                    </div>
                </div>
            </div>

            <!-- Card 4: Delivery Analytics & Success Rate -->
            <div class="acru-card col-span-4">
                <div>
                    <div class="card-label">
                        <span>Logistics Analytics</span>
                        <span style="color: #1B5E20;"><?php echo number_format($total_deliveries_period); ?> Logged</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px;">
                        <span class="card-value"><?php echo $success_rate; ?>%</span>
                        <span style="font-size: 0.85em; font-weight: 700; color: #379237;">Success Rate</span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar-fill" style="width: <?php echo $success_rate; ?>%;"></div>
                    </div>
                </div>
                <div style="margin-top: 15px;">
                    <div class="metric-row">
                        <span style="font-size: 0.9em; color: #475569;">Successful Deliveries (POS Verified)</span>
                        <strong style="color: #166534;"><?php echo number_format($successful_deliveries); ?></strong>
                    </div>
                    <div class="metric-row">
                        <span style="font-size: 0.9em; color: #475569;">Returns / Cancelled</span>
                        <strong style="color: #dc2626;"><?php echo number_format($failed_deliveries); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Card 5: FOH Reconciliation & Chef Action Queue -->
            <div class="acru-card col-span-4">
                <div>
                    <div class="card-label">
                        <span>Reconciliation &amp; Action Queue</span>
                        <span>Live Audit</span>
                    </div>
                    <div style="margin-top: 8px;">
                        <div class="metric-row">
                            <span style="font-size: 0.9em; color: #475569;">Pending POS Checks</span>
                            <a href="<?php echo esc_url( site_url('/kitchen-command-center/') ); ?>" target="_blank" class="metric-badge-link" style="background: <?php echo ($pending_pos_checks > 0) ? '#fef3c7' : '#dcfce7'; ?>; color: <?php echo ($pending_pos_checks > 0) ? '#d97706' : '#166534'; ?>;">
                                <?php echo number_format($pending_pos_checks); ?> ↗
                            </a>
                        </div>
                        <div class="metric-row">
                            <span style="font-size: 0.9em; color: #475569;">Completed POS Checks</span>
                            <strong style="color: #166534; padding: 0 6px;"><?php echo number_format($completed_pos_checks); ?></strong>
                        </div>
                        <div class="metric-row">
                            <span style="font-size: 0.9em; color: #475569;">Pending Chef Assignments</span>
                            <a href="<?php echo esc_url( site_url('/chef-dashboard/') ); ?>" target="_blank" class="metric-badge-link" style="background: <?php echo ($pending_chef_count > 0) ? '#fee2e2' : '#dcfce7'; ?>; color: <?php echo ($pending_chef_count > 0) ? '#dc2626' : '#166534'; ?>;">
                                <?php echo number_format($pending_chef_count); ?> ↗
                            </a>
                        </div>
                        <div class="metric-row">
                            <span style="font-size: 0.9em; color: #475569;">Plans Expiring Soon (&le;3 Days)</span>
                            <a href="<?php echo esc_url( site_url('/foh-admin-portal/') ); ?>" target="_blank" class="metric-badge-link" style="background: <?php echo ($renewal_radar_count > 0) ? '#ffedd5' : '#f1f5f9'; ?>; color: <?php echo ($renewal_radar_count > 0) ? '#c2410c' : '#64748b'; ?>;">
                                <?php echo number_format($renewal_radar_count); ?> ↗
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-subtext">Click any counter badge to jump directly to the workstation.</div>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}
