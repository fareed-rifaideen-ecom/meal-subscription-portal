<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

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

// 2. Render the admin page and handle the CSV upload
function cmp_render_admin_page() {
    // STRICT SECURITY: Only allow Admins or Menu Managers
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) { 
        wp_die('Access Denied. You do not have permission to view the Meal Portal Manager.'); 
    }

    global $wpdb;
    $table_foods = $wpdb->prefix . 'cmp_foods';
    $message = '';

    // --- PHASE 0: HANDLE DISH DETAILS DISPLAY MODE SAVE ---
    if ( isset( $_POST['cmp_save_dish_display_mode'] ) && check_admin_referer( 'cmp_save_dish_mode_action', 'cmp_dish_mode_nonce' ) ) {
        if ( current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' ) ) {
            $new_mode = isset( $_POST['cmp_dish_details_display_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['cmp_dish_details_display_mode'] ) ) : 'dish_1';
            if ( ! in_array( $new_mode, array( 'dish_1', 'dish_2', 'dish_3', 'off' ), true ) ) {
                $new_mode = 'dish_1';
            }
            update_option( 'cmp_dish_details_display_mode', $new_mode );
            $message .= '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Customer Portal Dish Details Display Mode updated successfully.</p></div>';
        }
    }

    $current_dish_mode = get_option( 'cmp_dish_details_display_mode', 'dish_1' );

    // --- PHASE 1: DATABASE UPGRADE (Safe & Non-Destructive) ---
    // Check if the date columns exist. If not, add them and migrate current data to Q2 2026.
    $columns_check = $wpdb->get_results( "SHOW COLUMNS FROM $table_foods LIKE 'valid_from'" );
    if (empty($columns_check)) {
        $wpdb->query("ALTER TABLE $table_foods ADD valid_from DATE NULL AFTER protein, ADD valid_until DATE NULL AFTER valid_from");
        $wpdb->query("UPDATE $table_foods SET valid_from = '2026-04-01', valid_until = '2026-06-30' WHERE is_active = 1");
    }

    // --- PHASE 2: SAFE DELETION (Targeted Date Range) ---
    if ( isset($_POST['delete_menu_range']) ) {
        $del_from  = sanitize_text_field($_POST['del_from']);
        $del_until = sanitize_text_field($_POST['del_until']);
        if (!empty($del_from) && !empty($del_until)) {
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table_foods WHERE valid_from = %s AND valid_until = %s", $del_from, $del_until));
            $message .= '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Completely deleted ' . intval($deleted) . ' items from the specific date range.</p></div>';
        }
    }

    // --- PHASE 3: PROCESS CSV UPLOAD ---
    if ( isset( $_POST['cmp_upload_csv'] ) && !empty( $_FILES['csv_file']['tmp_name'] ) ) {
        
        $valid_from  = sanitize_text_field($_POST['valid_from']);
        $valid_until = sanitize_text_field($_POST['valid_until']);

        if (empty($valid_from) || empty($valid_until)) {
            $message .= '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> You must select a Start and End date for the new menu.</p></div>';
        } else {
            // Open and read the CSV
            $file = $_FILES['csv_file']['tmp_name'];
            if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
                fgetcsv( $handle ); // Skip the first row (headers)
                
                $added_count = 0;
                while ( ( $data = fgetcsv( $handle, 1000, "," ) ) !== FALSE ) {
                    // Ensure the row has data before inserting
                    if ( !empty($data[0]) && !empty($data[1]) ) {
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
                                'is_active'     => 1
                            )
                        );
                        $added_count++;
                    }
                }
                fclose( $handle );
                $message .= '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> ' . $added_count . ' new items uploaded for the period of ' . date('d M Y', strtotime($valid_from)) . ' to ' . date('d M Y', strtotime($valid_until)) . '.</p></div>';
            }
        }
    }

    // Get unique date ranges for the delete dropdown
    $menu_ranges = $wpdb->get_results("SELECT DISTINCT valid_from, valid_until FROM $table_foods WHERE valid_from IS NOT NULL ORDER BY valid_from DESC");

    if ( ! defined( 'CMP_DISH_MODE_BANNER_RENDERED' ) ) {
        define( 'CMP_DISH_MODE_BANNER_RENDERED', true );
    }

    // --- PHASE 4: HTML FOR ADMIN PAGE ---
    ?>
    <div class="wrap">
        <h1>Meal Portal - Menu Manager</h1>
        <?php echo $message; ?>

        <!-- DISH DETAILS DISPLAY MODE TOGGLE CARD (OPTIONS DISH-1, DISH-2, DISH-3, OFF) -->
        <div style="background: #fff; border: 1.5px solid #DCECC9; border-left: 5px solid #379237; border-radius: 12px; padding: 18px 22px; margin-top: 20px; box-shadow: 0 4px 14px rgba(27, 94, 32, 0.05); max-width: 1280px;">
            <form method="POST" action="">
                <?php wp_nonce_field( 'cmp_save_dish_mode_action', 'cmp_dish_mode_nonce' ); ?>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                    <div>
                        <h2 style="margin:0 0 4px 0; color:#1B5E20; font-size:1.15em;">
                            🍽️ Customer Portal: Dish Ingredients &amp; Macros Display Mode
                            <span style="background:#EEF7E3; color:#1B5E20; border:1px solid #68B946; padding:2px 8px; border-radius:999px; font-size:0.72em; font-weight:800;">Live Toggle</span>
                        </h2>
                        <p style="margin:0; color:#64748b; font-size:0.9em;">Choose how each selected meal's Ingredients and Macros are displayed in the Customer Portal across Desktop, Tablet, and Mobile.</p>
                    </div>
                    <button type="submit" name="cmp_save_dish_display_mode" value="1" class="button button-primary" style="background:#379237; border-color:#1B5E20; font-weight:800; padding:6px 18px; height:auto; border-radius:8px;">
                        Save Display Mode
                    </button>
                </div>

                <div style="display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; align-items: stretch;">
                    <label style="display:flex; align-items:flex-start; gap:8px; padding:12px; border:1.5px solid <?php echo $current_dish_mode === 'dish_1' ? '#379237' : '#cbd5e1'; ?>; border-radius:10px; background:<?php echo $current_dish_mode === 'dish_1' ? '#EEF7E3' : '#f8fafc'; ?>; cursor:pointer;">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_1" <?php checked( $current_dish_mode, 'dish_1' ); ?> style="margin-top:2px; accent-color:#379237;">
                        <span>
                            <strong style="display:block; color:#0f172a; font-size:0.88em; margin-bottom:2px;">Option DISH-1: Micro-Card</strong>
                            <span style="color:#475569; font-size:0.78em; line-height:1.35; display:block;">Shows a clean card below each selected meal with Ingredients + 4 Macro Pills.</span>
                        </span>
                    </label>

                    <label style="display:flex; align-items:flex-start; gap:8px; padding:12px; border:1.5px solid <?php echo $current_dish_mode === 'dish_2' ? '#379237' : '#cbd5e1'; ?>; border-radius:10px; background:<?php echo $current_dish_mode === 'dish_2' ? '#EEF7E3' : '#f8fafc'; ?>; cursor:pointer;">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_2" <?php checked( $current_dish_mode, 'dish_2' ); ?> style="margin-top:2px; accent-color:#379237;">
                        <span>
                            <strong style="display:block; color:#0f172a; font-size:0.88em; margin-bottom:2px;">Option DISH-2: Badge &amp; Popover</strong>
                            <span style="color:#475569; font-size:0.78em; line-height:1.35; display:block;">Shows a compact <code>[440 kcal • ℹ️ Ingredients]</code> pill next to the slot label with floating popover.</span>
                        </span>
                    </label>

                    <label style="display:flex; align-items:flex-start; gap:8px; padding:12px; border:1.5px solid <?php echo $current_dish_mode === 'dish_3' ? '#379237' : '#cbd5e1'; ?>; border-radius:10px; background:<?php echo $current_dish_mode === 'dish_3' ? '#EEF7E3' : '#f8fafc'; ?>; cursor:pointer;">
                        <input type="radio" name="cmp_dish_details_display_mode" value="dish_3" <?php checked( $current_dish_mode, 'dish_3' ); ?> style="margin-top:2px; accent-color:#379237;">
                        <span>
                            <strong style="display:block; color:#0f172a; font-size:0.88em; margin-bottom:2px;">Option DISH-3: Macro Bar + Drawer</strong>
                            <span style="color:#475569; font-size:0.78em; line-height:1.35; display:block;">Shows a 1-line macro summary bar below dropdown with tap-to-expand ingredients drawer.</span>
                        </span>
                    </label>

                    <label style="display:flex; align-items:flex-start; gap:8px; padding:12px; border:1.5px solid <?php echo $current_dish_mode === 'off' ? '#379237' : '#cbd5e1'; ?>; border-radius:10px; background:<?php echo $current_dish_mode === 'off' ? '#EEF7E3' : '#f8fafc'; ?>; cursor:pointer;">
                        <input type="radio" name="cmp_dish_details_display_mode" value="off" <?php checked( $current_dish_mode, 'off' ); ?> style="margin-top:2px; accent-color:#379237;">
                        <span>
                            <strong style="display:block; color:#0f172a; font-size:0.88em; margin-bottom:2px;">Off (Menu Name Only)</strong>
                            <span style="color:#475569; font-size:0.78em; line-height:1.35; display:block;">Displays only the dish name inside dropdowns and daily macro totals at the bottom.</span>
                        </span>
                    </label>
                </div>
            </form>
        </div>

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
                        <?php foreach($menu_ranges as $r): ?>
                            <option value="<?php echo esc_attr($r->valid_from.'|'.$r->valid_until); ?>">
                                <?php echo date('d M Y', strtotime($r->valid_from)) . ' to ' . date('d M Y', strtotime($r->valid_until)); ?>
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
