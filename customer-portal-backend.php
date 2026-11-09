<?php
/**
 * Customer Portal - Backend Engine, AJAX Handlers, CSV Exporters & Feedback Admin
 * Part 1 of the 3-file Customer Portal modular architecture.
 *
 * Upgrades Included:
 *  - Allow Meal Skipping & Automatic Carryover (Redemption) Days Engine:
 *      * Self-healing columns: `allow_meal_skip` & `carryover_days` on `wp_cmp_subscriptions`,
 *        and `is_carryover`, `carryover_from_log_id`, `carryover_meals_quota`, `carryover_snacks_quota` on `wp_cmp_daily_logs`
 *      * Supports 2-Meal and 3-Meal plans, snack redemption enforcement on the final redemption day,
 *        and multi-hop chained meal skipping on Redemption Days when `Allow Meal Skipping` is enabled
 *      * Staff-only `Allow Meal Skipping` toggle for FOH, Menu Manager, and Admin inside Account Details
 *      * Centralized Tracking Metrics & Balance Total Meals calculator (`cmp_get_subscription_tracking_metrics`)
 *  - Option PREP-C: Central `prep_timing` schema column, 4:00 PM+ Same-Day Prep auto-detection, and Staff-Only Prep Schedule override
 *  - Option 2B: Row-Level Staff Audit Stamps (`cmp_format_row_audit_stamp`)
 *  - Option 3A: Admin-Only Cancellation Reset & Extra Day Reversal (`cmp_ajax_clear_daily_log`)
 *  - Option 4A: POS Check Exemption (`No POS Required`) for Cancelled & Returned days in Staff CSV Exports
 *  - Option 1A & 5A: Full synchronization with `Customer Logs` and `Kitchen Logs`
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Prevent duplicate loading
if ( defined( 'CMP_CUSTOMER_PORTAL_BACKEND_LOADED' ) ) {
    return;
}
define( 'CMP_CUSTOMER_PORTAL_BACKEND_LOADED', true );

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// 0. ROLE, QUOTA, DATE LOCK, CARRYOVER, SAME-DAY PREP, AUDIT & DB HELPER FUNCTIONS
//    (CONNECTED TO WP ADMIN -> MEAL SETTINGS)
// ==========================================

/**
 * Checks if the current logged-in user is staff (FOH, Kitchen, Menu Manager, or Admin)
 * who is permitted to see the Option 2B micro-footer audit stamp on meal cards.
 */
if ( ! function_exists( 'cmp_can_view_staff_audit_log' ) ) {
    function cmp_can_view_staff_audit_log() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        return current_user_can( 'manage_options' )
            || current_user_can( 'foh_manager' )
            || current_user_can( 'kitchen_staff' )
            || current_user_can( 'menu_manager' );
    }
}

/**
 * Checks if the current logged-in user is FOH, Menu Manager, or Admin
 * (Authorized to edit Account Details and toggle "Allow Meal Skipping" in Customer Portal & FOH Portal).
 */
if ( ! function_exists( 'cmp_can_manage_meal_skipping' ) ) {
    function cmp_can_manage_meal_skipping() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        return current_user_can( 'manage_options' )
            || current_user_can( 'foh_manager' )
            || current_user_can( 'menu_manager' );
    }
}

/**
 * Formats the Option 2B micro-footer audit stamp for a daily meal log row.
 */
if ( ! function_exists( 'cmp_format_row_audit_stamp' ) ) {
    function cmp_format_row_audit_stamp( $action_type, $actor_name, $actor_role, $updated_at ) {
        if ( empty( $actor_name ) || empty( $updated_at ) || $updated_at === '0000-00-00 00:00:00' ) {
            return '';
        }
        $action_label = ! empty( $action_type ) ? $action_type : 'Updated';
        $role_part    = ! empty( $actor_role ) ? ' (' . $actor_role . ')' : '';
        $time_part    = date( 'd M Y, h:i A', strtotime( $updated_at ) );

        return sprintf( '%s by %s%s • %s', $action_label, $actor_name, $role_part, $time_part );
    }
}

/**
 * Ensures the `prep_timing` column exists on `wp_cmp_subscriptions` ('auto', 'same_day', 'day_before').
 */
if ( ! function_exists( 'cmp_ensure_prep_timing_column' ) ) {
    function cmp_ensure_prep_timing_column() {
        global $wpdb;
        static $checked = false;
        if ( $checked ) {
            return;
        }
        $checked      = true;
        $table_subs   = $wpdb->prefix . 'cmp_subscriptions';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_subs ) );
        if ( $table_exists !== $table_subs ) {
            return;
        }
        $col_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_subs} LIKE 'prep_timing'" );
        if ( empty( $col_check ) ) {
            $wpdb->query( "ALTER TABLE {$table_subs} ADD COLUMN prep_timing VARCHAR(30) DEFAULT 'auto'" );
        }
    }
}

/**
 * Ensures the Meal Skipping & Carryover Redemption columns exist on `wp_cmp_subscriptions` and `wp_cmp_daily_logs`.
 */
add_action( 'init', 'cmp_ensure_meal_skip_carryover_columns', 6 );
if ( ! function_exists( 'cmp_ensure_meal_skip_carryover_columns' ) ) {
    function cmp_ensure_meal_skip_carryover_columns() {
        global $wpdb;
        static $checked = false;
        if ( $checked ) {
            return;
        }
        $checked = true;

        $table_subs = $wpdb->prefix . 'cmp_subscriptions';
        $table_logs = $wpdb->prefix . 'cmp_daily_logs';

        $subs_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_subs ) );
        if ( $subs_exists === $table_subs ) {
            $sub_cols = $wpdb->get_col( "DESC {$table_subs}", 0 );
            if ( is_array( $sub_cols ) ) {
                if ( ! in_array( 'allow_meal_skip', $sub_cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$table_subs} ADD COLUMN allow_meal_skip TINYINT(1) NOT NULL DEFAULT 0" );
                }
                if ( ! in_array( 'carryover_days', $sub_cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$table_subs} ADD COLUMN carryover_days INT(11) NOT NULL DEFAULT 0" );
                }
            }
        }

        $logs_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) );
        if ( $logs_exists === $table_logs ) {
            $log_cols = $wpdb->get_col( "DESC {$table_logs}", 0 );
            if ( is_array( $log_cols ) ) {
                if ( ! in_array( 'is_carryover', $log_cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$table_logs} ADD COLUMN is_carryover TINYINT(1) NOT NULL DEFAULT 0" );
                }
                if ( ! in_array( 'carryover_from_log_id', $log_cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$table_logs} ADD COLUMN carryover_from_log_id BIGINT(20) NOT NULL DEFAULT 0" );
                }
                if ( ! in_array( 'carryover_meals_quota', $log_cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$table_logs} ADD COLUMN carryover_meals_quota TINYINT(2) NOT NULL DEFAULT 0" );
                }
                if ( ! in_array( 'carryover_snacks_quota', $log_cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$table_logs} ADD COLUMN carryover_snacks_quota TINYINT(2) NOT NULL DEFAULT 0" );
                }
            }
        }
    }
}

/**
 * Resolves the base daily main meal quota and snack quota for a subscription.
 */
if ( ! function_exists( 'cmp_get_subscription_base_quotas' ) ) {
    function cmp_get_subscription_base_quotas( $sub ) {
        if ( ! is_object( $sub ) ) {
            return array(
                'is_juice'      => false,
                'allowed_meals' => 1,
                'snack_quota'   => 1,
            );
        }

        $allowed_cats = isset( $sub->allowed_categories ) ? (string) $sub->allowed_categories : '';
        $plan_name    = isset( $sub->plan_name ) ? (string) $sub->plan_name : '';

        $is_juice = ( stripos( $allowed_cats, 'Juices' ) !== false || stripos( $plan_name, 'juice' ) !== false || stripos( $plan_name, 'cleanse' ) !== false );

        if ( $is_juice ) {
            return array(
                'is_juice'      => true,
                'allowed_meals' => 3,
                'snack_quota'   => 0,
            );
        }

        $allowed_quota = 0;
        if ( preg_match( '/(\d+)\s*Meal/i', $plan_name, $m ) ) {
            $allowed_quota = intval( $m[1] );
        } else {
            if ( preg_match( '/\b(one|1)\b/i', $plan_name ) )       $allowed_quota = 1;
            elseif ( preg_match( '/\b(two|2)\b/i', $plan_name ) )   $allowed_quota = 2;
            elseif ( preg_match( '/\b(three|3)\b/i', $plan_name ) ) $allowed_quota = 3;
            else {
                $cats = explode( ',', $allowed_cats );
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

        return array(
            'is_juice'      => false,
            'allowed_meals' => $allowed_quota,
            'snack_quota'   => $snack_quota,
        );
    }
}

/**
 * Counts how many main meals (or juices) and snacks are selected on a daily log row.
 * Note: If Chef's Choice is checked and no specific meals are assigned yet, it fulfills the full row allocation.
 */
if ( ! function_exists( 'cmp_count_log_selected_meals_and_snacks' ) ) {
    function cmp_count_log_selected_meals_and_snacks( $log, $is_juice = false, $alloc_meals = 1, $alloc_snacks = 1 ) {
        if ( ! is_object( $log ) ) {
            return array( 'meals' => 0, 'snacks' => 0 );
        }

        if ( $is_juice ) {
            $j_count = 0;
            if ( ! empty( $log->juice_1_id ) || ! empty( trim( (string) ( $log->custom_juice_1 ?? '' ) ) ) ) $j_count++;
            if ( ! empty( $log->juice_2_id ) || ! empty( trim( (string) ( $log->custom_juice_2 ?? '' ) ) ) ) $j_count++;
            if ( ! empty( $log->juice_3_id ) || ! empty( trim( (string) ( $log->custom_juice_3 ?? '' ) ) ) ) $j_count++;
            if ( ! empty( $log->is_chefs_choice ) && $j_count === 0 ) {
                $j_count = $alloc_meals;
            }
            return array( 'meals' => $j_count, 'snacks' => 0 );
        }

        $m_count = 0;
        if ( ! empty( $log->breakfast_id ) || ! empty( trim( (string) ( $log->custom_breakfast ?? '' ) ) ) ) $m_count++;
        if ( ! empty( $log->lunch_id )     || ! empty( trim( (string) ( $log->custom_lunch ?? '' ) ) ) )     $m_count++;
        if ( ! empty( $log->dinner_id )    || ! empty( trim( (string) ( $log->custom_dinner ?? '' ) ) ) )    $m_count++;

        $s_count = 0;
        if ( ! empty( $log->snack_1_id ) || ! empty( trim( (string) ( $log->custom_snack_1 ?? '' ) ) ) ) $s_count++;
        if ( ! empty( $log->snack_2_id ) || ! empty( trim( (string) ( $log->custom_snack_2 ?? '' ) ) ) ) $s_count++;

        if ( ! empty( $log->is_chefs_choice ) && $m_count === 0 ) {
            $m_count = $alloc_meals;
            $s_count = $alloc_snacks;
        }

        return array(
            'meals'  => $m_count,
            'snacks' => $s_count,
        );
    }
}

/**
 * Builds the complete ordered schedule of Regular Slots and Carryover / Redemption Slots for a subscription.
 * Supports single-hop skips, multiple skips across different days, chained skips on Redemption Days,
 * and cancelled/returned replacement slots.
 */
if ( ! function_exists( 'cmp_build_subscription_schedule_slots' ) ) {
    function cmp_build_subscription_schedule_slots( $sub ) {
        global $wpdb;
        cmp_ensure_meal_skip_carryover_columns();

        if ( is_numeric( $sub ) ) {
            $table_subs = $wpdb->prefix . 'cmp_subscriptions';
            $sub        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", intval( $sub ) ) );
        }

        if ( ! is_object( $sub ) ) {
            return array(
                'regular_slots'      => array(),
                'carryover_slots'    => array(),
                'all_slots'          => array(),
                'regular_void_count' => 0,
                'active_carryovers'  => 0,
                'base_quotas'        => array( 'is_juice' => false, 'allowed_meals' => 1, 'snack_quota' => 1 ),
            );
        }

        $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
        $base_quotas = cmp_get_subscription_base_quotas( $sub );
        $is_juice    = $base_quotas['is_juice'];
        $base_meals  = $base_quotas['allowed_meals'];
        $base_snacks = $base_quotas['snack_quota'];

        $all_logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_logs} WHERE subscription_id = %d ORDER BY is_carryover ASC, target_date ASC, id ASC",
            intval( $sub->id )
        ) );

        $regular_logs             = array();
        $carryover_logs_by_parent = array();
        $all_carryover_logs       = array();

        if ( $all_logs ) {
            foreach ( $all_logs as $l ) {
                if ( ! empty( $l->is_carryover ) && intval( $l->is_carryover ) === 1 ) {
                    $p_id = intval( $l->carryover_from_log_id );
                    if ( ! isset( $carryover_logs_by_parent[ $p_id ] ) ) {
                        $carryover_logs_by_parent[ $p_id ] = array();
                    }
                    $carryover_logs_by_parent[ $p_id ][]    = $l;
                    $all_carryover_logs[ intval( $l->id ) ] = $l;
                } else {
                    $regular_logs[] = $l;
                }
            }
        }

        $regular_void_count = 0;
        foreach ( $regular_logs as $rlog ) {
            if ( in_array( $rlog->delivery_result, array( 'Cancelled', 'Returned' ), true ) ) {
                $regular_void_count++;
            }
        }

        $regular_display_days = intval( $sub->total_days ) + $regular_void_count;
        $regular_slots        = array();
        $carryover_queue      = array();

        for ( $i = 1; $i <= $regular_display_days; $i++ ) {
            $rlog    = isset( $regular_logs[ $i - 1 ] ) ? $regular_logs[ $i - 1 ] : null;
            $is_void = ( $rlog && in_array( $rlog->delivery_result, array( 'Cancelled', 'Returned' ), true ) );

            $regular_slots[] = array(
                'day_num'              => $i,
                'is_carryover'         => false,
                'log'                  => $rlog,
                'alloc_meals'          => $base_meals,
                'alloc_snacks'         => $base_snacks,
                'parent_log_id'        => 0,
                'source_day_num'       => 0,
                'source_date'          => '',
                'origin_day_num'       => 0,
                'origin_date'          => '',
                'tooltip_text'         => '',
                'allocation_badge_txt' => '',
            );

            if ( $rlog && ! $is_void && ! $is_juice && $base_meals > 1 ) {
                $counts = cmp_count_log_selected_meals_and_snacks( $rlog, false, $base_meals, $base_snacks );
                if ( $counts['meals'] > 0 && $counts['meals'] < $base_meals ) {
                    $skipped_meals     = $base_meals - $counts['meals'];
                    $skipped_snacks    = max( 0, $base_snacks - $counts['snacks'] );
                    $carryover_queue[] = array(
                        'parent_log_id'  => intval( $rlog->id ),
                        'alloc_meals'    => $skipped_meals,
                        'alloc_snacks'   => $skipped_snacks,
                        'source_day_num' => $i,
                        'source_date'    => $rlog->target_date,
                        'origin_day_num' => $i,
                        'origin_date'    => $rlog->target_date,
                        'hop'            => 1,
                    );
                }
            }
        }

        $carryover_slots        = array();
        $consumed_carryover_ids = array();
        $active_carryover_count = 0;
        $next_day_num           = $regular_display_days + 1;
        $q_idx                  = 0;

        while ( $q_idx < count( $carryover_queue ) ) {
            $item      = $carryover_queue[ $q_idx++ ];
            $parent_id = intval( $item['parent_log_id'] );
            $children  = $carryover_logs_by_parent[ $parent_id ] ?? array();

            $meal_word   = ( $item['alloc_meals'] === 1 ) ? '1 Meal' : $item['alloc_meals'] . ' Meals';
            $snack_word  = ( $item['alloc_snacks'] > 0 )
                ? ( $item['alloc_snacks'] === 1 ? '1 Snack' : $item['alloc_snacks'] . ' Snacks' )
                : '0 Snacks';
            $alloc_badge = $meal_word . ' • ' . $snack_word;

            $src_date_fmt = ( ! empty( $item['source_date'] ) && $item['source_date'] !== '0000-00-00' )
                ? date( 'D, d M Y', strtotime( $item['source_date'] ) )
                : 'Scheduled Day';

            if ( $item['hop'] > 1 ) {
                $orig_date_fmt = ( ! empty( $item['origin_date'] ) && $item['origin_date'] !== '0000-00-00' )
                    ? date( 'D, d M Y', strtotime( $item['origin_date'] ) )
                    : '';
                $tooltip_text = sprintf(
                    'Carryover from Day %d (%s) [Originally skipped on Day %d%s] — Redeem %s & %s.',
                    intval( $item['source_day_num'] ),
                    $src_date_fmt,
                    intval( $item['origin_day_num'] ),
                    $orig_date_fmt ? ', ' . $orig_date_fmt : '',
                    $meal_word,
                    $snack_word
                );
            } else {
                $tooltip_text = sprintf(
                    'Carryover from Day %d (%s) — Redeem %s & %s skipped from that day.',
                    intval( $item['source_day_num'] ),
                    $src_date_fmt,
                    $meal_word,
                    $snack_word
                );
            }

            $void_children   = array();
            $active_children = array();

            foreach ( $children as $ch ) {
                $consumed_carryover_ids[ intval( $ch->id ) ] = true;
                if ( in_array( $ch->delivery_result, array( 'Cancelled', 'Returned' ), true ) ) {
                    $void_children[] = $ch;
                } else {
                    $active_children[] = $ch;
                }
            }

            // 1. Render any Cancelled / Returned attempts for this Carryover slot first
            foreach ( $void_children as $v_ch ) {
                $carryover_slots[] = array(
                    'day_num'              => $next_day_num++,
                    'is_carryover'         => true,
                    'log'                  => $v_ch,
                    'alloc_meals'          => $item['alloc_meals'],
                    'alloc_snacks'         => $item['alloc_snacks'],
                    'parent_log_id'        => $parent_id,
                    'source_day_num'       => $item['source_day_num'],
                    'source_date'          => $item['source_date'],
                    'origin_day_num'       => $item['origin_day_num'],
                    'origin_date'          => $item['origin_date'],
                    'tooltip_text'         => $tooltip_text,
                    'allocation_badge_txt' => $alloc_badge,
                );
            }

            // 2. Render the active Carryover Redemption slot (either paired with $active_child or pending null)
            $active_child = ! empty( $active_children ) ? $active_children[0] : null;
            $this_day_num = $next_day_num++;
            $active_carryover_count++;

            $carryover_slots[] = array(
                'day_num'              => $this_day_num,
                'is_carryover'         => true,
                'log'                  => $active_child,
                'alloc_meals'          => $item['alloc_meals'],
                'alloc_snacks'         => $item['alloc_snacks'],
                'parent_log_id'        => $parent_id,
                'source_day_num'       => $item['source_day_num'],
                'source_date'          => $item['source_date'],
                'origin_day_num'       => $item['origin_day_num'],
                'origin_date'          => $item['origin_date'],
                'tooltip_text'         => $tooltip_text,
                'allocation_badge_txt' => $alloc_badge,
            );

            // 3. Check if $active_child itself skipped a meal (Chained Skipping on a 2+ Meal Carryover Day)
            if ( $active_child && intval( $item['alloc_meals'] ) > 1 ) {
                $ch_counts = cmp_count_log_selected_meals_and_snacks( $active_child, false, $item['alloc_meals'], $item['alloc_snacks'] );
                if ( $ch_counts['meals'] > 0 && $ch_counts['meals'] < intval( $item['alloc_meals'] ) ) {
                    $rem_meals         = intval( $item['alloc_meals'] ) - $ch_counts['meals'];
                    $rem_snacks        = max( 0, intval( $item['alloc_snacks'] ) - $ch_counts['snacks'] );
                    $carryover_queue[] = array(
                        'parent_log_id'  => intval( $active_child->id ),
                        'alloc_meals'    => $rem_meals,
                        'alloc_snacks'   => $rem_snacks,
                        'source_day_num' => $this_day_num,
                        'source_date'    => $active_child->target_date,
                        'origin_day_num' => $item['origin_day_num'],
                        'origin_date'    => $item['origin_date'],
                        'hop'            => $item['hop'] + 1,
                    );
                }
            }
        }

        // Safety Net: Include any delivered/locked carryover logs whose parent link was historical
        foreach ( $all_carryover_logs as $cid => $clog ) {
            if ( isset( $consumed_carryover_ids[ $cid ] ) ) {
                continue;
            }
            if ( $clog->delivery_result === 'Successful' || intval( $clog->is_prepared ) === 1 || intval( $clog->dispatch_status ) === 1 ) {
                $c_meals  = max( 1, intval( $clog->carryover_meals_quota ) );
                $c_snacks = max( 0, intval( $clog->carryover_snacks_quota ) );
                $m_word   = ( $c_meals === 1 ) ? '1 Meal' : $c_meals . ' Meals';
                $s_word   = ( $c_snacks > 0 ) ? ( $c_snacks === 1 ? '1 Snack' : $c_snacks . ' Snacks' ) : '0 Snacks';
                $active_carryover_count++;

                $carryover_slots[] = array(
                    'day_num'              => $next_day_num++,
                    'is_carryover'         => true,
                    'log'                  => $clog,
                    'alloc_meals'          => $c_meals,
                    'alloc_snacks'         => $c_snacks,
                    'parent_log_id'        => intval( $clog->carryover_from_log_id ),
                    'source_day_num'       => 0,
                    'source_date'          => '',
                    'origin_day_num'       => 0,
                    'origin_date'          => '',
                    'tooltip_text'         => sprintf( 'Carryover Redemption Day — Redeem %s & %s.', $m_word, $s_word ),
                    'allocation_badge_txt' => $m_word . ' • ' . $s_word,
                );
            }
        }

        return array(
            'regular_slots'      => $regular_slots,
            'carryover_slots'    => $carryover_slots,
            'all_slots'          => array_merge( $regular_slots, $carryover_slots ),
            'regular_void_count' => $regular_void_count,
            'active_carryovers'  => $active_carryover_count,
            'base_quotas'        => $base_quotas,
        );
    }
}

