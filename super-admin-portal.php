<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// UNIFIED SUPER ADMIN COMMAND CENTER
// ==========================================
add_shortcode( 'meal_super_admin', 'cmp_render_super_admin_portal' );

function cmp_render_super_admin_portal() {
    
    // 1. SECURITY: Check Login
    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-sa-login', 'label_username' => __('Admin Email or Username'), 'label_password' => __('Password'));
        $custom_css = '
        <style>
            #cmp-sa-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; }
            #cmp-sa-login input[type="text"], #cmp-sa-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
            #cmp-sa-login .login-submit input[type="submit"] { width: 100%; background: #0f172a; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        </style>';
        return $custom_css . '<div style="max-width:400px; margin:50px auto; padding:30px; background:#fff; border-radius:8px; border:1px solid #ddd; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <h2 style="text-align:center; margin-top:0; color:#0f172a;">Executive Login</h2>
                    <p style="text-align:center; color:#64748b; margin-bottom:20px;">Secure access for authorized personnel only.</p>' 
                    . wp_login_form( $login_args ) . 
                '</div>';
    }

    // 2. SECURITY: Check Role (Strictly Admin or Menu Manager)
    if ( !current_user_can('manage_options') && !current_user_can('menu_manager') ) {
        return '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border-left: 4px solid #dc3232; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <p style="font-size: 1.1em; color: #dc3232;"><strong>Access Denied:</strong> You do not have Executive clearance.</p>
                    <a href="' . wp_logout_url( get_permalink() ) . '" style="display: inline-block; margin-top: 15px; background: #222; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px;">Log Out & Switch Accounts</a>
                </div>';
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
    
    $today = date('Y-m-d');
    $today_time = date('Y-m-d H:i:s');

    // 1. Subscription Health & Financial Turnover
    $all_subs = $wpdb->get_results("SELECT id, wc_order_id, total_days, start_date, expiry_date, status FROM $table_subs WHERE status != 'pending'");
    
    $active_count = 0;
    $paused_count = 0;
    $inactive_count = 0;
    $active_turnover = 0.0;
    
    foreach ($all_subs as $sub) {
        $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
        
        if ($sub->status === 'paused') {
            $paused_count++;
        } elseif ($usage_days >= $sub->total_days || $sub->expiry_date < $today_time) {
            $inactive_count++;
        } else {
            $active_count++;
            
            // Calculate active financial turnover via WooCommerce
            if ($sub->wc_order_id > 0 && function_exists('wc_get_order')) {
                $order = wc_get_order($sub->wc_order_id);
                if ($order) {
                    $active_turnover += floatval($order->get_total());
                }
            }
        }
    }

    // 2. Operational Pulse
    $today_load = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE target_date = %s AND delivery_result NOT IN ('Cancelled', 'Returned')", $today));
    $active_menu_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_foods WHERE is_active = 1");

    // Pending Chef's Assignments
    $chef_logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_logs WHERE is_chefs_choice = 1 AND target_date >= %s", $today));
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

    // 3. Delivery Analytics
    $successful_deliveries = $wpdb->get_var("SELECT COUNT(*) FROM $table_logs WHERE delivery_result = 'Successful' AND pos_updated = 1");
    $failed_deliveries = $wpdb->get_var("SELECT COUNT(*) FROM $table_logs WHERE delivery_result IN ('Cancelled', 'Returned')");
    
    $total_deliveries_ever = $successful_deliveries + $failed_deliveries;
    $success_rate = ($total_deliveries_ever > 0) ? round(($successful_deliveries / $total_deliveries_ever) * 100, 1) : 0;

    $formatted_turnover = function_exists('wc_price') ? wc_price($active_turnover) : 'AED ' . number_format($active_turnover, 2);

    ob_start();
    ?>
    
    <style>
        .sa-dashboard-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; }
        
        /* Quick Launch Bar */
        .sa-quick-launch { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 40px; }
        .sa-launch-btn { 
            display: flex; align-items: center; justify-content: center; padding: 18px 20px; 
            color: #fff !important; text-decoration: none !important; border-radius: 8px; 
            font-weight: 800; font-size: 1.15em; letter-spacing: 0.5px;
            transition: transform 0.2s ease, box-shadow 0.2s ease; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center; 
        }
        .sa-launch-btn:hover { transform: translateY(-4px); box-shadow: 0 8px 15px rgba(0,0,0,0.15); }

        /* Section Titles */
        .sa-section-title { font-size: 1.4em; color: #0f172a; margin: 0 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; font-weight: 800; }

        /* KPI Grid */
        .sa-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .sa-kpi-card { 
            background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: flex; flex-direction: column; 
            justify-content: space-between; position: relative; overflow: hidden;
        }
        .sa-kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
        
        /* Card Status Colors */
        .sa-kpi-card.green::before { background: #10b981; }
        .sa-kpi-card.orange::before { background: #f59e0b; }
        .sa-kpi-card.red::before { background: #ef4444; }
        .sa-kpi-card.blue::before { background: #3b82f6; }
        .sa-kpi-card.teal::before { background: #14b8a6; }
        .sa-kpi-card.gold::before { background: #d97706; }
        .sa-kpi-card.dark::before { background: #0f172a; }

        /* KPI Typography */
        .sa-kpi-title { font-size: 0.9em; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
        .sa-kpi-value { font-size: 2.8em; font-weight: 900; color: #0f172a; line-height: 1; margin: 0; }
        .sa-kpi-subtitle { font-size: 0.85em; color: #94a3b8; margin-top: 10px; font-weight: 600; }
    </style>

    <div class="sa-dashboard-wrap">
        
        <!-- Header -->
        <div style="background: #0f172a; color: #fff; padding: 25px 30px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 30px; box-shadow: 0 4px 10px rgba(15,23,42,0.15);">
            <div>
                <h1 style="margin: 0; color: #fff; font-size: 2em; letter-spacing: -0.5px;">Executive Dashboard</h1>
                <p style="margin: 5px 0 0 0; color: #94a3b8; font-size: 1.1em;">Welcome back, <strong><?php echo esc_html($first_name); ?></strong>.</p>
            </div>
            <div>
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" style="background: rgba(255,255,255,0.1); color: white; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; border: 1px solid rgba(255,255,255,0.2); transition: 0.2s;">Log Out</a>
            </div>
        </div>

        <!-- Quick Launch Bar -->
        <h2 class="sa-section-title">Quick Launch Portals</h2>
        <div class="sa-quick-launch">
            <a href="<?php echo site_url('/foh-portal/'); ?>" target="_blank" class="sa-launch-btn" style="background: #0ea5e9;">FOH Command Center ⇗</a>
            <a href="<?php echo site_url('/kitchen-command-center/'); ?>" target="_blank" class="sa-launch-btn" style="background: #dc2626;">Kitchen Report ⇗</a>
            <a href="https://mealplan.thecyclebistro.com/menu-manager-portal/" target="_blank" class="sa-launch-btn" style="background: #10b981;">Menu Manager ⇗</a>
            <a href="<?php echo site_url('/admin-tools/'); ?>" target="_blank" class="sa-launch-btn" style="background: #f59e0b;">Admin Tools ⇗</a>
        </div>

        <!-- Tier 1: Subscription Health -->
        <h2 class="sa-section-title">Subscription Health</h2>
        <div class="sa-kpi-grid">
            <div class="sa-kpi-card green">
                <div class="sa-kpi-title">Active Customers</div>
                <div class="sa-kpi-value"><?php echo number_format($active_count); ?></div>
                <div class="sa-kpi-subtitle">Currently assigned to an active plan</div>
            </div>
            <div class="sa-kpi-card orange">
                <div class="sa-kpi-title">Paused Accounts</div>
                <div class="sa-kpi-value"><?php echo number_format($paused_count); ?></div>
                <div class="sa-kpi-subtitle">Plans placed on temporary hold</div>
            </div>
            <div class="sa-kpi-card red">
                <div class="sa-kpi-title">Completed / Churned</div>
                <div class="sa-kpi-value"><?php echo number_format($inactive_count); ?></div>
                <div class="sa-kpi-subtitle">Plans fully consumed or expired</div>
            </div>
        </div>

        <!-- Tier 2: Operational Pulse -->
        <h2 class="sa-section-title">Operational Pulse</h2>
        <div class="sa-kpi-grid">
            <div class="sa-kpi-card blue">
                <div class="sa-kpi-title">Today's Dispatch Load</div>
                <div class="sa-kpi-value"><?php echo number_format($today_load); ?></div>
                <div class="sa-kpi-subtitle">Total meals assigned for delivery today</div>
            </div>
            <div class="sa-kpi-card <?php echo ($pending_chef_count > 0) ? 'red' : 'green'; ?>">
                <div class="sa-kpi-title">Pending Chef's Assignments</div>
                <div class="sa-kpi-value"><?php echo number_format($pending_chef_count); ?></div>
                <div class="sa-kpi-subtitle">Upcoming meals requiring manual Chef input</div>
            </div>
            <div class="sa-kpi-card teal">
                <div class="sa-kpi-title">Active Menu Catalog</div>
                <div class="sa-kpi-value"><?php echo number_format($active_menu_items); ?></div>
                <div class="sa-kpi-subtitle">Total items available for customer selection</div>
            </div>
        </div>

        <!-- Tier 3: Delivery Analytics & Finance -->
        <h2 class="sa-section-title">Performance & Finance</h2>
        <div class="sa-kpi-grid">
            <div class="sa-kpi-card gold">
                <div class="sa-kpi-title">Delivery Success Rate</div>
                <div class="sa-kpi-value"><?php echo $success_rate; ?>%</div>
                <div class="sa-kpi-subtitle">Across <?php echo number_format($total_deliveries_ever); ?> total lifetime deliveries</div>
            </div>
            <div class="sa-kpi-card dark">
                <div class="sa-kpi-title">Returned / Cancelled</div>
                <div class="sa-kpi-value"><?php echo number_format($failed_deliveries); ?></div>
                <div class="sa-kpi-subtitle">Total logistics failures logged by FOH</div>
            </div>
            <div class="sa-kpi-card green" style="grid-column: 1 / -1;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                    <div>
                        <div class="sa-kpi-title">Active Financial Turnover</div>
                        <div class="sa-kpi-value" style="font-size: 3.5em; color: #10b981;"><?php echo $formatted_turnover; ?></div>
                        <div class="sa-kpi-subtitle">Total gross value of all currently active (non-manual) WooCommerce subscriptions.</div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#e2e8f0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5;"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                </div>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
