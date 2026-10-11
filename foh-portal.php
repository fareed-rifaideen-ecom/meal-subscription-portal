<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// FRONT OF HOUSE (FOH) ADMIN PORTAL
// ==========================================
add_shortcode( 'meal_foh_portal', 'cmp_render_foh_portal' );

// 1. AJAX HANDLER: Update Expiry Date
add_action('wp_ajax_cmp_foh_update_expiry', 'cmp_ajax_foh_update_expiry');
function cmp_ajax_foh_update_expiry() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $wpdb->update($table_subs, array('expiry_date' => sanitize_text_field($_POST['new_expiry']) . ' 23:59:59'), array('id' => intval($_POST['sub_id'])));
    wp_send_json_success();
}

// 2. AJAX HANDLER: Toggle Pause/Resume Status
add_action('wp_ajax_cmp_foh_toggle_status', 'cmp_ajax_foh_toggle_status');
function cmp_ajax_foh_toggle_status() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $wpdb->update($table_subs, array('status' => sanitize_text_field($_POST['new_status'])), array('id' => intval($_POST['sub_id'])));
    wp_send_json_success();
}

// 3. AJAX HANDLER: Save FOH Remarks
add_action('wp_ajax_cmp_foh_save_remark', 'cmp_ajax_foh_save_remark');
function cmp_ajax_foh_save_remark() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $remark = sanitize_textarea_field($_POST['remark']);
    $wpdb->update($table_subs, array('foh_remarks' => $remark), array('id' => intval($_POST['sub_id'])));
    wp_send_json_success();
}

// 4. AJAX HANDLER: Update Super Admin Financials
add_action('wp_ajax_cmp_foh_update_financials', 'cmp_ajax_foh_update_financials');
function cmp_ajax_foh_update_financials() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    
    $sub_id   = intval($_POST['sub_id']);
    $price    = floatval($_POST['plan_price']);
    $discount = floatval($_POST['discount_amount']);
    
    $wpdb->update($table_subs, array('plan_price' => $price, 'discount_amount' => $discount), array('id' => $sub_id));
    wp_send_json_success(array('price' => $price, 'discount' => $discount));
}

// 5. AJAX HANDLER: Export CSV
add_action('wp_ajax_cmp_foh_export_csv', 'cmp_foh_export_csv');
function cmp_foh_export_csv() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { 
        wp_die('Access Denied'); 
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $today = date('Y-m-d H:i:s');

    $all_subs = $wpdb->get_results("SELECT s.*, u.display_name, u.user_email FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.status != 'pending' ORDER BY s.id DESC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="FOH_Subscriptions_Export_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

    fputcsv($output, array('Order ID', 'Customer Name', 'Email', 'Phone', 'Plan Details', 'Status', 'Filled Days', 'Used Days', 'Balance Days', 'FOH Remarks'));

    foreach ($all_subs as $sub) {
        $order = wc_get_order($sub->wc_order_id);
        
        $fname = get_user_meta($sub->user_id, 'first_name', true) ?: get_user_meta($sub->user_id, 'billing_first_name', true);
        $lname = get_user_meta($sub->user_id, 'last_name', true) ?: get_user_meta($sub->user_id, 'billing_last_name', true);
        $fallback_name = trim($fname . ' ' . $lname);
        if (empty($fallback_name)) $fallback_name = $sub->display_name;
        
        $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $fallback_name;
        if (empty(trim($full_name))) { $full_name = $fallback_name; }

        $phone = $order ? $order->get_billing_phone() : (get_user_meta($sub->user_id, 'billing_phone', true) ?: 'N/A');

        $filled_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result NOT IN ('Cancelled', 'Returned')", $sub->id));
        $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
        $balance = max(0, $sub->total_days - $usage_days);

        $status_label = 'Active';
        if ($sub->status === 'paused') {
            $status_label = 'Paused';
        } elseif ($usage_days >= $sub->total_days || $sub->expiry_date < $today) {
            $status_label = 'Inactive/Expired';
        }

        $remarks = !empty($sub->foh_remarks) ? wp_strip_all_tags($sub->foh_remarks) : '';

        // Dynamic Sequence Handling for Export
        $display_order_id = 'Manual';
        if ($sub->wc_order_id < 0) { $display_order_id = 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT); } 
        elseif ($sub->wc_order_id > 0) { $display_order_id = '#' . $sub->wc_order_id; }

        fputcsv($output, array(
            $display_order_id,
            $full_name,
            $sub->user_email,
            $phone,
            $sub->plan_name,
            $status_label,
            $filled_days,
            $usage_days,
            $balance,
            $remarks
        ));
    }
    
    fclose($output); 
    exit;
}

