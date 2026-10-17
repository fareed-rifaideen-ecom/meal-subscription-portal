<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// UNIFIED SUPER ADMIN COMMAND CENTER
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
    $first_name = !empty($current_user->user_firstname) ? $current_user->user_firstname : $current_user->display_name;

    // ==========================================
    // DATA GATHERING & ANALYTICS CALCULATION
    // ==========================================
    global $wpdb;
    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods = $wpdb->prefix . 'cmp_foods';
    
    $today_time = date('Y-m-d H:i:s');
    $today_date = date('Y-m-d');

    // --- DEDICATED DATE FILTER LOGIC & DEFAULTS ---
    
    // 1. FOH Reconciliation (Default: Last 3 Days)
    $foh_start = isset($_GET['foh_start']) ? sanitize_text_field($_GET['foh_start']) : date('Y-m-d', strtotime('-3 days'));
    $foh_end   = isset($_GET['foh_end']) ? sanitize_text_field($_GET['foh_end']) : $today_date;

    // 2. Subscription Health & Finance (Default: Last 3 Months)
    $sub_start = isset($_GET['sub_start']) ? sanitize_text_field($_GET['sub_start']) : date('Y-m-d', strtotime('-3 months'));
    $sub_end   = isset($_GET['sub_end']) ? sanitize_text_field($_GET['sub_end']) : $today_date;

    // 3. Operational Pulse (Default: Today)
    $op_start = isset($_GET['op_start']) ? sanitize_text_field($_GET['op_start']) : $today_date;
    $op_end   = isset($_GET['op_end']) ? sanitize_text_field($_GET['op_end']) : $today_date;

    // 4. Logistics Analytics (Default: Last 3 Days)
    $log_start = isset($_GET['log_start']) ? sanitize_text_field($_GET['log_start']) : date('Y-m-d', strtotime('-3 days'));
    $log_end   = isset($_GET['log_end']) ? sanitize_text_field($_GET['log_end']) : $today_date;


    // --- TIER 1: FOH RECONCILIATION ---
    $pending_pos_checks = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_logs WHERE target_date >= %s AND target_date <= %s AND is_locked = 1 AND pos_updated = 0 AND delivery_result != 'Pending'", $foh_start, $foh_end));
    $completed_pos_checks = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_logs WHERE target_date >= %s AND target_date <= %s AND is_locked = 1 AND pos_updated = 1", $foh_start, $foh_end));


    // --- TIER 2: SUBSCRIPTION HEALTH & TRUE CHURN ---
    $all_subs = $wpdb->get_results($wpdb->prepare(
        "SELECT id, user_id, wc_order_id, total_days, start_date, expiry_date, status, plan_price, discount_amount 
         FROM $table_subs WHERE status != 'pending' AND DATE(start_date) >= %s AND DATE(start_date) <= %s", 
        $sub_start, $sub_end
    ));
    
    $active_count = 0;
    $paused_count = 0;
    $new_turnover = 0.0;
    $user_status = array();
    
    foreach ($all_subs as $sub) {
        $uid = $sub->user_id;
        
        if (!isset($user_status[$uid])) {
            $user_status[$uid] = array('has_active' => false, 'has_completed' => false);
        }

        // Calculate Native Financial Turnover
        $price      = isset($sub->plan_price) ? floatval($sub->plan_price) : 0;
        $discount   = isset($sub->discount_amount) ? floatval($sub->discount_amount) : 0;
        $final_paid = max(0, $price - $discount);
        
        // Legacy Fallback: If DB pricing is 0 but it has a valid Woo Order, pull from Woo
        if ($final_paid == 0 && $sub->wc_order_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($sub->wc_order_id);
            if ($order) {
                $final_paid = floatval($order->get_total());
            }
        }
        
        $new_turnover += $final_paid;
        
        // Health calculations
        $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
        $is_completed = ($usage_days >= $sub->total_days || $sub->expiry_date < $today_time);
        
        if ($sub->status === 'paused') {
            $user_status[$uid]['has_active'] = true;
            $paused_count++;
        } elseif ($is_completed) {
            $user_status[$uid]['has_completed'] = true;
        } else {
            $user_status[$uid]['has_active'] = true;
            $active_count++;
        }
    }

    $true_churn_count = 0;
    foreach ($user_status as $uid => $stats) {
        if ($stats['has_completed'] && !$stats['has_active']) {
            $true_churn_count++;
        }
    }

    // --- TIER 3: OPERATIONAL PULSE ---
    $prep_target_start = date('Y-m-d', strtotime($op_start . ' +1 day'));
    $prep_target_end   = date('Y-m-d', strtotime($op_end . ' +1 day'));

    $total_preps = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_logs WHERE target_date >= %s AND target_date <= %s AND is_locked = 1", $prep_target_start, $prep_target_end));
    $done_preps  = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_logs WHERE target_date >= %s AND target_date <= %s AND is_locked = 1 AND is_prepared = 1", $prep_target_start, $prep_target_end));

    $dispatch_load = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_logs WHERE target_date >= %s AND target_date <= %s AND delivery_result NOT IN ('Cancelled', 'Returned') AND is_locked = 1", $op_start, $op_end));
    
    $chef_logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_logs WHERE is_chefs_choice = 1 AND target_date >= %s AND target_date <= %s", $op_start, $op_end));
    $active_food_ranges = $wpdb->get_results("SELECT DISTINCT valid_from, valid_until FROM $table_foods WHERE is_active = 1");
    $pending_chef_count = 0;
    
    foreach($chef_logs as $log) {
        if (!$log->breakfast_id && !$log->lunch_id && !$log->dinner_id && !$log->juice_1_id) {
            $menu_exists = false;
            foreach($active_food_ranges as $range) {
                if (empty($range->valid_from) || empty($range->valid_until)) {
                    $menu_exists = true; break;
                }
                if ($log->target_date >= $range->valid_from && $log->target_date <= $range->valid_until) {
                    $menu_exists = true; break;
                }
            }
            if ($menu_exists) { $pending_chef_count++; }
        }
    }

    // --- TIER 4: DELIVERY ANALYTICS ---
    $successful_deliveries = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_logs WHERE delivery_result = 'Successful' AND pos_updated = 1 AND target_date >= %s AND target_date <= %s", $log_start, $log_end));
    $failed_deliveries = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_logs WHERE delivery_result IN ('Cancelled', 'Returned') AND target_date >= %s AND target_date <= %s", $log_start, $log_end));
    
    $total_deliveries_period = $successful_deliveries + $failed_deliveries;
    $success_rate = ($total_deliveries_period > 0) ? round(($successful_deliveries / $total_deliveries_period) * 100, 1) : 0;

    $formatted_turnover = function_exists('wc_price') ? wc_price($new_turnover) : 'AED ' . number_format($new_turnover, 2);

    ob_start();
    ?>
    
    <style>
        .sa-dashboard-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; padding: 0 12px; }
        
        /* Desktop Executive Glass Header (> 992px) */
        .sa-header-banner {
            background: linear-gradient(135deg, rgba(27, 94, 32, 0.92) 0%, rgba(55, 146, 55, 0.86) 55%, rgba(104, 185, 70, 0.82) 100%) !important;
            backdrop-filter: blur(18px) !important;
            -webkit-backdrop-filter: blur(18px) !important;
            color: #fff !important;
            padding: 26px 30px !important;
            border-radius: 18px !important;
            border: 1.5px solid #DCECC9 !important;
            border-bottom: 4px solid #68B946 !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 15px !important;
            margin-bottom: 40px !important;
            box-shadow: 0 14px 34px rgba(27, 94, 32, 0.14) !important;
        }
        .sa-header-banner h1 { margin: 0 !important; color: #fff !important; font-size: 2em !important; letter-spacing: -0.5px !important; font-weight: 800 !important; }
        .sa-header-banner p { margin: 5px 0 0 0 !important; color: #EEF7E3 !important; font-size: 1.1em !important; }
        .sa-header-banner .sa-logout-btn { background: rgba(255,255,255,0.18) !important; color: white !important; text-decoration: none !important; padding: 10px 22px !important; border-radius: 10px !important; font-weight: bold !important; border: 1.5px solid #DCECC9 !important; transition: 0.2s !important; display: inline-block !important; }

        /* Tablet Executive Glass Header (601px - 991px) */
        @media (min-width: 601px) and (max-width: 991px) {
            .sa-header-banner {
                background: linear-gradient(125deg, rgba(220, 236, 201, 0.92) 0%, rgba(104, 185, 70, 0.42) 100%) !important;
                border: 1.5px solid #379237 !important;
                padding: 22px 24px !important;
            }
            .sa-header-banner h1 { color: #231F20 !important; font-size: 1.75em !important; }
            .sa-header-banner p { color: #1B5E20 !important; }
            .sa-header-banner .sa-logout-btn { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; color: #ffffff !important; }
        }

        /* Mobile Executive Glass Header (<= 600px) */
        @media (max-width: 600px) {
            .sa-header-banner {
                background: linear-gradient(145deg, rgba(55, 146, 55, 0.95) 0%, rgba(27, 94, 32, 0.92) 100%) !important;
                border: 1.5px solid #68B946 !important;
                border-radius: 16px !important;
                padding: 18px !important;
            }
            .sa-header-banner h1 { color: #ffffff !important; font-size: 1.5em !important; }
            .sa-header-banner p { color: #DCECC9 !important; font-size: 0.95em !important; }
        }

        /* Quick Launch Bar */
        .sa-quick-launch { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 40px; }
        .sa-launch-btn { 
            display: flex; align-items: center; justify-content: center; padding: 18px 20px; 
            color: #fff !important; text-decoration: none !important; border-radius: 12px; 
            font-weight: 800; font-size: 1.1em; letter-spacing: 0.3px;
            transition: transform 0.2s ease, box-shadow 0.2s ease; 
            box-shadow: 0 6px 14px rgba(0,0,0,0.08); text-align: center; 
        }
        .sa-launch-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.14); }

        /* Section Titles */
        .sa-section-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; border-bottom: 2px solid #DCECC9; padding-bottom: 10px; flex-wrap: wrap; gap: 15px; }
        .sa-section-title { font-size: 1.4em; color: #231F20; margin: 0; padding: 0; border: none; font-weight: 800; }

        /* KPI Grid */
        .sa-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 50px; }
        .sa-kpi-card { 
            background: rgba(255, 255, 255, 0.88); backdrop-filter: blur(12px); padding: 25px; border-radius: 14px; border: 1px solid #DCECC9; 
            box-shadow: 0 6px 16px rgba(27, 94, 32, 0.05); display: flex; flex-direction: column; 
            justify-content: space-between; position: relative; overflow: hidden;
        }
        .sa-kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
        
        /* Card Status Colors */
        .sa-kpi-card.green::before { background: #379237; }
        .sa-kpi-card.orange::before { background: #f59e0b; }
        .sa-kpi-card.red::before { background: #ef4444; }
        .sa-kpi-card.blue::before { background: #3b82f6; }
        .sa-kpi-card.teal::before { background: #68B946; }
        .sa-kpi-card.gold::before { background: #d97706; }
        .sa-kpi-card.dark::before { background: #1B5E20; }

        /* KPI Typography */
        .sa-kpi-title { font-size: 0.9em; font-weight: 800; color: #1B5E20; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
        .sa-kpi-value { font-size: 2.8em; font-weight: 900; color: #231F20; line-height: 1; margin: 0; }
        .sa-kpi-subtitle { font-size: 0.85em; color: #64748b; margin-top: 10px; font-weight: 600; }
        
        .sa-filter-form { margin: 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .sa-filter-form input[type="date"] { padding: 6px 10px; border: 1px solid #b7e1a1; border-radius: 6px; color: #231F20; font-size: 0.9em; background: #fff; }
        .sa-filter-form button { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: white; border: none; padding: 7px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.9em; transition: 0.2s; }
        .sa-filter-form button:hover { background: linear-gradient(135deg, #68B946 0%, #379237 100%); }
    </style>

    <div class="sa-dashboard-wrap">
        
        <!-- Header -->
        <div class="sa-header-banner">
            <div>
                <h1>Executive Dashboard</h1>
                <p>Welcome back, <strong><?php echo esc_html($first_name); ?></strong>.</p>
            </div>
            <div>
                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="sa-logout-btn">Log Out</a>
            </div>
        </div>

        <!-- Quick Launch Bar -->
        <h2 class="sa-section-title" style="border-bottom: 2px solid #DCECC9; padding-bottom: 10px; margin-bottom: 20px;">Quick Launch Portals</h2>
        <div class="sa-quick-launch">
            <a href="<?php echo site_url('/foh-admin-portal/'); ?>" target="_blank" class="sa-launch-btn" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);">FOH Command Center ⇗</a>
            <a href="<?php echo site_url('/kitchen-command-center/'); ?>" target="_blank" class="sa-launch-btn" style="background: #dc2626;">Kitchen Report ⇗</a>
            <a href="<?php echo site_url('/menu-manager-portal/'); ?>" target="_blank" class="sa-launch-btn" style="background: #68B946;">Menu Manager ⇗</a>
            <a href="<?php echo site_url('/admin-tools/'); ?>" target="_blank" class="sa-launch-btn" style="background: #f59e0b;">Admin Tools ⇗</a>
        </div>

        <!-- Tier 1: FOH Reconciliation -->
        <div class="sa-section-header">
            <h2 class="sa-section-title">FOH Reconciliation</h2>
            <form method="GET" class="sa-filter-form">
                <!-- Preserve other states -->
                <input type="hidden" name="sub_start" value="<?php echo esc_attr($sub_start); ?>">
                <input type="hidden" name="sub_end" value="<?php echo esc_attr($sub_end); ?>">
                <input type="hidden" name="op_start" value="<?php echo esc_attr($op_start); ?>">
                <input type="hidden" name="op_end" value="<?php echo esc_attr($op_end); ?>">
                <input type="hidden" name="log_start" value="<?php echo esc_attr($log_start); ?>">
                <input type="hidden" name="log_end" value="<?php echo esc_attr($log_end); ?>">

                <span style="font-size: 0.85em; font-weight: 800; color: #1B5E20; text-transform: uppercase;">Filter:</span>
                <input type="date" name="foh_start" value="<?php echo esc_attr($foh_start); ?>">
                <span style="color: #64748b; font-weight: 600; font-size: 0.9em;">to</span>
                <input type="date" name="foh_end" value="<?php echo esc_attr($foh_end); ?>">
                <button type="submit">Apply</button>
            </form>
        </div>
        <div class="sa-kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
            <div class="sa-kpi-card <?php echo ($pending_pos_checks > 0) ? 'gold' : 'green'; ?>">
                <div class="sa-kpi-title">Pending POS Checks</div>
                <div class="sa-kpi-value"><?php echo number_format($pending_pos_checks); ?></div>
                <div class="sa-kpi-subtitle">Un-reconciled deliveries for selected period</div>
            </div>
            <div class="sa-kpi-card green">
                <div class="sa-kpi-title">Completed POS Checks</div>
                <div class="sa-kpi-value"><?php echo number_format($completed_pos_checks); ?></div>
                <div class="sa-kpi-subtitle">Successfully reconciled orders</div>
            </div>
        </div>

        <!-- Tier 2: Subscription Health -->
        <div class="sa-section-header">
            <h2 class="sa-section-title">Subscription Health & Finance</h2>
            <form method="GET" class="sa-filter-form">
                <input type="hidden" name="foh_start" value="<?php echo esc_attr($foh_start); ?>">
                <input type="hidden" name="foh_end" value="<?php echo esc_attr($foh_end); ?>">
                <input type="hidden" name="op_start" value="<?php echo esc_attr($op_start); ?>">
                <input type="hidden" name="op_end" value="<?php echo esc_attr($op_end); ?>">
                <input type="hidden" name="log_start" value="<?php echo esc_attr($log_start); ?>">
                <input type="hidden" name="log_end" value="<?php echo esc_attr($log_end); ?>">

                <span style="font-size: 0.85em; font-weight: 800; color: #1B5E20; text-transform: uppercase;">Plans Created Between:</span>
                <input type="date" name="sub_start" value="<?php echo esc_attr($sub_start); ?>">
                <span style="color: #64748b; font-weight: 600; font-size: 0.9em;">to</span>
                <input type="date" name="sub_end" value="<?php echo esc_attr($sub_end); ?>">
                <button type="submit">Apply</button>
            </form>
        </div>
        <div class="sa-kpi-grid">
            <div class="sa-kpi-card green">
                <div class="sa-kpi-title">Active Customers</div>
                <div class="sa-kpi-value"><?php echo number_format($active_count); ?></div>
                <div class="sa-kpi-subtitle">Active plans originating in this period</div>
            </div>
            <div class="sa-kpi-card orange">
                <div class="sa-kpi-title">Paused Accounts</div>
                <div class="sa-kpi-value"><?php echo number_format($paused_count); ?></div>
                <div class="sa-kpi-subtitle">Paused plans originating in this period</div>
            </div>
            <div class="sa-kpi-card red">
                <div class="sa-kpi-title">Churned Customers</div>
                <div class="sa-kpi-value"><?php echo number_format($true_churn_count); ?></div>
                <div class="sa-kpi-subtitle">Subscribers from this period who didn't renew</div>
            </div>
            <div class="sa-kpi-card dark">
                <div class="sa-kpi-title">New Financial Turnover</div>
                <div class="sa-kpi-value" style="font-size: 2.2em; color: #379237; margin-top: 5px;"><?php echo $formatted_turnover; ?></div>
                <div class="sa-kpi-subtitle">Gross value of all plans acquired in this period</div>
            </div>
        </div>

        <!-- Tier 3: Operational Pulse -->
        <div class="sa-section-header">
            <h2 class="sa-section-title">Operational Pulse</h2>
            <form method="GET" class="sa-filter-form">
                <input type="hidden" name="foh_start" value="<?php echo esc_attr($foh_start); ?>">
                <input type="hidden" name="foh_end" value="<?php echo esc_attr($foh_end); ?>">
                <input type="hidden" name="sub_start" value="<?php echo esc_attr($sub_start); ?>">
                <input type="hidden" name="sub_end" value="<?php echo esc_attr($sub_end); ?>">
                <input type="hidden" name="log_start" value="<?php echo esc_attr($log_start); ?>">
                <input type="hidden" name="log_end" value="<?php echo esc_attr($log_end); ?>">

                <span style="font-size: 0.85em; font-weight: 800; color: #1B5E20; text-transform: uppercase;">Eating Dates:</span>
                <input type="date" name="op_start" value="<?php echo esc_attr($op_start); ?>">
                <span style="color: #64748b; font-weight: 600; font-size: 0.9em;">to</span>
                <input type="date" name="op_end" value="<?php echo esc_attr($op_end); ?>">
                <button type="submit">Apply</button>
            </form>
        </div>
        <div class="sa-kpi-grid">
            <div class="sa-kpi-card teal">
                <div class="sa-kpi-title">Meal Preparations</div>
                <div class="sa-kpi-value"><?php echo number_format($done_preps); ?> / <?php echo number_format($total_preps); ?></div>
                <div class="sa-kpi-subtitle">Meals prepped vs total needed for this period</div>
            </div>
            <div class="sa-kpi-card blue">
                <div class="sa-kpi-title">Dispatch Load</div>
                <div class="sa-kpi-value"><?php echo number_format($dispatch_load); ?></div>
                <div class="sa-kpi-subtitle">Active meals assigned for delivery</div>
            </div>
            <div class="sa-kpi-card <?php echo ($pending_chef_count > 0) ? 'red' : 'green'; ?>">
                <div class="sa-kpi-title">Pending Chef's Assignments</div>
                <div class="sa-kpi-value"><?php echo number_format($pending_chef_count); ?></div>
                <div class="sa-kpi-subtitle">Meals requiring manual Chef input</div>
            </div>
        </div>

        <!-- Tier 4: Delivery Analytics -->
        <div class="sa-section-header">
            <h2 class="sa-section-title">Logistics Analytics</h2>
            <form method="GET" class="sa-filter-form">
                <input type="hidden" name="foh_start" value="<?php echo esc_attr($foh_start); ?>">
                <input type="hidden" name="foh_end" value="<?php echo esc_attr($foh_end); ?>">
                <input type="hidden" name="sub_start" value="<?php echo esc_attr($sub_start); ?>">
                <input type="hidden" name="sub_end" value="<?php echo esc_attr($sub_end); ?>">
                <input type="hidden" name="op_start" value="<?php echo esc_attr($op_start); ?>">
                <input type="hidden" name="op_end" value="<?php echo esc_attr($op_end); ?>">

                <span style="font-size: 0.85em; font-weight: 800; color: #1B5E20; text-transform: uppercase;">Filter:</span>
                <input type="date" name="log_start" value="<?php echo esc_attr($log_start); ?>">
                <span style="color: #64748b; font-weight: 600; font-size: 0.9em;">to</span>
                <input type="date" name="log_end" value="<?php echo esc_attr($log_end); ?>">
                <button type="submit">Apply</button>
            </form>
        </div>
        <div class="sa-kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
            <div class="sa-kpi-card dark">
                <div class="sa-kpi-title">Delivery Success Rate</div>
                <div class="sa-kpi-value"><?php echo $success_rate; ?>%</div>
                <div class="sa-kpi-subtitle">Success percentage for the selected timeframe</div>
            </div>
            <div class="sa-kpi-card green">
                <div class="sa-kpi-title">Successful Deliveries</div>
                <div class="sa-kpi-value"><?php echo number_format($successful_deliveries); ?></div>
                <div class="sa-kpi-subtitle">Deliveries completed and verified via POS</div>
            </div>
            <div class="sa-kpi-card red">
                <div class="sa-kpi-title">Returned / Cancelled</div>
                <div class="sa-kpi-value"><?php echo number_format($failed_deliveries); ?></div>
                <div class="sa-kpi-subtitle">Total logistics failures logged by FOH</div>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
