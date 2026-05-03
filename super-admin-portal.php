<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// UNIFIED SUPER ADMIN COMMAND CENTER
// ==========================================
add_shortcode( 'meal_super_admin', 'cmp_render_super_admin_portal' );

function cmp_render_super_admin_portal() {
    
    // 1. SECURITY: Check Login
    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-sa-login', 'label_username' => __('Admin Email or Username'), 'label_password' => __('Password'));
        $custom_css = '
        <style>
            #cmp-sa-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; }
            #cmp-sa-login input[type="text"], #cmp-sa-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
            #cmp-sa-login .login-submit input[type="submit"] { width: 100%; background: #0f172a; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        </style>';
        return $custom_css . '<div style="max-width:400px; margin:50px auto; padding:30px; background:#fff; border-radius:8px; border:1px solid #ddd; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <h2 style="text-align:center; margin-top:0; color:#0f172a;">Command Center Login</h2>
                    <p style="text-align:center; color:#666; margin-bottom:20px;">Secure access for authorized personnel only.</p>' 
                    . wp_login_form( $login_args ) . 
                '</div>';
    }

    // 2. SECURITY: Check Role (Strictly Admin or Menu Manager)
    if ( !current_user_can('manage_options') && !current_user_can('menu_manager') ) {
        return '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border-left: 4px solid #dc3232; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <p style="font-size: 1.1em; color: #dc3232;"><strong>Access Denied:</strong> You do not have Super Admin clearance.</p>
                    <a href="' . wp_logout_url( get_permalink() ) . '" style="display: inline-block; margin-top: 15px; background: #222; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px;">Log Out & Switch Accounts</a>
                </div>';
    }

    $current_user = wp_get_current_user();

    ob_start();
    ?>
    <style>
        .sa-dashboard-wrapper { display: flex; min-height: 85vh; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        
        /* SIDEBAR STYLING */
        .sa-sidebar { width: 260px; background: #0f172a; color: #fff; display: flex; flex-direction: column; flex-shrink: 0; }
        .sa-sidebar-header { padding: 30px 20px; border-bottom: 1px solid #1e293b; }
        .sa-sidebar-header h2 { color: #fff; margin: 0 0 5px 0; font-size: 1.4em; }
        .sa-sidebar-header p { color: #94a3b8; margin: 0; font-size: 0.85em; }
        
        .sa-nav { display: flex; flex-direction: column; padding: 20px 0; flex-grow: 1; }
        .sa-nav-btn { background: none; border: none; color: #cbd5e1; text-align: left; padding: 15px 25px; font-size: 1.05em; font-weight: 600; cursor: pointer; transition: all 0.2s; border-left: 4px solid transparent; }
        .sa-nav-btn:hover { background: #1e293b; color: #fff; }
        .sa-nav-btn.active { background: #1e293b; color: #38bdf8; border-left-color: #38bdf8; }
        
        .sa-sidebar-footer { padding: 20px; border-top: 1px solid #1e293b; }
        .sa-logout-btn { display: block; text-align: center; background: #ef4444; color: #fff; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: bold; transition: background 0.2s; }
        .sa-logout-btn:hover { background: #dc2626; color: #fff; }

        /* CONTENT AREA STYLING */
        .sa-content-area { flex-grow: 1; padding: 30px; overflow-y: auto; background: #f8fafc; width: calc(100% - 260px); }
        .sa-tab-content { display: none; animation: fadeIn 0.3s ease-in-out; }
        .sa-tab-content.active { display: block; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* CSS HACKS TO HIDE REDUNDANT UI ELEMENTS FROM NESTED SHORTCODES */
        .sa-content-area div[style*="background: #222"] { display: none !important; } /* Hides FOH and Menu black headers */
        .sa-content-area .cmp-no-print a[href*="logout"] { display: none !important; } /* Hides Kitchen logout button */
        .sa-content-area .cmp-no-print a[href*="kitchen-command-center"] { display: none !important; } /* Hides cross-links */
        
        /* Ensure mobile responsiveness */
        @media (max-width: 900px) {
            .sa-dashboard-wrapper { flex-direction: column; }
            .sa-sidebar { width: 100%; flex-direction: row; align-items: center; padding: 10px; border-bottom: 2px solid #1e293b; }
            .sa-sidebar-header, .sa-sidebar-footer { display: none; }
            .sa-nav { flex-direction: row; padding: 0; width: 100%; justify-content: space-around; }
            .sa-nav-btn { padding: 15px 10px; text-align: center; border-left: none; border-bottom: 3px solid transparent; font-size: 0.9em; }
            .sa-nav-btn.active { border-left-color: transparent; border-bottom-color: #38bdf8; background: transparent; }
            .sa-content-area { width: 100%; padding: 15px; }
        }
    </style>

    <div class="sa-dashboard-wrapper">
        <!-- SIDEBAR -->
        <div class="sa-sidebar">
            <div class="sa-sidebar-header">
                <h2>Command Center</h2>
                <p>Logged in as: <?php echo esc_html($current_user->display_name); ?></p>
            </div>
            
            <div class="sa-nav">
                <button class="sa-nav-btn" data-target="sa-foh">Customers (FOH)</button>
                <button class="sa-nav-btn" data-target="sa-kitchen">Kitchen Report</button>
                <button class="sa-nav-btn" data-target="sa-menu">Menu Manager</button>
            </div>

            <div class="sa-sidebar-footer">
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" class="sa-logout-btn">Secure Log Out</a>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="sa-content-area">
            <div id="sa-foh" class="sa-tab-content">
                <?php echo do_shortcode('[meal_foh_portal]'); ?>
            </div>
            <div id="sa-kitchen" class="sa-tab-content">
                <?php echo do_shortcode('[meal_kitchen_portal]'); ?>
            </div>
            <div id="sa-menu" class="sa-tab-content">
                <?php echo do_shortcode('[meal_menu_manager]'); ?>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const navBtns = document.querySelectorAll('.sa-nav-btn');
        const contents = document.querySelectorAll('.sa-tab-content');
        
        // Use localStorage to remember the active tab so page reloads (like Kitchen Date Filter) don't reset the view
        let activeTab = localStorage.getItem('cmpSuperAdminTab') || 'sa-foh';

        function activateTab(targetId) {
            navBtns.forEach(btn => {
                if (btn.getAttribute('data-target') === targetId) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            contents.forEach(content => {
                if (content.id === targetId) {
                    content.classList.add('active');
                } else {
                    content.classList.remove('active');
                }
            });
            
            localStorage.setItem('cmpSuperAdminTab', targetId);
        }

        // Initialize
        activateTab(activeTab);

        // Click listeners
        navBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                activateTab(this.getAttribute('data-target'));
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
