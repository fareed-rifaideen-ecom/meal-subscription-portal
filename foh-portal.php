<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// AJAX: EXPORT FOH TO CSV
// ==========================================
add_action('wp_ajax_cmp_export_foh_csv', 'cmp_export_foh_csv');
function cmp_export_foh_csv() {
    if ( !current_user_can('manage_options') && !current_user_can('foh_manager') ) wp_die('Access Denied');
    
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    
    // Fetch all non-pending subs
    $subs = $wpdb->get_results("SELECT s.*, u.display_name, u.user_email FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.status != 'pending' ORDER BY s.id DESC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="FOH_Customers_Export.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, array('Subscription ID', 'Order ID', 'Customer Name', 'Email', 'Phone', 'Plan Name', 'Total Days', 'Filled Days', 'Usage (Delivered)', 'Balance Days', 'Status', 'Expiry Date'));

    foreach ($subs as $sub) {
        $order = wc_get_order($sub->wc_order_id);
        $name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $sub->display_name;
        $phone = $order ? $order->get_billing_phone() : 'N/A';
        
        $filled = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result NOT IN ('Cancelled', 'Returned')", $sub->id));
        $usage = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
        $balance = max(0, $sub->total_days - $usage);

        fputcsv($output, array($sub->id, $sub->wc_order_id, $name, $sub->user_email, $phone, $sub->plan_name, $sub->total_days, $filled, $usage, $balance, ucfirst($sub->status), date('Y-m-d', strtotime($sub->expiry_date))));
    }
    fclose($output); exit;
}

// ==========================================
// FRONT OF HOUSE (FOH) ADMIN PORTAL
// ==========================================
add_shortcode( 'meal_foh_portal', 'cmp_render_foh_portal' );

