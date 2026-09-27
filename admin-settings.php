<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// SUPER ADMIN SETTINGS PANEL
// ==========================================

// 1. Add the Menu Item to WordPress Backend (NESTED AS SUBMENU)
add_action( 'admin_menu', 'cmp_add_settings_submenu', 99 ); 
function cmp_add_settings_submenu() {
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
        register_setting( 'cmp_settings_group', 'cmp_digest_emails' ); 
        register_setting( 'cmp_settings_group', 'cmp_grace_period' );
        register_setting( 'cmp_settings_group', 'cmp_label_chefs_choice' );
        register_setting( 'cmp_settings_group', 'cmp_whatsapp_number' ); 

        // FOH POS Alert Settings
        register_setting( 'cmp_settings_group', 'cmp_pos_alert_emails' );
        register_setting( 'cmp_settings_group', 'cmp_pos_alert_time_1' );
        register_setting( 'cmp_settings_group', 'cmp_pos_alert_time_2' );
        register_setting( 'cmp_settings_group', 'cmp_pos_alert_days' ); // NEW

        // Kitchen Alert Settings
        register_setting( 'cmp_settings_group', 'cmp_kitchen_alert_emails' );
        register_setting( 'cmp_settings_group', 'cmp_kitchen_alert_time_1' );
        register_setting( 'cmp_settings_group', 'cmp_kitchen_alert_time_2' );
        register_setting( 'cmp_settings_group', 'cmp_kitchen_alert_days' ); // NEW
    }
}