/**
 * Synchronizes the subscription's `carryover_days` count, cleans up unconsumed orphan carryover logs
 * if a parent day was restored to full quota, and adjusts `expiry_date` when carryover days change.
 */
if ( ! function_exists( 'cmp_sync_subscription_carryover_state' ) ) {
    function cmp_sync_subscription_carryover_state( $sub_id ) {
        global $wpdb;
        cmp_ensure_meal_skip_carryover_columns();

        $sub_id     = intval( $sub_id );
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';
        $table_logs = $wpdb->prefix . 'cmp_daily_logs';

        $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", $sub_id ) );
        if ( ! $sub ) {
            return array( 'active_carryovers' => 0, 'changed' => false );
        }

        $schedule      = cmp_build_subscription_schedule_slots( $sub );
        $valid_log_ids = array();

        foreach ( $schedule['carryover_slots'] as $c_slot ) {
            if ( ! empty( $c_slot['log'] ) && ! empty( $c_slot['log']->id ) ) {
                $lid                   = intval( $c_slot['log']->id );
                $valid_log_ids[ $lid ] = true;

                // Keep stored quota metadata on the child log synchronized with its parent's skipped count
                if ( intval( $c_slot['log']->carryover_meals_quota ) !== intval( $c_slot['alloc_meals'] ) ||
                     intval( $c_slot['log']->carryover_snacks_quota ) !== intval( $c_slot['alloc_snacks'] ) ) {
                    $wpdb->update(
                        $table_logs,
                        array(
                            'carryover_meals_quota'  => intval( $c_slot['alloc_meals'] ),
                            'carryover_snacks_quota' => intval( $c_slot['alloc_snacks'] ),
                        ),
                        array( 'id' => $lid )
                    );
                }
            }
        }

        // Remove any unconsumed carryover log rows whose parent day was restored to full quota or cleared
        $saved_carryovers = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_logs} WHERE subscription_id = %d AND is_carryover = 1",
            $sub_id
        ) );

        if ( $saved_carryovers ) {
            foreach ( $saved_carryovers as $sc ) {
                $sc_id = intval( $sc->id );
                if ( ! isset( $valid_log_ids[ $sc_id ] ) ) {
                    if ( $sc->delivery_result !== 'Successful' && intval( $sc->is_prepared ) === 0 && intval( $sc->dispatch_status ) === 0 ) {
                        $wpdb->delete( $table_logs, array( 'id' => $sc_id ), array( '%d' ) );
                    }
                }
            }
        }

        // Recalculate after cleanup
        $sub               = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", $sub_id ) );
        $schedule          = cmp_build_subscription_schedule_slots( $sub );
        $new_carryover_cnt = intval( $schedule['active_carryovers'] );
        $old_carryover_cnt = isset( $sub->carryover_days ) ? intval( $sub->carryover_days ) : 0;
        $delta             = $new_carryover_cnt - $old_carryover_cnt;

        if ( $delta !== 0 ) {
            $exp_dt = new DateTime( $sub->expiry_date );
            if ( $delta > 0 ) {
                $exp_dt->modify( '+' . $delta . ' days' );
            } else {
                $exp_dt->modify( $delta . ' days' );
            }

            $wpdb->update(
                $table_subs,
                array(
                    'carryover_days' => $new_carryover_cnt,
                    'expiry_date'    => $exp_dt->format( 'Y-m-d H:i:s' ),
                ),
                array( 'id' => $sub_id )
            );
        }

        return array(
            'active_carryovers' => $new_carryover_cnt,
            'delta'             => $delta,
            'changed'           => ( $delta !== 0 ),
            'schedule'          => $schedule,
        );
    }
}

/**
 * Centralized Tracking Metrics & Balance Total Meals Calculator for FOH, Executive & Accounts Portals.
 * Calculates:
 *  - Filled Days, Used Days, Effective Total Days (Base + Carryover), Balance Days, Pending to Fill Days
 *  - Total Plan Meals, Used Meals, Balance Total Meals (Main Meals/Juices Only — Excludes Snacks)
 *  - Pending Carryover Days & Pending Carryover Meals
 *  - Last Filled Date & formatted FOH Popover string
 */
if ( ! function_exists( 'cmp_get_subscription_tracking_metrics' ) ) {
    function cmp_get_subscription_tracking_metrics( $sub ) {
        global $wpdb;
        cmp_ensure_meal_skip_carryover_columns();

        if ( is_numeric( $sub ) ) {
            $table_subs = $wpdb->prefix . 'cmp_subscriptions';
            $sub        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", intval( $sub ) ) );
        }

        $schedule    = cmp_build_subscription_schedule_slots( $sub );
        $base_quotas = $schedule['base_quotas'];
        $is_juice    = $base_quotas['is_juice'];
        $daily_meals = $is_juice ? 3 : intval( $base_quotas['allowed_meals'] );

        $base_total_days      = intval( $sub->total_days );
        $carryover_days_count = intval( $schedule['active_carryovers'] );
        $effective_total_days = $base_total_days + $carryover_days_count;
        $total_plan_meals     = $base_total_days * $daily_meals;

        // Sync cached carryover_days on subscription if out of date
        if ( isset( $sub->carryover_days ) && intval( $sub->carryover_days ) !== $carryover_days_count ) {
            $wpdb->update(
                $wpdb->prefix . 'cmp_subscriptions',
                array( 'carryover_days' => $carryover_days_count ),
                array( 'id' => intval( $sub->id ) )
            );
            $sub->carryover_days = $carryover_days_count;
        }

        $filled_days             = 0;
        $used_days               = 0;
        $pending_fill_days       = 0;
        $used_meals              = 0;
        $pending_carryover_days  = 0;
        $pending_carryover_meals = 0;
        $last_filled_date        = '';

        foreach ( $schedule['all_slots'] as $slot ) {
            $log     = $slot['log'];
            $is_void = ( $log && in_array( $log->delivery_result, array( 'Cancelled', 'Returned' ), true ) );
            if ( $is_void ) {
                continue;
            }

            if ( $log && intval( $log->id ) > 0 ) {
                $filled_days++;
                if ( ! empty( $log->target_date ) && $log->target_date !== '0000-00-00' ) {
                    if ( $last_filled_date === '' || $log->target_date > $last_filled_date ) {
                        $last_filled_date = $log->target_date;
                    }
                }

                if ( $log->delivery_result === 'Successful' ) {
                    $used_days++;
                    $counts      = cmp_count_log_selected_meals_and_snacks( $log, $is_juice, $slot['alloc_meals'], $slot['alloc_snacks'] );
                    $meals_on_d  = $counts['meals'] > 0 ? $counts['meals'] : intval( $slot['alloc_meals'] );
                    $used_meals += $meals_on_d;
                } elseif ( ! empty( $slot['is_carryover'] ) ) {
                    $pending_carryover_days++;
                    $pending_carryover_meals += intval( $slot['alloc_meals'] );
                }
            } else {
                $pending_fill_days++;
                if ( ! empty( $slot['is_carryover'] ) ) {
                    $pending_carryover_days++;
                    $pending_carryover_meals += intval( $slot['alloc_meals'] );
                }
            }
        }

        $balance_days  = max( 0, $effective_total_days - $used_days );
        $balance_meals = max( 0, $total_plan_meals - $used_meals );

        $last_filled_formatted = ! empty( $last_filled_date )
            ? date( 'M j, Y', strtotime( $last_filled_date ) )
            : 'No Dates Scheduled';

        $popover_parts   = array();
        $popover_parts[] = ( $pending_fill_days > 0 )
            ? 'Pending to Fill: ' . $pending_fill_days . ' Day' . ( $pending_fill_days === 1 ? '' : 's' )
            : 'All Days Filled';
        $popover_parts[] = 'Balance Meals: ' . $balance_meals . ' Meal' . ( $balance_meals === 1 ? '' : 's' );

        if ( $pending_carryover_days > 0 ) {
            $popover_parts[] = sprintf(
                'Carryover: %d Day%s (%d Meal%s Pending)',
                $pending_carryover_days,
                $pending_carryover_days === 1 ? '' : 's',
                $pending_carryover_meals,
                $pending_carryover_meals === 1 ? '' : 's'
            );
        } elseif ( $carryover_days_count > 0 ) {
            $popover_parts[] = sprintf(
                'Carryover: %d Day%s (Redeemed)',
                $carryover_days_count,
                $carryover_days_count === 1 ? '' : 's'
            );
        }

        $popover_parts[] = 'Last Filled Date: ' . $last_filled_formatted;

        return array(
            'base_total_days'         => $base_total_days,
            'carryover_days'          => $carryover_days_count,
            'effective_total_days'    => $effective_total_days,
            'filled_days'             => $filled_days,
            'used_days'               => $used_days,
            'balance_days'            => $balance_days,
            'pending_fill_days'       => $pending_fill_days,
            'daily_meal_quota'        => $daily_meals,
            'total_plan_meals'        => $total_plan_meals,
            'used_meals'              => $used_meals,
            'balance_meals'           => $balance_meals,
            'pending_carryover_days'  => $pending_carryover_days,
            'pending_carryover_meals' => $pending_carryover_meals,
            'last_filled_date'        => $last_filled_date,
            'last_filled_formatted'   => $last_filled_formatted,
            'popover_text'            => implode( ' · ', $popover_parts ),
        );
    }
}

/**
 * Option PREP-C Helper: Checks whether a time slot string starts at or after 4:00 PM (16:00).
 */
if ( ! function_exists( 'cmp_is_time_slot_after_4pm' ) ) {
    function cmp_is_time_slot_after_4pm( $time_slot ) {
        $time_slot = trim( (string) $time_slot );
        if ( $time_slot === '' ) {
            return false;
        }
        if ( preg_match( '/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?/i', $time_slot, $m ) ) {
            $hour = intval( $m[1] );
            $ampm = isset( $m[3] ) ? strtoupper( trim( $m[3] ) ) : '';

            if ( $ampm === 'PM' && $hour < 12 ) {
                $hour += 12;
            } elseif ( $ampm === 'AM' && $hour === 12 ) {
                $hour = 0;
            }
            return ( $hour >= 16 );
        }
        return false;
    }
}

/**
 * Option PREP-C Resolver: Determines if a subscription is on Same-Day Preparation.
 * - 'same_day'   -> true (Manual Override)
 * - 'day_before' -> false (Manual Override)
 * - 'auto'       -> true if Receive By is Same Day AND Time Slot starts at or after 4:00 PM
 */
if ( ! function_exists( 'cmp_is_same_day_prep_subscription' ) ) {
    function cmp_is_same_day_prep_subscription( $sub, $resolved = null ) {
        if ( ! is_array( $resolved ) ) {
            $resolved = cmp_get_subscription_logistics( $sub );
        }

        $prep_override = isset( $sub->prep_timing ) ? strtolower( trim( (string) $sub->prep_timing ) ) : 'auto';
        if ( $prep_override === 'same_day' ) {
            return true;
        }
        if ( $prep_override === 'day_before' ) {
            return false;
        }

        $timing       = isset( $resolved['timing'] ) ? (string) $resolved['timing'] : '';
        $time_slot    = isset( $resolved['time_slot'] ) ? (string) $resolved['time_slot'] : '';
        $is_same_day  = ( stripos( $timing, 'Same Day' ) !== false );
        $is_after_4pm = cmp_is_time_slot_after_4pm( $time_slot );

        return ( $is_same_day && $is_after_4pm );
    }
}

