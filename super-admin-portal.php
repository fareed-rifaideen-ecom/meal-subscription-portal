<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// 1. AJAX CSV EXPORT FOR EXECUTIVE DRILL-DOWN VIEWS
// ==========================================
add_action( 'wp_ajax_cmp_sa_export_drilldown', 'cmp_sa_export_drilldown_csv' );
function cmp_sa_export_drilldown_csv() {
    if ( ! is_user_logged_in() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) ) {
        wp_die( 'Permission Denied' );
    }

    date_default_timezone_set( 'Asia/Dubai' );
    $view  = isset( $_GET['sa_view'] ) ? sanitize_text_field( $_GET['sa_view'] ) : 'unrenewed_expired';
    $start = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'Y-m-d', strtotime( '-29 days' ) );
    $end   = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'Y-m-d' );

    $dataset = cmp_sa_collect_analytics_data( $start, $end );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="Executive_' . ucfirst( $view ) . '_' . date( 'Y-m-d' ) . '.csv"' );
    $output = fopen( 'php://output', 'w' );
    fputs( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

    if ( $view === 'unrenewed_expired' ) {
        fputcsv( $output, array( 'Last Order ID', 'Customer Name', 'Email', 'Phone', 'Last Plan Name', 'Days Used', 'Total Days', 'Last Plan Paid (AED)', 'Start Date', 'Expired / Completed Date' ) );
        foreach ( $dataset['unrenewed_customers'] as $c ) {
            fputcsv( $output, array(
                $c['order_id'], $c['name'], $c['email'], $c['phone'], $c['plan_name'],
                $c['used_days'], $c['total_days'], number_format( $c['paid'], 2, '.', '' ),
                $c['start_date'], $c['expiry_date']
            ) );
        }
    } elseif ( in_array( $view, array( 'active_plans', 'paused_plans', 'expiring_soon', 'period_sales' ), true ) ) {
        $list_key = 'live_active_list';
        if ( $view === 'paused_plans' )  $list_key = 'live_paused_list';
        if ( $view === 'expiring_soon' ) $list_key = 'expiring_soon_list';
        if ( $view === 'period_sales' )  $list_key = 'period_sales_list';

        fputcsv( $output, array( 'Order ID', 'Customer Name', 'Email', 'Phone', 'Plan Name', 'Status', 'Used Days', 'Total Days', 'Balance Days', 'Gross Price (AED)', 'Discount (AED)', 'Net Paid (AED)', 'Start Date', 'Expiry Date' ) );
        foreach ( $dataset[ $list_key ] as $s ) {
            fputcsv( $output, array(
                $s['order_id'], $s['name'], $s['email'], $s['phone'], $s['plan_name'], $s['status_label'],
                $s['used_days'], $s['total_days'], $s['balance'],
                number_format( $s['price'], 2, '.', '' ), number_format( $s['discount'], 2, '.', '' ), number_format( $s['paid'], 2, '.', '' ),
                $s['start_date'], $s['expiry_date']
            ) );
        }
    } else {
        $list_key = 'pending_pos_list';
        if ( $view === 'completed_pos' )    $list_key = 'completed_pos_list';
        if ( $view === 'pending_delivery' ) $list_key = 'pending_delivery_list';
        if ( $view === 'delivery_logs' )    $list_key = 'period_delivery_list';
        if ( $view === 'pending_chef' )     $list_key = 'pending_chef_list';

        fputcsv( $output, array( 'Eating Date', 'Delivery Date', 'Timing', 'Order ID', 'Customer Name', 'Email', 'Phone', 'Plan Name', 'Prepared', 'Dispatched', 'Delivery Result', 'POS Reconciled' ) );
        foreach ( $dataset[ $list_key ] as $l ) {
            fputcsv( $output, array(
                $l['target_date'], $l['delivery_date'], $l['timing_label'], $l['order_id'],
                $l['name'], $l['email'], $l['phone'], $l['plan_name'],
                $l['is_prepared'] ? 'Yes' : 'No',
                $l['dispatch_status'] ? 'Yes' : 'No',
                $l['delivery_result'],
                $l['pos_updated'] ? 'Yes' : 'No'
            ) );
        }
    }

    fclose( $output );
    exit;
}

// ==========================================
// 2. CENTRALIZED EXECUTIVE DATA ENGINE
// ==========================================
function cmp_sa_format_order_id( $wc_order_id, $sub_id = 0 ) {
    if ( $wc_order_id < 0 ) {
        return 'M-' . str_pad( abs( $wc_order_id ), 4, '0', STR_PAD_LEFT );
    } elseif ( $wc_order_id > 0 ) {
        return '#' . $wc_order_id;
    }
    return 'M-' . str_pad( intval( $sub_id ), 4, '0', STR_PAD_LEFT );
}

