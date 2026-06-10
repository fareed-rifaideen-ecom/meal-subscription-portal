<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// FRONT OF HOUSE (FOH) ADMIN PORTAL
// ==========================================
add_shortcode( 'meal_foh_portal', 'cmp_render_foh_portal' );

function cmp_render_foh_portal( $atts ) {
    // Check if the shortcode is embedded inside the Super Admin portal
    $args = shortcode_atts( array(
        'is_embedded' => 'false'
    ), $atts );

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
                    <p style="text-align: center; color: #666; margin-bottom: 20px;">Please log in with your FOH Manager account.</p>' 
                    . wp_login_form( $login_args ) . 
                '</div>';
    }

    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) ) {
        return '<p style="padding: 20px; background: #fff; border-left: 4px solid #dc3232;">Access Denied. FOH Manager account required.</p>';
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $notification = '';

    // Action Handlers
    if ( isset($_POST['update_expiry']) && isset($_POST['sub_id']) && isset($_POST['new_expiry']) ) {
        $wpdb->update($table_subs, array('expiry_date' => sanitize_text_field($_POST['new_expiry']) . ' 23:59:59'), array('id' => intval($_POST['sub_id'])));
        $notification = '<div style="background:#d4edda; color:#155724; padding:12px; border-radius:4px; margin-bottom:20px;">Expiry date updated!</div>';
    }
    if ( isset($_POST['toggle_status']) && isset($_POST['sub_id']) && isset($_POST['new_status']) ) {
        $wpdb->update($table_subs, array('status' => sanitize_text_field($_POST['new_status'])), array('id' => intval($_POST['sub_id'])));
        $notification = '<div style="background:#cce5ff; color:#004085; padding:12px; border-radius:4px; margin-bottom:20px;">Subscription is now ' . esc_html($_POST['new_status']) . '.</div>';
    }

    // EXCLUDE PENDING PAYMENTS FROM DASHBOARD
    $all_subs = $wpdb->get_results("SELECT s.*, u.display_name, u.user_email FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.status != 'pending' ORDER BY s.id DESC");
    $unique_plans = $wpdb->get_col("SELECT DISTINCT plan_name FROM $table_subs WHERE status != 'pending' ORDER BY plan_name ASC");
    
    $tabs = array('active' => array(), 'paused' => array(), 'inactive' => array());
    $today = date('Y-m-d H:i:s');

    foreach ($all_subs as $sub) {
        $filled_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result NOT IN ('Cancelled', 'Returned')", $sub->id));
        $usage_days = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
        
        $sub->filled = $filled_days;
        $sub->usage = $usage_days;
        $sub->balance = max(0, $sub->total_days - $usage_days); // Calculate Balance Days
        
        if ($sub->status === 'paused') {
            $tabs['paused'][] = $sub;
        } elseif ($usage_days >= $sub->total_days || $sub->expiry_date < $today) {
            $tabs['inactive'][] = $sub;
        } else {
            $tabs['active'][] = $sub;
        }
    }

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
    </style>

    <div style="max-width: 1200px; margin: 0 auto; font-family: inherit;">
        
        <?php if ( $args['is_embedded'] !== 'true' ): ?>
        <div style="background: #222; color: #fff; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="margin: 0; color: #fff;">FOH Command Center</h2>
                <p style="margin: 5px 0 0 0; color: #ccc;">Manage Customer Subscriptions.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="<?php echo site_url('/kitchen-command-center/'); ?>" target="_blank" style="background: #2271b1; color: white; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold;">View Live Kitchen Report</a>
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" style="background: #dc3232; color: white; padding: 10px 20px; border-radius: 4px; text-decoration: none; font-weight:bold;">Log Out</a>
            </div>
        </div>
        <?php endif; ?>

        <?php echo $notification; ?>

        <div style="background: #f8f9fa; padding: 15px; border-radius: <?php echo ($args['is_embedded'] === 'true') ? '8px' : '0 0 8px 8px'; ?>; border: 1px solid #ddd; <?php echo ($args['is_embedded'] === 'true') ? '' : 'border-top: none;'; ?> margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
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
                            <th>Order ID</th>
                            <th>Customer Info</th>
                            <th>Plan Details</th>
                            <th style="text-align: center;">Tracking Metrics</th>
                            <th>Expiry Management</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($tabs[$tab_key])): ?>
                            <tr class="empty-row"><td colspan="6" style="text-align:center; padding:30px; color:#666;">No subscriptions found in this category.</td></tr>
                        <?php else: foreach($tabs[$tab_key] as $sub): 
                            $order = wc_get_order($sub->wc_order_id);
                            $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $sub->display_name;
                            $phone = $order ? $order->get_billing_phone() : 'N/A';
                            $is_paused = ($sub->status === 'paused');
                            
                            $search_data = esc_attr(strtolower($full_name . ' ' . $sub->user_email . ' ' . $phone));
                            $plan_data = esc_attr($sub->plan_name);
                        ?>
                        <tr class="foh-row" data-search="<?php echo $search_data; ?>" data-plan="<?php echo $plan_data; ?>">
                            
                            <td style="font-size: 1.1em; font-weight: bold; color: #334155;">#<?php echo esc_html($sub->wc_order_id); ?></td>
                            
                            <td>
                                <strong style="font-size: 1.1em; color: #0073aa;"><?php echo esc_html($full_name ?: 'Customer'); ?></strong><br>
                                <span style="color: #555;"><?php echo esc_html($sub->user_email); ?></span><br>
                                <span style="color: #555; display: inline-flex; align-items: center; gap: 5px; margin-top: 3px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                    <?php echo esc_html($phone); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo esc_html($sub->plan_name); ?></strong><br>
                                <span style="color: #666;">Total Days: <?php echo $sub->total_days; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <div style="background: #f1f1f1; padding: 10px; border-radius: 4px; display: inline-block; text-align: left; width: 100%; box-sizing: border-box;">
                                    <span style="color: #555;">Filled Days: <strong><?php echo $sub->filled; ?> / <?php echo $sub->total_days; ?></strong></span><br>
                                    <span style="color: #46b450;">Usage (Delivered): <strong><?php echo $sub->usage; ?></strong></span><br>
                                    <span style="color: #0073aa; font-size: 1.1em; display: block; border-top: 1px solid #ddd; margin-top: 5px; padding-top: 5px;">Balance Days: <strong><?php echo $sub->balance; ?></strong></span>
                                </div>
                            </td>
                            <td>
                                <span style="font-size: 0.85em; color: #666;">Started: <?php echo date('M j, Y', strtotime($sub->start_date)); ?></span><br>
                                <form method="POST" style="display: flex; gap: 5px; margin-top: 5px;">
                                    <input type="hidden" name="sub_id" value="<?php echo $sub->id; ?>">
                                    <input type="date" name="new_expiry" value="<?php echo date('Y-m-d', strtotime($sub->expiry_date)); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
                                    <button type="submit" name="update_expiry" style="background: #2271b1; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer;">Update</button>
                                </form>
                            </td>
                            <td style="text-align: center;">
                                <?php if($tab_key !== 'inactive'): ?>
                                <form method="POST" style="margin-bottom: 5px;">
                                    <input type="hidden" name="sub_id" value="<?php echo $sub->id; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $is_paused ? 'active' : 'paused'; ?>">
                                    <button type="submit" name="toggle_status" style="width: 100%; background: <?php echo $is_paused ? '#0073aa' : '#dba617'; ?>; color: white; border: none; padding: 8px; border-radius: 4px; font-weight: bold; cursor: pointer; box-sizing: border-box;">
                                        <?php echo $is_paused ? 'Resume Plan' : 'Pause Plan'; ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="<?php echo site_url('/my-meal-portal/?admin_edit_sub=' . $sub->id); ?>" target="_blank" style="display: block; background: #46b450; color: white; text-decoration: none; padding: 8px; border-radius: 4px; font-weight: bold; box-sizing: border-box;">Edit / View Customer Portal</a>
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

    document.addEventListener("DOMContentLoaded", function() {
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
    </script>
    <?php
    return ob_get_clean();
}
