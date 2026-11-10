<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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

    // --- PHASE 4: HTML FOR ADMIN PAGE ---
    ?>
    <div class="wrap">
        <h1>Meal Portal - Menu Manager</h1>
        <?php echo $message; ?>

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
