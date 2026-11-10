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
        .cmp-dish-mode-card {
            background: #ffffff;
            border: 1.5px solid #DCECC9;
            border-left: 5px solid #379237;
            border-radius: 12px;
            padding: 18px 22px;
            margin: 18px 0 22px 0;
            max-width: 1240px;
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.05);
            box-sizing: border-box;
        }
        .cmp-dish-mode-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 14px;
        }
        .cmp-dish-mode-title {
            margin: 0;
            font-size: 1.1rem;
            color: #1B5E20;
            font-weight: 800;
            display: flex;
            align-items: center;
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
        }
        .cmp-dish-mode-sub {
            margin: 4px 0 0 0;
            color: #475569;
            font-size: 0.86rem;
        }
        .cmp-dish-mode-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
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
        .cmp-dish-mode-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .cmp-dish-mode-option:hover {
            border-color: #68B946;
            background: #ffffff;
        }
        .cmp-dish-mode-option.is-active {
            border: 2px solid #379237;
            background: #EEF7E3;
        }
        .cmp-dish-mode-option input[type="radio"] {
            margin-top: 3px;
            accent-color: #379237;
            flex-shrink: 0;
        }
        .cmp-dish-mode-opt-title {
            display: block;
            color: #1e293b;
            font-size: 0.88rem;
            font-weight: 800;
            margin-bottom: 3px;
        }
        .cmp-dish-mode-opt-desc {
            display: block;
            color: #475569;
            font-size: 0.77rem;
            line-height: 1.38;
        }
        .cmp-dish-mode-save-btn {
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 0.86rem;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(27, 94, 32, 0.2);
        }
        .cmp-dish-mode-save-btn:hover {
            filter: brightness(1.06);
        }
    </style>

    <div class="wrap">
        <h1>Meal Portal - Menu Manager</h1>
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
                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_1' ? 'is-active' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_1" <?php checked( $current_dish_mode, 'dish_1' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-1: Micro-Card</span>
                            <span class="cmp-dish-mode-opt-desc">Shows a clean card below each selected meal with dish description + 4 pastel Macro Pills (kcal, P, C, F).</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_2' ? 'is-active' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_2" <?php checked( $current_dish_mode, 'dish_2' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-2: Badge &amp; Popover</span>
                            <span class="cmp-dish-mode-opt-desc">Shows a compact <code>[420 kcal • ℹ️ Details]</code> pill next to the slot label with floating popover.</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_3' ? 'is-active' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_3" <?php checked( $current_dish_mode, 'dish_3' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-3: Macro Bar + Drawer</span>
                            <span class="cmp-dish-mode-opt-desc">Shows a 1-line macro summary bar below the dropdown with a tap-to-expand description drawer.</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_4' ? 'is-active' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_4" <?php checked( $current_dish_mode, 'dish_4' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-4 (4A): Card-Stack Picker + Card Preview</span>
                            <span class="cmp-dish-mode-opt-desc">Replaces dropdowns with an inline visual Card-Stack picker (and mobile sheet) + full Dish Card preview in the slot.</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'dish_5' ? 'is-active' : ''; ?>">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_5" <?php checked( $current_dish_mode, 'dish_5' ); ?>>
                        <span>
                            <span class="cmp-dish-mode-opt-title">Option DISH-5 (4B): App-Style Menu Drawer + Card</span>
                            <span class="cmp-dish-mode-opt-desc">Opens a full app-style slide-up / modal Card-Stack Menu Drawer with instant search &amp; category filter tabs.</span>
                        </span>
                    </label>

                    <label class="cmp-dish-mode-option <?php echo $current_dish_mode === 'off' ? 'is-active' : ''; ?>">
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
                    lbl.classList.remove('is-active');
                });
                if (this.closest('.cmp-dish-mode-option')) {
                    this.closest('.cmp-dish-mode-option').classList.add('is-active');
                }
            });
        });
        </script>

        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
            <!-- CSV UPLOAD SECTION -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; flex: 1; min-width: 300px; max-width: 600px;">
                <h2 style="margin-top: 0;">Upload Quarterly Menu (CSV)</h2>
                <p>Your CSV must have exactly these 7 columns in this order: <br>
                <strong>Category | Food Name | Description | Calories | Total Fat | Carbohydrates | Protein</strong></p>
                
                <form method="post" enctype="multipart/form-data">
                    <input type="file" name="csv_file" accept=".csv" required style="margin-bottom: 20px; width: 100%;" />
                    
                    <div style="display: flex; gap: 15px; margin-bottom: 20px;">
                        <div style="flex: 1;">
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Valid From *</label>
                            <input type="date" name="valid_from" required style="width: 100%;">
                        </div>
                        <div style="flex: 1;">
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Valid Until *</label>
                            <input type="date" name="valid_until" required style="width: 100%;">
                        </div>
                    </div>
                    
                    <input type="submit" name="cmp_upload_csv" class="button button-primary" value="Upload Menu CSV" />
                </form>
            </div>

            <!-- SAFE DELETION SECTION -->
            <div style="background: #fffbdd; padding: 20px; border: 1px solid #d97706; flex: 1; min-width: 300px; max-width: 400px; align-self: flex-start;">
                <h2 style="margin-top: 0; color: #b45309;">Delete Old Menus</h2>
                <p style="font-size: 0.9em; color: #856404;">Completely wipe an old quarter's menu from the database to keep the system clean without affecting current menus.</p>
                <form method="POST" onsubmit="return confirm('Are you sure? This will delete ALL food items assigned to this specific date range forever.');">
                    <select name="del_range" style="width: 100%; padding: 5px; margin-bottom: 15px; border-color: #d97706;" onchange="
                        var spl = this.value.split('|'); 
                        document.getElementById('del_from').value = spl[0];
                        document.getElementById('del_until').value = spl[1];
                    " required>
                        <option value="">- Select Menu Period to Delete -</option>
                        <?php foreach ( $menu_ranges as $r ) : ?>
                            <option value="<?php echo esc_attr( $r->valid_from . '|' . $r->valid_until ); ?>">
                                <?php echo date( 'd M Y', strtotime( $r->valid_from ) ) . ' to ' . date( 'd M Y', strtotime( $r->valid_until ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="del_from" id="del_from" value="">
                    <input type="hidden" name="del_until" id="del_until" value="">
                    <button type="submit" name="delete_menu_range" class="button" style="color: #dc2626; border-color: #dc2626;">Permanently Delete Menu</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}