/**
 * Returns a human-readable staff summary for a subscription's Kitchen Prep Schedule.
 */
if ( ! function_exists( 'cmp_get_prep_schedule_display_label' ) ) {
    function cmp_get_prep_schedule_display_label( $sub, $resolved = null ) {
        $is_same_day = cmp_is_same_day_prep_subscription( $sub, $resolved );
        $mode        = isset( $sub->prep_timing ) ? strtolower( trim( (string) $sub->prep_timing ) ) : 'auto';
        $suffix      = ( $mode === 'same_day' || $mode === 'day_before' ) ? ' (Manual Override)' : ' (Auto 4PM+ Rule)';

        return ( $is_same_day ? '⚡ Same-Day Prep' : 'Day-Before Prep' ) . $suffix;
    }
}

/**
 * Ensures the customer feedback table exists without requiring plugin reactivation.
 */
add_action( 'init', 'cmp_ensure_feedback_table_exists' );
if ( ! function_exists( 'cmp_ensure_feedback_table_exists' ) ) {
    function cmp_ensure_feedback_table_exists() {
        if ( get_option( 'cmp_feedback_table_v1' ) === '1' ) {
            return;
        }
        global $wpdb;
        $table_feedback  = $wpdb->prefix . 'cmp_feedback';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE $table_feedback (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT 0,
            subscription_id bigint(20) DEFAULT 0,
            wc_order_id bigint(20) DEFAULT 0,
            is_anonymous tinyint(1) DEFAULT 0,
            customer_name varchar(255) DEFAULT '',
            customer_email varchar(255) DEFAULT '',
            customer_phone varchar(100) DEFAULT '',
            plan_name varchar(255) DEFAULT '',
            service_rating tinyint(2) NOT NULL DEFAULT 0,
            portal_rating tinyint(2) NOT NULL DEFAULT 0,
            quick_tags text NULL,
            comments text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta( $sql );
        update_option( 'cmp_feedback_table_v1', '1' );
    }
}

/**
 * Ensures `wp_cmp_subscriptions` has dedicated per-plan logistics, allergy, `prep_timing`,
 * and carryover columns and backfills existing subscriptions.
 */
add_action( 'init', 'cmp_ensure_subscription_logistics_columns' );
if ( ! function_exists( 'cmp_ensure_subscription_logistics_columns' ) ) {
    function cmp_ensure_subscription_logistics_columns() {
        cmp_ensure_prep_timing_column();
        cmp_ensure_meal_skip_carryover_columns();

        if ( get_option( 'cmp_sub_logistics_cols_v1' ) === '1' ) {
            return;
        }

        global $wpdb;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_subs ) );
        if ( $table_exists !== $table_subs ) {
            return;
        }

        $existing_cols = $wpdb->get_col( "DESC {$table_subs}", 0 );
        if ( ! is_array( $existing_cols ) ) {
            return;
        }

        $columns_to_add = array(
            'delivery_method'  => "ALTER TABLE {$table_subs} ADD COLUMN delivery_method VARCHAR(100) DEFAULT ''",
            'pickup_location'  => "ALTER TABLE {$table_subs} ADD COLUMN pickup_location VARCHAR(255) DEFAULT ''",
            'delivery_timing'  => "ALTER TABLE {$table_subs} ADD COLUMN delivery_timing VARCHAR(100) DEFAULT ''",
            'time_slot'        => "ALTER TABLE {$table_subs} ADD COLUMN time_slot VARCHAR(100) DEFAULT ''",
            'allergies'        => "ALTER TABLE {$table_subs} ADD COLUMN allergies TEXT NULL",
            'delivery_address' => "ALTER TABLE {$table_subs} ADD COLUMN delivery_address TEXT NULL",
            'prep_timing'      => "ALTER TABLE {$table_subs} ADD COLUMN prep_timing VARCHAR(30) DEFAULT 'auto'",
        );

        foreach ( $columns_to_add as $col_name => $alter_sql ) {
            if ( ! in_array( $col_name, $existing_cols, true ) ) {
                $wpdb->query( $alter_sql );
            }
        }

        // One-time backfill for existing subscriptions from Order Meta / User Meta
        $subs = $wpdb->get_results( "SELECT * FROM {$table_subs} ORDER BY id ASC" );
        if ( $subs ) {
            foreach ( $subs as $sub ) {
                $resolved = cmp_get_subscription_logistics( $sub );
                $update   = array();

                if ( empty( $sub->delivery_method ) && ! empty( $resolved['method'] ) ) {
                    $update['delivery_method'] = $resolved['method'];
                }
                if ( empty( $sub->pickup_location ) && ! empty( $resolved['pickup'] ) ) {
                    $update['pickup_location'] = $resolved['pickup'];
                }
                if ( empty( $sub->delivery_timing ) && ! empty( $resolved['timing'] ) ) {
                    $update['delivery_timing'] = $resolved['timing'];
                }
                if ( empty( $sub->time_slot ) && ! empty( $resolved['time_slot'] ) ) {
                    $update['time_slot'] = $resolved['time_slot'];
                }
                if ( ( ! isset( $sub->allergies ) || $sub->allergies === '' || $sub->allergies === null ) && ! empty( $resolved['allergies'] ) ) {
                    $update['allergies'] = $resolved['allergies'];
                }
                if ( empty( $sub->delivery_address ) && ! empty( $resolved['address'] ) && $resolved['address'] !== 'Address not provided' ) {
                    $update['delivery_address'] = $resolved['address'];
                }

                if ( ! empty( $update ) ) {
                    $wpdb->update( $table_subs, $update, array( 'id' => intval( $sub->id ) ) );
                }

                if ( intval( $sub->user_id ) > 0 ) {
                    cmp_save_user_logistics_profile_history(
                        intval( $sub->user_id ),
                        intval( $sub->id ),
                        array(
                            'plan_name' => $sub->plan_name,
                            'method'    => $resolved['method'],
                            'pickup'    => $resolved['pickup'],
                            'timing'    => $resolved['timing'],
                            'time_slot' => $resolved['time_slot'],
                            'allergies' => $resolved['allergies'],
                            'address'   => $resolved['address'],
                        ),
                        false
                    );
                }
            }
        }

        update_option( 'cmp_sub_logistics_cols_v1', '1' );
    }
}

/**
 * Resolves a subscription's exact logistics & allergies using the 3-tier priority hierarchy:
 * 1. Subscription Row (`wp_cmp_subscriptions`) -> 2. WooCommerce Order Meta -> 3. User Meta
 */
if ( ! function_exists( 'cmp_get_subscription_logistics' ) ) {
    function cmp_get_subscription_logistics( $sub ) {
        $order    = ( ! empty( $sub->wc_order_id ) && function_exists( 'wc_get_order' ) ) ? wc_get_order( $sub->wc_order_id ) : false;
        $user_id  = intval( $sub->user_id ?? 0 );
        $sub_user = $user_id > 0 ? get_userdata( $user_id ) : false;

        // 1. Delivery Timing
        $timing = ! empty( $sub->delivery_timing ) ? $sub->delivery_timing : '';
        if ( empty( $timing ) && $order ) {
            $timing = $order->get_meta( '_cmp_delivery_timing' ) ?: $order->get_meta( 'delivery_timing' );
        }
        if ( empty( $timing ) && $user_id > 0 ) {
            $timing = get_user_meta( $user_id, 'delivery_timing', true );
        }

        // 2. Time Slot
        $time_slot = ! empty( $sub->time_slot ) ? $sub->time_slot : '';
        if ( empty( $time_slot ) && $order ) {
            $time_slot = $order->get_meta( '_cmp_time_slot' ) ?: $order->get_meta( 'time_slot' );
        }
        if ( empty( $time_slot ) && $user_id > 0 ) {
            $time_slot = get_user_meta( $user_id, 'time_slot', true );
        }

        // 3. Delivery Method
        $method = ! empty( $sub->delivery_method ) ? $sub->delivery_method : '';
        if ( empty( $method ) && $order ) {
            $method = $order->get_meta( '_cmp_logistics_method' ) ?: $order->get_meta( 'delivery_method' );
        }
        if ( empty( $method ) && $user_id > 0 ) {
            $method = get_user_meta( $user_id, 'delivery_method', true );
        }

        // 4. Pickup Location
        $pickup = ! empty( $sub->pickup_location ) ? $sub->pickup_location : '';
        if ( empty( $pickup ) && $order ) {
            $pickup = $order->get_meta( '_cmp_pickup_location' ) ?: $order->get_meta( 'pickup_location' );
        }
        if ( empty( $pickup ) && $user_id > 0 ) {
            $pickup = get_user_meta( $user_id, 'pickup_location', true );
        }

        // 5. Allergies (Per-Plan)
        $allergies = '';
        if ( isset( $sub->allergies ) && $sub->allergies !== null && $sub->allergies !== '' ) {
            $allergies = ( $sub->allergies === '__NONE__' ) ? '' : $sub->allergies;
        } else {
            if ( $order ) {
                $allergies = $order->get_meta( 'allergies' ) ?: $order->get_customer_note();
            }
            if ( empty( $allergies ) && $user_id > 0 ) {
                $allergies = get_user_meta( $user_id, 'allergies', true );
            }
        }

        // 6. Delivery Address (Per-Plan)
        $address = ! empty( $sub->delivery_address ) ? $sub->delivery_address : '';
        if ( empty( $address ) && $order ) {
            $addr_parts = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city() ) );
            if ( empty( $addr_parts ) ) {
                $addr_parts = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city() ) );
            }
            if ( ! empty( $addr_parts ) ) {
                $address = implode( ', ', $addr_parts );
            }
        }
        if ( empty( $address ) && $user_id > 0 ) {
            $addr_parts = array_filter( array(
                get_user_meta( $user_id, 'billing_address_1', true ),
                get_user_meta( $user_id, 'billing_address_2', true ),
                get_user_meta( $user_id, 'billing_city', true ),
            ) );
            if ( ! empty( $addr_parts ) ) {
                $address = implode( ', ', $addr_parts );
            }
        }
        if ( empty( $address ) ) {
            $address = 'Address not provided';
        }

        // 7. Customer Name, Email, Phone
        $fname = $user_id > 0 ? ( get_user_meta( $user_id, 'first_name', true ) ?: get_user_meta( $user_id, 'billing_first_name', true ) ) : '';
        $lname = $user_id > 0 ? ( get_user_meta( $user_id, 'last_name', true ) ?: get_user_meta( $user_id, 'billing_last_name', true ) ) : '';
        if ( $order ) {
            $fname = $order->get_billing_first_name() ?: $fname;
            $lname = $order->get_billing_last_name() ?: $lname;
        }
        $full_name = trim( $fname . ' ' . $lname );
        if ( empty( $full_name ) ) {
            $full_name = $sub_user ? $sub_user->display_name : 'Customer';
        }

        $email = $order ? $order->get_billing_email() : ( $sub_user ? $sub_user->user_email : '' );
        $phone = $order ? $order->get_billing_phone() : ( $user_id > 0 ? get_user_meta( $user_id, 'billing_phone', true ) : '' );

        return array(
            'fname'     => $fname,
            'lname'     => $lname,
            'full_name' => $full_name,
            'email'     => $email,
            'phone'     => $phone,
            'method'    => $method,
            'pickup'    => $pickup,
            'timing'    => $timing,
            'time_slot' => $time_slot,
            'allergies' => $allergies,
            'address'   => $address,
        );
    }
}

/**
 * Records a customer's per-plan logistics and allergy profile into `wp_usermeta`.
 */
if ( ! function_exists( 'cmp_save_user_logistics_profile_history' ) ) {
    function cmp_save_user_logistics_profile_history( $user_id, $sub_id, $profile_data = array(), $update_default_meta = true ) {
        $user_id = intval( $user_id );
        $sub_id  = intval( $sub_id );
        if ( $user_id <= 0 ) {
            return;
        }

        $timing    = sanitize_text_field( $profile_data['timing'] ?? '' );
        $time_slot = sanitize_text_field( $profile_data['time_slot'] ?? '' );
        $allergies = sanitize_textarea_field( $profile_data['allergies'] ?? '' );
        $method    = sanitize_text_field( $profile_data['method'] ?? '' );
        $pickup    = sanitize_text_field( $profile_data['pickup'] ?? '' );
        $address   = sanitize_text_field( $profile_data['address'] ?? '' );
        $plan_name = sanitize_text_field( $profile_data['plan_name'] ?? '' );

        if ( $update_default_meta ) {
            if ( ! empty( $timing ) ) {
                update_user_meta( $user_id, 'delivery_timing', $timing );
            }
            if ( ! empty( $time_slot ) ) {
                update_user_meta( $user_id, 'time_slot', $time_slot );
            }
            update_user_meta( $user_id, 'allergies', $allergies );
            if ( ! empty( $method ) ) {
                update_user_meta( $user_id, 'delivery_method', $method );
            }
            if ( ! empty( $pickup ) ) {
                update_user_meta( $user_id, 'pickup_location', $pickup );
            }
            if ( ! empty( $address ) && $address !== 'Address not provided' ) {
                update_user_meta( $user_id, 'billing_address_1', $address );
            }
        }

        if ( empty( $timing ) && empty( $time_slot ) && empty( $allergies ) ) {
            return;
        }

        $profiles = get_user_meta( $user_id, '_cmp_saved_logistics_profiles', true );
        if ( ! is_array( $profiles ) ) {
            $profiles = array();
        }

        $profile_key = 'sub_' . ( $sub_id > 0 ? $sub_id : md5( $plan_name . '|' . $timing . '|' . $time_slot ) );
        $profiles[ $profile_key ] = array(
            'sub_id'     => $sub_id,
            'plan_name'  => $plan_name ?: ( 'Plan #' . $sub_id ),
            'method'     => $method ?: get_user_meta( $user_id, 'delivery_method', true ),
            'pickup'     => $pickup ?: get_user_meta( $user_id, 'pickup_location', true ),
            'timing'     => $timing,
            'time_slot'  => $time_slot,
            'allergies'  => $allergies,
            'address'    => ( ! empty( $address ) && $address !== 'Address not provided' ) ? $address : get_user_meta( $user_id, 'billing_address_1', true ),
            'updated_at' => current_time( 'mysql' ),
        );

        if ( count( $profiles ) > 15 ) {
            $profiles = array_slice( $profiles, -15, 15, true );
        }

        update_user_meta( $user_id, '_cmp_saved_logistics_profiles', $profiles );
    }
}

/**
 * Retrieves all saved logistics & allergy profiles for a customer.
 */
if ( ! function_exists( 'cmp_get_user_saved_logistics_profiles' ) ) {
    function cmp_get_user_saved_logistics_profiles( $user_id ) {
        $user_id = intval( $user_id );
        if ( $user_id <= 0 ) {
            return array();
        }

        $saved = get_user_meta( $user_id, '_cmp_saved_logistics_profiles', true );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        global $wpdb;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';
        $subs       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE user_id = %d ORDER BY id DESC LIMIT 10", $user_id ) );

        if ( $subs ) {
            foreach ( $subs as $s ) {
                $key = 'sub_' . intval( $s->id );
                if ( ! isset( $saved[ $key ] ) && ( ! empty( $s->delivery_timing ) || ! empty( $s->time_slot ) || ! empty( $s->allergies ) ) ) {
                    $saved[ $key ] = array(
                        'sub_id'     => intval( $s->id ),
                        'plan_name'  => $s->plan_name,
                        'method'     => $s->delivery_method ?? '',
                        'pickup'     => $s->pickup_location ?? '',
                        'timing'     => $s->delivery_timing ?? '',
                        'time_slot'  => $s->time_slot ?? '',
                        'allergies'  => ( isset( $s->allergies ) && $s->allergies !== '__NONE__' ) ? $s->allergies : '',
                        'address'    => $s->delivery_address ?? '',
                        'updated_at' => $s->start_date ?? '',
                    );
                }
            }
        }

        $unique_profiles = array();
        foreach ( array_reverse( $saved, true ) as $k => $p ) {
            if ( empty( $p['timing'] ) && empty( $p['time_slot'] ) ) {
                continue;
            }
            $sig = strtolower( trim( ( $p['timing'] ?? '' ) . '|' . ( $p['time_slot'] ?? '' ) . '|' . ( $p['allergies'] ?? '' ) ) );
            if ( ! isset( $unique_profiles[ $sig ] ) ) {
                $unique_profiles[ $sig ] = $p;
            }
        }

        return array_values( $unique_profiles );
    }
}