// 6. AJAX HANDLER: Toggle Deposit Status
add_action('wp_ajax_cmp_foh_toggle_deposit', 'cmp_ajax_foh_toggle_deposit');
function cmp_ajax_foh_toggle_deposit() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_foh_nonce', 'nonce');
    
    $user_id = intval($_POST['user_id']);
    $new_status = sanitize_text_field($_POST['new_status']); 
    
    update_user_meta($user_id, '_cmp_deposit_held', $new_status);
    wp_send_json_success();
}


function cmp_render_foh_portal() {
    date_default_timezone_set('Asia/Dubai');

    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-foh-login', 'label_username' => __('Email Address or Username'), 'label_password' => __('Password'));
        $custom_css = '
        <style>
            #cmp-foh-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; }
            #cmp-foh-login input[type="text"], #cmp-foh-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
            #cmp-foh-login .login-submit input[type="submit"] { width: 100%; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; box-sizing: border-box; }
            #cmp-foh-login .login-remember { text-align: left; margin-bottom: 15px; }
        </style>';
        return $custom_css . '<div style="max-width: 400px; margin: 50px auto; padding: 30px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <h2 style="text-align: center; margin-top: 0; color: #222;">FOH Command Center</h2>
                    <p style="text-align: center; color: #666; margin-bottom: 20px;">Please log in with an authorized staff account.</p>' 
                    . wp_login_form( $login_args ) . 
                '</div>';
    }

    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) {
        return '<p style="padding: 20px; background: #fff; border-left: 4px solid #dc3232;">Access Denied. Authorized staff account required.</p>';
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    $col_check = $wpdb->get_results("SHOW COLUMNS FROM {$table_subs} LIKE 'foh_remarks'");
    if (empty($col_check)) { $wpdb->query("ALTER TABLE {$table_subs} ADD foh_remarks TEXT NULL AFTER status"); }

    $all_subs = $wpdb->get_results("SELECT s.*, u.display_name, u.user_email FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.status != 'pending' ORDER BY s.id DESC");
    
    // Dynamic Filter Extractor
    $unique_plans = array();

    $tabs = array('active' => array(), 'paused' => array(), 'inactive' => array());
    $today = date('Y-m-d H:i:s');

    foreach ($all_subs as $sub) {
        $filled_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result NOT IN ('Cancelled', 'Returned')", $sub->id));
        $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
        
        $sub->filled = $filled_days;
        $sub->usage = $usage_days;
        $sub->balance = max(0, $sub->total_days - $usage_days);
        
        if ($sub->status === 'paused') {
            $tabs['paused'][] = $sub;
        } elseif ($usage_days >= $sub->total_days || $sub->expiry_date < $today) {
            $tabs['inactive'][] = $sub;
        } else {
            $tabs['active'][] = $sub;
        }
        
        // Extract raw plan name for filter
        $plan_parts = explode(' - ', $sub->plan_name);
        $core = (count($plan_parts) > 1 && !preg_match('/(Meal|Day|Cleanse|Plans)/i', end($plan_parts))) ? implode(' - ', array_slice($plan_parts, 0, -1)) : $sub->plan_name;
        if (!in_array($core, $unique_plans)) { $unique_plans[] = $core; }
    }
    
    sort($unique_plans);

    ob_start();
    ?>
    <style>
        .foh-tabs { display: flex; border-bottom: 2px solid #ddd; margin-bottom: 20px; overflow-x: auto; }
        .foh-tab-btn { background: none; border: none; padding: 15px 30px; font-size: 1.1em; font-weight: bold; color: #666; cursor: pointer; border-bottom: 3px solid transparent; white-space: nowrap; }
        .foh-tab-btn.active { color: #0073aa; border-bottom: 3px solid #0073aa; }
        .foh-tab-content { display: none; }
        .foh-tab-content.active { display: block; }
        .sub-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #ddd; font-size: 0.95em; }
        .sub-table th { padding: 12px; border-bottom: 2px solid #ddd; text-align: left; background: #f8f9fa; }
        .sub-table td { padding: 12px; border-bottom: 1px solid #eee; vertical-align: top; }
        .remark-edit-box { transition: all 0.3s ease; overflow: hidden; }
        .metric-progress-bar { width: 100%; background-color: #e2e8f0; border-radius: 4px; height: 6px; margin: 6px 0; overflow: hidden; }
        .metric-progress-fill { background-color: #16a34a; height: 100%; border-radius: 4px; transition: width 0.3s ease; }
    </style>

    <div style="max-width: 1200px; margin: 0 auto; font-family: inherit;">
        
        <div style="background: #222; color: #fff; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 25px;">
            <div>
                <h2 style="margin: 0; color: #fff;">FOH Command Center</h2>
                <p style="margin: 5px 0 0 0; color: #ccc;">Manage Customer Subscriptions.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_foh_export_csv')); ?>" style="background: #10b981; color: white; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; transition: background 0.2s;">Export CSV</a>
                <a href="<?php echo site_url('/kitchen-command-center/'); ?>" target="_blank" style="background: #2271b1; color: white; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold;">View Live Kitchen Report</a>
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" style="background: #dc3232; color: white; padding: 10px 20px; border-radius: 4px; text-decoration: none; font-weight:bold;">Log Out</a>
            </div>
        </div>

        <!-- NEW KPI DASHBOARD -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div style="background:#fff; border-left:4px solid #10b981; padding:20px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="color:#64748b; font-size:0.9em; font-weight:bold; text-transform:uppercase;">Active Customers</div>
                <div style="font-size:2em; font-weight:bold; color:#0f172a;"><?php echo count($tabs['active']); ?></div>
            </div>
            <div style="background:#fff; border-left:4px solid #f59e0b; padding:20px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="color:#64748b; font-size:0.9em; font-weight:bold; text-transform:uppercase;">Paused Customers</div>
                <div style="font-size:2em; font-weight:bold; color:#0f172a;"><?php echo count($tabs['paused']); ?></div>
            </div>
            <div style="background:#fff; border-left:4px solid #dc2626; padding:20px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="color:#64748b; font-size:0.9em; font-weight:bold; text-transform:uppercase;">Inactive / Expired</div>
                <div style="font-size:2em; font-weight:bold; color:#0f172a;"><?php echo count($tabs['inactive']); ?></div>
            </div>
        </div>

        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 2; min-width: 250px;">
                <input type="text" id="fohSearch" placeholder="🔍 Search customers by name, email, or phone..." style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em;">
            </div>
            <div style="flex: 1; min-width: 200px;">
                <select id="fohPlanFilter" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em;">
                    <option value="">All Plans</option>
                    <?php foreach($unique_plans as $up): ?>
                        <option value="<?php echo esc_attr($up); ?>"><?php echo esc_html($up); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="foh-tabs" style="background: #fff; padding: 0 20px;">
            <button class="foh-tab-btn active" onclick="switchTab(event, 'active-tab')">Active Subscriptions</button>
            <button class="foh-tab-btn" onclick="switchTab(event, 'paused-tab')">Paused</button>
            <button class="foh-tab-btn" onclick="switchTab(event, 'inactive-tab')">Inactive / Expired</button>
        </div>

        <?php foreach(array('active', 'paused', 'inactive') as $tab_key): ?>
        <div id="<?php echo $tab_key; ?>-tab" class="foh-tab-content <?php echo $tab_key == 'active' ? 'active' : ''; ?>">
            <div style="overflow-x: auto;">
                <table class="sub-table">
                    <thead>
                        <tr>
                            <!-- ADJUSTED WIDTHS FOR OPTIMAL FIT -->
                            <th style="width: 8%;">Order ID</th>
                            <th style="width: 22%;">Customer Info</th>
                            <th style="width: 22%;">Plan Details</th>
                            <th style="width: 18%; text-align: center;">Tracking Metrics</th>
                            <th style="width: 13%;">Expiry Management</th>
                            <th style="width: 17%; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($tabs[$tab_key])): ?>
                            <tr class="empty-row"><td colspan="6" style="text-align:center; padding:30px; color:#666;">No subscriptions found in this category.</td></tr>
                        <?php else: foreach($tabs[$tab_key] as $sub): 
                            $order = wc_get_order($sub->wc_order_id);
                            
                            $fname = get_user_meta($sub->user_id, 'first_name', true) ?: get_user_meta($sub->user_id, 'billing_first_name', true);
                            $lname = get_user_meta($sub->user_id, 'last_name', true) ?: get_user_meta($sub->user_id, 'billing_last_name', true);
                            $fallback_name = trim($fname . ' ' . $lname);
                            if (empty($fallback_name)) $fallback_name = $sub->display_name;
                            
                            $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $fallback_name;
                            if (empty(trim($full_name))) { $full_name = $fallback_name; }

                            $phone = $order ? $order->get_billing_phone() : (get_user_meta($sub->user_id, 'billing_phone', true) ?: 'N/A');
                            $is_paused = ($sub->status === 'paused');
                            
                            // NEW: RECIPIENT NAME EXTRACTION
                            $plan_parts = explode(' - ', $sub->plan_name);
                            $potential_recipient = end($plan_parts);
                            $recipient_badge = '';
                            
                            if (!preg_match('/(Meal|Day|Cleanse|Plans)/i', $potential_recipient) && count($plan_parts) > 1) {
                                $recipient_badge = ' <span style="background:#e2e8f0; color:#475569; padding:2px 8px; border-radius:12px; font-size:0.85em; margin-left:8px; display:inline-block; margin-bottom: 5px;">For: <strong>' . esc_html($potential_recipient) . '</strong></span>';
                                array_pop($plan_parts);
                                $core_plan_name = implode(' - ', $plan_parts);
                            } else {
                                $core_plan_name = $sub->plan_name;
                            }

                            $search_data = esc_attr(strtolower($full_name . ' ' . $sub->user_email . ' ' . $phone . ' ' . $potential_recipient));
                            $plan_data = esc_attr($core_plan_name);

                            // PRICE AND DISCOUNT LOGIC (PHASE 2 DB SYNC)
                            $base_price = isset($sub->plan_price) ? (float) $sub->plan_price : 0;
                            $discount   = isset($sub->discount_amount) ? (float) $sub->discount_amount : 0;
                            
                            // Fallback for legacy subscriptions without DB prices
                            if ($base_price == 0 && $order) {
                                $base_price = (float) $order->get_subtotal();
                                $discount = (float) $order->get_discount_total();
                            }
                            
                            $final_price = max(0, $base_price - $discount);
                            $currency = $order ? get_woocommerce_currency_symbol($order->get_currency()) : 'AED';
                            
                            if ($discount > 0 && $base_price > 0) {
                                $discount_percentage = round(($discount / $base_price) * 100);
                                $price_display = $currency . ' ' . number_format($final_price, 2) . ' <span style="color:#d63638; font-weight:bold; font-size:0.9em;">(-' . $discount_percentage . '%)</span>';
                            } else {
                                $price_display = $currency . ' ' . number_format($final_price, 2);
                            }

                            // REMARKS PREVIEW LOGIC
                            $remark_text = !empty($sub->foh_remarks) ? $sub->foh_remarks : '';
                            $short_remark = mb_strimwidth($remark_text, 0, 32, '...');
                            $preview_html = empty($remark_text) ? '<span style="color:#94a3b8; font-style:italic; font-weight:normal;">No remarks</span>' : esc_html($short_remark);
                            
                            // PROGRESS BAR CALCULATION
                            $usage_percent = ($sub->total_days > 0) ? round(($sub->usage / $sub->total_days) * 100) : 0;
                            
                            // DEPOSIT WALLET STATUS
                            $deposit_held = get_user_meta($sub->user_id, '_cmp_deposit_held', true);
                            if ($deposit_held === '') { $deposit_held = 'yes'; } // Grandfathered logic
                            
                            $status_color = '#dc2626'; $status_bg = '#fee2e2'; $status_text = 'Refunded / No Deposit';
                            if ($deposit_held === 'yes') { $status_color = '#10b981'; $status_bg = '#dcfce7'; $status_text = 'Bag Deposit Held'; }
                            if ($deposit_held === 'own_bag') { $status_color = '#8b5cf6'; $status_bg = '#ede9fe'; $status_text = "Customer's Own Bag"; }

                            // SEQUENCE M-0000 LOGIC
                            $display_order_id = '';
                            if ($sub->wc_order_id < 0) {
                                $display_order_id = 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT);
                            } elseif ($sub->wc_order_id > 0) {
                                $display_order_id = '#' . esc_html($sub->wc_order_id);
                            } else {
                                $display_order_id = '<span style="color:#d63638;">Manual</span>';
                            }
                        ?>
                        <tr class="foh-row" data-search="<?php echo $search_data; ?>" data-plan="<?php echo $plan_data; ?>">
                            
                            <td style="font-size: 1.1em; font-weight: bold; color: #334155;">
                                <?php echo $display_order_id; ?>
                            </td>
                            
                            <td>
                                <div style="display: flex; flex-direction: column;">
                                    <div><strong style="font-size: 1.1em; color: #0073aa;"><?php echo esc_html($full_name ?: 'Customer'); ?></strong><?php echo $recipient_badge; ?></div>
                                    <span style="color: #555;"><?php echo esc_html($sub->user_email); ?></span>
                                    <span style="color: #555; display: inline-flex; align-items: center; gap: 5px; margin-top: 3px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                        <?php echo esc_html($phone); ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <strong style="color: #222;"><?php echo esc_html($core_plan_name); ?></strong><br>
                                
                                <div style="margin-top:3px; display:inline-block;">
                                    <span style="color: #2271b1; font-weight:bold;" id="price-display-<?php echo $sub->id; ?>">Price: <?php echo $price_display; ?></span>
                                    
                                    <!-- NEW: SUPER ADMIN EDIT CONTROLS -->
                                    <?php if (current_user_can('manage_options')): ?>
                                        <div style="margin-top: 5px;">
                                            <button class="edit-financials-btn" data-target="fin-edit-<?php echo $sub->id; ?>" style="background:none; border:none; color:#0ea5e9; cursor:pointer; font-size:0.8em; font-weight:bold; padding:0; text-decoration:underline;">Edit Pricing</button>
                                            <div id="fin-edit-<?php echo $sub->id; ?>" class="fin-edit-box" style="display:none; margin-top:8px; background:#f8fafc; padding:10px; border:1px solid #cbd5e1; border-radius:4px;">
                                                <label style="font-size:0.8em; font-weight:bold;">Base Price (AED)</label>
                                                <input type="number" step="0.01" id="fin-price-<?php echo $sub->id; ?>" value="<?php echo esc_attr($base_price); ?>" style="width:100%; padding:4px; margin-bottom:5px; font-size:0.9em;">
                                                <label style="font-size:0.8em; font-weight:bold;">Discount (AED)</label>
                                                <input type="number" step="0.01" id="fin-discount-<?php echo $sub->id; ?>" value="<?php echo esc_attr($discount); ?>" style="width:100%; padding:4px; margin-bottom:8px; font-size:0.9em;">
                                                <button class="save-financials-btn" data-sub-id="<?php echo $sub->id; ?>" style="background:#0284c7; color:#fff; border:none; padding:4px 8px; border-radius:3px; font-size:0.85em; cursor:pointer; width:100%;">Save Financials</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- FOH Remarks Inline Toggle -->
                                <div style="margin-top: 8px; background: #f8fafc; padding: 8px; border-radius: 4px; border: 1px solid #e2e8f0;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <span style="font-size:0.9em; color:#475569;"><strong id="remark-preview-<?php echo $sub->id; ?>"><?php echo $preview_html; ?></strong></span>
                                        <button class="toggle-remark-btn" data-target="remark-box-<?php echo $sub->id; ?>" style="background:none; border:none; color:#0073aa; cursor:pointer; font-size:0.85em; font-weight:bold; padding:0;">Edit</button>
                                    </div>
                                    <div id="remark-box-<?php echo $sub->id; ?>" class="remark-edit-box" style="display:none; margin-top:8px;">
                                        <textarea id="remark-text-<?php echo $sub->id; ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; font-size:0.9em; min-height:60px; box-sizing:border-box;" placeholder="Add specific delivery or kitchen notes here..."><?php echo esc_textarea($remark_text); ?></textarea>
                                        <button class="save-remark-btn" data-sub-id="<?php echo $sub->id; ?>" style="margin-top:4px; background:#46b450; color:#fff; border:none; padding:4px 8px; border-radius:3px; font-size:0.85em; cursor:pointer; width:100%;">Save Note</button>
                                    </div>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <!-- VISUAL PROGRESS BAR & CONDENSED METRICS -->
                                <div style="background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; text-align: left; width: 100%; box-sizing: border-box;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.85em; margin-bottom: 2px;">
                                        <span style="color: #64748b;">Filled: <strong><?php echo $sub->filled; ?>/<?php echo $sub->total_days; ?></strong></span>
                                        <span style="color: #16a34a;">Used: <strong><?php echo $sub->usage; ?></strong></span>
                                    </div>
                                    
                                    <div class="metric-progress-bar">
                                        <div class="metric-progress-fill" style="width: <?php echo $usage_percent; ?>%;"></div>
                                    </div>
                                    
                                    <div style="color: #0369a1; font-size: 1.05em; font-weight: bold; text-align: center; border-top: 1px solid #cbd5e1; padding-top: 6px; margin-top: 4px;">
                                        Balance: <?php echo $sub->balance; ?> Days
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span style="font-size: 0.85em; color: #666;">Started: <?php echo date('M j, Y', strtotime($sub->start_date)); ?></span><br>
                                <form class="ajax-expiry-form" style="display: flex; flex-direction: column; gap: 5px; margin-top: 5px;">
                                    <input type="hidden" name="sub_id" value="<?php echo $sub->id; ?>">
                                    <input type="date" name="new_expiry" value="<?php echo date('Y-m-d', strtotime($sub->expiry_date)); ?>" style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
                                    <button type="submit" class="expiry-btn" style="width: 100%; background: #2271b1; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; transition: background 0.2s;">Update</button>
                                </form>
                            </td>
                            <td style="text-align: center; vertical-align: middle;">
                                <div style="display: flex; flex-direction: column; gap: 6px;">
                                    <?php if($tab_key !== 'inactive'): ?>
                                    <form class="ajax-status-form" style="margin: 0;">
                                        <input type="hidden" name="sub_id" value="<?php echo $sub->id; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $is_paused ? 'active' : 'paused'; ?>">
                                        <button type="submit" class="status-btn" style="width: 100%; background: <?php echo $is_paused ? '#0073aa' : '#dba617'; ?>; color: white; border: none; padding: 8px; border-radius: 4px; font-weight: bold; cursor: pointer; box-sizing: border-box; transition: background 0.2s;">
                                            <?php echo $is_paused ? 'Resume Plan' : 'Pause Plan'; ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <a href="<?php echo site_url('/my-meal-portal/?admin_edit_sub=' . $sub->id); ?>" target="_blank" style="display: block; background: #46b450; color: white; text-decoration: none; padding: 8px; border-radius: 4px; font-weight: bold; box-sizing: border-box;">Edit / View Portal</a>
                                    
                                    <!-- NEW: DEPOSIT WALLET INTERACTIVE PILL -->
                                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #cbd5e1; text-align: left;">
                                        <div style="margin-bottom: 5px;">
                                            <span style="font-weight: bold; color: #334155; font-size: 0.85em;">Bag Status: </span>
                                        </div>
                                        <div class="deposit-pill-wrapper" data-user-id="<?php echo $sub->user_id; ?>">
                                            <span class="deposit-status-pill" style="color: <?php echo $status_color; ?>; font-weight: bold; background: <?php echo $status_bg; ?>; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; display: inline-block; text-align: center; cursor: pointer; border: 1px solid <?php echo $status_color; ?>; transition: 0.2s;" title="Click to Change Status">
                                                <?php echo $status_text; ?> &#9662;
                                            </span>
                                            <select class="deposit-status-select" style="display:none; width: 100%; padding: 4px; font-size: 0.85em; border-radius: 4px; border: 1px solid #cbd5e1; margin-top: 4px;">
                                                <option value="yes" <?php selected($deposit_held, 'yes'); ?>>Held (Waived)</option>
                                                <option value="own_bag" <?php selected($deposit_held, 'own_bag'); ?>>Own Bag (Waived)</option>
                                                <option value="no" <?php selected($deposit_held, 'no'); ?>>Refunded / No Deposit</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <div id="fohPagination" style="margin-top: 20px; display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;"></div>

    </div>

    <script>
    const fohAjaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
    const fohNonce   = '<?php echo wp_create_nonce("cmp_foh_nonce"); ?>';

    document.addEventListener("DOMContentLoaded", function() {
        
        // Expiry Form
        document.querySelectorAll('.ajax-expiry-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = this.querySelector('.expiry-btn');
                const subId = this.querySelector('input[name="sub_id"]').value;
                const newExpiry = this.querySelector('input[name="new_expiry"]').value;

                const originalText = btn.innerText;
                const originalBg = btn.style.background;

                btn.innerText = '...';
                btn.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_update_expiry');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);
                formData.append('new_expiry', newExpiry);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        btn.innerText = '✓ Saved';
                        btn.style.background = '#46b450';
                        setTimeout(() => {
                            btn.innerText = originalText;
                            btn.style.background = originalBg;
                            btn.disabled = false;
                        }, 2000);
                    } else {
                        alert('Error updating expiry.');
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                }).catch(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
            });
        });

        // Status Form
        document.querySelectorAll('.ajax-status-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = this.querySelector('.status-btn');
                const subId = this.querySelector('input[name="sub_id"]').value;
                const statusInput = this.querySelector('input[name="new_status"]');
                const newStatus = statusInput.value;

                btn.disabled = true;
                btn.style.opacity = '0.7';

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_toggle_status');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);
                formData.append('new_status', newStatus);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        if (newStatus === 'active') {
                            btn.innerText = 'Pause Plan';
                            btn.style.background = '#dba617';
                            statusInput.value = 'paused';
                        } else {
                            btn.innerText = 'Resume Plan';
                            btn.style.background = '#0073aa';
                            statusInput.value = 'active';
                        }
                        btn.style.opacity = '1';
                        btn.disabled = false;
                    } else {
                        alert('Error updating status.');
                        btn.style.opacity = '1';
                        btn.disabled = false;
                    }
                }).catch(() => {
                    btn.style.opacity = '1';
                    btn.disabled = false;
                });
            });
        });

        // New Interactive Bag Status Pill
        document.querySelectorAll('.deposit-status-pill').forEach(pill => {
            pill.addEventListener('click', function(e) {
                let wrapper = this.closest('.deposit-pill-wrapper');
                let select = wrapper.querySelector('.deposit-status-select');
                this.style.display = 'none';
                select.style.display = 'block';
                select.focus();
            });
        });

        document.querySelectorAll('.deposit-status-select').forEach(select => {
            select.addEventListener('change', function(e) {
                let wrapper = this.closest('.deposit-pill-wrapper');
                let pill = wrapper.querySelector('.deposit-status-pill');
                let userId = wrapper.getAttribute('data-user-id');
                let newStatus = this.value;

                this.disabled = true;
                pill.innerHTML = 'Updating...';
                pill.style.display = 'inline-block';
                this.style.display = 'none';

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_toggle_deposit');
                formData.append('nonce', fohNonce);
                formData.append('user_id', userId);
                formData.append('new_status', newStatus);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        if (newStatus === 'yes') {
                            pill.innerHTML = 'Bag Deposit Held (Waived) &#9662;';
                            pill.style.background = '#dcfce7'; pill.style.color = '#10b981'; pill.style.borderColor = '#10b981';
                        } else if (newStatus === 'own_bag') {
                            pill.innerHTML = "Customer's Own Bag (Waived) &#9662;";
                            pill.style.background = '#ede9fe'; pill.style.color = '#8b5cf6'; pill.style.borderColor = '#8b5cf6';
                        } else {
                            pill.innerHTML = 'Refunded / No Deposit &#9662;';
                            pill.style.background = '#fee2e2'; pill.style.color = '#dc2626'; pill.style.borderColor = '#dc2626';
                        }
                        
                        // Sync multiple rows dynamically
                        document.querySelectorAll(`.deposit-pill-wrapper[data-user-id="${userId}"]`).forEach(otherWrap => {
                            otherWrap.querySelector('.deposit-status-select').value = newStatus;
                            let otherPill = otherWrap.querySelector('.deposit-status-pill');
                            otherPill.innerHTML = pill.innerHTML;
                            otherPill.style.background = pill.style.background;
                            otherPill.style.color = pill.style.color;
                            otherPill.style.borderColor = pill.style.borderColor;
                        });

                    } else {
                        alert('Error updating status.');
                        pill.innerHTML = 'Error &#9662;';
                    }
                }).catch(() => {
                    alert('Connection error.');
                }).finally(() => {
                    this.disabled = false;
                });
            });
            
            select.addEventListener('blur', function() {
                this.style.display = 'none';
                let wrapper = this.closest('.deposit-pill-wrapper');
                wrapper.querySelector('.deposit-status-pill').style.display = 'inline-block';
            });
        });

        // Edit Financials Logic (Super Admin)
        document.querySelectorAll('.edit-financials-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('data-target');
                const box = document.getElementById(targetId);
                if (box.style.display === 'none') {
                    box.style.display = 'block';
                    this.innerText = 'Close Edit';
                } else {
                    box.style.display = 'none';
                    this.innerText = 'Edit Pricing';
                }
            });
        });

        document.querySelectorAll('.save-financials-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const subId = this.getAttribute('data-sub-id');
                const priceVal = document.getElementById('fin-price-' + subId).value;
                const discountVal = document.getElementById('fin-discount-' + subId).value;
                const originalText = this.innerText;
                
                this.innerText = 'Saving...';
                this.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_update_financials');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);
                formData.append('plan_price', priceVal);
                formData.append('discount_amount', discountVal);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        this.innerText = '✓ Saved';
                        this.style.background = '#0f766e';
                        
                        let p = parseFloat(response.data.price);
                        let d = parseFloat(response.data.discount);
                        let fp = Math.max(0, p - d);
                        
                        let displayHtml = 'Price: AED ' + fp.toFixed(2);
                        if (d > 0 && p > 0) {
                            let pct = Math.round((d / p) * 100);
                            displayHtml += ' <span style="color:#d63638; font-weight:bold; font-size:0.9em;">(-' + pct + '%)</span>';
                        }
                        document.getElementById('price-display-' + subId).innerHTML = displayHtml;

                        setTimeout(() => {
                            this.innerText = 'Save Financials';
                            this.style.background = '#0284c7';
                            this.disabled = false;
                            document.getElementById('fin-edit-' + subId).style.display = 'none';
                            document.querySelector('.edit-financials-btn[data-target="fin-edit-' + subId + '"]').innerText = 'Edit Pricing';
                        }, 1000);
                    } else {
                        alert('Error saving financials.');
                        this.innerText = originalText;
                        this.disabled = false;
                    }
                }).catch(() => {
                    this.innerText = originalText;
                    this.disabled = false;
                });
            });
        });

        // Remarks Toggle Logic
        document.querySelectorAll('.toggle-remark-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('data-target');
                const box = document.getElementById(targetId);
                if (box.style.display === 'none') {
                    box.style.display = 'block';
                    this.innerText = 'Close';
                } else {
                    box.style.display = 'none';
                    this.innerText = 'Edit';
                }
            });
        });

        // Remarks Saving Logic
        document.querySelectorAll('.save-remark-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const subId = this.getAttribute('data-sub-id');
                const textVal = document.getElementById('remark-text-' + subId).value;
                const originalText = this.innerText;
                
                this.innerText = 'Saving...';
                this.disabled = true;

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_foh_save_remark');
                formData.append('nonce', fohNonce);
                formData.append('sub_id', subId);
                formData.append('remark', textVal);

                fetch(fohAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        this.innerText = '✓ Saved';
                        this.style.background = '#0f766e';
                        
                        let displayTxt = textVal.trim();
                        let newPreview = displayTxt.length > 0 
                            ? (displayTxt.length > 32 ? displayTxt.substring(0, 32).replace(/</g, "&lt;").replace(/>/g, "&gt;") + '...' : displayTxt.replace(/</g, "&lt;").replace(/>/g, "&gt;")) 
                            : '<span style="color:#94a3b8; font-style:italic; font-weight:normal;">No remarks</span>';
                        
                        document.getElementById('remark-preview-' + subId).innerHTML = newPreview;

                        setTimeout(() => {
                            this.innerText = 'Save Note';
                            this.style.background = '#46b450';
                            this.disabled = false;
                            
                            document.getElementById('remark-box-' + subId).style.display = 'none';
                            document.querySelector('.toggle-remark-btn[data-target="remark-box-' + subId + '"]').innerText = 'Edit';
                        }, 1000);
                    } else {
                        alert('Error saving note.');
                        this.innerText = originalText;
                        this.disabled = false;
                    }
                }).catch(() => {
                    this.innerText = originalText;
                    this.disabled = false;
                });
            });
        });

        // Filters
        document.getElementById('fohSearch').addEventListener('keyup', function() {
            currentPage = 1;
            renderTable();
        });
        document.getElementById('fohPlanFilter').addEventListener('change', function() {
            currentPage = 1;
            renderTable();
        });
        renderTable();
    });

    const rowsPerPage = 10;
    let currentPage = 1;
    let currentTab = 'active-tab';

    function switchTab(evt, tabId) {
        var contents = document.getElementsByClassName("foh-tab-content");
        for (var i = 0; i < contents.length; i++) contents[i].classList.remove("active");
        var btns = document.getElementsByClassName("foh-tab-btn");
        for (var i = 0; i < btns.length; i++) btns[i].classList.remove("active");
        document.getElementById(tabId).classList.add("active");
        evt.currentTarget.classList.add("active");
        
        currentTab = tabId;
        currentPage = 1;
        renderTable();
    }

    function renderTable() {
        const query = document.getElementById('fohSearch').value.toLowerCase();
        const plan = document.getElementById('fohPlanFilter').value;
        
        const allRows = Array.from(document.querySelectorAll('#' + currentTab + ' .foh-row'));
        
        const filtered = allRows.filter(row => {
            const searchData = row.getAttribute('data-search');
            const planData = row.getAttribute('data-plan');
            const matchesSearch = query === '' || searchData.includes(query);
            const matchesPlan = plan === '' || planData === plan;
            return matchesSearch && matchesPlan;
        });

        allRows.forEach(row => row.style.display = 'none');

        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        filtered.slice(start, end).forEach(row => row.style.display = '');

        let noResultsRow = document.querySelector('#' + currentTab + ' .no-search-results');
        if (!noResultsRow) {
            noResultsRow = document.createElement('tr');
            noResultsRow.className = 'no-search-results';
            noResultsRow.innerHTML = '<td colspan="6" style="text-align:center; padding:30px; color:#666;">No customers match your search criteria.</td>';
            document.querySelector('#' + currentTab + ' tbody').appendChild(noResultsRow);
        }
        
        if (filtered.length === 0 && allRows.length > 0) {
            noResultsRow.style.display = '';
        } else {
            noResultsRow.style.display = 'none';
        }

        renderPagination(filtered.length);
    }

    function renderPagination(totalItems) {
        const paginationContainer = document.getElementById('fohPagination');
        paginationContainer.innerHTML = '';
        
        const totalPages = Math.ceil(totalItems / rowsPerPage);
        if(totalPages <= 1) return;

        for(let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('button');
            btn.innerText = i;
            btn.style.padding = "6px 14px";
            btn.style.border = "1px solid #ccc";
            btn.style.background = (i === currentPage) ? "#0073aa" : "#fff";
            btn.style.color = (i === currentPage) ? "#fff" : "#333";
            btn.style.cursor = "pointer";
            btn.style.borderRadius = "4px";
            btn.style.margin = "0 2px";
            btn.style.fontWeight = "bold";
            
            btn.onclick = function(e) {
                e.preventDefault();
                currentPage = i;
                renderTable();
            };
            paginationContainer.appendChild(btn);
        }
    }
    </script>
    <?php
    return ob_get_clean();
}
