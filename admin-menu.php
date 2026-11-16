<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// Tell customer-portal-backend.php that admin-menu.php renders the Dish Display Mode UI natively
if ( ! defined( 'CMP_DISH_MODE_BANNER_RENDERED' ) ) {
    define( 'CMP_DISH_MODE_BANNER_RENDERED', true );
}

// Remove the fallback admin_notices banner from customer-portal-backend.php if registered
add_action( 'admin_init', function() {
    if ( function_exists( 'cmp_render_menu_manager_dish_display_mode_banner' ) ) {
        remove_action( 'admin_notices', 'cmp_render_menu_manager_dish_display_mode_banner', 5 );
    }
    if ( function_exists( 'cmp_handle_menu_manager_dish_display_mode_save' ) ) {
        remove_action( 'admin_init', 'cmp_handle_menu_manager_dish_display_mode_save' );
    }
}, 1 );

// 1. Add the menu item to the WordPress dashboard
add_action( 'admin_menu', 'cmp_add_admin_menu' );
function cmp_add_admin_menu() {
    add_menu_page(
        'Meal Portal Manager',
        'Meal Portal',
        'read', // Broad visibility, strict checking happens in renderer below
        'cmp-menu-manager',
        'cmp_render_admin_page',
        'dashicons-carrot', // Adds a food icon
        56 // Position in the menu
    );
}