/**
 * Checks if the current logged-in user belongs to any of the configured role slugs.
 */
if ( ! function_exists( 'cmp_user_matches_configured_roles' ) ) {
    function cmp_user_matches_configured_roles( $option_key, $default_roles = array( 'administrator' ) ) {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        $allowed_roles = get_option( $option_key, $default_roles );
        if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
            $allowed_roles = $default_roles;
        }

        $user       = wp_get_current_user();
        $user_roles = (array) $user->roles;

        foreach ( $allowed_roles as $role_slug ) {
            if ( in_array( $role_slug, $user_roles, true ) || current_user_can( $role_slug ) ) {
                return true;
            }
        }
        return false;
    }
}

/**
 * Dynamically checks WP Admin -> Meal Settings ('cmp_adjust_days_roles')
 */
if ( ! function_exists( 'cmp_can_adjust_plan_days' ) ) {
    function cmp_can_adjust_plan_days() {
        return cmp_user_matches_configured_roles( 'cmp_adjust_days_roles', array( 'administrator', 'menu_manager' ) );
    }
}

/**
 * Strict Quota Enforcement: Customers, FOH, Menu Managers & Admins must select according to the plan's quota.
 * Skipping a meal is governed strictly by the per-subscription `allow_meal_skip` flag.
 */
if ( ! function_exists( 'cmp_can_bypass_meal_quota' ) ) {
    function cmp_can_bypass_meal_quota() {
        return false;
    }
}

/**
 * Dynamically checks WP Admin -> Meal Settings ('cmp_date_exempt_roles')
 */
if ( ! function_exists( 'cmp_can_bypass_date_restrictions' ) ) {
    function cmp_can_bypass_date_restrictions() {
        return cmp_user_matches_configured_roles( 'cmp_date_exempt_roles', array( 'administrator', 'foh_manager', 'menu_manager' ) );
    }
}

/**
 * Checks whether the current user is FOH, Menu Manager, or Admin (Mode A: Staff-only Logistics Editing & POS Check visibility).
 */
if ( ! function_exists( 'cmp_can_view_pos_and_edit_logistics' ) ) {
    function cmp_can_view_pos_and_edit_logistics() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        return current_user_can( 'manage_options' ) || current_user_can( 'foh_manager' ) || current_user_can( 'menu_manager' ) || cmp_can_bypass_date_restrictions();
    }
}

/**
 * Returns the earliest allowed YYYY-MM-DD date a customer can modify, reset, or cancel.
 */
if ( ! function_exists( 'cmp_get_earliest_customer_allowed_date' ) ) {
    function cmp_get_earliest_customer_allowed_date() {
        $tz           = new DateTimeZone( 'Asia/Dubai' );
        $now          = new DateTime( 'now', $tz );
        $current_hour = (int) $now->format( 'H' );
        $cutoff_hour  = intval( get_option( 'cmp_cutoff_time', '11' ) );
        $tomorrow     = ( clone $now )->modify( '+1 day' )->format( 'Y-m-d' );
        $day_after    = ( clone $now )->modify( '+2 days' )->format( 'Y-m-d' );

        return ( $current_hour >= $cutoff_hour ) ? $day_after : $tomorrow;
    }
}

/**
 * Checks whether a target date is locked for customer edits/resets/cancellations.
 */
if ( ! function_exists( 'cmp_is_date_locked_for_customer' ) ) {
    function cmp_is_date_locked_for_customer( $target_date ) {
        if ( empty( $target_date ) || $target_date === '0000-00-00' ) {
            return false;
        }
        $tz               = new DateTimeZone( 'Asia/Dubai' );
        $today            = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
        $earliest_allowed = cmp_get_earliest_customer_allowed_date();

        if ( $target_date <= $today || $target_date < $earliest_allowed ) {
            return true;
        }
        return false;
    }
}

// ==========================================
// 1. AJAX HANDLERS
// ==========================================

// 1.1 SAVE OR UPDATE DAILY MEAL LOG (WITH ALLOW MEAL SKIPPING, CARRYOVER REDEMPTION & AUDIT LOGGING)
add_action( 'wp_ajax_cmp_save_daily_log', 'cmp_ajax_save_daily_log' );
if ( ! function_exists( 'cmp_ajax_save_daily_log' ) ) {
    function cmp_ajax_save_daily_log() {
        check_ajax_referer( 'cmp_portal_nonce', 'nonce' );
        global $wpdb;

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        if ( function_exists( 'cmp_auto_patch_database' ) ) {
            cmp_auto_patch_database();
        }
        cmp_ensure_meal_skip_carryover_columns();

        $log_id                = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;
        $sub_id                = intval( $_POST['sub_id'] );
        $target_date           = sanitize_text_field( $_POST['date'] );
        $is_override_mode      = ! empty( $_POST['is_override_mode'] ) ? intval( $_POST['is_override_mode'] ) : 0;
        $is_carryover_row      = ! empty( $_POST['is_carryover'] ) ? 1 : 0;
        $carryover_from_log_id = isset( $_POST['carryover_from_log_id'] ) ? intval( $_POST['carryover_from_log_id'] ) : 0;
        $posted_alloc_meals    = isset( $_POST['alloc_meals'] ) ? intval( $_POST['alloc_meals'] ) : 0;
        $posted_alloc_snacks   = isset( $_POST['alloc_snacks'] ) ? intval( $_POST['alloc_snacks'] ) : -1;

        if ( empty( $target_date ) ) {
            wp_send_json_error( 'Please select a valid date first.' );
        }

        $can_bypass_dates = cmp_can_bypass_date_restrictions() || ( current_user_can( 'kitchen_staff' ) && $is_override_mode === 1 );

        // Cutoff Time & Past/Today Date Validation
        if ( ! $can_bypass_dates ) {
            $cutoff_hour      = intval( get_option( 'cmp_cutoff_time', '11' ) );
            $earliest_allowed = cmp_get_earliest_customer_allowed_date();

            if ( cmp_is_date_locked_for_customer( $target_date ) ) {
                wp_send_json_error(
                    'This date is no longer available. Past dates, today, and dates past the ' .
                    str_pad( $cutoff_hour, 2, '0', STR_PAD_LEFT ) .
                    ':00 GST cutoff are locked. The earliest available date is ' .
                    date( 'D, d M Y', strtotime( $earliest_allowed ) ) . '.'
                );
            }
        }

        $table_logs  = $wpdb->prefix . 'cmp_daily_logs';
        $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
        $table_foods = $wpdb->prefix . 'cmp_foods';

        $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_subs WHERE id = %d", $sub_id ) );
        if ( ! $sub ) {
            wp_send_json_error( 'Subscription not found.' );
        }

        $is_staff_user = cmp_can_bypass_date_restrictions() || cmp_can_manage_meal_skipping() || current_user_can( 'kitchen_staff' );
        if ( ! $is_staff_user && intval( $sub->user_id ) !== get_current_user_id() ) {
            wp_send_json_error( 'Unauthorized access to this subscription.' );
        }

        // Fetch existing log row before update for Old Status snapshot & Plan Mode
        $existing_log = null;
        if ( $log_id > 0 ) {
            $existing_log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_logs WHERE id = %d", $log_id ) );
            $plan_mode    = ( $existing_log && ! empty( $existing_log->plan_mode ) ) ? $existing_log->plan_mode : 'standard';
            $pos_updated  = ( $existing_log && ! empty( $existing_log->pos_updated ) ) ? 1 : 0;
            if ( $existing_log && ! empty( $existing_log->is_carryover ) ) {
                $is_carryover_row      = 1;
                $carryover_from_log_id = intval( $existing_log->carryover_from_log_id );
            }
        } else {
            $plan_mode = 'standard';
            if ( ! empty( $sub->is_flexible ) )  $plan_mode = 'flexible';
            if ( ! empty( $sub->allow_custom ) ) $plan_mode = 'custom';
            $pos_updated = 0;
        }

        $old_snapshot = function_exists( 'cmp_format_log_meal_snapshot' )
            ? cmp_format_log_meal_snapshot( $existing_log )
            : ( $existing_log ? 'Selected (' . $existing_log->target_date . ')' : 'Pending Selection (Unscheduled)' );

        $row_is_flexible  = ( $plan_mode === 'flexible' ) ? 1 : 0;
        $row_allow_custom = ( $plan_mode === 'custom' ) ? 1 : 0;
        $is_chefs_choice  = isset( $_POST['chefs_choice'] ) ? intval( $_POST['chefs_choice'] ) : 0;

        // --- STRICT MENU AVAILABILITY CHECK ---
        $has_meals = false;
        if ( $row_allow_custom ) {
            $has_meals = ! empty( $_POST['custom_breakfast'] ) || ! empty( $_POST['custom_lunch'] ) || ! empty( $_POST['custom_dinner'] ) || ! empty( $_POST['custom_snack_1'] ) || ! empty( $_POST['custom_snack_2'] ) || ! empty( $_POST['custom_juice_1'] ) || ! empty( $_POST['custom_juice_2'] ) || ! empty( $_POST['custom_juice_3'] );
        } else {
            $has_meals = ! empty( $_POST['breakfast'] ) || ! empty( $_POST['lunch'] ) || ! empty( $_POST['dinner'] ) || ! empty( $_POST['snack_1'] ) || ! empty( $_POST['snack_2'] ) || ! empty( $_POST['juice_1'] ) || ! empty( $_POST['juice_2'] ) || ! empty( $_POST['juice_3'] );
        }

        $is_menu_pending = false;
        if ( ! $row_allow_custom ) {
            $food_exists_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_foods WHERE is_active = 1 AND (valid_from IS NULL OR (valid_from <= %s AND valid_until >= %s))", $target_date, $target_date ) );
            $is_menu_pending   = ( intval( $food_exists_count ) === 0 );

            if ( $has_meals && $is_menu_pending ) {
                wp_send_json_error( 'The menu for this date is pending release. Specific meals cannot be selected yet.' );
            }

            if ( $is_menu_pending && ! $is_chefs_choice ) {
                wp_send_json_error( 'The specific menu is not available yet for this date. Please select "Chef\'s Choice" to secure this date.' );
            }
        }

        // --- RESOLVE ROW ALLOCATION QUOTAS (REGULAR DAY VS. CARRYOVER REDEMPTION DAY) ---
        $base_quotas    = cmp_get_subscription_base_quotas( $sub );
        $is_juice       = $base_quotas['is_juice'];
        $allowed_quota  = $base_quotas['allowed_meals'];
        $snack_quota    = $base_quotas['snack_quota'];
        $allow_skip_sub = ! empty( $sub->allow_meal_skip ) && intval( $sub->allow_meal_skip ) === 1;

        $row_meals_quota  = $allowed_quota;
        $row_snacks_quota = $snack_quota;

        if ( $is_carryover_row && ! $is_juice ) {
            if ( $carryover_from_log_id > 0 ) {
                $parent_log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_logs} WHERE id = %d", $carryover_from_log_id ) );
                if ( $parent_log ) {
                    $p_alloc_meals  = ( ! empty( $parent_log->is_carryover ) && intval( $parent_log->carryover_meals_quota ) > 0 ) ? intval( $parent_log->carryover_meals_quota ) : $allowed_quota;
                    $p_alloc_snacks = ( ! empty( $parent_log->is_carryover ) ) ? intval( $parent_log->carryover_snacks_quota ) : $snack_quota;
                    $p_counts       = cmp_count_log_selected_meals_and_snacks( $parent_log, false, $p_alloc_meals, $p_alloc_snacks );
                    $calc_m         = max( 1, $p_alloc_meals - $p_counts['meals'] );
                    $calc_s         = max( 0, $p_alloc_snacks - $p_counts['snacks'] );

                    $row_meals_quota  = $calc_m;
                    $row_snacks_quota = $calc_s;
                } elseif ( $posted_alloc_meals > 0 ) {
                    $row_meals_quota  = $posted_alloc_meals;
                    $row_snacks_quota = max( 0, $posted_alloc_snacks );
                }
            } elseif ( $existing_log && intval( $existing_log->carryover_meals_quota ) > 0 ) {
                $row_meals_quota  = intval( $existing_log->carryover_meals_quota );
                $row_snacks_quota = intval( $existing_log->carryover_snacks_quota );
            } elseif ( $posted_alloc_meals > 0 ) {
                $row_meals_quota  = $posted_alloc_meals;
                $row_snacks_quota = max( 0, $posted_alloc_snacks );
            }
        }

        // --- STRICT SELECTION QUOTA & MEAL SKIPPING ENFORCEMENT (RULE A & RULE B) ---
        $submitted_count  = 0;
        $submitted_snacks = 0;

        if ( ! $is_chefs_choice && ! $is_menu_pending ) {
            if ( $is_juice ) {
                if ( $row_allow_custom ) {
                    if ( empty( trim( $_POST['custom_juice_1'] ?? '' ) ) || empty( trim( $_POST['custom_juice_2'] ?? '' ) ) || empty( trim( $_POST['custom_juice_3'] ?? '' ) ) ) {
                        wp_send_json_error( 'Please provide all 3 juices as per the subscription quota to save this day.' );
                    }
                } else {
                    if ( empty( $_POST['juice_1'] ) || empty( $_POST['juice_2'] ) || empty( $_POST['juice_3'] ) ) {
                        wp_send_json_error( 'Please select all 3 juices as per the subscription quota to save this day.' );
                    }
                }
            } else {
                if ( $row_allow_custom ) {
                    if ( ! empty( trim( $_POST['custom_breakfast'] ?? '' ) ) ) $submitted_count++;
                    if ( ! empty( trim( $_POST['custom_lunch'] ?? '' ) ) )     $submitted_count++;
                    if ( ! empty( trim( $_POST['custom_dinner'] ?? '' ) ) )    $submitted_count++;

                    if ( ! empty( trim( $_POST['custom_snack_1'] ?? '' ) ) )   $submitted_snacks++;
                    if ( ! empty( trim( $_POST['custom_snack_2'] ?? '' ) ) )   $submitted_snacks++;
                } else {
                    if ( ! empty( $_POST['breakfast'] ) ) $submitted_count++;
                    if ( ! empty( $_POST['lunch'] ) )     $submitted_count++;
                    if ( ! empty( $_POST['dinner'] ) )    $submitted_count++;

                    if ( ! empty( $_POST['snack_1'] ) )   $submitted_snacks++;
                    if ( ! empty( $_POST['snack_2'] ) )   $submitted_snacks++;
                }

                // 1. Must select at least 1 main meal
                if ( $submitted_count < 1 ) {
                    wp_send_json_error( 'Quota Requirement: Please select at least 1 main meal to save this day.' );
                }

                // 2. Cannot exceed row's allocated main meals or snacks
                if ( $submitted_count > $row_meals_quota ) {
                    wp_send_json_error( "Quota Requirement: You can select at most {$row_meals_quota} main meal(s) on this day." );
                }
                if ( $submitted_snacks > $row_snacks_quota ) {
                    wp_send_json_error( "Quota Requirement: You can select at most {$row_snacks_quota} snack(s) on this day." );
                }

                // 3. Check if customer/staff is skipping 1 or more main meals ($submitted_count < $row_meals_quota)
                if ( $submitted_count < $row_meals_quota ) {
                    // Check if this row was already saved with this exact reduced meal count previously
                    $existing_counts = $existing_log ? cmp_count_log_selected_meals_and_snacks( $existing_log, false, $row_meals_quota, $row_snacks_quota ) : array( 'meals' => 0, 'snacks' => 0 );
                    $was_already_skipped_same_count = ( $existing_log && $existing_counts['meals'] === $submitted_count );

                    if ( ! $allow_skip_sub && ! $was_already_skipped_same_count ) {
                        wp_send_json_error(
                            "Quota Requirement: Please select exactly {$row_meals_quota} main meal(s) and {$row_snacks_quota} snack(s) as allocated in this subscription. (To skip a meal and carry it forward, \"Allow Meal Skipping\" must be enabled by FOH)."
                        );
                    }
                    // Rule B: Because at least 1 main meal is being carried forward to a future Redemption Day,
                    // snacks are optional on this day (0 .. $row_snacks_quota) and any unselected snacks carry forward!
                } else {
                    // 4. Rule A: Selecting the full main meal allocation ($submitted_count === $row_meals_quota)
                    // Because 0 main meals are being skipped, NO future Redemption Day will be created from this row.
                    // Therefore, all allocated snacks ($row_snacks_quota) MUST be selected without skipping!
                    if ( $submitted_snacks !== $row_snacks_quota ) {
                        if ( $is_carryover_row ) {
                            wp_send_json_error(
                                "Redemption Quota Requirement: Please select all {$row_snacks_quota} carried-forward snack(s) along with your meal(s) to save this Redemption Day."
                            );
                        } else {
                            wp_send_json_error(
                                "Quota Requirement: When selecting your full meal quota ({$row_meals_quota} meal(s)), you must also select all {$row_snacks_quota} snack(s) to save this day."
                            );
                        }
                    }
                }
            }
        }

        // Determine Actor & Timestamp for Option 2B Row Audit Stamp + Option 4B Activity Log
        $actor = function_exists( 'cmp_get_current_actor_info' )
            ? cmp_get_current_actor_info( $sub->user_id )
            : array(
                'id'   => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
                'role' => current_user_can( 'manage_options' ) ? 'Admin' : 'Customer',
            );

        $tz           = new DateTimeZone( 'Asia/Dubai' );
        $now_datetime = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );
        $row_action   = ( $log_id > 0 ) ? 'Updated' : 'Selected';

        $data = array(
            'user_id'                => $sub->user_id,
            'subscription_id'        => $sub_id,
            'target_date'            => $target_date,
            'is_chefs_choice'        => $is_chefs_choice,
            'is_locked'              => 1,
            'plan_mode'              => $plan_mode,
            'is_carryover'           => $is_carryover_row,
            'carryover_from_log_id'  => $is_carryover_row ? $carryover_from_log_id : 0,
            'carryover_meals_quota'  => $is_carryover_row ? $row_meals_quota : 0,
            'carryover_snacks_quota' => $is_carryover_row ? $row_snacks_quota : 0,
            'updated_by_id'          => $actor['id'],
            'updated_by_name'        => $actor['name'],
            'updated_by_role'        => $actor['role'],
            'last_action_type'       => $row_action,
            'updated_at'             => $now_datetime,
        );

        if ( $row_allow_custom ) {
            $data['custom_breakfast'] = ! empty( $_POST['custom_breakfast'] ) ? sanitize_text_field( $_POST['custom_breakfast'] ) : null;
            $data['custom_lunch']     = ! empty( $_POST['custom_lunch'] ) ? sanitize_text_field( $_POST['custom_lunch'] ) : null;
            $data['custom_dinner']    = ! empty( $_POST['custom_dinner'] ) ? sanitize_text_field( $_POST['custom_dinner'] ) : null;
            $data['custom_snack_1']   = ! empty( $_POST['custom_snack_1'] ) ? sanitize_text_field( $_POST['custom_snack_1'] ) : null;
            $data['custom_snack_2']   = ! empty( $_POST['custom_snack_2'] ) ? sanitize_text_field( $_POST['custom_snack_2'] ) : null;
            $data['custom_juice_1']   = ! empty( $_POST['custom_juice_1'] ) ? sanitize_text_field( $_POST['custom_juice_1'] ) : null;
            $data['custom_juice_2']   = ! empty( $_POST['custom_juice_2'] ) ? sanitize_text_field( $_POST['custom_juice_2'] ) : null;
            $data['custom_juice_3']   = ! empty( $_POST['custom_juice_3'] ) ? sanitize_text_field( $_POST['custom_juice_3'] ) : null;

            $data['breakfast_id'] = null;
            $data['lunch_id']     = null;
            $data['dinner_id']    = null;
            $data['snack_1_id']   = null;
            $data['snack_2_id']   = null;
            $data['juice_1_id']   = null;
            $data['juice_2_id']   = null;
            $data['juice_3_id']   = null;
        } else {
            $data['breakfast_id'] = ! empty( $_POST['breakfast'] ) ? intval( $_POST['breakfast'] ) : null;
            $data['lunch_id']     = ! empty( $_POST['lunch'] ) ? intval( $_POST['lunch'] ) : null;
            $data['dinner_id']    = ! empty( $_POST['dinner'] ) ? intval( $_POST['dinner'] ) : null;
            $data['snack_1_id']   = ! empty( $_POST['snack_1'] ) ? intval( $_POST['snack_1'] ) : null;
            $data['snack_2_id']   = ! empty( $_POST['snack_2'] ) ? intval( $_POST['snack_2'] ) : null;
            $data['juice_1_id']   = ! empty( $_POST['juice_1'] ) ? intval( $_POST['juice_1'] ) : null;
            $data['juice_2_id']   = ! empty( $_POST['juice_2'] ) ? intval( $_POST['juice_2'] ) : null;
            $data['juice_3_id']   = ! empty( $_POST['juice_3'] ) ? intval( $_POST['juice_3'] ) : null;

            $data['custom_breakfast'] = null;
            $data['custom_lunch']     = null;
            $data['custom_dinner']    = null;
            $data['custom_snack_1']   = null;
            $data['custom_snack_2']   = null;
            $data['custom_juice_1']   = null;
            $data['custom_juice_2']   = null;
            $data['custom_juice_3']   = null;
        }

        if ( $log_id > 0 ) {
            $wpdb->update( $table_logs, $data, array( 'id' => $log_id ) );
            $saved_log_id = $log_id;
        } else {
            $wpdb->insert( $table_logs, $data );
            $saved_log_id = intval( $wpdb->insert_id );
        }

        // Synchronize Carryover Redemption Days & check if a new Redemption Day was spawned or updated
        $sync_res        = cmp_sync_subscription_carryover_state( $sub_id );
        $skipped_now     = ( ! $is_juice && ! $is_chefs_choice && ! $is_menu_pending && $submitted_count > 0 && $submitted_count < $row_meals_quota );
        $reload_required = ( $sync_res['changed'] || $skipped_now ) ? 1 : 0;

        // Build New Status Snapshot & Record in Meal Portal > Customer Logs
        $new_snapshot = function_exists( 'cmp_format_log_meal_snapshot' )
            ? cmp_format_log_meal_snapshot( $data )
            : 'Selected (' . $target_date . ')';

        if ( $is_carryover_row ) {
            $new_snapshot .= ' [Carryover Redemption Day]';
        } elseif ( $skipped_now ) {
            $carried_m = $row_meals_quota - $submitted_count;
            $carried_s = max( 0, $row_snacks_quota - $submitted_snacks );
            $new_snapshot .= sprintf( ' [Skipped %d Meal(s) & %d Snack(s) -> Carried Forward]', $carried_m, $carried_s );
        }

        if ( function_exists( 'cmp_record_customer_activity_log' ) ) {
            $act_label = ( $log_id > 0 ) ? 'Meal Updated' : 'Meal Selected';
            if ( $skipped_now ) {
                $act_label = 'Meal Skipped (Carryover Created)';
            } elseif ( $is_carryover_row ) {
                $act_label = 'Carryover Meal Redeemed';
            }
            cmp_record_customer_activity_log( array(
                'subscription_id' => $sub_id,
                'action_type'     => $act_label,
                'target_date'     => $target_date,
                'old_status'      => $old_snapshot,
                'new_status'      => $new_snapshot,
                'actor_id'        => $actor['id'],
                'actor_name'      => $actor['name'],
                'actor_role'      => $actor['role'],
            ) );
        }

        // If Kitchen Staff assigned Chef's Choice meals via Chef Override Mode, also log in Kitchen Logs
        if ( $actor['role'] === 'Kitchen' && function_exists( 'cmp_record_kitchen_activity_log' ) ) {
            cmp_record_kitchen_activity_log( array(
                'log_id'          => $saved_log_id,
                'subscription_id' => $sub_id,
                'user_id'         => intval( $sub->user_id ),
                'target_date'     => $target_date,
                'action_type'     => "Chef's Choice Assigned",
                'old_status'      => $old_snapshot,
                'new_status'      => $new_snapshot,
                'actor_id'        => $actor['id'],
                'actor_name'      => $actor['name'],
                'actor_role'      => $actor['role'],
            ) );
        }

        $audit_stamp = cmp_format_row_audit_stamp( $row_action, $actor['name'], $actor['role'], $now_datetime );

        $carryover_notice = '';
        if ( $skipped_now ) {
            $carried_m        = $row_meals_quota - $submitted_count;
            $carried_s        = max( 0, $row_snacks_quota - $submitted_snacks );
            $carryover_notice = sprintf(
                'Saved! %d skipped meal(s) and %d snack(s) have been carried forward to a Redemption Day at the end of your schedule.',
                $carried_m,
                $carried_s
            );
        }

        $response_data = array(
            'msg'              => ( $log_id > 0 ) ? 'Updated' : 'Saved',
            'new_log_id'       => $saved_log_id,
            'pos_updated'      => $pos_updated,
            'audit_stamp'      => cmp_can_view_staff_audit_log() ? $audit_stamp : '',
            'reload_required'  => $reload_required,
            'carryover_notice' => $carryover_notice,
        );

        $updated_subs_queue = get_option( 'cmp_daily_updated_subs', array() );
        if ( ! is_array( $updated_subs_queue ) ) {
            $updated_subs_queue = array();
        }

        if ( ! in_array( $sub_id, $updated_subs_queue, true ) ) {
            $updated_subs_queue[] = $sub_id;
            update_option( 'cmp_daily_updated_subs', $updated_subs_queue );
        }

        wp_send_json_success( $response_data );
    }
}

