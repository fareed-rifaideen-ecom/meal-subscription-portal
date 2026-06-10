<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// UNIFIED SUPER ADMIN PORTAL
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
            #cmp-sa-login .login-submit input[type="submit"] { width: 100%; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        </style>';
        return $custom_css . '<div style="max-width:400px; margin:50px auto; padding:30px; background:#fff; border-radius:8px; border:1px solid #ddd; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <h2 style="text-align:center; margin-top:0; color:#222;">Super Admin Portal Login</h2>
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

    // Grab current user and extract First Name (fallback to Display Name if First Name is blank)
    $current_user = wp_get_current_user();
    $first_name = !empty($current_user->user_firstname) ? $current_user->user_firstname : $current_user->display_name;

    ob_start();
    ?>
    
    <style>
        /* Base Nav Button Styles */
        .sa-nav-btn { 
            background: #475569; color: white; border: none; padding: 10px 20px; 
            border-radius: 4px; font-weight: bold; cursor: pointer; transition: background 0.2s; 
            font-family: inherit; font-size: 0.9em; box-sizing: border-box;
        }
        .sa-nav-btn:hover { background: #334155; }
        
        /* Active State matches standard FOH Blue */
        .sa-nav-btn.active { background: #0073aa; }
        
        .sa-tab-content { display: none; }
        .sa-tab-content.active { display: block; }

        /* =========================================================
           PURE CSS INJECTION TO HIDE DUPLICATE HEADERS
           This safely hides the internal headers of the FOH & Kitchen 
           shortcodes so the Super Admin header acts as the master.
           ========================================================= */
        #sa-foh > div > div:first-child,
        #sa-kitchen > div > div:first-child { 
            display: none !important; 
        }
        
        /* Ensures the FOH search bar connects cleanly to the master header */
        #sa-foh > div > div[style*="background: #f8f9fa"] {
            border-radius: 0 0 8px 8px !important;
            border-top: none !important;
        }
    </style>

    <div style="max-width: 1200px; margin: 0 auto; font-family: inherit;">
        
        <div style="background: #222; color: #fff; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            
            <div style="display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;">
                <h2 style="margin: 0; color: #fff;">Super Admin</h2>
                <span style="color: #ccc; font-size: 0.85em;">Logged in as: <strong><?php echo esc_html($first_name); ?></strong></span>
            </div>
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button class="sa-nav-btn active" data-target="sa-foh">FOH Portal</button>
                <button class="sa-nav-btn" data-target="sa-kitchen">Kitchen Report</button>
                
                <a href="https://mealplan.thecyclebistro.com/menu-manager-portal/" target="_blank" style="background: #1d6f42; color: white; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; font-size: 0.9em; display: inline-flex; align-items: center;">Menu Manager</a>
                
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" style="background: #dc3232; color: white; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; font-size: 0.9em; display: inline-flex; align-items: center;">Log Out</a>
            </div>
            
        </div>

        <div class="sa-content-area">
            <div id="sa-foh" class="sa-tab-content active">
                <?php echo do_shortcode('[meal_foh_portal]'); ?>
            </div>
            <div id="sa-kitchen" class="sa-tab-content">
                <?php echo do_shortcode('[meal_kitchen_portal]'); ?>
            </div>
        </div>

    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const navBtns = document.querySelectorAll('.sa-nav-btn');
        const contents = document.querySelectorAll('.sa-tab-content');
        
        // Use localStorage to remember the active tab
        let activeTab = localStorage.getItem('cmpSuperAdminTab') || 'sa-foh';

        // Safety check: if the stored tab was 'sa-menu' (which is now external), default back to 'sa-foh'
        if (activeTab === 'sa-menu') { activeTab = 'sa-foh'; }

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

        // Initialize view
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
