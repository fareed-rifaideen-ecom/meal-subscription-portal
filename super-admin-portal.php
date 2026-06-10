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
            #cmp-sa-login .login-submit input[type="submit"] { width: 100%; background: #0f172a; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        </style>';
        return $custom_css . '<div style="max-width:400px; margin:50px auto; padding:30px; background:#fff; border-radius:8px; border:1px solid #ddd; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <h2 style="text-align:center; margin-top:0; color:#0f172a;">Super Admin Portal Login</h2>
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

    // 3. ENQUEUE EXTERNAL STYLESHEET (If you have one)
    wp_enqueue_style( 'cmp-super-admin-css', plugin_dir_url( __FILE__ ) . 'assets/sa-style.css', array(), time() );

    $current_user = wp_get_current_user();

    ob_start();
    ?>
    
    <style>
        .sa-dashboard-wrapper { 
            background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; 
            overflow: hidden; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05); 
            font-family: inherit; margin: 20px auto; max-width: 1400px;
        }
        .sa-topbar { 
            background: #0f172a; padding: 20px 30px; display: flex; 
            flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 20px; 
        }
        .sa-topbar-header h2 { 
            color: #f8fafc; margin: 0 0 5px 0; font-size: 1.6rem; 
            display: flex; align-items: center; gap: 10px; line-height: 1;
        }
        .sa-topbar-header p { color: #94a3b8; margin: 0; font-size: 0.95rem; }
        .sa-topbar-header p strong { color: #e2e8f0; }
        
        .sa-nav { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: center; flex: 1; }
        
        .sa-nav-btn, .sa-ext-btn, .sa-logout-btn { 
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; 
            border-radius: 6px; font-size: 0.95rem; font-weight: 600; text-decoration: none; 
            border: none; cursor: pointer; transition: all 0.2s ease; box-sizing: border-box;
            font-family: inherit; line-height: 1;
        }
        
        /* Tab Buttons */
        .sa-nav-btn { background: #1e293b; color: #cbd5e1; }
        .sa-nav-btn:hover { background: #334155; color: #fff; }
        .sa-nav-btn.active { background: #38bdf8; color: #0f172a; box-shadow: 0 4px 12px rgba(56,189,248,0.25); }
        
        /* External Link Button */
        .sa-ext-btn { background: #10b981; color: #fff; }
        .sa-ext-btn:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16,185,129,0.25); }
        
        /* Logout Button */
        .sa-logout-btn { background: #ef4444; color: #fff; }
        .sa-logout-btn:hover { background: #dc2626; }
        
        .sa-content-area { padding: 25px; min-height: 500px; background: #f8fafc; }
        .sa-tab-content { display: none; animation: saFadeIn 0.3s ease-out forwards; }
        .sa-tab-content.active { display: block; }
        
        @keyframes saFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Responsive adjustments */
        @media (max-width: 900px) { 
            .sa-topbar { flex-direction: column; text-align: center; } 
            .sa-topbar-header h2 { justify-content: center; }
            .sa-nav { justify-content: center; }
            .sa-content-area { padding: 15px; }
        }
    </style>

    <div class="sa-dashboard-wrapper">
        <div class="sa-topbar">
            <div class="sa-topbar-header">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    Super Admin
                </h2>
                <p>Logged in as: <strong><?php echo esc_html($current_user->display_name); ?></strong></p>
            </div>
            
            <div class="sa-nav">
                <button class="sa-nav-btn active" data-target="sa-foh">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    FOH Portal
                </button>
                <button class="sa-nav-btn" data-target="sa-kitchen">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M10.4 12.6a2 2 0 1 1 3 3L8 21l-4 1 1-4Z"></path><path d="M18 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"></path></svg>
                    Kitchen Report
                </button>
                
                <a href="https://mealplan.thecyclebistro.com/menu-manager-portal/" target="_blank" class="sa-ext-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    Menu Manager 
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                </a>
            </div>

            <div class="sa-topbar-footer">
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" class="sa-logout-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    Log Out
                </a>
            </div>
        </div>

        <div class="sa-content-area">
            <div id="sa-foh" class="sa-tab-content active">
                <?php echo do_shortcode('[meal_foh_portal is_embedded="true"]'); ?>
            </div>
            <div id="sa-kitchen" class="sa-tab-content">
                <?php echo do_shortcode('[meal_kitchen_portal is_embedded="true"]'); ?>
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