// 1.2 CUSTOMER PORTAL PER-PLAN LOGISTICS & STAFF KITCHEN PREP SCHEDULE EDITING
add_action( 'wp_ajax_cmp_cp_update_customer_logistics', 'cmp_ajax_cp_update_customer_logistics' );
if ( ! function_exists( 'cmp_ajax_cp_update_customer_logistics' ) ) {
    function cmp_ajax_cp_update_customer_logistics() {
        check_ajax_referer( 'cmp_portal_nonce', 'nonce' );

        if ( ! cmp_can_view_pos_and_edit_logistics() ) {
            wp_send_json_error( 'Permission Denied' );
        }

        cmp_ensure_subscription_logistics_columns();
        cmp_ensure_prep_timing_column();

        global $wpdb;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';

        $user_id            = intval( $_POST['user_id'] ?? 0 );
        $order_id           = intval( $_POST['order_id'] ?? 0 );
        $sub_id             = isset( $_POST['sub_id'] ) ? intval( $_POST['sub_id'] ) : 0;
        $method             = sanitize_text_field( wp_unslash( $_POST['delivery_method'] ?? '' ) );
        $pickup             = sanitize_text_field( wp_unslash( $_POST['pickup_location'] ?? '' ) );
        $timing             = sanitize_text_field( wp_unslash( $_POST['delivery_timing'] ?? '' ) );
        $time_slot          = sanitize_text_field( wp_unslash( $_POST['time_slot'] ?? '' ) );
        $allergies          = sanitize_textarea_field( wp_unslash( $_POST['allergies'] ?? '' ) );
        $save_custom_timing = ! empty( $_POST['save_custom_timing'] );
        $save_custom_slot   = ! empty( $_POST['save_custom_slot'] );

        // Option PREP-C: Staff-only Kitchen Prep Schedule override ('auto', 'same_day', 'day_before')
        $has_prep_payload = isset( $_POST['prep_timing'] );
        $prep_timing      = sanitize_text_field( wp_unslash( $_POST['prep_timing'] ?? 'auto' ) );
        if ( ! in_array( $prep_timing, array( 'auto', 'same_day', 'day_before' ), true ) ) {
            $prep_timing = 'auto';
        }

        if ( empty( $timing ) ) {
            wp_send_json_error( 'Please provide a valid Delivery Timing (Receive By) option.' );
        }
        if ( empty( $time_slot ) ) {
            wp_send_json_error( 'Please provide a valid Time Slot option.' );
        }

        if ( $save_custom_timing ) {
            $saved_timings = get_option( 'cmp_custom_delivery_timings', array() );
            if ( ! is_array( $saved_timings ) ) {
                $saved_timings = array();
            }
            $default_timings = array( 'Deliver Day Before', 'Deliver Same Day' );
            if ( ! in_array( $timing, $default_timings, true ) && ! in_array( $timing, $saved_timings, true ) ) {
                $saved_timings[] = $timing;
                update_option( 'cmp_custom_delivery_timings', array_values( array_unique( $saved_timings ) ) );
            }
        }

        if ( $save_custom_slot ) {
            $saved_slots = get_option( 'cmp_custom_time_slots', array() );
            if ( ! is_array( $saved_slots ) ) {
                $saved_slots = array();
            }
            $default_slots = array(
                '5:00 AM to 6:00 AM',
                '6:00 AM to 7:00 AM',
                '7:00 AM to 8:00 AM',
                '8:00 AM to 9:00 AM',
                '5:00 PM to 8:00 PM',
            );
            if ( ! in_array( $time_slot, $default_slots, true ) && ! in_array( $time_slot, $saved_slots, true ) ) {
                $saved_slots[] = $time_slot;
                update_option( 'cmp_custom_time_slots', array_values( array_unique( $saved_slots ) ) );
            }
        }

        $sub_row           = null;
        $plan_name         = '';
        $old_resolved      = array();
        $old_prep_mode     = 'auto';
        $prep_schedule_lbl = 'Day-Before Prep';
        $is_same_day_prep  = false;

        if ( $sub_id > 0 ) {
            $sub_row       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", $sub_id ) );
            $plan_name     = $sub_row ? $sub_row->plan_name : '';
            $old_resolved  = $sub_row ? cmp_get_subscription_logistics( $sub_row ) : array();
            $old_prep_mode = ( $sub_row && ! empty( $sub_row->prep_timing ) ) ? $sub_row->prep_timing : 'auto';

            $update_cols = array(
                'delivery_timing' => $timing,
                'time_slot'       => $time_slot,
                'allergies'       => $allergies !== '' ? $allergies : '__NONE__',
            );
            if ( ! empty( $method ) ) {
                $update_cols['delivery_method'] = $method;
                $update_cols['pickup_location'] = ( $method === 'Pickup' ) ? $pickup : '';
            }
            if ( $has_prep_payload ) {
                $update_cols['prep_timing'] = $prep_timing;
            }

            $wpdb->update(
                $table_subs,
                $update_cols,
                array( 'id' => $sub_id )
            );
        }

        $updated_sub       = $sub_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", $sub_id ) ) : null;
        $updated_resolved  = $updated_sub ? cmp_get_subscription_logistics( $updated_sub ) : array();
        $is_same_day_prep  = $updated_sub ? cmp_is_same_day_prep_subscription( $updated_sub, $updated_resolved ) : false;
        $prep_schedule_lbl = $updated_sub ? cmp_get_prep_schedule_display_label( $updated_sub, $updated_resolved ) : 'Day-Before Prep';

        $effective_method = ! empty( $method ) ? $method : ( $sub_row ? ( $sub_row->delivery_method ?? '' ) : '' );
        $effective_pickup = ( $effective_method === 'Pickup' ) ? ( ! empty( $method ) ? $pickup : ( $sub_row ? ( $sub_row->pickup_location ?? '' ) : '' ) ) : '';
        $method_display   = $effective_method ?: 'N/A';
        if ( $effective_method === 'Pickup' && ! empty( $effective_pickup ) ) {
            $method_display .= ' (' . $effective_pickup . ')';
        }

        $prep_labels = array(
            'auto'       => 'Auto (4PM+ Rule)',
            'same_day'   => 'Same-Day Prep',
            'day_before' => 'Day-Before Prep',
        );

        if ( $sub_id > 0 && function_exists( 'cmp_record_customer_activity_log' ) ) {
            $old_log_str = sprintf(
                'Method: %s | Receive: %s (%s) | Prep: %s | Allergies: %s',
                $old_resolved['method'] ?? 'N/A',
                $old_resolved['timing'] ?? 'N/A',
                $old_resolved['time_slot'] ?? 'N/A',
                $prep_labels[ $old_prep_mode ] ?? $old_prep_mode,
                ! empty( $old_resolved['allergies'] ) ? $old_resolved['allergies'] : 'None'
            );
            $new_log_str = sprintf(
                'Method: %s | Receive: %s (%s) | Prep: %s | Allergies: %s',
                $method_display,
                $timing,
                $time_slot,
                $prep_labels[ $has_prep_payload ? $prep_timing : $old_prep_mode ] ?? $prep_timing,
                $allergies !== '' ? $allergies : 'None'
            );
            if ( $old_log_str !== $new_log_str ) {
                cmp_record_customer_activity_log( array(
                    'subscription_id' => $sub_id,
                    'action_type'     => 'Logistics Updated',
                    'old_status'      => $old_log_str,
                    'new_status'      => $new_log_str,
                ) );
            }
        }

        if ( $sub_id > 0 && $has_prep_payload && $old_prep_mode !== $prep_timing && function_exists( 'cmp_record_kitchen_activity_log' ) ) {
            cmp_record_kitchen_activity_log( array(
                'log_id'          => 0,
                'subscription_id' => $sub_id,
                'user_id'         => $user_id,
                'target_date'     => date( 'Y-m-d' ),
                'action_type'     => 'Prep Schedule Override (Portal)',
                'old_status'      => 'Prep Schedule: ' . ( $prep_labels[ $old_prep_mode ] ?? $old_prep_mode ),
                'new_status'      => 'Prep Schedule: ' . ( $prep_labels[ $prep_timing ] ?? $prep_timing ),
            ) );
        }

        if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
            $order_sub_count = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_subs} WHERE wc_order_id = %d", $order_id ) ) );
            if ( $order_sub_count <= 1 ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    if ( ! empty( $effective_method ) ) {
                        $order->update_meta_data( 'delivery_method', $effective_method );
                        $order->update_meta_data( '_cmp_logistics_method', $effective_method );
                        $order->update_meta_data( 'pickup_location', $effective_pickup );
                        $order->update_meta_data( '_cmp_pickup_location', $effective_pickup );
                    }
                    $order->update_meta_data( 'delivery_timing', $timing );
                    $order->update_meta_data( '_cmp_delivery_timing', $timing );
                    $order->update_meta_data( 'time_slot', $time_slot );
                    $order->update_meta_data( '_cmp_time_slot', $time_slot );
                    $order->update_meta_data( 'allergies', $allergies );
                    $order->set_customer_note( $allergies );
                    $order->save();
                }
            }
        }

        if ( $user_id > 0 ) {
            $active_user_subs = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active','paused')", $user_id ) ) );
            $should_update_global_usermeta = ( $active_user_subs <= 1 );

            cmp_save_user_logistics_profile_history(
                $user_id,
                $sub_id,
                array(
                    'plan_name' => $plan_name,
                    'method'    => $effective_method,
                    'pickup'    => $effective_pickup,
                    'timing'    => $timing,
                    'time_slot' => $time_slot,
                    'allergies' => $allergies,
                    'address'   => $sub_row ? ( $sub_row->delivery_address ?? '' ) : '',
                ),
                $should_update_global_usermeta
            );
        }

        wp_send_json_success( array(
            'sub_id'              => $sub_id,
            'method_display'      => $method_display,
            'timing'              => $timing,
            'time_slot'           => $time_slot,
            'allergies'           => $allergies ?: 'No Allergies Recorded',
            'prep_timing'         => $prep_timing,
            'prep_schedule_label' => $prep_schedule_lbl,
            'is_same_day_prep'    => $is_same_day_prep ? 1 : 0,
            'saved_profiles'      => cmp_get_user_saved_logistics_profiles( $user_id ),
        ) );
    }
}