// 2. Render the admin page, Dish Display Mode toggle, and CSV upload
function cmp_render_admin_page() {
    // STRICT SECURITY: Only allow Admins or Menu Managers
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) { 
        wp_die( 'Access Denied. You do not have permission to view the Meal Portal Manager.' ); 
    }

    global $wpdb;
    $table_foods = $wpdb->prefix . 'cmp_foods';
    $message     = '';

    // --- PHASE 0: SAVE CUSTOMER PORTAL DISH DISPLAY MODE TOGGLE ---
    if ( isset( $_POST['cmp_save_dish_display_mode'] ) ) {
        check_admin_referer( 'cmp_save_dish_display_mode_nonce' );
        $new_mode = sanitize_text_field( wp_unslash( $_POST['cmp_dish_details_display_mode'] ?? 'dish_1' ) );
        if ( ! in_array( $new_mode, array( 'dish_1', 'dish_2', 'dish_3', 'dish_4', 'dish_5', 'off' ), true ) ) {
            $new_mode = 'dish_1';
        }
        update_option( 'cmp_dish_details_display_mode', $new_mode );
        $message .= '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Customer Portal Dish Display Mode updated successfully.</p></div>';
    }

    $current_dish_mode = get_option( 'cmp_dish_details_display_mode', 'dish_1' );
    if ( ! in_array( $current_dish_mode, array( 'dish_1', 'dish_2', 'dish_3', 'dish_4', 'dish_5', 'off' ), true ) ) {
        $current_dish_mode = 'dish_1';
    }

    // --- PHASE 1: DATABASE UPGRADE (Safe & Non-Destructive) ---
    // Check if the date columns exist. If not, add them and migrate current data to Q2 2026.
    $columns_check = $wpdb->get_results( "SHOW COLUMNS FROM $table_foods LIKE 'valid_from'" );
    if ( empty( $columns_check ) ) {
        $wpdb->query( "ALTER TABLE $table_foods ADD valid_from DATE NULL AFTER protein, ADD valid_until DATE NULL AFTER valid_from" );
        $wpdb->query( "UPDATE $table_foods SET valid_from = '2026-04-01', valid_until = '2026-06-30' WHERE is_active = 1" );
    }

    // --- PHASE 2: SAFE DELETION (Targeted Date Range) ---
    if ( isset( $_POST['delete_menu_range'] ) ) {
        $del_from  = sanitize_text_field( $_POST['del_from'] );
        $del_until = sanitize_text_field( $_POST['del_until'] );
        if ( ! empty( $del_from ) && ! empty( $del_until ) ) {
            $deleted  = $wpdb->query( $wpdb->prepare( "DELETE FROM $table_foods WHERE valid_from = %s AND valid_until = %s", $del_from, $del_until ) );
            $message .= '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Completely deleted ' . intval( $deleted ) . ' items from the specific date range.</p></div>';
        }
    }

    // --- PHASE 3: PROCESS CSV UPLOAD ---
    if ( isset( $_POST['cmp_upload_csv'] ) && ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
        
        $valid_from  = sanitize_text_field( $_POST['valid_from'] );
        $valid_until = sanitize_text_field( $_POST['valid_until'] );

        if ( empty( $valid_from ) || empty( $valid_until ) ) {
            $message .= '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> You must select a Start and End date for the new menu.</p></div>';
        } else {
            // Open and read the CSV
            $file = $_FILES['csv_file']['tmp_name'];
            if ( ( $handle = fopen( $file, 'r' ) ) !== false ) {
                fgetcsv( $handle ); // Skip the first row (headers)
                
                $added_count = 0;
                while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
                    // Ensure the row has data before inserting
                    if ( ! empty( $data[0] ) && ! empty( $data[1] ) ) {
                        $wpdb->insert(
                            $table_foods,
                            array(
                                'category_name' => sanitize_text_field( $data[0] ),
                                'food_name'     => sanitize_text_field( $data[1] ),
                                'description'   => sanitize_textarea_field( $data[2] ),
                                'calories'      => intval( $data[3] ),
                                'total_fat'     => floatval( $data[4] ),
                                'carbohydrates' => floatval( $data[5] ),
                                'protein'       => floatval( $data[6] ),
                                'valid_from'    => $valid_from,
                                'valid_until'   => $valid_until,
                                'is_active'     => 1,
                            )
                        );
                        $added_count++;
                    }
                }
                fclose( $handle );
                $message .= '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> ' . $added_count . ' new items uploaded for the period of ' . date( 'd M Y', strtotime( $valid_from ) ) . ' to ' . date( 'd M Y', strtotime( $valid_until ) ) . '.</p></div>';
            }
        }
    }

    // Get unique date ranges for the delete dropdown
    $menu_ranges = $wpdb->get_results( "SELECT DISTINCT valid_from, valid_until FROM $table_foods WHERE valid_from IS NOT NULL ORDER BY valid_from DESC" );

    // --- PHASE 4: HTML FOR ADMIN PAGE ---
    ?>
    <style>
        .cmp-mm-admin-wrap,
        .cmp-mm-admin-wrap *,
        .cmp-mm-admin-wrap *::before,
        .cmp-mm-admin-wrap *::after {
            box-sizing: border-box;
        }
        .cmp-mm-admin-wrap {
            max-width: 1240px;
            margin: 20px 20px 40px 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
        }
        .cmp-mm-hero-banner {
            background: linear-gradient(135deg, #1B5E20 0%, #379237 100%);
            color: #ffffff;
            padding: 22px 26px;
            border-radius: 14px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            box-shadow: 0 8px 22px rgba(27, 94, 32, 0.14);
        }
        .cmp-mm-hero-banner h1 {
            margin: 0 !important;
            padding: 0 !important;
            color: #ffffff !important;
            font-size: 1.6rem !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
        }
        .cmp-mm-hero-banner p {
            margin: 5px 0 0 0 !important;
            color: #DCECC9 !important;
            font-size: 0.92rem !important;
            font-weight: 500 !important;
        }
        .cmp-mm-hero-badge {
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            border: 1px solid rgba(220, 236, 201, 0.55);
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 800;
            white-space: nowrap;
        }

        /* Dish Display Mode Card */
        .cmp-dish-mode-card {
            background: #ffffff;
            border: 1.5px solid #DCECC9;
            border-left: 5px solid #379237;
            border-radius: 14px;
            padding: 20px 24px;
            margin: 0 0 22px 0;
            width: 100%;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
        }
        .cmp-dish-mode-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }
        .cmp-dish-mode-title {
            margin: 0 !important;
            font-size: 1.12rem !important;
            color: #1B5E20 !important;
            font-weight: 800 !important;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .cmp-dish-mode-live-pill {
            background: #EEF7E3;
            color: #1B5E20;
            border: 1px solid #68B946;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .cmp-dish-mode-sub {
            margin: 5px 0 0 0;
            color: #475569;
            font-size: 0.86rem;
        }
        .cmp-dish-mode-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: stretch;
        }
        @media (max-width: 1024px) {
            .cmp-dish-mode-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 640px) {
            .cmp-dish-mode-grid {
                grid-template-columns: 1fr;
            }
        }
        label.cmp-dish-mode-option {
            display: flex !important;
            align-items: flex-start !important;
            gap: 10px !important;
            padding: 13px 15px !important;
            border-radius: 11px !important;
            border: 1.5px solid #e2e8f0 !important;
            background: #f8fafc !important;
            cursor: pointer !important;
            transition: all 0.16s ease !important;
            margin: 0 !important;
            height: 100% !important;
            box-shadow: none !important;
        }
        label.cmp-dish-mode-option:hover {
            border-color: #86efac !important;
            background: #ffffff !important;
        }
        label.cmp-dish-mode-option.is-selected-mode {
            border: 2px solid #379237 !important;
            background: #EEF7E3 !important;
            box-shadow: 0 4px 12px rgba(55, 146, 55, 0.12) !important;
        }
        label.cmp-dish-mode-option input[type="radio"] {
            margin: 3px 0 0 0 !important;
            accent-color: #379237 !important;
            flex-shrink: 0 !important;
            width: 16px !important;
            height: 16px !important;
        }
        .cmp-dish-mode-opt-title {
            display: block;
            color: #0f172a;
            font-size: 0.88rem;
            font-weight: 800;
            margin-bottom: 4px;
            line-height: 1.25;
        }
        label.cmp-dish-mode-option.is-selected-mode .cmp-dish-mode-opt-title {
            color: #1B5E20;
        }
        .cmp-dish-mode-opt-desc {
            display: block;
            color: #475569;
            font-size: 0.77rem;
            line-height: 1.42;
            font-weight: 500;
        }
        .cmp-dish-mode-save-btn {
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            border-radius: 9px;
            font-weight: 800;
            font-size: 0.88rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(27, 94, 32, 0.22);
            transition: all 0.18s ease;
            white-space: nowrap;
        }
        .cmp-dish-mode-save-btn:hover {
            filter: brightness(1.07);
            transform: translateY(-1px);
        }

        /* Bottom 2-Column Management Grid (CSV Upload + Safe Deletion) */
        .cmp-mm-bottom-grid {
            display: grid;
            grid-template-columns: 1.45fr 1fr;
            gap: 20px;
            align-items: stretch;
        }
        @media (max-width: 960px) {
            .cmp-mm-bottom-grid {
                grid-template-columns: 1fr;
            }
        }
        .cmp-mm-panel-card {
            background: #ffffff;
            border: 1.5px solid #dce4ec;
            border-top: 4px solid #379237;
            border-radius: 14px;
            padding: 22px 24px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .cmp-mm-panel-card.danger-card {
            background: linear-gradient(180deg, #fffbeb 0%, #ffffff 100%);
            border-color: #fde68a;
            border-top: 4px solid #d97706;
        }
        .cmp-mm-panel-card h2 {
            margin: 0 0 8px 0 !important;
            font-size: 1.15rem !important;
            font-weight: 800 !important;
            color: #1B5E20 !important;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .cmp-mm-panel-card.danger-card h2 {
            color: #b45309 !important;
        }
        .cmp-mm-panel-desc {
            margin: 0 0 14px 0;
            font-size: 0.86rem;
            color: #475569;
            line-height: 1.48;
        }
        .cmp-mm-csv-cols-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 16px;
            padding: 10px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
        }
        .cmp-mm-col-pill {
            background: #EEF7E3;
            color: #1B5E20;
            border: 1px solid #b7e1a1;
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 0.74rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .cmp-mm-file-dropzone {
            background: #f8fafc;
            border: 1.5px dashed #94a3b8;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 16px;
            transition: border-color 0.15s ease, background 0.15s ease;
        }
        .cmp-mm-file-dropzone:hover {
            border-color: #379237;
            background: #f4fbf0;
        }
        .cmp-mm-file-dropzone input[type="file"] {
            width: 100%;
            font-size: 0.86rem;
            color: #334155;
            font-weight: 600;
        }
        .cmp-mm-date-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        @media (max-width: 540px) {
            .cmp-mm-date-grid {
                grid-template-columns: 1fr;
            }
        }
        .cmp-mm-field-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 6px;
        }
        .cmp-mm-input,
        .cmp-mm-select {
            width: 100% !important;
            max-width: 100% !important;
            height: 40px !important;
            padding: 6px 12px !important;
            border: 1.5px solid #cbd5e1 !important;
            border-radius: 8px !important;
            background: #ffffff !important;
            font-size: 0.9rem !important;
            font-weight: 600 !important;
            color: #0f172a !important;
        }
        .cmp-mm-input:focus,
        .cmp-mm-select:focus {
            border-color: #379237 !important;
            box-shadow: 0 0 0 3px rgba(55, 146, 55, 0.16) !important;
            outline: none !important;
        }
        .cmp-mm-primary-btn {
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
            color: #ffffff;
            border: none;
            border-radius: 9px;
            padding: 11px 22px;
            font-size: 0.9rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(27, 94, 32, 0.2);
            transition: all 0.18s ease;
            width: 100%;
        }
        .cmp-mm-primary-btn:hover {
            filter: brightness(1.07);
            transform: translateY(-1px);
        }
        .cmp-mm-danger-btn {
            background: #ffffff;
            color: #dc2626;
            border: 1.5px solid #f87171;
            border-radius: 9px;
            padding: 10px 18px;
            font-size: 0.88rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.18s ease;
            width: 100%;
        }
        .cmp-mm-danger-btn:hover {
            background: #dc2626;
            color: #ffffff;
            border-color: #b91c1c;
        }
    </style>

    <div class="wrap cmp-mm-admin-wrap">
        <div class="cmp-mm-hero-banner">
            <div>
                <h1>Meal Portal &mdash; Menu Manager</h1>
                <p>Configure Customer Portal Dish Display Modes, upload quarterly menu CSVs, and manage active menu periods.</p>
            </div>
            <span class="cmp-mm-hero-badge">
                <?php echo count( $menu_ranges ); ?> Active Menu Period<?php echo count( $menu_ranges ) === 1 ? '' : 's'; ?>
            </span>
        </div>

        <?php echo $message; ?>

        <!-- CUSTOMER PORTAL DISH DESCRIPTION & MACROS DISPLAY MODE TOGGLE (OPTIONS DISH-1 TO DISH-5 + OFF) -->
        <div class="cmp-dish-mode-card">
            <form method="post">
                <?php wp_nonce_field( 'cmp_save_dish_display_mode_nonce' ); ?>
                <div class="cmp-dish-mode-header">
                    <div>
                        <h2 class="cmp-dish-mode-title">
                            <span>🍽️ Customer Portal: Dish Description &amp; Macros Display Mode</span>
                            <span class="cmp-dish-mode-live-pill">Live Toggle</span>
                        </h2>
                        <p class="cmp-dish-mode-sub">Choose how each meal's description and macros are displayed in the Customer Portal across Desktop, Tablet, and Mobile (both before and after saving).</p>
                    </div>
                    <button type="submit" name="cmp_save_dish_display_mode" value="1" class="cmp-dish-mode-save-btn">Save Display Mode</button>
                </div>

                <div class="cmp-dish-mode-grid" id="cmp-dish-mode-options-grid">
                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_1' ? 'is-selected-mode' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_1" <?php checked( $current_dish_mode, 'dish_1' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-1: Micro-Card</span>
                            <span class="cmp-dish-mode-opt-desc">Shows a clean card below each selected meal with dish description + 4 pastel Macro Pills (kcal, P, C, F).</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_2' ? 'is-selected-mode' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_2" <?php checked( $current_dish_mode, 'dish_2' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-2: Badge &amp; Popover</span>
                            <span class="cmp-dish-mode-opt-desc">Shows a compact <code>[420 kcal • ℹ️ Details]</code> pill next to the slot label with floating popover.</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_3' ? 'is-selected-mode' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_3" <?php checked( $current_dish_mode, 'dish_3' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-3: Macro Bar + Drawer</span>
                            <span class="cmp-dish-mode-opt-desc">Shows a 1-line macro summary bar below the dropdown with a tap-to-expand description drawer.</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_4' ? 'is-selected-mode' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_4" <?php checked( $current_dish_mode, 'dish_4' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-4 (4A): Card-Stack Picker + Card Preview</span>
                            <span class="cmp-dish-mode-opt-desc">Replaces dropdowns with an inline visual Card-Stack picker (and mobile sheet) + full Dish Card preview in the slot.</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_5' ? 'is-selected-mode' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_5" <?php checked( $current_dish_mode, 'dish_5' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-5 (4B): App-Style Menu Drawer + Card</span>
                            <span class="cmp-dish-mode-opt-desc">Opens a full app-style slide-up / modal Card-Stack Menu Drawer with instant search &amp; category filter tabs.</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'off' ? 'is-selected-mode' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="off" <?php checked( $current_dish_mode, 'off' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Off (Menu Name Only)</span>
                            <span class="cmp-dish-mode-opt-desc">Displays only the dish name inside dropdowns and daily macro totals at the bottom.</span>
                        </span>
                    </label>
                </div>
            </form>
        </div>

        <script>
        document.querySelectorAll('#cmp-dish-mode-options-grid input[type="radio"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.querySelectorAll('#cmp-dish-mode-options-grid .cmp-dish-mode-option').forEach(function(lbl) {
                    lbl.classList.remove('is-selected-mode');
                });
                if (this.closest('.cmp-dish-mode-option')) {
                    this.closest('.cmp-dish-mode-option').classList.add('is-selected-mode');
                }
            });
        });

        function cmpMmConfirmDeleteMenu(formEl) {
            var sel = formEl.querySelector('select[name="del_range"]');
            if (!sel || !sel.value) {
                return false;
            }
            var periodTxt = sel.options[sel.selectedIndex].text;
            var existingModal = document.getElementById('cmp-mm-del-confirm-modal');
            if (existingModal) existingModal.remove();

            var backdrop = document.createElement('div');
            backdrop.id = 'cmp-mm-del-confirm-modal';
            Object.assign(backdrop.style, {
                position: 'fixed',
                inset: '0',
                background: 'rgba(15, 23, 42, 0.55)',
                backdropFilter: 'blur(4px)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: '100100',
                padding: '16px'
            });

            var box = document.createElement('div');
            Object.assign(box.style, {
                background: '#ffffff',
                border: '2px solid #fde68a',
                borderTop: '5px solid #dc2626',
                borderRadius: '14px',
                maxWidth: '420px',
                width: '100%',
                padding: '22px 20px',
                boxShadow: '0 20px 40px rgba(15, 23, 42, 0.25)',
                textAlign: 'center',
                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
            });

            box.innerHTML = '<h3 style="margin:0 0 8px 0; color:#991b1b; font-size:1.1rem; font-weight:800;">Permanently Delete Menu Period?</h3>' +
                '<p style="margin:0 0 18px 0; color:#334155; font-size:0.9rem; line-height:1.45;">This will permanently delete all food items assigned to <strong>' + periodTxt + '</strong>.</p>' +
                '<div style="display:flex; gap:10px;">' +
                    '<button type="button" id="cmp-mm-cancel-del" style="flex:1; padding:10px; border-radius:8px; border:1.5px solid #cbd5e1; background:#f8fafc; color:#334155; font-weight:800; cursor:pointer;">Cancel</button>' +
                    '<button type="button" id="cmp-mm-confirm-del" style="flex:1; padding:10px; border-radius:8px; border:none; background:#dc2626; color:#ffffff; font-weight:800; cursor:pointer;">Yes, Delete Menu</button>' +
                '</div>';

            backdrop.appendChild(box);
            document.body.appendChild(backdrop);

            document.getElementById('cmp-mm-cancel-del').onclick = function() {
                backdrop.remove();
            };
            document.getElementById('cmp-mm-confirm-del').onclick = function() {
                backdrop.remove();
                var hiddenSubmit = document.createElement('input');
                hiddenSubmit.type = 'hidden';
                hiddenSubmit.name = 'delete_menu_range';
                hiddenSubmit.value = '1';
                formEl.appendChild(hiddenSubmit);
                formEl.submit();
            };
            return false;
        }
        </script>

        <div class="cmp-mm-bottom-grid">
            <!-- CSV UPLOAD SECTION -->
            <div class="cmp-mm-panel-card">
                <div>
                    <h2>📥 Upload Quarterly Menu (CSV)</h2>
                    <p class="cmp-mm-panel-desc">Upload your menu CSV file with these exact 7 columns in order:</p>
                    <div class="cmp-mm-csv-cols-bar">
                        <span class="cmp-mm-col-pill">1. Category</span>
                        <span class="cmp-mm-col-pill">2. Food Name</span>
                        <span class="cmp-mm-col-pill">3. Description</span>
                        <span class="cmp-mm-col-pill">4. Calories</span>
                        <span class="cmp-mm-col-pill">5. Total Fat</span>
                        <span class="cmp-mm-col-pill">6. Carbohydrates</span>
                        <span class="cmp-mm-col-pill">7. Protein</span>
                    </div>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <div class="cmp-mm-file-dropzone">
                        <input type="file" name="csv_file" accept=".csv" required />
                    </div>
                    
                    <div class="cmp-mm-date-grid">
                        <div>
                            <label class="cmp-mm-field-label">Valid From *</label>
                            <input type="date" name="valid_from" class="cmp-mm-input" required>
                        </div>
                        <div>
                            <label class="cmp-mm-field-label">Valid Until *</label>
                            <input type="date" name="valid_until" class="cmp-mm-input" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="cmp_upload_csv" value="1" class="cmp-mm-primary-btn">Upload Menu CSV</button>
                </form>
            </div>

            <!-- SAFE DELETION SECTION -->
            <div class="cmp-mm-panel-card danger-card">
                <div>
                    <h2>🗑️ Delete Old Menus</h2>
                    <p class="cmp-mm-panel-desc">Completely wipe an old quarter's menu from the database to keep the system clean without affecting active or upcoming menus.</p>
                </div>

                <form method="POST" onsubmit="return cmpMmConfirmDeleteMenu(this);">
                    <div style="margin-bottom: 18px;">
                        <label class="cmp-mm-field-label" style="color:#92400e;">Select Menu Period to Delete *</label>
                        <select name="del_range" class="cmp-mm-select" style="border-color: #f59e0b !important;" onchange="
                            var spl = this.value.split('|'); 
                            document.getElementById('del_from').value = spl[0] || '';
                            document.getElementById('del_until').value = spl[1] || '';
                        " required>
                            <option value="">&mdash; Select Menu Period to Delete &mdash;</option>
                            <?php foreach ( $menu_ranges as $r ) : ?>
                                <option value="<?php echo esc_attr( $r->valid_from . '|' . $r->valid_until ); ?>">
                                    <?php echo date( 'd M Y', strtotime( $r->valid_from ) ) . ' to ' . date( 'd M Y', strtotime( $r->valid_until ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="del_from" id="del_from" value="">
                    <input type="hidden" name="del_until" id="del_until" value="">
                    <button type="submit" name="delete_menu_range" class="cmp-mm-danger-btn">Permanently Delete Menu</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}
