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
    $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : 'active';
    
    $subs = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, u.user_email, u.display_name FROM {$wpdb->prefix}cmp_subscriptions s 
         JOIN {$wpdb->prefix}users u ON s.user_id = u.ID 
         WHERE s.status != 'pending' AND DATE(s.start_date) >= %s AND DATE(s.start_date) <= %s ORDER BY s.id DESC", 
        $start, $end
    ));

    $filename_status = ucfirst($status_filter);
    if ($status_filter === 'completed') $filename_status = 'Completed';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Accounts_' . $filename_status . '_Plans_' . $start . '_to_' . $end . '.csv"');
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
        
        if ($is_completed) {
            $internal_status = 'completed';
            $derived_status = 'Completed/Expired';
        } elseif ($s->status === 'paused') {
            $internal_status = 'paused';
            $derived_status = 'Paused';
        } else {
            $internal_status = 'active';
            $derived_status = 'Active';
        }

        if ($status_filter !== 'all' && $status_filter !== $internal_status) {
            continue; 
        }

        $display_order_id = 'Manual';
        if ($s->wc_order_id < 0) { $display_order_id = 'M-' . str_pad(abs($s->wc_order_id), 4, '0', STR_PAD_LEFT); } 
        elseif ($s->wc_order_id > 0) { $display_order_id = '#' . $s->wc_order_id; }

        fputcsv($output, array(
            $display_order_id,
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

    $display_order_id = 'Manual';
    if ($sub->wc_order_id < 0) { $display_order_id = 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT); } 
    elseif ($sub->wc_order_id > 0) { $display_order_id = '#' . $sub->wc_order_id; }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Customer_Record_' . str_replace(' ', '_', $name) . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

    fputcsv($output, array('CUSTOMER RECORD'));
    fputcsv($output, array('Name:', $name, 'Order:', $display_order_id));
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

// 1.5 AJAX HANDLER: ACCOUNTS DEPOSIT TOGGLE
add_action('wp_ajax_cmp_acc_toggle_deposit', 'cmp_acc_toggle_deposit');
function cmp_acc_toggle_deposit() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'accounts_team' ) ) { wp_send_json_error('Access Denied'); }
    check_ajax_referer('cmp_acc_nonce', 'nonce');
    
    $user_id = intval($_POST['user_id']);
    $new_status = sanitize_text_field($_POST['new_status']); 
    
    update_user_meta($user_id, '_cmp_deposit_held', $new_status);
    wp_send_json_success();
}


// ==========================================
// HELPER: PROCESS TIMING DATA
// ==========================================
if(!function_exists('cmp_process_timing_data')){
    function cmp_process_timing_data($log_array) {
        foreach($log_array as $p) {
            $order = wc_get_order($p->wc_order_id);
            $timing = '';
            if ($order) { $timing = $order->get_meta('_cmp_delivery_timing') ?: $order->get_meta('delivery_timing'); }
            if (empty($timing)) { $timing = get_user_meta($p->user_id, 'delivery_timing', true) ?: 'N/A'; }
            
            $timing = str_ireplace(['Deliver Day Before', 'Deliver Same Day'], ['Day Before', 'Same Day'], $timing);
            $is_day_before = (stripos($timing, 'Day Before') !== false);
            
            $prep_date = date('Y-m-d', strtotime($p->target_date . ' - 1 day'));
            $p->computed_delivery_date = $is_day_before ? $prep_date : $p->target_date;
            $p->timing_label = $is_day_before ? 'Day Before' : 'Same Day';
        }
        return $log_array;
    }
}

// ==========================================
// 2. ACCOUNTS PORTAL ROUTER & GLASS LOGIN UI
// ==========================================
add_shortcode('meal_accounts_portal', 'cmp_render_accounts_portal');
function cmp_render_accounts_portal() {
    
    // Security & Custom Glassmorphism UI/UX Login Screen
    if ( ! is_user_logged_in() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'accounts_team' ) ) ) {
        return cmp_render_accounts_glass_login( array(
            'portal_id'      => 'accounts',
            'title'          => 'Accounts Portal',
            'subtitle'       => 'Finance, Compliance & Meal Plan Auditing',
            'required_caps'  => array( 'manage_options', 'accounts_team' ),
            'denied_message' => 'Finance or Accounts Team clearance is required to access this portal.',
        ) );
    }

    // Smart Routing
    $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';
    
    if ($view === 'kitchen_audit') {
        return cmp_render_kitchen_audit_view();
    } elseif ($view === 'pos_audit') {
        return cmp_render_pos_audit_view();
    } elseif ( isset($_GET['view_sub']) && intval($_GET['view_sub']) > 0 ) {
        return cmp_render_accounts_sub_view(intval($_GET['view_sub']));
    } else {
        return cmp_render_accounts_dashboard();
    }
}

