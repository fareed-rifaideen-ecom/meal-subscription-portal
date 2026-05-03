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
                    <h2 style="text-align:center; margin-top:0; color:#0f172a;">Admin Center Login</h2>
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

    // 3. ENQUEUE THE NEW PREMIUM STYLESHEET (CACHE BUSTER ENABLED)
    wp_enqueue_style( 'cmp-super-admin-css', plugin_dir_url( __FILE__ ) . 'assets/sa-style.css', array(), time() );

    $current_user = wp_get_current_user();

    ob_start();
    ?>
    <style>
        /* Extra inline CSS specifically for the new external button */
        .sa-ext-btn {
            background: #38bdf8;
            color: #0f172a !important;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 0.95em;
            font-weight: bold;
            margin-left: 15px;
            align-self: center;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .sa-ext-btn:hover {
            background: #0ea5e9;
            color: #fff !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(56, 189, 248, 0.3);
        }
    </style>

    <div class="sa-dashboard-wrapper">
        <!-- TOP NAVIGATION BAR -->
        <div class="sa-topbar">
            <div class="sa-topbar-header">
                <h2>Super Admin Center</h2>
                <p>Logged in as: <?php echo esc_html($current_user->display_name); ?></p>
            </div>
            
            <div class="sa-nav">
                <!-- Primary Tabbed Views -->
                <button class="sa-nav-btn" data-target="sa-foh">Customer Portal</button>
                <button class="sa-nav-btn" data-target="sa-kitchen">Kitchen Report</button>
                
                <!-- External Link Button for Menu Manager -->
                <a href="https://mealplan.thecyclebistro.com/menu-manager-portal/" target="_blank" class="sa-ext-btn">
                    Menu Manager 
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                </a>
            </div>

            <div class="sa-topbar-footer">
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" class="sa-logout-btn">Log Out</a>
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
            <!-- Menu Manager has been removed from here -->
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const navBtns = document.querySelectorAll('.sa-nav-btn');
        const contents = document.querySelectorAll('.sa-tab-content');
        
        let activeTab = "<?php echo isset($_POST['sa_tab']) ? esc_js($_POST['sa_tab']) : ''; ?>";
        
        // Safeguard: If the page reloads from an old menu action, force it to FOH
        if (activeTab === 'sa-menu') { activeTab = 'sa-foh'; }

        // If not, fall back to the last remembered tab or default to FOH
        if (!activeTab) {
            activeTab = localStorage.getItem('cmpSuperAdminTab') || 'sa-foh';
        }

        // Validate tab exists, otherwise default
        if (activeTab !== 'sa-foh' && activeTab !== 'sa-kitchen') {
            activeTab = 'sa-foh';
        }

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
