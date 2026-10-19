<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

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

// Helper: Sanitize Role Permission Arrays & Always Keep Administrator Included
function cmp_sanitize_role_array( $input ) {
    $clean = array( 'administrator' );
    if ( is_array( $input ) ) {
        foreach ( $input as $role_slug ) {
            $sanitized = sanitize_key( $role_slug );
            if ( ! empty( $sanitized ) ) {
                $clean[] = $sanitized;
            }
        }
    }
    return array_values( array_unique( $clean ) );
}

// Helper: Sanitize Toggle Checkbox ('1' or '0')
function cmp_sanitize_toggle_flag( $input ) {
    return ( ! empty( $input ) && $input === '1' ) ? '1' : '0';
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

        // Customer Portal Quota, Date & Role Enforcement Settings
        register_setting( 'cmp_settings_group', 'cmp_strict_quota_enabled', array(
            'sanitize_callback' => 'cmp_sanitize_toggle_flag',
            'default'           => '1',
        ) );
        register_setting( 'cmp_settings_group', 'cmp_quota_bypass_roles', array(
            'sanitize_callback' => 'cmp_sanitize_role_array',
            'default'           => array( 'administrator' ),
        ) );
        register_setting( 'cmp_settings_group', 'cmp_date_exempt_roles', array(
            'sanitize_callback' => 'cmp_sanitize_role_array',
            'default'           => array( 'administrator', 'foh_manager', 'menu_manager' ),
        ) );
        register_setting( 'cmp_settings_group', 'cmp_adjust_days_roles', array(
            'sanitize_callback' => 'cmp_sanitize_role_array',
            'default'           => array( 'administrator', 'menu_manager' ),
        ) );

        // FOH POS Alert Settings
        register_setting( 'cmp_settings_group', 'cmp_pos_alert_emails' );
        register_setting( 'cmp_settings_group', 'cmp_pos_alert_time_1' );
        register_setting( 'cmp_settings_group', 'cmp_pos_alert_time_2' );
        register_setting( 'cmp_settings_group', 'cmp_pos_alert_days' ); 

        // Kitchen Alert Settings
        register_setting( 'cmp_settings_group', 'cmp_kitchen_alert_emails' );
        register_setting( 'cmp_settings_group', 'cmp_kitchen_alert_time_1' );
        register_setting( 'cmp_settings_group', 'cmp_kitchen_alert_time_2' );
        register_setting( 'cmp_settings_group', 'cmp_kitchen_alert_days' ); 
    }
}

// Helper: Format legacy integers (e.g., '19') to time strings ('19:00')
function cmp_format_time_setting($val) {
    if (strpos($val, ':') !== false) return $val;
    return str_pad($val, 2, '0', STR_PAD_LEFT) . ':00';
}

