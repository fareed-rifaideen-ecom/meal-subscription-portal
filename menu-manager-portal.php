<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// DEDICATED MENU MANAGER PORTAL
// ==========================================
add_shortcode( 'meal_menu_manager', 'cmp_render_menu_manager' );

function cmp_render_menu_manager() {
    
    // 1. SECURITY DOOR: Show login form if not logged in
    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-menu-login', 'label_username' => __('Email Address or Username'), 'label_password' => __('Password'));
        $custom_css = '
        <style>
            #cmp-menu-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; }
            #cmp-menu-login input[type="text"], #cmp-menu-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
            #cmp-menu-login .login-submit input[type="submit"] { width: 100%; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        </style>';
        return $custom_css . '<div style="max-width:400px; margin:50px auto; padding:30px; background:#f8f9fa; border-radius:8px; border:1px solid #ddd; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <h2 style="text-align:center; margin-top:0; color:#222;">Menu Manager Login</h2>
                    <p style="text-align:center; color:#666; margin-bottom:20px;">Please log in with your Menu Manager account.</p>' 
                    . wp_login_form( $login_args ) . 
                '</div>';
    }

    // 2. STRICT ROLE CHECK: Admin OR Menu Manager ONLY
    if ( !current_user_can('manage_options') && !current_user_can('menu_manager') ) {
        return '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border-left: 4px solid #dc3232; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <p style="font-size: 1.1em; color: #dc3232;"><strong>Access Denied:</strong> You do not have permission to view the Menu Manager. Dedicated Menu Manager account required.</p>
                    <a href="' . wp_logout_url( get_permalink() ) . '" style="display: inline-block; margin-top: 15px; background: #222; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px;">Log Out & Switch Accounts</a>
                </div>';
    }

    global $wpdb;
    $table_foods = $wpdb->prefix . 'cmp_foods';
    $notification = '';

    // ACTION 1: CSV Upload & Wipe
    if ( isset($_POST['upload_csv_frontend']) && isset($_FILES['csv_file']) ) {
        if ($_FILES['csv_file']['error'] == 0) {
            if (isset($_POST['wipe_menu']) && $_POST['wipe_menu'] == 'yes') {
                $wpdb->query("TRUNCATE TABLE $table_foods");
                $notification = '<div style="background:#fff3cd; color:#856404; padding:12px; border-radius:4px; margin-bottom:20px;">Old menu wiped clean!</div>';
            } else {
                $wpdb->query("UPDATE $table_foods SET is_active = 0"); 
            }

            $file = fopen($_FILES['csv_file']['tmp_name'], "r");
            fgetcsv($file); 
            $count = 0;
            while (($data = fgetcsv($file, 1000, ",")) !== FALSE) {
                if(empty($data[0]) || empty($data[1])) continue;
                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_foods WHERE food_name = %s", sanitize_text_field($data[1])));
                if ($existing && !isset($_POST['wipe_menu'])) {
                    $wpdb->update($table_foods, array('category_name'=>sanitize_text_field($data[0]), 'description'=>sanitize_text_field($data[2]), 'calories'=>intval($data[3]), 'total_fat'=>floatval($data[4]), 'carbohydrates'=>floatval($data[5]), 'protein'=>floatval($data[6]), 'is_active'=>1), array('id'=>$existing));
                } else {
                    $wpdb->insert($table_foods, array('category_name'=>sanitize_text_field($data[0]), 'food_name'=>sanitize_text_field($data[1]), 'description'=>sanitize_text_field($data[2]), 'calories'=>intval($data[3]), 'total_fat'=>floatval($data[4]), 'carbohydrates'=>floatval($data[5]), 'protein'=>floatval($data[6]), 'is_active'=>1));
                }
                $count++;
            }
            fclose($file);
            $notification .= '<div style="background:#d4edda; color:#155724; padding:12px; border-radius:4px; margin-bottom:20px;"><strong>Success:</strong> ' . $count . ' items activated.</div>';
        }
    }

    // ACTION 2: Save / Update Single Food Item
    if ( isset($_POST['save_food']) ) {
        $food_id = intval($_POST['food_id']);
        $food_data = array(
            'category_name' => sanitize_text_field($_POST['cat_name']),
            'food_name'     => sanitize_text_field($_POST['food_name']),
            'description'   => sanitize_textarea_field($_POST['desc']),
            'calories'      => intval($_POST['cal']),
            'total_fat'     => floatval($_POST['fat']),
            'carbohydrates' => floatval($_POST['carbs']),
            'protein'       => floatval($_POST['pro']),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0
        );

        if ($food_id > 0) {
            $wpdb->update($table_foods, $food_data, array('id' => $food_id));
            $notification = '<div style="background:#d4edda; color:#155724; padding:12px; border-radius:4px; margin-bottom:20px;"><strong>Success:</strong> Food item updated!</div>';
        } else {
            $wpdb->insert($table_foods, $food_data);
            $notification = '<div style="background:#d4edda; color:#155724; padding:12px; border-radius:4px; margin-bottom:20px;"><strong>Success:</strong> New food item added!</div>';
        }
    }

    // ACTION 3: Delete Food
    if ( isset($_POST['delete_food']) && isset($_POST['food_id']) ) {
        $wpdb->delete($table_foods, array('id' => intval($_POST['food_id'])));
        $notification = '<div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:20px;"><strong>Deleted:</strong> Food item removed permanently.</div>';
    }

    $all_foods = $wpdb->get_results("SELECT * FROM $table_foods ORDER BY category_name ASC, food_name ASC");

    ob_start();
    ?>
    <div style="max-width: 1200px; margin: 0 auto; font-family: inherit;">
        
        <?php echo $notification; ?>

        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px;">
            <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                <h3 style="margin-top: 0; color: #0f172a;">Upload Quarterly CSV</h3>
                <p style="font-size: 0.9em; color: #64748b;">Upload your new menu here. Items not in the CSV will be automatically deactivated.</p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="file" name="csv_file" accept=".csv" required style="margin-bottom: 10px; width: 100%; padding: 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px;">
                    <label style="display: block; margin-bottom: 15px; font-weight: bold; color: #dc2626; font-size: 0.9em;">
                        <input type="checkbox" name="wipe_menu" value="yes"> WIPE CURRENT MENU (Deletes old database)
                    </label>
                    <input type="hidden" name="sa_tab" value="sa-menu"> <!-- ENSURES WE STAY ON THIS TAB AFTER SUBMIT -->
                    <button type="submit" name="upload_csv_frontend" style="background: #38bdf8; color: #0f172a; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; transition: 0.2s;">Upload & Sync Menu</button>
                </form>
            </div>

            <div style="flex: 2; min-width: 400px; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #38bdf8; box-shadow: 0 4px 6px rgba(56,189,248,0.1);">
                <h3 id="form_title" style="margin-top: 0; color: #0f172a;">Add New Food Item</h3>
                <form method="POST" id="crud-form">
                    <input type="hidden" name="sa_tab" value="sa-menu"> <!-- ENSURES WE STAY ON THIS TAB AFTER SUBMIT -->
                    <input type="hidden" name="food_id" id="edit_id" value="0">
                    <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                        <div style="flex: 1;">
                            <label style="display:block; font-weight:bold; margin-bottom:5px; color: #334155;">Category</label>
                            <select name="cat_name" id="edit_cat" required style="width:100%; padding:8px; border-radius:4px; border:1px solid #cbd5e1; background: #f8fafc;">
                                <option value="Breakfast">Breakfast</option>
                                <option value="Lunch">Lunch</option>
                                <option value="Dinner">Dinner</option>
                                <option value="Snacks">Snacks</option>
                                <option value="Juices">Juices</option>
                            </select>
                        </div>
                        <div style="flex: 2;">
                            <label style="display:block; font-weight:bold; margin-bottom:5px; color: #334155;">Food Name</label>
                            <input type="text" name="food_name" id="edit_name" required style="width:100%; padding:8px; border-radius:4px; border:1px solid #cbd5e1; background: #f8fafc;">
                        </div>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; font-weight:bold; margin-bottom:5px; color: #334155;">Description</label>
                        <textarea name="desc" id="edit_desc" rows="2" style="width:100%; padding:8px; border-radius:4px; border:1px solid #cbd5e1; background: #f8fafc;"></textarea>
                    </div>
                    <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                        <div style="flex:1;"><label style="display:block; font-weight:bold; font-size:0.9em; color: #334155;">Calories</label><input type="number" name="cal" id="edit_cal" required style="width:100%; padding:8px; border:1px solid #cbd5e1; background: #f8fafc;"></div>
                        <div style="flex:1;"><label style="display:block; font-weight:bold; font-size:0.9em; color: #334155;">Fat (g)</label><input type="number" step="0.1" name="fat" id="edit_fat" required style="width:100%; padding:8px; border:1px solid #cbd5e1; background: #f8fafc;"></div>
                        <div style="flex:1;"><label style="display:block; font-weight:bold; font-size:0.9em; color: #334155;">Carbs (g)</label><input type="number" step="0.1" name="carbs" id="edit_carbs" required style="width:100%; padding:8px; border:1px solid #cbd5e1; background: #f8fafc;"></div>
                        <div style="flex:1;"><label style="display:block; font-weight:bold; font-size:0.9em; color: #334155;">Protein (g)</label><input type="number" step="0.1" name="pro" id="edit_pro" required style="width:100%; padding:8px; border:1px solid #cbd5e1; background: #f8fafc;"></div>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="color: #334155; font-weight: bold;"><input type="checkbox" name="is_active" id="edit_active" checked> Item is Active & Visible</label>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="save_food" id="btn_save" style="background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; transition: 0.2s;">Save Food Item</button>
                        <button type="button" onclick="resetForm()" style="background: #e2e8f0; color: #334155; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; transition: 0.2s;">Clear Form</button>
                    </div>
                </form>
            </div>
        </div>

        <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
            <div style="flex: 2; min-width: 250px;">
                <input type="text" id="foodSearch" placeholder="🔍 Search food by name or description..." style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 1em; background: #f8fafc;">
            </div>
            <div style="flex: 1; min-width: 200px;">
                <select id="catFilter" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 1em; background: #f8fafc;">
                    <option value="">All Categories</option>
                    <option value="Breakfast">Breakfast</option>
                    <option value="Lunch">Lunch</option>
                    <option value="Dinner">Dinner</option>
                    <option value="Snacks">Snacks</option>
                    <option value="Juices">Juices</option>
                </select>
            </div>
        </div>

        <div style="overflow-x: auto; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <table style="width: 100%; border-collapse: collapse; background: #fff; font-size: 0.95em;">
                <thead>
                    <tr style="background: #0f172a; color: #fff;">
                        <th style="padding: 15px; text-align: left;">Category</th>
                        <th style="padding: 15px; text-align: left;">Food Details</th>
                        <th style="padding: 15px; text-align: center;">Macros</th>
                        <th style="padding: 15px; text-align: center;">Status</th>
                        <th style="padding: 15px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="foodTableBody">
                    <?php foreach($all_foods as $f): 
                        $js_name = htmlspecialchars($f->food_name, ENT_QUOTES);
                        $js_desc = htmlspecialchars($f->description, ENT_QUOTES);
                        $search_text = strtolower(esc_attr($f->food_name . ' ' . $f->description));
                        $cat_text = esc_attr($f->category_name);
                    ?>
                    <tr class="food-row" data-search="<?php echo $search_text; ?>" data-cat="<?php echo $cat_text; ?>" style="border-bottom: 1px solid #f1f5f9; <?php if(!$f->is_active) echo 'background: #f8fafc; opacity: 0.6;'; ?>">
                        <td style="padding: 15px; color: #334155;"><strong><?php echo esc_html($f->category_name); ?></strong></td>
                        <td style="padding: 15px;">
                            <strong style="font-size: 1.1em; color: #0284c7;"><?php echo esc_html($f->food_name); ?></strong><br>
                            <span style="color: #64748b; font-size: 0.9em;"><?php echo esc_html($f->description); ?></span>
                        </td>
                        <td style="padding: 15px; text-align: center; color: #475569; font-size: 0.9em;">
                            Cal: <?php echo $f->calories; ?> | F: <?php echo $f->total_fat; ?> | C: <?php echo $f->carbohydrates; ?> | P: <?php echo $f->protein; ?>
                        </td>
                        <td style="padding: 15px; text-align: center;">
                            <?php if($f->is_active): ?>
                                <span style="background: #dcfce7; color: #065f46; padding: 4px 10px; border-radius: 20px; font-size: 0.85em; font-weight: bold;">Active</span>
                            <?php else: ?>
                                <span style="background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 20px; font-size: 0.85em; font-weight: bold;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 15px; text-align: center;">
                            <div style="display: flex; gap: 8px; justify-content: center;">
                                <button onclick="editFood(<?php echo $f->id; ?>, '<?php echo esc_js($f->category_name); ?>', '<?php echo $js_name; ?>', '<?php echo $js_desc; ?>', <?php echo $f->calories; ?>, <?php echo $f->total_fat; ?>, <?php echo $f->carbohydrates; ?>, <?php echo $f->protein; ?>, <?php echo $f->is_active; ?>)" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; transition: 0.2s;">Edit</button>
                                
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this food item forever?');" style="margin:0;">
                                    <input type="hidden" name="sa_tab" value="sa-menu"> <!-- ENSURES WE STAY ON THIS TAB -->
                                    <input type="hidden" name="food_id" value="<?php echo $f->id; ?>">
                                    <button type="submit" name="delete_food" style="background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; transition: 0.2s;">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div id="foodPagination" style="margin-top: 25px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;"></div>

    </div>

    <script>
    // FORM LOGIC
    function editFood(id, cat, name, desc, cal, fat, carbs, pro, active) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_cat').value = cat;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_desc').value = desc;
        document.getElementById('edit_cal').value = cal;
        document.getElementById('edit_fat').value = fat;
        document.getElementById('edit_carbs').value = carbs;
        document.getElementById('edit_pro').value = pro;
        document.getElementById('edit_active').checked = (active == 1);
        
        document.getElementById('form_title').innerText = "Update Food Item";
        document.getElementById('btn_save').innerText = "Update Food Item";
        document.getElementById('btn_save').style.background = "#38bdf8";
        document.getElementById('btn_save').style.color = "#0f172a";
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('crud-form').reset();
        document.getElementById('edit_id').value = "0";
        document.getElementById('form_title').innerText = "Add New Food Item";
        document.getElementById('btn_save').innerText = "Save Food Item";
        document.getElementById('btn_save').style.background = "#10b981";
        document.getElementById('btn_save').style.color = "white";
    }

    // SEARCH, FILTER & PAGINATION ENGINE
    const rowsPerPage = 10;
    let currentPage = 1;
    let allFoodRows = [];
    let filteredFoods = [];

    document.addEventListener("DOMContentLoaded", function() {
        allFoodRows = Array.from(document.querySelectorAll('#foodTableBody .food-row'));
        filterAndPaginateFoods();
    });

    document.getElementById('foodSearch').addEventListener('keyup', function() {
        currentPage = 1;
        filterAndPaginateFoods();
    });

    document.getElementById('catFilter').addEventListener('change', function() {
        currentPage = 1;
        filterAndPaginateFoods();
    });

    function filterAndPaginateFoods() {
        const query = document.getElementById('foodSearch').value.toLowerCase();
        const cat = document.getElementById('catFilter').value;

        filteredFoods = allFoodRows.filter(row => {
            const searchData = row.getAttribute('data-search');
            const catData = row.getAttribute('data-cat');
            const matchesSearch = query === '' || searchData.includes(query);
            const matchesCat = cat === '' || catData === cat;
            return matchesSearch && matchesCat;
        });

        allFoodRows.forEach(row => row.style.display = 'none');

        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        filteredFoods.slice(start, end).forEach(row => row.style.display = '');

        renderPagination();
    }

    function renderPagination() {
        const paginationContainer = document.getElementById('foodPagination');
        paginationContainer.innerHTML = '';
        
        const totalPages = Math.ceil(filteredFoods.length / rowsPerPage);
        if(totalPages <= 1) return;

        for(let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('button');
            btn.innerText = i;
            
            btn.style.padding = "8px 16px";
            btn.style.border = "none";
            btn.style.background = (i === currentPage) ? "#0f172a" : "#e2e8f0";
            btn.style.color = (i === currentPage) ? "#38bdf8" : "#475569";
            btn.style.cursor = "pointer";
            btn.style.borderRadius = "6px";
            btn.style.fontWeight = "bold";
            btn.style.transition = "all 0.2s";
            
            btn.onmouseover = function() { if(i !== currentPage) this.style.background = "#cbd5e1"; };
            btn.onmouseout = function() { if(i !== currentPage) this.style.background = "#e2e8f0"; };
            
            btn.onclick = function(e) {
                e.preventDefault();
                currentPage = i;
                filterAndPaginateFoods();
                
                // Scroll slightly up when paginating so the user stays oriented
                const searchBar = document.getElementById('foodSearch');
                if(searchBar) {
                    const yOffset = searchBar.getBoundingClientRect().top + window.pageYOffset - 50;
                    window.scrollTo({top: yOffset, behavior: 'smooth'});
                }
            };
            paginationContainer.appendChild(btn);
        }
    }
    </script>
    <?php
    return ob_get_clean();
}
