<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. AJAX HANDLER
// ==========================================
add_action('wp_ajax_cmp_save_daily_log', 'cmp_ajax_save_daily_log');
function cmp_ajax_save_daily_log() {
    check_ajax_referer('cmp_portal_nonce', 'nonce');
    global $wpdb;
    date_default_timezone_set('Asia/Dubai');
    
    if (!is_user_logged_in()) wp_send_json_error('Not logged in.');

    $log_id       = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
    $sub_id       = intval($_POST['sub_id']);
    $target_date  = sanitize_text_field($_POST['date']);
    $is_admin     = current_user_can('manage_options') || current_user_can('foh_manager');

    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_subs WHERE id = %d", $sub_id));
    if (!$sub) wp_send_json_error('Subscription not found.');

    $data = array(
        'user_id'         => $sub->user_id,
        'subscription_id' => $sub_id,
        'target_date'     => $target_date,
        'is_chefs_choice' => isset($_POST['chefs_choice']) ? intval($_POST['chefs_choice']) : 0,
        'breakfast_id'    => !empty($_POST['breakfast']) ? intval($_POST['breakfast']) : null,
        'lunch_id'        => !empty($_POST['lunch']) ? intval($_POST['lunch']) : null,
        'dinner_id'       => !empty($_POST['dinner']) ? intval($_POST['dinner']) : null,
        'snack_1_id'      => !empty($_POST['snack_1']) ? intval($_POST['snack_1']) : null,
        'snack_2_id'      => !empty($_POST['snack_2']) ? intval($_POST['snack_2']) : null,
        'juice_1_id'      => !empty($_POST['juice_1']) ? intval($_POST['juice_1']) : null,
        'juice_2_id'      => !empty($_POST['juice_2']) ? intval($_POST['juice_2']) : null,
        'juice_3_id'      => !empty($_POST['juice_3']) ? intval($_POST['juice_3']) : null,
        'is_locked'       => 1
    );

    if ($log_id > 0) {
        $wpdb->update($table_logs, $data, array('id' => $log_id));
        wp_send_json_success(array('msg' => 'Updated'));
    } else {
        $wpdb->insert($table_logs, $data);
        wp_send_json_success(array('new_log_id' => $wpdb->insert_id));
    }
}

