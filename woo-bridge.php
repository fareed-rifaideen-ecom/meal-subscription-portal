<?php
/**
 * WooCommerce Checkout & Subscription Bridge
 * Synchronized with Post-Payment Subscription Provisioning, Per-Plan Logistics,
 * Delivery Timing, Time Slots, Kitchen Prep Schedule (Option PREP-C) & Allergies
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// Load shared per-plan logistics & database helpers from customer-portal-backend.php if available
if ( file_exists( __DIR__ . '/customer-portal-backend.php' ) ) {
    require_once __DIR__ . '/customer-portal-backend.php';
}

// ==========================================
// 1. ADD CUSTOM SETTINGS TO WOOCOMMERCE PRODUCTS
// ==========================================
add_filter( 'woocommerce_add_to_cart_validation', 'cmp_empty_cart_before_add', 10, 2 );
function cmp_empty_cart_before_add( $passed, $product_id ) {
    if ( function_exists( 'WC' ) && ! is_null( WC()->cart ) ) {
        WC()->cart->empty_cart();
    }
    return $passed;
}

add_filter( 'woocommerce_checkout_fields', 'cmp_rename_order_notes' );
function cmp_rename_order_notes( $fields ) {
    if ( isset( $fields['order']['order_comments'] ) ) {
        $fields['order']['order_comments']['label']       = 'Allergy Notes & Special Instructions';
        $fields['order']['order_comments']['placeholder'] = 'Please list any allergies or specific delivery instructions here.';
    }
    return $fields;
}

add_action( 'woocommerce_product_options_general_product_data', 'cmp_add_product_settings' );
function cmp_add_product_settings() {
    echo '<div class="options_group">';
    woocommerce_wp_text_input( array(
        'id'          => '_cmp_total_days',
        'label'       => 'Meal Plan: Total Days',
        'description' => 'Enter the number of days for this plan (e.g., 7 or 20).',
        'type'        => 'number',
        'desc_tip'    => 'true',
    ) );
    woocommerce_wp_text_input( array(
        'id'          => '_cmp_allowed_meals',
        'label'       => 'Meal Plan: Allowed Meals Per Day',
        'description' => 'Enter 1, 2, or 3. (Leave blank if Juices/Cleanse)',
        'type'        => 'number',
        'desc_tip'    => 'true',
    ) );
    woocommerce_wp_text_input( array(
        'id'          => '_cmp_allowed_categories',
        'label'       => 'Special Category Override',
        'description' => 'Enter EXACTLY "Juices" if this is a Cleanse Booster.',
        'desc_tip'    => 'true',
    ) );
    echo '</div>';
}

add_action( 'woocommerce_process_product_meta', 'cmp_save_product_settings' );
function cmp_save_product_settings( $post_id ) {
    update_post_meta( $post_id, '_cmp_total_days', sanitize_text_field( $_POST['_cmp_total_days'] ?? '' ) );
    update_post_meta( $post_id, '_cmp_allowed_meals', sanitize_text_field( $_POST['_cmp_allowed_meals'] ?? '' ) );
    update_post_meta( $post_id, '_cmp_allowed_categories', sanitize_text_field( $_POST['_cmp_allowed_categories'] ?? '' ) );
}

function cmp_get_cart_plan_details() {
    if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
        return false;
    }
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product_id = $cart_item['product_id'];
        $days       = get_post_meta( $product_id, '_cmp_total_days', true );
        if ( ! empty( $days ) ) {
            return array(
                'days'        => intval( $days ),
                'allowed'     => intval( get_post_meta( $product_id, '_cmp_allowed_meals', true ) ),
                'special_cat' => get_post_meta( $product_id, '_cmp_allowed_categories', true ),
                'product_id'  => $product_id,
            );
        }
    }
    return false;
}

// ==========================================
// 2. CHECKOUT FIELDS (UI UPGRADED)
// ==========================================
add_action( 'woocommerce_after_order_notes', 'cmp_add_checkout_fields' );
function cmp_add_checkout_fields( $checkout ) {
    $plan = cmp_get_cart_plan_details();
    if ( ! $plan ) {
        return;
    }

    $allowed_meals = $plan['allowed'];
    $is_juice      = ( $plan['special_cat'] === 'Juices' );
    $map_url       = get_option( 'cmp_map_url', 'http://mealplan.thecyclebistro.com/wp-content/uploads/2026/04/Coverage-Map.jpg' );

    echo '<div id="cmp_custom_checkout_fields" style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; margin-top: 30px; border-radius: 5px;" data-allowed="' . esc_attr( $allowed_meals ) . '">';
    echo '<h3 style="border-bottom: 2px solid #ddd; padding-bottom: 10px; margin-top: 0;">Subscription Logistics</h3>';

    if ( ! $is_juice ) {
        echo '<p style="margin-top: 15px; font-weight: bold;">Select Your Meal Categories (Choose exactly ' . esc_html( $allowed_meals ) . '):</p>';
        woocommerce_form_field( 'cmp_cat_breakfast', array( 'type' => 'checkbox', 'class' => array( 'form-row-wide cmp-meal-checkbox' ), 'label' => 'Breakfast' ), $checkout->get_value( 'cmp_cat_breakfast' ) );
        woocommerce_form_field( 'cmp_cat_lunch', array( 'type' => 'checkbox', 'class' => array( 'form-row-wide cmp-meal-checkbox' ), 'label' => 'Lunch' ), $checkout->get_value( 'cmp_cat_lunch' ) );
        woocommerce_form_field( 'cmp_cat_dinner', array( 'type' => 'checkbox', 'class' => array( 'form-row-wide cmp-meal-checkbox' ), 'label' => 'Dinner' ), $checkout->get_value( 'cmp_cat_dinner' ) );
    } else {
        echo '<p style="margin-top: 15px; font-weight: bold; color: #ca8a04;">Cleanse Booster (3 Juices / Day)</p>';
    }

    woocommerce_form_field( 'cmp_delivery_timing', array(
        'type'     => 'select',
        'class'    => array( 'form-row-wide' ),
        'label'    => 'Delivery Timing <abbr class="required" title="required">*</abbr>',
        'options'  => array(
            ''                   => '-- Select Timing --',
            'Deliver Day Before' => 'Deliver Day Before',
            'Deliver Same Day'   => 'Deliver Same Day',
        ),
        'required' => true,
    ), $checkout->get_value( 'cmp_delivery_timing' ) );

    woocommerce_form_field( 'cmp_logistics_method', array(
        'type'     => 'select',
        'class'    => array( 'form-row-wide' ),
        'label'    => 'How will you receive your items? <abbr class="required" title="required">*</abbr>',
        'options'  => array(
            ''         => '-- Select Method --',
            'Delivery' => 'Delivery',
            'Pickup'   => 'Store Pick-up',
        ),
        'required' => true,
    ), $checkout->get_value( 'cmp_logistics_method' ) );

    echo '<div id="cmp_delivery_zone_wrap" style="display:none; background: #e5f5fa; padding: 15px; border-radius: 4px; margin-top: 10px; border: 1px solid #b8e6f5;">';
    woocommerce_form_field( 'cmp_delivery_zone', array(
        'type'     => 'checkbox',
        'class'    => array( 'form-row-wide' ),
        'label'    => 'I confirm I am within the delivery zone. <a href="' . esc_url( $map_url ) . '" target="_blank" style="text-decoration: underline; color: #0073aa;">(View Map)</a> <abbr class="required" title="required">*</abbr>',
        'required' => false,
    ), $checkout->get_value( 'cmp_delivery_zone' ) );
    echo '</div>';

    echo '<div id="cmp_pickup_location_wrap" style="display:none; background: #fff3cd; padding: 15px; border-radius: 4px; margin-top: 10px; border: 1px solid #ffeeba;">';
    woocommerce_form_field( 'cmp_pickup_location', array(
        'type'     => 'select',
        'class'    => array( 'form-row-wide' ),
        'label'    => 'Select Pick-up Location <abbr class="required" title="required">*</abbr>',
        'options'  => array(
            ''           => '-- Select Location --',
            'Jumeirah'   => 'Jumeirah Branch',
            'Motor City' => 'Motor City Branch',
        ),
        'required' => false,
    ), $checkout->get_value( 'cmp_pickup_location' ) );
    echo '</div>';

    // --- RECIPIENT NAME FIELD ---
    echo '<div style="margin-top: 20px; padding-top: 15px; border-top: 1px dashed #ccc;">';
    $tooltip = '<span title="If buying for a family member, enter their name here so they get their own dedicated calendar tab in the dashboard." style="cursor:help; font-size:0.85em; background:#e2e8f0; color:#475569; padding:2px 6px; border-radius:50%; margin-left:5px; vertical-align:middle; display:inline-block;">?</span>';

    woocommerce_form_field( 'cmp_recipient_name', array(
        'type'        => 'text',
        'class'       => array( 'form-row-wide' ),
        'label'       => 'Recipient Name (Optional)' . $tooltip,
        'placeholder' => 'e.g., Sarah, John, etc.',
        'required'    => false,
    ), $checkout->get_value( 'cmp_recipient_name' ) );
    echo '</div>';

    echo '</div>';

    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var isJuice = <?php echo $is_juice ? 'true' : 'false'; ?>;
        if (!isJuice) {
            var maxAllowed = parseInt($('#cmp_custom_checkout_fields').attr('data-allowed'));
            var checkboxes = $('.cmp-meal-checkbox input[type="checkbox"]');
            function updateLimits() {
                var checkedCount = checkboxes.filter(':checked').length;
                if (checkedCount >= maxAllowed) {
                    checkboxes.not(':checked').prop('disabled', true);
                } else {
                    checkboxes.prop('disabled', false);
                }
            }
            checkboxes.on('change', updateLimits);
            updateLimits();
        }
        function toggleLogistics() {
            var method = $('select[name="cmp_logistics_method"]').val();
            if (method === 'Delivery') {
                $('#cmp_delivery_zone_wrap').slideDown();
                $('#cmp_pickup_location_wrap').slideUp();
            } else if (method === 'Pickup') {
                $('#cmp_delivery_zone_wrap').slideUp();
                $('#cmp_pickup_location_wrap').slideDown();
            } else {
                $('#cmp_delivery_zone_wrap').slideUp();
                $('#cmp_pickup_location_wrap').slideUp();
            }
        }
        $('select[name="cmp_logistics_method"]').on('change', toggleLogistics);
        toggleLogistics();
    });
    </script>
    <?php
}

// ==========================================
// 3. CHECKOUT VALIDATION
// ==========================================
add_action( 'woocommerce_checkout_process', 'cmp_validate_checkout_fields' );
function cmp_validate_checkout_fields() {
    $plan = cmp_get_cart_plan_details();
    if ( ! $plan ) {
        return;
    }

    if ( $plan['special_cat'] !== 'Juices' ) {
        $selected_count = 0;
        if ( isset( $_POST['cmp_cat_breakfast'] ) ) $selected_count++;
        if ( isset( $_POST['cmp_cat_lunch'] ) )     $selected_count++;
        if ( isset( $_POST['cmp_cat_dinner'] ) )    $selected_count++;

        if ( $selected_count !== $plan['allowed'] ) {
            wc_add_notice( 'Your meal plan requires you to select exactly ' . $plan['allowed'] . ' category(s). You selected ' . $selected_count . '.', 'error' );
        }
    }

    if ( empty( $_POST['cmp_delivery_timing'] ) ) {
        wc_add_notice( 'Please select your Delivery Timing.', 'error' );
    }
    if ( empty( $_POST['cmp_logistics_method'] ) ) {
        wc_add_notice( 'Please select Delivery or Store Pick-up.', 'error' );
    } else {
        if ( $_POST['cmp_logistics_method'] === 'Delivery' && empty( $_POST['cmp_delivery_zone'] ) ) {
            wc_add_notice( 'You must confirm you are within the delivery zone to proceed with Delivery.', 'error' );
        }
        if ( $_POST['cmp_logistics_method'] === 'Pickup' && empty( $_POST['cmp_pickup_location'] ) ) {
            wc_add_notice( 'Please select your preferred Pick-up Location.', 'error' );
        }
    }
}

// ==========================================
// 4. PRE-PAYMENT: SAVE SUBSCRIPTION BLUEPRINT TO ORDER META ONLY
//    (Does NOT insert into wp_cmp_subscriptions until order reaches Processing / Completed)
// ==========================================
add_action( 'woocommerce_checkout_update_order_meta', 'cmp_save_checkout_fields' );
function cmp_save_checkout_fields( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || ! $order->get_customer_id() ) {
        return;
    }

    $plan = cmp_get_cart_plan_details();
    if ( ! $plan ) {
        return;
    }

    if ( function_exists( 'cmp_ensure_subscription_logistics_columns' ) ) {
        cmp_ensure_subscription_logistics_columns();
    }

    $user_id         = intval( $order->get_customer_id() );
    $delivery_timing = ! empty( $_POST['cmp_delivery_timing'] ) ? sanitize_text_field( wp_unslash( $_POST['cmp_delivery_timing'] ) ) : '';
    $delivery_method = ! empty( $_POST['cmp_logistics_method'] ) ? sanitize_text_field( wp_unslash( $_POST['cmp_logistics_method'] ) ) : '';
    $pickup_location = ( $delivery_method === 'Pickup' && ! empty( $_POST['cmp_pickup_location'] ) ) ? sanitize_text_field( wp_unslash( $_POST['cmp_pickup_location'] ) ) : '';
    $time_slot       = ! empty( $_POST['cmp_time_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['cmp_time_slot'] ) ) : ( $order->get_meta( '_cmp_time_slot' ) ?: get_user_meta( $user_id, 'time_slot', true ) );
    $allergies       = isset( $_POST['order_comments'] ) ? sanitize_textarea_field( wp_unslash( $_POST['order_comments'] ) ) : $order->get_customer_note();
    $recipient_name  = sanitize_text_field( wp_unslash( $_POST['cmp_recipient_name'] ?? '' ) );

    $addr_parts = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city() ) );
    if ( empty( $addr_parts ) ) {
        $addr_parts = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city() ) );
    }
    $delivery_address = ! empty( $addr_parts ) ? implode( ', ', $addr_parts ) : '';

    if ( $plan['special_cat'] === 'Juices' ) {
        $categories_string = 'Juices';
    } else {
        $selected_cats = array();
        if ( isset( $_POST['cmp_cat_breakfast'] ) ) $selected_cats[] = 'Breakfast';
        if ( isset( $_POST['cmp_cat_lunch'] ) )     $selected_cats[] = 'Lunch';
        if ( isset( $_POST['cmp_cat_dinner'] ) )    $selected_cats[] = 'Dinner';
        $categories_string = ! empty( $selected_cats ) ? implode( ',', $selected_cats ) : 'Breakfast,Lunch,Dinner,Snacks';
    }

    $product         = wc_get_product( $plan['product_id'] );
    $final_plan_name = $product ? $product->get_name() : 'Subscription Plan';
    $base_plan_price = $product ? (float) $product->get_price() : (float) $order->get_subtotal();

    if ( ! empty( $recipient_name ) ) {
        $final_plan_name .= ' - ' . $recipient_name;
    }

    // Store complete Subscription Blueprint in Order Meta (both Post Meta & HPOS Order Meta compatible)
    update_post_meta( $order_id, '_cmp_is_wc_bridge_checkout', '1' );
    update_post_meta( $order_id, '_cmp_plan_name',             $final_plan_name );
    update_post_meta( $order_id, '_cmp_total_days',            intval( $plan['days'] ) );
    update_post_meta( $order_id, '_cmp_allowed_categories',    $categories_string );
    update_post_meta( $order_id, '_cmp_plan_price',            $base_plan_price );
    update_post_meta( $order_id, '_cmp_discount_amount',       (float) $order->get_discount_total() );
    update_post_meta( $order_id, '_cmp_recipient_name',        $recipient_name );
    update_post_meta( $order_id, '_cmp_delivery_address',      $delivery_address );
    update_post_meta( $order_id, '_cmp_prep_timing',           'auto' );

    $order->update_meta_data( '_cmp_is_wc_bridge_checkout', '1' );
    $order->update_meta_data( '_cmp_plan_name',             $final_plan_name );
    $order->update_meta_data( '_cmp_total_days',            intval( $plan['days'] ) );
    $order->update_meta_data( '_cmp_allowed_categories',    $categories_string );
    $order->update_meta_data( '_cmp_plan_price',            $base_plan_price );
    $order->update_meta_data( '_cmp_discount_amount',       (float) $order->get_discount_total() );
    $order->update_meta_data( '_cmp_recipient_name',        $recipient_name );
    $order->update_meta_data( '_cmp_delivery_address',      $delivery_address );
    $order->update_meta_data( '_cmp_prep_timing',           'auto' );

    if ( ! empty( $delivery_timing ) ) {
        update_post_meta( $order_id, '_cmp_delivery_timing', $delivery_timing );
        update_post_meta( $order_id, 'delivery_timing',      $delivery_timing );
        $order->update_meta_data( '_cmp_delivery_timing',    $delivery_timing );
        $order->update_meta_data( 'delivery_timing',         $delivery_timing );
    }
    if ( ! empty( $delivery_method ) ) {
        update_post_meta( $order_id, '_cmp_logistics_method', $delivery_method );
        update_post_meta( $order_id, 'delivery_method',       $delivery_method );
        $order->update_meta_data( '_cmp_logistics_method',    $delivery_method );
        $order->update_meta_data( 'delivery_method',          $delivery_method );

        if ( $delivery_method === 'Pickup' && ! empty( $pickup_location ) ) {
            update_post_meta( $order_id, '_cmp_pickup_location', $pickup_location );
            update_post_meta( $order_id, 'pickup_location',      $pickup_location );
            $order->update_meta_data( '_cmp_pickup_location',    $pickup_location );
            $order->update_meta_data( 'pickup_location',         $pickup_location );
        }
    }
    if ( ! empty( $time_slot ) ) {
        update_post_meta( $order_id, '_cmp_time_slot', $time_slot );
        update_post_meta( $order_id, 'time_slot',      $time_slot );
        $order->update_meta_data( '_cmp_time_slot',    $time_slot );
        $order->update_meta_data( 'time_slot',         $time_slot );
    }
    update_post_meta( $order_id, 'allergies', $allergies );
    $order->update_meta_data( 'allergies', $allergies );
    $order->save();
}

// ==========================================
// 4.5 POST-PAYMENT: PROVISION SUBSCRIPTION ONLY ON PROCESSING / COMPLETED
// ==========================================
add_action( 'woocommerce_order_status_processing', 'cmp_provision_wc_bridge_subscription_on_payment', 20, 1 );
add_action( 'woocommerce_order_status_completed',  'cmp_provision_wc_bridge_subscription_on_payment', 20, 1 );

function cmp_provision_wc_bridge_subscription_on_payment( $order_id ) {
    global $wpdb;
    $order_id = absint( $order_id );
    if ( ! $order_id ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || ! $order->has_status( array( 'processing', 'completed' ) ) ) {
        return;
    }

    $user_id = intval( $order->get_customer_id() );
    if ( $user_id <= 0 ) {
        return;
    }

    if ( function_exists( 'cmp_ensure_subscription_logistics_columns' ) ) {
        cmp_ensure_subscription_logistics_columns();
    }
    if ( function_exists( 'cmp_ensure_prep_timing_column' ) ) {
        cmp_ensure_prep_timing_column();
    }

    $table_subscriptions = $wpdb->prefix . 'cmp_subscriptions';

    // 1. Idempotency Guard: If meal-plan-checkout.php or a previous status transition already created the row, exit safely
    $existing_sub = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, status, total_days FROM {$table_subscriptions} WHERE wc_order_id = %d ORDER BY id DESC LIMIT 1",
        $order_id
    ) );

    if ( $existing_sub ) {
        if ( $existing_sub->status === 'pending' ) {
            $days = max( 1, intval( $existing_sub->total_days ) );
            $wpdb->update(
                $table_subscriptions,
                array(
                    'status'      => 'active',
                    'start_date'  => date( 'Y-m-d H:i:s' ),
                    'expiry_date' => date( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) ),
                ),
                array( 'id' => intval( $existing_sub->id ) )
            );
        }
        return;
    }

    // 2. Verify this order contains a Meal Plan blueprint
    $is_bridge_order  = ( $order->get_meta( '_cmp_is_wc_bridge_checkout' ) === '1' ) || ( get_post_meta( $order_id, '_cmp_is_wc_bridge_checkout', true ) === '1' );
    $is_custom_wizard = ( $order->get_meta( '_mpc_is_custom_checkout' ) === '1' );
    $delivery_timing  = $order->get_meta( '_cmp_delivery_timing' ) ?: get_post_meta( $order_id, '_cmp_delivery_timing', true );

    if ( ! $is_bridge_order && ! $is_custom_wizard && empty( $delivery_timing ) ) {
        return;
    }

    $final_plan_name   = $order->get_meta( '_cmp_plan_name' ) ?: get_post_meta( $order_id, '_cmp_plan_name', true );
    $total_days        = intval( $order->get_meta( '_cmp_total_days' ) ?: get_post_meta( $order_id, '_cmp_total_days', true ) );
    $categories_string = $order->get_meta( '_cmp_allowed_categories' ) ?: get_post_meta( $order_id, '_cmp_allowed_categories', true );
    $plan_price        = (float) ( $order->get_meta( '_cmp_plan_price' ) ?: get_post_meta( $order_id, '_cmp_plan_price', true ) );
    $discount_amount   = (float) ( $order->get_meta( '_cmp_discount_amount' ) ?: get_post_meta( $order_id, '_cmp_discount_amount', true ) );
    $delivery_method   = $order->get_meta( '_cmp_logistics_method' ) ?: get_post_meta( $order_id, '_cmp_logistics_method', true );
    $pickup_location   = $order->get_meta( '_cmp_pickup_location' ) ?: get_post_meta( $order_id, '_cmp_pickup_location', true );
    $time_slot         = $order->get_meta( '_cmp_time_slot' ) ?: ( get_post_meta( $order_id, '_cmp_time_slot', true ) ?: get_user_meta( $user_id, 'time_slot', true ) );
    $allergies         = $order->get_meta( 'allergies' ) ?: $order->get_customer_note();
    $delivery_address  = $order->get_meta( '_cmp_delivery_address' ) ?: get_post_meta( $order_id, '_cmp_delivery_address', true );
    $prep_timing       = $order->get_meta( '_cmp_prep_timing' ) ?: 'auto';

    if ( empty( $final_plan_name ) ) {
        foreach ( $order->get_items() as $item ) {
            $final_plan_name = $item->get_name();
            if ( $total_days <= 0 ) {
                $total_days = intval( get_post_meta( $item->get_product_id(), '_cmp_total_days', true ) );
            }
            break;
        }
    }

    if ( $total_days <= 0 ) {
        $total_days = 30;
        if ( stripos( $final_plan_name, '7' )  !== false ) $total_days = 7;
        if ( stripos( $final_plan_name, '20' ) !== false ) $total_days = 20;
        if ( stripos( $final_plan_name, '24' ) !== false ) $total_days = 24;
        if ( stripos( $final_plan_name, '5' )  !== false ) $total_days = 5;
        if ( stripos( $final_plan_name, '3' )  !== false && stripos( $final_plan_name, 'juice' ) !== false ) $total_days = 3;
    }

    if ( empty( $categories_string ) ) {
        $categories_string = ( stripos( $final_plan_name, 'juice' ) !== false || stripos( $final_plan_name, 'cleanse' ) !== false )
            ? 'Juices'
            : 'Breakfast,Lunch,Dinner,Snacks';
    }

    if ( $plan_price <= 0 ) {
        $plan_price      = (float) $order->get_subtotal();
        $discount_amount = (float) $order->get_discount_total();
    }

    if ( empty( $delivery_address ) ) {
        $addr_parts = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city() ) );
        if ( empty( $addr_parts ) ) {
            $addr_parts = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city() ) );
        }
        $delivery_address = ! empty( $addr_parts ) ? implode( ', ', $addr_parts ) : '';
    }

    $grace_period = intval( get_option( 'cmp_grace_period', '45' ) );
    $expiry_days  = max( $total_days, $grace_period );
    $expiry       = date( 'Y-m-d H:i:s', strtotime( '+' . $expiry_days . ' days' ) );

    $insert_data = array(
        'user_id'            => $user_id,
        'wc_order_id'        => $order_id,
        'plan_name'          => $final_plan_name ?: 'Subscription Plan',
        'total_days'         => $total_days,
        'allowed_categories' => $categories_string,
        'plan_price'         => $plan_price,
        'discount_amount'    => $discount_amount,
        'start_date'         => date( 'Y-m-d H:i:s' ),
        'expiry_date'        => $expiry,
        'status'             => 'active',
    );

    $existing_cols = $wpdb->get_col( "DESC {$table_subscriptions}", 0 );
    if ( is_array( $existing_cols ) ) {
        if ( in_array( 'delivery_method', $existing_cols, true ) )  $insert_data['delivery_method']  = $delivery_method;
        if ( in_array( 'pickup_location', $existing_cols, true ) )  $insert_data['pickup_location']  = $pickup_location;
        if ( in_array( 'delivery_timing', $existing_cols, true ) )  $insert_data['delivery_timing']  = $delivery_timing;
        if ( in_array( 'time_slot', $existing_cols, true ) )        $insert_data['time_slot']        = $time_slot;
        if ( in_array( 'allergies', $existing_cols, true ) )        $insert_data['allergies']        = ( $allergies !== '' && $allergies !== null ) ? $allergies : '__NONE__';
        if ( in_array( 'delivery_address', $existing_cols, true ) ) $insert_data['delivery_address'] = $delivery_address;
        if ( in_array( 'prep_timing', $existing_cols, true ) )      $insert_data['prep_timing']      = $prep_timing;
    }

    $inserted = $wpdb->insert( $table_subscriptions, $insert_data );
    if ( $inserted ) {
        $new_sub_id = intval( $wpdb->insert_id );

        if ( function_exists( 'cmp_save_user_logistics_profile_history' ) ) {
            $active_user_subs = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_subscriptions} WHERE user_id = %d AND status IN ('active','paused')",
                $user_id
            ) ) );
            cmp_save_user_logistics_profile_history(
                $user_id,
                $new_sub_id,
                array(
                    'plan_name' => $final_plan_name,
                    'method'    => $delivery_method,
                    'pickup'    => $pickup_location,
                    'timing'    => $delivery_timing,
                    'time_slot' => $time_slot,
                    'allergies' => $allergies,
                    'address'   => $delivery_address,
                ),
                ( $active_user_subs <= 1 )
            );
        }

        if ( function_exists( 'cmp_record_customer_activity_log' ) ) {
            cmp_record_customer_activity_log( array(
                'subscription_id' => $new_sub_id,
                'wc_order_id'     => $order_id,
                'user_id'         => $user_id,
                'plan_name'       => $final_plan_name,
                'action_type'     => 'Subscription Activated (Paid)',
                'old_status'      => 'Order #' . $order_id . ' Payment Verified',
                'new_status'      => 'Active (' . $total_days . ' Days) | ' . $delivery_timing . ' (' . $time_slot . ')',
            ) );
        }
    }
}

// Clean up any legacy 'pending' subscription rows if an order fails, is cancelled, or is trashed
add_action( 'woocommerce_order_status_failed',    'cmp_cleanup_unpaid_wc_bridge_sub', 20, 1 );
add_action( 'woocommerce_order_status_cancelled', 'cmp_cleanup_unpaid_wc_bridge_sub', 20, 1 );
add_action( 'woocommerce_order_status_trash',     'cmp_cleanup_unpaid_wc_bridge_sub', 20, 1 );

function cmp_cleanup_unpaid_wc_bridge_sub( $order_id ) {
    global $wpdb;
    $order_id = absint( $order_id );
    if ( ! $order_id ) {
        return;
    }
    $table_subscriptions = $wpdb->prefix . 'cmp_subscriptions';
    $wpdb->delete( $table_subscriptions, array( 'wc_order_id' => $order_id, 'status' => 'pending' ), array( '%d', '%s' ) );
}

// ==========================================
// 5. GO TO DASHBOARD BUTTON (THANK YOU PAGE — PAYMENT VERIFIED & DUPLICATE-SAFE)
// ==========================================
add_action( 'woocommerce_thankyou', 'cmp_thankyou_page_button', 10 );
function cmp_thankyou_page_button( $order_id ) {
    // If meal-plan-checkout.php is active, let its status-aware handler render the Thank-You banner so it never prints twice
    if ( function_exists( 'mpc_add_dashboard_button_to_thankyou' ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $plan = cmp_get_cart_plan_details();
    $has_meal_plan = $plan || $order->get_meta( '_cmp_delivery_timing' ) || get_post_meta( $order_id, '_cmp_delivery_timing', true );
    if ( ! $has_meal_plan ) {
        return;
    }

    $portal_url = site_url( '/my-meal-portal/' );

    if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
        cmp_provision_wc_bridge_subscription_on_payment( $order_id );

        echo '<div style="margin: 40px 0; padding: 30px; background: #f8fafc; border-radius: 12px; text-align: center; border: 2px solid #379237;">';
        echo '<h2 style="color: #1B5E20; margin-top: 0;">Your Meal Plan is Ready!</h2>';
        echo '<p style="font-size: 1.1em; color: #475569; margin-bottom: 20px;">Your payment has been confirmed. Please visit your dashboard now to start selecting your daily meals.</p>';
        echo '<a href="' . esc_url( $portal_url ) . '" style="background: #379237; color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: bold; font-size: 1.15em; display: inline-block;">Go to My Dashboard &rarr;</a>';
        echo '</div>';
    } else {
        $status_label = ucfirst( $order->get_status() );
        echo '<div style="margin: 40px 0; padding: 30px; background: #fff1f2; border-radius: 12px; text-align: center; border: 2px solid #f43f5e;">';
        echo '<h2 style="color: #be123c; margin-top: 0;">Payment Not Completed (Order Status: ' . esc_html( $status_label ) . ')</h2>';
        echo '<p style="font-size: 1.05em; color: #881337; margin-bottom: 0;">Your meal plan subscription will only be activated once payment is completed and the order status reaches Processing.</p>';
        echo '</div>';
    }
}

// ==========================================
// 6. MY ACCOUNT: CUSTOMER DASHBOARD BUTTON
// ==========================================
add_action( 'woocommerce_before_account_orders', 'cmp_add_portal_button_to_orders' );
function cmp_add_portal_button_to_orders() {
    $portal_url = site_url( '/my-meal-portal/' );
    echo '<div style="margin-bottom: 25px; padding: 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">';
    echo '<div><h3 style="margin: 0 0 5px 0; color: #166534; font-size: 1.3em;">Manage Your Active Meals</h3><p style="margin: 0; color: #155724; font-size: 1em;">Head over to your dedicated dashboard to schedule your upcoming meals and juices.</p></div>';
    echo '<a href="' . esc_url( $portal_url ) . '" class="button" style="background: #46b450; color: white; border: none; padding: 12px 25px; font-weight: bold; border-radius: 4px; text-decoration: none;">Go to Meal Portal &rarr;</a>';
    echo '</div>';
}
