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

    // Process the CSV if the form is submitted
    if ( isset( $_POST['cmp_upload_csv'] ) && !empty( $_FILES['csv_file']['tmp_name'] ) ) {
        
        // If the "Wipe Menu" checkbox is checked, delete old foods
        if ( isset( $_POST['wipe_menu'] ) && $_POST['wipe_menu'] == 'yes' ) {
            $wpdb->query("TRUNCATE TABLE $table_foods");
            $message .= '<div class="notice notice-warning"><p>Previous menu wiped clean.</p></div>';
        }

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
                            'is_active'     => 1
                        )
                    );
                    $added_count++;
                }
            }
            fclose( $handle );
            $message .= '<div class="notice notice-success"><p>Successfully imported ' . $added_count . ' food items!</p></div>';
        }
    }

    // 3. The HTML for the Admin Page
    ?>
    <div class="wrap">
        <h1>Meal Portal - Menu Manager</h1>
        <?php echo $message; ?>

        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px; max-width: 600px;">
            <h2>Upload Quarterly Menu (CSV)</h2>
            <p>Your CSV must have exactly these 7 columns in this order: <br>
            <strong>Category | Food Name | Description | Calories | Total Fat | Carbohydrates | Protein</strong></p>
            
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="csv_file" accept=".csv" required style="margin-bottom: 20px;" /><br>
                
                <label>
                    <input type="checkbox" name="wipe_menu" value="yes" />
                    <strong>Wipe current menu?</strong> (Check this to delete all old foods before uploading the new ones)
                </label><br><br>
                
                <input type="submit" name="cmp_upload_csv" class="button button-primary" value="Upload Menu CSV" />
            </form>
        </div>
    </div>
    <?php
}