// 1.3 CUSTOMER PORTAL STAFF ACCOUNT DETAILS & "ALLOW MEAL SKIPPING" EDITING
//     (Accessible to FOH, Menu Manager, and Admin — Hidden from Customers)
add_action( 'wp_ajax_cmp_cp_admin_update_account_details', 'cmp_ajax_cp_admin_update_account_details' );
if ( ! function_exists( 'cmp_ajax_cp_admin_update_account_details' ) ) {
    function cmp_ajax_cp_admin_update_account_details() {
        check_ajax_referer( 'cmp_portal_nonce', 'nonce' );

        if ( ! cmp_can_manage_meal_skipping() ) {
            wp_send_json_error( 'Permission Denied. Staff clearance required.' );
        }

        cmp_ensure_subscription_logistics_columns();
        cmp_ensure_meal_skip_carryover_columns();

        global $wpdb;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';

        $user_id  = intval( $_POST['user_id'] ?? 0 );
        $order_id = intval( $_POST['order_id'] ?? 0 );
        $sub_id   = isset( $_POST['sub_id'] ) ? intval( $_POST['sub_id'] ) : 0;

        $first_name      = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name       = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $address         = sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) );
        $recipient_name  = sanitize_text_field( wp_unslash( $_POST['recipient_name'] ?? '' ) );
        $has_skip_param  = isset( $_POST['allow_meal_skip'] );
        $allow_meal_skip = ( ! empty( $_POST['allow_meal_skip'] ) && intval( $_POST['allow_meal_skip'] ) === 1 ) ? 1 : 0;

        if ( $user_id > 0 ) {
            wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ) );
            update_user_meta( $user_id, 'billing_first_name', $first_name );
            update_user_meta( $user_id, 'billing_last_name', $last_name );
            update_user_meta( $user_id, 'billing_address_1', $address );
            update_user_meta( $user_id, 'billing_address_2', '' );
            update_user_meta( $user_id, 'billing_city', '' );
        }

        if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
            $order_sub_count = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_subs} WHERE wc_order_id = %d", $order_id ) ) );
            if ( $order_sub_count <= 1 ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $order->set_billing_first_name( $first_name );
                    $order->set_billing_last_name( $last_name );
                    $order->set_shipping_first_name( $first_name );
                    $order->set_shipping_last_name( $last_name );
                    $order->set_billing_address_1( $address );
                    $order->set_shipping_address_1( $address );
                    $order->save();
                }
            }
        }

        $new_plan_name = '';
        $skip_changed  = false;

        if ( $sub_id > 0 ) {
            $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_subs} WHERE id = %d", $sub_id ) );
            if ( $sub ) {
                $old_resolved = cmp_get_subscription_logistics( $sub );
                $old_skip     = ! empty( $sub->allow_meal_skip ) ? 1 : 0;

                $plan_parts          = explode( ' - ', $sub->plan_name );
                $potential_recipient = end( $plan_parts );
                if ( ! preg_match( '/(Meal|Day|Cleanse|Plans)/i', $potential_recipient ) && count( $plan_parts ) > 1 ) {
                    array_pop( $plan_parts );
                    $core_plan_name = implode( ' - ', $plan_parts );
                } else {
                    $core_plan_name = $sub->plan_name;
                }

                $new_plan_name = $core_plan_name;
                if ( ! empty( $recipient_name ) ) {
                    $new_plan_name .= ' - ' . $recipient_name;
                }

                $update_cols = array(
                    'plan_name'        => $new_plan_name,
                    'delivery_address' => $address,
                );
                if ( $has_skip_param ) {
                    $update_cols['allow_meal_skip'] = $allow_meal_skip;
                    $skip_changed                   = ( $old_skip !== $allow_meal_skip );
                }

                $wpdb->update( $table_subs, $update_cols, array( 'id' => $sub_id ) );

                if ( function_exists( 'cmp_record_customer_activity_log' ) ) {
                    $old_skip_lbl = $old_skip ? 'Allowed' : 'Disabled';
                    $new_skip_lbl = ( $has_skip_param ? $allow_meal_skip : $old_skip ) ? 'Allowed' : 'Disabled';

                    $old_summary = 'Name: ' . ( $old_resolved['full_name'] ?? '' ) . ' | Plan: ' . $sub->plan_name . ' | Address: ' . ( $old_resolved['address'] ?? '' ) . ' | Meal Skipping: ' . $old_skip_lbl;
                    $new_summary = 'Name: ' . trim( $first_name . ' ' . $last_name ) . ' | Plan: ' . $new_plan_name . ' | Address: ' . $address . ' | Meal Skipping: ' . $new_skip_lbl;

                    if ( $old_summary !== $new_summary ) {
                        cmp_record_customer_activity_log( array(
                            'subscription_id' => $sub_id,
                            'action_type'     => $skip_changed ? ( $allow_meal_skip ? 'Meal Skipping Enabled' : 'Meal Skipping Disabled' ) : 'Account Details Updated',
                            'old_status'      => $old_summary,
                            'new_status'      => $new_summary,
                        ) );
                    }
                }

                if ( $user_id > 0 ) {
                    $resolved = cmp_get_subscription_logistics( $sub );
                    cmp_save_user_logistics_profile_history(
                        $user_id,
                        $sub_id,
                        array(
                            'plan_name' => $new_plan_name,
                            'method'    => $resolved['method'],
                            'pickup'    => $resolved['pickup'],
                            'timing'    => $resolved['timing'],
                            'time_slot' => $resolved['time_slot'],
                            'allergies' => $resolved['allergies'],
                            'address'   => $address,
                        ),
                        true
                    );
                }
            }
        }

        wp_send_json_success( array(
            'name'            => trim( $first_name . ' ' . $last_name ),
            'address'         => $address ?: 'Address not provided',
            'recipient'       => $recipient_name,
            'plan_name'       => $new_plan_name,
            'allow_meal_skip' => $allow_meal_skip,
        ) );
    }
}

// 1.4 ADD / REMOVE PLAN DAYS (GOVERNED BY WP ADMIN SETTINGS)
add_action( 'wp_ajax_cmp_cp_admin_adjust_days', 'cmp_ajax_cp_admin_adjust_days' );
if ( ! function_exists( 'cmp_ajax_cp_admin_adjust_days' ) ) {
    function cmp_ajax_cp_admin_adjust_days() {
        check_ajax_referer( 'cmp_portal_nonce', 'nonce' );
        if ( ! cmp_can_adjust_plan_days() ) {
            wp_send_json_error( 'Permission Denied. Your role is not authorized to add or remove plan days.' );
        }

        $sub_id    = intval( $_POST['sub_id'] );
        $operation = sanitize_text_field( $_POST['operation'] );

        global $wpdb;
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';
        $sub        = $wpdb->get_row( $wpdb->prepare( "SELECT total_days, expiry_date FROM $table_subs WHERE id = %d", $sub_id ) );

        if ( ! $sub ) {
            wp_send_json_error( 'Subscription not found.' );
        }

        $old_days       = intval( $sub->total_days );
        $new_days       = $old_days;
        $current_expiry = new DateTime( $sub->expiry_date );

        if ( $operation === 'add' ) {
            $new_days++;
            $current_expiry->modify( '+1 day' );
        } elseif ( $operation === 'remove' ) {
            if ( $new_days <= 1 ) {
                wp_send_json_error( 'Cannot reduce plan below 1 day.' );
            }
            $new_days--;
            $current_expiry->modify( '-1 day' );
        } else {
            wp_send_json_error( 'Invalid operation.' );
        }

        $wpdb->update(
            $table_subs,
            array(
                'total_days'  => $new_days,
                'expiry_date' => $current_expiry->format( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => $sub_id )
        );

        if ( function_exists( 'cmp_record_customer_activity_log' ) ) {
            cmp_record_customer_activity_log( array(
                'subscription_id' => $sub_id,
                'action_type'     => ( $operation === 'add' ) ? 'Extra Day Added' : 'Plan Day Removed',
                'old_status'      => 'Total Quota: ' . $old_days . ' Days',
                'new_status'      => 'Total Quota: ' . $new_days . ' Days',
            ) );
        }

        wp_send_json_success( array( 'new_days' => $new_days ) );
    }
}

// 1.5 CLEAR / RESET DAILY SELECTION (INCLUDES OPTION 3A: ADMIN-ONLY CANCELLATION RESET & CARRYOVER SYNC)
add_action( 'wp_ajax_cmp_clear_daily_log', 'cmp_ajax_clear_daily_log' );
if ( ! function_exists( 'cmp_ajax_clear_daily_log' ) ) {
    function cmp_ajax_clear_daily_log() {
        check_ajax_referer( 'cmp_portal_nonce', 'nonce' );
        global $wpdb;

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        $log_id           = intval( $_POST['log_id'] );
        $sub_id           = intval( $_POST['sub_id'] );
        $is_override_mode = ! empty( $_POST['is_override_mode'] ) ? intval( $_POST['is_override_mode'] ) : 0;

        $table_logs = $wpdb->prefix . 'cmp_daily_logs';
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';

        $log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_logs WHERE id = %d", $log_id ) );
        if ( ! $log ) {
            wp_send_json_error( 'Selection not found.' );
        }

        $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_subs WHERE id = %d", $sub_id ) );
        if ( ! $sub ) {
            wp_send_json_error( 'Subscription not found.' );
        }

        $is_cancelled_row = in_array( $log->delivery_result, array( 'Cancelled', 'Returned' ), true );

        // OPTION 3A: Strictly allow ONLY Super Admins (`manage_options`) to reset/clear a Cancelled row
        if ( $is_cancelled_row ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Permission Denied: Only Administrators can reset a cancelled meal selection.' );
            }
        } else {
            $can_bypass_dates = cmp_can_bypass_date_restrictions() || ( current_user_can( 'kitchen_staff' ) && $is_override_mode === 1 );

            if ( ! $can_bypass_dates && intval( $sub->user_id ) !== get_current_user_id() ) {
                wp_send_json_error( 'Unauthorized access to this subscription.' );
            }

            if ( ! $can_bypass_dates ) {
                if ( cmp_is_date_locked_for_customer( $log->target_date ) || $log->delivery_result === 'Successful' ) {
                    wp_send_json_error( 'Past dates, today\'s date, and dates past the daily cut-off time cannot be reset.' );
                }
            }
        }

        $old_snapshot = function_exists( 'cmp_format_log_meal_snapshot' )
            ? cmp_format_log_meal_snapshot( $log )
            : ( $is_cancelled_row ? 'Cancelled (' . $log->target_date . ')' : 'Selected (' . $log->target_date . ')' );

        $wpdb->delete( $table_logs, array( 'id' => $log_id ) );

        // Synchronize Carryover Redemption Days after clearing
        cmp_sync_subscription_carryover_state( $sub_id );

        if ( function_exists( 'cmp_record_customer_activity_log' ) ) {
            cmp_record_customer_activity_log( array(
                'subscription_id' => $sub_id,
                'action_type'     => $is_cancelled_row ? 'Cancellation Reset' : 'Meal Reset',
                'target_date'     => $log->target_date,
                'old_status'      => $old_snapshot,
                'new_status'      => $is_cancelled_row
                    ? 'Pending Selection (Cancellation Cleared & Extra Day Reversed)'
                    : 'Pending Selection (Cleared)',
            ) );
        }

        $updated_subs_queue = get_option( 'cmp_daily_updated_subs', array() );
        if ( ! is_array( $updated_subs_queue ) ) {
            $updated_subs_queue = array();
        }
        if ( ! in_array( $sub_id, $updated_subs_queue, true ) ) {
            $updated_subs_queue[] = $sub_id;
            update_option( 'cmp_daily_updated_subs', $updated_subs_queue );
        }

        wp_send_json_success( array( 'msg' => $is_cancelled_row ? 'Cancellation Reset' : 'Cleared' ) );
    }
}

// 1.6 CUSTOMER / FOH CANCEL SPECIFIC DAY (STAMPS ACTOR, PRE-PREP / POST-PREP CONTEXT & CARRYOVER SYNC)
add_action( 'wp_ajax_cmp_cancel_daily_log', 'cmp_ajax_cancel_daily_log' );
if ( ! function_exists( 'cmp_ajax_cancel_daily_log' ) ) {
    function cmp_ajax_cancel_daily_log() {
        check_ajax_referer( 'cmp_portal_nonce', 'nonce' );
        global $wpdb;

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        if ( function_exists( 'cmp_auto_patch_database' ) ) {
            cmp_auto_patch_database();
        }

        $log_id           = intval( $_POST['log_id'] );
        $sub_id           = intval( $_POST['sub_id'] );
        $is_override_mode = ! empty( $_POST['is_override_mode'] ) ? intval( $_POST['is_override_mode'] ) : 0;

        $table_logs = $wpdb->prefix . 'cmp_daily_logs';
        $table_subs = $wpdb->prefix . 'cmp_subscriptions';

        $log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_logs WHERE id = %d", $log_id ) );
        if ( ! $log ) {
            wp_send_json_error( 'Selection not found.' );
        }

        $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_subs WHERE id = %d", $sub_id ) );
        if ( ! $sub ) {
            wp_send_json_error( 'Subscription not found.' );
        }

        $can_bypass_dates = cmp_can_bypass_date_restrictions() || ( current_user_can( 'kitchen_staff' ) && $is_override_mode === 1 );

        if ( ! $can_bypass_dates && intval( $sub->user_id ) !== get_current_user_id() ) {
            wp_send_json_error( 'Unauthorized access to this subscription.' );
        }

        if ( ! $can_bypass_dates ) {
            if ( cmp_is_date_locked_for_customer( $log->target_date ) || $log->delivery_result === 'Successful' ) {
                wp_send_json_error( 'Past dates, today\'s date, and dates past the daily cut-off time cannot be cancelled.' );
            }
        }

        $old_snapshot = function_exists( 'cmp_format_log_meal_snapshot' )
            ? cmp_format_log_meal_snapshot( $log )
            : 'Selected (' . $log->target_date . ')';

        $actor = function_exists( 'cmp_get_current_actor_info' )
            ? cmp_get_current_actor_info( $sub->user_id )
            : array(
                'id'   => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
                'role' => current_user_can( 'manage_options' ) ? 'Admin' : 'Customer',
            );

        $tz           = new DateTimeZone( 'Asia/Dubai' );
        $now_datetime = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        $was_prepped  = ( intval( $log->is_prepared ) === 1 || intval( $log->dispatch_status ) === 1 );
        $cancel_stage = $was_prepped ? 'Cancelled After Prep' : 'Cancelled Before Prep (DO NOT PREPARE)';

        $wpdb->update(
            $table_logs,
            array(
                'delivery_result'  => 'Cancelled',
                'pos_updated'      => 0,
                'updated_by_id'    => $actor['id'],
                'updated_by_name'  => $actor['name'],
                'updated_by_role'  => $actor['role'],
                'last_action_type' => 'Cancelled',
                'updated_at'       => $now_datetime,
            ),
            array( 'id' => $log_id )
        );

        // Synchronize Carryover Redemption Days after cancelling
        cmp_sync_subscription_carryover_state( $sub_id );

        if ( function_exists( 'cmp_record_customer_activity_log' ) ) {
            cmp_record_customer_activity_log( array(
                'subscription_id' => $sub_id,
                'action_type'     => 'Meal Cancelled',
                'target_date'     => $log->target_date,
                'old_status'      => $old_snapshot,
                'new_status'      => $cancel_stage . ' (' . $log->target_date . ')',
                'actor_id'        => $actor['id'],
                'actor_name'      => $actor['name'],
                'actor_role'      => $actor['role'],
            ) );
        }

        $updated_subs_queue = get_option( 'cmp_daily_updated_subs', array() );
        if ( ! is_array( $updated_subs_queue ) ) {
            $updated_subs_queue = array();
        }
        if ( ! in_array( $sub_id, $updated_subs_queue, true ) ) {
            $updated_subs_queue[] = $sub_id;
            update_option( 'cmp_daily_updated_subs', $updated_subs_queue );
        }

        wp_send_json_success( array( 'msg' => 'Day Cancelled' ) );
    }
}