// 2.1 Self-Contained Cycle Bistro Glass Login Screen (Desktop, Tablet & Mobile)
function cmp_render_accounts_glass_login( $args = array() ) {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    $defaults = array(
        'portal_id'      => 'accounts',
        'title'          => 'Accounts Portal',
        'subtitle'       => 'Finance, Compliance & Meal Plan Auditing',
        'required_caps'  => array( 'manage_options', 'accounts_team' ),
        'denied_message' => 'Finance or Accounts Team clearance is required to access this portal.',
    );
    $cfg = wp_parse_args( $args, $defaults );

    $uid         = esc_attr( $cfg['portal_id'] );
    $caps_csv    = is_array( $cfg['required_caps'] ) ? implode( ',', $cfg['required_caps'] ) : $cfg['required_caps'];
    $ajax_url    = esc_url( admin_url( 'admin-ajax.php' ) );
    $nonce       = wp_create_nonce( 'cmp_portal_auth_nonce' );
    $current_url = esc_url( remove_query_arg( array( 'cmp_login' ) ) );
    $url_failed  = isset( $_GET['cmp_login'] ) && $_GET['cmp_login'] === 'failed';

    ob_start();
    ?>
    <style>
        #cmp-auth-stage-<?php echo $uid; ?>, #cmp-auth-stage-<?php echo $uid; ?> * { box-sizing: border-box !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important; letter-spacing: normal !important; }
        #cmp-auth-stage-<?php echo $uid; ?> { position: relative !important; width: 100% !important; max-width: 490px !important; margin: 32px auto 60px auto !important; padding: 22px 18px !important; z-index: 1 !important; }
        #cmp-auth-stage-<?php echo $uid; ?>::before, #cmp-auth-stage-<?php echo $uid; ?>::after { content: "" !important; position: absolute !important; border-radius: 50% !important; filter: blur(38px) !important; z-index: -1 !important; pointer-events: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?>::before { width: 240px !important; height: 240px !important; top: -12px !important; left: -18px !important; background: radial-gradient(circle, rgba(104, 185, 70, 0.48) 0%, rgba(55, 146, 55, 0.16) 68%, transparent 100%) !important; }
        #cmp-auth-stage-<?php echo $uid; ?>::after { width: 260px !important; height: 260px !important; bottom: -18px !important; right: -15px !important; background: radial-gradient(circle, rgba(220, 236, 201, 0.92) 0%, rgba(55, 146, 55, 0.22) 70%, transparent 100%) !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-card { position: relative !important; background: linear-gradient(145deg, rgba(255, 255, 255, 0.78) 0%, rgba(238, 247, 227, 0.68) 100%) !important; backdrop-filter: blur(22px) saturate(180%) !important; -webkit-backdrop-filter: blur(22px) saturate(180%) !important; border-radius: 24px !important; border: 1.5px solid rgba(255, 255, 255, 0.92) !important; box-shadow: 0 24px 50px rgba(27, 94, 32, 0.15), 0 6px 16px rgba(35, 31, 32, 0.06), inset 0 1px 2px rgba(255, 255, 255, 0.95) !important; overflow: hidden !important; transition: all 0.3s ease !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-brand-ribbon { height: 6px !important; width: 100% !important; background: linear-gradient(90deg, #1B5E20 0%, #379237 38%, #68B946 72%, #DCECC9 100%) !important; }
        /* DESKTOP HEADER (> 992px): Frosted Pistachio-Sage & Fresh Leaf Glass */
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header { position: relative !important; background: linear-gradient(135deg, rgba(220, 236, 201, 0.88) 0%, rgba(238, 247, 227, 0.80) 50%, rgba(104, 185, 70, 0.30) 100%) !important; backdrop-filter: blur(18px) !important; -webkit-backdrop-filter: blur(18px) !important; padding: 28px 28px 24px 28px !important; text-align: center !important; border-bottom: 1.5px solid rgba(55, 146, 55, 0.22) !important; display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; gap: 8px !important; margin: 0 !important; border-radius: 0 !important; transition: all 0.3s ease !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 58px !important; height: 58px !important; border-radius: 18px !important; background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; border: 2px solid #DCECC9 !important; margin: 0 auto 4px auto !important; box-shadow: 0 8px 20px rgba(55, 146, 55, 0.28) !important; flex-shrink: 0 !important; color: #ffffff !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-kicker { display: inline-block !important; padding: 4px 12px !important; border-radius: 999px !important; font-size: 11px !important; font-weight: 800 !important; text-transform: uppercase !important; letter-spacing: 0.06em !important; background: rgba(55, 146, 55, 0.14) !important; color: #1B5E20 !important; border: 1px solid rgba(55, 146, 55, 0.28) !important; margin-bottom: 2px !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header-text { text-align: center !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 { margin: 0 !important; padding: 0 !important; font-size: 25px !important; line-height: 1.2 !important; font-weight: 800 !important; color: #231F20 !important; text-transform: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header p { margin: 5px 0 0 0 !important; padding: 0 !important; font-size: 13.5px !important; line-height: 1.45 !important; color: #1B5E20 !important; font-weight: 600 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body { padding: 26px 28px 30px 28px !important; background: transparent !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert { display: none; padding: 12px 15px !important; border-radius: 12px !important; font-size: 13.5px !important; line-height: 1.45 !important; margin: 0 0 18px 0 !important; font-weight: 600 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert.error { display: block !important; background: rgba(254, 242, 242, 0.94) !important; color: #991b1b !important; border: 1px solid #fecaca !important; border-left: 4px solid #dc2626 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-alert.success { display: block !important; background: rgba(238, 247, 227, 0.96) !important; color: #1B5E20 !important; border: 1px solid #b7e1a1 !important; border-left: 4px solid #379237 !important; }
        @keyframes cmpShake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }
        .cmp-shake { animation: cmpShake 0.35s ease-in-out; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-form-group { margin: 0 0 18px 0 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-form-group label { display: block !important; font-size: 13.5px !important; line-height: 1.3 !important; font-weight: 700 !important; color: #231F20 !important; margin: 0 0 7px 0 !important; text-transform: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap { position: relative !important; display: flex !important; align-items: center !important; width: 100% !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-icon { position: absolute !important; left: 14px !important; color: #379237 !important; pointer-events: none !important; display: flex !important; align-items: center !important; justify-content: center !important; z-index: 2 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="text"], #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="password"], #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="email"] { width: 100% !important; height: 48px !important; padding: 10px 44px 10px 42px !important; font-size: 15px !important; line-height: 1.4 !important; color: #231F20 !important; background: rgba(255, 255, 255, 0.86) !important; border: 1.5px solid rgba(55, 146, 55, 0.30) !important; border-radius: 12px !important; transition: all 0.2s ease !important; outline: none !important; box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.03) !important; margin: 0 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input:focus { background: #ffffff !important; border-color: #379237 !important; box-shadow: 0 0 0 4px rgba(104, 185, 70, 0.24) !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle { position: absolute !important; right: 8px !important; top: 50% !important; transform: translateY(-50%) !important; background: transparent !important; border: none !important; box-shadow: none !important; color: #526352 !important; cursor: pointer !important; padding: 6px !important; margin: 0 !important; min-height: 0 !important; min-width: 0 !important; width: 34px !important; height: 34px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 8px !important; z-index: 2 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle:hover, #cmp-auth-stage-<?php echo $uid; ?> .cmp-pwd-toggle:focus { color: #1B5E20 !important; background: rgba(220, 236, 201, 0.65) !important; outline: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-meta { display: flex !important; justify-content: space-between !important; align-items: center !important; gap: 10px !important; flex-wrap: nowrap !important; margin: 4px 0 22px 0 !important; width: 100% !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label { display: inline-flex !important; align-items: center !important; gap: 8px !important; color: #231F20 !important; cursor: pointer !important; font-size: 13.5px !important; line-height: 1.2 !important; font-weight: 600 !important; margin: 0 !important; white-space: nowrap !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label input[type="checkbox"] { width: 17px !important; height: 17px !important; min-width: 17px !important; accent-color: #379237 !important; cursor: pointer !important; margin: 0 !important; }
        #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action { background: transparent !important; border: none !important; color: #1B5E20 !important; font-weight: 700 !important; font-size: 13.5px !important; line-height: 1.2 !important; cursor: pointer !important; padding: 4px 8px !important; margin: 0 !important; border-radius: 6px !important; text-decoration: none !important; white-space: nowrap !important; display: inline-block !important; transition: all 0.2s ease !important; }
        #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action:hover { color: #1B5E20 !important; background: rgba(220, 236, 201, 0.7) !important; text-decoration: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn, #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn { width: 100% !important; min-height: 50px !important; background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; background-color: #379237 !important; color: #ffffff !important; border: 1px solid rgba(220, 236, 201, 0.45) !important; padding: 13px 20px !important; font-size: 15.5px !important; line-height: 1.3 !important; font-weight: 700 !important; text-transform: none !important; border-radius: 12px !important; cursor: pointer !important; transition: all 0.25s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 9px !important; text-decoration: none !important; box-shadow: 0 8px 20px rgba(55, 146, 55, 0.30) !important; margin: 0 !important; white-space: nowrap !important; }
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn:hover, #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn:hover { background: linear-gradient(135deg, #68B946 0%, #379237 100%) !important; background-color: #287028 !important; color: #ffffff !important; transform: translateY(-1px) !important; box-shadow: 0 10px 24px rgba(55, 146, 55, 0.38) !important; }
        #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn:disabled { opacity: 0.68 !important; cursor: not-allowed !important; transform: none !important; }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-spinner { width: 17px !important; height: 17px !important; border: 2.5px solid rgba(255,255,255,0.35) !important; border-top-color: #ffffff !important; border-radius: 50% !important; animation: cmpSpin 0.7s linear infinite !important; display: none; flex-shrink: 0 !important; }
        @keyframes cmpSpin { to { transform: rotate(360deg); } }
        #cmp-auth-stage-<?php echo $uid; ?> .cmp-user-pill { background: rgba(238, 247, 227, 0.8) !important; border: 1px solid rgba(55, 146, 55, 0.28) !important; border-radius: 12px !important; padding: 14px !important; margin-bottom: 18px !important; font-size: 14px !important; line-height: 1.5 !important; color: #231F20 !important; }
        /* TABLET HEADER (601px to 991px): Core Brand Green (#379237) to Leaf Lime (#68B946) Split Glass Header */
        @media (min-width: 601px) and (max-width: 991px) {
            #cmp-auth-stage-<?php echo $uid; ?> { max-width: 460px !important; margin: 24px auto 48px auto !important; padding: 18px 16px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header { background: linear-gradient(125deg, rgba(55, 146, 55, 0.92) 0%, rgba(104, 185, 70, 0.86) 100%) !important; flex-direction: row !important; text-align: left !important; justify-content: flex-start !important; align-items: center !important; gap: 16px !important; padding: 24px 26px !important; border-bottom: 3px solid #DCECC9 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge { background: rgba(255, 255, 255, 0.22) !important; border-color: rgba(255, 255, 255, 0.55) !important; width: 54px !important; height: 54px !important; margin: 0 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header-text { text-align: left !important; flex: 1 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-kicker { background: rgba(220, 236, 201, 0.28) !important; color: #EEF7E3 !important; border-color: rgba(220, 236, 201, 0.5) !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 { color: #ffffff !important; font-size: 23px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header p { color: #EEF7E3 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body { padding: 24px 24px 28px 24px !important; }
        }
        /* MOBILE HEADER (<= 600px): Floating Inset Deep Forest (#1B5E20) to Core Green (#379237) Glass Banner */
        @media (max-width: 600px) {
            #cmp-auth-stage-<?php echo $uid; ?> { max-width: 100% !important; margin: 12px auto 32px auto !important; padding: 8px 12px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-card { border-radius: 20px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header { background: linear-gradient(145deg, rgba(27, 94, 32, 0.95) 0%, rgba(55, 146, 55, 0.90) 100%) !important; margin: 12px 12px 0 12px !important; border-radius: 16px !important; padding: 16px 16px !important; flex-direction: row !important; text-align: left !important; justify-content: flex-start !important; align-items: center !important; gap: 13px !important; border: 1.5px solid rgba(104, 185, 70, 0.65) !important; box-shadow: 0 8px 20px rgba(27, 94, 32, 0.18) !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-badge { width: 46px !important; height: 46px !important; border-radius: 13px !important; margin: 0 !important; background: rgba(220, 236, 201, 0.2) !important; border-color: #68B946 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header-text { text-align: left !important; flex: 1 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-kicker { background: rgba(104, 185, 70, 0.28) !important; color: #DCECC9 !important; border-color: rgba(220, 236, 201, 0.4) !important; font-size: 10px !important; padding: 2px 9px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header h2 { color: #ffffff !important; font-size: 19.5px !important; line-height: 1.2 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-header p { color: #DCECC9 !important; font-size: 12px !important; margin-top: 3px !important; line-height: 1.35 !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-body { padding: 18px 16px 22px 16px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="text"], #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="password"], #cmp-auth-stage-<?php echo $uid; ?> .cmp-input-wrap input[type="email"] { font-size: 16px !important; height: 48px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-auth-meta { gap: 6px !important; margin-bottom: 18px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> .cmp-remember-label, #cmp-auth-stage-<?php echo $uid; ?> a.cmp-link-action { font-size: 13px !important; }
            #cmp-auth-stage-<?php echo $uid; ?> button.cmp-submit-btn, #cmp-auth-stage-<?php echo $uid; ?> a.cmp-submit-btn { font-size: 15px !important; min-height: 48px !important; padding: 12px 16px !important; }
        }
    </style>

    <div id="cmp-auth-stage-<?php echo $uid; ?>">
        <div class="cmp-auth-card" id="cmp-auth-card-<?php echo $uid; ?>">
            <div class="cmp-brand-ribbon"></div>
            <div class="cmp-auth-header" id="cmp-auth-header-<?php echo $uid; ?>">
                <div class="cmp-auth-badge" id="cmp-auth-badge-<?php echo $uid; ?>">
                    <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                </div>
                <div class="cmp-auth-header-text" id="cmp-auth-htext-<?php echo $uid; ?>">
                    <span class="cmp-auth-kicker" id="cmp-auth-kicker-<?php echo $uid; ?>">The Cycle Bistro</span>
                    <h2 id="cmp-auth-title-<?php echo $uid; ?>"><?php echo esc_html( $cfg['title'] ); ?></h2>
                    <p id="cmp-auth-sub-<?php echo $uid; ?>"><?php echo esc_html( $cfg['subtitle'] ); ?></p>
                </div>
            </div>

            <div class="cmp-auth-body">
                <?php if ( is_user_logged_in() ) : 
                    $curr_user = wp_get_current_user();
                ?>
                    <div class="cmp-auth-alert error" style="display:block !important;">
                        <strong>Access Restricted:</strong> <?php echo esc_html( $cfg['denied_message'] ); ?>
                    </div>
                    <div class="cmp-user-pill">
                        Currently signed in as:<br>
                        <strong style="color:#231F20;"><?php echo esc_html( $curr_user->display_name ); ?></strong>
                        <span style="color:#526352;">(<?php echo esc_html( $curr_user->user_email ); ?>)</span>
                    </div>
                    <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="cmp-submit-btn">
                        Log Out &amp; Switch Account
                    </a>

                <?php else : ?>
                    <div id="cmp-login-view-<?php echo $uid; ?>">
                        <div id="cmp-login-alert-<?php echo $uid; ?>" class="cmp-auth-alert <?php echo $url_failed ? 'error' : ''; ?>">
                            <?php if ( $url_failed ) echo 'Incorrect username/email or password. Please try again.'; ?>
                        </div>

                        <form id="cmp-login-form-<?php echo $uid; ?>" novalidate>
                            <div class="cmp-form-group">
                                <label for="cmp-user-<?php echo $uid; ?>">Email Address or Username</label>
                                <div class="cmp-input-wrap">
                                    <span class="cmp-input-icon">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="12" cy="7" r="4"></circle>
                                        </svg>
                                    </span>
                                    <input type="text" id="cmp-user-<?php echo $uid; ?>" name="log" placeholder="name@thecyclebistro.com" autocomplete="username" required>
                                </div>
                            </div>

                            <div class="cmp-form-group">
                                <label for="cmp-pass-<?php echo $uid; ?>">Password</label>
                                <div class="cmp-input-wrap">
                                    <span class="cmp-input-icon">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                        </svg>
                                    </span>
                                    <input type="password" id="cmp-pass-<?php echo $uid; ?>" name="pwd" placeholder="Enter your password" autocomplete="current-password" required>
                                    <button type="button" class="cmp-pwd-toggle" id="cmp-pwd-toggle-<?php echo $uid; ?>" aria-label="Show password" title="Show / Hide Password">
                                        <svg id="cmp-eye-open-<?php echo $uid; ?>" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg id="cmp-eye-closed-<?php echo $uid; ?>" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="cmp-auth-meta">
                                <label class="cmp-remember-label">
                                    <input type="checkbox" id="cmp-remember-<?php echo $uid; ?>" name="rememberme" value="forever">
                                    <span>Remember me</span>
                                </label>
                                <a href="#forgot-password" class="cmp-link-action" id="cmp-show-forgot-<?php echo $uid; ?>">Forgot Password?</a>
                            </div>

                            <button type="submit" class="cmp-submit-btn" id="cmp-login-btn-<?php echo $uid; ?>">
                                <span class="cmp-spinner" id="cmp-login-spin-<?php echo $uid; ?>"></span>
                                <span id="cmp-login-btn-text-<?php echo $uid; ?>">Sign In</span>
                                <svg id="cmp-login-arrow-<?php echo $uid; ?>" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                        </form>
                    </div>

                    <div id="cmp-forgot-view-<?php echo $uid; ?>" style="display:none;">
                        <div id="cmp-forgot-alert-<?php echo $uid; ?>" class="cmp-auth-alert"></div>

                        <p style="margin: 0 0 16px 0 !important; font-size: 13.5px !important; color: #231F20 !important; line-height: 1.5 !important;">
                            Enter your account email or username and we will send you a password reset link.
                        </p>

                        <form id="cmp-forgot-form-<?php echo $uid; ?>" novalidate>
                            <div class="cmp-form-group">
                                <label for="cmp-reset-user-<?php echo $uid; ?>">Email Address or Username</label>
                                <div class="cmp-input-wrap">
                                    <span class="cmp-input-icon">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                            <polyline points="22,6 12,13 2,6"></polyline>
                                        </svg>
                                    </span>
                                    <input type="text" id="cmp-reset-user-<?php echo $uid; ?>" name="user_login" placeholder="name@thecyclebistro.com" autocomplete="username" required>
                                </div>
                            </div>

                            <button type="submit" class="cmp-submit-btn" id="cmp-forgot-btn-<?php echo $uid; ?>">
                                <span class="cmp-spinner" id="cmp-forgot-spin-<?php echo $uid; ?>"></span>
                                <span id="cmp-forgot-btn-text-<?php echo $uid; ?>">Send Reset Link</span>
                            </button>
                        </form>

                        <div style="text-align: center !important; margin-top: 18px !important;">
                            <a href="#sign-in" class="cmp-link-action" id="cmp-back-login-<?php echo $uid; ?>">&larr; Back to Sign In</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const uid      = '<?php echo esc_js( $uid ); ?>';
        const headerEl = document.getElementById('cmp-auth-header-' + uid);
        const htextEl  = document.getElementById('cmp-auth-htext-' + uid);
        const kickerEl = document.getElementById('cmp-auth-kicker-' + uid);
        const titleEl  = document.getElementById('cmp-auth-title-' + uid);
        const subEl    = document.getElementById('cmp-auth-sub-' + uid);

        function applyResponsiveHeaderTheme() {
            if (!headerEl) return;
            const w = window.innerWidth;
            if (w <= 600) {
                headerEl.style.setProperty('background', 'linear-gradient(145deg, rgba(27, 94, 32, 0.95) 0%, rgba(55, 146, 55, 0.90) 100%)', 'important');
                headerEl.style.setProperty('flex-direction', 'row', 'important');
                headerEl.style.setProperty('text-align', 'left', 'important');
                headerEl.style.setProperty('margin', '12px 12px 0 12px', 'important');
                headerEl.style.setProperty('border-radius', '16px', 'important');
                headerEl.style.setProperty('padding', '16px', 'important');
                if (htextEl) htextEl.style.setProperty('text-align', 'left', 'important');
                if (titleEl) titleEl.style.setProperty('color', '#ffffff', 'important');
                if (subEl) subEl.style.setProperty('color', '#DCECC9', 'important');
                if (kickerEl) {
                    kickerEl.style.setProperty('background', 'rgba(104, 185, 70, 0.28)', 'important');
                    kickerEl.style.setProperty('color', '#DCECC9', 'important');
                }
            } else if (w <= 991) {
                headerEl.style.setProperty('background', 'linear-gradient(125deg, rgba(55, 146, 55, 0.92) 0%, rgba(104, 185, 70, 0.86) 100%)', 'important');
                headerEl.style.setProperty('flex-direction', 'row', 'important');
                headerEl.style.setProperty('text-align', 'left', 'important');
                headerEl.style.setProperty('margin', '0', 'important');
                headerEl.style.setProperty('border-radius', '0', 'important');
                headerEl.style.setProperty('padding', '24px 26px', 'important');
                if (htextEl) htextEl.style.setProperty('text-align', 'left', 'important');
                if (titleEl) titleEl.style.setProperty('color', '#ffffff', 'important');
                if (subEl) subEl.style.setProperty('color', '#EEF7E3', 'important');
                if (kickerEl) {
                    kickerEl.style.setProperty('background', 'rgba(220, 236, 201, 0.28)', 'important');
                    kickerEl.style.setProperty('color', '#EEF7E3', 'important');
                }
            } else {
                headerEl.style.setProperty('background', 'linear-gradient(135deg, rgba(220, 236, 201, 0.88) 0%, rgba(238, 247, 227, 0.80) 50%, rgba(104, 185, 70, 0.30) 100%)', 'important');
                headerEl.style.setProperty('flex-direction', 'column', 'important');
                headerEl.style.setProperty('text-align', 'center', 'important');
                headerEl.style.setProperty('margin', '0', 'important');
                headerEl.style.setProperty('border-radius', '0', 'important');
                headerEl.style.setProperty('padding', '28px 28px 24px 28px', 'important');
                if (htextEl) htextEl.style.setProperty('text-align', 'center', 'important');
                if (titleEl) titleEl.style.setProperty('color', '#231F20', 'important');
                if (subEl) subEl.style.setProperty('color', '#1B5E20', 'important');
                if (kickerEl) {
                    kickerEl.style.setProperty('background', 'rgba(55, 146, 55, 0.14)', 'important');
                    kickerEl.style.setProperty('color', '#1B5E20', 'important');
                }
            }
        }

        applyResponsiveHeaderTheme();
        window.addEventListener('resize', applyResponsiveHeaderTheme);

        <?php if ( ! is_user_logged_in() ) : ?>
        const ajaxUrl     = '<?php echo $ajax_url; ?>';
        const nonce       = '<?php echo esc_js( $nonce ); ?>';
        const reqCaps     = '<?php echo esc_js( $caps_csv ); ?>';
        const pTitle      = '<?php echo esc_js( $cfg['title'] ); ?>';
        const reloadUrl   = '<?php echo $current_url; ?>';

        const card        = document.getElementById('cmp-auth-card-' + uid);
        const loginView   = document.getElementById('cmp-login-view-' + uid);
        const forgotView  = document.getElementById('cmp-forgot-view-' + uid);
        const loginAlert  = document.getElementById('cmp-login-alert-' + uid);
        const forgotAlert = document.getElementById('cmp-forgot-alert-' + uid);

        function showAlert(el, type, message) {
            if (!el) return;
            el.textContent = message;
            el.className = 'cmp-auth-alert ' + type;
            el.style.setProperty('display', 'block', 'important');
        }

        function hideAlert(el) {
            if (!el) return;
            el.textContent = '';
            el.className = 'cmp-auth-alert';
            el.style.setProperty('display', 'none', 'important');
        }

        function triggerCardShake() {
            if (!card) return;
            card.classList.remove('cmp-shake');
            void card.offsetWidth;
            card.classList.add('cmp-shake');
        }

        const passInput = document.getElementById('cmp-pass-' + uid);
        const pwdToggle = document.getElementById('cmp-pwd-toggle-' + uid);
        const eyeOpen   = document.getElementById('cmp-eye-open-' + uid);
        const eyeClosed = document.getElementById('cmp-eye-closed-' + uid);

        if (pwdToggle && passInput) {
            pwdToggle.addEventListener('click', function(e) {
                e.preventDefault();
                const isPwd = passInput.type === 'password';
                passInput.type = isPwd ? 'text' : 'password';
                eyeOpen.style.display = isPwd ? 'none' : 'block';
                eyeClosed.style.display = isPwd ? 'block' : 'none';
                passInput.focus();
            });
        }

        const showForgotBtn = document.getElementById('cmp-show-forgot-' + uid);
        const backLoginBtn  = document.getElementById('cmp-back-login-' + uid);
        const loginUserInp  = document.getElementById('cmp-user-' + uid);
        const resetUserInp  = document.getElementById('cmp-reset-user-' + uid);

        if (showForgotBtn) {
            showForgotBtn.addEventListener('click', function(e) {
                e.preventDefault();
                hideAlert(forgotAlert);
                loginView.style.display = 'none';
                forgotView.style.display = 'block';
                if (loginUserInp && loginUserInp.value.trim() !== '') {
                    resetUserInp.value = loginUserInp.value.trim();
                }
                resetUserInp.focus();
            });
        }

        if (backLoginBtn) {
            backLoginBtn.addEventListener('click', function(e) {
                e.preventDefault();
                hideAlert(loginAlert);
                forgotView.style.display = 'none';
                loginView.style.display = 'block';
                loginUserInp.focus();
            });
        }

        const loginForm    = document.getElementById('cmp-login-form-' + uid);
        const loginBtn     = document.getElementById('cmp-login-btn-' + uid);
        const loginSpin    = document.getElementById('cmp-login-spin-' + uid);
        const loginBtnText = document.getElementById('cmp-login-btn-text-' + uid);
        const loginArrow   = document.getElementById('cmp-login-arrow-' + uid);

        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                hideAlert(loginAlert);

                const logVal = loginUserInp.value.trim();
                const pwdVal = passInput.value;
                const remVal = document.getElementById('cmp-remember-' + uid).checked ? '1' : '';

                if (!logVal || !pwdVal) {
                    showAlert(loginAlert, 'error', 'Please enter both your email/username and password.');
                    triggerCardShake();
                    return;
                }

                loginBtn.disabled = true;
                loginSpin.style.display = 'inline-block';
                if (loginArrow) loginArrow.style.display = 'none';
                loginBtnText.textContent = 'Signing in...';

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_portal_login');
                formData.append('nonce', nonce);
                formData.append('log', logVal);
                formData.append('pwd', pwdVal);
                formData.append('rememberme', remVal);
                formData.append('required_caps', reqCaps);
                formData.append('portal_title', pTitle);

                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(res => res.json())
                .then(response => {
                    if (response && response.success) {
                        showAlert(loginAlert, 'success', response.data.message);
                        loginBtnText.textContent = 'Loading...';
                        window.location.href = reloadUrl || window.location.href;
                    } else {
                        const errMsg = (response && response.data && response.data.message)
                            ? response.data.message
                            : 'Incorrect username/email or password. Please try again.';
                        showAlert(loginAlert, 'error', errMsg);
                        triggerCardShake();
                        loginBtn.disabled = false;
                        loginSpin.style.display = 'none';
                        if (loginArrow) loginArrow.style.display = 'inline-block';
                        loginBtnText.textContent = 'Sign In';
                        passInput.value = '';
                        passInput.focus();
                    }
                })
                .catch(() => {
                    showAlert(loginAlert, 'error', 'Unable to verify credentials right now. Please try again.');
                    triggerCardShake();
                    loginBtn.disabled = false;
                    loginSpin.style.display = 'none';
                    if (loginArrow) loginArrow.style.display = 'inline-block';
                    loginBtnText.textContent = 'Sign In';
                });
            });
        }

        const forgotForm    = document.getElementById('cmp-forgot-form-' + uid);
        const forgotBtn     = document.getElementById('cmp-forgot-btn-' + uid);
        const forgotSpin    = document.getElementById('cmp-forgot-spin-' + uid);
        const forgotBtnText = document.getElementById('cmp-forgot-btn-text-' + uid);

        if (forgotForm) {
            forgotForm.addEventListener('submit', function(e) {
                e.preventDefault();
                hideAlert(forgotAlert);

                const userVal = resetUserInp.value.trim();
                if (!userVal) {
                    showAlert(forgotAlert, 'error', 'Please enter your email address or username.');
                    triggerCardShake();
                    return;
                }

                forgotBtn.disabled = true;
                forgotSpin.style.display = 'inline-block';
                forgotBtnText.textContent = 'Sending...';

                const formData = new URLSearchParams();
                formData.append('action', 'cmp_portal_forgot_password');
                formData.append('nonce', nonce);
                formData.append('user_login', userVal);

                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(res => res.json())
                .then(response => {
                    if (response && response.success) {
                        showAlert(forgotAlert, 'success', response.data.message);
                        resetUserInp.value = '';
                    } else {
                        const errMsg = (response && response.data && response.data.message)
                            ? response.data.message
                            : 'Could not process password reset request.';
                        showAlert(forgotAlert, 'error', errMsg);
                        triggerCardShake();
                    }
                })
                .catch(() => {
                    showAlert(forgotAlert, 'error', 'Network error occurred. Please try again.');
                    triggerCardShake();
                })
                .finally(() => {
                    forgotBtn.disabled = false;
                    forgotSpin.style.display = 'none';
                    forgotBtnText.textContent = 'Send Reset Link';
                });
            });
        }
        <?php endif; ?>
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ==========================================
// 3. MAIN DASHBOARD VIEW
// ==========================================
function cmp_render_accounts_dashboard() {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    
    // Fetch Dynamic Lookback Defaults from WP Settings
    $foh_days = intval(get_option('cmp_pos_alert_days', '7'));
    $kit_days = intval(get_option('cmp_kitchen_alert_days', '7'));

    // Filters & Active Selections
    $dash_start = isset($_GET['dash_start']) ? sanitize_text_field($_GET['dash_start']) : date('Y-m-d', strtotime('-3 months'));
    $dash_end   = isset($_GET['dash_end']) ? sanitize_text_field($_GET['dash_end']) : date('Y-m-d');
    
    $pos_start  = isset($_GET['pos_start']) ? sanitize_text_field($_GET['pos_start']) : date('Y-m-d', strtotime("-{$foh_days} days"));
    $pos_end    = isset($_GET['pos_end']) ? sanitize_text_field($_GET['pos_end']) : date('Y-m-d');

    $kit_start  = isset($_GET['kit_start']) ? sanitize_text_field($_GET['kit_start']) : date('Y-m-d', strtotime("-{$kit_days} days"));
    $kit_end    = isset($_GET['kit_end']) ? sanitize_text_field($_GET['kit_end']) : date('Y-m-d');

    // --- KITCHEN COMPLIANCE (COUNT ONLY FOR PERFORMANCE) ---
    $pending_kitchen_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l 
         WHERE l.is_locked = 1 
         AND (l.is_prepared = 0 OR l.dispatch_status = 0 OR l.delivery_result = 'Pending') 
         AND l.target_date >= %s AND l.target_date <= %s",
        $kit_start, $kit_end
    ));

    // --- POS COMPLIANCE (COUNT ONLY FOR PERFORMANCE) ---
    $pending_pos_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(l.id) FROM $table_logs l 
         WHERE l.pos_updated = 0 AND l.delivery_result != 'Pending' AND l.is_locked = 1 
         AND l.target_date >= %s AND l.target_date <= %s",
        $pos_start, $pos_end
    ));

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
        .acc-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; padding: 0 12px; }
        /* Desktop Dashboard Glass Header (> 992px) */
        .acc-header { background: linear-gradient(135deg, rgba(220, 236, 201, 0.90) 0%, rgba(238, 247, 227, 0.82) 50%, rgba(104, 185, 70, 0.32) 100%) !important; backdrop-filter: blur(18px) !important; -webkit-backdrop-filter: blur(18px) !important; color: #231F20 !important; padding: 26px 30px !important; border-radius: 18px !important; border: 1.5px solid rgba(55, 146, 55, 0.28) !important; border-top: 5px solid #379237 !important; box-shadow: 0 14px 34px rgba(27, 94, 32, 0.10) !important; display: flex !important; justify-content: space-between !important; align-items: center !important; margin-bottom: 30px !important; flex-wrap: wrap !important; gap: 15px !important; }
        .acc-header h1 { margin: 0 !important; color: #231F20 !important; font-size: 2em !important; font-weight: 800 !important; }
        .acc-header p { margin: 5px 0 0 0 !important; color: #1B5E20 !important; font-weight: 600 !important; }
        .acc-header .acc-logout-btn { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important; color: #ffffff !important; text-decoration: none !important; padding: 10px 22px !important; border-radius: 10px !important; font-weight: 700 !important; border: 1px solid rgba(220, 236, 201, 0.5) !important; box-shadow: 0 6px 16px rgba(55, 146, 55, 0.25) !important; display: inline-block !important; }
        /* Tablet Dashboard Glass Header (601px - 991px) */
        @media (min-width: 601px) and (max-width: 991px) {
            .acc-header { background: linear-gradient(125deg, rgba(55, 146, 55, 0.92) 0%, rgba(104, 185, 70, 0.86) 100%) !important; border-top: 5px solid #DCECC9 !important; padding: 22px 24px !important; }
            .acc-header h1 { color: #ffffff !important; font-size: 1.75em !important; }
            .acc-header p { color: #EEF7E3 !important; }
            .acc-header .acc-logout-btn { background: rgba(255, 255, 255, 0.2) !important; border: 1.5px solid #DCECC9 !important; }
        }
        /* Mobile Dashboard Glass Header (<= 600px) */
        @media (max-width: 600px) {
            .acc-header { background: linear-gradient(145deg, rgba(27, 94, 32, 0.96) 0%, rgba(55, 146, 55, 0.90) 100%) !important; border: 1.5px solid #68B946 !important; border-radius: 16px !important; padding: 18px 18px !important; }
            .acc-header h1 { color: #ffffff !important; font-size: 1.5em !important; }
            .acc-header p { color: #DCECC9 !important; font-size: 0.9em !important; }
            .acc-header .acc-logout-btn { background: rgba(220, 236, 201, 0.2) !important; border: 1px solid #DCECC9 !important; padding: 8px 16px !important; font-size: 0.9em !important; }
        }
        .acc-section-title { font-size: 1.4em; color: #231F20; margin: 0 0 20px 0; border-bottom: 2px solid #DCECC9; padding-bottom: 10px; font-weight: 800; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:10px; }
        .acc-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .acc-kpi-card { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); padding: 25px; border-radius: 14px; border: 1px solid #DCECC9; border-left: 5px solid #379237; box-shadow: 0 6px 16px rgba(27, 94, 32, 0.06); cursor: pointer; transition: 0.2s; position: relative; text-decoration:none; display:block; }
        .acc-kpi-card:hover { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(27, 94, 32, 0.12); }
        .acc-kpi-card.active-tab { box-shadow: 0 0 0 3px rgba(55, 146, 55, 0.22); border-color:#379237; background: #f8fcf5; }
        .acc-kpi-card.green { border-left-color: #379237; }
        .acc-kpi-card.orange { border-left-color: #f59e0b; }
        .acc-kpi-card.red { border-left-color: #ef4444; }
        .acc-kpi-val { font-size: 2.8em; font-weight: 900; color: #231F20; margin: 5px 0 0 0; line-height: 1; pointer-events: none;}
        .acc-filter { display: flex; gap: 10px; align-items: center; font-size: 0.85em; font-weight: normal; flex-wrap: wrap; }
        .acc-filter input[type="date"] { padding: 6px 10px; border: 1px solid #b7e1a1; border-radius: 6px; color: #231F20; background: #fff; }
        .acc-filter button { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: white; border: none; padding: 7px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .acc-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 4px 14px rgba(27, 94, 32, 0.06); font-size: 0.9em; border-radius:12px; overflow:hidden; }
        .acc-table th { background: #EEF7E3; color: #1B5E20; padding: 12px; text-align: left; border-bottom: 2px solid #DCECC9; }
        .acc-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .acc-btn { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: #fff; padding: 8px 15px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block; transition:0.2s;}
        .acc-btn:hover { background: linear-gradient(135deg, #68B946 0%, #379237 100%); color: #fff; }
        .acc-tab-content { display: none; }
        .acc-tab-content.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    </style>

    <div class="acc-wrap">
        <div class="acc-header cmp-no-print">
            <div>
                <h1>Accounts Portal</h1>
                <p>The Cycle Bistro &mdash; Meal Plan Monitoring</p>
            </div>
            <div>
                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="acc-logout-btn">Log Out</a>
            </div>
        </div>

        <!-- COMPLIANCE TILES -->
        <div class="acc-section-title cmp-no-print" style="margin-top: 10px;">
            <span style="color:#231F20;">Operational Auditing</span>
            <form method="GET" class="acc-filter">
                <input type="hidden" name="dash_start" value="<?php echo esc_attr($dash_start); ?>">
                <input type="hidden" name="dash_end" value="<?php echo esc_attr($dash_end); ?>">
                <span>Audit Period:</span>
                <input type="date" name="kit_start" value="<?php echo esc_attr($kit_start); ?>"> <span>to</span>
                <input type="date" name="kit_end" value="<?php echo esc_attr($kit_end); ?>">
                <button type="submit" style="background:#ea580c;">Apply to Both</button>
            </form>
        </div>

        <div class="acc-kpi-grid cmp-no-print">
            <a href="?view=kitchen_audit&kit_start=<?php echo esc_attr($kit_start); ?>&kit_end=<?php echo esc_attr($kit_end); ?>" class="acc-kpi-card <?php echo $pending_kitchen_count > 0 ? 'red' : 'green'; ?>">
                <div style="font-weight:800; color:#1B5E20; text-transform:uppercase;">Kitchen Compliance</div>
                <div class="acc-kpi-val"><?php echo number_format($pending_kitchen_count); ?></div>
                <div style="font-size:0.85em; color:#64748b; margin-top:5px; font-weight:bold;">Incomplete Kitchen Logs (Click to view)</div>
            </a>
            
            <a href="?view=pos_audit&pos_start=<?php echo esc_attr($kit_start); ?>&pos_end=<?php echo esc_attr($kit_end); ?>" class="acc-kpi-card <?php echo $pending_pos_count > 0 ? 'orange' : 'green'; ?>">
                <div style="font-weight:800; color:#1B5E20; text-transform:uppercase;">POS Reconciliations</div>
                <div class="acc-kpi-val"><?php echo number_format($pending_pos_count); ?></div>
                <div style="font-size:0.85em; color:#64748b; margin-top:5px; font-weight:bold;">Pending POS Checks (Click to view)</div>
            </a>
        </div>


        <!-- SUBSCRIPTION METRICS TILES -->
        <div class="acc-section-title cmp-no-print" style="margin-top: 50px;">
            <span>Subscription Metrics</span>
            <form method="GET" class="acc-filter">
                <input type="hidden" name="kit_start" value="<?php echo esc_attr($kit_start); ?>">
                <input type="hidden" name="kit_end" value="<?php echo esc_attr($kit_end); ?>">
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
                <div style="font-weight:800; color:#1B5E20; text-transform:uppercase; pointer-events:none;">Active Plans</div>
                <div class="acc-kpi-val"><?php echo count($active_subs); ?></div>
                <div style="font-size:0.85em; color:#64748b; margin-top:5px; pointer-events:none;">Originating in selected period</div>
            </div>
            <div class="acc-kpi-card orange" onclick="switchAccTab('acc-paused', this)">
                <div style="font-weight:800; color:#1B5E20; text-transform:uppercase; pointer-events:none;">Paused Plans</div>
                <div class="acc-kpi-val"><?php echo count($paused_subs); ?></div>
                <div style="font-size:0.85em; color:#64748b; margin-top:5px; pointer-events:none;">Originating in selected period</div>
            </div>
            <div class="acc-kpi-card red" onclick="switchAccTab('acc-completed', this)">
                <div style="font-weight:800; color:#1B5E20; text-transform:uppercase; pointer-events:none;">Completed / Expired</div>
                <div class="acc-kpi-val"><?php echo count($completed_subs); ?></div>
                <div style="font-size:0.85em; color:#64748b; margin-top:5px; pointer-events:none;">Fully consumed or expired</div>
            </div>
        </div>

        <!-- HELPER FUNCTION FOR TABLES -->
        <?php 
        function render_accounts_sub_table($subs_array, $table_id, $status_filter, $dash_start, $dash_end, $is_active = false) {
            $display = $is_active ? 'active' : '';
            $title = ucfirst($status_filter) . ' Plans';
            if($status_filter == 'completed') $title = 'Completed/Expired Plans';

            echo "<div id='{$table_id}' class='acc-tab-content {$display}'>
                    <div style='display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:15px; flex-wrap:wrap; gap:10px;'>
                        <h3 style='margin:0; color:#231F20;'>{$title}</h3>
                        <div class='cmp-no-print' style='display:flex; gap:10px;'>
                            <a href='" . esc_url(admin_url('admin-ajax.php?action=cmp_export_accounts_main&status_filter='.$status_filter.'&start='.$dash_start.'&end='.$dash_end)) . "' style='background:#379237; color:white; padding:8px 15px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:0.9em;'>Export {$title} to CSV</a>
                            <button onclick='window.print()' style='background:#475569; color:white; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:0.9em;'>Print PDF</button>
                        </div>
                    </div>
                    <div style='overflow-x:auto;'><table class='acc-table'>
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
                    else { $status_color = '#379237'; $status_label = 'ACTIVE'; }

                    $display_order_id = '<span style="color:#ef4444;">Manual</span>';
                    if ($sub->wc_order_id < 0) {
                        $display_order_id = 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT);
                    } elseif ($sub->wc_order_id > 0) {
                        $display_order_id = '#' . esc_html($sub->wc_order_id);
                    }

                    // Deposit Logic for Pill
                    global $wpdb;
                    $deposit_held = get_user_meta($sub->user_id, '_cmp_deposit_held', true);
                    if ($deposit_held === '') {
                        $past_plans = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d", $sub->user_id));
                        $deposit_held = (intval($past_plans) > 0) ? 'yes' : 'no';
                    }
                    $b_color = '#dc2626'; $b_bg = '#fee2e2'; $b_text = 'Refunded / No Deposit';
                    if ($deposit_held === 'yes') { $b_color = '#379237'; $b_bg = '#dcfce7'; $b_text = 'Bag Deposit Held'; }
                    if ($deposit_held === 'own_bag') { $b_color = '#8b5cf6'; $b_bg = '#ede9fe'; $b_text = "Customer's Own Bag"; }

                    echo "<tr>
                        <td><strong style='color:#231F20; font-size:1.1em;'>{$display_order_id}</strong></td>
                        <td>
                            <strong style='color:#379237;'>" . esc_html($name) . "</strong><br>
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
                            <div class='deposit-pill-wrapper' data-user-id='{$sub->user_id}' style='margin-top:8px;'>
                                <span class='deposit-status-pill' style='color:{$b_color}; font-weight:bold; background:{$b_bg}; padding:4px 8px; border-radius:12px; font-size:0.75em; display:inline-block; cursor:pointer; border:1px solid {$b_color}; transition:0.2s;' title='Click to Change Status'>
                                    {$b_text} &#9662;
                                </span>
                                <select class='deposit-status-select' style='display:none; width:100%; padding:4px; font-size:0.8em; border-radius:4px; border:1px solid #cbd5e1; margin-top:4px;'>
                                    <option value='yes' ".selected($deposit_held, 'yes', false).">Held (Waived)</option>
                                    <option value='own_bag' ".selected($deposit_held, 'own_bag', false).">Own Bag (Waived)</option>
                                    <option value='no' ".selected($deposit_held, 'no', false).">Refunded / No Deposit</option>
                                </select>
                            </div>
                        </td>
                    </tr>";
                }
            }
            echo "</tbody></table></div></div>";
        }
        ?>

        <!-- Render the 3 Tables -->
        <?php render_accounts_sub_table($active_subs, 'acc-active', 'active', $dash_start, $dash_end, true); ?>
        <?php render_accounts_sub_table($paused_subs, 'acc-paused', 'paused', $dash_start, $dash_end, false); ?>
        <?php render_accounts_sub_table($completed_subs, 'acc-completed', 'completed', $dash_start, $dash_end, false); ?>

    </div>

    <script>
    function switchAccTab(targetId, cardElement) {
        var contents = document.getElementsByClassName('acc-tab-content');
        for (var i = 0; i < contents.length; i++) { contents[i].classList.remove('active'); }
        var cards = document.getElementsByClassName('acc-kpi-card');
        for (var i = 0; i < cards.length; i++) { cards[i].classList.remove('active-tab'); }
        document.getElementById(targetId).classList.add('active');
        cardElement.classList.add('active-tab');
    }
    </script>
    <?php
    cmp_render_acc_bag_script();
    return ob_get_clean();
}


// ==========================================
// DRILL-DOWN: KITCHEN AUDIT VIEW
// ==========================================
function cmp_render_kitchen_audit_view() {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    
    $kit_days = intval(get_option('cmp_kitchen_alert_days', '7'));
    $kit_start = isset($_GET['kit_start']) ? sanitize_text_field($_GET['kit_start']) : date('Y-m-d', strtotime("-{$kit_days} days"));
    $kit_end = isset($_GET['kit_end']) ? sanitize_text_field($_GET['kit_end']) : date('Y-m-d');

    $pending_kitchen = $wpdb->get_results($wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.user_id, s.plan_name, u.display_name FROM $table_logs l 
         JOIN $table_subs s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
         WHERE l.is_locked = 1 
         AND (l.is_prepared = 0 OR l.dispatch_status = 0 OR l.delivery_result = 'Pending') 
         AND l.target_date >= %s AND l.target_date <= %s ORDER BY l.target_date DESC",
        $kit_start, $kit_end
    ));
    $pending_kitchen = cmp_process_timing_data($pending_kitchen);

    ob_start();
    ?>
    <style>
        .acc-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; }
        .acc-filter { display: flex; gap: 10px; align-items: center; font-size: 0.85em; font-weight: normal; }
        .acc-filter input[type="date"] { padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; color: #334155; }
        .acc-filter button { background: #379237; color: white; border: none; padding: 7px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .acc-section-title { font-size: 1.4em; color: #231F20; margin: 0 0 20px 0; border-bottom: 2px solid #DCECC9; padding-bottom: 10px; font-weight: 800; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:10px; }
    </style>
    <div class="acc-wrap">
        <div class="cmp-no-print" style="margin-bottom: 20px;">
            <a href="?" style="color:#379237; text-decoration:none; font-weight:bold;">&larr; Back to Dashboard</a>
        </div>
        
        <div class="acc-section-title cmp-no-print">
            <span style="color:#231F20;">Audit: Kitchen Operations</span>
            <form method="GET" class="acc-filter">
                <input type="hidden" name="view" value="kitchen_audit">
                <span>Period:</span>
                <input type="date" name="kit_start" value="<?php echo esc_attr($kit_start); ?>"> <span>to</span>
                <input type="date" name="kit_end" value="<?php echo esc_attr($kit_end); ?>">
                <button type="submit" style="background:#ea580c;">Apply</button>
            </form>
        </div>

        <?php if(empty($pending_kitchen)): ?>
            <div style="background:#f0fdf4; color:#166534; padding:20px; border-radius:8px; border:1px solid #bbf7d0; font-weight:bold;">
                ✓ Perfect compliance. No pending Kitchen tasks found.
            </div>
        <?php else: ?>
            <div style="background:#fff7ed; padding:20px; border-radius:8px; border:1px solid #fed7aa;">
                <h4 style="margin:0 0 15px 0; color:#c2410c; font-size:1.1em;">Attention: <?php echo count($pending_kitchen); ?> Incomplete Kitchen Logs</h4>
                <div style="overflow-x: auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.9em; text-align:left;">
                        <tr style="border-bottom:2px solid #fdba74; color:#9a3412;">
                            <th style="padding:12px;">Meal Date (Eating)</th>
                            <th style="padding:12px; border-right:2px solid #fdba74;">Delivery Date</th>
                            <th style="padding:12px;">Order ID</th>
                            <th style="padding:12px;">Customer</th>
                            <th style="padding:12px;">Plan</th>
                            <th style="padding:12px;">Missing Step(s)</th>
                        </tr>
                        <?php foreach($pending_kitchen as $k): 
                            $missing_badges = [];
                            if (!$k->is_prepared) $missing_badges[] = '<span style="background:#fee2e2; color:#991b1b; padding:2px 6px; border-radius:4px; font-weight:bold; font-size:0.85em;">Prep</span>';
                            if (!$k->dispatch_status) $missing_badges[] = '<span style="background:#fef3c7; color:#92400e; padding:2px 6px; border-radius:4px; font-weight:bold; font-size:0.85em;">Dispatch</span>';
                            if ($k->delivery_result === 'Pending') $missing_badges[] = '<span style="background:#e0e7ff; color:#3730a3; padding:2px 6px; border-radius:4px; font-weight:bold; font-size:0.85em;">Delivery Result</span>';
                            
                            $display_order_id = 'Manual';
                            if ($k->wc_order_id < 0) { $display_order_id = 'M-' . str_pad(abs($k->wc_order_id), 4, '0', STR_PAD_LEFT); } 
                            elseif ($k->wc_order_id > 0) { $display_order_id = '#' . $k->wc_order_id; }
                        ?>
                        <tr style="border-bottom:1px solid #fed7aa;">
                            <td style="padding:12px;">
                                <strong><?php echo date('d M Y', strtotime($k->target_date)); ?></strong><br>
                                <span style="font-size:0.85em; color:#c2410c;">(<?php echo $k->timing_label; ?>)</span>
                            </td>
                            <td style="padding:12px; border-right:2px solid #fed7aa; font-weight:bold; color:#c2410c;"><?php echo date('d M Y', strtotime($k->computed_delivery_date)); ?></td>
                            <td style="padding:12px;"><?php echo $display_order_id; ?></td>
                            <td style="padding:12px;"><?php echo esc_html($k->display_name); ?></td>
                            <td style="padding:12px;"><?php echo esc_html($k->plan_name); ?></td>
                            <td style="padding:12px;">
                                <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                    <?php echo implode('', $missing_badges); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ==========================================
// DRILL-DOWN: POS AUDIT VIEW
// ==========================================
function cmp_render_pos_audit_view() {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    
    $foh_days = intval(get_option('cmp_pos_alert_days', '7'));
    $pos_start = isset($_GET['pos_start']) ? sanitize_text_field($_GET['pos_start']) : date('Y-m-d', strtotime("-{$foh_days} days"));
    $pos_end = isset($_GET['pos_end']) ? sanitize_text_field($_GET['pos_end']) : date('Y-m-d');

    $pending_pos = $wpdb->get_results($wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.user_id, s.plan_name, u.display_name FROM $table_logs l 
         JOIN $table_subs s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
         WHERE l.pos_updated = 0 AND l.delivery_result != 'Pending' AND l.is_locked = 1 AND l.target_date >= %s AND l.target_date <= %s ORDER BY l.target_date DESC",
        $pos_start, $pos_end
    ));
    $completed_pos = $wpdb->get_results($wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.user_id, s.plan_name, u.display_name FROM $table_logs l 
         JOIN $table_subs s ON l.subscription_id = s.id 
         JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
         WHERE l.pos_updated = 1 AND l.delivery_result != 'Pending' AND l.is_locked = 1 AND l.target_date >= %s AND l.target_date <= %s ORDER BY l.target_date DESC",
        $pos_start, $pos_end
    ));

    $pending_pos = cmp_process_timing_data($pending_pos);
    $completed_pos = cmp_process_timing_data($completed_pos);

    ob_start();
    ?>
    <style>
        .acc-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; }
        .acc-filter { display: flex; gap: 10px; align-items: center; font-size: 0.85em; font-weight: normal; }
        .acc-filter input[type="date"] { padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; color: #334155; }
        .acc-filter button { background: #379237; color: white; border: none; padding: 7px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .acc-section-title { font-size: 1.4em; color: #231F20; margin: 0 0 20px 0; border-bottom: 2px solid #DCECC9; padding-bottom: 10px; font-weight: 800; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:10px; }
    </style>
    <div class="acc-wrap">
        <div class="cmp-no-print" style="margin-bottom: 20px;">
            <a href="?" style="color:#379237; text-decoration:none; font-weight:bold;">&larr; Back to Dashboard</a>
        </div>

        <div class="acc-section-title cmp-no-print">
            <span style="color:#231F20;">Audit: POS Reconciliations</span>
            <form method="GET" class="acc-filter">
                <input type="hidden" name="view" value="pos_audit">
                <span>Period:</span>
                <input type="date" name="pos_start" value="<?php echo esc_attr($pos_start); ?>"> <span>to</span>
                <input type="date" name="pos_end" value="<?php echo esc_attr($pos_end); ?>">
                <button type="submit" style="background:#f59e0b;">Apply</button>
            </form>
        </div>

        <?php if(empty($pending_pos)): ?>
            <div style="background:#f0fdf4; color:#166534; padding:20px; border-radius:8px; border:1px solid #bbf7d0; font-weight:bold; margin-bottom:20px;">
                ✓ Perfect compliance. No pending POS checks found.
            </div>
        <?php else: ?>
            <div style="background:#fffbeb; padding:20px; border-radius:8px; border:1px solid #fde68a; margin-bottom:20px;">
                <h4 style="margin:0 0 15px 0; color:#b45309; font-size:1.1em;">Attention: <?php echo count($pending_pos); ?> Missing Reconciliations</h4>
                <div style="overflow-x: auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.9em; text-align:left;">
                        <tr style="border-bottom:2px solid #fcd34d; color:#92400e;">
                            <th style="padding:12px;">Meal Date (Eating)</th>
                            <th style="padding:12px; border-right:2px solid #fcd34d;">Delivery Date</th>
                            <th style="padding:12px;">Order ID</th>
                            <th style="padding:12px;">Customer</th>
                            <th style="padding:12px;">Plan</th>
                            <th style="padding:12px;">Delivery Logged As</th>
                        </tr>
                        <?php foreach($pending_pos as $p): 
                            $display_order_id = 'Manual';
                            if ($p->wc_order_id < 0) { $display_order_id = 'M-' . str_pad(abs($p->wc_order_id), 4, '0', STR_PAD_LEFT); } 
                            elseif ($p->wc_order_id > 0) { $display_order_id = '#' . $p->wc_order_id; }
                        ?>
                        <tr style="border-bottom:1px solid #fde68a;">
                            <td style="padding:12px;">
                                <strong><?php echo date('d M Y', strtotime($p->target_date)); ?></strong><br>
                                <span style="font-size:0.85em; color:#b45309;">(<?php echo $p->timing_label; ?>)</span>
                            </td>
                            <td style="padding:12px; border-right:2px solid #fde68a; font-weight:bold; color:#b45309;"><?php echo date('d M Y', strtotime($p->computed_delivery_date)); ?></td>
                            <td style="padding:12px;"><?php echo $display_order_id; ?></td>
                            <td style="padding:12px;"><?php echo esc_html($p->display_name); ?></td>
                            <td style="padding:12px;"><?php echo esc_html($p->plan_name); ?></td>
                            <td style="padding:12px;">
                                <span style="background:#fecdd3; color:#9f1239; padding:2px 6px; border-radius:4px; font-weight:bold; font-size:0.85em;"><?php echo esc_html($p->delivery_result); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if(!empty($completed_pos)): ?>
            <div style="background:#f0fdf4; padding:20px; border-radius:8px; border:1px solid #bbf7d0;">
                <h4 style="margin:0 0 15px 0; color:#166534; font-size:1.1em;">✓ <?php echo count($completed_pos); ?> Completed Reconciliations</h4>
                <div style="overflow-x: auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.9em; text-align:left;">
                        <tr style="border-bottom:2px solid #86efac; color:#14532d;">
                            <th style="padding:12px;">Meal Date (Eating)</th>
                            <th style="padding:12px; border-right:2px solid #86efac;">Delivery Date</th>
                            <th style="padding:12px;">Order ID</th>
                            <th style="padding:12px;">Customer</th>
                            <th style="padding:12px;">Plan</th>
                            <th style="padding:12px;">Delivery Logged As</th>
                        </tr>
                        <?php foreach($completed_pos as $p): 
                            $display_order_id = 'Manual';
                            if ($p->wc_order_id < 0) { $display_order_id = 'M-' . str_pad(abs($p->wc_order_id), 4, '0', STR_PAD_LEFT); } 
                            elseif ($p->wc_order_id > 0) { $display_order_id = '#' . $p->wc_order_id; }
                        ?>
                        <tr style="border-bottom:1px solid #bbf7d0;">
                            <td style="padding:12px;">
                                <strong><?php echo date('d M Y', strtotime($p->target_date)); ?></strong><br>
                                <span style="font-size:0.85em; color:#166534;">(<?php echo $p->timing_label; ?>)</span>
                            </td>
                            <td style="padding:12px; border-right:2px solid #bbf7d0; font-weight:bold; color:#166534;"><?php echo date('d M Y', strtotime($p->computed_delivery_date)); ?></td>
                            <td style="padding:12px;"><?php echo $display_order_id; ?></td>
                            <td style="padding:12px;"><?php echo esc_html($p->display_name); ?></td>
                            <td style="padding:12px;"><?php echo esc_html($p->plan_name); ?></td>
                            <td style="padding:12px;">
                                <span style="background:#dcfce7; color:#166534; padding:2px 6px; border-radius:4px; font-weight:bold; font-size:0.85em;"><?php echo esc_html($p->delivery_result); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
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

    $display_order_id = 'Manual';
    if ($sub->wc_order_id < 0) { $display_order_id = 'M-' . str_pad(abs($sub->wc_order_id), 4, '0', STR_PAD_LEFT); } 
    elseif ($sub->wc_order_id > 0) { $display_order_id = '#' . $sub->wc_order_id; }

    // Bag Status Logic
    $deposit_held = get_user_meta($sub->user_id, '_cmp_deposit_held', true);
    if ($deposit_held === '') {
        $past_plans = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d", $sub->user_id));
        $deposit_held = (intval($past_plans) > 0) ? 'yes' : 'no';
    }
    $b_color = '#dc2626'; $b_bg = '#fee2e2'; $b_text = 'Refunded / No Deposit';
    if ($deposit_held === 'yes') { $b_color = '#379237'; $b_bg = '#dcfce7'; $b_text = 'Bag Deposit Held'; }
    if ($deposit_held === 'own_bag') { $b_color = '#8b5cf6'; $b_bg = '#ede9fe'; $b_text = "Customer's Own Bag"; }

    ob_start();
    ?>
    <style>
        .acc-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; }
        .acc-ledger-header { background:#fff; padding:25px; border-radius:12px; border:1px solid #DCECC9; margin-bottom:30px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px; box-shadow:0 4px 12px rgba(27, 94, 32, 0.05); }
        .acc-summary-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:15px; margin-bottom:30px; }
        .acc-sum-box { background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #e2e8f0; text-align:center; }
        .acc-sum-val { font-size:2em; font-weight:900; color:#231F20; margin-bottom:5px; line-height:1; }
        .acc-sum-label { font-size:0.85em; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
        
        .acc-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); font-size: 0.9em; border-radius:8px; overflow:hidden; }
        .acc-table th { background: #EEF7E3; color: #1B5E20; padding: 12px; text-align: left; border-bottom: 2px solid #DCECC9; }
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
            <a href="?" style="color:#379237; text-decoration:none; font-weight:bold;">&larr; Back to Dashboard</a>
        </div>

        <div class="acc-ledger-header">
            <div>
                <h1 style="margin:0 0 10px 0; color:#231F20; font-size:1.8em;">Customer Record</h1>
                <div style="font-size:1.1em; color:#334155;"><strong><?php echo esc_html($name); ?></strong></div>
                <div style="color:#64748b; margin-top:5px;"><?php echo esc_html($sub->user_email); ?> | <?php echo esc_html($phone ?: 'No Phone'); ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:1.2em; font-weight:bold; color:#231F20; margin-bottom:10px;"><?php echo esc_html($sub->plan_name); ?></div>
                <div style="color:#64748b;">Order: <strong><?php echo esc_html($display_order_id); ?></strong></div>
                <div style="color:#64748b; margin-top:5px;">Status: <strong style="text-transform:uppercase; color:<?php echo $derived_status == 'active' ? '#379237' : ($derived_status == 'Completed' ? '#ef4444' : '#f59e0b'); ?>;"><?php echo esc_html($derived_status); ?></strong></div>
                
                <div style="color:#64748b; margin-top:8px; display:flex; align-items:center; justify-content:flex-end; gap:8px;">
                    <span>Bag Status:</span>
                    <div class="deposit-pill-wrapper" data-user-id="<?php echo $sub->user_id; ?>" style="display:inline-block;">
                        <span class="deposit-status-pill cmp-no-print" style="color: <?php echo $b_color; ?>; font-weight: bold; background: <?php echo $b_bg; ?>; padding: 4px 10px; border-radius: 12px; font-size: 0.85em; cursor: pointer; border: 1px solid <?php echo $b_color; ?>; transition: 0.2s;" title="Click to Change Status">
                            <?php echo $b_text; ?> &#9662;
                        </span>
                        <select class="deposit-status-select cmp-no-print" style="display:none; padding: 4px; font-size: 0.85em; border-radius: 4px; border: 1px solid #cbd5e1;">
                            <option value="yes" <?php selected($deposit_held, 'yes'); ?>>Held</option>
                            <option value="own_bag" <?php selected($deposit_held, 'own_bag'); ?>>Own Bag</option>
                            <option value="no" <?php selected($deposit_held, 'no'); ?>>Refunded</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="acc-summary-grid">
            <div class="acc-sum-box">
                <div class="acc-sum-val"><?php echo $sub->total_days; ?></div>
                <div class="acc-sum-label">Total Days</div>
            </div>
            <div class="acc-sum-box" style="border-bottom: 4px solid #379237;">
                <div class="acc-sum-val" style="color:#379237;"><?php echo $used; ?></div>
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
            <h2 style="margin:0; font-size:1.3em; color:#231F20;">Daily Selections & Logistics</h2>
            <div class="cmp-no-print" style="display:flex; gap:10px;">
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cmp_export_accounts_sub&sub_id='.$sub_id)); ?>" style="background:#379237; color:white; padding:8px 15px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.9em;">Export CSV</a>
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
                        if ($l->delivery_result === 'Successful') $del_color = '#379237';
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
                            <?php echo $l->pos_updated ? '<span style="color:#379237; font-weight:bold;">Yes</span>' : '<span style="color:#ef4444; font-weight:bold;">No</span>'; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    cmp_render_acc_bag_script();
    return ob_get_clean();
}

// ==========================================
// HELPER: INJECT JS FOR BAG PILL TOGGLE
// ==========================================
function cmp_render_acc_bag_script() {
    ?>
    <script>
    const accAjaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
    const accNonce   = '<?php echo wp_create_nonce("cmp_acc_nonce"); ?>';

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('.deposit-status-pill').forEach(pill => {
            pill.addEventListener('click', function(e) {
                let wrapper = this.closest('.deposit-pill-wrapper');
                let select = wrapper.querySelector('.deposit-status-select');
                this.style.display = 'none';
                select.style.display = 'inline-block';
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
                formData.append('action', 'cmp_acc_toggle_deposit');
                formData.append('nonce', accNonce);
                formData.append('user_id', userId);
                formData.append('new_status', newStatus);

                fetch(accAjaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        if (newStatus === 'yes') {
                            pill.innerHTML = 'Bag Deposit Held &#9662;';
                            pill.style.background = '#dcfce7'; pill.style.color = '#379237'; pill.style.borderColor = '#379237';
                        } else if (newStatus === 'own_bag') {
                            pill.innerHTML = "Customer's Own Bag &#9662;";
                            pill.style.background = '#ede9fe'; pill.style.color = '#8b5cf6'; pill.style.borderColor = '#8b5cf6';
                        } else {
                            pill.innerHTML = 'Refunded / No Deposit &#9662;';
                            pill.style.background = '#fee2e2'; pill.style.color = '#dc2626'; pill.style.borderColor = '#dc2626';
                        }
                        
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
    });
    </script>
    <?php
}
