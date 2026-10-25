<?php
/**
 * Customer Portal - Main Shortcode Controller & HTML View Template
 * Part 3 of the 3-file Customer Portal modular architecture.
 *
 * Automatically loads:
 *  1. customer-portal-backend.php (DB setup, Role/Date Helpers, AJAX Endpoints, CSV Exporters & WP Admin Feedback Page)
 *  2. customer-portal-assets.php  (Responsive CSS Stylesheet & Interactive JS/jQuery Engine)
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// 1. LOAD MODULAR BACKEND & ASSETS FILES
// ==========================================
require_once __DIR__ . '/customer-portal-backend.php';
require_once __DIR__ . '/customer-portal-assets.php';

// ==========================================
// 2. CUSTOMER PORTAL SHORTCODE RENDERER
// ==========================================
add_shortcode( 'meal_customer_portal', 'cmp_render_customer_portal' );
if ( ! function_exists( 'cmp_render_customer_portal' ) ) {
    function cmp_render_customer_portal() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        date_default_timezone_set( 'Asia/Dubai' );

        // Security & Custom Glassmorphism UI/UX Login Screen for Customers
        if ( ! is_user_logged_in() ) {
            if ( function_exists( 'cmp_render_portal_login_ui' ) ) {
                return cmp_render_portal_login_ui( array(
                    'portal_id'      => 'customer',
                    'title'          => 'My Meal Plan Portal',
                    'subtitle'       => 'Sign in to customize your daily meals & schedule',
                    'required_caps'  => 'any',
                    'denied_message' => 'Please sign in with your registered customer account.',
                ) );
            }
        }

        global $wpdb;
        $user_id      = get_current_user_id();
        $current_user = wp_get_current_user();
        $table_logs   = $wpdb->prefix . 'cmp_daily_logs';
        $table_foods  = $wpdb->prefix . 'cmp_foods';

        $subscribe_url        = 'https://mealplan.thecyclebistro.com/';
        $dynamic_cutoff_hour  = intval( get_option( 'cmp_cutoff_time', '11' ) );
        $label_chefs_choice   = get_option( 'cmp_label_chefs_choice', "Chef's Choice" );
        $blackout_string      = get_option( 'cmp_blackout_dates', '' );
        $blackout_dates_array = ! empty( $blackout_string ) ? explode( ',', str_replace( ' ', '', $blackout_string ) ) : array();
        $raw_wa               = get_option( 'cmp_whatsapp_number', '' );
        $clean_wa             = preg_replace( '/[^0-9]/', '', $raw_wa );

        // Load Custom Delivery Timings & Time Slots saved by Staff
        $custom_saved_timings = get_option( 'cmp_custom_delivery_timings', array() );
        if ( ! is_array( $custom_saved_timings ) ) {
            $custom_saved_timings = array();
        }
        $custom_saved_slots = get_option( 'cmp_custom_time_slots', array() );
        if ( ! is_array( $custom_saved_slots ) ) {
            $custom_saved_slots = array();
        }

        // Fixed-Size SVG Icons (Protected from Theme CSS Interference)
        $icon_reset  = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>';
        $icon_cancel = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
        $icon_kbd    = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1B5E20" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M8 12h.01M12 12h.01M16 12h.01M7 16h10"/></svg>';
        $icon_target = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 12 12 16 16 12"/><line x1="12" y1="8" x2="12" y2="16"/></svg>';
        $icon_up     = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>';
        $icon_undo   = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>';
        $icon_plus   = '<svg class="cmp-fixed-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';

        // OVERRIDE & PRIVILEGE DETECTION (CONNECTED TO WP ADMIN -> MEAL SETTINGS)
        $is_admin_override        = isset( $_GET['admin_edit_sub'] ) && ( cmp_can_bypass_date_restrictions() || current_user_can( 'manage_options' ) || current_user_can( 'foh_manager' ) || current_user_can( 'menu_manager' ) );
        $is_chef_override         = isset( $_GET['chef_override_sub'] ) && ( cmp_can_bypass_date_restrictions() || current_user_can( 'kitchen_staff' ) );
        $can_bypass_date_lock     = cmp_can_bypass_date_restrictions() || $is_chef_override;
        $can_bypass_quota         = cmp_can_bypass_meal_quota();
        $can_view_pos_check       = current_user_can( 'manage_options' ) || current_user_can( 'foh_manager' ) || current_user_can( 'menu_manager' );
        $can_edit_staff_logistics = cmp_can_view_pos_and_edit_logistics();

        if ( $is_admin_override ) {
            $raw_subs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", intval( $_GET['admin_edit_sub'] ) ) );
        } elseif ( $is_chef_override ) {
            $raw_subs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", intval( $_GET['chef_override_sub'] ) ) );
        } else {
            $raw_subs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d AND status IN ('active','paused') ORDER BY id DESC", $user_id ) );
        }

        $subs      = array();
        $tz_dubai  = new DateTimeZone( 'Asia/Dubai' );
        $now_dubai = new DateTime( 'now', $tz_dubai );
        $today_dt  = $now_dubai->format( 'Y-m-d H:i:s' );
        $today_ymd = $now_dubai->format( 'Y-m-d' );

        if ( $raw_subs ) {
            foreach ( $raw_subs as $sub ) {
                if ( $is_admin_override || $is_chef_override ) {
                    $subs[] = $sub;
                } else {
                    $usage_days = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_logs WHERE subscription_id = %d AND delivery_result = 'Successful'", $sub->id ) );
                    if ( $usage_days < $sub->total_days && $sub->expiry_date >= $today_dt ) {
                        $subs[] = $sub;
                    }
                }
            }
        }

        if ( empty( $subs ) ) {
            return '<div style="max-width:520px; margin:40px auto; padding:40px 30px; text-align:center; background:rgba(255,255,255,0.88); backdrop-filter:blur(18px); border:1.5px solid #DCECC9; border-top:5px solid #379237; border-radius:18px; box-shadow:0 16px 36px rgba(27,94,32,0.10);">
                        <h3 style="color:#1B5E20; font-size:1.5em; margin:0 0 10px 0;">No Active Meal Plans Found</h3>
                        <p style="color:#475569; margin-bottom: 25px; line-height:1.5;">It looks like your meal plans have been completed or expired.</p>
                        <a href="' . esc_url( $subscribe_url ) . '" style="background:linear-gradient(135deg, #379237 0%, #1B5E20 100%); color:#fff; text-decoration:none; padding:13px 28px; border-radius:10px; font-weight:bold; display:inline-block; margin-bottom:18px; box-shadow:0 6px 16px rgba(55,146,55,0.25);">Subscribe to a New Meal Plan</a>
                        <br>
                        <a href="' . esc_url( wp_logout_url( get_permalink() ) ) . '" style="color:#dc2626; font-weight:bold; text-decoration:none; font-size:0.95em;">Log Out</a>
                    </div>';
        }

        if ( ( $is_admin_override || $is_chef_override ) && ! empty( $subs ) ) {
            $override_user_id      = $subs[0]->user_id;
            $override_user         = get_userdata( $override_user_id );
            $fname                 = get_user_meta( $override_user_id, 'first_name', true ) ?: get_user_meta( $override_user_id, 'billing_first_name', true );
            $lname                 = get_user_meta( $override_user_id, 'last_name', true ) ?: get_user_meta( $override_user_id, 'billing_last_name', true );
            $override_name         = trim( $fname . ' ' . $lname );
            $customer_display_name = ! empty( $override_name ) ? $override_name : ( $override_user ? $override_user->display_name : 'Customer' );

            if ( $is_chef_override ) {
                $greeting_name = 'Chef Mode (Managing: ' . esc_html( $customer_display_name ) . ')';
            } else {
                $greeting_name = esc_html( $customer_display_name ) . ' (Staff Override)';
            }
        } else {
            $fname         = get_user_meta( $current_user->ID, 'first_name', true ) ?: get_user_meta( $current_user->ID, 'billing_first_name', true );
            $lname         = get_user_meta( $current_user->ID, 'last_name', true ) ?: get_user_meta( $current_user->ID, 'billing_last_name', true );
            $curr_name     = trim( $fname . ' ' . $lname );
            $greeting_name = ! empty( $curr_name ) ? $curr_name : $current_user->display_name;
        }

        // Fetch active foods for frontend dropdown population
        $foods = $wpdb->get_results( "SELECT * FROM $table_foods WHERE is_active = 1 ORDER BY category_name, food_name" );

        $current_hour      = (int) $now_dubai->format( 'H' );
        $customer_min_date = cmp_get_earliest_customer_allowed_date();

        // Dynamic status note based on configured WP Admin permissions
        if ( $can_bypass_date_lock ) {
            $global_min_date = '';
            $quota_note      = $can_bypass_quota
                ? 'Privilege Mode: Date, cut-off & meal quota restrictions are disabled for your role.'
                : 'Staff Mode: Date & cut-off restrictions are disabled for your role. Standard subscription meal quotas apply.';
            $note_text       = $quota_note;
            $note_color      = '#379237';
        } elseif ( $current_hour >= $dynamic_cutoff_hour ) {
            $global_min_date = $customer_min_date;
            $note_text       = 'Note: It is past ' . str_pad( $dynamic_cutoff_hour, 2, '0', STR_PAD_LEFT ) . ':00 GST. Meal selections, resets, and cancellations are now locked for today and tomorrow.';
            $note_color      = '#d63638';
        } else {
            $global_min_date = $customer_min_date;
            $note_text       = 'Note: Please make selections, resets, or cancellations before ' . str_pad( $dynamic_cutoff_hour, 2, '0', STR_PAD_LEFT ) . ':00 GST the day prior to delivery.';
            $note_color      = '#1B5E20';
        }

        ob_start();

        // Render Global Food Data & Responsive Stylesheet from customer-portal-assets.php
        if ( function_exists( 'cmp_render_customer_portal_styles' ) ) {
            cmp_render_customer_portal_styles( $foods );
        }
        ?>

        <div class="cmp-dashboard-wrap">
            <!-- 1. HEADER BANNER & ACTION BUTTONS -->
            <div class="cmp-header-banner">
                <div>
                    <h1>My Meal Plan Dashboard</h1>
                    <p><?php echo esc_html( $greeting_name ); ?></p>
                </div>
                <div class="cmp-header-actions">
                    <a href="<?php echo esc_url( $subscribe_url ); ?>" class="cmp-hdr-btn cmp-hdr-btn-subscribe">
                        <?php echo $icon_plus; ?>
                        <span>Subscribe to a New Meal Plan</span>
                    </a>
                    <a id="cmp-global-export-btn" href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=cmp_export_customer_csv&sub_id=' . $subs[0]->id ) ); ?>" class="cmp-hdr-btn cmp-hdr-btn-export">Export to Excel</a>
                    <?php if ( ! $is_chef_override && ! $is_admin_override ) : ?>
                        <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="cmp-hdr-btn cmp-hdr-btn-logout">Log Out</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. RESPONSIVE PLAN TABS BAR (ZERO SCROLLBAR CLIPPING ON MOBILE/TABLET) -->
            <div class="cmp-tab-nav">
                <?php
                $first_tab = true;
                foreach ( $subs as $sub ) {
                    $active_class         = $first_tab ? 'active' : '';
                    $start_date_formatted = ! empty( $sub->start_date ) ? date( 'M d, Y', strtotime( $sub->start_date ) ) : 'N/A';

                    echo '<button type="button" class="cmp-tab-btn ' . esc_attr( $active_class ) . '" onclick="switchTab(event, \'plan-tab-' . esc_attr( $sub->id ) . '\')">';
                    echo '<span id="tab-title-' . esc_attr( $sub->id ) . '">' . esc_html( $sub->plan_name ) . '</span><br><span style="font-size: 0.84em; font-weight: 600; opacity: 0.85;">' . esc_html( $start_date_formatted ) . '</span>';
                    echo '</button>';

                    $first_tab = false;
                }
                ?>
            </div>

            <?php
            $first_content = true;
            foreach ( $subs as $sub ) :
                $tab_id         = 'plan-tab-' . $sub->id;
                $active_class   = $first_content ? 'active' : '';
                $first_content  = false;

                $order    = function_exists( 'wc_get_order' ) ? wc_get_order( $sub->wc_order_id ) : false;
                $sub_user = get_userdata( $sub->user_id );

                $fname = get_user_meta( $sub->user_id, 'first_name', true ) ?: get_user_meta( $sub->user_id, 'billing_first_name', true );
                $lname = get_user_meta( $sub->user_id, 'last_name', true ) ?: get_user_meta( $sub->user_id, 'billing_last_name', true );
                if ( $order ) {
                    $fname = $order->get_billing_first_name() ?: $fname;
                    $lname = $order->get_billing_last_name() ?: $lname;
                }

                $fallback_name = trim( $fname . ' ' . $lname );
                if ( empty( $fallback_name ) ) {
                    $fallback_name = $sub_user ? $sub_user->display_name : 'Customer';
                }
                $display_name = $fallback_name;

                // Recipient Extraction
                $plan_parts          = explode( ' - ', $sub->plan_name );
                $potential_recipient = end( $plan_parts );
                $recipient_name      = '';

                if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', $potential_recipient ) && count( $plan_parts ) > 1 ) {
                    $recipient_name = $potential_recipient;
                    array_pop( $plan_parts );
                    $core_plan_name = implode( ' - ', $plan_parts );
                } else {
                    $core_plan_name = $sub->plan_name;
                }

                $timing = ! empty( $sub->delivery_timing ) ? $sub->delivery_timing : '';
                if ( empty( $timing ) && $order ) {
                    $timing = $order->get_meta( '_cmp_delivery_timing' ) ?: $order->get_meta( 'delivery_timing' );
                }
                if ( empty( $timing ) ) {
                    $timing = get_user_meta( $sub->user_id, 'delivery_timing', true );
                }

                $time_slot = ! empty( $sub->time_slot ) ? $sub->time_slot : '';
                if ( empty( $time_slot ) && $order ) {
                    $time_slot = $order->get_meta( '_cmp_time_slot' ) ?: $order->get_meta( 'time_slot' );
                }
                if ( empty( $time_slot ) ) {
                    $time_slot = get_user_meta( $sub->user_id, 'time_slot', true );
                }

                $method = ! empty( $sub->delivery_method ) ? $sub->delivery_method : '';
                if ( empty( $method ) && $order ) {
                    $method = $order->get_meta( '_cmp_logistics_method' ) ?: $order->get_meta( 'delivery_method' );
                }
                if ( empty( $method ) ) {
                    $method = get_user_meta( $sub->user_id, 'delivery_method', true );
                }

                $pickup = ! empty( $sub->pickup_location ) ? $sub->pickup_location : '';
                if ( empty( $pickup ) && $order ) {
                    $pickup = $order->get_meta( '_cmp_pickup_location' ) ?: $order->get_meta( 'pickup_location' );
                }
                if ( empty( $pickup ) ) {
                    $pickup = get_user_meta( $sub->user_id, 'pickup_location', true );
                }

                $allergies = ! empty( $sub->allergies ) ? $sub->allergies : '';
                if ( empty( $allergies ) && $order ) {
                    $allergies = $order->get_customer_note();
                }
                if ( empty( $allergies ) ) {
                    $allergies = get_user_meta( $sub->user_id, 'allergies', true );
                }

                $address = 'Address not provided';
                if ( $order ) {
                    $addr_parts = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city() ) );
                    if ( empty( $addr_parts ) ) {
                        $addr_parts = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city() ) );
                    }
                    if ( ! empty( $addr_parts ) ) {
                        $address = implode( ', ', $addr_parts );
                    }
                }
                if ( $address === 'Address not provided' ) {
                    $addr1      = get_user_meta( $sub->user_id, 'billing_address_1', true );
                    $addr2      = get_user_meta( $sub->user_id, 'billing_address_2', true );
                    $city       = get_user_meta( $sub->user_id, 'billing_city', true );
                    $addr_parts = array_filter( array( $addr1, $addr2, $city ) );
                    if ( ! empty( $addr_parts ) ) {
                        $address = implode( ', ', $addr_parts );
                    }
                }

                $email = $order ? $order->get_billing_email() : ( $sub_user ? $sub_user->user_email : '' );
                $phone = $order ? $order->get_billing_phone() : get_user_meta( $sub->user_id, 'billing_phone', true );

                $method_display = $method ?: 'N/A';
                if ( $method === 'Pickup' && ! empty( $pickup ) ) {
                    $method_display .= ' (' . esc_html( $pickup ) . ')';
                }
                $timing_display    = $timing ?: 'N/A';
                $time_slot_display = $time_slot ?: 'N/A';
                $allergies_display = ! empty( $allergies ) ? $allergies : 'No Allergies Recorded';
                $is_paused         = ( $sub->status === 'paused' );

                // Build Dynamic Timing & Time Slot Option Lists (Preserves Custom Values)
                $timing_options = array_values( array_unique( array_merge(
                    array( 'Deliver Day Before', 'Deliver Same Day' ),
                    $custom_saved_timings,
                    ! empty( $timing ) ? array( $timing ) : array()
                ) ) );

                $slot_options = array_values( array_unique( array_merge(
                    array(
                        '5:00 AM to 6:00 AM',
                        '6:00 AM to 7:00 AM',
                        '7:00 AM to 8:00 AM',
                        '8:00 AM to 9:00 AM',
                        '5:00 PM to 8:00 PM',
                    ),
                    $custom_saved_slots,
                    ! empty( $time_slot ) ? array( $time_slot ) : array()
                ) ) );

                // Quota Engine
                $is_juice     = ( stripos( $sub->allowed_categories, 'Juices' ) !== false || stripos( $sub->plan_name, 'juice' ) !== false || stripos( $sub->plan_name, 'cleanse' ) !== false );
                $is_flexible  = isset( $sub->is_flexible ) ? intval( $sub->is_flexible ) : 0;
                $allow_custom = isset( $sub->allow_custom ) ? intval( $sub->allow_custom ) : 0;

                $allowed_quota = 0;
                $snack_quota   = 0;

                if ( ! $is_juice ) {
                    preg_match( '/(\d+)\s*Meal/i', $sub->plan_name, $m );
                    if ( isset( $m[1] ) ) {
                        $allowed_quota = intval( $m[1] );
                    } else {
                        if ( preg_match( '/\b(one|1)\b/i', $sub->plan_name ) )       $allowed_quota = 1;
                        elseif ( preg_match( '/\b(two|2)\b/i', $sub->plan_name ) )   $allowed_quota = 2;
                        elseif ( preg_match( '/\b(three|3)\b/i', $sub->plan_name ) ) $allowed_quota = 3;
                        else {
                            $cats = explode( ',', $sub->allowed_categories );
                            foreach ( $cats as $c ) {
                                if ( in_array( trim( $c ), array( 'Breakfast', 'Lunch', 'Dinner' ), true ) ) {
                                    $allowed_quota++;
                                }
                            }
                        }
                    }
                    if ( $allowed_quota <= 0 ) {
                        $allowed_quota = 1;
                    }
                    $snack_quota = ( $allowed_quota >= 2 ) ? 2 : 1;
                }
            ?>
            <div id="<?php echo esc_attr( $tab_id ); ?>" class="cmp-tab-content <?php echo esc_attr( $active_class ); ?> cmp-portal-container" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" data-total-days="<?php echo esc_attr( $sub->total_days ); ?>" data-allowed-meals="<?php echo esc_attr( $allowed_quota ); ?>" data-snack-quota="<?php echo esc_attr( $snack_quota ); ?>" data-is-juice="<?php echo $is_juice ? '1' : '0'; ?>">

                <!-- 3. ACCOUNT & ACTIVE LOGISTICS DRAWER (COLLAPSIBLE ON MOBILE / 2-COL GRID ON TABLET & DESKTOP) -->
                <div class="cmp-info-drawer-wrap" id="info-drawer-<?php echo esc_attr( $sub->id ); ?>">
                    <button type="button" class="cmp-mobile-info-toggle" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" aria-expanded="false">
                        <div class="cmp-mobile-info-toggle-left">
                            <span class="cmp-mobile-info-kicker">Account &amp; Active Logistics</span>
                            <span class="cmp-mobile-info-summary-line" id="mobile-drawer-summary-<?php echo esc_attr( $sub->id ); ?>">
                                <?php echo esc_html( $timing_display . ' (' . $time_slot_display . ') • ' . $allergies_display ); ?>
                            </span>
                        </div>
                        <span class="cmp-mobile-info-pill">
                            <span class="drawer-pill-txt">Details</span> <span class="drawer-pill-arrow">&#9660;</span>
                        </span>
                    </button>

                    <div class="cmp-info-drawer-body">
                        <div class="cmp-info-cards-grid">
                            <!-- Account Details Card -->
                            <div class="cmp-account-card-box">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                    <h3 style="margin:0; color:#379237; font-size:1.15em; font-weight:800;">Account Details</h3>
                                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                                        <button type="button" class="toggle-account-edit" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" style="background:none; border:none; color:#379237; cursor:pointer; font-size:0.85em; font-weight:bold; text-decoration:underline; padding:0;">Edit</button>
                                    <?php endif; ?>
                                </div>

                                <div id="account-view-<?php echo esc_attr( $sub->id ); ?>" class="cmp-kv-grid">
                                    <div class="cmp-kv-label">Order ID:</div>
                                    <div class="cmp-kv-val"><?php echo ( $sub->wc_order_id > 0 ) ? '#' . esc_html( $sub->wc_order_id ) : 'M-' . str_pad( abs( $sub->wc_order_id ), 4, '0', STR_PAD_LEFT ); ?></div>

                                    <div class="cmp-kv-label">Name:</div>
                                    <div class="cmp-kv-val" style="display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
                                        <span id="name-val-<?php echo esc_attr( $sub->id ); ?>"><?php echo esc_html( $display_name ); ?></span>
                                        <span id="recipient-row-<?php echo esc_attr( $sub->id ); ?>" class="cmp-recipient-label-badge" style="display:<?php echo ! empty( $recipient_name ) ? 'inline-flex' : 'none'; ?>;">
                                            Recipient: <strong id="recipient-val-<?php echo esc_attr( $sub->id ); ?>"><?php echo esc_html( $recipient_name ); ?></strong>
                                        </span>
                                    </div>

                                    <div class="cmp-kv-label">Email:</div>
                                    <div class="cmp-kv-val"><?php echo esc_html( $email ); ?></div>

                                    <div class="cmp-kv-label">Phone:</div>
                                    <div class="cmp-kv-val"><?php echo esc_html( $phone ?: 'N/A' ); ?></div>

                                    <div class="cmp-kv-label">Address:</div>
                                    <div class="cmp-kv-val"><span id="address-val-<?php echo esc_attr( $sub->id ); ?>"><?php echo esc_html( $address ); ?></span></div>
                                </div>

                                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                                <div id="account-edit-<?php echo esc_attr( $sub->id ); ?>" style="display:none; margin-top:10px;">
                                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
                                        <div style="flex:1; min-width:120px;">
                                            <label style="font-size:0.82em; font-weight:bold; color:#334155;">First Name:</label>
                                            <input type="text" id="edit-fname-<?php echo esc_attr( $sub->id ); ?>" value="<?php echo esc_attr( $fname ); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                        </div>
                                        <div style="flex:1; min-width:120px;">
                                            <label style="font-size:0.82em; font-weight:bold; color:#334155;">Last Name:</label>
                                            <input type="text" id="edit-lname-<?php echo esc_attr( $sub->id ); ?>" value="<?php echo esc_attr( $lname ); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                        </div>
                                        <div style="flex:1; min-width:140px;">
                                            <label style="font-size:0.82em; font-weight:bold; color:#1B5E20;">Recipient Name:</label>
                                            <input type="text" id="edit-recipient-<?php echo esc_attr( $sub->id ); ?>" value="<?php echo esc_attr( $recipient_name ); ?>" placeholder="e.g. Sarah" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #68B946; background:#EEF7E3; border-radius:6px; box-sizing:border-box;">
                                        </div>
                                    </div>
                                    <div style="margin-bottom:10px;">
                                        <label style="font-size:0.82em; font-weight:bold; color:#334155;">Full Address:</label>
                                        <input type="text" id="edit-address-<?php echo esc_attr( $sub->id ); ?>" value="<?php echo esc_attr( $address !== 'Address not provided' ? $address : '' ); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                    </div>
                                    <button type="button" class="save-account-btn" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" data-user-id="<?php echo esc_attr( $sub->user_id ); ?>" data-order-id="<?php echo esc_attr( $sub->wc_order_id ); ?>" style="background:#379237; color:#fff; border:none; padding:9px 14px; border-radius:8px; font-size:0.86em; font-weight:bold; cursor:pointer; width:100%; transition:background 0.2s;">Save Account Details</button>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Active Logistics Card (With Staff Hybrid Custom Timing & Slot Engine) -->
                            <div class="cmp-logistics-card-box">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                    <h3 style="margin:0; color:#1B5E20; font-size:1.15em; font-weight:800;">Active Logistics</h3>
                                    <?php if ( $can_edit_staff_logistics ) : ?>
                                        <button type="button" class="toggle-logistics-edit" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" style="background:none; border:none; color:#379237; cursor:pointer; font-size:0.85em; font-weight:bold; text-decoration:underline; padding:0;">Edit</button>
                                    <?php endif; ?>
                                </div>

                                <div id="logistics-view-<?php echo esc_attr( $sub->id ); ?>">
                                    <div class="cmp-kv-grid">
                                        <div class="cmp-kv-label">Method:</div>
                                        <div class="cmp-kv-val"><?php echo esc_html( $method_display ); ?></div>

                                        <div class="cmp-kv-label">Receive By:</div>
                                        <div class="cmp-kv-val"><span id="timing-val-<?php echo esc_attr( $sub->id ); ?>"><?php echo esc_html( $timing_display ); ?></span></div>

                                        <div class="cmp-kv-label">Time Slot:</div>
                                        <div class="cmp-kv-val"><span id="time-slot-val-<?php echo esc_attr( $sub->id ); ?>"><?php echo esc_html( $time_slot_display ); ?></span></div>
                                    </div>

                                    <div class="cmp-allergy-Dynamic-box">
                                        <strong>Allergies:</strong> <span id="allergies-val-<?php echo esc_attr( $sub->id ); ?>"><?php echo esc_html( $allergies_display ); ?></span>
                                    </div>
                                </div>

                                <?php if ( $can_edit_staff_logistics ) : ?>
                                <div id="logistics-edit-<?php echo esc_attr( $sub->id ); ?>" style="display:none; margin-top:10px;">
                                    <!-- Receive By (Delivery Timing) Hybrid Preset + Custom Selector -->
                                    <div style="margin-bottom:10px;">
                                        <label style="font-size:0.82em; font-weight:bold; color:#334155; display:block; margin-bottom:4px;">Receive By (Delivery Timing):</label>
                                        <select id="edit-timing-<?php echo esc_attr( $sub->id ); ?>" class="cmp-logistics-timing-select" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                            <?php foreach ( $timing_options as $t_opt ) : ?>
                                                <option value="<?php echo esc_attr( $t_opt ); ?>" <?php selected( $timing, $t_opt ); ?>><?php echo esc_html( $t_opt ); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__custom__">+ Enter Custom Timing...</option>
                                        </select>
                                        <div id="custom-timing-wrap-<?php echo esc_attr( $sub->id ); ?>" style="display:none; margin-top:6px; padding:8px; background:#EEF7E3; border:1px solid #68B946; border-radius:6px;">
                                            <input type="text" id="custom-timing-input-<?php echo esc_attr( $sub->id ); ?>" placeholder="e.g. Deliver Evening Prior (9 PM)" style="width:100%; padding:6px 8px; font-size:0.88em; border:1px solid #cbd5e1; border-radius:5px; margin-bottom:5px;">
                                            <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.78em; color:#1B5E20; font-weight:700; cursor:pointer;">
                                                <input type="checkbox" id="save-custom-timing-<?php echo esc_attr( $sub->id ); ?>" checked style="accent-color:#379237;">
                                                <span>Save this timing to dropdown presets for future use</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Time Slot Hybrid Preset + Custom Selector -->
                                    <div style="margin-bottom:10px;">
                                        <label style="font-size:0.82em; font-weight:bold; color:#334155; display:block; margin-bottom:4px;">Time Slot:</label>
                                        <select id="edit-timeslot-<?php echo esc_attr( $sub->id ); ?>" class="cmp-logistics-slot-select" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                                            <?php foreach ( $slot_options as $s_opt ) : ?>
                                                <option value="<?php echo esc_attr( $s_opt ); ?>" <?php selected( $time_slot, $s_opt ); ?>><?php echo esc_html( $s_opt ); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__custom__">+ Enter Custom Time Slot...</option>
                                        </select>
                                        <div id="custom-slot-wrap-<?php echo esc_attr( $sub->id ); ?>" style="display:none; margin-top:6px; padding:8px; background:#EEF7E3; border:1px solid #68B946; border-radius:6px;">
                                            <input type="text" id="custom-slot-input-<?php echo esc_attr( $sub->id ); ?>" placeholder="e.g. 10:00 AM to 12:00 PM" style="width:100%; padding:6px 8px; font-size:0.88em; border:1px solid #cbd5e1; border-radius:5px; margin-bottom:5px;">
                                            <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.78em; color:#1B5E20; font-weight:700; cursor:pointer;">
                                                <input type="checkbox" id="save-custom-slot-<?php echo esc_attr( $sub->id ); ?>" checked style="accent-color:#379237;">
                                                <span>Save this time slot to dropdown presets for future use</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div style="margin-bottom:10px;">
                                        <label style="font-size:0.82em; font-weight:bold; color:#334155; display:block; margin-bottom:4px;">Allergies:</label>
                                        <textarea id="edit-allergies-<?php echo esc_attr( $sub->id ); ?>" style="width:100%; padding:7px; font-size:0.9em; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;"><?php echo esc_textarea( $allergies ); ?></textarea>
                                    </div>
                                    <button type="button" class="save-logistics-btn" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" data-user-id="<?php echo esc_attr( $sub->user_id ); ?>" data-order-id="<?php echo esc_attr( $sub->wc_order_id ); ?>" style="background:#379237; color:#fff; border:none; padding:9px 14px; border-radius:8px; font-size:0.86em; font-weight:bold; cursor:pointer; width:100%; transition:background 0.2s;">Save Logistics Changes</button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="cmp-cutoff-callout">
                            <p style="color: <?php echo esc_attr( $note_color ); ?>; font-size: 0.88em; font-weight: 700; margin: 0;"><em><?php echo esc_html( $note_text ); ?></em></p>
                        </div>
                    </div>
                </div>

                <?php if ( $is_paused ) : ?>
                <div class="cmp-no-print" style="background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:15px; margin-bottom:20px; text-align:center;">
                    <strong style="color:#c2410c; font-size:1.1em;">This Meal Plan is Currently Paused</strong>
                    <p style="color:#9a3412; margin:5px 0 0 0; font-size:0.9em;">Your deliveries have been temporarily halted. Please contact support to resume your plan.</p>
                </div>
                <?php endif; ?>

                <!-- PLAN DAYS CONTROLS (GOVERNED BY WP ADMIN -> MEAL SETTINGS) -->
                <?php if ( cmp_can_adjust_plan_days() ) : ?>
                <div class="cmp-no-print" style="background:#fffbdd; border:1px solid #eab308; border-radius:8px; padding:15px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <strong style="color:#b45309; font-size:1.1em;">Plan Days Controls</strong><br>
                        <span style="color:#92400e; font-size:0.9em;">Current Quota: <strong><?php echo esc_html( $sub->total_days ); ?> Days</strong></span>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="admin-adjust-days-btn" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" data-operation="add" style="background:#379237; color:#fff; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; transition:0.2s;">+ Add Extra Day</button>
                        <button type="button" class="admin-adjust-days-btn" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" data-operation="remove" style="background:#dc2626; color:#fff; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; transition:0.2s;">- Remove Last Day</button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 4. DESKTOP KEYBOARD FAST-ENTRY & MOBILE SMART PENDING BAR -->
                <div class="cmp-schedule-toolbar cmp-no-print">
                    <div class="cmp-kbd-guide">
                        <?php echo $icon_kbd; ?>
                        <strong>Keyboard Fast-Entry:</strong>
                        <span><kbd class="cmp-kbd-key">Tab</kbd> Next Field</span>
                        <span><kbd class="cmp-kbd-key">Alt</kbd>+<kbd class="cmp-kbd-key">C</kbd> Toggle <?php echo esc_html( $label_chefs_choice ); ?></span>
                        <span><kbd class="cmp-kbd-key">Enter</kbd> / <kbd class="cmp-kbd-key">Ctrl+Enter</kbd> Save &amp; Next Day</span>
                        <span><kbd class="cmp-kbd-key">Alt</kbd>+<kbd class="cmp-kbd-key">Up/Down</kbd> Switch Day</span>
                    </div>
                    <div class="cmp-toolbar-actions">
                        <button type="button" class="cmp-util-btn primary-util cmp-jump-pending-btn" title="Scroll directly to the first pending meal selection and open it">
                            <span class="cmp-jump-btn-left">
                                <?php echo $icon_target; ?>
                                <span>Go to Pending Selection</span>
                            </span>
                            <span class="cmp-pending-count-badge">...</span>
                        </button>
                        <button type="button" class="cmp-util-btn cmp-collapse-all-btn">Collapse All</button>
                        <button type="button" class="cmp-util-btn cmp-expand-all-btn">Expand All</button>
                    </div>
                </div>

                <!-- 5. MEAL SCHEDULE TABLE / RESPONSIVE DAY CARDS -->
                <div class="cmp-table-responsive">
                    <table class="cmp-table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Date</th>
                                <th><?php echo esc_html( $label_chefs_choice ); ?></th>
                                <?php
                                if ( ! $is_juice ) :
                                    $h1 = $is_flexible ? 'Meal 1' : 'Breakfast';
                                    $h2 = $is_flexible ? 'Meal 2' : 'Lunch';
                                    $h3 = $is_flexible ? 'Meal 3' : 'Dinner';
                                ?>
                                    <th><?php echo esc_html( $h1 ); ?></th>
                                    <th><?php echo esc_html( $h2 ); ?></th>
                                    <th><?php echo esc_html( $h3 ); ?></th>
                                    <?php if ( $snack_quota > 0 ) echo '<th>Snacks</th>'; ?>
                                    <th>Macros (Daily)</th>
                                <?php else : ?>
                                    <th>Juice 1</th>
                                    <th>Juice 2</th>
                                    <th>Juice 3</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $saved_logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_logs WHERE subscription_id = %d ORDER BY target_date ASC, id ASC", $sub->id ) );

                            $void_count = 0;
                            foreach ( $saved_logs as $log ) {
                                if ( in_array( $log->delivery_result, array( 'Cancelled', 'Returned' ), true ) ) {
                                    $void_count++;
                                }
                            }
                            $display_days    = intval( $sub->total_days ) + $void_count;
                            $active_min_date = $global_min_date;

                            for ( $i = 1; $i <= $display_days; $i++ ) {
                                $log                = isset( $saved_logs[ $i - 1 ] ) ? $saved_logs[ $i - 1 ] : null;
                                $log_id             = $log ? $log->id : 0;
                                $is_void            = ( $log && in_array( $log->delivery_result, array( 'Cancelled', 'Returned' ), true ) );
                                $saved_chefs_choice = ( $log && $log->is_chefs_choice ) ? true : false;
                                $pos_updated        = ( $log && ! empty( $log->pos_updated ) ) ? 1 : 0;

                                // Evaluate Plan Mode at the Log Level
                                $log_mode = ( $log && ! empty( $log->plan_mode ) ) ? $log->plan_mode : null;
                                if ( $log_mode ) {
                                    $row_is_flexible  = ( $log_mode === 'flexible' ) ? 1 : 0;
                                    $row_allow_custom = ( $log_mode === 'custom' ) ? 1 : 0;
                                } else {
                                    $row_is_flexible  = $is_flexible;
                                    $row_allow_custom = $allow_custom;
                                }

                                $is_chef_assigned = ( $log && ( $log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || $log->custom_breakfast || $log->custom_lunch || $log->custom_dinner || $log->custom_juice_1 ) );

                                // Strict Lock Evaluation: Past dates, Today, and Post-Cutoff Tomorrow
                                $is_time_locked = false;
                                if ( $log && ! empty( $log->target_date ) ) {
                                    if ( cmp_is_date_locked_for_customer( $log->target_date ) || $log->delivery_result === 'Successful' ) {
                                        $is_time_locked = true;
                                    }
                                }

                                $base_input_disabled = ( $is_void || ( ! $can_bypass_date_lock && ( $is_time_locked || $is_paused ) ) ) ? 'disabled' : '';

                                if ( $is_chef_override ) {
                                    $chefs_choice_checkbox_disabled = 'disabled';
                                    $meal_select_disabled           = $saved_chefs_choice ? '' : 'disabled';
                                    $date_picker_disabled           = 'disabled';
                                } else {
                                    $chefs_choice_checkbox_disabled = $base_input_disabled;
                                    $meal_select_disabled           = ( $base_input_disabled || $saved_chefs_choice ) ? 'disabled' : '';
                                    $date_picker_disabled           = $base_input_disabled;
                                }

                                $row_status_class = 'status-pending';
                                if ( $is_void ) {
                                    $row_status_class = 'status-void row-void';
                                } elseif ( $log_id > 0 ) {
                                    $row_status_class = 'status-saved';
                                }

                                // Keep all Day Cards collapsed by default on Desktop/Tablet
                                $row_status_class .= ' is-collapsed-desktop';

                                // Keep Chef's Choice rows without assigned meals in compact mode when expanded
                                if ( $saved_chefs_choice && ! $is_chef_override && ! $is_chef_assigned ) {
                                    $row_status_class .= ' is-chefs-choice-compact';
                                }

                                if ( $log && ! $is_void && ! empty( $log->target_date ) ) {
                                    $next_day_calc = date( 'Y-m-d', strtotime( '+1 day', strtotime( $log->target_date ) ) );
                                    if ( empty( $active_min_date ) || $next_day_calc > $active_min_date ) {
                                        if ( ! $can_bypass_date_lock ) {
                                            $active_min_date = $next_day_calc;
                                        }
                                    }
                                }

                                // Status Badge ("Selected" / "Pending" / "Cancelled" / "Successful")
                                $status_badge = '<span class="cmp-status-pill pill-pending">Pending</span>';
                                if ( $log && $log->delivery_result === 'Successful' ) {
                                    $status_badge = '<span class="cmp-status-pill pill-successful">Successful</span>';
                                } elseif ( $is_void && $log ) {
                                    $status_badge = '<span class="cmp-status-pill pill-void">' . esc_html( $log->delivery_result ) . '</span>';
                                } elseif ( $is_paused ) {
                                    $status_badge = '<span class="cmp-status-pill pill-paused">Paused</span>';
                                } elseif ( $log_id > 0 && ! $saved_chefs_choice && ! $is_chef_assigned ) {
                                    $status_badge = '<span class="cmp-status-pill pill-meals-pending">Meals Pending</span>';
                                } elseif ( $log_id > 0 ) {
                                    $status_badge = '<span class="cmp-status-pill pill-confirmed">Selected</span>';
                                }

                                // Read-Only POS Check Badges for FOH, Menu Manager & Admin
                                $desktop_pos_badge = '';
                                $mobile_pos_badge  = '';
                                if ( $can_view_pos_check && $log_id > 0 && ! $is_void ) {
                                    if ( $pos_updated ) {
                                        $desktop_pos_badge = '<span class="cmp-pos-readonly-pill pos-is-checked" title="POS Reconciled">✓ POS Checked</span>';
                                        $mobile_pos_badge  = '<span class="cmp-pos-micro-pill pos-is-checked" title="POS Checked">POS ✓</span>';
                                    } else {
                                        $desktop_pos_badge = '<span class="cmp-pos-readonly-pill pos-is-pending" title="Pending POS Check">POS Pending</span>';
                                        $mobile_pos_badge  = '<span class="cmp-pos-micro-pill pos-is-pending" title="Pending POS Check">POS •</span>';
                                    }
                                }

                                // Formatted Mobile Date Preview
                                $formatted_mobile_date = '';
                                if ( $log && ! empty( $log->target_date ) && $log->target_date !== '0000-00-00' ) {
                                    $formatted_mobile_date = '• ' . date( 'D, d M', strtotime( $log->target_date ) );
                                }

                                $default_macro_tiles = '
                                    <div class="cmp-macro-tiles-grid">
                                        <div class="cmp-macro-tile tile-cal"><span class="tile-label">Calories</span><span class="tile-val">0 kcal</span></div>
                                        <div class="cmp-macro-tile tile-pro"><span class="tile-label">Protein</span><span class="tile-val">0g</span></div>
                                        <div class="cmp-macro-tile tile-carb"><span class="tile-label">Carbs</span><span class="tile-val">0g</span></div>
                                        <div class="cmp-macro-tile tile-fat"><span class="tile-label">Fat</span><span class="tile-val">0g</span></div>
                                    </div>';

                                $min_attr = ( ! empty( $active_min_date ) && ! $can_bypass_date_lock ) ? 'min="' . esc_attr( $active_min_date ) . '"' : '';
                            ?>
                                <tr class="cmp-day-row <?php echo esc_attr( $row_status_class ); ?>" data-row="<?php echo esc_attr( $i ); ?>" data-pos-updated="<?php echo esc_attr( $pos_updated ); ?>" data-is-flexible="<?php echo esc_attr( $row_is_flexible ); ?>" data-allow-custom="<?php echo esc_attr( $row_allow_custom ); ?>">
                                    <td class="cmp-cell-day" title="Click to Expand / Collapse Day <?php echo esc_attr( $i ); ?>">
                                        <div class="cmp-day-header-top">
                                            <div class="cmp-day-title-group">
                                                <strong class="cmp-day-num">Day <?php echo esc_html( $i ); ?></strong>
                                                <span class="cmp-mobile-date-badge <?php echo ! empty( $formatted_mobile_date ) ? 'has-date' : ''; ?>"><?php echo esc_html( $formatted_mobile_date ); ?></span>
                                            </div>
                                            <div class="cmp-day-right-group">
                                                <?php if ( $can_view_pos_check ) : ?>
                                                    <span class="cmp-mobile-pos-holder"><?php echo $mobile_pos_badge; ?></span>
                                                <?php endif; ?>
                                                <span class="cmp-mobile-status"><?php echo $status_badge; ?></span>
                                            </div>
                                        </div>
                                        <div class="cmp-mobile-meal-preview"></div>
                                    </td>
                                    <td class="cmp-cell-date">
                                        <span class="cmp-mobile-label">Date</span>
                                        <input type="date" class="cmp-date-picker" data-row="<?php echo esc_attr( $i ); ?>" <?php echo $min_attr; ?> value="<?php echo $log ? esc_attr( $log->target_date ) : ''; ?>" <?php echo $date_picker_disabled; ?>>
                                    </td>
                                    <td class="cmp-cell-chef">
                                        <span class="cmp-mobile-label"><?php echo esc_html( $label_chefs_choice ); ?></span>
                                        <label class="cmp-chef-box" title="Shortcut: Alt + C">
                                            <input type="checkbox" class="cmp-chefs-choice" data-row="<?php echo esc_attr( $i ); ?>" <?php echo $chefs_choice_checkbox_disabled; ?> <?php if ( $saved_chefs_choice ) echo 'checked'; ?>>
                                            <span style="font-size:0.86rem; font-weight:700; color:#231F20;"><?php echo esc_html( $label_chefs_choice ); ?></span>
                                        </label>
                                    </td>

                                    <?php
                                    if ( ! $is_juice ) :
                                        $main_cats = array(
                                            'Breakfast' => array( 'slot' => 'breakfast', 'idx' => 1, 'label' => $row_is_flexible ? 'Meal 1' : 'Breakfast' ),
                                            'Lunch'     => array( 'slot' => 'lunch',     'idx' => 2, 'label' => $row_is_flexible ? 'Meal 2' : 'Lunch' ),
                                            'Dinner'    => array( 'slot' => 'dinner',    'idx' => 3, 'label' => $row_is_flexible ? 'Meal 3' : 'Dinner' ),
                                        );
                                        foreach ( $main_cats as $cat => $data ) :
                                    ?>
                                            <td class="cmp-cell-meal cmp-cell-meal-<?php echo esc_attr( $data['idx'] ); ?>">
                                                <span class="cmp-mobile-label"><?php echo esc_html( $data['label'] ); ?></span>
                                                <?php if ( $row_allow_custom ) : ?>
                                                    <input type="text" class="cmp-meal-custom cmp-main-meal" data-slot="<?php echo esc_attr( $data['slot'] ); ?>" data-row="<?php echo esc_attr( $i ); ?>" value="<?php echo esc_attr( $log ? $log->{ 'custom_' . $data['slot'] } : '' ); ?>" placeholder="Type <?php echo esc_attr( $data['label'] ); ?>..." <?php echo $meal_select_disabled; ?>>
                                                <?php else : ?>
                                                    <select class="cmp-meal-select cmp-main-meal" data-cat="<?php echo esc_attr( $row_is_flexible ? 'Any' : $cat ); ?>" data-slot="<?php echo esc_attr( $data['slot'] ); ?>" data-row="<?php echo esc_attr( $i ); ?>" data-saved-val="<?php echo esc_attr( $log ? $log->{ $data['slot'] . '_id' } : '' ); ?>" <?php echo $meal_select_disabled; ?>>
                                                        <option value="">- Select <?php echo esc_html( $data['label'] ); ?> -</option>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>

                                        <?php if ( $snack_quota > 0 ) : ?>
                                        <td class="cmp-cell-snack">
                                            <div class="cmp-snacks-grid <?php echo ( $snack_quota == 1 ) ? 'single-snack' : ''; ?>">
                                                <div class="cmp-snack-slot">
                                                    <span class="cmp-mobile-label"><?php echo ( $snack_quota == 2 ) ? 'Snack 1' : 'Snack'; ?></span>
                                                    <?php if ( $row_allow_custom ) : ?>
                                                        <input type="text" class="cmp-meal-custom cmp-snack-1" data-slot="snack_1" data-row="<?php echo esc_attr( $i ); ?>" value="<?php echo esc_attr( $log ? $log->custom_snack_1 : '' ); ?>" placeholder="Type Snack 1..." <?php echo $meal_select_disabled; ?>>
                                                    <?php else : ?>
                                                        <select class="cmp-meal-select cmp-snack-1" data-cat="Snacks" data-slot="snack_1" data-row="<?php echo esc_attr( $i ); ?>" data-saved-val="<?php echo esc_attr( $log ? $log->snack_1_id : '' ); ?>" <?php echo $meal_select_disabled; ?>>
                                                            <option value="">- Select Snack 1 -</option>
                                                        </select>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ( $snack_quota == 2 ) : ?>
                                                <div class="cmp-snack-slot">
                                                    <span class="cmp-mobile-label">Snack 2</span>
                                                    <?php if ( $row_allow_custom ) : ?>
                                                        <input type="text" class="cmp-meal-custom cmp-snack-2" data-slot="snack_2" data-row="<?php echo esc_attr( $i ); ?>" value="<?php echo esc_attr( $log ? $log->custom_snack_2 : '' ); ?>" placeholder="Type Snack 2..." <?php echo $meal_select_disabled; ?>>
                                                    <?php else : ?>
                                                        <select class="cmp-meal-select cmp-snack-2" data-cat="Snacks" data-slot="snack_2" data-row="<?php echo esc_attr( $i ); ?>" data-saved-val="<?php echo esc_attr( $log ? $log->snack_2_id : '' ); ?>" <?php echo $meal_select_disabled; ?>>
                                                            <option value="">- Select Snack 2 -</option>
                                                        </select>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php endif; ?>

                                        <td class="cmp-cell-macros">
                                            <span class="cmp-mobile-label">Daily Macros</span>
                                            <div class="cmp-macro-display" id="macros-row-<?php echo esc_attr( $i ); ?>">
                                                <?php echo $default_macro_tiles; ?>
                                            </div>
                                        </td>

                                    <?php else : ?>
                                        <?php for ( $j = 1; $j <= 3; $j++ ) : ?>
                                            <td class="cmp-cell-juice cmp-cell-juice-<?php echo esc_attr( $j ); ?>">
                                                <span class="cmp-mobile-label">Juice <?php echo esc_html( $j ); ?></span>
                                                <?php if ( $row_allow_custom ) : ?>
                                                    <input type="text" class="cmp-meal-custom cmp-juice-<?php echo esc_attr( $j ); ?>" data-slot="juice_<?php echo esc_attr( $j ); ?>" data-row="<?php echo esc_attr( $i ); ?>" value="<?php echo esc_attr( $log ? $log->{ 'custom_juice_' . $j } : '' ); ?>" placeholder="Type Juice <?php echo esc_attr( $j ); ?>..." <?php echo $meal_select_disabled; ?>>
                                                <?php else : ?>
                                                    <select class="cmp-meal-select cmp-juice-<?php echo esc_attr( $j ); ?>" data-cat="Juices" data-slot="juice_<?php echo esc_attr( $j ); ?>" data-row="<?php echo esc_attr( $i ); ?>" data-saved-val="<?php echo esc_attr( $log ? $log->{ 'juice_' . $j . '_id' } : '' ); ?>" <?php echo $meal_select_disabled; ?>>
                                                        <option value="">- Select Juice <?php echo esc_html( $j ); ?> -</option>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                        <?php endfor; ?>
                                    <?php endif; ?>

                                    <td class="cmp-cell-status">
                                        <span class="cmp-mobile-label">Status</span>
                                        <div class="cmp-status-box">
                                            <span class="cmp-desktop-status-holder"><?php echo $status_badge; ?></span>
                                            <?php if ( $can_view_pos_check ) : ?>
                                                <span class="cmp-desktop-pos-holder"><?php echo $desktop_pos_badge; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td class="cmp-cell-action">
                                        <span class="cmp-mobile-label">Action</span>
                                        <div class="cmp-action-box">
                                            <?php
                                            if ( $is_chef_override ) {
                                                if ( $saved_chefs_choice ) {
                                                    $chef_btn_text  = $is_chef_assigned ? 'Update' : 'Save';
                                                    $chef_btn_color = $is_chef_assigned ? '#1B5E20' : '#379237';
                                                    echo '<button type="button" class="cmp-save-row" data-sub-id="' . esc_attr( $sub->id ) . '" data-log-id="' . esc_attr( $log_id ) . '" data-row="' . esc_attr( $i ) . '" style="background:' . esc_attr( $chef_btn_color ) . ';">' . esc_html( $chef_btn_text ) . '</button>';
                                                    echo '<button type="button" class="cmp-undo-row" data-row="' . esc_attr( $i ) . '" title="Discard unsaved changes and restore previous selection (Shortcut: Esc)">' . $icon_undo . ' <span>Undo</span></button>';
                                                    if ( $log_id > 0 ) {
                                                        echo '<div class="cmp-secondary-actions">';
                                                        echo '<button type="button" class="cmp-clear-row" data-sub-id="' . esc_attr( $sub->id ) . '" data-log-id="' . esc_attr( $log_id ) . '" title="Reset / Clear Selection">' . $icon_reset . '</button>';
                                                        echo '</div>';
                                                    }
                                                } else {
                                                    echo '<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center; width:100%; padding:10px 0;">Locked (Customer Pick)</span>';
                                                }
                                            } else {
                                                if ( ( ! $is_time_locked || $can_bypass_date_lock ) && ! $is_void && ( ! $is_paused || $can_bypass_date_lock ) ) {
                                                    $btn_txt   = ( $log_id > 0 ) ? 'Update' : 'Save';
                                                    $btn_color = ( $log_id > 0 ) ? '#1B5E20' : '#379237';
                                                    echo '<button type="button" class="cmp-save-row" data-sub-id="' . esc_attr( $sub->id ) . '" data-log-id="' . esc_attr( $log_id ) . '" data-row="' . esc_attr( $i ) . '" style="background:' . esc_attr( $btn_color ) . ';">' . esc_html( $btn_txt ) . '</button>';
                                                    echo '<button type="button" class="cmp-undo-row" data-row="' . esc_attr( $i ) . '" title="Discard unsaved changes and restore previous selection (Shortcut: Esc)">' . $icon_undo . ' <span>Undo</span></button>';

                                                    if ( $log_id > 0 ) {
                                                        echo '<div class="cmp-secondary-actions">';
                                                        echo '<button type="button" class="cmp-clear-row" data-sub-id="' . esc_attr( $sub->id ) . '" data-log-id="' . esc_attr( $log_id ) . '" title="Reset Selection">' . $icon_reset . '</button>';
                                                        echo '<button type="button" class="cmp-cancel-day-row" data-sub-id="' . esc_attr( $sub->id ) . '" data-log-id="' . esc_attr( $log_id ) . '" title="Cancel Day">' . $icon_cancel . '</button>';
                                                        echo '</div>';
                                                    }
                                                } else {
                                                    $lock_reason = 'Cutoff Passed';
                                                    if ( $is_paused ) {
                                                        $lock_reason = 'Paused';
                                                    } elseif ( $is_void ) {
                                                        $lock_reason = 'Void';
                                                    } elseif ( $log && ! empty( $log->target_date ) && $log->target_date <= $today_ymd ) {
                                                        $lock_reason = ( $log->target_date === $today_ymd ) ? 'Today Locked' : 'Past Date';
                                                    }
                                                    echo '<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center; width:100%; padding:10px 0;">Locked (' . esc_html( $lock_reason ) . ')</span>';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </td>

                                    <!-- Compact 1-Line Summary Bar (Visible When Card is Collapsed on Desktop/Tablet) -->
                                    <td class="cmp-collapsed-summary" title="<?php echo $is_void ? 'This day was cancelled' : 'Click to expand meal selections'; ?>">
                                        <span class="cmp-summary-text">Click to view or edit meal selections</span>
                                        <span class="cmp-expand-hint" style="color:#379237; font-size:0.8rem; font-weight:800; white-space:nowrap;">Expand &#9660;</span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- 6. CONTEXTUAL SUBSCRIBE / RENEW MEAL PLAN BANNER -->
                <div class="cmp-renew-banner-card cmp-no-print">
                    <div class="cmp-renew-banner-text">
                        <h4>Ready for Your Next Cycle or Adding Another Plan?</h4>
                        <p>Easily renew your meal plan, switch your meal quota, or subscribe for a family member anytime.</p>
                    </div>
                    <a href="<?php echo esc_url( $subscribe_url ); ?>" class="cmp-renew-cta-btn">
                        <?php echo $icon_plus; ?>
                        <span>Subscribe to a New Meal Plan</span>
                    </a>
                </div>

                <!-- 7. COLLAPSIBLE CUSTOMER EXPERIENCE FEEDBACK SURVEY (COLLAPSED BY DEFAULT) -->
                <div class="cmp-feedback-card cmp-no-print" data-sub-id="<?php echo esc_attr( $sub->id ); ?>">
                    <button type="button" class="cmp-feedback-toggle-header" aria-expanded="false">
                        <div class="cmp-feedback-header-left">
                            <h3>Share Your Experience</h3>
                            <p>Take 15 seconds to rate your meals and online portal experience.</p>
                        </div>
                        <span class="cmp-feedback-toggle-pill">
                            <span class="fb-pill-txt">Rate Now</span>
                            <span class="fb-pill-arrow">&#9660;</span>
                        </span>
                    </button>

                    <div class="cmp-feedback-form-body" id="feedback-body-<?php echo esc_attr( $sub->id ); ?>">
                        <div class="cmp-feedback-ratings-grid">
                            <!-- Rating 1: Meal Plan & Delivery Service -->
                            <div class="cmp-rating-box">
                                <span class="cmp-rating-box-label">1. Meal Plan &amp; Delivery Service</span>
                                <div class="cmp-star-group" data-rating-type="service" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" data-rating="0">
                                    <?php for ( $s = 1; $s <= 5; $s++ ) : ?>
                                        <button type="button" class="cmp-star-btn" data-val="<?php echo esc_attr( $s ); ?>" aria-label="Rate <?php echo esc_attr( $s ); ?> out of 5">&#9733;</button>
                                    <?php endfor; ?>
                                    <span class="cmp-rating-score-txt">Tap to rate</span>
                                </div>
                            </div>

                            <!-- Rating 2: Online Portal Experience -->
                            <div class="cmp-rating-box">
                                <span class="cmp-rating-box-label">2. Online Portal Experience</span>
                                <div class="cmp-star-group" data-rating-type="portal" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" data-rating="0">
                                    <?php for ( $s = 1; $s <= 5; $s++ ) : ?>
                                        <button type="button" class="cmp-star-btn" data-val="<?php echo esc_attr( $s ); ?>" aria-label="Rate <?php echo esc_attr( $s ); ?> out of 5">&#9733;</button>
                                    <?php endfor; ?>
                                    <span class="cmp-rating-score-txt">Tap to rate</span>
                                </div>
                            </div>
                        </div>

                        <!-- Quick One-Tap Highlights -->
                        <div class="cmp-feedback-tags-wrap">
                            <span class="cmp-feedback-tags-label">Quick Highlights (Optional — tap any that apply):</span>
                            <div class="cmp-feedback-tags-list" id="feedback-tags-<?php echo esc_attr( $sub->id ); ?>">
                                <button type="button" class="cmp-feedback-tag-pill" data-tag="Fresh & Tasty Meals">Fresh &amp; Tasty Meals</button>
                                <button type="button" class="cmp-feedback-tag-pill" data-tag="Great Portions">Great Portions</button>
                                <button type="button" class="cmp-feedback-tag-pill" data-tag="On-Time Delivery">On-Time Delivery</button>
                                <button type="button" class="cmp-feedback-tag-pill" data-tag="Clean Packaging">Clean Packaging</button>
                                <button type="button" class="cmp-feedback-tag-pill" data-tag="Easy Portal Scheduling">Easy Portal Scheduling</button>
                                <button type="button" class="cmp-feedback-tag-pill" data-tag="Mobile Friendly">Mobile Friendly</button>
                                <button type="button" class="cmp-feedback-tag-pill" data-tag="More Menu Variety">More Menu Variety</button>
                            </div>
                        </div>

                        <!-- Optional Additional Comments -->
                        <textarea id="feedback-comments-<?php echo esc_attr( $sub->id ); ?>" class="cmp-feedback-textarea" placeholder="Optional: Share any suggestions, favorite dishes, or areas we can improve..."></textarea>

                        <!-- Smart Customer Details Toggle (Option 4A) & Single-Line Status Pill -->
                        <div class="cmp-feedback-footer">
                            <div class="cmp-identity-toggle-wrap">
                                <label class="cmp-identity-toggle-label">
                                    <input type="checkbox" class="cmp-feedback-identity-chk" id="feedback-identity-<?php echo esc_attr( $sub->id ); ?>" data-sub-id="<?php echo esc_attr( $sub->id ); ?>" checked>
                                    <span>Include my account details (<strong><?php echo esc_html( $display_name ); ?></strong>) so the team can follow up</span>
                                </label>
                                <span class="cmp-identity-status-pill" id="feedback-id-pill-<?php echo esc_attr( $sub->id ); ?>">Details Included</span>
                            </div>

                            <button type="button" class="cmp-submit-feedback-btn" data-sub-id="<?php echo esc_attr( $sub->id ); ?>">
                                Submit Feedback
                            </button>
                        </div>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>

            <!-- Floating Unsaved Changes Helper Bar -->
            <div id="cmp-unsaved-float-bar" class="cmp-unsaved-float-bar cmp-no-print" role="status" aria-live="polite">
                <span id="cmp-unsaved-float-text">Unsaved changes</span>
                <button type="button" id="cmp-float-jump-btn" class="cmp-float-btn">Jump to Day</button>
                <button type="button" id="cmp-float-undo-all-btn" class="cmp-float-btn cmp-float-undo-all">Undo All</button>
            </div>

            <!-- Small Back to Top Button (Positioned Directly Above WhatsApp Floating Button) -->
            <button type="button" id="cmp-back-to-top" class="cmp-back-to-top cmp-no-print" title="Back to Top" aria-label="Back to Top">
                <?php echo $icon_up; ?>
            </button>

            <!-- Floating WhatsApp Support Button (Protected 24x24 SVG Class Across Desktop, Tablet & Mobile) -->
            <?php if ( ! empty( $clean_wa ) && ! $is_chef_override ) : ?>
            <a href="https://wa.me/<?php echo esc_attr( $clean_wa ); ?>?text=Hi,%20I%20need%20help%20with%20my%20meal%20plan." target="_blank" rel="noopener noreferrer" class="cmp-wa-float cmp-no-print" aria-label="WhatsApp Support">
                <svg class="cmp-wa-svg" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="#ffffff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                <span class="cmp-wa-text">Support</span>
            </a>
            <?php endif; ?>

        </div>

        <?php
        // Render Interactive JavaScript Engine from customer-portal-assets.php
        if ( function_exists( 'cmp_render_customer_portal_scripts' ) ) {
            cmp_render_customer_portal_scripts( array(
                'ajax_url'             => admin_url( 'admin-ajax.php' ),
                'nonce'                => wp_create_nonce( 'cmp_portal_nonce' ),
                'can_bypass_date_lock' => $can_bypass_date_lock,
                'can_bypass_quota'     => $can_bypass_quota,
                'is_chef_override'     => $is_chef_override,
                'can_view_pos_check'   => $can_view_pos_check,
                'label_chefs_choice'   => $label_chefs_choice,
                'blackout_dates'       => $blackout_dates_array,
                'customer_min_date'    => $customer_min_date,
                'today_ymd'            => $today_ymd,
            ) );
        }

        return ob_get_clean();
    }
}