// 3. Build the Backend User Interface
function cmp_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) { 
        wp_die('Access Denied. You do not have permission to view this page.'); 
    }

    // Load current Quota & Date Exemption Settings with safe defaults
    $strict_quota_enabled = get_option( 'cmp_strict_quota_enabled', '1' );
    $quota_bypass_roles   = get_option( 'cmp_quota_bypass_roles', array( 'administrator' ) );
    if ( ! is_array( $quota_bypass_roles ) ) { $quota_bypass_roles = array( 'administrator' ); }

    $date_exempt_roles    = get_option( 'cmp_date_exempt_roles', array( 'administrator', 'foh_manager', 'menu_manager' ) );
    if ( ! is_array( $date_exempt_roles ) ) { $date_exempt_roles = array( 'administrator', 'foh_manager', 'menu_manager' ); }

    $adjust_days_roles    = get_option( 'cmp_adjust_days_roles', array( 'administrator', 'menu_manager' ) );
    if ( ! is_array( $adjust_days_roles ) ) { $adjust_days_roles = array( 'administrator', 'menu_manager' ); }

    $portal_staff_roles = array(
        'administrator' => 'Administrator (Super Admin)',
        'menu_manager'  => 'Menu Manager',
        'foh_manager'   => 'FOH Manager',
        'kitchen_staff' => 'Kitchen Staff',
    );
    ?>
    <div class="wrap" style="max-width: 860px;">
        <h1 style="margin-bottom: 20px;">Meal Subscription Portal: Global Settings</h1>

        <div style="background: #fff; padding: 20px 25px; border: 1px solid #ccd0d4; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
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

                    <!-- STRICT MEAL QUOTA ENFORCEMENT -->
                    <tr valign="top" style="background: #f0fdf4; border-left: 4px solid #379237;">
                        <th scope="row" style="padding-left: 15px;">Strict Meal Quota Enforcement</th>
                        <td>
                            <label style="display:inline-flex; align-items:center; gap:8px; font-weight:700; color:#1B5E20; margin-bottom:10px; cursor:pointer;">
                                <input type="hidden" name="cmp_strict_quota_enabled" value="0" />
                                <input type="checkbox" name="cmp_strict_quota_enabled" value="1" <?php checked( $strict_quota_enabled, '1' ); ?> />
                                Require users to select exact allocated Meal, Snack &amp; Juice quotas before saving or updating
                            </label>
                            <p class="description" style="margin-bottom: 12px;">
                                When enabled, users cannot save or update a day unless all allocated main meals and snacks (or 3 juices) are selected, or <strong>Chef's Choice</strong> is ticked.
                            </p>

                            <div style="background:#ffffff; border:1px solid #bbf7d0; border-radius:6px; padding:12px 14px;">
                                <strong style="display:block; margin-bottom:8px; color:#1e293b; font-size:0.92em;">Roles Allowed to Bypass Meal Quotas (Save Incomplete Selections):</strong>
                                <input type="hidden" name="cmp_quota_bypass_roles[]" value="administrator" />
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:8px;">
                                    <?php foreach ( $portal_staff_roles as $role_key => $role_label ) : 
                                        $is_admin_role = ( $role_key === 'administrator' );
                                        $is_checked    = $is_admin_role || in_array( $role_key, $quota_bypass_roles, true );
                                    ?>
                                        <label style="display:flex; align-items:center; gap:6px; font-size:0.9em; color:#334155;">
                                            <input type="checkbox" name="cmp_quota_bypass_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( $is_checked ); ?> <?php echo $is_admin_role ? 'disabled' : ''; ?> />
                                            <span><?php echo esc_html( $role_label ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description" style="margin-top:8px; margin-bottom:0;">Unchecked roles (and all Customers) must fulfill the subscription's exact meal &amp; snack quota.</p>
                            </div>
                        </td>
                    </tr>

                    <!-- DATE & CUT-OFF TIME EXEMPTION -->
                    <tr valign="top" style="background: #eff6ff; border-left: 4px solid #2563eb;">
                        <th scope="row" style="padding-left: 15px;">Date &amp; Cut-Off Exemption</th>
                        <td>
                            <div style="background:#ffffff; border:1px solid #bfdbfe; border-radius:6px; padding:12px 14px;">
                                <strong style="display:block; margin-bottom:8px; color:#1e293b; font-size:0.92em;">Roles Exempt from Past-Date Locks, Today Locks &amp; Daily Cut-Off Time:</strong>
                                <input type="hidden" name="cmp_date_exempt_roles[]" value="administrator" />
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:8px;">
                                    <?php foreach ( $portal_staff_roles as $role_key => $role_label ) : 
                                        $is_admin_role = ( $role_key === 'administrator' );
                                        $is_checked    = $is_admin_role || in_array( $role_key, $date_exempt_roles, true );
                                    ?>
                                        <label style="display:flex; align-items:center; gap:6px; font-size:0.9em; color:#334155;">
                                            <input type="checkbox" name="cmp_date_exempt_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( $is_checked ); ?> <?php echo $is_admin_role ? 'disabled' : ''; ?> />
                                            <span><?php echo esc_html( $role_label ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description" style="margin-top:8px; margin-bottom:0;">
                                    Customers are always restricted from editing, resetting, or cancelling past dates, today's date, or tomorrow's date after the daily cut-off hour. Checked staff roles can modify dates without cut-off restrictions.
                                </p>
                            </div>
                        </td>
                    </tr>

                    <!-- PLAN DAYS ADJUSTMENT PERMISSIONS (+ ADD / - REMOVE DAY) -->
                    <tr valign="top" style="background: #fefce8; border-left: 4px solid #ca8a04;">
                        <th scope="row" style="padding-left: 15px;">Add / Remove Plan Days Access</th>
                        <td>
                            <div style="background:#ffffff; border:1px solid #fef08a; border-radius:6px; padding:12px 14px;">
                                <strong style="display:block; margin-bottom:8px; color:#1e293b; font-size:0.92em;">Roles Allowed to Add Extra Day or Remove Last Day in Customer Portal:</strong>
                                <input type="hidden" name="cmp_adjust_days_roles[]" value="administrator" />
                                <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:8px;">
                                    <?php foreach ( $portal_staff_roles as $role_key => $role_label ) : 
                                        $is_admin_role = ( $role_key === 'administrator' );
                                        $is_checked    = $is_admin_role || in_array( $role_key, $adjust_days_roles, true );
                                    ?>
                                        <label style="display:flex; align-items:center; gap:6px; font-size:0.9em; color:#334155;">
                                            <input type="checkbox" name="cmp_adjust_days_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( $is_checked ); ?> <?php echo $is_admin_role ? 'disabled' : ''; ?> />
                                            <span><?php echo esc_html( $role_label ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description" style="margin-top:8px; margin-bottom:0;">
                                    Controls which staff roles can see and trigger the <strong>+ Add Extra Day</strong> and <strong>- Remove Last Day</strong> buttons.
                                </p>
                            </div>
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
                                    <label style="font-weight:bold; display:block;">Daily Alert 1</label>
                                    <input type="time" name="cmp_pos_alert_time_1" value="<?php echo esc_attr( cmp_format_time_setting(get_option('cmp_pos_alert_time_1', '19:00')) ); ?>" style="width: 110px;" />
                                    <span style="font-size: 0.85em; color:#666; display:block;">(Standard Alert)</span>
                                </div>
                                <div>
                                    <label style="font-weight:bold; display:block;">Daily Alert 2</label>
                                    <input type="time" name="cmp_pos_alert_time_2" value="<?php echo esc_attr( cmp_format_time_setting(get_option('cmp_pos_alert_time_2', '10:00')) ); ?>" style="width: 110px;" />
                                    <span style="font-size: 0.85em; color:#666; display:block;">(Escalation Alert)</span>
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
                                    <label style="font-weight:bold; display:block;">Daily Alert 1</label>
                                    <input type="time" name="cmp_kitchen_alert_time_1" value="<?php echo esc_attr( cmp_format_time_setting(get_option('cmp_kitchen_alert_time_1', '19:00')) ); ?>" style="width: 110px;" />
                                    <span style="font-size: 0.85em; color:#666; display:block;">(Standard Alert)</span>
                                </div>
                                <div>
                                    <label style="font-weight:bold; display:block;">Daily Alert 2</label>
                                    <input type="time" name="cmp_kitchen_alert_time_2" value="<?php echo esc_attr( cmp_format_time_setting(get_option('cmp_kitchen_alert_time_2', '10:00')) ); ?>" style="width: 110px;" />
                                    <span style="font-size: 0.85em; color:#666; display:block;">(Escalation Alert)</span>
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
