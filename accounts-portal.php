<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. AJAX EXPORTS FOR ACCOUNTS
// ==========================================

add_action('wp_ajax_cmp_export_accounts_main', 'cmp_export_accounts_main');
function cmp_export_accounts_main() {
    if (!is_user_logged_in() || (!current_user_can('manage_options') && !current_user_can('accounts_team'))) { wp_die('Permission Denied'); }
    
    global $wpdb;
    $start = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : date('Y-m-d', strtotime('-3 months'));
    $end = isset($_GET['end']) ? sanitize_text_field($_GET['end']) : date('Y-m-d');
    
    $subs = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, u.user_email, u.display_name FROM {$wpdb->prefix}cmp_subscriptions s 
         JOIN {$wpdb->prefix}users u ON s.user_id = u.ID 
         WHERE s.status != 'pending' AND DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s ORDER BY s.id DESC", 
        $start, $end
    ));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Accounts_Subscriptions_' . $start . '_to_' . $end . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 
    
    fputcsv($output, array('Order ID', 'Customer Name', 'Email', 'Phone', 'Plan Name', 'Status', 'Total Days', 'Days Consumed', 'Balance', 'Start Date', 'Expiry Date'));

    foreach($subs as $s) {
        $order = wc_get_order($s->wc_order_id);
        $phone = $order ? $order->get_billing_phone() : get_user_meta($s->user_id, 'billing_phone', true);
        $name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $s->display_name;
        
        $used = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}cmp_daily_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $s->id));
        $balance = $s->total_days - $used;
        
        $is_completed = ($used >= $s->total_days || $s->expiry_date < current_time('mysql'));
        $derived_status = $is_completed ? 'Completed/Expired' : ucfirst($s->status);

        fputcsv($output, array(
            $s->wc_order_id > 0 ? '#'.$s->wc_order_id : 'Manual',
            $name, $s->user_email, $phone, $s->plan_name, $derived_status,
            $s->total_days, $used, $balance, 
            date('Y-m-d', strtotime($s->start_date)), date('Y-m-d', strtotime($s->expiry_date))
        ));
    }
    fclose($output); exit;
}

add_action('wp_ajax_cmp_export_accounts_sub', 'cmp_export_accounts_sub');
function cmp_export_accounts_sub() {
    if (!is_user_logged_in() || (!current_user_can('manage_options') && !current_user_can('accounts_team'))) { wp_die('Permission Denied'); }
    
    if (!isset($_GET['sub_id'])) wp_die('No Sub ID');
    $sub_id = intval($_GET['sub_id']);
    
    global $wpdb;
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods = $wpdb->prefix . 'cmp_foods';
    
    $sub = $wpdb->get_row($wpdb->prepare("SELECT s.*, u.display_name, u.user_email FROM {$wpdb->prefix}cmp_subscriptions s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.id = %d", $sub_id));
    if (!$sub) wp_die('Not found');

    $order = wc_get_order($sub->wc_order_id);
    $name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $sub->display_name;
    
    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_logs WHERE subscription_id = %d ORDER BY target_date ASC", $sub_id));
    $foods = $wpdb->get_results("SELECT id, food_name FROM $table_foods");
    $fmap = array(); foreach($foods as $f) $fmap[$f->id] = $f->food_name;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Customer_Record_' . str_replace(' ', '_', $name) . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

    fputcsv($output, array('CUSTOMER RECORD'));
    fputcsv($output, array('Name:', $name, 'Order:', $sub->wc_order_id > 0 ? '#'.$sub->wc_order_id : 'Manual'));
    fputcsv($output, array('Plan:', $sub->plan_name));
    fputcsv($output, array(''));
    fputcsv($output, array('Date', 'Meals Scheduled', 'Delivery Status', 'POS Reconciled'));

    foreach ($logs as $l) {
        $meals = [];
        if ($l->is_chefs_choice && !$l->breakfast_id && !$l->lunch_id && !$l->dinner_id && !$l->juice_1_id) {
            $meals[] = "Chef's Choice (Pending)";
        } else {
            if ($l->breakfast_id) $meals[] = "BF: " . ($fmap[$l->breakfast_id] ?? 'Unknown');
            if ($l->lunch_id) $meals[] = "L: " . ($fmap[$l->lunch_id] ?? 'Unknown');
            if ($l->dinner_id) $meals[] = "D: " . ($fmap[$l->dinner_id] ?? 'Unknown');
            if ($l->snack_1_id) $meals[] = "S1: " . ($fmap[$l->snack_1_id] ?? 'Unknown');
            if ($l->snack_2_id) $meals[] = "S2: " . ($fmap[$l->snack_2_id] ?? 'Unknown');
            if ($l->juice_1_id) $meals[] = "J1: " . ($fmap[$l->juice_1_id] ?? 'Unknown');
            if ($l->juice_2_id) $meals[] = "J2: " . ($fmap[$l->juice_2_id] ?? 'Unknown');
            if ($l->juice_3_id) $meals[] = "J3: " . ($fmap[$l->juice_3_id] ?? 'Unknown');
        }
        $meal_str = implode(" | ", $meals);
        $pos = $l->pos_updated ? 'Yes' : 'No';

        fputcsv($output, array($l->target_date, $meal_str, $l->delivery_result, $pos));
    }
    fclose($output); exit;
}