// 1.7 SUBMIT CUSTOMER EXPERIENCE FEEDBACK SURVEY (OPTION 4A)
add_action( 'wp_ajax_cmp_submit_customer_feedback', 'cmp_ajax_submit_customer_feedback' );
if ( ! function_exists( 'cmp_ajax_submit_customer_feedback' ) ) {
    function cmp_ajax_submit_customer_feedback() {
        check_ajax_referer( 'cmp_portal_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Please sign in to submit feedback.' );
        }

        cmp_ensure_feedback_table_exists();
        global $wpdb;

        $sub_id          = isset( $_POST['sub_id'] ) ? intval( $_POST['sub_id'] ) : 0;
        $service_rating  = isset( $_POST['service_rating'] ) ? intval( $_POST['service_rating'] ) : 0;
        $portal_rating   = isset( $_POST['portal_rating'] ) ? intval( $_POST['portal_rating'] ) : 0;
        $include_details = isset( $_POST['include_details'] ) && intval( $_POST['include_details'] ) === 1 ? 1 : 0;
        $quick_tags      = isset( $_POST['quick_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['quick_tags'] ) ) : '';
        $comments        = isset( $_POST['comments'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comments'] ) ) : '';

        if ( $service_rating < 1 && $portal_rating < 1 ) {
            wp_send_json_error( 'Please select a rating for your Meal Plan Service or Online Portal experience.' );
        }

        $table_subs     = $wpdb->prefix . 'cmp_subscriptions';
        $table_feedback = $wpdb->prefix . 'cmp_feedback';
        $sub            = $sub_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_subs WHERE id = %d", $sub_id ) ) : null;

        $is_anonymous   = $include_details ? 0 : 1;
        $user_id        = 0;
        $wc_order_id    = 0;
        $customer_name  = 'Anonymous Customer';
        $customer_email = '';
        $customer_phone = '';
        $plan_name      = $sub ? $sub->plan_name : '';

        if ( $include_details ) {
            $target_uid  = $sub ? intval( $sub->user_id ) : get_current_user_id();
            $user_id     = $target_uid;
            $wc_order_id = $sub ? intval( $sub->wc_order_id ) : 0;

            if ( $sub ) {
                $resolved       = cmp_get_subscription_logistics( $sub );
                $customer_name  = $resolved['full_name'];
                $customer_email = $resolved['email'];
                $customer_phone = $resolved['phone'];
            } else {
                $u_obj          = get_userdata( $target_uid );
                $customer_name  = $u_obj ? $u_obj->display_name : 'Customer';
                $customer_email = $u_obj ? $u_obj->user_email : '';
                $customer_phone = get_user_meta( $target_uid, 'billing_phone', true );
            }
        }

        $wpdb->insert(
            $table_feedback,
            array(
                'user_id'         => $user_id,
                'subscription_id' => $include_details ? $sub_id : 0,
                'wc_order_id'     => $wc_order_id,
                'is_anonymous'    => $is_anonymous,
                'customer_name'   => $customer_name,
                'customer_email'  => $customer_email,
                'customer_phone'  => $customer_phone,
                'plan_name'       => $plan_name,
                'service_rating'  => $service_rating,
                'portal_rating'   => $portal_rating,
                'quick_tags'      => $quick_tags,
                'comments'        => $comments,
                'created_at'      => current_time( 'mysql' ),
            )
        );

        wp_send_json_success( array(
            'msg' => 'Thank you! Your feedback has been received.',
        ) );
    }
}

// ==========================================
// 2. EXPORT CUSTOMER MEAL PLAN TO CSV (PER-PLAN LOGISTICS, CARRYOVER & POS EXEMPTIONS)
// ==========================================
if ( ! function_exists( 'cmp_export_customer_csv' ) ) {
    add_action( 'wp_ajax_cmp_export_customer_csv', 'cmp_export_customer_csv' );
    add_action( 'wp_ajax_nopriv_cmp_export_customer_csv', 'cmp_export_customer_csv' );

    function cmp_export_customer_csv() {
        if ( ! isset( $_GET['sub_id'] ) ) {
            wp_die( 'No subscription ID provided.' );
        }
        global $wpdb;
        $sub_id = intval( $_GET['sub_id'] );

        $is_admin     = current_user_can( 'manage_options' ) || current_user_can( 'foh_manager' ) || current_user_can( 'kitchen_staff' ) || current_user_can( 'menu_manager' );
        $can_view_pos = current_user_can( 'manage_options' ) || current_user_can( 'foh_manager' ) || current_user_can( 'menu_manager' );
        $user_id      = get_current_user_id();
        $sub          = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cmp_subscriptions WHERE id = %d", $sub_id ) );

        if ( ! $sub || ( ! $is_admin && intval( $sub->user_id ) !== $user_id ) ) {
            wp_die( 'Unauthorized Access' );
        }

        $is_flexible = isset( $sub->is_flexible ) ? intval( $sub->is_flexible ) : 0;

        $resolved       = cmp_get_subscription_logistics( $sub );
        $full_name      = $resolved['full_name'];
        $email          = $resolved['email'];
        $phone          = $resolved['phone'];
        $timing         = $resolved['timing'];
        $time_slot      = $resolved['time_slot'];
        $method         = $resolved['method'];
        $pickup         = $resolved['pickup'];
        $allergies      = $resolved['allergies'];
        $address        = $resolved['address'];
        $method_display = ( $method === 'Pickup' && ! empty( $pickup ) ) ? $method . ' (' . $pickup . ')' : $method;
        $prep_schedule  = cmp_is_same_day_prep_subscription( $sub, $resolved ) ? 'Same-Day Prep' : 'Day-Before Prep';

        $logs     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cmp_daily_logs WHERE subscription_id = %d ORDER BY is_carryover ASC, target_date ASC, id ASC", $sub_id ) );
        $foods    = $wpdb->get_results( "SELECT id, food_name FROM {$wpdb->prefix}cmp_foods" );
        $food_map = array();
        foreach ( $foods as $f ) {
            $food_map[ $f->id ] = $f->food_name;
        }

        $is_juice = ( stripos( $sub->allowed_categories, 'Juices' ) !== false || stripos( $sub->plan_name, 'juice' ) !== false || stripos( $sub->plan_name, 'cleanse' ) !== false );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="Meal_Schedule_' . esc_attr( str_replace( ' ', '_', $sub->plan_name ) ) . '.csv"' );
        $output = fopen( 'php://output', 'w' );
        fputs( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        fputcsv( $output, array( 'CUSTOMER & PLAN DETAILS' ) );
        fputcsv( $output, array( 'Plan:', $sub->plan_name, 'Name:', $full_name, 'Email:', $email, 'Phone:', $phone ) );
        fputcsv( $output, array( 'Address:', $address ) );
        if ( $can_view_pos ) {
            fputcsv( $output, array( 'Logistics:', $method_display, 'Receive By:', $timing, 'Time Slot:', $time_slot, 'Kitchen Prep:', $prep_schedule ) );
        } else {
            fputcsv( $output, array( 'Logistics:', $method_display, 'Receive By:', $timing, 'Time Slot:', $time_slot ) );
        }
        fputcsv( $output, array( 'Allergies:', $allergies ?: 'None' ) );
        fputcsv( $output, array( '' ) );

        if ( $is_juice ) {
            $headers = array( 'Date', 'Juice 1', 'Juice 2', 'Juice 3', 'Chefs Choice', 'Delivery Status' );
            if ( $can_view_pos ) {
                $headers[] = 'POS Check';
                $headers[] = 'Last Updated By';
            }
            fputcsv( $output, $headers );
        } else {
            $h1      = $is_flexible ? 'Meal 1' : 'Breakfast';
            $h2      = $is_flexible ? 'Meal 2' : 'Lunch';
            $h3      = $is_flexible ? 'Meal 3' : 'Dinner';
            $headers = array( 'Date', $h1, $h2, $h3, 'Snack 1', 'Snack 2', 'Chefs Choice', 'Delivery Status' );
            if ( $can_view_pos ) {
                $headers[] = 'POS Check';
                $headers[] = 'Last Updated By';
            }
            fputcsv( $output, $headers );
        }

        foreach ( $logs as $log ) {
            $chefs          = $log->is_chefs_choice ? 'Yes' : 'No';
            $is_canc_or_ret = in_array( $log->delivery_result, array( 'Cancelled', 'Returned' ), true );
            $is_carry       = ! empty( $log->is_carryover ) && intval( $log->is_carryover ) === 1;

            $status = 'Pending';
            if ( $log->delivery_result === 'Successful' ) {
                $status = 'Successful';
            } elseif ( $is_canc_or_ret ) {
                $status = $log->delivery_result;
            } elseif ( $log->id > 0 ) {
                $status = 'Selected';
            }
            if ( $is_carry ) {
                $status .= ' (Skipped Meal Carryover)';
            }

            // Option 4A: Cancelled and Returned rows do not require a POS Check
            $pos_status  = $is_canc_or_ret ? 'No POS Required' : ( ( ! empty( $log->pos_updated ) ) ? 'Checked' : 'Pending' );
            $audit_stamp = cmp_format_row_audit_stamp(
                $log->last_action_type ?? '',
                $log->updated_by_name ?? '',
                $log->updated_by_role ?? '',
                $log->updated_at ?? ''
            );

            $is_assigned = ( $log->breakfast_id || $log->lunch_id || $log->dinner_id || $log->juice_1_id || ! empty( $log->custom_breakfast ) || ! empty( $log->custom_lunch ) || ! empty( $log->custom_dinner ) || ! empty( $log->custom_juice_1 ) );
            $chef_tag    = $log->is_chefs_choice ? ' (Chef)' : '';
            $pending_tag = '[Pending Chef]';

            if ( $is_juice ) {
                $j1 = '-';
                $j2 = '-';
                $j3 = '-';
                if ( $log->is_chefs_choice && ! $is_assigned ) {
                    $j1 = $pending_tag;
                    $j2 = $pending_tag;
                    $j3 = $pending_tag;
                } else {
                    $j1 = ! empty( $log->custom_juice_1 ) ? $log->custom_juice_1 : ( $log->juice_1_id ? ( $food_map[ $log->juice_1_id ] ?? 'Unknown' ) . $chef_tag : '-' );
                    $j2 = ! empty( $log->custom_juice_2 ) ? $log->custom_juice_2 : ( $log->juice_2_id ? ( $food_map[ $log->juice_2_id ] ?? 'Unknown' ) . $chef_tag : '-' );
                    $j3 = ! empty( $log->custom_juice_3 ) ? $log->custom_juice_3 : ( $log->juice_3_id ? ( $food_map[ $log->juice_3_id ] ?? 'Unknown' ) . $chef_tag : '-' );
                }
                $row_out = array( $log->target_date, $j1, $j2, $j3, $chefs, $status );
                if ( $can_view_pos ) {
                    $row_out[] = $pos_status;
                    $row_out[] = $audit_stamp ?: '-';
                }
                fputcsv( $output, $row_out );
            } else {
                $b  = '-';
                $l  = '-';
                $d  = '-';
                $s1 = '-';
                $s2 = '-';
                if ( $log->is_chefs_choice && ! $is_assigned ) {
                    $b  = $pending_tag;
                    $l  = $pending_tag;
                    $d  = $pending_tag;
                    $s1 = $pending_tag;
                    $s2 = $pending_tag;
                } else {
                    $b  = ! empty( $log->custom_breakfast ) ? $log->custom_breakfast : ( $log->breakfast_id ? ( $food_map[ $log->breakfast_id ] ?? 'Unknown' ) . $chef_tag : '-' );
                    $l  = ! empty( $log->custom_lunch )     ? $log->custom_lunch     : ( $log->lunch_id     ? ( $food_map[ $log->lunch_id ]     ?? 'Unknown' ) . $chef_tag : '-' );
                    $d  = ! empty( $log->custom_dinner )    ? $log->custom_dinner    : ( $log->dinner_id    ? ( $food_map[ $log->dinner_id ]    ?? 'Unknown' ) . $chef_tag : '-' );
                    $s1 = ! empty( $log->custom_snack_1 )   ? $log->custom_snack_1   : ( $log->snack_1_id   ? ( $food_map[ $log->snack_1_id ]   ?? 'Unknown' ) . $chef_tag : '-' );
                    $s2 = ! empty( $log->custom_snack_2 )   ? $log->custom_snack_2   : ( $log->snack_2_id   ? ( $food_map[ $log->snack_2_id ]   ?? 'Unknown' ) . $chef_tag : '-' );
                }
                $row_out = array( $log->target_date, $b, $l, $d, $s1, $s2, $chefs, $status );
                if ( $can_view_pos ) {
                    $row_out[] = $pos_status;
                    $row_out[] = $audit_stamp ?: '-';
                }
                fputcsv( $output, $row_out );
            }
        }
        fclose( $output );
        exit;
    }
}

// ==========================================
// 3. WP ADMIN: MEAL PORTAL -> CUSTOMER FEEDBACK SUBMENU & CSV EXPORT
// ==========================================

if ( ! function_exists( 'cmp_get_meal_portal_parent_slug' ) ) {
    function cmp_get_meal_portal_parent_slug() {
        global $menu;
        $default_slug = 'cmp-menu-manager';
        if ( is_array( $menu ) ) {
            foreach ( $menu as $item ) {
                $slug  = $item[2] ?? '';
                $title = wp_strip_all_tags( $item[0] ?? '' );
                if (
                    $slug === 'cmp-menu-manager' ||
                    $slug === 'meal-subscription-portal' ||
                    $slug === 'cmp-meal-portal' ||
                    $slug === 'cmp_meal_portal' ||
                    stripos( $title, 'Meal Portal' ) !== false ||
                    stripos( $title, 'Meal Subscription' ) !== false
                ) {
                    return $slug;
                }
            }
        }
        return $default_slug;
    }
}

add_action( 'admin_menu', 'cmp_register_customer_feedback_submenu', 90 );
if ( ! function_exists( 'cmp_register_customer_feedback_submenu' ) ) {
    function cmp_register_customer_feedback_submenu() {
        $parent_slug = cmp_get_meal_portal_parent_slug();
        add_submenu_page(
            $parent_slug,
            'Customer Feedback & Ratings',
            'Customer Feedback',
            'manage_options',
            'cmp-customer-feedback',
            'cmp_render_customer_feedback_admin_page'
        );
    }
}

add_action( 'wp_ajax_cmp_export_feedback_csv', 'cmp_export_feedback_csv' );
if ( ! function_exists( 'cmp_export_feedback_csv' ) ) {
    function cmp_export_feedback_csv() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'foh_manager' ) && ! current_user_can( 'menu_manager' ) ) {
            wp_die( 'Unauthorized Access' );
        }
        check_admin_referer( 'cmp_export_feedback_nonce' );

        cmp_ensure_feedback_table_exists();
        global $wpdb;
        $table_feedback = $wpdb->prefix . 'cmp_feedback';
        $rows           = $wpdb->get_results( "SELECT * FROM {$table_feedback} ORDER BY created_at DESC, id DESC" );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="Customer_Feedback_Report_' . date( 'Y-m-d' ) . '.csv"' );
        $output = fopen( 'php://output', 'w' );
        fputs( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        fputcsv( $output, array(
            'ID',
            'Submitted At',
            'Identity Mode',
            'Customer Name',
            'Email',
            'Phone',
            'Order ID',
            'Meal Plan',
            'Meal & Delivery Rating (1-5)',
            'Online Portal Rating (1-5)',
            'Quick Highlights',
            'Additional Comments',
        ) );

        if ( $rows ) {
            foreach ( $rows as $r ) {
                $mode_label  = ! empty( $r->is_anonymous ) ? 'Anonymous' : 'Details Included';
                $order_label = ( intval( $r->wc_order_id ) > 0 ) ? '#' . $r->wc_order_id : ( ! empty( $r->is_anonymous ) ? '-' : 'Manual / N/A' );

                fputcsv( $output, array(
                    $r->id,
                    $r->created_at,
                    $mode_label,
                    ! empty( $r->is_anonymous ) ? 'Anonymous Customer' : $r->customer_name,
                    ! empty( $r->is_anonymous ) ? '-' : ( $r->customer_email ?: '-' ),
                    ! empty( $r->is_anonymous ) ? '-' : ( $r->customer_phone ?: '-' ),
                    $order_label,
                    $r->plan_name ?: '-',
                    $r->service_rating > 0 ? $r->service_rating . ' / 5' : 'N/A',
                    $r->portal_rating > 0 ? $r->portal_rating . ' / 5' : 'N/A',
                    $r->quick_tags ?: '-',
                    $r->comments ?: '-',
                ) );
            }
        }

        fclose( $output );
        exit;
    }
}

if ( ! function_exists( 'cmp_render_customer_feedback_admin_page' ) ) {
    function cmp_render_customer_feedback_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        cmp_ensure_feedback_table_exists();
        global $wpdb;
        $table_feedback = $wpdb->prefix . 'cmp_feedback';
        $admin_notice   = '';

        if ( isset( $_GET['delete_feedback_id'], $_GET['_wpnonce'] ) ) {
            $del_id = intval( $_GET['delete_feedback_id'] );
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cmp_delete_feedback_' . $del_id ) ) {
                $wpdb->delete( $table_feedback, array( 'id' => $del_id ) );
                $admin_notice = '<div class="notice notice-success is-dismissible"><p>Feedback entry #' . esc_html( $del_id ) . ' deleted.</p></div>';
            }
        }

        $search_query  = isset( $_GET['s_feedback'] ) ? sanitize_text_field( wp_unslash( $_GET['s_feedback'] ) ) : '';
        $identity_mode = isset( $_GET['mode_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['mode_filter'] ) ) : '';
        $rating_filter = isset( $_GET['rating_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['rating_filter'] ) ) : '';

        $all_rows      = $wpdb->get_results( "SELECT * FROM {$table_feedback} ORDER BY created_at DESC, id DESC" );
        $total_count   = is_array( $all_rows ) ? count( $all_rows ) : 0;
        $named_count   = 0;
        $anon_count    = 0;
        $service_sum   = 0;
        $service_cnt   = 0;
        $portal_sum    = 0;
        $portal_cnt    = 0;
        $tag_frequency = array();

        if ( $all_rows ) {
            foreach ( $all_rows as $item ) {
                if ( ! empty( $item->is_anonymous ) ) {
                    $anon_count++;
                } else {
                    $named_count++;
                }
                if ( intval( $item->service_rating ) > 0 ) {
                    $service_sum += intval( $item->service_rating );
                    $service_cnt++;
                }
                if ( intval( $item->portal_rating ) > 0 ) {
                    $portal_sum += intval( $item->portal_rating );
                    $portal_cnt++;
                }
                if ( ! empty( $item->quick_tags ) ) {
                    $tags = array_filter( array_map( 'trim', explode( ',', $item->quick_tags ) ) );
                    foreach ( $tags as $t ) {
                        $tag_frequency[ $t ] = ( $tag_frequency[ $t ] ?? 0 ) + 1;
                    }
                }
            }
        }

        $avg_service = $service_cnt > 0 ? number_format( $service_sum / $service_cnt, 1 ) : '0.0';
        $avg_portal  = $portal_cnt > 0 ? number_format( $portal_sum / $portal_cnt, 1 ) : '0.0';
        arsort( $tag_frequency );
        $top_tag = ! empty( $tag_frequency ) ? key( $tag_frequency ) : 'No tags yet';

        $filtered_rows = array();
        if ( $all_rows ) {
            foreach ( $all_rows as $r ) {
                if ( $identity_mode === 'named' && ! empty( $r->is_anonymous ) ) {
                    continue;
                }
                if ( $identity_mode === 'anonymous' && empty( $r->is_anonymous ) ) {
                    continue;
                }
                if ( $rating_filter === 'low' && ( intval( $r->service_rating ) > 2 || intval( $r->service_rating ) === 0 ) && ( intval( $r->portal_rating ) > 2 || intval( $r->portal_rating ) === 0 ) ) {
                    continue;
                } elseif ( in_array( $rating_filter, array( '5', '4', '3' ), true ) ) {
                    $target_r = intval( $rating_filter );
                    if ( intval( $r->service_rating ) !== $target_r && intval( $r->portal_rating ) !== $target_r ) {
                        continue;
                    }
                }

                if ( ! empty( $search_query ) ) {
                    $haystack = strtolower( $r->customer_name . ' ' . $r->customer_email . ' ' . $r->customer_phone . ' ' . $r->plan_name . ' ' . $r->quick_tags . ' ' . $r->comments );
                    if ( strpos( $haystack, strtolower( $search_query ) ) === false ) {
                        continue;
                    }
                }
                $filtered_rows[] = $r;
            }
        }

        $export_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=cmp_export_feedback_csv' ), 'cmp_export_feedback_nonce' );
        ?>
        <style>
            .cmp-fb-admin-wrap { max-width: 1320px; margin: 20px 20px 40px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .cmp-fb-banner { background: linear-gradient(135deg, #1B5E20 0%, #379237 100%); color: #fff; padding: 22px 26px; border-radius: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; box-shadow: 0 8px 22px rgba(27,94,32,0.14); }
            .cmp-fb-banner h1 { color: #fff; margin: 0; font-size: 1.65rem; font-weight: 800; }
            .cmp-fb-banner p { color: #DCECC9; margin: 4px 0 0 0; font-size: 0.92rem; }
            .cmp-fb-export-btn { background: #ffffff; color: #1B5E20; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 800; font-size: 0.88rem; border: 1.5px solid #DCECC9; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
            .cmp-fb-export-btn:hover { background: #EEF7E3; color: #1B5E20; }
            .cmp-fb-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 20px; }
            .cmp-fb-kpi-card { background: #fff; border: 1px solid #dce4ec; border-top: 4px solid #379237; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(15,23,42,0.04); }
            .cmp-fb-kpi-label { font-size: 0.76rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; display: block; margin-bottom: 6px; }
            .cmp-fb-kpi-val { font-size: 1.65rem; font-weight: 900; color: #1e293b; line-height: 1.15; }
            .cmp-fb-kpi-sub { font-size: 0.8rem; color: #475569; margin-top: 4px; font-weight: 600; }
            .cmp-fb-filter-bar { background: #fff; border: 1px solid #dce4ec; border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
            .cmp-fb-table-wrap { background: #fff; border: 1px solid #dce4ec; border-radius: 12px; overflow-x: auto; box-shadow: 0 2px 10px rgba(15,23,42,0.03); }
            .cmp-fb-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
            .cmp-fb-table th { background: #f8fafc; color: #334155; font-weight: 800; text-align: left; padding: 13px 15px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
            .cmp-fb-table td { padding: 14px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: top; color: #1e293b; }
            .cmp-fb-table tr:hover td { background: #f8fafc; }
            .cmp-fb-star-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-weight: 800; font-size: 0.8rem; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; white-space: nowrap; }
            .cmp-fb-star-pill.low-score { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
            .cmp-fb-star-pill.high-score { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
            .cmp-fb-tag { display: inline-block; background: #EEF7E3; color: #1B5E20; border: 1px solid #b7e1a1; border-radius: 999px; padding: 2px 9px; font-size: 0.74rem; font-weight: 700; margin: 0 4px 4px 0; }
            .cmp-fb-anon-badge { display: inline-block; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 999px; padding: 3px 10px; font-size: 0.76rem; font-weight: 800; }
            @media (max-width: 960px) { .cmp-fb-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
            @media (max-width: 600px) { .cmp-fb-kpi-grid { grid-template-columns: minmax(0, 1fr); } }
        </style>

        <div class="cmp-fb-admin-wrap">
            <?php echo $admin_notice; ?>

            <div class="cmp-fb-banner">
                <div>
                    <h1>Customer Experience Feedback</h1>
                    <p>Monitor Meal Plan Service &amp; Online Portal ratings submitted via the Customer Portal</p>
                </div>
                <a href="<?php echo esc_url( $export_url ); ?>" class="cmp-fb-export-btn">Export Feedbacks to Excel (CSV)</a>
            </div>

            <div class="cmp-fb-kpi-grid">
                <div class="cmp-fb-kpi-card">
                    <span class="cmp-fb-kpi-label">Total Responses</span>
                    <div class="cmp-fb-kpi-val"><?php echo intval( $total_count ); ?></div>
                    <div class="cmp-fb-kpi-sub"><?php echo intval( $named_count ); ?> Named &bull; <?php echo intval( $anon_count ); ?> Anonymous</div>
                </div>
                <div class="cmp-fb-kpi-card">
                    <span class="cmp-fb-kpi-label">Meal &amp; Delivery Service</span>
                    <div class="cmp-fb-kpi-val" style="color:#1B5E20;"><?php echo esc_html( $avg_service ); ?> <span style="color:#f59e0b; font-size:1.3rem;">&#9733;</span></div>
                    <div class="cmp-fb-kpi-sub">Based on <?php echo intval( $service_cnt ); ?> rating(s)</div>
                </div>
                <div class="cmp-fb-kpi-card">
                    <span class="cmp-fb-kpi-label">Online Portal Experience</span>
                    <div class="cmp-fb-kpi-val" style="color:#0369a1;"><?php echo esc_html( $avg_portal ); ?> <span style="color:#f59e0b; font-size:1.3rem;">&#9733;</span></div>
                    <div class="cmp-fb-kpi-sub">Based on <?php echo intval( $portal_cnt ); ?> rating(s)</div>
                </div>
                <div class="cmp-fb-kpi-card">
                    <span class="cmp-fb-kpi-label">Top Highlight Tag</span>
                    <div class="cmp-fb-kpi-val" style="font-size:1.15rem; padding-top:4px;"><?php echo esc_html( $top_tag ); ?></div>
                    <div class="cmp-fb-kpi-sub">Most selected quick highlight</div>
                </div>
            </div>

            <form method="GET" class="cmp-fb-filter-bar">
                <input type="hidden" name="page" value="cmp-customer-feedback">
                <input type="text" name="s_feedback" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Search name, email, plan, tags, or comments..." style="flex:2; min-width:220px; padding:7px 12px; border:1px solid #cbd5e1; border-radius:7px;">
                <select name="mode_filter" style="padding:7px 12px; border:1px solid #cbd5e1; border-radius:7px;">
                    <option value="">All Identity Modes</option>
                    <option value="named" <?php selected( $identity_mode, 'named' ); ?>>Details Included Only</option>
                    <option value="anonymous" <?php selected( $identity_mode, 'anonymous' ); ?>>Anonymous Only</option>
                </select>
                <select name="rating_filter" style="padding:7px 12px; border:1px solid #cbd5e1; border-radius:7px;">
                    <option value="">All Star Ratings</option>
                    <option value="5" <?php selected( $rating_filter, '5' ); ?>>5 Stars (Excellent)</option>
                    <option value="4" <?php selected( $rating_filter, '4' ); ?>>4 Stars (Very Good)</option>
                    <option value="3" <?php selected( $rating_filter, '3' ); ?>>3 Stars (Good)</option>
                    <option value="low" <?php selected( $rating_filter, 'low' ); ?>>1 - 2 Stars (Needs Attention)</option>
                </select>
                <button type="submit" class="button button-primary" style="background:#379237; border-color:#1B5E20; font-weight:700; padding:3px 16px;">Filter</button>
                <?php if ( ! empty( $search_query ) || ! empty( $identity_mode ) || ! empty( $rating_filter ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmp-customer-feedback' ) ); ?>" class="button">Reset</a>
                <?php endif; ?>
            </form>

            <div class="cmp-fb-table-wrap">
                <table class="cmp-fb-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Customer Details</th>
                            <th>Meal Plan</th>
                            <th>Meal &amp; Delivery</th>
                            <th>Portal Experience</th>
                            <th>Quick Highlights</th>
                            <th>Comments</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $filtered_rows ) ) : ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:36px; color:#64748b; font-weight:600;">No customer feedback submissions found matching your criteria.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $filtered_rows as $row ) :
                                $s_score = intval( $row->service_rating );
                                $p_score = intval( $row->portal_rating );
                                $s_class = $s_score >= 4 ? 'high-score' : ( $s_score > 0 && $s_score <= 2 ? 'low-score' : '' );
                                $p_class = $p_score >= 4 ? 'high-score' : ( $p_score > 0 && $p_score <= 2 ? 'low-score' : '' );
                                $del_url = wp_nonce_url(
                                    add_query_arg( array( 'page' => 'cmp-customer-feedback', 'delete_feedback_id' => $row->id ), admin_url( 'admin.php' ) ),
                                    'cmp_delete_feedback_' . $row->id
                                );
                            ?>
                                <tr>
                                    <td style="white-space:nowrap; font-size:0.84rem; color:#475569;">
                                        <strong><?php echo esc_html( date( 'M d, Y', strtotime( $row->created_at ) ) ); ?></strong><br>
                                        <span><?php echo esc_html( date( 'h:i A', strtotime( $row->created_at ) ) ); ?></span>
                                    </td>
                                    <td>
                                        <?php if ( ! empty( $row->is_anonymous ) ) : ?>
                                            <span class="cmp-fb-anon-badge">Anonymous Mode</span>
                                        <?php else : ?>
                                            <strong style="color:#1B5E20; font-size:0.95rem;"><?php echo esc_html( $row->customer_name ); ?></strong><br>
                                            <?php if ( ! empty( $row->customer_email ) ) : ?>
                                                <span style="font-size:0.82rem; color:#475569;"><?php echo esc_html( $row->customer_email ); ?></span><br>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $row->customer_phone ) ) : ?>
                                                <span style="font-size:0.82rem; color:#64748b;"><?php echo esc_html( $row->customer_phone ); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html( $row->plan_name ?: 'N/A' ); ?></strong>
                                        <?php if ( intval( $row->wc_order_id ) > 0 ) : ?>
                                            <br><span style="font-size:0.8rem; color:#64748b;">Order #<?php echo intval( $row->wc_order_id ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $s_score > 0 ) : ?>
                                            <span class="cmp-fb-star-pill <?php echo esc_attr( $s_class ); ?>"><?php echo esc_html( $s_score ); ?> / 5 &#9733;</span>
                                        <?php else : ?>
                                            <span style="color:#94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $p_score > 0 ) : ?>
                                            <span class="cmp-fb-star-pill <?php echo esc_attr( $p_class ); ?>"><?php echo esc_html( $p_score ); ?> / 5 &#9733;</span>
                                        <?php else : ?>
                                            <span style="color:#94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:240px;">
                                        <?php
                                        if ( ! empty( $row->quick_tags ) ) {
                                            $t_list = array_filter( array_map( 'trim', explode( ',', $row->quick_tags ) ) );
                                            foreach ( $t_list as $t_item ) {
                                                echo '<span class="cmp-fb-tag">' . esc_html( $t_item ) . '</span>';
                                            }
                                        } else {
                                            echo '<span style="color:#94a3b8;">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td style="max-width:320px; line-height:1.45;">
                                        <?php echo ! empty( $row->comments ) ? nl2br( esc_html( $row->comments ) ) : '<span style="color:#94a3b8;">No additional comments</span>'; ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <a href="<?php echo esc_url( $del_url ); ?>" style="color:#dc2626; text-decoration:none; font-weight:700; font-size:0.82rem;">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