function cmp_sa_get_customer_contact( $user_id, $wc_order_id, $fallback_display = '', $fallback_email = '' ) {
    static $cache = array();
    $cache_key = intval( $user_id ) . '_' . intval( $wc_order_id );
    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $order = ( $wc_order_id > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( $wc_order_id ) : false;
    $fname = get_user_meta( $user_id, 'first_name', true ) ?: get_user_meta( $user_id, 'billing_first_name', true );
    $lname = get_user_meta( $user_id, 'last_name', true ) ?: get_user_meta( $user_id, 'billing_last_name', true );
    $name  = $order ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : trim( $fname . ' ' . $lname );
    if ( empty( $name ) ) {
        $name = $fallback_display ?: 'Customer #' . $user_id;
    }

    $email  = $order ? ( $order->get_billing_email() ?: $fallback_email ) : $fallback_email;
    $phone  = $order ? $order->get_billing_phone() : get_user_meta( $user_id, 'billing_phone', true );
    $timing = $order ? ( $order->get_meta( '_cmp_delivery_timing' ) ?: $order->get_meta( 'delivery_timing' ) ) : '';
    if ( empty( $timing ) ) {
        $timing = get_user_meta( $user_id, 'delivery_timing', true ) ?: 'Same Day';
    }
    $is_day_before = ( stripos( $timing, 'Day Before' ) !== false );

    $cache[ $cache_key ] = array(
        'name'          => $name,
        'email'         => $email,
        'phone'         => $phone ?: 'N/A',
        'timing_label'  => $is_day_before ? 'Day Before' : 'Same Day',
        'is_day_before' => $is_day_before,
    );
    return $cache[ $cache_key ];
}

function cmp_sa_collect_analytics_data( $global_start, $global_end ) {
    global $wpdb;
    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
    $table_foods = $wpdb->prefix . 'cmp_foods';

    $today_time    = date( 'Y-m-d H:i:s' );
    $today_date    = date( 'Y-m-d' );
    $tomorrow_date = date( 'Y-m-d', strtotime( '+1 day' ) );

    // 1. Pre-aggregate usage counts in a single SQL query
    $usage_rows = $wpdb->get_results(
        "SELECT subscription_id, COUNT(id) AS used_cnt, MAX(target_date) AS last_meal_date 
         FROM $table_logs 
         WHERE delivery_result = 'Successful' 
         GROUP BY subscription_id"
    );
    $usage_map     = array();
    $last_meal_map = array();
    if ( $usage_rows ) {
        foreach ( $usage_rows as $ur ) {
            $usage_map[ intval( $ur->subscription_id ) ]     = intval( $ur->used_cnt );
            $last_meal_map[ intval( $ur->subscription_id ) ] = $ur->last_meal_date;
        }
    }

    // 2. Fetch all subscriptions with user details
    $all_subs = $wpdb->get_results(
        "SELECT s.*, u.display_name, u.user_email 
         FROM $table_subs s 
         LEFT JOIN {$wpdb->prefix}users u ON s.user_id = u.ID 
         WHERE s.status != 'pending' 
         ORDER BY s.id DESC"
    );

    $live_active_list   = array();
    $live_paused_list   = array();
    $expiring_soon_list = array();
    $period_sales_list  = array();
    $user_history       = array();

    $gross_turnover  = 0.0;
    $total_discounts = 0.0;
    $net_turnover    = 0.0;

    foreach ( $all_subs as $sub ) {
        $uid        = intval( $sub->user_id );
        $sub_id     = intval( $sub->id );
        $used_days  = isset( $usage_map[ $sub_id ] ) ? $usage_map[ $sub_id ] : 0;
        $total_days = intval( $sub->total_days );
        $balance    = max( 0, $total_days - $used_days );

        $contact = cmp_sa_get_customer_contact( $uid, $sub->wc_order_id, $sub->display_name, $sub->user_email );

        $price      = isset( $sub->plan_price ) ? floatval( $sub->plan_price ) : 0.0;
        $discount   = isset( $sub->discount_amount ) ? floatval( $sub->discount_amount ) : 0.0;
        $final_paid = max( 0.0, $price - $discount );

        if ( $final_paid == 0 && $sub->wc_order_id > 0 && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $sub->wc_order_id );
            if ( $order ) {
                $price      = floatval( $order->get_subtotal() );
                $discount   = floatval( $order->get_discount_total() );
                $final_paid = floatval( $order->get_total() );
            }
        }

        $is_completed = ( $used_days >= $total_days || $sub->expiry_date < $today_time );
        if ( $is_completed ) {
            $status_key   = 'expired';
            $status_label = 'Completed / Expired';
        } elseif ( $sub->status === 'paused' ) {
            $status_key   = 'paused';
            $status_label = 'Paused';
        } else {
            $status_key   = 'active';
            $status_label = 'Active';
        }

        $end_ref_date = ! empty( $last_meal_map[ $sub_id ] ) && $used_days >= $total_days
            ? $last_meal_map[ $sub_id ]
            : substr( $sub->expiry_date, 0, 10 );

        $sub_record = array(
            'sub_id'       => $sub_id,
            'user_id'      => $uid,
            'order_id'     => cmp_sa_format_order_id( $sub->wc_order_id, $sub_id ),
            'name'         => $contact['name'],
            'email'        => $contact['email'],
            'phone'        => $contact['phone'],
            'plan_name'    => $sub->plan_name,
            'status_key'   => $status_key,
            'status_label' => $status_label,
            'used_days'    => $used_days,
            'total_days'   => $total_days,
            'balance'      => $balance,
            'price'        => $price,
            'discount'     => $discount,
            'paid'         => $final_paid,
            'start_date'   => substr( $sub->start_date, 0, 10 ),
            'expiry_date'  => $end_ref_date,
        );

        if ( ! isset( $user_history[ $uid ] ) ) {
            $user_history[ $uid ] = array(
                'has_active_or_paused' => false,
                'latest_expired'       => null,
            );
        }

        if ( $status_key === 'active' ) {
            $live_active_list[] = $sub_record;
            $user_history[ $uid ]['has_active_or_paused'] = true;
            if ( $balance >= 1 && $balance <= 3 ) {
                $expiring_soon_list[] = $sub_record;
            }
        } elseif ( $status_key === 'paused' ) {
            $live_paused_list[] = $sub_record;
            $user_history[ $uid ]['has_active_or_paused'] = true;
        } else {
            if ( $user_history[ $uid ]['latest_expired'] === null ) {
                $user_history[ $uid ]['latest_expired'] = $sub_record;
            }
        }

        // Period Sales Check
        $start_ymd = substr( $sub->start_date, 0, 10 );
        if ( $start_ymd >= $global_start && $start_ymd <= $global_end ) {
            $period_sales_list[] = $sub_record;
            $gross_turnover     += $price;
            $total_discounts    += $discount;
            $net_turnover       += $final_paid;
        }
    }

    // 3. Build Unrenewed Expired Customers List (Retargeting Pool)
    // Strictly customers who have 1+ expired plans AND 0 active/paused plans!
    $unrenewed_customers        = array();
    $unrenewed_in_period_count  = 0;
    foreach ( $user_history as $uid => $uh ) {
        if ( ! $uh['has_active_or_paused'] && $uh['latest_expired'] !== null ) {
            $exp_rec = $uh['latest_expired'];
            $unrenewed_customers[] = $exp_rec;
            if ( $exp_rec['expiry_date'] >= $global_start && $exp_rec['expiry_date'] <= $global_end ) {
                $unrenewed_in_period_count++;
            }
        }
    }

    // 4. Fetch Daily Logs for Compliance, Delivery Status & Operational Pulse
    $prep_target_start = date( 'Y-m-d', strtotime( $global_start . ' +1 day' ) );
    $prep_target_end   = date( 'Y-m-d', strtotime( $global_end . ' +1 day' ) );
    $min_query_date    = min( $global_start, $today_date );
    $max_query_date    = max( $prep_target_end, $tomorrow_date );

    $logs_raw = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.wc_order_id, s.plan_name, s.status AS sub_status, s.allow_custom, u.display_name, u.user_email
         FROM $table_logs l
         JOIN $table_subs s ON l.subscription_id = s.id
         LEFT JOIN {$wpdb->prefix}users u ON l.user_id = u.ID
         WHERE l.target_date >= %s AND l.target_date <= %s
         ORDER BY l.target_date DESC, l.id DESC",
        $min_query_date, $max_query_date
    ) );

    $active_food_ranges = $wpdb->get_results( "SELECT DISTINCT valid_from, valid_until FROM $table_foods WHERE is_active = 1" );

    $pending_pos_list      = array();
    $completed_pos_list    = array();
    $pending_delivery_list = array();
    $period_delivery_list  = array();
    $pending_chef_list     = array();

    $total_preps        = 0;
    $done_preps         = 0;
    $today_total_preps  = 0;
    $today_done_preps   = 0;
    $dispatch_load      = 0;
    $today_dispatch     = 0;

    $successful_deliveries = 0;
    $failed_deliveries     = 0;
    $pending_deliveries_period = 0;

    if ( $logs_raw ) {
        foreach ( $logs_raw as $l ) {
            $contact  = cmp_sa_get_customer_contact( $l->user_id, $l->wc_order_id, $l->display_name, $l->user_email );
            $prep_dt  = date( 'Y-m-d', strtotime( $l->target_date . ' -1 day' ) );
            $del_date = $contact['is_day_before'] ? $prep_dt : $l->target_date;
            $del_res  = ! empty( $l->delivery_result ) ? $l->delivery_result : 'Pending';
            $is_void  = in_array( $del_res, array( 'Cancelled', 'Returned' ), true );

            $log_item = array(
                'log_id'          => intval( $l->id ),
                'sub_id'          => intval( $l->subscription_id ),
                'order_id'        => cmp_sa_format_order_id( $l->wc_order_id, $l->subscription_id ),
                'name'            => $contact['name'],
                'email'           => $contact['email'],
                'phone'           => $contact['phone'],
                'plan_name'       => $l->plan_name,
                'target_date'     => $l->target_date,
                'delivery_date'   => $del_date,
                'timing_label'    => $contact['timing_label'],
                'is_prepared'     => intval( $l->is_prepared ),
                'dispatch_status' => intval( $l->dispatch_status ),
                'delivery_result' => $del_res,
                'pos_updated'     => intval( $l->pos_updated ),
            );

            // A. Period Logs (global_start to global_end)
            if ( $l->target_date >= $global_start && $l->target_date <= $global_end ) {
                if ( $l->is_locked == 1 ) {
                    $period_delivery_list[] = $log_item;

                    // POS Reconciliation
                    if ( $l->pos_updated == 0 && $del_res !== 'Pending' ) {
                        $pending_pos_list[] = $log_item;
                    } elseif ( $l->pos_updated == 1 ) {
                        $completed_pos_list[] = $log_item;
                    }

                    // Delivery Status Breakdown
                    if ( $del_res === 'Successful' && $l->pos_updated == 1 ) {
                        $successful_deliveries++;
                    } elseif ( $is_void ) {
                        $failed_deliveries++;
                    } elseif ( $del_res === 'Pending' && $l->sub_status !== 'paused' && $l->target_date <= $today_date ) {
                        $pending_deliveries_period++;
                        $pending_delivery_list[] = $log_item;
                    }

                    // Period Dispatch Load
                    if ( ! $is_void && $l->sub_status !== 'paused' ) {
                        $dispatch_load++;
                    }
                }
            }

            // B. Period Meal Preparations (prep_target_start to prep_target_end)
            if ( $l->target_date >= $prep_target_start && $l->target_date <= $prep_target_end ) {
                if ( $l->is_locked == 1 && $l->sub_status !== 'paused' && ! $is_void ) {
                    $total_preps++;
                    if ( $l->is_prepared == 1 ) {
                        $done_preps++;
                    }
                }
            }

            // C. Today's Live Prep (for tomorrow) & Today's Dispatch
            if ( $l->target_date === $tomorrow_date && $l->is_locked == 1 && $l->sub_status !== 'paused' && ! $is_void ) {
                $today_total_preps++;
                if ( $l->is_prepared == 1 ) {
                    $today_done_preps++;
                }
            }
            if ( $l->target_date === $today_date && $l->is_locked == 1 && $l->sub_status !== 'paused' && ! $is_void ) {
                $today_dispatch++;
            }

            // D. Pending Chef Assignments (Active subscriptions, not void)
            if ( $l->is_chefs_choice == 1 && $l->sub_status === 'active' && ! $is_void && $l->target_date >= $global_start ) {
                $is_assigned = (
                    ! empty( $l->breakfast_id ) || ! empty( $l->lunch_id ) || ! empty( $l->dinner_id ) || ! empty( $l->juice_1_id ) ||
                    ! empty( $l->custom_breakfast ) || ! empty( $l->custom_lunch ) || ! empty( $l->custom_dinner ) || ! empty( $l->custom_juice_1 )
                );
                if ( ! $is_assigned ) {
                    $row_allow_custom = ( ! empty( $l->plan_mode ) && $l->plan_mode === 'custom' ) || ! empty( $l->allow_custom );
                    $menu_exists      = false;
                    if ( $row_allow_custom ) {
                        $menu_exists = true;
                    } else {
                        foreach ( $active_food_ranges as $range ) {
                            if ( empty( $range->valid_from ) || empty( $range->valid_until ) ) {
                                $menu_exists = true;
                                break;
                            }
                            if ( $l->target_date >= $range->valid_from && $l->target_date <= $range->valid_until ) {
                                $menu_exists = true;
                                break;
                            }
                        }
                    }
                    if ( $menu_exists ) {
                        $pending_chef_list[] = $log_item;
                    }
                }
            }
        }
    }

    $total_del_resolved = $successful_deliveries + $failed_deliveries;
    $success_rate       = ( $total_del_resolved > 0 ) ? round( ( $successful_deliveries / $total_del_resolved ) * 100, 1 ) : 0;
    $prep_percentage    = ( $total_preps > 0 ) ? round( ( $done_preps / $total_preps ) * 100 ) : 0;
    $avg_plan_value     = count( $period_sales_list ) > 0 ? ( $net_turnover / count( $period_sales_list ) ) : 0.0;

    return array(
        'live_active_list'          => $live_active_list,
        'live_paused_list'          => $live_paused_list,
        'expiring_soon_list'        => $expiring_soon_list,
        'period_sales_list'         => $period_sales_list,
        'unrenewed_customers'       => $unrenewed_customers,
        'unrenewed_in_period_count' => $unrenewed_in_period_count,
        'pending_pos_list'          => $pending_pos_list,
        'completed_pos_list'        => $completed_pos_list,
        'pending_delivery_list'     => $pending_delivery_list,
        'period_delivery_list'      => $period_delivery_list,
        'pending_chef_list'         => $pending_chef_list,
        'gross_turnover'            => $gross_turnover,
        'total_discounts'           => $total_discounts,
        'net_turnover'              => $net_turnover,
        'avg_plan_value'            => $avg_plan_value,
        'total_preps'               => $total_preps,
        'done_preps'                => $done_preps,
        'prep_percentage'           => $prep_percentage,
        'today_total_preps'         => $today_total_preps,
        'today_done_preps'          => $today_done_preps,
        'dispatch_load'             => $dispatch_load,
        'today_dispatch'            => $today_dispatch,
        'successful_deliveries'     => $successful_deliveries,
        'failed_deliveries'         => $failed_deliveries,
        'pending_deliveries_period' => $pending_deliveries_period,
        'success_rate'              => $success_rate,
    );
}

