<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// 1. Register the shortcode
add_shortcode( 'public_meal_menu', 'cmp_render_public_menu' );

function cmp_render_public_menu() {
    global $wpdb;
    $table_foods = $wpdb->prefix . 'cmp_foods';

    // Fetch active foods
    $foods = $wpdb->get_results( "SELECT * FROM $table_foods WHERE is_active = 1" );

    if ( empty( $foods ) ) {
        return '<p style="text-align:center; padding: 20px;">Our new quarterly menu is currently being prepared. Check back soon!</p>';
    }

    // Group foods by category
    $grouped_foods = array();
    foreach ( $foods as $food ) {
        $grouped_foods[$food->category_name][] = $food;
    }

    // Explicitly Sort Categories based on user preference
    $category_order = array('Breakfast', 'Lunch', 'Dinner', 'Snacks', 'Juices');

    ob_start();
    ?>
    <style>
        .cmp-menu-wrapper { max-width: 1200px; margin: 0 auto; font-family: inherit; position: relative; }
        
        /* STICKY NAVIGATION BAR */
        .cmp-sticky-nav {
            position: sticky;
            top: 0; /* Adjust this value if your WordPress theme has a fixed header (e.g., top: 80px;) */
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            padding: 15px 0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 30px;
        }

        .cmp-menu-tabs { 
            display: flex; 
            gap: 10px; 
            overflow-x: auto; 
            -webkit-overflow-scrolling: touch; 
            padding: 0 15px 5px 15px;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none;  /* IE and Edge */
        }
        .cmp-menu-tabs::-webkit-scrollbar { display: none; /* Hide scrollbar for Chrome/Safari/Opera */ }
        
        .cmp-menu-tab-btn {
            background: #f1f5f9;
            border: 2px solid transparent;
            color: #475569;
            font-weight: bold;
            font-size: 1.05em;
            padding: 10px 22px;
            border-radius: 50px; /* Pill shape */
            cursor: pointer;
            white-space: nowrap; /* Prevent wrapping on mobile */
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            text-decoration: none;
            display: inline-block;
        }
        .cmp-menu-tab-btn:hover { background: #e2e8f0; color: #0f172a; }
        .cmp-menu-tab-btn.active {
            background: #0073aa;
            color: #ffffff;
            box-shadow: 0 4px 8px rgba(0, 115, 170, 0.25);
            transform: scale(1.02);
        }

        /* CONTENT SECTIONS */
        .cmp-menu-section {
            scroll-margin-top: 120px; /* Prevents sticky nav from covering the title when scrolling to it */
            margin-bottom: 60px;
            padding: 0 15px;
        }
        
        .cmp-section-title {
            font-size: 1.8em;
            color: #0f172a;
            margin-bottom: 25px;
            border-bottom: 3px solid #f1f5f9;
            padding-bottom: 10px;
            font-weight: 800;
        }

        /* GRID & CARDS */
        .cmp-menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .cmp-food-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .cmp-food-card:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.06); border-color: #cbd5e1; }
        .cmp-food-card h4 { margin: 0 0 10px 0; color: #0f172a; font-size: 1.25em; line-height: 1.3; }
        .cmp-food-card p { font-size: 0.95em; color: #475569; margin: 0 0 20px 0; line-height: 1.6; flex-grow: 1; }
        .cmp-macros { display: flex; justify-content: space-between; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px dashed #cbd5e1; font-size: 0.85em; color: #334155; }
        .cmp-macros div { text-align: center; display: flex; flex-direction: column; gap: 4px; }
        .cmp-macros div strong { color: #64748b; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.5px; }
        .cmp-macros div span { font-weight: bold; color: #0f172a; font-size: 1.1em; }
        
        /* MOBILE ADJUSTMENTS */
        @media (max-width: 768px) {
            .cmp-menu-grid { grid-template-columns: 1fr; gap: 15px; }
            .cmp-food-card { padding: 15px; }
            .cmp-section-title { font-size: 1.5em; margin-bottom: 15px; }
        }
    </style>

    <div class="cmp-menu-wrapper">
        
        <div class="cmp-sticky-nav">
            <div class="cmp-menu-tabs">
                <?php
                $first_tab = true;
                foreach ( $category_order as $cat ) {
                    if ( !empty( $grouped_foods[$cat] ) ) {
                        $active_class = $first_tab ? 'active' : '';
                        $safe_id = 'cmp-section-' . strtolower(str_replace(' ', '-', $cat));
                        echo '<button class="cmp-menu-tab-btn ' . $active_class . '" data-target="' . esc_attr($safe_id) . '">' . esc_html( $cat ) . '</button>';
                        $first_tab = false;
                    }
                }
                ?>
            </div>
        </div>

        <div class="cmp-menu-content-area">
            <?php
            foreach ( $category_order as $cat ) {
                if ( !empty( $grouped_foods[$cat] ) ) {
                    $safe_id = 'cmp-section-' . strtolower(str_replace(' ', '-', $cat));
                    
                    echo '<div id="' . esc_attr($safe_id) . '" class="cmp-menu-section">';
                    echo '<h2 class="cmp-section-title">' . esc_html( $cat ) . '</h2>';
                    echo '<div class="cmp-menu-grid">';
                    
                    foreach ( $grouped_foods[$cat] as $food ) {
                        ?>
                        <div class="cmp-food-card">
                            <div>
                                <h4><?php echo esc_html( $food->food_name ); ?></h4>
                                <p><?php echo esc_html( $food->description ); ?></p>
                            </div>
                            <div class="cmp-macros">
                                <div><strong>Cal</strong><span><?php echo intval( $food->calories ); ?></span></div>
                                <div><strong>Fat</strong><span><?php echo floatval( $food->total_fat ); ?>g</span></div>
                                <div><strong>Carbs</strong><span><?php echo floatval( $food->carbohydrates ); ?>g</span></div>
                                <div><strong>Pro</strong><span><?php echo floatval( $food->protein ); ?>g</span></div>
                            </div>
                        </div>
                        <?php
                    }
                    
                    echo '</div></div>';
                }
            }
            ?>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const navBtns = document.querySelectorAll('.cmp-menu-tab-btn');
        const sections = document.querySelectorAll('.cmp-menu-section');
        let isClickScrolling = false;

        // 1. Smooth scroll to section on pill click
        navBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                isClickScrolling = true; // Pause observer temporarily during click scroll

                // Update active classes immediately
                navBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                // Scroll to target
                const targetId = this.getAttribute('data-target');
                const targetSection = document.getElementById(targetId);
                
                if (targetSection) {
                    targetSection.scrollIntoView({ behavior: 'smooth' });
                    
                    // Re-enable the observer after scroll animation finishes
                    setTimeout(() => { isClickScrolling = false; }, 800);
                }
            });
        });

        // 2. Intersection Observer (Scroll-Spy) to highlight pills on scroll
        // Configured to trigger when a section hits the top 20% of the viewport
        const observerOptions = {
            root: null,
            rootMargin: '-15% 0px -85% 0px', 
            threshold: 0
        };

        const observer = new IntersectionObserver((entries) => {
            if (isClickScrolling) return; // Don't run this while a click-scroll is happening

            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const activeId = entry.target.id;
                    
                    navBtns.forEach(btn => {
                        btn.classList.remove('active');
                        if (btn.getAttribute('data-target') === activeId) {
                            btn.classList.add('active');
                            
                            // Automatically scroll the pill bar horizontally so the active pill stays visible on mobile
                            btn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                        }
                    });
                }
            });
        }, observerOptions);

        sections.forEach(section => observer.observe(section));
    });
    </script>
    <?php
    return ob_get_clean();
}