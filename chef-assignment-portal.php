<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// CHEF ASSIGNMENT DESK DASHBOARD
// ==========================================
add_shortcode( 'meal_chef_assignment', 'cmp_render_chef_assignment_desk' );

function cmp_render_chef_assignment_desk() {
    date_default_timezone_set('Asia/Dubai');

    // 1. Authentication & Login Form
    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-chef-login', 'label_username' => __('Email Address or Username'), 'label_password' => __('Password'));
        $custom_css = '<style>#cmp-chef-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; } #cmp-chef-login input[type="text"], #cmp-chef-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; } #cmp-chef-login .login-submit input[type="submit"] { width: 100%; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; box-sizing: border-box; } #cmp-chef-login .login-remember { text-align: left; margin-bottom: 15px; }</style>';
        return $custom_css . '<div style="max-width: 400px; margin: 50px auto; padding: 30px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);"><h2 style="text-align: center; margin-top: 0; color: #222;">Chef Assignment Desk</h2><p style="text-align: center; color: #666; margin-bottom: 20px;">Please log in with your Kitchen account.</p>' . wp_login_form( $login_args ) . '</div>';
    }

    // 2. Security Check
    if ( !current_user_can('manage_options') && !current_user_can('kitchen_staff') && !current_user_can('menu_manager') && !current_user_can('foh_manager') ) {
        return '<p style="padding: 20px; background: #fff; border-left: 4px solid #dc3232;">Access Denied. Kitchen Staff only.</p>';
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';

    $today_time = date('Y-m-d H:i:s');
    $today_date = date('Y-m-d');

    // 3. Fetch Subs (using same robust join as FOH Portal)
    $raw_subs = $wpdb->get_results("SELECT s.*, u.display_name, u.user_email FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.status = 'active' ORDER BY s.id DESC");

    $pending_customers = array();
    $all_customers = array();

    foreach ($raw_subs as $sub) {
        
        // --- FIX 1: FILTER OUT OLD/WIPED OUT DATA ---
        // Ensure the plan isn't expired and hasn't consumed all its days
        $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
        if ($usage_days >= $sub->total_days || $sub->expiry_date < $today_time) {
            continue; // Skip this customer, they are done.
        }

        $order = wc_get_order($sub->wc_order_id);
        
        // Bulletproof Customer Name Fetching
        $fname = get_user_meta($sub->user_id, 'first_name', true) ?: get_user_meta($sub->user_id, 'billing_first_name', true);
        $lname = get_user_meta($sub->user_id, 'last_name', true) ?: get_user_meta($sub->user_id, 'billing_last_name', true);
        $fallback_name = trim($fname . ' ' . $lname);
        if (empty($fallback_name)) $fallback_name = $sub->display_name;
        
        $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $fallback_name;
        if (empty(trim($full_name))) { $full_name = $fallback_name; }

        $email = $order ? $order->get_billing_email() : $sub->user_email;
        $phone = $order ? $order->get_billing_phone() : (get_user_meta($sub->user_id, 'billing_phone', true) ?: 'N/A');

        $timing = !empty($sub->delivery_timing) ? $sub->delivery_timing : '';
        if (empty($timing) && $order) $timing = $order->get_meta('_cmp_delivery_timing') ?: $order->get_meta('delivery_timing');
        if (empty($timing)) $timing = get_user_meta($sub->user_id, 'delivery_timing', true) ?: 'N/A';

        $method = !empty($sub->delivery_method) ? $sub->delivery_method : '';
        if (empty($method) && $order) $method = $order->get_meta('_cmp_logistics_method') ?: $order->get_meta('delivery_method');
        if (empty($method)) $method = get_user_meta($sub->user_id, 'delivery_method', true) ?: 'N/A';

        $allergies = !empty($sub->allergies) ? $sub->allergies : '';
        if (empty($allergies) && $order) $allergies = $order->get_customer_note();
        if (empty($allergies)) $allergies = get_user_meta($sub->user_id, 'allergies', true) ?: 'None';

        $address = 'Address not provided';
        if ($order) {
            $addr_parts = array_filter([$order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city()]);
            if (empty($addr_parts)) $addr_parts = array_filter([$order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city()]);
            if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
        }
        if ($address === 'Address not provided') {
            $addr_parts = array_filter([get_user_meta($sub->user_id, 'billing_address_1', true), get_user_meta($sub->user_id, 'billing_address_2', true), get_user_meta($sub->user_id, 'billing_city', true)]);
            if (!empty($addr_parts)) $address = implode(', ', $addr_parts);
        }

        $customer_data = array(
            'id'        => $sub->id,
            'order_id'  => $sub->wc_order_id,
            'name'      => $full_name,
            'email'     => $email,
            'phone'     => $phone,
            'address'   => $address,
            'method'    => $method,
            'timing'    => $timing,
            'allergies' => $allergies,
            'plan_name' => $sub->plan_name,
            'status'    => $sub->status
        );

        $all_customers[] = $customer_data;

        // --- FIX 2: PENDING LOGIC (FUTURE DATES ONLY) ---
        // Only look at TODAY and FUTURE dates. Ignore past empty days.
        $chef_logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_logs WHERE subscription_id = %d AND is_chefs_choice = 1 AND target_date >= %s", $sub->id, $today_date));
        $is_pending = false;
        
        foreach($chef_logs as $log) {
            if (!$log->breakfast_id && !$log->lunch_id && !$log->dinner_id && !$log->juice_1_id) {
                $is_pending = true;
                break;
            }
        }

        if ($is_pending) {
            $pending_customers[] = $customer_data;
        }
    }

    ob_start();
    ?>
    <style>
        .chef-desk-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; }
        .chef-tabs { display: flex; border-bottom: 2px solid #ddd; margin-bottom: 20px; overflow-x: auto; }
        .chef-tab-btn { background: none; border: none; padding: 15px 30px; font-size: 1.1em; font-weight: bold; color: #666; cursor: pointer; border-bottom: 3px solid transparent; white-space: nowrap; }
        .chef-tab-btn.active { color: #d63638; border-bottom: 3px solid #d63638; }
        .chef-tab-content { display: none; }
        .chef-tab-content.active { display: block; }
        
        .chef-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #ddd; font-size: 0.95em; }
        .chef-table th { padding: 12px; border-bottom: 2px solid #ddd; text-align: left; background: #f8f9fa; }
        .chef-table td { padding: 12px; border-bottom: 1px solid #eee; vertical-align: top; }
        
        .chef-search-bar { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; margin-bottom: 15px; box-sizing: border-box; }
        .chef-assign-btn { display: inline-block; background: #d63638; color: #fff; padding: 8px 15px; border-radius: 4px; font-weight: bold; text-decoration: none; transition: 0.2s; white-space: nowrap; }
        .chef-assign-btn:hover { background: #b91c1c; color: #fff; }
    </style>

    <div class="chef-desk-wrap">
        <div style="background: #222; color: #fff; padding: 25px; border-radius: 8px 8px 0 0; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 style="margin:0; color:#fff;">Assignment Desk</h1>
                <p style="margin:5px 0 0 0; color:#ccc;">Select meals for "Chef's Choice" customers.</p>
            </div>
            <a href="<?php echo wp_logout_url( get_permalink() ); ?>" style="background: #dc3232; color: white; padding: 10px 20px; border-radius: 4px; text-decoration: none; font-weight:bold;">Log Out</a>
        </div>

        <div class="chef-tabs">
            <button class="chef-tab-btn active" onclick="switchChefTab(event, 'tab-pending')">
                Pending Chef's Selections <span style="background:#d63638; color:#fff; padding:2px 8px; border-radius:12px; font-size:0.8em; margin-left:5px;"><?php echo count($pending_customers); ?></span>
            </button>
            <button class="chef-tab-btn" onclick="switchChefTab(event, 'tab-all')">All Active Customers</button>
        </div>

        <!-- TAB 1: PENDING -->
        <div id="tab-pending" class="chef-tab-content active">
            <input type="text" id="search-pending" class="chef-search-bar" placeholder="🔍 Search pending customers by name, email, or phone...">
            <div style="overflow-x: auto;">
                <table class="chef-table" id="table-pending">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer Details</th>
                            <th>Logistics</th>
                            <th>Plan / Allergies</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($pending_customers)): ?>
                            <tr><td colspan="5" style="text-align:center; padding:30px; color:#666;">No pending assignments. The kitchen is fully prepped!</td></tr>
                        <?php else: foreach($pending_customers as $c): 
                            $search_str = strtolower($c['name'] . ' ' . $c['email'] . ' ' . $c['phone']);
                        ?>
                            <tr class="chef-row" data-search="<?php echo esc_attr($search_str); ?>">
                                <td><strong style="color:#334155; font-size:1.1em;"><?php echo $c['order_id'] > 0 ? '#'.esc_html($c['order_id']) : '<span style="color:#d63638;">Manual</span>'; ?></strong></td>
                                <td>
                                    <strong style="color: #0073aa; font-size: 1.1em;"><?php echo esc_html($c['name']); ?></strong><br>
                                    <span style="color: #666;"><?php echo esc_html($c['email']); ?></span><br>
                                    <span style="color: #666;"><?php echo esc_html($c['phone']); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($c['method']); ?></strong><br>
                                    <span style="color: #666;"><?php echo esc_html($c['timing']); ?></span><br>
                                    <span style="font-size:0.85em; color:#999;"><?php echo esc_html($c['address']); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($c['plan_name']); ?></strong><br>
                                    <span style="display:inline-block; margin-top:5px; background:<?php echo $c['allergies'] !== 'None' ? '#fff3cd' : '#f1f5f9'; ?>; color:<?php echo $c['allergies'] !== 'None' ? '#856404' : '#64748b'; ?>; padding:3px 8px; border-radius:4px; font-size:0.85em; font-weight:bold;">
                                        Allergies: <?php echo esc_html($c['allergies']); ?>
                                    </span>
                                </td>
                                <td style="vertical-align: middle; text-align:center;">
                                    <a href="<?php echo site_url('/my-meal-portal/?chef_override_sub=') . $c['id']; ?>" target="_blank" class="chef-assign-btn">Assign Meals ⇗</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 2: ALL CUSTOMERS -->
        <div id="tab-all" class="chef-tab-content">
            <input type="text" id="search-all" class="chef-search-bar" placeholder="🔍 Search all active customers...">
            <div style="overflow-x: auto;">
                <table class="chef-table" id="table-all">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer Details</th>
                            <th>Logistics</th>
                            <th>Plan / Allergies</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($all_customers)): ?>
                            <tr><td colspan="5" style="text-align:center; padding:30px; color:#666;">No active customers found.</td></tr>
                        <?php else: foreach($all_customers as $c): 
                            $search_str = strtolower($c['name'] . ' ' . $c['email'] . ' ' . $c['phone']);
                        ?>
                            <tr class="chef-row" data-search="<?php echo esc_attr($search_str); ?>">
                                <td><strong style="color:#334155; font-size:1.1em;"><?php echo $c['order_id'] > 0 ? '#'.esc_html($c['order_id']) : '<span style="color:#d63638;">Manual</span>'; ?></strong></td>
                                <td>
                                    <strong style="color: #0073aa; font-size: 1.1em;"><?php echo esc_html($c['name']); ?></strong><br>
                                    <span style="color: #666;"><?php echo esc_html($c['email']); ?></span><br>
                                    <span style="color: #666;"><?php echo esc_html($c['phone']); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($c['method']); ?></strong><br>
                                    <span style="color: #666;"><?php echo esc_html($c['timing']); ?></span><br>
                                    <span style="font-size:0.85em; color:#999;"><?php echo esc_html($c['address']); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($c['plan_name']); ?></strong><br>
                                    <span style="display:inline-block; margin-top:5px; background:<?php echo $c['allergies'] !== 'None' ? '#fff3cd' : '#f1f5f9'; ?>; color:<?php echo $c['allergies'] !== 'None' ? '#856404' : '#64748b'; ?>; padding:3px 8px; border-radius:4px; font-size:0.85em; font-weight:bold;">
                                        Allergies: <?php echo esc_html($c['allergies']); ?>
                                    </span>
                                </td>
                                <td style="vertical-align: middle; text-align:center;">
                                    <a href="<?php echo site_url('/my-meal-portal/?chef_override_sub=') . $c['id']; ?>" target="_blank" class="chef-assign-btn" style="background:#475569;">View Portal ⇗</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
    function switchChefTab(evt, tabId) {
        var contents = document.getElementsByClassName("chef-tab-content");
        for (var i = 0; i < contents.length; i++) contents[i].classList.remove("active");
        var btns = document.getElementsByClassName("chef-tab-btn");
        for (var i = 0; i < btns.length; i++) btns[i].classList.remove("active");
        document.getElementById(tabId).classList.add("active");
        evt.currentTarget.classList.add("active");
    }

    jQuery(document).ready(function($) {
        function setupSearch(inputId, tableId) {
            $(inputId).on('keyup', function() {
                var val = $(this).val().toLowerCase();
                $(tableId + ' tbody .chef-row').each(function() {
                    var searchData = $(this).data('search');
                    $(this).toggle(searchData.indexOf(val) > -1);
                });
            });
        }
        setupSearch('#search-pending', '#table-pending');
        setupSearch('#search-all', '#table-all');
    });
    </script>
    <?php
    return ob_get_clean();
}