// ==========================================
// 2. DASHBOARD RENDERER
// ==========================================
add_shortcode( 'meal_customer_portal', 'cmp_render_customer_portal' );
function cmp_render_customer_portal() {
    date_default_timezone_set('Asia/Dubai');

    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-login', 'label_username' => __('Email Address'), 'label_password' => __('Password'));
        $custom_css = '
        <style>
            #cmp-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; }
            #cmp-login input[type="text"], #cmp-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
            #cmp-login .login-submit input[type="submit"] { width: 100%; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; box-sizing: border-box; }
            #cmp-login .login-remember { text-align: left; margin-bottom: 15px; }
        </style>';
        return $custom_css . '<div style="max-width:400px; margin:50px auto; padding:30px; background:#f8f9fa; border-radius:8px; border:1px solid #ddd; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <h2 style="text-align:center; margin-top:0; color:#222;">Dashboard Login</h2>
                    <p style="text-align:center; color:#666; margin-bottom:20px;">Please log in to manage your plan.</p>' 
                    . wp_login_form( $login_args ) . 
                '</div>';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $current_user = wp_get_current_user();
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods = $wpdb->prefix . 'cmp_foods';
    
    $dynamic_cutoff_hour = intval( get_option('cmp_cutoff_time', '11') );
    $label_chefs_choice = get_option('cmp_label_chefs_choice', "Chef's Choice");
    $blackout_string = get_option('cmp_blackout_dates', '');
    $blackout_dates_array = !empty($blackout_string) ? explode(',', str_replace(' ', '', $blackout_string)) : array();

    $raw_wa = get_option('cmp_whatsapp_number', '');
    $clean_wa = preg_replace('/[^0-9]/', '', $raw_wa);

    $is_admin_override = isset($_GET['admin_edit_sub']) && (current_user_can('manage_options') || current_user_can('foh_manager'));
    
    if ($is_admin_override) {
        $raw_subs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", intval($_GET['admin_edit_sub'])) );
    } else {
        $raw_subs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d AND status IN ('active','paused') ORDER BY id DESC", $user_id) );
    }

    $subs = array();
    $today = date('Y-m-d H:i:s');
    if ($raw_subs) {
        foreach ($raw_subs as $sub) {
            if ($is_admin_override) {
                $subs[] = $sub; 
            } else {
                $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
                if ($usage_days < $sub->total_days && $sub->expiry_date >= $today) {
                    $subs[] = $sub; 
                }
            }
        }
    }

    if ( empty($subs) ) {
        return '<div style="padding:40px; text-align:center; background:#fff; border:1px solid #ddd; border-radius:8px;">
                    <h3 style="color:#0073aa;">No Active Plans Found</h3>
                    <p style="color:#666;">It looks like your plans have expired or been completed.</p>
                    <br><a href="'.wp_logout_url(get_permalink()).'" style="color:#dc3232; font-weight:bold;">Log Out</a>
                </div>';
    }

    $foods = $wpdb->get_results("SELECT * FROM $table_foods WHERE is_active = 1 ORDER BY category_name, food_name");
    $foods_map = array(); foreach ($foods as $food) { $foods_map[$food->id] = $food; }

    $current_hour = (int) date('H');
    if ( $current_hour >= $dynamic_cutoff_hour && !$is_admin_override ) {
        $global_min_date = date( 'Y-m-d', strtotime('+2 days') );
        $note_text = "Note: It is past " . str_pad($dynamic_cutoff_hour, 2, '0', STR_PAD_LEFT) . ":00 GST. Meal selections are now locked for tomorrow.";
        $note_color = "#d63638"; 
    } else {
        $global_min_date = date( 'Y-m-d', strtotime('+1 day') );
        $note_text = "Note: Please make selections before " . str_pad($dynamic_cutoff_hour, 2, '0', STR_PAD_LEFT) . ":00 GST the day prior to delivery.";
        $note_color = "#0073aa"; 
    }

    ob_start();
    ?>
    <style>
        .cmp-dashboard-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; overflow-x: hidden; box-sizing: border-box; position: relative; }
        .cmp-tab-nav { display: flex; background: #fff; border-bottom: 2px solid #ddd; padding: 0 20px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .cmp-tab-btn { padding: 15px 25px; border: none; background: none; font-weight: bold; cursor: pointer; border-bottom: 3px solid transparent; color: #666; white-space: nowrap; }
        .cmp-tab-btn.active { color: #0073aa; border-bottom-color: #0073aa; }
        .cmp-tab-content { display: none; padding: 25px 0; }
        .cmp-tab-content.active { display: block; }
        
        .cmp-table-responsive { overflow: hidden; background: #fff; border: 1px solid #ddd; border-radius: 8px; width: 100%; }
        .cmp-table { width: 100%; border-collapse: collapse; font-size: 0.9em; table-layout: fixed; }
        .cmp-table th { padding: 12px 5px; border-bottom: 2px solid #ddd; background: #f1f1f1; text-align: center; vertical-align: middle; white-space: normal; overflow: visible; word-wrap: break-word; }
        .cmp-table td { padding: 10px 5px; border-bottom: 1px solid #eee; vertical-align: middle; box-sizing: border-box; }
        
        .cmp-mobile-label { display: none; }
        .macro-mobile { display: none; }
        .macro-desktop { display: inline-block; white-space: nowrap; line-height: 1.4; font-size: 0.85em; }

        .cmp-wa-float {
            position: fixed; bottom: 30px; right: 30px; background-color: #25d366; color: white; border-radius: 50px;
            padding: 12px 20px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 12px rgba(37, 211, 102, 0.4);
            text-decoration: none; font-weight: bold; font-size: 16px; z-index: 9999; transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .cmp-wa-float:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(37, 211, 102, 0.5); color: white; }

        @media (min-width: 769px) {
            .cmp-table select { width: 100%; padding: 8px; box-sizing: border-box; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; height: 42px; }
            .cmp-table input[type="date"] { width: 100% !important; padding: 6px !important; box-sizing: border-box !important; height: 42px !important; }
            .cmp-table th:nth-child(1) { width: 55px; }  
            .cmp-table th:nth-child(2) { width: 145px; } 
            .cmp-table th:nth-child(3) { width: 105px; } 
            .cmp-table th:last-child { width: 95px; } 
            .cmp-table th:nth-last-child(2) { width: 85px; } 
            .cmp-table th:nth-last-child(3) { width: 155px; } 
            .cmp-stacked-snack { margin-bottom: 8px; }
            .cmp-save-row { padding: 10px 5px !important; height: 42px; }
        }

        @media (max-width: 768px) {
            .cmp-dashboard-wrap { padding: 0 5px; }
            .cmp-table-responsive { overflow: visible !important; border: none !important; }
            .cmp-table { width: 100% !important; display: block !important; } 
            .cmp-table tbody, .cmp-table tr, .cmp-table td { display: block !important; width: 100% !important; box-sizing: border-box !important; }
            .cmp-table thead { display: none !important; }
            .cmp-table td { border: none !important; padding: 10px 0 !important; text-align: left !important; overflow: hidden; }
            
            .cmp-table tr {
                margin-bottom: 25px !important; border-radius: 12px !important; padding: 15px 20px 20px 20px !important;
                box-shadow: 0 4px 10px rgba(0,0,0,0.05) !important; background: #fff !important; border: 2px solid #cbd5e1 !important;
            }
            
            .cmp-table td:nth-child(1) {
                border-radius: 8px !important; text-align: left !important; font-size: 1.3em !important; margin-bottom: 15px !important;
                padding: 15px 20px !important; cursor: pointer; position: relative; transition: all 0.2s ease;
            }
            
            .cmp-table td:nth-child(1)::after { content: '\25BC'; position: absolute; right: 20px; top: 50%; transform: translateY(-50%); font-size: 16px; color: inherit; }
            .cmp-table tr.is-open td:nth-child(1)::after { content: '\25B2'; }
            .cmp-table tr:not(.is-open) td:not(:first-child) { display: none !important; }

            .status-pending { border: 2px solid #fde68a !important; }
            .status-pending td:nth-child(1) { background: #fffbeb !important; color: #b45309 !important; border: 1px solid #fde68a !important; }
            .status-pending td:nth-child(1)::before { content: 'Pending • '; font-size: 0.7em; text-transform: uppercase; letter-spacing: 1px; display: block; opacity: 0.8;}

            .status-saved { border: 2px solid #bbf7d0 !important; }
            .status-saved td:nth-child(1) { background: #f0fdf4 !important; color: #166534 !important; border: 1px solid #bbf7d0 !important; }
            .status-saved td:nth-child(1)::before { content: 'Saved ✓ • '; font-size: 0.7em; text-transform: uppercase; letter-spacing: 1px; display: block; opacity: 0.8;}

            .status-void { border: 2px solid #fecdd3 !important; opacity: 0.75; }
            .status-void td:nth-child(1) { background: #fff1f2 !important; color: #9f1239 !important; border: 1px solid #fecdd3 !important; }

            .cmp-mobile-label { display: block !important; font-weight: bold !important; color: #475569 !important; margin-bottom: 8px !important; font-size: 1.05em !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; }

            .cmp-table select, .cmp-table input[type="date"] {
                -webkit-appearance: none !important; appearance: none !important; width: 100% !important; min-height: 55px !important; padding: 15px 40px 15px 20px !important; font-size: 16px !important; 
                background-color: #f1f5f9 !important; border: 2px solid #cbd5e1 !important; border-radius: 10px !important; box-shadow: 0 3px 0 #cbd5e1 !important;
                font-weight: bold !important; color: #0f172a !important; margin-bottom: 10px !important; box-sizing: border-box !important;
                background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e") !important;
                background-repeat: no-repeat !important; background-position: right 15px center !important; background-size: 18px !important;
            }
            .cmp-table select:active { transform: translateY(3px) !important; box-shadow: 0 0 0 #cbd5e1 !important; }
            .cmp-table input[type="date"] { padding-right: 15px !important; background-image: none !important; }

            .cmp-chefs-choice { transform: scale(1.8) !important; margin: 5px 15px 5px 5px !important; }
            
            .macro-desktop { display: none !important; }
            .macro-mobile { display: block !important; width: 100%; }
            .cmp-macro-display { display: block !important; margin-top: 10px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1; font-size: 1.1em !important; }
            .mob-grid { display: flex; flex-wrap: wrap; justify-content: space-between; }
            .mob-grid div { width: 48%; margin-bottom: 5px; }

            .cmp-save-row { width: 100% !important; padding: 18px !important; font-size: 20px !important; border-radius: 10px !important; margin-top: 10px !important; }
            .cmp-wa-float { bottom: 20px; right: 20px; padding: 12px; border-radius: 50%; }
            .cmp-wa-text { display: none; }
        }
    </style>

    <div class="cmp-dashboard-wrap">
        
        <div style="background: #222; color: #fff; padding: 25px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="margin:0; color:#fff;">Dashboard</h1>
                <p style="margin:5px 0 0 0; color:#ccc;">Welcome back, <?php echo esc_html(wp_get_current_user()->display_name); ?></p>
            </div>
            <a href="<?php echo wp_logout_url(get_permalink()); ?>" style="background:#dc3232; color:#fff; text-decoration:none; padding:10px 20px; border-radius:4px; font-weight:bold;">Log Out</a>
        </div>

        <div class="cmp-tab-nav">
            <?php 
            $first_tab = true;
            foreach ($subs as $sub) { 
                $active_class = $first_tab ? 'active' : '';
                echo '<button class="cmp-tab-btn ' . $active_class . '" onclick="switchTab(event, \'plan-tab-' . esc_attr($sub->id) . '\')">' . esc_html($sub->plan_name) . '</button>';
                $first_tab = false;
            }
            ?>
        </div>

        <?php 
        $first_content = true;
        foreach ($subs as $sub): 
            $is_juice = ($sub->allowed_categories === 'Juices');
            $tab_id = 'plan-tab-' . $sub->id;
            $active_class = $first_content ? 'active' : '';
            $first_content = false;
            
            $order = wc_get_order($sub->wc_order_id);
            
            // ===============================================
            // TRIPLE-CATCH BULLETPROOF DATA RETRIEVAL
            // ===============================================
            $timing = !empty($sub->delivery_timing) ? $sub->delivery_timing : '';
            if (empty($timing) && $order) $timing = $order->get_meta('_cmp_delivery_timing') ?: $order->get_meta('delivery_timing');
            if (empty($timing)) $timing = get_user_meta($user_id, 'delivery_timing', true);

            $time_slot = !empty($sub->time_slot) ? $sub->time_slot : '';
            if (empty($time_slot) && $order) $time_slot = $order->get_meta('_cmp_time_slot') ?: $order->get_meta('time_slot');
            if (empty($time_slot)) $time_slot = get_user_meta($user_id, 'time_slot', true);

            $method = !empty($sub->delivery_method) ? $sub->delivery_method : '';
            if (empty($method) && $order) $method = $order->get_meta('_cmp_logistics_method') ?: $order->get_meta('delivery_method');
            if (empty($method)) $method = get_user_meta($user_id, 'delivery_method', true);

            $pickup = !empty($sub->pickup_location) ? $sub->pickup_location : '';
            if (empty($pickup) && $order) $pickup = $order->get_meta('_cmp_pickup_location') ?: $order->get_meta('pickup_location');
            if (empty($pickup)) $pickup = get_user_meta($user_id, 'pickup_location', true);

            $allergies = !empty($sub->allergies) ? $sub->allergies : '';
            if (empty($allergies) && $order) $allergies = $order->get_customer_note();
            if (empty($allergies)) $allergies = get_user_meta($user_id, 'allergies', true);

            $address = 'Address not provided';
            if ($order) {
                $addr_parts = array_filter([$order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city()]);
                if (empty($addr_parts)) $addr_parts = array_filter([$order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city()]);
                if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
            }
            if ($address === 'Address not provided') {
                $addr1 = get_user_meta($user_id, 'billing_address_1', true);
                $addr2 = get_user_meta($user_id, 'billing_address_2', true);
                $city = get_user_meta($user_id, 'billing_city', true);
                $addr_parts = array_filter([$addr1, $addr2, $city]);
                if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
            }
            
            $phone = $order ? $order->get_billing_phone() : get_user_meta($user_id, 'billing_phone', true);
            $email = $order ? $order->get_billing_email() : $current_user->user_email;

            // Visual Formatting
            $method_display = $method ?: 'N/A';
            if ($method === 'Pickup' && !empty($pickup)) { $method_display .= ' (' . esc_html($pickup) . ')'; }
            $timing_display = $timing ?: 'N/A';
            $time_slot_display = $time_slot ?: 'N/A';

            $allowed_cats = explode(',', $sub->allowed_categories);
            $meal_count = count($allowed_cats);
            $snack_count = ($meal_count >= 2) ? 2 : 1;
            $is_paused = ($sub->status === 'paused');
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="cmp-tab-content <?php echo $active_class; ?> cmp-portal-container" data-sub-id="<?php echo $sub->id; ?>" data-total-days="<?php echo $sub->total_days; ?>">
            
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:25px; margin-bottom:20px; box-shadow:0 2px 4px rgba(0,0,0,0.02); display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px;">
                
                <div style="flex:1; min-width:250px;">
                    <h3 style="margin:0 0 10px 0; color:#0073aa; font-size:1.3em;">Account Details</h3>
                    <p style="margin:0 0 8px 0; color:#334155;"><strong>Name:</strong> <?php echo $order ? esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : esc_html($current_user->display_name); ?></p>
                    <p style="margin:0 0 8px 0; color:#334155;"><strong>Email:</strong> <?php echo esc_html($email); ?></p>
                    <p style="margin:0 0 8px 0; color:#334155;"><strong>Phone:</strong> <?php echo esc_html($phone ?: 'N/A'); ?></p>
                    <p style="margin:0; color:#334155;"><strong>Address:</strong> <?php echo esc_html($address); ?></p>
                </div>
                
                <div style="flex:1; min-width:250px; background:#f8fafc; padding:15px; border-radius:6px; border:1px solid #f1f5f9;">
                    <h3 style="margin:0 0 12px 0; color:#0f172a; font-size:1.1em;">Active Logistics</h3>
                    <p style="margin:0 0 8px 0; color:#475569;"><strong>Method:</strong> <?php echo esc_html($method_display); ?></p>
                    <p style="margin:0 0 8px 0; color:#475569;"><strong>Receive By:</strong> <?php echo esc_html($timing_display); ?></p>
                    <p style="margin:0 0 8px 0; color:#475569;"><strong>Time Slot:</strong> <?php echo esc_html($time_slot_display); ?></p>
                    <div style="margin-top:15px; padding-top:15px; border-top:1px dashed #cbd5e1;">
                        <p style="margin:0; color:#b45309;"><strong>Allergies:</strong> <?php echo !empty($allergies) ? esc_html($allergies) : 'No Allergies Recorded'; ?></p>
                    </div>
                </div>

                <div style="width: 100%; border-top: 1px solid #eee; margin-top: 10px; padding-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <p style="color: <?php echo $note_color; ?>; font-size: 0.9em; font-weight: bold; margin: 0;"><em><?php echo $note_text; ?></em></p>
                    <a href="<?php echo $order ? $order->get_view_order_url() : '#'; ?>" target="_blank" style="background:#e2e8f0; color:#334155; padding:8px 15px; border-radius:4px; font-weight:bold; text-decoration:none; font-size:0.9em;">Download Receipt</a>
                </div>
            </div>

            <div class="cmp-table-responsive">
                <table class="cmp-table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Date</th>
                            <th><?php echo esc_html($label_chefs_choice); ?></th>
                            <?php if(!$is_juice): ?>
                                <?php if(in_array('Breakfast', $allowed_cats)) echo '<th>Breakfast</th>'; ?>
                                <?php if(in_array('Lunch', $allowed_cats)) echo '<th>Lunch</th>'; ?>
                                <?php if(in_array('Dinner', $allowed_cats)) echo '<th>Dinner</th>'; ?>
                                <?php if($snack_count > 0) echo '<th>Snacks</th>'; ?>
                                <th>Macros (Daily)</th>
                            <?php else: ?>
                                <th>Juice 1</th>
                                <th>Juice 2</th>
                                <th>Juice 3</th>
                            <?php endif; ?>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $saved_logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_logs WHERE subscription_id = %d ORDER BY target_date ASC, id ASC", $sub->id));
                        
                        $void_count = 0;
                        foreach ($saved_logs as $log) {
                            if (in_array($log->delivery_result, array('Cancelled', 'Returned'))) $void_count++;
                        }
                        $display_days = intval($sub->total_days) + $void_count;
                        $active_min_date = $global_min_date;

                        for ($i = 1; $i <= $display_days; $i++) {
                            $log = isset($saved_logs[$i-1]) ? $saved_logs[$i-1] : null;
                            $log_id = $log ? $log->id : 0;
                            $is_void = ($log && in_array($log->delivery_result, array('Cancelled', 'Returned')));
                            $is_locked = ($log && $log->is_locked);
                            $saved_chefs_choice = ($log && $log->is_chefs_choice) ? true : false;
                            
                            $input_disabled = ($is_void || (!$is_admin_override && ($is_locked || $is_paused))) ? 'disabled' : '';
                            $meal_select_disabled = ($input_disabled || $saved_chefs_choice) ? 'disabled' : '';
                            
                            $row_status_class = 'status-pending';
                            if ($is_void) { $row_status_class = 'status-void row-void'; } 
                            elseif ($log_id > 0) { $row_status_class = 'status-saved'; }
                            
                            if ($log && !$is_void) {
                                $next_day_calc = date('Y-m-d', strtotime('+1 day', strtotime($log->target_date)));
                                if ($next_day_calc > $active_min_date) {
                                    $active_min_date = $next_day_calc;
                                }
                            }

                            $macros_desk = '<strong>Cal:</strong> 0<br><strong>Fat:</strong> 0g<br><strong>Carb:</strong> 0g<br><strong>Pro:</strong> 0g';
                            $macros_mob = '<div class="mob-grid"><div><strong>Cal:</strong> 0</div><div><strong>Fat:</strong> 0g</div><div><strong>Carb:</strong> 0g</div><div><strong>Pro:</strong> 0g</div></div>';
                            
                            if ($log && !$is_juice) {
                                if ($saved_chefs_choice) {
                                    $macros_desk = esc_html($label_chefs_choice);
                                    $macros_mob = esc_html($label_chefs_choice);
                                } else {
                                    $cal=0; $fat=0; $carbs=0; $pro=0;
                                    $meal_ids = array($log->breakfast_id, $log->lunch_id, $log->dinner_id, $log->snack_1_id, $log->snack_2_id);
                                    foreach ($meal_ids as $m_id) {
                                        if ($m_id && isset($foods_map[$m_id])) {
                                            $cal += floatval($foods_map[$m_id]->calories);
                                            $fat += floatval($foods_map[$m_id]->total_fat);
                                            $carbs += floatval($foods_map[$m_id]->carbohydrates);
                                            $pro += floatval($foods_map[$m_id]->protein);
                                        }
                                    }
                                    $macros_desk = "<strong>Cal:</strong> {$cal}<br><strong>Fat:</strong> {$fat}g<br><strong>Carb:</strong> {$carbs}g<br><strong>Pro:</strong> {$pro}g";
                                    $macros_mob = '<div class="mob-grid"><div><strong>Cal:</strong> '.$cal.'</div><div><strong>Fat:</strong> '.$fat.'g</div><div><strong>Carb:</strong> '.$carbs.'g</div><div><strong>Pro:</strong> '.$pro.'g</div></div>';
                                }
                            }
                        ?>
                            <tr class="cmp-day-row <?php echo $row_status_class; ?>">
                                <td>
                                    <strong>Day <?php echo $i; ?></strong>
                                </td>
                                <td>
                                    <input type="date" class="cmp-date-picker" data-row="<?php echo $i; ?>" min="<?php echo esc_attr($active_min_date); ?>" value="<?php echo $log ? esc_attr($log->target_date) : ''; ?>" <?php echo $input_disabled; ?>>
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox" class="cmp-chefs-choice" data-row="<?php echo $i; ?>" <?php echo $input_disabled; ?> <?php if($saved_chefs_choice) echo 'checked'; ?>>
                                </td>
                                <?php if(!$is_juice): ?>
                                    <?php foreach(['Breakfast','Lunch','Dinner'] as $cat): if(in_array($cat, $allowed_cats)): ?>
                                        <td>
                                            <select class="cmp-meal-select" data-cat="<?php echo $cat; ?>" data-row="<?php echo $i; ?>" <?php echo $meal_select_disabled; ?>>
                                                <option value="">- Select <?php echo esc_html($cat); ?> -</option>
                                                <?php foreach($foods as $f) if($f->category_name == $cat) {
                                                    $sel = ($log && $log->{strtolower($cat).'_id'} == $f->id) ? 'selected' : '';
                                                    echo '<option value="'.esc_attr($f->id).'" data-cal="'.$f->calories.'" data-fat="'.$f->total_fat.'" data-carbs="'.$f->carbohydrates.'" data-pro="'.$f->protein.'" '.$sel.'>'.esc_html($f->food_name).'</option>';
                                                } ?>
                                            </select>
                                        </td>
                                    <?php endif; endforeach; ?>
                                    
                                    <?php if($snack_count > 0): ?>
                                    <td>
                                        <select class="cmp-meal-select cmp-snack-1 <?php echo ($snack_count == 2) ? 'cmp-stacked-snack' : ''; ?>" data-row="<?php echo $i; ?>" <?php echo $meal_select_disabled; ?>>
                                            <option value="">- Select Snack 1 -</option>
                                            <?php foreach($foods as $f) if($f->category_name == 'Snacks') {
                                                $sel = ($log && $log->snack_1_id == $f->id) ? 'selected' : '';
                                                echo '<option value="'.esc_attr($f->id).'" data-cal="'.$f->calories.'" data-fat="'.$f->total_fat.'" data-carbs="'.$f->carbohydrates.'" data-pro="'.$f->protein.'" '.$sel.'>'.esc_html($f->food_name).'</option>';
                                            } ?>
                                        </select>
                                        <?php if($snack_count == 2): ?>
                                        <select class="cmp-meal-select cmp-snack-2" data-row="<?php echo $i; ?>" <?php echo $meal_select_disabled; ?>>
                                            <option value="">- Select Snack 2 -</option>
                                            <?php foreach($foods as $f) if($f->category_name == 'Snacks') {
                                                $sel = ($log && $log->snack_2_id == $f->id) ? 'selected' : '';
                                                echo '<option value="'.esc_attr($f->id).'" data-cal="'.$f->calories.'" data-fat="'.$f->total_fat.'" data-carbs="'.$f->carbohydrates.'" data-pro="'.$f->protein.'" '.$sel.'>'.esc_html($f->food_name).'</option>';
                                            } ?>
                                        </select>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    
                                    <td>
                                        <span class="cmp-macro-display" id="macros-row-<?php echo $i; ?>">
                                            <span class="macro-desktop"><?php echo $macros_desk; ?></span>
                                            <span class="macro-mobile"><?php echo $macros_mob; ?></span>
                                        </span>
                                    </td>

                                <?php else: ?>
                                    <?php for($j=1; $j<=3; $j++): ?>
                                        <td>
                                            <select class="cmp-juice-<?php echo $j; ?>" <?php echo $input_disabled; ?> style="width:100%; padding:6px; box-sizing: border-box;">
                                                <option value="">- Select Juice <?php echo $j; ?> -</option>
                                                <?php foreach($foods as $f) if($f->category_name == 'Juices') {
                                                    $sel = ($log && $log->{'juice_'.$j.'_id'} == $f->id) ? 'selected' : '';
                                                    echo '<option value="'.esc_attr($f->id).'" '.$sel.'>'.esc_html($f->food_name).'</option>';
                                                } ?>
                                            </select>
                                        </td>
                                    <?php endfor; ?>
                                <?php endif; ?>
                                
                                <td style="text-align:center;">
                                    <?php if(!$is_locked && !$is_void): ?>
                                        <button class="cmp-save-row" data-sub-id="<?php echo $sub->id; ?>" data-log-id="<?php echo $log_id; ?>" data-row="<?php echo $i; ?>" style="background:#0073aa; color:#fff; border:none; font-weight:bold; cursor:pointer;">Save</button>
                                    <?php else: ?>
                                        <button class="cmp-save-row" data-sub-id="<?php echo $sub->id; ?>" data-log-id="<?php echo $log_id; ?>" data-row="<?php echo $i; ?>" style="background:#46b450; color:#fff; border:none; font-weight:bold; cursor:pointer;" <?php if(!$is_admin_override) echo 'disabled'; ?>><?php echo $is_admin_override ? 'Update' : 'Saved'; ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (!empty($clean_wa)): ?>
        <a href="https://wa.me/<?php echo esc_attr($clean_wa); ?>?text=Hi,%20I%20need%20help%20with%20my%20meal%20plan." target="_blank" class="cmp-wa-float">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
            <span class="cmp-wa-text">Support</span>
        </a>
        <?php endif; ?>

    </div>

    <script type="text/javascript">
    function switchTab(evt, tabId) {
        var contents = document.getElementsByClassName("cmp-tab-content");
        for (var i = 0; i < contents.length; i++) contents[i].classList.remove("active");
        var btns = document.getElementsByClassName("cmp-tab-btn");
        for (var i = 0; i < btns.length; i++) btns[i].classList.remove("active");
        document.getElementById(tabId).classList.add("active");
        evt.currentTarget.classList.add("active");
    }

    jQuery(document).ready(function($) {
        var isAdminOverride = <?php echo $is_admin_override ? 'true' : 'false'; ?>;
        var chefsChoiceLabel = "<?php echo esc_js($label_chefs_choice); ?>";
        var blackoutDates = <?php echo json_encode($blackout_dates_array); ?>;

        $('.cmp-day-row td:first-child').on('click', function() {
            if ($(window).width() <= 768) {
                $(this).parent('tr').toggleClass('is-open');
            }
        });

        setTimeout(function() {
            if ($(window).width() <= 768) {
                var $firstPending = $('.cmp-tab-content.active .status-pending').first();
                if ($firstPending.length) {
                    $firstPending.addClass('is-open');
                    $('html, body').animate({
                        scrollTop: $firstPending.offset().top - 80
                    }, 800);
                }
            }
        }, 300);

        $('.cmp-table').each(function() {
            var $table = $(this);
            var headers = [];
            $table.find('thead th').each(function() { headers.push($(this).text().trim()); });
            $table.find('tbody tr').each(function() {
                $(this).find('td').each(function(index) {
                    if (index !== 0 && headers[index] && $(this).find('.cmp-mobile-label').length === 0) {
                        $(this).prepend('<span class="cmp-mobile-label">' + headers[index] + '</span>');
                    }
                });
            });
        });

        function calculateMacros(rowElement) {
            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
            var macroDisplay = rowElement.find('.cmp-macro-display');
            
            if (isChefsChoice) {
                macroDisplay.html('<span class="macro-desktop">' + chefsChoiceLabel + '</span><span class="macro-mobile">' + chefsChoiceLabel + '</span>');
                return;
            }
            
            var cal=0, fat=0, carbs=0, pro=0;
            rowElement.find('select.cmp-meal-select').each(function() {
                var selected = $(this).find('option:selected');
                if (selected.val() !== "") {
                    cal += parseFloat(selected.data('cal')) || 0;
                    fat += parseFloat(selected.data('fat')) || 0;
                    carbs += parseFloat(selected.data('carbs')) || 0;
                    pro += parseFloat(selected.data('pro')) || 0;
                }
            });
            
            var deskHtml = '<strong>Cal:</strong> ' + cal + '<br><strong>Fat:</strong> ' + fat + 'g<br><strong>Carb:</strong> ' + carbs + 'g<br><strong>Pro:</strong> ' + pro + 'g';
            var mobHtml = '<div class="mob-grid"><div><strong>Cal:</strong> '+cal+'</div><div><strong>Fat:</strong> '+fat+'g</div><div><strong>Carb:</strong> '+carbs+'g</div><div><strong>Pro:</strong> '+pro+'g</div></div>';
            
            macroDisplay.html('<span class="macro-desktop">' + deskHtml + '</span><span class="macro-mobile">' + mobHtml + '</span>');
        }

        $('.cmp-meal-select').on('change', function() { calculateMacros($(this).closest('tr')); });

        $('.cmp-chefs-choice').on('change', function() {
            var rowElement = $(this).closest('tr');
            var selects = rowElement.find('select.cmp-meal-select');
            if ($(this).is(':checked')) { selects.prop('disabled', true).val(''); } else { selects.prop('disabled', false); }
            calculateMacros(rowElement); 
        });

        $('.cmp-date-picker').on('change', function() {
            var selectedDate = $(this).val();
            var rowInput = $(this);
            var container = rowInput.closest('.cmp-portal-container');
            var rowNumber = parseInt(rowInput.data('row'));
            var totalDays = parseInt(container.data('total-days'));
            
            if(selectedDate) {
                if(blackoutDates.includes(selectedDate)) {
                    alert('The kitchen is closed on this date. Please select a different day.');
                    rowInput.val('');
                    return;
                }
                var nextDate = new Date(selectedDate);
                nextDate.setDate(nextDate.getDate() + 1);
                var nextDateString = nextDate.toISOString().split('T')[0];
                for(var i = rowNumber + 1; i <= (totalDays + 10); i++) { 
                    var targetInput = container.find('.cmp-date-picker[data-row="' + i + '"]');
                    if (targetInput.length) {
                        targetInput.attr('min', nextDateString);
                        if (targetInput.val() && targetInput.val() < nextDateString) {
                            targetInput.val('');
                        }
                    }
                }
            }
        });

        $('.cmp-save-row').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var rowElement = btn.closest('tr'); 
            
            var dateVal = rowElement.find('.cmp-date-picker').val();
            var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked') ? 1 : 0;
            
            if(!dateVal) { alert('Please select a date from the calendar first.'); return; }

            // STRICT VALIDATION: Block saving empty meals
            if (!isChefsChoice && !isAdminOverride) {
                var missingSelections = false;
                rowElement.find('select:not(:disabled)').each(function() {
                    if ($(this).val() === "") {
                        missingSelections = true;
                    }
                });
                if (missingSelections) {
                    alert('Please select all your meals and snacks before saving.');
                    return;
                }
            }

            btn.text('Saving...').prop('disabled', true);
            
            var data = {
                action: 'cmp_save_daily_log',
                nonce: '<?php echo wp_create_nonce("cmp_portal_nonce"); ?>',
                log_id: btn.data('log-id'),
                sub_id: btn.data('sub-id'),
                date: dateVal,
                chefs_choice: isChefsChoice,
                breakfast: rowElement.find('.cmp-meal-select[data-cat="Breakfast"]').val() || null,
                lunch: rowElement.find('.cmp-meal-select[data-cat="Lunch"]').val() || null,
                dinner: rowElement.find('.cmp-meal-select[data-cat="Dinner"]').val() || null,
                snack_1: rowElement.find('.cmp-snack-1').val() || null,
                snack_2: rowElement.find('.cmp-snack-2').val() || null,
                juice_1: rowElement.find('.cmp-juice-1').val() || null, 
                juice_2: rowElement.find('.cmp-juice-2').val() || null,
                juice_3: rowElement.find('.cmp-juice-3').val() || null
            };

            $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(response) {
                if(response.success) {
                    btn.text(isAdminOverride ? 'Update' : 'Saved').css({'background':'#46b450', 'box-shadow':'none'});
                    if(response.data && response.data.new_log_id) { btn.data('log-id', response.data.new_log_id); }
                    
                    rowElement.removeClass('status-pending').addClass('status-saved');

                    if (!isAdminOverride) {
                        rowElement.find('.cmp-date-picker, .cmp-chefs-choice, select').prop('disabled', true);
                        btn.prop('disabled', true);

                        if ($(window).width() <= 768) {
                            rowElement.removeClass('is-open'); 
                            var $nextRow = rowElement.next('tr.status-pending'); 
                            if ($nextRow.length) {
                                $nextRow.addClass('is-open'); 
                                $('html, body').animate({ scrollTop: $nextRow.offset().top - 80 }, 600); 
                            }
                        }
                    } else {
                        btn.prop('disabled', false); 
                    }
                    rowElement.find('.cmp-date-picker').trigger('change');
                } else {
                    alert('Error: ' + response.data);
                    btn.text(btn.data('log-id') ? 'Update' : 'Save').prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}