// ==========================================
// 2. ACCOUNTS PORTAL ROUTER
// ==========================================

add_shortcode('meal_accounts_portal', 'cmp_render_accounts_portal');
function cmp_render_accounts_portal() {
    
    // Security
    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-acc-login', 'label_username' => __('Email Address'), 'label_password' => __('Password'));
        return '<div style="max-width:400px; margin:50px auto; padding:30px; background:#fff; border-radius:8px; border:1px solid #ddd; box-shadow: 0 4px 15px rgba(0,0,0,0.05);"><h2 style="text-align:center; margin-top:0; color:#0f172a;">Accounts Portal</h2>' . wp_login_form( $login_args ) . '</div>';
    }

    if ( !current_user_can('manage_options') && !current_user_can('accounts_team') ) {
        return '<div style="max-width:600px; margin:50px auto; padding:30px; background:#fff; border-left:4px solid #dc3232; box-shadow: 0 4px 6px rgba(0,0,0,0.05);"><p style="font-size:1.1em; color:#dc3232;"><strong>Access Denied:</strong> Finance / Accounts clearance required.</p><a href="'.wp_logout_url(get_permalink()).'" style="display:inline-block; background:#222; color:white; padding:8px 15px; text-decoration:none; border-radius:4px;">Log Out</a></div>';
    }

    // Route to the correct view
    if ( isset($_GET['view_sub']) && intval($_GET['view_sub']) > 0 ) {
        return cmp_render_accounts_sub_view(intval($_GET['view_sub']));
    } else {
        return cmp_render_accounts_dashboard();
    }
}