function cmp_render_foh_portal() {
    date_default_timezone_set('Asia/Dubai');

    if ( ! is_user_logged_in() ) {
        return '<div style="max-width: 400px; margin: 50px auto; padding: 30px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px;">
                    <h2 style="text-align: center; margin-top: 0;">FOH Command Center</h2>
                    <p style="text-align: center; color: #666; margin-bottom: 20px;">Please log in.</p>
                </div>';
    }

    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) ) {
        return '<p style="padding: 20px; border-left: 4px solid #dc3232;">Access Denied. FOH Manager account required.</p>';
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $notification = '';

    // Action Handlers (Single)
    if ( isset($_POST['update_expiry']) ) {
        $wpdb->update($table_subs, array('expiry_date' => sanitize_text_field($_POST['new_expiry']) . ' 23:59:59'), array('id' => intval($_POST['sub_id'])));
        $notification = '<div style="background:#d4edda; color:#155724; padding:12px; border-radius:4px; margin-bottom:20px;">Expiry date updated!</div>';
    }
    if ( isset($_POST['toggle_status']) ) {
        $wpdb->update($table_subs, array('status' => sanitize_text_field($_POST['new_status'])), array('id' => intval($_POST['sub_id'])));
        $notification = '<div style="background:#cce5ff; color:#004085; padding:12px; border-radius:4px; margin-bottom:20px;">Subscription updated to ' . esc_html($_POST['new_status']) . '.</div>';
    }
    
    // Action Handler (Bulk)
    if ( isset($_POST['apply_bulk_action']) && !empty($_POST['bulk_action']) && !empty($_POST['bulk_sub_ids']) ) {
        $action = sanitize_text_field($_POST['bulk_action']);
        $ids = array_map('intval', $_POST['bulk_sub_ids']);
        $ids_list = implode(',', $ids);
        
        if (in_array($action, ['active', 'paused', 'cancelled'])) {
            $wpdb->query("UPDATE $table_subs SET status = '$action' WHERE id IN ($ids_list)");
            $notification = '<div style="background:#d4edda; color:#155724; padding:12px; border-radius:4px; margin-bottom:20px;">Bulk action applied successfully to ' . count($ids) . ' plans!</div>';
        }
    }

    // ONLY fetch non-pending subscriptions
    $all_subs = $wpdb->get_results("SELECT s.*, u.display_name, u.user_email FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.status != 'pending' ORDER BY s.id DESC");
    $unique_plans = $wpdb->get_col("SELECT DISTINCT plan_name FROM $table_subs WHERE status != 'pending' ORDER BY plan_name ASC");
    
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
        } elseif ($sub->status === 'cancelled' || $usage_days >= $sub->total_days || $sub->expiry_date < $today) {
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
        .bulk-check-large { transform: scale(1.3); cursor: pointer; }
    </style>

    <div style="max-width: 1200px; margin: 0 auto; font-family: inherit;">
        
        <div style="background: #222; color: #fff; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="margin: 0; color: #fff;">FOH Command Center</h2>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_foh_csv')); ?>" style="background: #1d6f42; color: white; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold;">Export CSV</a>
                <a href="<?php echo site_url('/kitchen-command-center/'); ?>" target="_blank" style="background: #2271b1; color: white; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold;">Live Kitchen Report</a>
            </div>
        </div>

        <?php echo $notification; ?>

        <div style="background: #f8f9fa; padding: 15px; border-radius: 0 0 8px 8px; border: 1px solid #ddd; border-top: none; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 2; min-width: 250px;">
                <input type="text" id="fohSearch" placeholder="🔍 Search customers by name, email, or phone..." style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em;">
            </div>
            <div style="flex: 1; min-width: 200px;">
                <select id="fohPlanFilter" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em;">
                    <option value="">All Plans</option>
                    <?php foreach($unique_plans as $up) echo '<option value="'.esc_attr($up).'">'.esc_html($up).'</option>'; ?>
                </select>
            </div>
        </div>

        <div class="foh-tabs" style="background: #fff; padding: 0 20px;">
            <button class="foh-tab-btn active" onclick="switchTab(event, 'active-tab')">Active Subscriptions</button>
            <button class="foh-tab-btn" onclick="switchTab(event, 'paused-tab')">Paused</button>
            <button class="foh-tab-btn" onclick="switchTab(event, 'inactive-tab')">Cancelled / Expired</button>
        </div>

        <form method="POST" id="foh-bulk-form">
            <div style="margin-bottom: 15px; background: #fff; padding: 10px 20px; border: 1px solid #ddd; border-radius: 4px; display: inline-flex; gap: 10px; align-items: center;">
                <strong>Bulk Actions:</strong>
                <select name="bulk_action" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                    <option value="">- Select Action -</option>
                    <option value="active">Activate</option>
                    <option value="paused">Pause</option>
                    <option value="cancelled">Cancel (Remove to Inactive)</option>
                </select>
                <button type="submit" name="apply_bulk_action" style="background: #334155; color: white; padding: 6px 15px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Apply</button>
            </div>

            <?php foreach(array('active', 'paused', 'inactive') as $tab_key): ?>
            <div id="<?php echo $tab_key; ?>-tab" class="foh-tab-content <?php echo $tab_key == 'active' ? 'active' : ''; ?>">
                <div style="overflow-x: auto;">
                    <table class="sub-table">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;"><input type="checkbox" class="bulk-check-large" onclick="toggleAllCheckboxes(this, '<?php echo $tab_key; ?>')"></th>
                                <th>Customer Info</th>
                                <th>Plan Details</th>
                                <th style="text-align: center;">Tracking Metrics</th>
                                <th>Expiry Management</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($tabs[$tab_key])): ?>
                                <tr class="empty-row"><td colspan="6" style="text-align:center; padding:30px; color:#666;">No subscriptions found.</td></tr>
                            <?php else: foreach($tabs[$tab_key] as $sub): 
                                $order = wc_get_order($sub->wc_order_id);
                                $full_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $sub->display_name;
                                $phone = $order ? $order->get_billing_phone() : 'N/A';
                                
                                $search_data = esc_attr(strtolower($full_name . ' ' . $sub->user_email . ' ' . $phone));
                            ?>
                            <tr class="foh-row" data-search="<?php echo $search_data; ?>" data-plan="<?php echo esc_attr($sub->plan_name); ?>">
                                <td style="text-align:center;"><input type="checkbox" name="bulk_sub_ids[]" value="<?php echo $sub->id; ?>" class="bulk-check-large row-checkbox-<?php echo $tab_key; ?>"></td>
                                <td>
                                    <strong style="font-size: 1.1em; color: #0073aa;"><?php echo esc_html($full_name ?: 'Customer'); ?></strong><br>
                                    <span style="color: #555;"><?php echo esc_html($sub->user_email); ?></span><br>
                                    <span style="color: #555;">📞 <?php echo esc_html($phone); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($sub->plan_name); ?></strong><br>
                                    <span style="color: #666;">Total Days: <?php echo $sub->total_days; ?></span><br>
                                    <span style="color: #888; font-size:0.85em;">Status: <?php echo ucfirst($sub->status); ?></span>
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
                                    <div style="display: flex; gap: 5px; margin-top: 5px;">
                                        <input type="date" name="individual_expiry_<?php echo $sub->id; ?>" value="<?php echo date('Y-m-d', strtotime($sub->expiry_date)); ?>" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;" form="expiry-form-<?php echo $sub->id; ?>">
                                        <button type="submit" name="update_expiry" style="background: #2271b1; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer;" form="expiry-form-<?php echo $sub->id; ?>">Update</button>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <a href="<?php echo site_url('/my-meal-portal/?admin_edit_sub=' . $sub->id); ?>" target="_blank" style="display: block; background: #46b450; color: white; text-decoration: none; padding: 8px; border-radius: 4px; font-weight: bold; box-sizing: border-box;">Edit / View Portal</a>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </form>

        <?php foreach ($all_subs as $sub): ?>
            <form method="POST" id="expiry-form-<?php echo $sub->id; ?>" style="display:none;">
                <input type="hidden" name="sub_id" value="<?php echo $sub->id; ?>">
                <input type="hidden" name="new_expiry" id="hidden_expiry_<?php echo $sub->id; ?>">
            </form>
        <?php endforeach; ?>

        <div id="fohPagination" style="margin-top: 20px; display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;"></div>
    </div>

    <script>
    // Sync visible date inputs to hidden individual forms
    document.querySelectorAll('input[type="date"][name^="individual_expiry_"]').forEach(input => {
        input.addEventListener('change', function() {
            const subId = this.name.split('_').pop();
            document.getElementById('hidden_expiry_' + subId).value = this.value;
        });
        // Initial setup
        const subId = input.name.split('_').pop();
        document.getElementById('hidden_expiry_' + subId).value = input.value;
    });

    function toggleAllCheckboxes(masterCheckbox, tabKey) {
        const checkboxes = document.querySelectorAll('.row-checkbox-' + tabKey);
        checkboxes.forEach(cb => {
            // Only check if the row is currently visible (matches search filter)
            if (cb.closest('tr').style.display !== 'none') {
                cb.checked = masterCheckbox.checked;
            }
        });
    }

    const rowsPerPage = 15;
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
            return (query === '' || searchData.includes(query)) && (plan === '' || planData === plan);
        });

        allRows.forEach(row => row.style.display = 'none');

        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        filtered.slice(start, end).forEach(row => row.style.display = '');

        renderPagination(filtered.length);
    }

    function renderPagination(totalItems) {
        const container = document.getElementById('fohPagination');
        container.innerHTML = '';
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
            btn.onclick = function(e) { e.preventDefault(); currentPage = i; renderTable(); };
            container.appendChild(btn);
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.getElementById('fohSearch').addEventListener('keyup', () => { currentPage = 1; renderTable(); });
        document.getElementById('fohPlanFilter').addEventListener('change', () => { currentPage = 1; renderTable(); });
        renderTable();
    });
    </script>
    <?php
    return ob_get_clean();
}
