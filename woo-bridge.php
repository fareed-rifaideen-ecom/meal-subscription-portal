<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. ADD CUSTOM SETTINGS TO WOOCOMMERCE PRODUCTS
// ==========================================
add_filter( 'woocommerce_add_to_cart_validation', 'cmp_empty_cart_before_add', 10, 2 );
function cmp_empty_cart_before_add( $passed, $product_id ) {
    WC()->cart->empty_cart();
    return $passed;
}

add_filter( 'woocommerce_checkout_fields' , 'cmp_rename_order_notes' );
function cmp_rename_order_notes( $fields ) {
     $fields['order']['order_comments']['label'] = 'Allergy Notes & Special Instructions';
     $fields['order']['order_comments']['placeholder'] = 'Please list any allergies or specific delivery instructions here.';
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
    if ( is_null( WC()->cart ) ) return false;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product_id = $cart_item['product_id'];
        $days = get_post_meta( $product_id, '_cmp_total_days', true );
        if ( !empty($days) ) {
            return array( 
                'days' => intval($days), 
                'allowed' => intval(get_post_meta( $product_id, '_cmp_allowed_meals', true )),
                'special_cat' => get_post_meta( $product_id, '_cmp_allowed_categories', true ),
                'product_id' => $product_id 
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
    if ( !$plan ) return;

    $allowed_meals = $plan['allowed'];
    $is_juice = ($plan['special_cat'] === 'Juices');
    $map_url = get_option('cmp_map_url', 'http://mealplan.thecyclebistro.com/wp-content/uploads/2026/04/Coverage-Map.jpg');

    echo '<div id="cmp_custom_checkout_fields" style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; margin-top: 30px; border-radius: 5px;" data-allowed="' . $allowed_meals . '">';
    echo '<h3 style="border-bottom: 2px solid #ddd; padding-bottom: 10px; margin-top: 0;">Subscription Logistics</h3>';

    if (!$is_juice) {
        echo '<p style="margin-top: 15px; font-weight: bold;">Select Your Meal Categories (Choose exactly ' . $allowed_meals . '):</p>';
        woocommerce_form_field( 'cmp_cat_breakfast', array('type' => 'checkbox', 'class' => array('form-row-wide cmp-meal-checkbox'), 'label' => 'Breakfast'), $checkout->get_value( 'cmp_cat_breakfast' ));
        woocommerce_form_field( 'cmp_cat_lunch', array('type' => 'checkbox', 'class' => array('form-row-wide cmp-meal-checkbox'), 'label' => 'Lunch'), $checkout->get_value( 'cmp_cat_lunch' ));
        woocommerce_form_field( 'cmp_cat_dinner', array('type' => 'checkbox', 'class' => array('form-row-wide cmp-meal-checkbox'), 'label' => 'Dinner'), $checkout->get_value( 'cmp_cat_dinner' ));
    } else {
        echo '<p style="margin-top: 15px; font-weight: bold; color: #ca8a04;">Cleanse Booster (3 Juices / Day)</p>';
    }

    woocommerce_form_field( 'cmp_delivery_timing', array(
        'type'     => 'select',
        'class'    => array('form-row-wide'),
        'label'    => 'Delivery Timing <abbr class="required" title="required">*</abbr>',
        'options'  => array(
            ''                   => '-- Select Timing --',
            'Deliver Day Before' => 'Deliver Day Before',
            'Deliver Same Day'   => 'Deliver Same Day'
        ),
        'required' => true,
    ), $checkout->get_value( 'cmp_delivery_timing' ) );

    woocommerce_form_field( 'cmp_logistics_method', array(
        'type'     => 'select',
        'class'    => array('form-row-wide'),
        'label'    => 'How will you receive your items? <abbr class="required" title="required">*</abbr>',
        'options'  => array(
            ''         => '-- Select Method --',
            'Delivery' => 'Delivery',
            'Pickup'   => 'Store Pick-up'
        ),
        'required' => true,
    ), $checkout->get_value( 'cmp_logistics_method' ) );

    echo '<div id="cmp_delivery_zone_wrap" style="display:none; background: #e5f5fa; padding: 15px; border-radius: 4px; margin-top: 10px; border: 1px solid #b8e6f5;">';
    woocommerce_form_field( 'cmp_delivery_zone', array(
        'type'          => 'checkbox',
        'class'         => array('form-row-wide'),
        'label'         => 'I confirm I am within the delivery zone. <a href="' . esc_url($map_url) . '" target="_blank" style="text-decoration: underline; color: #0073aa;">(View Map)</a> <abbr class="required" title="required">*</abbr>',
        'required'      => false,
    ), $checkout->get_value( 'cmp_delivery_zone' ));
    echo '</div>';

    echo '<div id="cmp_pickup_location_wrap" style="display:none; background: #fff3cd; padding: 15px; border-radius: 4px; margin-top: 10px; border: 1px solid #ffeeba;">';
    woocommerce_form_field( 'cmp_pickup_location', array(
        'type'     => 'select',
        'class'    => array('form-row-wide'),
        'label'    => 'Select Pick-up Location <abbr class="required" title="required">*</abbr>',
        'options'  => array(
            ''            => '-- Select Location --',
            'Jumeirah'    => 'Jumeirah Branch',
            'Motor City'  => 'Motor City Branch'
        ),
        'required' => false, 
    ), $checkout->get_value( 'cmp_pickup_location' ) );
    echo '</div>';

    echo '</div>';

    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var isJuice = <?php echo $is_juice ? 'true' : 'false'; ?>;
        if(!isJuice) {
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
    if ( !$plan ) return;

    if ($plan['special_cat'] !== 'Juices') {
        $selected_count = 0;
        if ( isset( $_POST['cmp_cat_breakfast'] ) ) $selected_count++;
        if ( isset( $_POST['cmp_cat_lunch'] ) ) $selected_count++;
        if ( isset( $_POST['cmp_cat_dinner'] ) ) $selected_count++;

        if ( $selected_count !== $plan['allowed'] ) {
            wc_add_notice( 'Your meal plan requires you to select exactly ' . $plan['allowed'] . ' category(s). You selected ' . $selected_count . '.', 'error' );
        }
    }

    if ( empty( $_POST['cmp_delivery_timing'] ) ) wc_add_notice( 'Please select your Delivery Timing.', 'error' );
    if ( empty( $_POST['cmp_logistics_method'] ) ) wc_add_notice( 'Please select Delivery or Store Pick-up.', 'error' );
    else {
        if ( $_POST['cmp_logistics_method'] === 'Delivery' && empty( $_POST['cmp_delivery_zone'] ) ) {
            wc_add_notice( 'You must confirm you are within the delivery zone to proceed with Delivery.', 'error' );
        }
        if ( $_POST['cmp_logistics_method'] === 'Pickup' && empty( $_POST['cmp_pickup_location'] ) ) {
            wc_add_notice( 'Please select your preferred Pick-up Location.', 'error' );
        }
    }
}

// ==========================================
// 4. SAVE TO DATABASE
// ==========================================
add_action( 'woocommerce_checkout_update_order_meta', 'cmp_save_checkout_fields' );
function cmp_save_checkout_fields( $order_id ) {
    global $wpdb;
    $order = wc_get_order($order_id);
    if (!$order || !$order->get_customer_id()) return; 

    $plan = cmp_get_cart_plan_details();
    if ( !$plan ) return;

    if ( ! empty( $_POST['cmp_delivery_timing'] ) ) update_post_meta( $order_id, '_cmp_delivery_timing', sanitize_text_field( $_POST['cmp_delivery_timing'] ) );
    if ( ! empty( $_POST['cmp_logistics_method'] ) ) {
        $method = sanitize_text_field( $_POST['cmp_logistics_method'] );
        update_post_meta( $order_id, '_cmp_logistics_method', $method );
        if ( $method === 'Pickup' && ! empty( $_POST['cmp_pickup_location'] ) ) {
            update_post_meta( $order_id, '_cmp_pickup_location', sanitize_text_field( $_POST['cmp_pickup_location'] ) );
        }
    }

    if ($plan['special_cat'] === 'Juices') {
        $categories_string = 'Juices';
    } else {
        $selected_cats = array();
        if ( isset( $_POST['cmp_cat_breakfast'] ) ) $selected_cats[] = 'Breakfast';
        if ( isset( $_POST['cmp_cat_lunch'] ) ) $selected_cats[] = 'Lunch';
        if ( isset( $_POST['cmp_cat_dinner'] ) ) $selected_cats[] = 'Dinner';
        $categories_string = implode( ',', $selected_cats );
    }

    $product = wc_get_product($plan['product_id']);
    $product_name = $product ? $product->get_name() : 'Subscription Plan';
    
    $grace_period = intval( get_option('cmp_grace_period', '45') );
    $expiry = date('Y-m-d H:i:s', strtotime('+' . $grace_period . ' days'));

    $table_subscriptions = $wpdb->prefix . 'cmp_subscriptions';
    $wpdb->insert(
        $table_subscriptions,
        array(
            'user_id'            => $order->get_customer_id(),
            'wc_order_id'        => $order_id,
            'plan_name'          => $product_name, 
            'total_days'         => $plan['days'], 
            'allowed_categories' => $categories_string,
            'expiry_date'        => $expiry,
            'status'             => 'active'
        )
    );
}

// ==========================================
// 5. GO TO DASHBOARD BUTTON (THANK YOU PAGE)
// ==========================================
add_action( 'woocommerce_thankyou', 'cmp_thankyou_page_button', 10 );
function cmp_thankyou_page_button( $order_id ) {
    $plan = cmp_get_cart_plan_details();
    if ( $plan || get_post_meta( $order_id, '_cmp_delivery_timing', true ) ) {
        $portal_url = site_url('/my-meal-portal/'); 
        echo '<div style="margin: 40px 0; padding: 30px; background: #e5f5fa; border-radius: 8px; text-align: center; border: 2px solid #0073aa;">';
        echo '<h2 style="color: #0073aa; margin-top: 0;">Your Plan is Ready!</h2>';
        echo '<p style="font-size: 1.1em; margin-bottom: 20px;">Please visit your dashboard now to start selecting your daily items.</p>';
        echo '<a href="' . esc_url($portal_url) . '" style="background: #46b450; color: white; text-decoration: none; padding: 15px 30px; border-radius: 6px; font-weight: bold; font-size: 1.2em; display: inline-block;">Go to My Dashboard</a>';
        echo '</div>';
    }
}

// ==========================================
// 6. MY ACCOUNT: CUSTOMER DASHBOARD BUTTON
// ==========================================
add_action( 'woocommerce_before_account_orders', 'cmp_add_portal_button_to_orders' );
function cmp_add_portal_button_to_orders() {
    $portal_url = site_url('/my-meal-portal/');
    echo '<div style="margin-bottom: 25px; padding: 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">';
    echo '<div><h3 style="margin: 0 0 5px 0; color: #166534; font-size: 1.3em;">Manage Your Active Meals</h3><p style="margin: 0; color: #155724; font-size: 1em;">Head over to your dedicated dashboard to schedule your upcoming meals and juices.</p></div>';
    echo '<a href="' . esc_url($portal_url) . '" class="button" style="background: #46b450; color: white; border: none; padding: 12px 25px; font-weight: bold; border-radius: 4px; text-decoration: none;">Go to Meal Portal &rarr;</a>';
    echo '</div>';
}