// ==========================================
// 3. MAIN DASHBOARD VIEW
// ==========================================
function cmp_render_accounts_dashboard() {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    
    // Filters
    $dash_start = isset($_GET['dash_start']) ? sanitize_text_field($_GET['dash_start']) : date('Y-m-d', strtotime('-3 months'));
    $dash_end   = isset($_GET['dash_end']) ? sanitize_text_field($_GET['dash_end']) : date('Y-m-d');
    
    $pos_start  = isset($_GET['pos_start']) ? sanitize_text_field($_GET['pos_start']) : date('Y-m-d', strtotime('-3 days'));
    $pos_end    = isset($_GET['pos_end']) ? sanitize_text_field($_GET['pos_end']) : date('Y-m-d');

    // --- POS CHECKS DATA ---
    $pending_pos = $wpdb->get_results($wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.user_id, s.plan_name, u.display_name FROM $table_logs l 
         JOIN $table_subs s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
         WHERE l.pos_updated = 0 AND l.delivery_result != 'Pending' AND l.is_locked = 1 AND l.target_date >= %s AND l.target_date <= %s ORDER BY l.target_date DESC",
        $pos_start, $pos_end
    ));

    // Calculate Delivery Date for POS Checks
    foreach($pending_pos as $p) {
        $order = wc_get_order($p->wc_order_id);
        $timing = '';
        if ($order) { $timing = $order->get_meta('_cmp_delivery_timing') ?: $order->get_meta('delivery_timing'); }
        if (empty($timing)) { $timing = get_user_meta($p->user_id, 'delivery_timing', true) ?: 'N/A'; }
        
        $timing = str_ireplace(['Deliver Day Before', 'Deliver Same Day'], ['Day Before', 'Same Day'], $timing);
        $is_day_before = (stripos($timing, 'Day Before') !== false);
        
        $prep_date = date('Y-m-d', strtotime($p->target_date . ' - 1 day'));
        $p->computed_delivery_date = $is_day_before ? $prep_date : $p->target_date;
    }

    // --- SUBSCRIPTION DATA & SORTING ---
    $all_subs_data = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, u.user_email, u.display_name FROM $table_subs s 
         JOIN {$wpdb->prefix}users u ON s.user_id = u.ID 
         WHERE s.status != 'pending' AND DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s ORDER BY s.id DESC",
        $dash_start, $dash_end
    ));

    $active_subs = [];
    $paused_subs = [];
    $completed_subs = [];

    foreach($all_subs_data as $sub) {
        $used = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id));
        $is_completed = ($used >= $sub->total_days || $sub->expiry_date < current_time('mysql'));
        
        $sub->used = $used;
        $sub->balance = $sub->total_days - $used;

        if ($is_completed) {
            $completed_subs[] = $sub;
        } elseif ($sub->status === 'paused') {
            $paused_subs[] = $sub;
        } else {
            $active_subs[] = $sub;
        }
    }

    ob_start();
    ?>
    <style>
        .acc-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; }
        .acc-header { background: #0f172a; color: #fff; padding: 25px 30px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .acc-section-title { font-size: 1.4em; color: #0f172a; margin: 0 0 20px 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; font-weight: 800; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:10px; }
        
        .acc-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .acc-kpi-card { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; border-left: 5px solid #3b82f6; box-shadow: 0 4px 6px rgba(0,0,0,0.02); cursor: pointer; transition: 0.2s; position: relative; }
        .acc-kpi-card:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.05); }
        .acc-kpi-card.active-tab { box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.1); border-color:#0f172a; }
        
        .acc-kpi-card.green { border-left-color: #10b981; }
        .acc-kpi-card.orange { border-left-color: #f59e0b; }
        .acc-kpi-card.red { border-left-color: #ef4444; }
        .acc-kpi-val { font-size: 2.8em; font-weight: 900; color: #0f172a; margin: 5px 0 0 0; line-height: 1; pointer-events: none;}
        
        .acc-filter { display: flex; gap: 10px; align-items: center; font-size: 0.85em; font-weight: normal; }
        .acc-filter input[type="date"] { padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; color: #334155; }
        .acc-filter button { background: #3b82f6; color: white; border: none; padding: 7px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        
        .acc-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); font-size: 0.9em; border-radius:8px; overflow:hidden; }
        .acc-table th { background: #f1f5f9; color: #334155; padding: 12px; text-align: left; border-bottom: 2px solid #e2e8f0; }
        .acc-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .acc-btn { background: #0f172a; color: #fff; padding: 8px 15px; border-radius: 4px; text-decoration: none; font-weight: bold; display: inline-block; transition:0.2s;}
        .acc-btn:hover { background: #334155; color: #fff; }

        .acc-tab-content { display: none; }
        .acc-tab-content.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        @media print {
            .cmp-no-print { display: none !important; }
            body, html { background: #fff !important; }
            .acc-table { border: 1px solid #000; box-shadow: none; }
            .acc-table th, .acc-table td { border: 1px solid #000; }
            .acc-tab-content { display: block !important; }
        }
    </style>

    <div class="acc-wrap">
        <div class="acc-header cmp-no-print">
            <div>
                <h1 style="margin:0; color:#fff; font-size:2em;">Accounts Portal</h1>
                <p style="margin:5px 0 0 0; color:#94a3b8;">Financial & Compliance Tracking</p>
            </div>
            <div>
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" style="background: rgba(255,255,255,0.1); color: white; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; border: 1px solid rgba(255,255,255,0.2);">Log Out</a>
            </div>
        </div>

        <!-- POS NOTIFICATIONS -->
        <div class="acc-section-title cmp-no-print" style="margin-top: 10px;">
            <span style="color:#b45309;">Compliance: Pending POS Checks</span>
            <form method="GET" class="acc-filter">
                <input type="hidden" name="dash_start" value="<?php echo esc_attr($dash_start); ?>">
                <input type="hidden" name="dash_end" value="<?php echo esc_attr($dash_end); ?>">
                <span>Audit Period:</span>
                <input type="date" name="pos_start" value="<?php echo esc_attr($pos_start); ?>"> <span>to</span>
                <input type="date" name="pos_end" value="<?php echo esc_attr($pos_end); ?>">
                <button type="submit" style="background:#f59e0b;">Apply</button>
            </form>
        </div>
        
        <?php if(empty($pending_pos)): ?>
            <div style="background:#f0fdf4; color:#166534; padding:20px; border-radius:8px; border:1px solid #bbf7d0; font-weight:bold; margin-bottom:40px;">
                ✓ Perfect compliance. No pending POS checks found for this period.
            </div>
        <?php else: ?>
            <div style="background:#fffbeb; padding:20px; border-radius:8px; border:1px solid #fde68a; margin-bottom:40px;">
                <h4 style="margin:0 0 15px 0; color:#b45309; font-size:1.1em;">Attention: <?php echo count($pending_pos); ?> Missing Reconciliations</h4>
                <div style="max-height: 250px; overflow-y: auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.9em; text-align:left;">
                        <tr style="border-bottom:2px solid #fcd34d; color:#92400e;">
                            <th style="padding:8px;">Target Date (Eating)</th>
                            <th style="padding:8px; border-right:2px solid #fcd34d;">Delivery Date</th>
                            <th style="padding:8px;">Order ID</th>
                            <th style="padding:8px;">Customer</th>
                            <th style="padding:8px;">Plan</th>
                            <th style="padding:8px;">Delivery Logged As</th>
                        </tr>
                        <?php foreach($pending_pos as $p): ?>
                        <tr style="border-bottom:1px solid #fde68a;">
                            <td style="padding:8px;"><strong><?php echo date('d M Y', strtotime($p->target_date)); ?></strong></td>
                            <td style="padding:8px; border-right:2px solid #fde68a; font-weight:bold; color:#b45309;"><?php echo date('d M Y', strtotime($p->computed_delivery_date)); ?></td>
                            <td style="padding:8px;"><?php echo $p->wc_order_id > 0 ? '#'.$p->wc_order_id : 'Manual'; ?></td>
                            <td style="padding:8px;"><?php echo esc_html($p->display_name); ?></td>
                            <td style="padding:8px;"><?php echo esc_html($p->plan_name); ?></td>
                            <td style="padding:8px;">
                                <span style="background:#fecdd3; color:#9f1239; padding:2px 6px; border-radius:4px; font-weight:bold; font-size:0.85em;"><?php echo esc_html($p->delivery_result); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI SECTION (TABS) -->
        <div class="acc-section-title cmp-no-print">
            <span>Subscription Metrics</span>
            <form method="GET" class="acc-filter">
                <input type="hidden" name="pos_start" value="<?php echo esc_attr($pos_start); ?>">
                <input type="hidden" name="pos_end" value="<?php echo esc_attr($pos_end); ?>">
                <span>Filter:</span>
                <input type="date" name="dash_start" value="<?php echo esc_attr($dash_start); ?>"> <span>to</span>
                <input type="date" name="dash_end" value="<?php echo esc_attr($dash_end); ?>">
                <button type="submit">Apply</button>
            </form>
        </div>
        <div class="acc-kpi-grid cmp-no-print">
            <div class="acc-kpi-card green active-tab" onclick="switchAccTab('acc-active', this)">
                <div style="font-weight:800; color:#64748b; text-transform:uppercase; pointer-events:none;">Active Plans</div>
                <div class="acc-kpi-val"><?php echo count($active_subs); ?></div>
                <div style="font-size:0.85em; color:#94a3b8; margin-top:5px; pointer-events:none;">Originating in selected period</div>
            </div>
            <div class="acc-kpi-card orange" onclick="switchAccTab('acc-paused', this)">
                <div style="font-weight:800; color:#64748b; text-transform:uppercase; pointer-events:none;">Paused Plans</div>
                <div class="acc-kpi-val"><?php echo count($paused_subs); ?></div>
                <div style="font-size:0.85em; color:#94a3b8; margin-top:5px; pointer-events:none;">Originating in selected period</div>
            </div>
            <div class="acc-kpi-card red" onclick="switchAccTab('acc-completed', this)">
                <div style="font-weight:800; color:#64748b; text-transform:uppercase; pointer-events:none;">Completed / Expired</div>
                <div class="acc-kpi-val"><?php echo count($completed_subs); ?></div>
                <div style="font-size:0.85em; color:#94a3b8; margin-top:5px; pointer-events:none;">Fully consumed or expired</div>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-bottom:20px;">
            <div class="cmp-no-print" style="display:flex; gap:10px;">
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_accounts_main&start='.$dash_start.'&end='.$dash_end)); ?>" style="background:#1d6f42; color:white; padding:8px 15px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.9em;">Export All to CSV</a>
                <button onclick="window.print()" style="background:#475569; color:white; border:none; padding:8px 15px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.9em;">Print PDF</button>
            </div>
        </div>

        <!-- HELPER FUNCTION FOR TABLES -->
        <?php 
        function render_accounts_sub_table($subs_array, $table_id, $is_active = false) {
            $display = $is_active ? 'active' : '';
            echo "<div id='{$table_id}' class='acc-tab-content {$display}'><div style='overflow-x:auto;'><table class='acc-table'>
                    <thead>
                        <tr>
                            <th style='width:10%;'>Order ID</th>
                            <th style='width:25%;'>Customer Info</th>
                            <th style='width:25%;'>Plan Details</th>
                            <th style='width:25%;'>Tracking Metrics</th>
                            <th style='width:15%; text-align:center;' class='cmp-no-print'>Action</th>
                        </tr>
                    </thead>
                    <tbody>";
            if(empty($subs_array)) {
                echo "<tr><td colspan='5' style='text-align:center; padding:30px; color:#64748b;'>No plans found in this category.</td></tr>";
            } else {
                foreach($subs_array as $sub) {
                    $order = wc_get_order($sub->wc_order_id);
                    $phone = $order ? $order->get_billing_phone() : get_user_meta($sub->user_id, 'billing_phone', true);
                    $name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $sub->display_name;
                    
                    $used = $sub->used;
                    $balance = $sub->balance;
                    
                    if ($sub->status === 'paused') { $status_color = '#f59e0b'; $status_label = 'PAUSED'; }
                    elseif ($balance <= 0 || $sub->expiry_date < current_time('mysql')) { $status_color = '#ef4444'; $status_label = 'COMPLETED'; }
                    else { $status_color = '#10b981'; $status_label = 'ACTIVE'; }

                    echo "<tr>
                        <td><strong style='color:#0f172a; font-size:1.1em;'>" . ($sub->wc_order_id > 0 ? '#'.esc_html($sub->wc_order_id) : '<span style="color:#ef4444;">Manual</span>') . "</strong></td>
                        <td>
                            <strong style='color:#0073aa;'>" . esc_html($name) . "</strong><br>
                            <span style='color:#64748b; font-size:0.9em;'>" . esc_html($sub->user_email) . "</span><br>
                            <span style='color:#64748b; font-size:0.9em;'>" . esc_html($phone ?: 'N/A') . "</span>
                        </td>
                        <td>
                            <strong>" . esc_html($sub->plan_name) . "</strong><br>
                            <span style='color:#64748b; font-size:0.9em;'>Started: " . date('d M Y', strtotime($sub->start_date)) . "</span>
                        </td>
                        <td>
                            <div style='display:flex; justify-content:space-between; margin-bottom:4px; font-size:0.95em;'>
                                <span>Used: <strong>{$used}</strong></span>
                                <span>Total: <strong>{$sub->total_days}</strong></span>
                            </div>
                            <div style='background:#e2e8f0; width:100%; height:8px; border-radius:4px; overflow:hidden; margin-bottom:5px;'>";
                    $pct = ($sub->total_days > 0) ? min(100, ($used / $sub->total_days)*100) : 0;
                    echo "<div style='width:{$pct}%; background:{$status_color}; height:100%;'></div>
                            </div>
                            <div style='display:flex; justify-content:space-between; font-size:0.85em;'>
                                <span style='color:{$status_color}; font-weight:bold;'>{$status_label}</span>
                                <span>Bal: <strong>{$balance}</strong></span>
                            </div>
                        </td>
                        <td style='text-align:center;' class='cmp-no-print'>
                            <a href='" . add_query_arg('view_sub', $sub->id) . "' class='acc-btn'>View Record</a>
                        </td>
                    </tr>";
                }
            }
            echo "</tbody></table></div></div>";
        }
        ?>

        <!-- Render the 3 Tables -->
        <?php render_accounts_sub_table($active_subs, 'acc-active', true); ?>
        <?php render_accounts_sub_table($paused_subs, 'acc-paused', false); ?>
        <?php render_accounts_sub_table($completed_subs, 'acc-completed', false); ?>

    </div>

    <script>
    function switchAccTab(targetId, cardElement) {
        // Hide all tables
        var contents = document.getElementsByClassName('acc-tab-content');
        for (var i = 0; i < contents.length; i++) {
            contents[i].classList.remove('active');
        }
        // Remove active outline from all cards
        var cards = document.getElementsByClassName('acc-kpi-card');
        for (var i = 0; i < cards.length; i++) {
            cards[i].classList.remove('active-tab');
        }
        // Show target table and highlight clicked card
        document.getElementById(targetId).classList.add('active');
        cardElement.classList.add('active-tab');
    }
    </script>
    <?php
    return ob_get_clean();
}


// ==========================================
// 4. INDIVIDUAL CUSTOMER RECORD VIEW (READ ONLY)
// ==========================================
function cmp_render_accounts_sub_view($sub_id) {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods = $wpdb->prefix . 'cmp_foods';
    
    $sub = $wpdb->get_row($wpdb->prepare("SELECT s.*, u.user_email, u.display_name FROM $table_subs s JOIN {$wpdb->prefix}users u ON s.user_id = u.ID WHERE s.id = %d", $sub_id));
    
    if (!$sub) { return "<div style='padding:40px; text-align:center;'>Subscription Not Found. <a href='?'>Go Back</a></div>"; }

    $order = wc_get_order($sub->wc_order_id);
    $phone = $order ? $order->get_billing_phone() : get_user_meta($sub->user_id, 'billing_phone', true);
    $name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : $sub->display_name;

    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_logs WHERE subscription_id = %d ORDER BY target_date ASC", $sub_id));
    $foods = $wpdb->get_results("SELECT id, food_name FROM $table_foods");
    $fmap = array(); foreach($foods as $f) $fmap[$f->id] = $f->food_name;

    $used = 0; $cancelled = 0; $returned = 0;
    foreach($logs as $l) {
        if ($l->delivery_result === 'Successful') $used++;
        if ($l->delivery_result === 'Cancelled') $cancelled++;
        if ($l->delivery_result === 'Returned') $returned++;
    }
    $balance = $sub->total_days - $used;

    $is_completed = ($used >= $sub->total_days || $sub->expiry_date < current_time('mysql'));
    $derived_status = $is_completed ? 'Completed' : $sub->status;

    ob_start();
    ?>
    <style>
        .acc-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; }
        .acc-ledger-header { background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:30px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px; box-shadow:0 2px 4px rgba(0,0,0,0.02); }
        .acc-summary-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:15px; margin-bottom:30px; }
        .acc-sum-box { background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #e2e8f0; text-align:center; }
        .acc-sum-val { font-size:2em; font-weight:900; color:#0f172a; margin-bottom:5px; line-height:1; }
        .acc-sum-label { font-size:0.85em; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
        
        .acc-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); font-size: 0.9em; border-radius:8px; overflow:hidden; }
        .acc-table th { background: #f1f5f9; color: #334155; padding: 12px; text-align: left; border-bottom: 2px solid #e2e8f0; }
        .acc-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }

        @media print {
            .cmp-no-print { display: none !important; }
            body, html { background: #fff !important; }
            .acc-table { border: 1px solid #000; box-shadow: none; }
            .acc-table th, .acc-table td { border: 1px solid #000; }
        }
    </style>

    <div class="acc-wrap">
        <div class="cmp-no-print" style="margin-bottom: 20px;">
            <a href="?" style="color:#0073aa; text-decoration:none; font-weight:bold;">&larr; Back to Dashboard</a>
        </div>

        <div class="acc-ledger-header">
            <div>
                <h1 style="margin:0 0 10px 0; color:#0f172a; font-size:1.8em;">Customer Record</h1>
                <div style="font-size:1.1em; color:#334155;"><strong><?php echo esc_html($name); ?></strong></div>
                <div style="color:#64748b; margin-top:5px;"><?php echo esc_html($sub->user_email); ?> | <?php echo esc_html($phone ?: 'No Phone'); ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:1.2em; font-weight:bold; color:#0f172a; margin-bottom:10px;"><?php echo esc_html($sub->plan_name); ?></div>
                <div style="color:#64748b;">Order: <strong><?php echo $sub->wc_order_id > 0 ? '#'.esc_html($sub->wc_order_id) : 'Manual'; ?></strong></div>
                <div style="color:#64748b; margin-top:5px;">Status: <strong style="text-transform:uppercase; color:<?php echo $derived_status == 'active' ? '#10b981' : ($derived_status == 'Completed' ? '#ef4444' : '#f59e0b'); ?>;"><?php echo esc_html($derived_status); ?></strong></div>
            </div>
        </div>

        <div class="acc-summary-grid">
            <div class="acc-sum-box">
                <div class="acc-sum-val"><?php echo $sub->total_days; ?></div>
                <div class="acc-sum-label">Total Days</div>
            </div>
            <div class="acc-sum-box" style="border-bottom: 4px solid #10b981;">
                <div class="acc-sum-val" style="color:#10b981;"><?php echo $used; ?></div>
                <div class="acc-sum-label">Consumed (Success)</div>
            </div>
            <div class="acc-sum-box" style="border-bottom: 4px solid #3b82f6;">
                <div class="acc-sum-val" style="color:#3b82f6;"><?php echo $balance; ?></div>
                <div class="acc-sum-label">Remaining Balance</div>
            </div>
            <div class="acc-sum-box" style="border-bottom: 4px solid #f59e0b;">
                <div class="acc-sum-val" style="color:#f59e0b;"><?php echo $returned; ?></div>
                <div class="acc-sum-label">Returned</div>
            </div>
            <div class="acc-sum-box" style="border-bottom: 4px solid #ef4444;">
                <div class="acc-sum-val" style="color:#ef4444;"><?php echo $cancelled; ?></div>
                <div class="acc-sum-label">Cancelled</div>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:15px;">
            <h2 style="margin:0; font-size:1.3em; color:#0f172a;">Daily Selections & Logistics</h2>
            <div class="cmp-no-print" style="display:flex; gap:10px;">
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_accounts_sub&sub_id='.$sub_id)); ?>" style="background:#1d6f42; color:white; padding:8px 15px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.9em;">Export CSV</a>
                <button onclick="window.print()" style="background:#475569; color:white; border:none; padding:8px 15px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.9em;">Print PDF</button>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="acc-table">
                <thead>
                    <tr>
                        <th style="width:5%;">Day</th>
                        <th style="width:15%;">Eating Date</th>
                        <th style="width:40%;">Meal Assignments</th>
                        <th style="width:15%; text-align:center;">Delivery Log</th>
                        <th style="width:10%; text-align:center;">POS Check</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $day_counter = 1;
                    if(empty($logs)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:30px; color:#64748b;">No schedule created yet.</td></tr>
                    <?php else: foreach($logs as $l): 
                        $meals = [];
                        if ($l->is_chefs_choice && !$l->breakfast_id && !$l->lunch_id && !$l->dinner_id && !$l->juice_1_id) {
                            $meals[] = "<span style='color:#b45309; font-weight:bold;'>Chef's Choice (Pending)</span>";
                        } else {
                            if ($l->breakfast_id) $meals[] = "<strong>BF:</strong> " . esc_html($fmap[$l->breakfast_id] ?? 'Unknown');
                            if ($l->lunch_id) $meals[] = "<strong>L:</strong> " . esc_html($fmap[$l->lunch_id] ?? 'Unknown');
                            if ($l->dinner_id) $meals[] = "<strong>D:</strong> " . esc_html($fmap[$l->dinner_id] ?? 'Unknown');
                            if ($l->snack_1_id) $meals[] = "<strong>S1:</strong> " . esc_html($fmap[$l->snack_1_id] ?? 'Unknown');
                            if ($l->snack_2_id) $meals[] = "<strong>S2:</strong> " . esc_html($fmap[$l->snack_2_id] ?? 'Unknown');
                            if ($l->juice_1_id) $meals[] = "<strong>J1:</strong> " . esc_html($fmap[$l->juice_1_id] ?? 'Unknown');
                            if ($l->juice_2_id) $meals[] = "<strong>J2:</strong> " . esc_html($fmap[$l->juice_2_id] ?? 'Unknown');
                            if ($l->juice_3_id) $meals[] = "<strong>J3:</strong> " . esc_html($fmap[$l->juice_3_id] ?? 'Unknown');
                        }

                        $del_color = '#64748b'; // pending
                        if ($l->delivery_result === 'Successful') $del_color = '#10b981';
                        if ($l->delivery_result === 'Cancelled') $del_color = '#ef4444';
                        if ($l->delivery_result === 'Returned') $del_color = '#f59e0b';
                    ?>
                    <tr>
                        <td><strong><?php echo $day_counter++; ?></strong></td>
                        <td><?php echo date('d M Y', strtotime($l->target_date)); ?></td>
                        <td style="line-height:1.5;">
                            <?php echo empty($meals) ? '<em style="color:#94a3b8;">No meals selected</em>' : implode('<br>', $meals); ?>
                        </td>
                        <td style="text-align:center;">
                            <strong style="color:<?php echo $del_color; ?>;"><?php echo esc_html($l->delivery_result); ?></strong>
                        </td>
                        <td style="text-align:center;">
                            <?php echo $l->pos_updated ? '<span style="color:#10b981; font-weight:bold;">Yes</span>' : '<span style="color:#ef4444; font-weight:bold;">No</span>'; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