// 3. Build the Backend User Interface
function cmp_render_settings_page() {
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
                            <p class="description">Comma-separated list of dates the kitchen is closed. Format EXACTLY as YYYY-MM-DD.</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Delivery Map Image URL</th>
                        <td>
                            <input type="url" name="cmp_map_url" value="<?php echo esc_attr( get_option('cmp_map_url', 'http://mealplan.thecyclebistro.com/wp-content/uploads/2026/04/Coverage-Map.jpg') ); ?>" style="width: 100%;" />
                            <p class="description">The link attached to the "View Map" text at WooCommerce checkout.</p>
                        </td>
                    </tr>

                    <tr valign="top" style="background: #f0f8ff; border-left: 4px solid #0073aa;">
                        <th scope="row" style="padding-left: 15px;">Daily Digest Alert Emails</th>
                        <td>
                            <input type="text" name="cmp_digest_emails" value="<?php echo esc_attr( get_option('cmp_digest_emails', get_option('admin_email')) ); ?>" style="width: 100%;" />
                            <p class="description">Emails that receive the daily "Meals Updated" summary 30 mins after cutoff.</p>
                        </td>
                    </tr>

                    <!-- FOH POS ALERT SETTINGS -->
                    <tr valign="top" style="background: #fdf2f8; border-left: 4px solid #db2777;">
                        <th scope="row" style="padding-left: 15px;">FOH POS Reminder Emails</th>
                        <td>
                            <input type="text" name="cmp_pos_alert_emails" value="<?php echo esc_attr( get_option('cmp_pos_alert_emails', '') ); ?>" style="width: 100%;" placeholder="e.g. foh@example.com" />
                            <p class="description">Emails to receive reminders if POS Checkboxes are left unticked.</p>
                            
                            <div style="display:flex; gap: 15px; margin-top: 10px;">
                                <div>
                                    <label style="font-weight:bold; display:block;">Lookback Days</label>
                                    <input type="number" name="cmp_pos_alert_days" value="<?php echo esc_attr( get_option('cmp_pos_alert_days', '7') ); ?>" min="1" style="width: 80px;" />
                                    <span style="font-size: 0.85em; color:#666;">(Days to audit)</span>
                                </div>
                                <div>
                                    <label style="font-weight:bold; display:block;">Same-Day Alert</label>
                                    <input type="number" name="cmp_pos_alert_time_1" value="<?php echo esc_attr( get_option('cmp_pos_alert_time_1', '18') ); ?>" min="0" max="23" style="width: 80px;" />
                                    <span style="font-size: 0.85em; color:#666;">(e.g., 18 = 6 PM)</span>
                                </div>
                                <div>
                                    <label style="font-weight:bold; display:block;">Next-Day Alert</label>
                                    <input type="number" name="cmp_pos_alert_time_2" value="<?php echo esc_attr( get_option('cmp_pos_alert_time_2', '10') ); ?>" min="0" max="23" style="width: 80px;" />
                                    <span style="font-size: 0.85em; color:#666;">(e.g., 10 = 10 AM)</span>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <!-- KITCHEN ALERT SETTINGS -->
                    <tr valign="top" style="background: #fff7ed; border-left: 4px solid #ea580c;">
                        <th scope="row" style="padding-left: 15px;">Kitchen Operational Alerts</th>
                        <td>
                            <input type="text" name="cmp_kitchen_alert_emails" value="<?php echo esc_attr( get_option('cmp_kitchen_alert_emails', '') ); ?>" style="width: 100%;" placeholder="e.g. kitchen@example.com" />
                            <p class="description">Emails to receive reminders if Prep, Dispatch, or Delivery logs are incomplete.</p>
                            
                            <div style="display:flex; gap: 15px; margin-top: 10px;">
                                <div>
                                    <label style="font-weight:bold; display:block;">Lookback Days</label>
                                    <input type="number" name="cmp_kitchen_alert_days" value="<?php echo esc_attr( get_option('cmp_kitchen_alert_days', '7') ); ?>" min="1" style="width: 80px;" />
                                    <span style="font-size: 0.85em; color:#666;">(Days to audit)</span>
                                </div>
                                <div>
                                    <label style="font-weight:bold; display:block;">Same-Day Alert</label>
                                    <input type="number" name="cmp_kitchen_alert_time_1" value="<?php echo esc_attr( get_option('cmp_kitchen_alert_time_1', '18') ); ?>" min="0" max="23" style="width: 80px;" />
                                    <span style="font-size: 0.85em; color:#666;">(e.g., 18 = 6 PM)</span>
                                </div>
                                <div>
                                    <label style="font-weight:bold; display:block;">Next-Day Alert</label>
                                    <input type="number" name="cmp_kitchen_alert_time_2" value="<?php echo esc_attr( get_option('cmp_kitchen_alert_time_2', '10') ); ?>" min="0" max="23" style="width: 80px;" />
                                    <span style="font-size: 0.85em; color:#666;">(e.g., 10 = 10 AM)</span>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Standard Kitchen Email</th>
                        <td>
                            <input type="email" name="cmp_kitchen_email" value="<?php echo esc_attr( get_option('cmp_kitchen_email', 'kitchen@thecyclebistro.com') ); ?>" style="width: 100%;" />
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Subscription Grace Period (Days)</th>
                        <td>
                            <input type="number" name="cmp_grace_period" value="<?php echo esc_attr( get_option('cmp_grace_period', '45') ); ?>" min="0" style="width: 100px;" />
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">"Chef's Choice" Label Text</th>
                        <td>
                            <input type="text" name="cmp_label_chefs_choice" value="<?php echo esc_attr( get_option('cmp_label_chefs_choice', "Chef's Choice") ); ?>" style="width: 100%;" />
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row">WhatsApp Support Number</th>
                        <td>
                            <input type="text" name="cmp_whatsapp_number" value="<?php echo esc_attr( get_option('cmp_whatsapp_number', '') ); ?>" style="width: 100%;" placeholder="e.g., +971501234567" />
                        </td>
                    </tr>

                </table>
                
                <?php submit_button( 'Save Global Settings', 'primary', 'submit', true, array('style' => 'background: #0073aa; border-color: #0073aa; margin-top: 20px;') ); ?>
            </form>
        </div>
    </div>
    <?php
}
