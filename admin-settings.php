<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// SUPER ADMIN SETTINGS PANEL
// ==========================================

// 1. Add the Menu Item to WordPress Backend (NESTED AS SUBMENU)
add_action( 'admin_menu', 'cmp_add_settings_submenu', 99 ); 
function cmp_add_settings_submenu() {
    // UPDATED: Use a capability that both Admin and Menu Manager share, or explicitly allow both.
    // The easiest way is to use 'read' and then do a strict check in the renderer.
    add_submenu_page( 
        'cmp-menu-manager', // The exact parent slug
        'Meal Subscription Portal: Global Settings', 
        'Meal Settings', 
        'read', // Broad capability so Menu Manager can see it, strict checking happens in renderer 
        'cmp-settings', 
        'cmp_render_settings_page'
    );
}

// 2. Register the Settings in the Database
add_action( 'admin_init', 'cmp_register_settings' );
function cmp_register_settings() {
    // Ensure only admins and menu managers can actually save settings
    if ( current_user_can('manage_options') || current_user_can('menu_manager') ) {
        register_setting( 'cmp_settings_group', 'cmp_cutoff_time' );
        register_setting( 'cmp_settings_group', 'cmp_blackout_dates' );
        register_setting( 'cmp_settings_group', 'cmp_map_url' );
        register_setting( 'cmp_settings_group', 'cmp_kitchen_email' );
        register_setting( 'cmp_settings_group', 'cmp_grace_period' );
        register_setting( 'cmp_settings_group', 'cmp_label_chefs_choice' );
        register_setting( 'cmp_settings_group', 'cmp_whatsapp_number' ); 
    }
}

// 3. Build the Backend User Interface
function cmp_render_settings_page() {
    // UPDATED: Strict check to allow BOTH Admin and Menu Manager
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) { 
        wp_die('Access Denied. You do not have permission to view this page.'); 
    }
    ?>
    <div class="wrap" style="max-width: 800px;">
        <h1 style="margin-bottom: 20px;">Meal Subscription Portal: Global Settings</h1>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <form method="post" action="options.php">
                <?php settings_fields( 'cmp_settings_group' ); ?>
                <?php do_settings_sections( 'cmp_settings_group' ); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Daily Cutoff Time (24h format)</th>
                        <td>
                            <input type="number" name="cmp_cutoff_time" value="<?php echo esc_attr( get_option('cmp_cutoff_time', '11') ); ?>" min="0" max="23" style="width: 100px;" />
                            <p class="description">Enter the hour the portal locks for next-day delivery (e.g., 11 for 11:00 AM GST).</p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">Holiday / Blackout Dates</th>
                        <td>
                            <textarea name="cmp_blackout_dates" rows="3" style="width: 100%;"><?php echo esc_textarea( get_option('cmp_blackout_dates', '') ); ?></textarea>
                            <p class="description">Comma-separated list of dates the kitchen is closed. Format EXACTLY as YYYY-MM-DD (e.g., 2026-04-18,2026-04-19).</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Delivery Map Image URL</th>
                        <td>
                            <input type="url" name="cmp_map_url" value="<?php echo esc_attr( get_option('cmp_map_url', 'http://mealplan.thecyclebistro.com/wp-content/uploads/2026/04/Coverage-Map.jpg') ); ?>" style="width: 100%;" />
                            <p class="description">The link attached to the "View Map" text at WooCommerce checkout.</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Kitchen Alert Email</th>
                        <td>
                            <input type="email" name="cmp_kitchen_email" value="<?php echo esc_attr( get_option('cmp_kitchen_email', 'kitchen@thecyclebistro.com') ); ?>" style="width: 100%;" />
                            <p class="description">Where system alerts (like FOH overrides) should be sent.</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Subscription Grace Period (Days)</th>
                        <td>
                            <input type="number" name="cmp_grace_period" value="<?php echo esc_attr( get_option('cmp_grace_period', '45') ); ?>" min="0" style="width: 100px;" />
                            <p class="description">Extra days added to the plan duration before the subscription auto-expires.</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">"Chef's Choice" Label Text</th>
                        <td>
                            <input type="text" name="cmp_label_chefs_choice" value="<?php echo esc_attr( get_option('cmp_label_chefs_choice', "Chef's Choice") ); ?>" style="width: 100%;" />
                            <p class="description">Change how the checkbox text appears in the Customer Portal.</p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">WhatsApp Support Number</th>
                        <td>
                            <input type="text" name="cmp_whatsapp_number" value="<?php echo esc_attr( get_option('cmp_whatsapp_number', '') ); ?>" style="width: 100%;" placeholder="e.g., +971501234567" />
                            <p class="description">Enter the number with the country code (e.g., +971) to enable the floating WhatsApp button in the Customer Portal. Leave blank to disable.</p>
                        </td>
                    </tr>

                </table>
                
                <?php submit_button( 'Save Global Settings', 'primary', 'submit', true, array('style' => 'background: #0073aa; border-color: #0073aa; margin-top: 20px;') ); ?>
            </form>
        </div>
    </div>
    <?php
}