// ==========================================
// 3. UNIFIED SUPER ADMIN COMMAND CENTER ROUTER
// ==========================================
add_shortcode( 'meal_super_admin', 'cmp_render_super_admin_portal' );

function cmp_render_super_admin_portal() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    date_default_timezone_set( 'Asia/Dubai' );

    $has_exec_access = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' );

    // Security & Custom Glassmorphism UI/UX Login Screen
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

    $today_date   = date( 'Y-m-d' );
    $preset_7d    = date( 'Y-m-d', strtotime( '-6 days' ) );
    $preset_30d   = date( 'Y-m-d', strtotime( '-29 days' ) );
    $preset_month = date( 'Y-m-01' );
    $preset_90d   = date( 'Y-m-d', strtotime( '-89 days' ) );

    $global_start = isset( $_GET['start_date'] ) && ! empty( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : $preset_30d;
    $global_end   = isset( $_GET['end_date'] ) && ! empty( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : $today_date;

    if ( $global_start > $global_end ) {
        $tmp          = $global_start;
        $global_start = $global_end;
        $global_end   = $tmp;
    }

    $sa_view = isset( $_GET['sa_view'] ) ? sanitize_text_field( $_GET['sa_view'] ) : '';
    $data    = cmp_sa_collect_analytics_data( $global_start, $global_end );

    if ( ! empty( $sa_view ) ) {
        return cmp_sa_render_drilldown_screen( $sa_view, $data, $global_start, $global_end );
    }

    $current_user = wp_get_current_user();
    $first_name   = ! empty( $current_user->user_firstname ) ? $current_user->user_firstname : $current_user->display_name;

    $active_preset = 'custom';
    if ( $global_end === $today_date ) {
        if ( $global_start === $today_date )       { $active_preset = 'today'; }
        elseif ( $global_start === $preset_7d )    { $active_preset = '7d'; }
        elseif ( $global_start === $preset_30d )   { $active_preset = '30d'; }
        elseif ( $global_start === $preset_month ) { $active_preset = 'month'; }
        elseif ( $global_start === $preset_90d )   { $active_preset = '90d'; }
    }

    $pending_pos_count      = count( $data['pending_pos_list'] );
    $completed_pos_count    = count( $data['completed_pos_list'] );
    $pending_delivery_count = count( $data['pending_delivery_list'] );
    $pending_chef_count     = count( $data['pending_chef_list'] );
    $unrenewed_total_count  = count( $data['unrenewed_customers'] );
    $expiring_soon_count    = count( $data['expiring_soon_list'] );
    $live_active_count      = count( $data['live_active_list'] );
    $live_paused_count      = count( $data['live_paused_list'] );
    $period_new_plans       = count( $data['period_sales_list'] );

    $base_filter_args = array(
        'start_date' => $global_start,
        'end_date'   => $global_end,
    );

    ob_start();
    ?>
    <style>
        .acru-dashboard, .acru-dashboard * { box-sizing: border-box; }
        /* Hard lock on all SVG and Emoji images inside the dashboard so WP/Theme CSS can never blow them up */
        .acru-dashboard svg,
        .acru-dashboard img.emoji,
        .acru-dashboard img.wp-smiley {
            width: 18px !important;
            height: 18px !important;
            max-width: 22px !important;
            max-height: 22px !important;
            display: inline-block !important;
            vertical-align: middle !important;
            flex-shrink: 0 !important;
        }
        .acru-dashboard {
            background: linear-gradient(145deg, rgba(244, 248, 242, 0.94) 0%, rgba(238, 247, 227, 0.65) 100%);
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #231F20;
            border-radius: 22px;
            border: 1px solid #DCECC9;
            max-width: 1350px;
            margin: 0 auto 40px auto;
            box-shadow: 0 12px 34px rgba(27, 94, 32, 0.06);
        }

        /* Top Glass Header */
        .acru-top-bar {
            background: linear-gradient(135deg, rgba(27, 94, 32, 0.95) 0%, rgba(55, 146, 55, 0.89) 55%, rgba(104, 185, 70, 0.85) 100%) !important;
            backdrop-filter: blur(18px) !important;
            -webkit-backdrop-filter: blur(18px) !important;
            padding: 22px 26px !important;
            border-radius: 18px !important;
            border: 1.5px solid #DCECC9 !important;
            border-bottom: 4px solid #68B946 !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-bottom: 20px !important;
            flex-wrap: wrap !important;
            gap: 16px !important;
            box-shadow: 0 12px 28px rgba(27, 94, 32, 0.14) !important;
        }
        .acru-welcome h1 { font-size: 1.75em !important; font-weight: 800 !important; margin: 0 !important; color: #ffffff !important; letter-spacing: -0.02em !important; }
        .acru-welcome p { font-size: 0.95em !important; color: #EEF7E3 !important; margin: 4px 0 0 0 !important; font-weight: 500 !important; }

        .acru-controls-wrap { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .acru-preset-pills { display: inline-flex; background: rgba(255, 255, 255, 0.16); padding: 4px; border-radius: 10px; border: 1px solid rgba(220, 236, 201, 0.4); gap: 4px; flex-wrap: wrap; }
        .acru-preset-pill { padding: 6px 11px; border-radius: 7px; font-size: 0.8em; font-weight: 700; color: #EEF7E3 !important; text-decoration: none !important; transition: 0.2s; white-space: nowrap; }
        .acru-preset-pill:hover { background: rgba(255, 255, 255, 0.24); color: #ffffff !important; }
        .acru-preset-pill.active { background: #ffffff; color: #1B5E20 !important; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }

        .acru-global-filter { display: flex; background: rgba(255, 255, 255, 0.96); padding: 5px 10px; border-radius: 10px; border: 1px solid #DCECC9; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); flex-wrap: wrap; }
        .acru-global-filter input[type="date"] { border: 1px solid #cbd5e1; border-radius: 6px; padding: 5px 8px; font-size: 0.85em; color: #231F20; outline: none; background: #fff; font-weight: 600; }
        .acru-global-filter button { background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: #fff; border: none; padding: 6px 14px; border-radius: 7px; font-weight: 700; font-size: 0.85em; cursor: pointer; transition: 0.2s; }
        .acru-global-filter button:hover { background: linear-gradient(135deg, #68B946 0%, #379237 100%); }

        .acru-logout-btn { background: #dc2626; color: #ffffff !important; padding: 9px 16px; border-radius: 10px; text-decoration: none !important; font-weight: 700; font-size: 0.88em; border: 1px solid rgba(255,255,255,0.3); transition: 0.2s; white-space: nowrap; }
        .acru-logout-btn:hover { background: #b91c1c; color: #ffffff !important; }

        /* Compact Action Required Alert Bar */
        .acru-alert-bar {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            border-left: 5px solid #dc2626;
            padding: 12px 18px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            color: #991b1b;
        }
        .acru-alert-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 0.92em; }
        .acru-alert-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ffffff;
            color: #991b1b !important;
            border: 1px solid #fca5a5;
            padding: 4px 11px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.9em;
            text-decoration: none !important;
            transition: 0.2s;
        }
        .acru-alert-pill:hover { background: #dc2626; color: #ffffff !important; border-color: #dc2626; }

        /* =========================================================
           TOP PRIORITY HIGHLIGHT STRIP (POS, DELIVERY, RETARGETING)
           ========================================================= */
        .acru-priority-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .acru-priority-card {
            background: #ffffff;
            border-radius: 15px;
            padding: 18px 20px;
            border: 1.5px solid #e2e8f0;
            border-top: 5px solid #379237;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
            text-decoration: none !important;
            color: inherit !important;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
        }
        .acru-priority-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(27, 94, 32, 0.12);
        }
        .acru-priority-card.urgent-red {
            border-color: #fecaca;
            border-top: 5px solid #dc2626;
            background: linear-gradient(180deg, #fff5f5 0%, #ffffff 65%);
        }
        .acru-priority-card.urgent-amber {
            border-color: #fde68a;
            border-top: 5px solid #f59e0b;
            background: linear-gradient(180deg, #fffbeb 0%, #ffffff 65%);
        }
        .acru-priority-card.brand-green {
            border-color: #DCECC9;
            border-top: 5px solid #379237;
            background: linear-gradient(180deg, #f4fbf0 0%, #ffffff 65%);
        }
        .acru-priority-card.retarget-purple {
            border-color: #ddd6fe;
            border-top: 5px solid #7c3aed;
            background: linear-gradient(180deg, #f5f3ff 0%, #ffffff 65%);
        }
        .priority-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .priority-title { font-size: 0.78em; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; }
        .priority-badge { font-size: 0.74em; font-weight: 800; padding: 3px 8px; border-radius: 999px; }
        .priority-val { font-size: 2.35em; font-weight: 900; line-height: 1.05; color: #1e293b; margin: 2px 0 8px 0; }
        .priority-foot { font-size: 0.82em; font-weight: 700; color: #379237; display: flex; justify-content: space-between; align-items: center; padding-top: 8px; border-top: 1px dashed #e2e8f0; }

        /* 12-Column Bento Grid */
        .acru-bento-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 20px; }
        .acru-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 16px;
            padding: 22px 24px;
            border: 1px solid #DCECC9;
            box-shadow: 0 6px 18px rgba(27, 94, 32, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        .col-span-7 { grid-column: span 7; }
        .col-span-5 { grid-column: span 5; }
        .col-span-4 { grid-column: span 4; }
        .col-span-6 { grid-column: span 6; }
        .col-span-12 { grid-column: span 12; }

        .card-label { font-size: 0.82em; font-weight: 800; color: #1B5E20; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; }
        .card-value { font-size: 2.25em; font-weight: 900; color: #231F20; line-height: 1.1; }
        .card-subtext { font-size: 0.84em; color: #64748b; margin-top: 8px; font-weight: 500; }

        /* Hero Financial Sub-Grids (Clickable) */
        .hero-fin-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 16px; padding: 12px 14px; background: rgba(0, 0, 0, 0.18); border-radius: 12px; border: 1px solid rgba(220, 236, 201, 0.25); }
        .hero-health-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(255, 255, 255, 0.22); }
        .hero-stat-link { text-decoration: none !important; color: #ffffff !important; padding: 6px 8px; border-radius: 8px; transition: background 0.2s; display: block; }
        .hero-stat-link:hover { background: rgba(255, 255, 255, 0.14); }

        /* 4-Portal Quick Launch Grid */
        .quick-launch-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 10px; }
        .ql-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 16px;
            background: #f8fcf5;
            border: 1.5px solid #DCECC9;
            border-radius: 12px;
            color: #1B5E20 !important;
            font-weight: 800;
            text-decoration: none !important;
            font-size: 0.92em;
            transition: all 0.2s ease;
        }
        .ql-btn:hover {
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
            color: #ffffff !important;
            border-color: #1B5E20;
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(55, 146, 55, 0.22);
        }

        /* Progress Bars & Metric Rows */
        .progress-container { background: #e2e8f0; border-radius: 999px; height: 10px; width: 100%; overflow: hidden; margin: 12px 0 6px 0; }
        .progress-bar-fill { background: linear-gradient(90deg, #68B946, #379237); height: 100%; border-radius: 999px; transition: width 0.4s ease; }

        .metric-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #f1f5f9; }
        .metric-row:last-child { border-bottom: none; }
        .metric-badge-link { text-decoration: none !important; padding: 4px 10px; border-radius: 8px; font-weight: 800; font-size: 0.85em; transition: 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .metric-badge-link:hover { transform: translateY(-1px); filter: brightness(0.95); }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .acru-priority-strip { grid-template-columns: repeat(2, 1fr); }
            .col-span-7, .col-span-5 { grid-column: span 12; }
            .col-span-4 { grid-column: span 6; }
            .acru-bento-grid > .col-span-4:last-child { grid-column: span 12; }
        }
        @media (max-width: 640px) {
            .acru-dashboard { padding: 14px; }
            .acru-priority-strip { grid-template-columns: 1fr; }
            .col-span-7, .col-span-5, .col-span-4, .col-span-6 { grid-column: span 12; }
            .hero-fin-strip, .hero-health-strip { grid-template-columns: repeat(2, 1fr); }
            .quick-launch-grid { grid-template-columns: 1fr; }
        }
    </style>

    <div class="acru-dashboard">
        
        <!-- Top Navigation & Global Filter Bar -->
        <div class="acru-top-bar">
            <div class="acru-welcome">
                <h1>Executive Command Center</h1>
                <p>Welcome back, <strong><?php echo esc_html( $first_name ); ?></strong>. Click any metric card to inspect full records.</p>
            </div>
            
            <div class="acru-controls-wrap">
                <div class="acru-preset-pills">
                    <a href="<?php echo esc_url( add_query_arg( array( 'start_date' => $today_date, 'end_date' => $today_date ) ) ); ?>" class="acru-preset-pill <?php echo $active_preset === 'today' ? 'active' : ''; ?>">Today</a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'start_date' => $preset_7d, 'end_date' => $today_date ) ) ); ?>" class="acru-preset-pill <?php echo $active_preset === '7d' ? 'active' : ''; ?>">7D</a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'start_date' => $preset_30d, 'end_date' => $today_date ) ) ); ?>" class="acru-preset-pill <?php echo $active_preset === '30d' ? 'active' : ''; ?>">30D</a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'start_date' => $preset_month, 'end_date' => $today_date ) ) ); ?>" class="acru-preset-pill <?php echo $active_preset === 'month' ? 'active' : ''; ?>">This Month</a>
                    <a href="<?php echo esc_url( add_query_arg( array( 'start_date' => $preset_90d, 'end_date' => $today_date ) ) ); ?>" class="acru-preset-pill <?php echo $active_preset === '90d' ? 'active' : ''; ?>">90D</a>
                </div>

                <form method="GET" class="acru-global-filter">
                    <input type="date" name="start_date" value="<?php echo esc_attr( $global_start ); ?>">
                    <span style="color: #64748b; font-weight: 700; font-size: 0.85em;">to</span>
                    <input type="date" name="end_date" value="<?php echo esc_attr( $global_end ); ?>">
                    <button type="submit">Apply</button>
                </form>

                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="acru-logout-btn">Log Out</a>
            </div>
        </div>

        <!-- Compact SVG Action Required Banner (Strictly Sized 18px SVG Icon) -->
        <?php if ( $pending_pos_count > 0 || $pending_delivery_count > 0 || $pending_chef_count > 0 ) : ?>
        <div class="acru-alert-bar">
            <div class="acru-alert-left">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:18px !important; height:18px !important; flex-shrink:0;">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                <strong>Action Required:</strong>
                <?php if ( $pending_pos_count > 0 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'pending_pos' ) ) ) ); ?>" class="acru-alert-pill">
                        <?php echo number_format( $pending_pos_count ); ?> Un-reconciled POS Checks &rarr;
                    </a>
                <?php endif; ?>
                <?php if ( $pending_delivery_count > 0 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'pending_delivery' ) ) ) ); ?>" class="acru-alert-pill">
                        <?php echo number_format( $pending_delivery_count ); ?> Pending Delivery Status &rarr;
                    </a>
                <?php endif; ?>
                <?php if ( $pending_chef_count > 0 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'pending_chef' ) ) ) ); ?>" class="acru-alert-pill">
                        <?php echo number_format( $pending_chef_count ); ?> Pending Chef Assignments &rarr;
                    </a>
                <?php endif; ?>
            </div>
            <span style="font-size: 0.82em; font-weight: 700; color: #991b1b;">Click any pill to open detailed view</span>
        </div>
        <?php endif; ?>

        <!-- =========================================================
             TOP PRIORITY HIGHLIGHT ROW: POS CHECKS, DELIVERY STATUS & RETARGETING
             ========================================================= -->
        <div class="acru-priority-strip">
            <!-- Priority 1: Pending POS Checks -->
            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'pending_pos' ) ) ) ); ?>" class="acru-priority-card <?php echo $pending_pos_count > 0 ? 'urgent-red' : 'brand-green'; ?>">
                <div>
                    <div class="priority-top">
                        <span class="priority-title">Pending POS Checks</span>
                        <span class="priority-badge" style="background: <?php echo $pending_pos_count > 0 ? '#fee2e2' : '#dcfce7'; ?>; color: <?php echo $pending_pos_count > 0 ? '#991b1b' : '#166534'; ?>;">
                            <?php echo $pending_pos_count > 0 ? 'Needs FOH Action' : 'All Reconciled'; ?>
                        </span>
                    </div>
                    <div class="priority-val" style="color: <?php echo $pending_pos_count > 0 ? '#dc2626' : '#166534'; ?>;">
                        <?php echo number_format( $pending_pos_count ); ?>
                    </div>
                </div>
                <div class="priority-foot">
                    <span style="color:#64748b; font-weight:600;"><?php echo number_format( $completed_pos_count ); ?> Completed in period</span>
                    <span>View Records &rarr;</span>
                </div>
            </a>

            <!-- Priority 2: Pending Delivery Status (Kitchen) -->
            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'pending_delivery' ) ) ) ); ?>" class="acru-priority-card <?php echo $pending_delivery_count > 0 ? 'urgent-amber' : 'brand-green'; ?>">
                <div>
                    <div class="priority-top">
                        <span class="priority-title">Pending Delivery Status</span>
                        <span class="priority-badge" style="background: <?php echo $pending_delivery_count > 0 ? '#fef3c7' : '#dcfce7'; ?>; color: <?php echo $pending_delivery_count > 0 ? '#92400e' : '#166534'; ?>;">
                            <?php echo $pending_delivery_count > 0 ? 'Unlogged Status' : 'Up to Date'; ?>
                        </span>
                    </div>
                    <div class="priority-val" style="color: <?php echo $pending_delivery_count > 0 ? '#d97706' : '#166534'; ?>;">
                        <?php echo number_format( $pending_delivery_count ); ?>
                    </div>
                </div>
                <div class="priority-foot">
                    <span style="color:#64748b; font-weight:600;">Past/Today meals still 'Pending'</span>
                    <span>View Records &rarr;</span>
                </div>
            </a>

            <!-- Priority 3: Delivery Status Performance -->
            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'delivery_logs' ) ) ) ); ?>" class="acru-priority-card brand-green">
                <div>
                    <div class="priority-top">
                        <span class="priority-title">Delivery Status Summary</span>
                        <span class="priority-badge" style="background: #EEF7E3; color: #1B5E20;">
                            <?php echo $data['success_rate']; ?>% Success
                        </span>
                    </div>
                    <div class="priority-val" style="color: #1B5E20;">
                        <?php echo number_format( $data['successful_deliveries'] ); ?>
                        <small style="font-size: 0.42em; color: #dc2626; font-weight: 800; margin-left: 4px;">(<?php echo number_format( $data['failed_deliveries'] ); ?> Ret/Canc)</small>
                    </div>
                </div>
                <div class="priority-foot">
                    <span style="color:#64748b; font-weight:600;">Verified deliveries vs failures</span>
                    <span>Inspect Logs &rarr;</span>
                </div>
            </a>

            <!-- Priority 4: Unrenewed Expired Customers (Retargeting Pool) -->
            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'unrenewed_expired' ) ) ) ); ?>" class="acru-priority-card retarget-purple">
                <div>
                    <div class="priority-top">
                        <span class="priority-title">Unrenewed Expired</span>
                        <span class="priority-badge" style="background: #ede9fe; color: #6d28d9;">
                            Retargeting Pool
                        </span>
                    </div>
                    <div class="priority-val" style="color: #6d28d9;">
                        <?php echo number_format( $unrenewed_total_count ); ?>
                    </div>
                </div>
                <div class="priority-foot" style="color: #6d28d9;">
                    <span style="color:#64748b; font-weight:600;">Expired &amp; no active plan</span>
                    <span>Retarget List &rarr;</span>
                </div>
            </a>
        </div>

        <!-- =========================================================
             MAIN BENTO GRID
             ========================================================= -->
        <div class="acru-bento-grid">

            <!-- Card 1: Net Financial Turnover & Live Subscription Health (Hero Card) -->
            <div class="acru-card col-span-7" style="background: linear-gradient(135deg, #1B5E20 0%, #379237 100%); color: #fff; border-color: #1B5E20;">
                <div>
                    <div class="card-label" style="color: #DCECC9;">
                        <span>Period Net Financial Turnover</span>
                        <span style="background: rgba(220,236,201,0.2); padding: 3px 10px; border-radius: 999px; font-size: 0.85em; color: #EEF7E3;">
                            <?php echo esc_html( date( 'd M Y', strtotime( $global_start ) ) . ' – ' . date( 'd M Y', strtotime( $global_end ) ) ); ?>
                        </span>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:10px; margin-top: 4px;">
                        <div class="card-value" style="color: #fff; font-size: 2.65em;">AED <?php echo number_format( $data['net_turnover'], 2 ); ?></div>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" style="color:#EEF7E3; font-size:0.85em; font-weight:700; text-decoration:underline;">View Sales Breakdown &rarr;</a>
                    </div>
                    
                    <!-- Period Financial Breakdown Strip -->
                    <div class="hero-fin-strip">
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" class="hero-stat-link">
                            <span style="font-size: 0.75em; color: #DCECC9; display: block;">New Plans Sold</span>
                            <strong style="font-size: 1.2em; color: #fff;"><?php echo number_format( $period_new_plans ); ?> &rarr;</strong>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" class="hero-stat-link">
                            <span style="font-size: 0.75em; color: #DCECC9; display: block;">Gross Value</span>
                            <strong style="font-size: 1.05em; color: #fff;">AED <?php echo number_format( $data['gross_turnover'], 2 ); ?></strong>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" class="hero-stat-link">
                            <span style="font-size: 0.75em; color: #DCECC9; display: block;">Discounts Given</span>
                            <strong style="font-size: 1.05em; color: #fde68a;">AED <?php echo number_format( $data['total_discounts'], 2 ); ?></strong>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'period_sales' ) ) ) ); ?>" class="hero-stat-link">
                            <span style="font-size: 0.75em; color: #DCECC9; display: block;">Avg Plan Value</span>
                            <strong style="font-size: 1.05em; color: #fff;">AED <?php echo number_format( $data['avg_plan_value'], 2 ); ?></strong>
                        </a>
                    </div>
                </div>

                <!-- Live Global Subscription Health Strip (All Clickable to Drill-Down Screens) -->
                <div class="hero-health-strip">
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'active_plans' ) ) ) ); ?>" class="hero-stat-link">
                        <span style="font-size: 0.78em; color: #DCECC9; display: block;">Live Active Plans</span>
                        <strong style="font-size: 1.35em; color: #fff;"><?php echo number_format( $live_active_count ); ?> &rarr;</strong>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'paused_plans' ) ) ) ); ?>" class="hero-stat-link" style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 12px;">
                        <span style="font-size: 0.78em; color: #DCECC9; display: block;">Live Paused</span>
                        <strong style="font-size: 1.35em; color: #fde68a;"><?php echo number_format( $live_paused_count ); ?> &rarr;</strong>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'expiring_soon' ) ) ) ); ?>" class="hero-stat-link" style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 12px;">
                        <span style="font-size: 0.78em; color: #DCECC9; display: block;">Expiring (&le;3d)</span>
                        <strong style="font-size: 1.35em; color: #fed7aa;"><?php echo number_format( $expiring_soon_count ); ?> &rarr;</strong>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'unrenewed_expired' ) ) ) ); ?>" class="hero-stat-link" style="border-left: 1px solid rgba(255,255,255,0.2); padding-left: 12px;">
                        <span style="font-size: 0.78em; color: #DCECC9; display: block;">Unrenewed Expired</span>
                        <strong style="font-size: 1.35em; color: #fecaca;"><?php echo number_format( $unrenewed_total_count ); ?> &rarr;</strong>
                    </a>
                </div>
            </div>

            <!-- Card 2: 4-Portal Quick Launch (Strictly Required Portals) -->
            <div class="acru-card col-span-5">
                <div>
                    <div class="card-label">
                        <span>Quick Launch Portals</span>
                        <span style="color: #379237;">4 Workstations</span>
                    </div>
                    <div class="quick-launch-grid">
                        <a href="<?php echo esc_url( site_url( '/foh-admin-portal/' ) ); ?>" target="_blank" class="ql-btn">
                            <span>FOH Command</span>
                            <span>&nearr;</span>
                        </a>
                        <a href="<?php echo esc_url( site_url( '/kitchen-command-center/' ) ); ?>" target="_blank" class="ql-btn">
                            <span>Kitchen Report</span>
                            <span>&nearr;</span>
                        </a>
                        <a href="<?php echo esc_url( site_url( '/menu-manager-portal/' ) ); ?>" target="_blank" class="ql-btn">
                            <span>Menu Manager</span>
                            <span>&nearr;</span>
                        </a>
                        <a href="<?php echo esc_url( site_url( '/admin-tools/' ) ); ?>" target="_blank" class="ql-btn">
                            <span>Admin Tools</span>
                            <span>&nearr;</span>
                        </a>
                    </div>
                </div>
                <div class="card-subtext" style="margin-top: 15px;">Direct one-click access to core operational workstations.</div>
            </div>

            <!-- Card 3: Operational Pulse (Period Prep + Live Today Pulse) -->
            <div class="acru-card col-span-4">
                <div>
                    <div class="card-label">
                        <span>Operational Pulse</span>
                        <span style="color: #379237;"><?php echo $data['prep_percentage']; ?>% Prepped</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px;">
                        <span class="card-value"><?php echo number_format( $data['done_preps'] ); ?> <small style="font-size: 0.5em; color: #64748b;">/ <?php echo number_format( $data['total_preps'] ); ?></small></span>
                        <span style="font-weight: 700; color: #64748b; font-size: 0.85em;">Period Preps</span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar-fill" style="width: <?php echo $data['prep_percentage']; ?>%;"></div>
                    </div>
                    <div class="card-subtext">Period Dispatch Load: <strong><?php echo number_format( $data['dispatch_load'] ); ?> meals</strong></div>
                </div>

                <div style="margin-top: 14px; font-size: 0.86em; background: #EEF7E3; border: 1px solid #DCECC9; padding: 10px 12px; border-radius: 10px; color: #1B5E20;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span><strong>Today's Prep</strong> (For Tomorrow):</span>
                        <strong><?php echo number_format( $data['today_done_preps'] ); ?> / <?php echo number_format( $data['today_total_preps'] ); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span><strong>Today's Dispatch Load:</strong></span>
                        <strong><?php echo number_format( $data['today_dispatch'] ); ?> meals</strong>
                    </div>
                </div>
            </div>

            <!-- Card 4: Delivery Analytics & Status Breakdown -->
            <div class="acru-card col-span-4">
                <div>
                    <div class="card-label">
                        <span>Logistics &amp; Delivery Status</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'delivery_logs' ) ) ) ); ?>" style="color: #379237; text-decoration: underline;">View All &rarr;</a>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px;">
                        <span class="card-value"><?php echo $data['success_rate']; ?>%</span>
                        <span style="font-size: 0.85em; font-weight: 700; color: #379237;">Success Rate</span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar-fill" style="width: <?php echo $data['success_rate']; ?>%;"></div>
                    </div>
                </div>
                <div style="margin-top: 12px;">
                    <div class="metric-row">
                        <span style="font-size: 0.9em; color: #475569;">Successful (POS Verified)</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'delivery_logs', 'del_filter' => 'Successful' ) ) ) ); ?>" class="metric-badge-link" style="background:#dcfce7; color:#166534;">
                            <?php echo number_format( $data['successful_deliveries'] ); ?> &rarr;
                        </a>
                    </div>
                    <div class="metric-row">
                        <span style="font-size: 0.9em; color: #475569;">Returns / Cancelled</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'delivery_logs', 'del_filter' => 'Failed' ) ) ) ); ?>" class="metric-badge-link" style="background:#fee2e2; color:#dc2626;">
                            <?php echo number_format( $data['failed_deliveries'] ); ?> &rarr;
                        </a>
                    </div>
                    <div class="metric-row">
                        <span style="font-size: 0.9em; color: #475569;">Unlogged / Pending Status</span>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'pending_delivery' ) ) ) ); ?>" class="metric-badge-link" style="background:#fef3c7; color:#b45309;">
                            <?php echo number_format( $pending_delivery_count ); ?> &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Card 5: Reconciliation & Retargeting Action Queue -->
            <div class="acru-card col-span-4">
                <div>
                    <div class="card-label">
                        <span>Audit &amp; Retargeting Queue</span>
                        <span>Drill-Down</span>
                    </div>
                    <div style="margin-top: 8px;">
                        <div class="metric-row">
                            <span style="font-size: 0.9em; color: #475569;">Pending POS Checks</span>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'pending_pos' ) ) ) ); ?>" class="metric-badge-link" style="background: <?php echo ( $pending_pos_count > 0 ) ? '#fef3c7' : '#dcfce7'; ?>; color: <?php echo ( $pending_pos_count > 0 ) ? '#d97706' : '#166534'; ?>;">
                                <?php echo number_format( $pending_pos_count ); ?> &rarr;
                            </a>
                        </div>
                        <div class="metric-row">
                            <span style="font-size: 0.9em; color: #475569;">Pending Chef Assignments</span>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'pending_chef' ) ) ) ); ?>" class="metric-badge-link" style="background: <?php echo ( $pending_chef_count > 0 ) ? '#fee2e2' : '#dcfce7'; ?>; color: <?php echo ( $pending_chef_count > 0 ) ? '#dc2626' : '#166534'; ?>;">
                                <?php echo number_format( $pending_chef_count ); ?> &rarr;
                            </a>
                        </div>
                        <div class="metric-row">
                            <span style="font-size: 0.9em; color: #475569;">Plans Expiring Soon (&le;3 Days)</span>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'expiring_soon' ) ) ) ); ?>" class="metric-badge-link" style="background: <?php echo ( $expiring_soon_count > 0 ) ? '#ffedd5' : '#f1f5f9'; ?>; color: <?php echo ( $expiring_soon_count > 0 ) ? '#c2410c' : '#64748b'; ?>;">
                                <?php echo number_format( $expiring_soon_count ); ?> &rarr;
                            </a>
                        </div>
                        <div class="metric-row">
                            <span style="font-size: 0.9em; color: #475569;">Unrenewed Expired (Retarget)</span>
                            <a href="<?php echo esc_url( add_query_arg( array_merge( $base_filter_args, array( 'sa_view' => 'unrenewed_expired' ) ) ) ); ?>" class="metric-badge-link" style="background: #ede9fe; color: #6d28d9;">
                                <?php echo number_format( $unrenewed_total_count ); ?> &rarr;
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-subtext">Click any badge to open the detailed report screen.</div>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ==========================================
// 4. DEDICATED DRILL-DOWN SCREEN RENDERER
// ==========================================
function cmp_sa_render_drilldown_screen( $sa_view, $data, $global_start, $global_end ) {
    $back_url   = remove_query_arg( array( 'sa_view', 'del_filter' ) );
    $export_url = admin_url( 'admin-ajax.php?action=cmp_sa_export_drilldown&sa_view=' . urlencode( $sa_view ) . '&start_date=' . urlencode( $global_start ) . '&end_date=' . urlencode( $global_end ) );

    $titles = array(
        'pending_pos'       => array( 'Pending POS Reconciliations', 'Delivered or voided meals waiting for FOH POS verification', '#d97706' ),
        'completed_pos'     => array( 'Completed POS Reconciliations', 'Orders verified and reconciled in POS for the selected period', '#379237' ),
        'pending_delivery'  => array( 'Pending Delivery Status Logs', 'Locked meals (up to today) where delivery status is still Pending', '#dc2626' ),
        'delivery_logs'     => array( 'Delivery Status Breakdown', 'Complete log of Successful, Cancelled, Returned, and Pending deliveries', '#1B5E20' ),
        'unrenewed_expired' => array( 'Unrenewed Expired Customers (Retargeting Pool)', 'Customers whose past plans finished/expired and who have NOT subscribed to a new active plan', '#6d28d9' ),
        'expiring_soon'     => array( 'Renewal Radar: Plans Expiring Soon (<= 3 Balance Days)', 'Active customers with 1 to 3 days remaining — prime candidates for renewal outreach', '#c2410c' ),
        'active_plans'      => array( 'Live Active Subscriptions', 'All currently active customer meal plans across the system', '#379237' ),
        'paused_plans'      => array( 'Live Paused Subscriptions', 'All currently paused customer meal plans', '#f59e0b' ),
        'period_sales'      => array( 'Period Financial Turnover & New Plans Sold', 'All subscriptions acquired between ' . date( 'd M Y', strtotime( $global_start ) ) . ' and ' . date( 'd M Y', strtotime( $global_end ) ), '#1B5E20' ),
        'pending_chef'      => array( "Pending Chef's Choice Assignments", 'Active Chef Choice days waiting for meal selection', '#dc2626' ),
    );

    $meta     = isset( $titles[ $sa_view ] ) ? $titles[ $sa_view ] : $titles['unrenewed_expired'];
    $del_filt = isset( $_GET['del_filter'] ) ? sanitize_text_field( $_GET['del_filter'] ) : 'all';

    ob_start();
    ?>
    <style>
        .sa-drill-wrap, .sa-drill-wrap * { box-sizing: border-box; }
        .sa-drill-wrap {
            max-width: 1350px;
            margin: 0 auto 40px auto;
            padding: 24px;
            background: linear-gradient(145deg, rgba(244, 248, 242, 0.94) 0%, rgba(238, 247, 227, 0.65) 100%);
            border-radius: 22px;
            border: 1px solid #DCECC9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #231F20;
        }
        .sa-drill-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }
        .sa-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ffffff;
            color: #1B5E20 !important;
            border: 1.5px solid #379237;
            padding: 9px 16px;
            border-radius: 10px;
            font-weight: 800;
            text-decoration: none !important;
            font-size: 0.92em;
            transition: 0.2s;
        }
        .sa-back-btn:hover { background: #379237; color: #ffffff !important; }
        .sa-drill-header {
            background: #ffffff;
            border-radius: 16px;
            padding: 22px 26px;
            border: 1px solid #DCECC9;
            border-left: 6px solid <?php echo esc_attr( $meta[2] ); ?>;
            box-shadow: 0 6px 18px rgba(27, 94, 32, 0.05);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .sa-drill-header h2 { margin: 0; font-size: 1.55em; color: #231F20; font-weight: 800; }
        .sa-drill-header p { margin: 5px 0 0 0; color: #64748b; font-size: 0.95em; }
        .sa-drill-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
            background: #ffffff;
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid #DCECC9;
        }
        .sa-drill-search {
            flex: 1;
            min-width: 240px;
            padding: 10px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95em;
            outline: none;
        }
        .sa-drill-search:focus { border-color: #379237; }
        .sa-drill-table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.05);
            font-size: 0.92em;
        }
        .sa-drill-table th {
            background: #EEF7E3;
            color: #1B5E20;
            padding: 13px 12px;
            text-align: left;
            border-bottom: 2px solid #DCECC9;
            font-weight: 800;
        }
        .sa-drill-table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .sa-drill-table tr:hover td { background: #f8fcf5; }
        .sa-pill { padding: 4px 10px; border-radius: 999px; font-weight: 800; font-size: 0.82em; display: inline-block; white-space: nowrap; }
    </style>

    <div class="sa-drill-wrap">
        <div class="sa-drill-top">
            <a href="<?php echo esc_url( $back_url ); ?>" class="sa-back-btn">&larr; Back to Executive Command Center</a>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="<?php echo esc_url( $export_url ); ?>" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color:#fff; text-decoration:none; padding:9px 18px; border-radius:10px; font-weight:700; font-size:0.9em;">Export Table to CSV</a>
                <button onclick="window.print()" style="background:#475569; color:#fff; border:none; padding:9px 16px; border-radius:10px; font-weight:700; font-size:0.9em; cursor:pointer;">Print / PDF</button>
            </div>
        </div>

        <div class="sa-drill-header">
            <div>
                <h2><?php echo esc_html( $meta[0] ); ?></h2>
                <p><?php echo esc_html( $meta[1] ); ?></p>
            </div>
            <!-- Switch Between Drill-Down Views Quickly -->
            <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="sa_view" value="<?php echo esc_attr( $sa_view ); ?>">
                <input type="date" name="start_date" value="<?php echo esc_attr( $global_start ); ?>" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                <span style="color:#64748b; font-weight:700;">to</span>
                <input type="date" name="end_date" value="<?php echo esc_attr( $global_end ); ?>" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                <button type="submit" style="background:#379237; color:#fff; border:none; padding:8px 15px; border-radius:8px; font-weight:700; cursor:pointer;">Update Period</button>
            </form>
        </div>

        <div class="sa-drill-toolbar">
            <input type="text" id="saDrillSearch" class="sa-drill-search" placeholder="Search by Order ID, Customer Name, Email, Phone, or Plan...">
            <?php if ( $sa_view === 'delivery_logs' ) : ?>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <a href="<?php echo esc_url( add_query_arg( 'del_filter', 'all' ) ); ?>" class="sa-pill" style="text-decoration:none; background:<?php echo $del_filt === 'all' ? '#1B5E20' : '#f1f5f9'; ?>; color:<?php echo $del_filt === 'all' ? '#fff' : '#475569'; ?>;">All Logs</a>
                    <a href="<?php echo esc_url( add_query_arg( 'del_filter', 'Successful' ) ); ?>" class="sa-pill" style="text-decoration:none; background:<?php echo $del_filt === 'Successful' ? '#166534' : '#dcfce7'; ?>; color:<?php echo $del_filt === 'Successful' ? '#fff' : '#166534'; ?>;">Successful</a>
                    <a href="<?php echo esc_url( add_query_arg( 'del_filter', 'Failed' ) ); ?>" class="sa-pill" style="text-decoration:none; background:<?php echo $del_filt === 'Failed' ? '#dc2626' : '#fee2e2'; ?>; color:<?php echo $del_filt === 'Failed' ? '#fff' : '#dc2626'; ?>;">Cancelled / Returned</a>
                    <a href="<?php echo esc_url( add_query_arg( 'del_filter', 'Pending' ) ); ?>" class="sa-pill" style="text-decoration:none; background:<?php echo $del_filt === 'Pending' ? '#d97706' : '#fef3c7'; ?>; color:<?php echo $del_filt === 'Pending' ? '#fff' : '#92400e'; ?>;">Pending</a>
                </div>
            <?php endif; ?>
        </div>

        <div style="overflow-x:auto;">
            <?php if ( $sa_view === 'unrenewed_expired' ) : ?>
                <!-- TABLE A: UNRENEWED EXPIRED CUSTOMERS (RETARGETING LIST) -->
                <table class="sa-drill-table" id="saDrillTable">
                    <thead>
                        <tr>
                            <th>Last Order</th>
                            <th>Customer Details</th>
                            <th>Last Plan Subscribed</th>
                            <th>Consumption</th>
                            <th>Last Plan Paid</th>
                            <th>Completed / Expired Date</th>
                            <th style="text-align:center;">Retarget Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $data['unrenewed_customers'] ) ) : ?>
                            <tr><td colspan="7" style="text-align:center; padding:30px; color:#64748b;">No unrenewed expired customers found. All past subscribers have active renewals!</td></tr>
                        <?php else : foreach ( $data['unrenewed_customers'] as $c ) : 
                            $clean_phone = preg_replace( '/[^0-9]/', '', $c['phone'] );
                            if ( substr( $clean_phone, 0, 1 ) === '0' ) {
                                $clean_phone = '971' . substr( $clean_phone, 1 );
                            }
                            $wa_msg = urlencode( "Hi " . $c['name'] . ", we noticed your meal plan (" . $c['plan_name'] . ") with The Cycle Bistro has finished. Would you like to renew your plan for the upcoming week?" );
                        ?>
                            <tr class="sa-drill-row">
                                <td><strong style="color:#231F20;"><?php echo esc_html( $c['order_id'] ); ?></strong></td>
                                <td>
                                    <strong style="color:#379237; font-size:1.05em;"><?php echo esc_html( $c['name'] ); ?></strong><br>
                                    <span style="color:#64748b; font-size:0.9em;"><?php echo esc_html( $c['email'] ); ?></span><br>
                                    <span style="color:#334155; font-size:0.9em; font-weight:600;"><?php echo esc_html( $c['phone'] ); ?></span>
                                </td>
                                <td><strong><?php echo esc_html( $c['plan_name'] ); ?></strong></td>
                                <td>
                                    <span class="sa-pill" style="background:#f1f5f9; color:#334155;">
                                        <?php echo intval( $c['used_days'] ) . ' / ' . intval( $c['total_days'] ); ?> Days Used
                                    </span>
                                </td>
                                <td><strong>AED <?php echo number_format( $c['paid'], 2 ); ?></strong></td>
                                <td>
                                    <span class="sa-pill" style="background:#ede9fe; color:#6d28d9;">
                                        <?php echo esc_html( date( 'd M Y', strtotime( $c['expiry_date'] ) ) ); ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                                        <?php if ( ! empty( $clean_phone ) ) : ?>
                                            <a href="https://wa.me/<?php echo esc_attr( $clean_phone ); ?>?text=<?php echo $wa_msg; ?>" target="_blank" style="background:#25d366; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.85em;">WhatsApp</a>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( site_url( '/my-meal-portal/?admin_edit_sub=' . $c['sub_id'] ) ); ?>" target="_blank" style="background:#379237; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.85em;">View Record &nearr;</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

            <?php elseif ( in_array( $sa_view, array( 'active_plans', 'paused_plans', 'expiring_soon', 'period_sales' ), true ) ) : 
                $list_key = 'live_active_list';
                if ( $sa_view === 'paused_plans' )  $list_key = 'live_paused_list';
                if ( $sa_view === 'expiring_soon' ) $list_key = 'expiring_soon_list';
                if ( $sa_view === 'period_sales' )  $list_key = 'period_sales_list';
                $rows = $data[ $list_key ];
            ?>
                <!-- TABLE B: SUBSCRIPTIONS & FINANCIALS DRILL-DOWN -->
                <table class="sa-drill-table" id="saDrillTable">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer Info</th>
                            <th>Plan Name</th>
                            <th>Status</th>
                            <th>Progress &amp; Balance</th>
                            <th>Financials (AED)</th>
                            <th>Dates</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="8" style="text-align:center; padding:30px; color:#64748b;">No subscription records found in this category.</td></tr>
                        <?php else : foreach ( $rows as $s ) : ?>
                            <tr class="sa-drill-row">
                                <td><strong><?php echo esc_html( $s['order_id'] ); ?></strong></td>
                                <td>
                                    <strong style="color:#379237;"><?php echo esc_html( $s['name'] ); ?></strong><br>
                                    <span style="color:#64748b; font-size:0.88em;"><?php echo esc_html( $s['email'] ); ?></span><br>
                                    <span style="color:#334155; font-size:0.88em;"><?php echo esc_html( $s['phone'] ); ?></span>
                                </td>
                                <td><strong><?php echo esc_html( $s['plan_name'] ); ?></strong></td>
                                <td>
                                    <span class="sa-pill" style="background:<?php echo $s['status_key'] === 'active' ? '#dcfce7' : ( $s['status_key'] === 'paused' ? '#fef3c7' : '#fee2e2' ); ?>; color:<?php echo $s['status_key'] === 'active' ? '#166534' : ( $s['status_key'] === 'paused' ? '#92400e' : '#991b1b' ); ?>;">
                                        <?php echo esc_html( $s['status_label'] ); ?>
                                    </span>
                                </td>
                                <td>
                                    Used: <strong><?php echo intval( $s['used_days'] ); ?> / <?php echo intval( $s['total_days'] ); ?></strong><br>
                                    <span style="color:<?php echo $s['balance'] <= 3 ? '#dc2626' : '#1B5E20'; ?>; font-weight:800;">Balance: <?php echo intval( $s['balance'] ); ?> Days</span>
                                </td>
                                <td>
                                    Net: <strong style="color:#1B5E20;">AED <?php echo number_format( $s['paid'], 2 ); ?></strong><br>
                                    <?php if ( $s['discount'] > 0 ) : ?>
                                        <span style="font-size:0.82em; color:#dc2626;">Disc: -AED <?php echo number_format( $s['discount'], 2 ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.88em; color:#475569;">
                                    Start: <?php echo esc_html( date( 'd M Y', strtotime( $s['start_date'] ) ) ); ?><br>
                                    Exp: <?php echo esc_html( date( 'd M Y', strtotime( $s['expiry_date'] ) ) ); ?>
                                </td>
                                <td style="text-align:center;">
                                    <a href="<?php echo esc_url( site_url( '/my-meal-portal/?admin_edit_sub=' . $s['sub_id'] ) ); ?>" target="_blank" style="background:#379237; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.85em; display:inline-block;">Open Plan &nearr;</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

            <?php else : 
                $list_key = 'pending_pos_list';
                if ( $sa_view === 'completed_pos' )    $list_key = 'completed_pos_list';
                if ( $sa_view === 'pending_delivery' ) $list_key = 'pending_delivery_list';
                if ( $sa_view === 'delivery_logs' )    $list_key = 'period_delivery_list';
                if ( $sa_view === 'pending_chef' )     $list_key = 'pending_chef_list';
                $rows = $data[ $list_key ];

                if ( $sa_view === 'delivery_logs' && $del_filt !== 'all' ) {
                    $rows = array_filter( $rows, function( $r ) use ( $del_filt ) {
                        if ( $del_filt === 'Failed' ) {
                            return in_array( $r['delivery_result'], array( 'Cancelled', 'Returned' ), true );
                        }
                        return $r['delivery_result'] === $del_filt;
                    } );
                }
            ?>
                <!-- TABLE C: DAILY OPERATIONAL, DELIVERY & POS RECONCILIATION LOGS -->
                <table class="sa-drill-table" id="saDrillTable">
                    <thead>
                        <tr>
                            <th>Eating Date</th>
                            <th>Delivery Date</th>
                            <th>Order ID</th>
                            <th>Customer Details</th>
                            <th>Plan</th>
                            <th style="text-align:center;">Prep / Dispatch</th>
                            <th style="text-align:center;">Delivery Status</th>
                            <th style="text-align:center;">POS Check</th>
                            <th style="text-align:center;">Resolve</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="9" style="text-align:center; padding:30px; color:#166534; font-weight:700;">No pending or matching records found for this filter!</td></tr>
                        <?php else : foreach ( $rows as $l ) : 
                            $prep_date_param = date( 'Y-m-d', strtotime( $l['target_date'] . ' -1 day' ) );
                            $del_bg = '#fef3c7'; $del_col = '#92400e';
                            if ( $l['delivery_result'] === 'Successful' ) { $del_bg = '#dcfce7'; $del_col = '#166534'; }
                            elseif ( in_array( $l['delivery_result'], array( 'Cancelled', 'Returned' ), true ) ) { $del_bg = '#fee2e2'; $del_col = '#991b1b'; }
                        ?>
                            <tr class="sa-drill-row">
                                <td>
                                    <strong><?php echo esc_html( date( 'd M Y', strtotime( $l['target_date'] ) ) ); ?></strong><br>
                                    <span style="font-size:0.82em; color:#64748b;">(<?php echo esc_html( $l['timing_label'] ); ?>)</span>
                                </td>
                                <td style="font-weight:700; color:#1B5E20;"><?php echo esc_html( date( 'd M Y', strtotime( $l['delivery_date'] ) ) ); ?></td>
                                <td><strong><?php echo esc_html( $l['order_id'] ); ?></strong></td>
                                <td>
                                    <strong style="color:#379237;"><?php echo esc_html( $l['name'] ); ?></strong><br>
                                    <span style="color:#64748b; font-size:0.88em;"><?php echo esc_html( $l['phone'] ); ?></span>
                                </td>
                                <td><?php echo esc_html( $l['plan_name'] ); ?></td>
                                <td style="text-align:center;">
                                    <span class="sa-pill" style="background:<?php echo $l['is_prepared'] ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo $l['is_prepared'] ? '#166534' : '#991b1b'; ?>; margin-right:4px;">
                                        Prep: <?php echo $l['is_prepared'] ? 'Yes' : 'No'; ?>
                                    </span>
                                    <span class="sa-pill" style="background:<?php echo $l['dispatch_status'] ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo $l['dispatch_status'] ? '#166534' : '#991b1b'; ?>;">
                                        Disp: <?php echo $l['dispatch_status'] ? 'Yes' : 'No'; ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="sa-pill" style="background:<?php echo $del_bg; ?>; color:<?php echo $del_col; ?>;">
                                        <?php echo esc_html( $l['delivery_result'] ); ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="sa-pill" style="background:<?php echo $l['pos_updated'] ? '#dcfce7' : '#fef3c7'; ?>; color:<?php echo $l['pos_updated'] ? '#166534' : '#d97706'; ?>;">
                                        <?php echo $l['pos_updated'] ? 'Verified' : 'Pending'; ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ( $sa_view === 'pending_chef' ) : ?>
                                        <a href="<?php echo esc_url( site_url( '/my-meal-portal/?chef_override_sub=' . $l['sub_id'] ) ); ?>" target="_blank" style="background:#379237; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.84em; display:inline-block;">Assign Meals &nearr;</a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( site_url( '/kitchen-command-center/?prep_date=' . $prep_date_param ) ); ?>" target="_blank" style="background:#1B5E20; color:#fff; text-decoration:none; padding:6px 12px; border-radius:6px; font-weight:700; font-size:0.84em; display:inline-block;">Open Prep Date &nearr;</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const searchInput = document.getElementById("saDrillSearch");
        if (searchInput) {
            searchInput.addEventListener("keyup", function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll("#saDrillTable tbody .sa-drill-row").forEach(function(row) {
                    row.style.display = row.innerText.toLowerCase().indexOf(q) > -1 ? "" : "none";
                });
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
