<?php
/**
 * Customer Portal - Frontend Styles (CSS) & Interactive Scripts (JS)
 * Part 2 of the 3-file Customer Portal modular architecture.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Prevent duplicate loading
if ( defined( 'CMP_CUSTOMER_PORTAL_ASSETS_LOADED' ) ) {
    return;
}
define( 'CMP_CUSTOMER_PORTAL_ASSETS_LOADED', true );

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

/**
 * Renders the global food JSON dataset and responsive CSS stylesheet for the Customer Portal.
 *
 * @param array $foods Array of active food objects from wp_cmp_foods.
 */
if ( ! function_exists( 'cmp_render_customer_portal_styles' ) ) {
    function cmp_render_customer_portal_styles( $foods = array() ) {
        ?>
        <script>
            var cmpGlobalFoodData = <?php echo wp_json_encode( is_array( $foods ) ? $foods : array() ); ?>;
        </script>

        <style>
            .cmp-dashboard-wrap {
                max-width: 1220px;
                margin: 0 auto;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                overflow-x: hidden;
                box-sizing: border-box;
                position: relative;
                padding: 0 12px 60px 12px;
            }
            .cmp-dashboard-wrap *,
            .cmp-dashboard-wrap *::before,
            .cmp-dashboard-wrap *::after {
                box-sizing: border-box;
            }

            /* Ironclad WordPress Twemoji / Inline Icon Size Lock */
            .cmp-dashboard-wrap img.emoji,
            .cmp-dashboard-wrap img.wp-smiley {
                width: 16px !important;
                height: 16px !important;
                max-width: 16px !important;
                max-height: 16px !important;
                display: inline-block !important;
                vertical-align: middle !important;
                margin: 0 4px !important;
                border: none !important;
                box-shadow: none !important;
            }
            .cmp-dashboard-wrap svg.cmp-fixed-svg {
                width: 16px !important;
                height: 16px !important;
                min-width: 16px !important;
                min-height: 16px !important;
                max-width: 16px !important;
                max-height: 16px !important;
                flex-shrink: 0 !important;
                display: inline-block !important;
                vertical-align: middle !important;
            }

            /* =========================================================
               1. HEADER BANNER, SUBSCRIBE CTA & RESPONSIVE PLAN TABS
               ========================================================= */
            .cmp-header-banner {
                background: linear-gradient(135deg, rgba(27, 94, 32, 0.94) 0%, rgba(55, 146, 55, 0.88) 55%, rgba(104, 185, 70, 0.84) 100%) !important;
                backdrop-filter: blur(18px) !important;
                -webkit-backdrop-filter: blur(18px) !important;
                color: #fff !important;
                padding: 24px 28px !important;
                border-radius: 18px 18px 0 0 !important;
                border: 1.5px solid #DCECC9 !important;
                border-bottom: 4px solid #68B946 !important;
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                flex-wrap: wrap !important;
                gap: 15px !important;
                box-shadow: 0 10px 28px rgba(27, 94, 32, 0.12) !important;
            }
            .cmp-header-banner h1 {
                margin: 0 !important;
                color: #ffffff !important;
                font-size: 1.85em !important;
                font-weight: 800 !important;
                line-height: 1.2 !important;
            }
            .cmp-header-banner p {
                margin: 5px 0 0 0 !important;
                color: #EEF7E3 !important;
                font-weight: 600 !important;
                font-size: 0.98em !important;
            }

            .cmp-header-actions {
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
                flex-wrap: wrap !important;
            }
            .cmp-hdr-btn {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 7px !important;
                padding: 10px 18px !important;
                border-radius: 10px !important;
                font-weight: 800 !important;
                font-size: 0.9rem !important;
                text-decoration: none !important;
                transition: all 0.2s ease !important;
                white-space: nowrap !important;
                line-height: 1.2 !important;
            }
            .cmp-hdr-btn-subscribe {
                background: #ffffff !important;
                color: #1B5E20 !important;
                border: 1.5px solid #DCECC9 !important;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.10) !important;
            }
            .cmp-hdr-btn-subscribe:hover {
                background: #EEF7E3 !important;
                color: #1B5E20 !important;
                transform: translateY(-1px) !important;
            }
            .cmp-hdr-btn-export {
                background: rgba(27, 94, 32, 0.72) !important;
                color: #ffffff !important;
                border: 1.5px solid rgba(220, 236, 201, 0.65) !important;
            }
            .cmp-hdr-btn-export:hover {
                background: #1B5E20 !important;
                color: #ffffff !important;
                transform: translateY(-1px) !important;
            }
            .cmp-hdr-btn-logout {
                background: #dc2626 !important;
                color: #ffffff !important;
                border: 1.5px solid rgba(254, 202, 202, 0.4) !important;
            }
            .cmp-hdr-btn-logout:hover {
                background: #b91c1c !important;
                color: #ffffff !important;
            }

            /* Plan Navigation Tabs — Zero Ugly Grey Scrollbar Across All Devices */
            .cmp-tab-nav {
                display: flex !important;
                background: #ffffff !important;
                border: 1.5px solid #DCECC9 !important;
                border-top: none !important;
                border-bottom: 2px solid #DCECC9 !important;
                padding: 6px 14px 0 14px !important;
                gap: 8px !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                scrollbar-width: none !important; /* Firefox */
                -ms-overflow-style: none !important; /* IE/Edge */
                align-items: stretch !important;
                border-radius: 0 0 14px 14px !important;
            }
            .cmp-tab-nav::-webkit-scrollbar {
                display: none !important; /* Chrome, Safari, iOS */
                width: 0 !important;
                height: 0 !important;
            }
            .cmp-tab-btn {
                padding: 11px 20px !important;
                border: none !important;
                background: transparent !important;
                font-weight: 800 !important;
                font-size: 0.94rem !important;
                cursor: pointer !important;
                border-bottom: 3px solid transparent !important;
                border-radius: 10px 10px 0 0 !important;
                color: #64748b !important;
                white-space: nowrap !important;
                line-height: 1.35 !important;
                text-align: center !important;
                transition: all 0.2s ease !important;
                flex: 0 0 auto !important;
            }
            .cmp-tab-btn:hover {
                color: #1B5E20 !important;
                background: rgba(238, 247, 227, 0.3) !important;
            }
            .cmp-tab-btn.active {
                color: #1B5E20 !important;
                border-bottom-color: #379237 !important;
                background: rgba(238, 247, 227, 0.65) !important;
            }
            .cmp-tab-content { display: none; padding: 20px 0; }
            .cmp-tab-content.active { display: block; }

            /* Tablet Header & Tabs Optimization (601px - 991px) */
            @media (min-width: 601px) and (max-width: 991px) {
                .cmp-header-banner {
                    background: linear-gradient(125deg, rgba(220, 236, 201, 0.95) 0%, rgba(104, 185, 70, 0.42) 100%) !important;
                    border: 1.5px solid #379237 !important;
                    padding: 20px 22px !important;
                }
                .cmp-header-banner h1 { color: #231F20 !important; font-size: 1.55em !important; }
                .cmp-header-banner p { color: #1B5E20 !important; }
                .cmp-hdr-btn-subscribe {
                    background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important;
                    color: #ffffff !important;
                    border-color: #1B5E20 !important;
                }
                .cmp-hdr-btn-subscribe:hover {
                    filter: brightness(1.06) !important;
                    color: #ffffff !important;
                }
                .cmp-hdr-btn-export {
                    background: #ffffff !important;
                    color: #1B5E20 !important;
                    border-color: #379237 !important;
                }
                .cmp-hdr-btn-export:hover {
                    background: #EEF7E3 !important;
                    color: #1B5E20 !important;
                }
                .cmp-tab-nav {
                    padding: 8px 12px !important;
                    flex-wrap: wrap !important;
                    gap: 8px !important;
                }
                .cmp-tab-btn {
                    flex: 1 1 auto !important;
                    min-width: 180px !important;
                    white-space: normal !important;
                    border: 1px solid #e2e8f0 !important;
                    border-bottom: 3px solid transparent !important;
                    border-radius: 10px !important;
                    padding: 10px 14px !important;
                    font-size: 0.88rem !important;
                }
                .cmp-tab-btn.active {
                    border-color: #68B946 !important;
                    border-bottom: 3px solid #379237 !important;
                }
            }

            /* Mobile Header & Tabs Optimization (<= 600px) */
            @media (max-width: 600px) {
                .cmp-header-banner {
                    background: linear-gradient(145deg, rgba(55, 146, 55, 0.96) 0%, rgba(27, 94, 32, 0.94) 100%) !important;
                    border: 1.5px solid #68B946 !important;
                    border-radius: 16px 16px 0 0 !important;
                    padding: 15px 14px !important;
                    gap: 12px !important;
                }
                .cmp-header-banner > div:first-child { width: 100% !important; }
                .cmp-header-banner h1 { color: #ffffff !important; font-size: 1.32em !important; }
                .cmp-header-banner p { color: #DCECC9 !important; font-size: 0.88em !important; margin-top: 3px !important; }
                .cmp-header-actions {
                    width: 100% !important;
                    display: grid !important;
                    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                    gap: 8px !important;
                }
                .cmp-hdr-btn-subscribe {
                    grid-column: 1 / -1 !important;
                    width: 100% !important;
                    padding: 10px 14px !important;
                    font-size: 0.86rem !important;
                }
                .cmp-hdr-btn-export,
                .cmp-hdr-btn-logout {
                    width: 100% !important;
                    padding: 8px 10px !important;
                    font-size: 0.8rem !important;
                }

                /* Mobile Plan Tabs: Clean Full-Width / Multi-Card Pill Layout with Zero Text Clipping */
                .cmp-tab-nav {
                    padding: 8px 10px !important;
                    display: flex !important;
                    flex-wrap: wrap !important;
                    gap: 6px !important;
                    overflow-x: hidden !important;
                }
                .cmp-tab-btn {
                    flex: 1 1 100% !important;
                    width: 100% !important;
                    max-width: 100% !important;
                    white-space: normal !important;
                    word-break: break-word !important;
                    padding: 9px 12px !important;
                    font-size: 0.86rem !important;
                    line-height: 1.3 !important;
                    border-radius: 9px !important;
                    border: 1.5px solid #e2e8f0 !important;
                }
                .cmp-tab-btn.active {
                    background: #EEF7E3 !important;
                    color: #1B5E20 !important;
                    border: 1.5px solid #379237 !important;
                    box-shadow: 0 2px 8px rgba(27, 94, 32, 0.08) !important;
                }
            }

            /* =========================================================
               2. ACCOUNT DETAILS & ACTIVE LOGISTICS DRAWER (OPTION 5A)
               ========================================================= */
            .cmp-info-drawer-wrap {
                background: #ffffff;
                border: 1.5px solid #DCECC9;
                border-radius: 14px;
                margin-bottom: 18px;
                box-shadow: 0 4px 14px rgba(27, 94, 32, 0.04);
                overflow: hidden;
            }
            .cmp-mobile-info-toggle {
                display: none;
                width: 100%;
                background: linear-gradient(135deg, #f8fafc 0%, #EEF7E3 100%);
                border: none;
                border-bottom: 1px solid transparent;
                padding: 11px 14px;
                text-align: left;
                cursor: pointer;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                font-family: inherit;
            }
            .cmp-mobile-info-toggle-left {
                display: flex;
                flex-direction: column;
                gap: 2px;
                min-width: 0;
                flex: 1;
            }
            .cmp-mobile-info-kicker {
                font-size: 0.68rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #1B5E20;
            }
            .cmp-mobile-info-summary-line {
                font-size: 0.82rem;
                font-weight: 700;
                color: #231F20;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .cmp-mobile-info-pill {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                background: #ffffff;
                color: #1B5E20;
                border: 1px solid #68B946;
                border-radius: 999px;
                padding: 4px 10px;
                font-size: 0.74rem;
                font-weight: 800;
                white-space: nowrap;
                flex-shrink: 0;
            }

            .cmp-info-drawer-body { padding: 22px 24px 18px 24px; }
            .cmp-info-cards-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            .cmp-Allergy-Dynamic-box {
                margin-top: 12px;
                padding: 9px 12px;
                background: #fffbeb;
                border: 1px solid #fde68a;
                border-left: 4px solid #f59e0b;
                border-radius: 8px;
                font-size: 0.88rem;
                color: #92400e;
            }
            .cmp-saved-profile-bar {
                margin-bottom: 10px;
                padding: 8px 10px;
                background: #EEF7E3;
                border: 1px solid #68B946;
                border-radius: 8px;
            }
            .cmp-saved-profile-bar label {
                display: block;
                font-size: 0.76rem;
                font-weight: 800;
                color: #1B5E20;
                text-transform: uppercase;
                letter-spacing: 0.03em;
                margin-bottom: 4px;
            }
            .cmp-saved-profile-select {
                width: 100%;
                padding: 6px 8px;
                font-size: 0.85rem;
                font-weight: 600;
                color: #1e293b;
                background: #ffffff;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                cursor: pointer;
            }
            .cmp-cutoff-callout {
                margin-top: 14px;
                padding: 10px 14px;
                background: #f8fafc;
                border: 1px dashed #cbd5e1;
                border-radius: 10px;
                text-align: center;
            }

            .cmp-recipient-label-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                background: #EEF7E3;
                color: #1B5E20;
                border: 1px solid #68B946;
                padding: 2px 9px;
                border-radius: 999px;
                font-size: 0.82em;
                font-weight: 700;
                line-height: 1.3;
                white-space: nowrap;
            }

            @media (min-width: 769px) and (max-width: 1024px) {
                .cmp-info-drawer-body { padding: 16px; }
                .cmp-info-cards-grid { gap: 14px; }
                .cmp-account-card-box, .cmp-logistics-card-box { padding: 14px; }
                .cmp-kv-grid { grid-template-columns: 88px minmax(0, 1fr); font-size: 0.86rem; row-gap: 5px; }
            }

            @media (max-width: 768px) {
                .cmp-mobile-info-toggle { display: flex !important; }
                .cmp-info-drawer-wrap:not(.is-drawer-open) .cmp-info-drawer-body { display: none !important; }
                .cmp-info-drawer-wrap.is-drawer-open .cmp-mobile-info-toggle { border-bottom-color: #DCECC9; }
                .cmp-info-drawer-body { padding: 12px; }
                .cmp-info-cards-grid { grid-template-columns: minmax(0, 1fr); gap: 12px; }
                .cmp-account-card-box, .cmp-logistics-card-box { padding: 12px; }
                .cmp-kv-grid { grid-template-columns: 84px minmax(0, 1fr); font-size: 0.84rem; row-gap: 5px; }
                .cmp-cutoff-callout { margin-top: 10px; padding: 8px 10px; font-size: 0.82rem; }
            }

            /* =========================================================
               3. SCHEDULE UTILITY & KEYBOARD FAST-ENTRY TOOLBAR (OPTION 1A)
               ========================================================= */
            .cmp-schedule-toolbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 12px;
                background: #f8fafc;
                border: 1.5px solid #DCECC9;
                border-radius: 12px;
                padding: 12px 16px;
                margin-bottom: 16px;
            }
            .cmp-kbd-guide {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
                font-size: 0.82rem;
                color: #334155;
                font-weight: 600;
            }
            .cmp-kbd-key {
                display: inline-block;
                background: #ffffff;
                color: #1B5E20;
                border: 1px solid #cbd5e1;
                border-bottom: 2px solid #94a3b8;
                border-radius: 5px;
                padding: 2px 6px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                font-size: 0.76rem;
                font-weight: 800;
            }
            .cmp-toolbar-actions {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
            }
            .cmp-util-btn {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 8px !important;
                background: #ffffff;
                color: #1B5E20;
                border: 1.5px solid #68B946;
                border-radius: 10px;
                padding: 8px 15px;
                font-size: 0.84rem;
                font-weight: 800;
                cursor: pointer;
                transition: all 0.18s ease;
                white-space: nowrap;
                line-height: 1.2 !important;
            }
            .cmp-util-btn:hover {
                background: #EEF7E3;
                border-color: #379237;
            }
            .cmp-util-btn.primary-util {
                background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important;
                color: #ffffff !important;
                border: 1.5px solid #1B5E20 !important;
                box-shadow: 0 4px 12px rgba(27, 94, 32, 0.20) !important;
            }
            .cmp-util-btn.primary-util:hover {
                filter: brightness(1.08);
                transform: translateY(-1px);
            }
            .cmp-jump-pending-btn {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                flex-wrap: nowrap !important;
                letter-spacing: normal !important;
                gap: 10px !important;
            }
            .cmp-jump-btn-left {
                display: flex !important;
                align-items: center !important;
                gap: 7px !important;
                flex: 1 1 auto !important;
                min-width: 0 !important;
                overflow: hidden !important;
                text-align: left !important;
            }
            .cmp-jump-btn-left > span,
            .cmp-jump-pending-btn > span:not(.cmp-pending-count-badge) {
                display: block !important;
                flex: 1 1 auto !important;
                min-width: 0 !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                white-space: nowrap !important;
                letter-spacing: -0.01em !important;
                line-height: 1.25 !important;
                text-align: left !important;
            }
            .cmp-pending-count-badge {
                position: static !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                flex: 0 0 auto !important;
                background: rgba(15, 41, 18, 0.36) !important;
                color: #ffffff !important;
                border: 1px solid rgba(220, 236, 201, 0.75) !important;
                border-radius: 999px !important;
                padding: 3px 10px !important;
                font-size: 0.74rem !important;
                font-weight: 800 !important;
                letter-spacing: 0 !important;
                line-height: 1.1 !important;
                white-space: nowrap !important;
                flex-shrink: 0 !important;
                margin-left: 8px !important;
            }

            /* Pulse Highlight When Scrolling to Pending Row */
            @keyframes cmpPendingPulse {
                0%   { box-shadow: 0 0 0 0 rgba(55, 146, 55, 0.55); }
                50%  { box-shadow: 0 0 0 10px rgba(55, 146, 55, 0); }
                100% { box-shadow: 0 0 0 0 rgba(55, 146, 55, 0); }
            }
            .cmp-table tr.cmp-day-row.cmp-pulse-target {
                animation: cmpPendingPulse 1.1s ease-out 2;
                border-color: #379237 !important;
            }

            /* =========================================================
               UNSAVED CHANGES ("DIRTY STATE") UI/UX & PULSE ANIMATION
               ========================================================= */
            @keyframes cmpSavePulse {
                0%   { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.65), 0 4px 10px rgba(217, 119, 6, 0.28); transform: scale(1); }
                50%  { box-shadow: 0 0 0 8px rgba(245, 158, 11, 0), 0 6px 15px rgba(217, 119, 6, 0.42); transform: scale(1.02); }
                100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0), 0 4px 10px rgba(217, 119, 6, 0.28); transform: scale(1); }
            }
            .cmp-table tr.cmp-day-row.is-unsaved {
                background: #fffbeb !important;
                border-color: #f59e0b !important;
                border-left: 6px solid #d97706 !important;
                box-shadow: 0 6px 20px rgba(245, 158, 11, 0.16) !important;
            }
            .cmp-table tr.cmp-day-row.is-unsaved td.cmp-cell-day {
                background: #fef3c7 !important;
                color: #b45309 !important;
                border-color: #f59e0b !important;
            }
            .cmp-table tr.cmp-day-row.is-unsaved .cmp-save-row:not(:disabled) {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
                color: #ffffff !important;
                border: 1.5px solid #fde68a !important;
                animation: cmpSavePulse 1.75s infinite ease-in-out !important;
            }
            .cmp-table tr.cmp-day-row.is-unsaved .cmp-save-row:hover:not(:disabled) {
                background: linear-gradient(135deg, #d97706 0%, #b45309 100%) !important;
                filter: brightness(1.04);
            }
            .cmp-status-pill.pill-unsaved {
                color: #92400e !important;
                background: #fef3c7 !important;
                border: 1.5px solid #f59e0b !important;
            }
            .cmp-status-pill.pill-meals-pending {
                color: #9a3412 !important;
                background: #ffedd5 !important;
                border: 1.5px solid #fdba74 !important;
            }
            .cmp-undo-row {
                display: none !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 5px !important;
                height: 48px !important;
                padding: 0 13px !important;
                border-radius: 10px !important;
                border: 1.5px solid #cbd5e1 !important;
                background: #ffffff !important;
                color: #334155 !important;
                font-size: 13px !important;
                font-weight: 800 !important;
                cursor: pointer !important;
                white-space: nowrap !important;
                transition: all 0.18s ease !important;
                flex-shrink: 0 !important;
            }
            .cmp-undo-row:hover {
                background: #f1f5f9 !important;
                color: #0f172a !important;
                border-color: #94a3b8 !important;
            }
            .cmp-table tr.cmp-day-row.is-unsaved .cmp-undo-row {
                display: inline-flex !important;
            }
            .cmp-table tr.cmp-day-row.is-unsaved .cmp-secondary-actions {
                display: none !important;
            }
            .cmp-table tr.cmp-day-row.status-void td.cmp-cell-day {
                cursor: default !important;
            }
            .cmp-table tr.cmp-day-row.status-void td.cmp-cell-day::after {
                display: none !important;
            }
            .cmp-table tr.cmp-day-row.status-void td.cmp-collapsed-summary {
                background: #fff1f2 !important;
                border: 1px dashed #fda4af !important;
                color: #9f1239 !important;
                cursor: default !important;
            }
            .cmp-table tr.cmp-day-row.status-void td.cmp-collapsed-summary .cmp-expand-hint {
                display: none !important;
            }
            .cmp-unsaved-float-bar {
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(15, 23, 42, 0.96);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                color: #ffffff;
                border: 1.5px solid #f59e0b;
                border-radius: 12px;
                padding: 8px 16px;
                display: none;
                align-items: center;
                gap: 10px;
                box-shadow: 0 10px 26px rgba(15, 23, 42, 0.32);
                z-index: 9998;
                font-size: 0.84rem;
                font-weight: 700;
                max-width: 92vw;
                flex-wrap: nowrap;
                white-space: nowrap;
                justify-content: center;
            }
            .cmp-unsaved-float-bar.is-active {
                display: inline-flex !important;
            }
            .cmp-unsaved-float-bar .cmp-float-btn {
                background: #f59e0b;
                color: #0f172a;
                border: none;
                border-radius: 8px;
                padding: 5px 12px;
                font-size: 0.78rem;
                font-weight: 800;
                cursor: pointer;
                transition: all 0.15s ease;
                white-space: nowrap;
            }
            .cmp-unsaved-float-bar .cmp-float-btn:hover { background: #fbbf24; }
            .cmp-unsaved-float-bar .cmp-float-btn.cmp-float-undo-all {
                background: transparent;
                color: #fde68a;
                border: 1px solid rgba(253, 230, 138, 0.5);
            }
            .cmp-unsaved-float-bar .cmp-float-btn.cmp-float-undo-all:hover {
                background: rgba(253, 230, 138, 0.15);
                color: #ffffff;
            }

            /* =========================================================
               4. UNIFIED STACKED DAY CARD LAYOUT & POS CHECK BADGES
               ========================================================= */
            .cmp-table-responsive {
                overflow: visible !important;
                background: transparent !important;
                border: none !important;
                width: 100%;
                box-shadow: none !important;
            }
            .cmp-table { width: 100% !important; display: block !important; border-collapse: separate; }
            .cmp-table thead { display: none !important; }
            .cmp-table tbody { display: block !important; width: 100% !important; }

            .cmp-table tr.cmp-day-row {
                margin-bottom: 16px !important;
                border-radius: 16px !important;
                padding: 16px 20px !important;
                background: #ffffff !important;
                border: 2px solid #dbe3ec !important;
                box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04) !important;
                transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
            }
            .cmp-table tr.cmp-day-row.status-pending:not(.is-unsaved) { border-color: #fde68a !important; }
            .cmp-table tr.cmp-day-row.status-saved:not(.is-unsaved) { border-color: #bbf7d0 !important; }
            .cmp-table tr.cmp-day-row.status-void { border-color: #fecdd3 !important; opacity: 0.82; }

            .cmp-table tr.cmp-day-row:focus-within:not(.is-unsaved) {
                border-color: #379237 !important;
                box-shadow: 0 0 0 4px rgba(104, 185, 70, 0.22), 0 10px 25px rgba(27, 94, 32, 0.10) !important;
            }

            .cmp-mobile-label {
                display: block !important;
                font-weight: 800 !important;
                color: #1B5E20 !important;
                margin-bottom: 6px !important;
                font-size: 0.78rem !important;
                text-transform: uppercase !important;
                letter-spacing: 0.05em !important;
            }

            .cmp-table select,
            .cmp-table input[type="text"],
            .cmp-table input[type="date"] {
                -webkit-appearance: none !important;
                appearance: none !important;
                width: 100% !important;
                min-height: 48px !important;
                padding: 10px 38px 10px 14px !important;
                font-size: 14.5px !important;
                line-height: 1.35 !important;
                background-color: #f8fafc !important;
                border: 2px solid #cbd5e1 !important;
                border-radius: 10px !important;
                box-shadow: 0 2px 0 #cbd5e1 !important;
                font-weight: 700 !important;
                color: #231F20 !important;
                margin-bottom: 0 !important;
                box-sizing: border-box !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: clip !important;
                transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
            }
            .cmp-table select {
                background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23379237' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e") !important;
                background-repeat: no-repeat !important;
                background-position: right 12px center !important;
                background-size: 17px !important;
                cursor: pointer;
            }
            .cmp-table select:focus,
            .cmp-table input[type="text"]:focus,
            .cmp-table input[type="date"]:focus {
                background-color: #ffffff !important;
                border-color: #1B5E20 !important;
                box-shadow: 0 0 0 3.5px rgba(55, 146, 55, 0.32) !important;
                outline: none !important;
            }
            .cmp-table select:disabled,
            .cmp-table input[type="text"]:disabled,
            .cmp-table input[type="date"]:disabled {
                background-color: #f1f5f9 !important;
                color: #64748b !important;
                border-color: #e2e8f0 !important;
                box-shadow: none !important;
                cursor: not-allowed !important;
            }
            .cmp-table input[type="date"],
            .cmp-table input[type="text"] {
                padding-right: 12px !important;
                background-image: none !important;
            }

            .cmp-snacks-grid {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 14px !important;
                width: 100% !important;
            }
            .cmp-snacks-grid.single-snack {
                grid-template-columns: minmax(0, 1fr) !important;
            }
            .cmp-snack-slot {
                display: flex !important;
                flex-direction: column !important;
                width: 100% !important;
            }

            .cmp-status-box {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 4px !important;
                width: 100% !important;
                min-height: 48px !important;
                padding: 5px 8px !important;
                background: #f8fafc !important;
                border: 1.5px solid #e2e8f0 !important;
                border-radius: 10px !important;
                box-sizing: border-box !important;
                text-align: center !important;
                overflow: hidden !important;
            }
            .cmp-status-pill {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                min-width: 92px !important;
                max-width: 100% !important;
                padding: 4px 12px !important;
                border-radius: 999px !important;
                font-size: 0.81rem !important;
                font-weight: 800 !important;
                line-height: 1.2 !important;
                white-space: nowrap !important;
                text-align: center !important;
                box-sizing: border-box !important;
            }
            .cmp-status-pill.pill-pending { color: #92400e; background: #fffbeb; border: 1px solid #fde68a; }
            .cmp-status-pill.pill-confirmed { color: #166534; background: #f0fdf4; border: 1px solid #86efac; }
            .cmp-status-pill.pill-successful { color: #166534; background: #dcfce7; border: 1px solid #86efac; }
            .cmp-status-pill.pill-void { color: #9f1239; background: #fff1f2; border: 1px solid #fda4af; }
            .cmp-status-pill.pill-paused { color: #b45309; background: #fef3c7; border: 1px solid #fde68a; }

            .cmp-pos-readonly-pill {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 4px !important;
                padding: 2px 8px !important;
                border-radius: 999px !important;
                font-size: 0.67rem !important;
                font-weight: 800 !important;
                line-height: 1.15 !important;
                letter-spacing: 0.02em !important;
                white-space: nowrap !important;
            }
            .cmp-pos-readonly-pill.pos-is-checked {
                background: #ccfbf1 !important;
                color: #0f766e !important;
                border: 1px solid #5eead4 !important;
            }
            .cmp-pos-readonly-pill.pos-is-pending {
                background: #f1f5f9 !important;
                color: #64748b !important;
                border: 1px solid #cbd5e1 !important;
            }

            .cmp-action-box {
                display: flex !important;
                align-items: center !important;
                justify-content: flex-end !important;
                gap: 8px !important;
                flex-wrap: nowrap !important;
                width: 100% !important;
            }
            .cmp-save-row {
                min-width: 132px !important;
                width: auto !important;
                flex: 1 1 auto !important;
                min-height: 48px !important;
                padding: 10px 14px !important;
                font-size: 14px !important;
                font-weight: 800 !important;
                line-height: 1.2 !important;
                white-space: nowrap !important;
                border-radius: 10px !important;
                border: none !important;
                color: #ffffff !important;
                cursor: pointer !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 6px !important;
                transition: all 0.2s ease !important;
                box-shadow: 0 4px 10px rgba(27, 94, 32, 0.18) !important;
            }
            .cmp-save-row:hover:not(:disabled) { transform: translateY(-1px); filter: brightness(1.06); }
            .cmp-save-row:focus { outline: none !important; box-shadow: 0 0 0 3.5px rgba(55, 146, 55, 0.4) !important; }
            .cmp-save-row:disabled { opacity: 0.7; cursor: not-allowed !important; transform: none !important; animation: none !important; }

            .cmp-secondary-actions {
                display: inline-flex !important;
                gap: 6px !important;
                flex-shrink: 0 !important;
            }
            .cmp-clear-row,
            .cmp-cancel-day-row {
                width: 44px !important;
                height: 48px !important;
                padding: 0 !important;
                border-radius: 10px !important;
                border: none !important;
                color: #fff !important;
                cursor: pointer !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                transition: all 0.2s ease !important;
                flex-shrink: 0 !important;
            }
            .cmp-clear-row { background: #f59e0b !important; }
            .cmp-clear-row:hover:not(:disabled) { background: #d97706 !important; }
            .cmp-cancel-day-row { background: #dc2626 !important; }
            .cmp-cancel-day-row:hover:not(:disabled) { background: #b91c1c !important; }

            /* Colourful Macro Tiles */
            .cmp-macro-display {
                display: block !important;
                width: 100% !important;
                padding: 10px 12px !important;
                background: #f8fafc !important;
                border: 1.5px solid #e2e8f0 !important;
                border-radius: 12px !important;
                box-sizing: border-box !important;
            }
            .cmp-macro-chef-tag {
                display: inline-block;
                background: #EEF7E3;
                color: #1B5E20;
                border: 1px solid #68B946;
                font-size: 0.73rem;
                font-weight: 800;
                padding: 2px 8px;
                border-radius: 999px;
                margin-bottom: 8px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            .cmp-macro-tiles-grid {
                display: grid !important;
                grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
                gap: 8px !important;
                width: 100% !important;
            }
            .cmp-macro-tile {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 7px 6px !important;
                border-radius: 10px !important;
                text-align: center !important;
                box-sizing: border-box !important;
            }
            .cmp-macro-tile .tile-label {
                font-size: 0.66rem !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                letter-spacing: 0.05em !important;
                opacity: 0.88 !important;
                margin-bottom: 2px !important;
                line-height: 1.1 !important;
            }
            .cmp-macro-tile .tile-val {
                font-size: 0.95rem !important;
                font-weight: 900 !important;
                line-height: 1.2 !important;
                white-space: nowrap !important;
            }
            .cmp-macro-tile.tile-cal {
                background: linear-gradient(145deg, #fffbeb 0%, #fef3c7 100%) !important;
                border: 1.5px solid #fcd34d !important;
                color: #b45309 !important;
            }
            .cmp-macro-tile.tile-pro {
                background: linear-gradient(145deg, #ecfdf5 0%, #d1fae5 100%) !important;
                border: 1.5px solid #6ee7b7 !important;
                color: #047857 !important;
            }
            .cmp-macro-tile.tile-carb {
                background: linear-gradient(145deg, #f0f9ff 0%, #e0f2fe 100%) !important;
                border: 1.5px solid #7dd3fc !important;
                color: #0369a1 !important;
            }
            .cmp-macro-tile.tile-fat {
                background: linear-gradient(145deg, #faf5ff 0%, #f3e8ff 100%) !important;
                border: 1.5px solid #d8b4fe !important;
                color: #6d28d9 !important;
            }
            .cmp-macro-banner-state {
                padding: 8px 12px;
                border-radius: 8px;
                font-weight: 700;
                font-size: 0.86rem;
                text-align: center;
            }

            .cmp-chef-box {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                min-height: 48px !important;
                padding: 6px 12px !important;
                background: #f8fafc !important;
                border: 2px solid #cbd5e1 !important;
                border-radius: 10px !important;
                gap: 9px !important;
                cursor: pointer !important;
                transition: all 0.18s ease;
            }
            .cmp-chef-box:focus-within {
                background: #ffffff !important;
                border-color: #1B5E20 !important;
                box-shadow: 0 0 0 3.5px rgba(55, 146, 55, 0.32) !important;
            }
            .cmp-chefs-choice {
                width: 20px !important;
                height: 20px !important;
                accent-color: #379237 !important;
                cursor: pointer !important;
                margin: 0 !important;
            }

            .cmp-table tr.cmp-day-row.is-chefs-choice-compact td.cmp-cell-meal,
            .cmp-table tr.cmp-day-row.is-chefs-choice-compact td.cmp-cell-snack,
            .cmp-table tr.cmp-day-row.is-chefs-choice-compact td.cmp-cell-juice {
                display: none !important;
            }
            .cmp-table tr.cmp-day-row.is-chefs-choice-compact td.cmp-cell-macros .cmp-mobile-label {
                display: none !important;
            }

            .cmp-table td.cmp-collapsed-summary {
                display: none !important;
                grid-column: 1 / span 12 !important;
                background: #f8fafc !important;
                border: 1px dashed #cbd5e1 !important;
                border-radius: 10px !important;
                padding: 9px 14px !important;
                margin-top: 4px !important;
                font-size: 0.85rem !important;
                color: #334155 !important;
                font-weight: 600 !important;
                cursor: pointer !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 10px !important;
            }
            .cmp-table td.cmp-collapsed-summary:hover {
                background: #EEF7E3 !important;
                border-color: #68B946 !important;
                color: #1B5E20 !important;
            }

            /* DESKTOP & TABLET CARD GRID LAYOUT (> 768px) */
            @media (min-width: 769px) {
                .cmp-mobile-status,
                .cmp-mobile-date-badge,
                .cmp-mobile-pos-holder,
                .cmp-mobile-meal-preview {
                    display: none !important;
                }
                .cmp-day-header-top {
                    display: contents !important;
                }

                .cmp-table tr.cmp-day-row {
                    display: grid !important;
                    grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
                    gap: 12px 14px !important;
                    align-items: end !important;
                }
                .cmp-table td:not(.cmp-collapsed-summary) {
                    display: block !important;
                    padding: 0 !important;
                    border: none !important;
                    width: 100% !important;
                }

                .cmp-table td.cmp-cell-day {
                    grid-column: 1 / span 2 !important;
                    grid-row: 1 !important;
                    align-self: stretch !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: space-between !important;
                    background: #EEF7E3 !important;
                    color: #1B5E20 !important;
                    border: 1.5px solid #DCECC9 !important;
                    border-radius: 12px !important;
                    font-size: 1.08rem !important;
                    font-weight: 800 !important;
                    padding: 10px 14px !important;
                    cursor: pointer !important;
                    user-select: none;
                    transition: filter 0.15s ease;
                }
                .cmp-table td.cmp-cell-day:hover { filter: brightness(0.97); }
                .cmp-table td.cmp-cell-day::after {
                    content: '\25B2';
                    font-size: 0.72rem;
                    opacity: 0.7;
                }
                .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-day::after {
                    content: '\25BC';
                }

                .cmp-table tr.status-pending td.cmp-cell-day { background: #fffbeb !important; color: #b45309 !important; border-color: #fde68a !important; }
                .cmp-table tr.status-void td.cmp-cell-day { background: #fff1f2 !important; color: #9f1239 !important; border-color: #fecdd3 !important; }

                .cmp-table td.cmp-cell-date { grid-column: 3 / span 3 !important; grid-row: 1 !important; }
                .cmp-table td.cmp-cell-chef { grid-column: 6 / span 2 !important; grid-row: 1 !important; }
                .cmp-table td.cmp-cell-status { grid-column: 8 / span 2 !important; grid-row: 1 !important; }
                .cmp-table td.cmp-cell-action { grid-column: 10 / span 3 !important; grid-row: 1 !important; }

                .cmp-table td.cmp-cell-meal,
                .cmp-table td.cmp-cell-snack,
                .cmp-table td.cmp-cell-macros,
                .cmp-table td.cmp-cell-juice {
                    padding-top: 10px !important;
                    border-top: 1px dashed #e2e8f0 !important;
                }

                .cmp-table td.cmp-cell-meal-1 { grid-column: 1 / span 4 !important; grid-row: 2 !important; }
                .cmp-table td.cmp-cell-meal-2 { grid-column: 5 / span 4 !important; grid-row: 2 !important; }
                .cmp-table td.cmp-cell-meal-3 { grid-column: 9 / span 4 !important; grid-row: 2 !important; }
                .cmp-table td.cmp-cell-snack  { grid-column: 1 / span 12 !important; grid-row: 3 !important; }
                .cmp-table td.cmp-cell-macros { grid-column: 1 / span 12 !important; grid-row: 4 !important; }

                .cmp-table td.cmp-cell-juice-1 { grid-column: 1 / span 4 !important; grid-row: 2 !important; }
                .cmp-table td.cmp-cell-juice-2 { grid-column: 5 / span 4 !important; grid-row: 2 !important; }
                .cmp-table td.cmp-cell-juice-3 { grid-column: 9 / span 4 !important; grid-row: 2 !important; }

                .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-meal,
                .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-snack,
                .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-macros,
                .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-cell-juice {
                    display: none !important;
                }
                .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-collapsed-summary {
                    display: flex !important;
                    grid-row: 2 !important;
                }
            }

            /* Tablet Adjustments (769px - 1024px) */
            @media (min-width: 769px) and (max-width: 1024px) {
                .cmp-dashboard-wrap { padding-bottom: 95px !important; }
                .cmp-table td.cmp-cell-day    { grid-column: 1 / span 3 !important; grid-row: 1 !important; }
                .cmp-table td.cmp-cell-date   { grid-column: 4 / span 5 !important; grid-row: 1 !important; }
                .cmp-table td.cmp-cell-chef   { grid-column: 9 / span 4 !important; grid-row: 1 !important; }
                .cmp-table td.cmp-cell-status { grid-column: 1 / span 5 !important; grid-row: 2 !important; }
                .cmp-table td.cmp-cell-action { grid-column: 6 / span 7 !important; grid-row: 2 !important; }

                .cmp-table td.cmp-cell-meal-1 { grid-column: 1 / span 4 !important; grid-row: 3 !important; }
                .cmp-table td.cmp-cell-meal-2 { grid-column: 5 / span 4 !important; grid-row: 3 !important; }
                .cmp-table td.cmp-cell-meal-3 { grid-column: 9 / span 4 !important; grid-row: 3 !important; }
                .cmp-table td.cmp-cell-snack  { grid-column: 1 / span 12 !important; grid-row: 4 !important; }
                .cmp-table td.cmp-cell-macros { grid-column: 1 / span 12 !important; grid-row: 5 !important; }

                .cmp-table td.cmp-cell-juice-1 { grid-column: 1 / span 4 !important; grid-row: 3 !important; }
                .cmp-table td.cmp-cell-juice-2 { grid-column: 5 / span 4 !important; grid-row: 3 !important; }
                .cmp-table td.cmp-cell-juice-3 { grid-column: 9 / span 4 !important; grid-row: 3 !important; }

                .cmp-table tr.cmp-day-row.is-collapsed-desktop td.cmp-collapsed-summary {
                    grid-row: 3 !important;
                }
            }

            /* =========================================================
               5. MOBILE SINGLE-VIEWPORT ACCORDION & FLUSH FULL-BLEED
                  TWO-LINE MICRO-CARD (OPTIONS 1A & 2A — <= 768px)
               ========================================================= */
            @media (max-width: 768px) {
                .cmp-dashboard-wrap { padding: 0 6px 104px 6px !important; }

                /* Option 1A: Hide Collapse All, Expand All & Keyboard Guide on Mobile; Crisp Full-Width Pending Button */
                .cmp-kbd-guide,
                .cmp-collapse-all-btn,
                .cmp-expand-all-btn {
                    display: none !important;
                }
                .cmp-schedule-toolbar {
                    background: transparent !important;
                    border: none !important;
                    padding: 0 !important;
                    margin-bottom: 12px !important;
                }
                .cmp-toolbar-actions {
                    width: 100% !important;
                }
                .cmp-jump-pending-btn {
                    width: 100% !important;
                    max-width: 100% !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: space-between !important;
                    flex-wrap: nowrap !important;
                    padding: 10px 14px !important;
                    font-size: 0.82rem !important;
                    letter-spacing: -0.01em !important;
                    border-radius: 12px !important;
                    gap: 8px !important;
                    overflow: hidden !important;
                    box-sizing: border-box !important;
                }
                .cmp-jump-btn-left {
                    flex: 1 1 auto !important;
                    min-width: 0 !important;
                    max-width: calc(100% - 78px) !important;
                    gap: 6px !important;
                    overflow: hidden !important;
                }
                .cmp-jump-btn-left > span,
                .cmp-jump-pending-btn > span:not(.cmp-pending-count-badge) {
                    font-size: 0.82rem !important;
                    letter-spacing: -0.01em !important;
                    overflow: hidden !important;
                    text-overflow: ellipsis !important;
                    white-space: nowrap !important;
                }
                .cmp-jump-pending-btn svg.cmp-fixed-svg {
                    width: 16px !important;
                    height: 16px !important;
                    min-width: 16px !important;
                    min-height: 16px !important;
                    margin-left: 0 !important;
                    flex-shrink: 0 !important;
                }
                .cmp-pending-count-badge {
                    position: static !important;
                    flex: 0 0 auto !important;
                    padding: 4px 10px !important;
                    font-size: 0.72rem !important;
                    margin-left: 8px !important;
                    background: rgba(15, 41, 18, 0.42) !important;
                }

                .cmp-table td.cmp-collapsed-summary { display: none !important; }
                .cmp-table td.cmp-cell-status { display: none !important; }

                .cmp-unsaved-float-bar,
                .cmp-unsaved-float-bar.is-active {
                    display: none !important;
                }

                /* Option 2A: Flush Full-Bleed Collapsed Mobile Card */
                .cmp-table tr.cmp-day-row:not(.is-open) {
                    display: block !important;
                    width: 100% !important;
                    margin-bottom: 10px !important;
                    padding: 0 !important;
                    border-radius: 13px !important;
                    overflow: hidden !important;
                    border-width: 1.5px !important;
                }
                .cmp-table tr.cmp-day-row:not(.is-open) td:not(.cmp-cell-day) {
                    display: none !important;
                }
                .cmp-table tr.cmp-day-row:not(.is-open) td.cmp-cell-day {
                    display: flex !important;
                    flex-direction: column !important;
                    justify-content: center !important;
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 10px 34px 10px 13px !important;
                    border: none !important;
                    border-radius: 0 !important;
                    box-sizing: border-box !important;
                    cursor: pointer !important;
                    position: relative !important;
                }

                /* Expanded Mobile Card: 12-Column Compact Grid */
                .cmp-table tr.cmp-day-row.is-open {
                    display: grid !important;
                    grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
                    gap: 4px 8px !important;
                    align-items: end !important;
                    padding: 9px 10px 10px 10px !important;
                    margin-bottom: 12px !important;
                    border-radius: 13px !important;
                }
                .cmp-table tr.cmp-day-row.is-open td:not(.cmp-collapsed-summary):not(.cmp-cell-status) {
                    display: block !important;
                    width: 100% !important;
                    border: none !important;
                    padding: 0 !important;
                    text-align: left !important;
                }
                .cmp-table tr.cmp-day-row.is-open td.cmp-cell-day {
                    grid-column: 1 / span 12 !important;
                    display: flex !important;
                    flex-direction: column !important;
                    width: 100% !important;
                    border-radius: 9px !important;
                    padding: 8px 34px 8px 12px !important;
                    margin-bottom: 2px !important;
                    position: relative !important;
                    cursor: pointer !important;
                }

                .cmp-day-header-top {
                    display: flex !important;
                    align-items: center !important;
                    justify-content: space-between !important;
                    width: 100% !important;
                    gap: 8px !important;
                }
                .cmp-day-title-group {
                    display: inline-flex !important;
                    align-items: center !important;
                    gap: 6px !important;
                    min-width: 0 !important;
                    flex: 1 1 auto !important;
                }
                .cmp-day-num {
                    font-size: 0.95rem !important;
                    font-weight: 800 !important;
                    white-space: nowrap !important;
                }
                .cmp-mobile-date-badge {
                    display: none;
                    font-size: 0.78rem !important;
                    font-weight: 700 !important;
                    opacity: 0.92 !important;
                    white-space: nowrap !important;
                    overflow: hidden !important;
                    text-overflow: ellipsis !important;
                }
                .cmp-mobile-date-badge.has-date {
                    display: inline-block !important;
                }
                .cmp-day-right-group {
                    display: inline-flex !important;
                    align-items: center !important;
                    gap: 5px !important;
                    flex-shrink: 0 !important;
                }

                .cmp-mobile-status {
                    display: inline-flex !important;
                    margin: 0 !important;
                }
                .cmp-mobile-status .cmp-status-pill {
                    min-width: 84px !important;
                    padding: 3px 9px !important;
                    font-size: 0.71rem !important;
                    font-weight: 800 !important;
                    justify-content: center !important;
                    text-align: center !important;
                    border-radius: 999px !important;
                }

                .cmp-mobile-pos-holder {
                    display: inline-flex !important;
                    align-items: center !important;
                }
                .cmp-pos-micro-pill {
                    display: inline-flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    padding: 2px 6px !important;
                    border-radius: 6px !important;
                    font-size: 0.63rem !important;
                    font-weight: 800 !important;
                    white-space: nowrap !important;
                }
                .cmp-pos-micro-pill.pos-is-checked {
                    background: #ccfbf1 !important;
                    color: #0f766e !important;
                    border: 1px solid #5eead4 !important;
                }
                .cmp-pos-micro-pill.pos-is-pending {
                    background: rgba(255, 255, 255, 0.75) !important;
                    color: #475569 !important;
                    border: 1px solid #cbd5e1 !important;
                }

                .cmp-mobile-meal-preview {
                    display: none !important;
                    width: 100% !important;
                    margin-top: 4px !important;
                    padding-top: 4px !important;
                    border-top: 1px dashed rgba(15, 23, 42, 0.12) !important;
                    font-size: 0.75rem !important;
                    font-weight: 600 !important;
                    line-height: 1.25 !important;
                    white-space: nowrap !important;
                    overflow: hidden !important;
                    text-overflow: ellipsis !important;
                    opacity: 0.92 !important;
                }
                .cmp-table tr.cmp-day-row:not(.is-open) .cmp-mobile-meal-preview.has-preview {
                    display: block !important;
                }

                .status-pending td.cmp-cell-day { background: #FFFBEB !important; color: #b45309 !important; }
                .status-saved td.cmp-cell-day { background: #F0FDF4 !important; color: #166534 !important; }
                .status-void td.cmp-cell-day { background: #FFF1F2 !important; color: #9f1239 !important; }

                .status-pending .cmp-mobile-status .cmp-status-pill.pill-pending {
                    background: #fef3c7 !important;
                    color: #92400e !important;
                    border: 1px solid #f59e0b !important;
                }
                .status-saved .cmp-mobile-status .cmp-status-pill.pill-confirmed,
                .status-saved .cmp-mobile-status .cmp-status-pill.pill-successful {
                    background: #dcfce7 !important;
                    color: #166534 !important;
                    border: 1px solid #4ade80 !important;
                }
                .status-void .cmp-mobile-status .cmp-status-pill.pill-void {
                    background: #ffe4e6 !important;
                    color: #9f1239 !important;
                    border: 1px solid #fb7185 !important;
                }

                .cmp-table td.cmp-cell-day::after {
                    content: '\25BC';
                    position: absolute;
                    right: 11px;
                    top: 50%;
                    transform: translateY(-50%);
                    font-size: 11px;
                    color: inherit;
                    opacity: 0.8;
                }
                .cmp-table tr.is-open td.cmp-cell-day::after { content: '\25B2'; }

                .cmp-table tr.cmp-day-row.is-open.is-chefs-choice-compact td.cmp-cell-meal,
                .cmp-table tr.cmp-day-row.is-open.is-chefs-choice-compact td.cmp-cell-snack,
                .cmp-table tr.cmp-day-row.is-open.is-chefs-choice-compact td.cmp-cell-juice {
                    display: none !important;
                }

                .cmp-table tr.cmp-day-row.is-open td.cmp-cell-date { grid-column: 1 / span 7 !important; }
                .cmp-table tr.cmp-day-row.is-open td.cmp-cell-chef { grid-column: 8 / span 5 !important; }
                .cmp-table tr.cmp-day-row.is-open td.cmp-cell-meal,
                .cmp-table tr.cmp-day-row.is-open td.cmp-cell-juice { grid-column: 1 / span 12 !important; }
                .cmp-table tr.cmp-day-row.is-open td.cmp-cell-snack { grid-column: 1 / span 12 !important; }

                .cmp-snacks-grid,
                .cmp-snacks-grid.single-snack {
                    grid-template-columns: minmax(0, 1fr) !important;
                    gap: 4px !important;
                }

                .cmp-mobile-label {
                    font-size: 0.64rem !important;
                    margin-bottom: 2px !important;
                    line-height: 1.05 !important;
                }
                .cmp-table select {
                    min-height: 35px !important;
                    height: auto !important;
                    padding: 6px 30px 6px 10px !important;
                    font-size: 13px !important;
                    line-height: 1.25 !important;
                    border-radius: 8px !important;
                    border-width: 1.5px !important;
                    box-shadow: 0 1px 0 #cbd5e1 !important;
                    background-position: right 9px center !important;
                    background-size: 14px !important;
                }
                .cmp-table input[type="text"],
                .cmp-table input[type="date"] {
                    min-height: 35px !important;
                    height: 35px !important;
                    padding: 4px 8px 4px 10px !important;
                    font-size: 13px !important;
                    line-height: 1.2 !important;
                    border-radius: 8px !important;
                    border-width: 1.5px !important;
                    box-shadow: 0 1px 0 #cbd5e1 !important;
                }
                .cmp-chef-box {
                    min-height: 35px !important;
                    height: 35px !important;
                    padding: 4px 8px !important;
                    gap: 6px !important;
                    border-radius: 8px !important;
                    border-width: 1.5px !important;
                }
                .cmp-chef-box span {
                    font-size: 0.75rem !important;
                    white-space: nowrap !important;
                }
                .cmp-chefs-choice {
                    width: 16px !important;
                    height: 16px !important;
                }

                .cmp-table tr.cmp-day-row.is-open td.cmp-cell-macros {
                    grid-column: 1 / span 12 !important;
                    margin-top: 1px !important;
                }
                .cmp-table td.cmp-cell-macros > .cmp-mobile-label { display: none !important; }
                .cmp-macro-display { padding: 4px 5px !important; border-radius: 8px !important; }
                .cmp-macro-chef-tag { font-size: 0.62rem !important; padding: 1px 6px !important; margin-bottom: 3px !important; }
                .cmp-macro-tiles-grid { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; gap: 4px !important; }
                .cmp-macro-tile { padding: 3px 2px !important; border-radius: 6px !important; }
                .cmp-macro-tile .tile-label { font-size: 0.54rem !important; margin-bottom: 1px !important; letter-spacing: 0.02em !important; }
                .cmp-macro-tile .tile-val { font-size: 0.74rem !important; }
                .cmp-macro-banner-state { padding: 4px 8px !important; font-size: 0.74rem !important; }

                .cmp-table tr.cmp-day-row.is-open td.cmp-cell-action {
                    grid-column: 1 / span 12 !important;
                    margin-top: 2px !important;
                }
                .cmp-table td.cmp-cell-action > .cmp-mobile-label { display: none !important; }
                .cmp-action-box {
                    flex-direction: row !important;
                    align-items: center !important;
                    justify-content: space-between !important;
                    gap: 6px !important;
                }
                .cmp-save-row {
                    flex: 1 1 auto !important;
                    width: auto !important;
                    min-width: 0 !important;
                    min-height: 38px !important;
                    height: 38px !important;
                    padding: 6px 12px !important;
                    font-size: 13.5px !important;
                    border-radius: 8px !important;
                }
                .cmp-secondary-actions {
                    width: auto !important;
                    display: inline-flex !important;
                    gap: 6px !important;
                    flex-shrink: 0 !important;
                }
                .cmp-clear-row, .cmp-cancel-day-row {
                    flex: 0 0 38px !important;
                    width: 38px !important;
                    height: 38px !important;
                    border-radius: 8px !important;
                }
                .cmp-undo-row {
                    width: auto !important;
                    height: 38px !important;
                    padding: 0 11px !important;
                    font-size: 12.5px !important;
                    border-radius: 8px !important;
                }

                /* Prevent iOS Safari Auto-Zoom on Tap */
                @supports (-webkit-touch-callout: none) {
                    .cmp-table select,
                    .cmp-table input[type="text"],
                    .cmp-table input[type="date"] {
                        font-size: 16px !important;
                    }
                }
            }

            /* =========================================================
               6. RENEW / SUBSCRIBE CALLOUT & COLLAPSIBLE FEEDBACK SURVEY
               ========================================================= */
            .cmp-renew-banner-card {
                margin-top: 22px;
                background: linear-gradient(135deg, #EEF7E3 0%, #DCECC9 100%);
                border: 1.5px solid #68B946;
                border-radius: 16px;
                padding: 18px 22px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 14px;
                box-shadow: 0 4px 14px rgba(27, 94, 32, 0.06);
            }
            .cmp-renew-banner-text h4 {
                margin: 0 0 4px 0 !important;
                color: #1B5E20 !important;
                font-size: 1.12rem !important;
                font-weight: 800 !important;
            }
            .cmp-renew-banner-text p {
                margin: 0 !important;
                color: #334155 !important;
                font-size: 0.88rem !important;
                font-weight: 600 !important;
            }
            .cmp-renew-cta-btn {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 8px !important;
                background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important;
                color: #ffffff !important;
                text-decoration: none !important;
                padding: 11px 22px !important;
                border-radius: 10px !important;
                font-weight: 800 !important;
                font-size: 0.9rem !important;
                box-shadow: 0 4px 12px rgba(27, 94, 32, 0.22) !important;
                transition: all 0.2s ease !important;
                white-space: nowrap !important;
            }
            .cmp-renew-cta-btn:hover {
                filter: brightness(1.07) !important;
                transform: translateY(-1px) !important;
                color: #ffffff !important;
            }

            /* Collapsible Feedback Survey Card (Collapsed by Default) */
            .cmp-feedback-card {
                margin-top: 16px;
                background: #ffffff;
                border: 1.5px solid #DCECC9;
                border-top: 4px solid #379237;
                border-radius: 16px;
                box-shadow: 0 6px 20px rgba(15, 23, 42, 0.04);
                overflow: hidden;
            }
            .cmp-feedback-toggle-header {
                width: 100%;
                background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
                border: none;
                padding: 16px 22px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                cursor: pointer;
                text-align: left;
                font-family: inherit;
                transition: background 0.18s ease;
            }
            .cmp-feedback-toggle-header:hover {
                background: #EEF7E3;
            }
            .cmp-feedback-header-left h3 {
                margin: 0 0 3px 0 !important;
                color: #1B5E20 !important;
                font-size: 1.15rem !important;
                font-weight: 800 !important;
                line-height: 1.25 !important;
            }
            .cmp-feedback-header-left p {
                margin: 0 !important;
                color: #475569 !important;
                font-size: 0.85rem !important;
                font-weight: 600 !important;
                line-height: 1.35 !important;
            }
            .cmp-feedback-toggle-pill {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                background: #EEF7E3;
                color: #1B5E20;
                border: 1.5px solid #68B946;
                border-radius: 999px;
                padding: 6px 14px;
                font-size: 0.78rem;
                font-weight: 800;
                white-space: nowrap;
                flex-shrink: 0;
            }
            .cmp-feedback-card:not(.is-feedback-open) .cmp-feedback-form-body {
                display: none !important;
            }
            .cmp-feedback-card.is-feedback-open .cmp-feedback-toggle-header {
                border-bottom: 1px solid #DCECC9;
                background: #f8fafc;
            }
            .cmp-feedback-form-body {
                padding: 18px 22px 22px 22px;
            }

            .cmp-feedback-ratings-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
                margin-bottom: 14px;
            }
            .cmp-rating-box {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 12px 14px;
            }
            .cmp-rating-box-label {
                display: block;
                font-size: 0.84rem;
                font-weight: 800;
                color: #1e293b;
                margin-bottom: 8px;
            }
            .cmp-star-group {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 6px;
            }
            .cmp-star-btn {
                background: #ffffff;
                border: 1.5px solid #cbd5e1;
                border-radius: 8px;
                width: 38px;
                height: 36px;
                font-size: 1.1rem;
                line-height: 1;
                color: #94a3b8;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                transition: all 0.15s ease;
                padding: 0;
                flex-shrink: 0;
            }
            .cmp-star-btn.is-active {
                background: #fffbeb;
                border-color: #f59e0b;
                color: #f59e0b;
                transform: translateY(-1px);
                box-shadow: 0 2px 6px rgba(245, 158, 11, 0.2);
            }
            .cmp-rating-score-txt {
                margin-left: 4px;
                font-size: 0.78rem;
                font-weight: 700;
                color: #64748b;
                white-space: nowrap;
            }

            .cmp-feedback-tags-wrap { margin-bottom: 14px; }
            .cmp-feedback-tags-label {
                display: block;
                font-size: 0.8rem;
                font-weight: 700;
                color: #475569;
                margin-bottom: 7px;
            }
            .cmp-feedback-tags-list {
                display: flex;
                flex-wrap: wrap;
                gap: 7px;
            }
            .cmp-feedback-tag-pill {
                background: #f8fafc;
                color: #334155;
                border: 1px solid #cbd5e1;
                border-radius: 999px;
                padding: 5px 12px;
                font-size: 0.78rem;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .cmp-feedback-tag-pill.is-selected {
                background: #EEF7E3;
                color: #1B5E20;
                border-color: #379237;
                box-shadow: 0 2px 6px rgba(55, 146, 55, 0.15);
            }

            .cmp-feedback-textarea {
                width: 100%;
                border: 1.5px solid #cbd5e1;
                border-radius: 10px;
                padding: 10px 12px;
                font-size: 0.88rem;
                color: #1e293b;
                background: #f8fafc;
                resize: vertical;
                min-height: 68px;
                font-family: inherit;
                margin-bottom: 12px;
            }
            .cmp-feedback-textarea:focus {
                background: #ffffff;
                border-color: #379237;
                outline: none;
                box-shadow: 0 0 0 3px rgba(104, 185, 70, 0.2);
            }

            /* Smart Identity Toggle & Single-Line Status Bubble */
            .cmp-feedback-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 12px;
                padding-top: 10px;
                border-top: 1px dashed #e2e8f0;
            }
            .cmp-identity-toggle-wrap {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px 10px;
                flex: 1 1 320px;
                min-width: 0;
            }
            .cmp-identity-toggle-label {
                display: inline-flex;
                align-items: flex-start;
                gap: 8px;
                font-size: 0.83rem;
                font-weight: 600;
                color: #334155;
                cursor: pointer;
                line-height: 1.35;
                flex: 1 1 200px;
                min-width: 0;
            }
            .cmp-identity-toggle-label input[type="checkbox"] {
                width: 18px !important;
                height: 18px !important;
                min-width: 18px !important;
                accent-color: #379237 !important;
                cursor: pointer !important;
                margin: 1px 0 0 0 !important;
                flex-shrink: 0 !important;
            }
            .cmp-identity-status-pill {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 4px 12px !important;
                border-radius: 999px !important;
                font-size: 0.73rem !important;
                font-weight: 800 !important;
                line-height: 1.15 !important;
                white-space: nowrap !important;
                flex-shrink: 0 !important;
                background: #EEF7E3 !important;
                color: #1B5E20 !important;
                border: 1px solid #68B946 !important;
            }
            .cmp-identity-status-pill.is-anon {
                background: #f1f5f9 !important;
                color: #475569 !important;
                border-color: #cbd5e1 !important;
            }
            .cmp-submit-feedback-btn {
                background: linear-gradient(135deg, #379237 0%, #1B5E20 100%);
                color: #ffffff;
                border: none;
                border-radius: 10px;
                padding: 10px 22px;
                font-size: 0.88rem;
                font-weight: 800;
                cursor: pointer;
                transition: all 0.2s ease;
                white-space: nowrap;
            }
            .cmp-submit-feedback-btn:hover:not(:disabled) {
                filter: brightness(1.06);
                transform: translateY(-1px);
            }
            .cmp-submit-feedback-btn:disabled {
                opacity: 0.65;
                cursor: not-allowed;
            }

            @media (max-width: 768px) {
                .cmp-renew-banner-card {
                    padding: 14px 16px;
                    text-align: left;
                }
                .cmp-renew-cta-btn { width: 100% !important; }
                .cmp-feedback-toggle-header { padding: 13px 14px; }
                .cmp-feedback-header-left h3 { font-size: 1rem !important; }
                .cmp-feedback-header-left p { font-size: 0.78rem !important; }
                .cmp-feedback-toggle-pill { padding: 5px 11px; font-size: 0.73rem; }
                .cmp-feedback-form-body { padding: 14px; }
                .cmp-feedback-ratings-grid { grid-template-columns: minmax(0, 1fr); gap: 10px; }
                .cmp-identity-toggle-wrap {
                    width: 100%;
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 6px;
                }
                .cmp-identity-toggle-label { flex: 1 1 auto; width: 100%; }
                .cmp-identity-status-pill { margin-left: 26px; }
                .cmp-submit-feedback-btn { width: 100%; padding: 11px 16px; }
            }

            /* =========================================================
               7. FLOATING WHATSAPP & BACK-TO-TOP BUTTONS (DESKTOP, TABLET, MOBILE)
               ========================================================= */
            .cmp-wa-float {
                position: fixed !important;
                bottom: 24px !important;
                right: 24px !important;
                background-color: #25d366 !important;
                color: #ffffff !important;
                border-radius: 999px !important;
                padding: 11px 18px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 9px !important;
                box-shadow: 0 6px 18px rgba(37, 211, 102, 0.38) !important;
                text-decoration: none !important;
                font-weight: 800 !important;
                font-size: 15px !important;
                line-height: 1 !important;
                z-index: 9999 !important;
                transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease !important;
            }
            .cmp-wa-float:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 8px 22px rgba(37, 211, 102, 0.5) !important;
                color: #ffffff !important;
            }
            /* Strict SVG Lock for WhatsApp Logo so Theme CSS Never Shrinks It to a Dot */
            .cmp-wa-float svg.cmp-wa-svg {
                width: 24px !important;
                height: 24px !important;
                min-width: 24px !important;
                min-height: 24px !important;
                max-width: 24px !important;
                max-height: 24px !important;
                fill: #ffffff !important;
                display: block !important;
                flex-shrink: 0 !important;
            }

            .cmp-back-to-top {
                position: fixed !important;
                bottom: 82px !important;
                right: 24px !important;
                width: 42px !important;
                height: 42px !important;
                border-radius: 50% !important;
                background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important;
                color: #ffffff !important;
                border: 1.5px solid #DCECC9 !important;
                box-shadow: 0 4px 12px rgba(27, 94, 32, 0.30) !important;
                display: none;
                align-items: center !important;
                justify-content: center !important;
                cursor: pointer !important;
                z-index: 9999 !important;
                padding: 0 !important;
                transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease !important;
            }
            .cmp-back-to-top.is-visible {
                display: inline-flex !important;
            }
            .cmp-back-to-top:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 6px 16px rgba(27, 94, 32, 0.42) !important;
            }

            /* Tablet Floating Buttons (601px - 1024px) */
            @media (min-width: 601px) and (max-width: 1024px) {
                .cmp-wa-float {
                    bottom: 18px !important;
                    right: 18px !important;
                    padding: 10px 15px !important;
                    font-size: 14px !important;
                }
                .cmp-wa-float svg.cmp-wa-svg {
                    width: 22px !important;
                    height: 22px !important;
                    min-width: 22px !important;
                    min-height: 22px !important;
                }
                .cmp-back-to-top {
                    bottom: 72px !important;
                    right: 18px !important;
                    width: 40px !important;
                    height: 40px !important;
                }
            }

            /* Mobile Floating Buttons (<= 600px) — Compact Circular FABs Docked Cleanly */
            @media (max-width: 600px) {
                .cmp-wa-float {
                    bottom: 14px !important;
                    right: 12px !important;
                    width: 46px !important;
                    height: 46px !important;
                    padding: 0 !important;
                    border-radius: 50% !important;
                    justify-content: center !important;
                }
                .cmp-wa-float svg.cmp-wa-svg {
                    width: 24px !important;
                    height: 24px !important;
                    min-width: 24px !important;
                    min-height: 24px !important;
                }
                .cmp-wa-text {
                    display: none !important;
                }
                .cmp-back-to-top {
                    bottom: 68px !important;
                    right: 15px !important;
                    width: 40px !important;
                    height: 40px !important;
                }
                body.cmp-mobile-input-active .cmp-wa-float,
                body.cmp-mobile-input-active .cmp-back-to-top {
                    opacity: 0.18 !important;
                    pointer-events: none !important;
                }
            }
        </style>
        <?php
    }
}

/**
 * Renders the JavaScript / jQuery interactive engine for the Customer Portal.
 *
 * @param array $config Associative array of portal configuration & role flags.
 */
if ( ! function_exists( 'cmp_render_customer_portal_scripts' ) ) {
    function cmp_render_customer_portal_scripts( $config = array() ) {
        $ajax_url             = $config['ajax_url'] ?? admin_url( 'admin-ajax.php' );
        $nonce                = $config['nonce'] ?? wp_create_nonce( 'cmp_portal_nonce' );
        $can_bypass_date_lock = ! empty( $config['can_bypass_date_lock'] );
        $can_bypass_quota     = ! empty( $config['can_bypass_quota'] );
        $is_chef_override     = ! empty( $config['is_chef_override'] );
        $can_view_pos_check   = ! empty( $config['can_view_pos_check'] );
        $label_chefs_choice   = $config['label_chefs_choice'] ?? "Chef's Choice";
        $blackout_dates       = isset( $config['blackout_dates'] ) && is_array( $config['blackout_dates'] ) ? $config['blackout_dates'] : array();
        $customer_min_date    = $config['customer_min_date'] ?? '';
        $today_ymd            = $config['today_ymd'] ?? '';
        ?>
        <script type="text/javascript">
        function switchTab(evt, tabId) {
            var contents = document.getElementsByClassName("cmp-tab-content");
            for (var i = 0; i < contents.length; i++) contents[i].classList.remove("active");
            var btns = document.getElementsByClassName("cmp-tab-btn");
            for (var i = 0; i < btns.length; i++) btns[i].classList.remove("active");
            document.getElementById(tabId).classList.add("active");
            evt.currentTarget.classList.add("active");

            var subId = tabId.replace('plan-tab-', '');
            var exportBtn = document.getElementById('cmp-global-export-btn');
            if (exportBtn) {
                exportBtn.href = "<?php echo esc_url( $ajax_url . '?action=cmp_export_customer_csv&sub_id=' ); ?>" + subId;
            }
        }

        jQuery(document).ready(function($) {
            var ajaxUrl           = "<?php echo esc_url( $ajax_url ); ?>";
            var portalNonce       = "<?php echo esc_js( $nonce ); ?>";
            var canBypassDateLock = <?php echo $can_bypass_date_lock ? 'true' : 'false'; ?>;
            var canBypassQuota    = <?php echo $can_bypass_quota ? 'true' : 'false'; ?>;
            var isChefOverride    = <?php echo $is_chef_override ? 'true' : 'false'; ?>;
            var canViewPosCheck   = <?php echo $can_view_pos_check ? 'true' : 'false'; ?>;
            var chefsChoiceLabel  = "<?php echo esc_js( $label_chefs_choice ); ?>";
            var blackoutDates     = <?php echo wp_json_encode( $blackout_dates ); ?>;
            var customerMinDate   = "<?php echo esc_js( $customer_min_date ); ?>";
            var todayYmd          = "<?php echo esc_js( $today_ymd ); ?>";

            var isIOSOrTouch = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
                               (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) ||
                               ('ontouchstart' in window && $(window).width() <= 1024);

            var shortDays   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            var shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            function formatShortMobileDate(dateStr) {
                if (!dateStr || dateStr === '0000-00-00') return '';
                var d = new Date(dateStr + 'T00:00:00');
                if (isNaN(d.getTime())) return '';
                var dayName = shortDays[d.getDay()];
                var dayNum  = String(d.getDate()).padStart(2, '0');
                var monName = shortMonths[d.getMonth()];
                return '• ' + dayName + ', ' + dayNum + ' ' + monName;
            }

            function updatePendingCounts($container) {
                var $targets = ($container && $container.length) ? $container : $('.cmp-portal-container');
                $targets.each(function() {
                    var $c = $(this);
                    var pendingCount = $c.find('tr.cmp-day-row.status-pending').length;
                    var $badge = $c.find('.cmp-pending-count-badge');
                    if ($badge.length) {
                        if (pendingCount > 0) {
                            $badge.text(pendingCount + ' Left');
                        } else {
                            $badge.text('All Selected');
                        }
                    }
                });
            }

            // OPTION 5A: Mobile Account & Active Logistics Collapsible Drawer Toggle
            $(document).on('click', '.cmp-mobile-info-toggle', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $drawer = $btn.closest('.cmp-info-drawer-wrap');
                var willOpen = !$drawer.hasClass('is-drawer-open');
                $drawer.toggleClass('is-drawer-open', willOpen);
                $btn.attr('aria-expanded', willOpen ? 'true' : 'false');
                $btn.find('.drawer-pill-txt').text(willOpen ? 'Hide' : 'Details');
                $btn.find('.drawer-pill-arrow').html(willOpen ? '&#9650;' : '&#9660;');
            });

            // Collapsible Feedback Section Toggle (Collapsed by Default)
            $(document).on('click', '.cmp-feedback-toggle-header', function(e) {
                e.preventDefault();
                var $btn  = $(this);
                var $card = $btn.closest('.cmp-feedback-card');
                var willOpen = !$card.hasClass('is-feedback-open');
                $card.toggleClass('is-feedback-open', willOpen);
                $btn.attr('aria-expanded', willOpen ? 'true' : 'false');
                $btn.find('.fb-pill-txt').text(willOpen ? 'Hide' : 'Rate Now');
                $btn.find('.fb-pill-arrow').html(willOpen ? '&#9650;' : '&#9660;');
            });

            // =========================================================
            // UNSAVED STATE ("DIRTY STATE") & BEFOREUNLOAD EXIT GUARD
            // =========================================================
            var hasUnsavedChanges   = false;
            var isIntentionalReload = false;

            function captureRowSnapshot(rowElement) {
                if (!rowElement || !rowElement.length) return;
                var mealsMap = {};
                rowElement.find('[data-slot]').each(function() {
                    var slot = $(this).data('slot');
                    mealsMap[slot] = ($(this).val() || '').toString().trim();
                });

                var saveBtn = rowElement.find('.cmp-save-row');
                var logId   = saveBtn.length ? (parseInt(saveBtn.data('log-id')) || 0) : 0;
                var currentBadgeHtml = rowElement.find('.cmp-desktop-status-holder').html();

                rowElement.data('cmp-snapshot', {
                    date: (rowElement.find('.cmp-date-picker').val() || '').toString().trim(),
                    chefsChoice: rowElement.find('.cmp-chefs-choice').is(':checked'),
                    meals: mealsMap,
                    logId: logId,
                    btnText: logId > 0 ? 'Update' : 'Save',
                    btnBg: logId > 0 ? '#1B5E20' : '#379237',
                    statusBadgeHtml: currentBadgeHtml
                });
            }

            function isRowDifferentFromSnapshot(rowElement) {
                var snap = rowElement.data('cmp-snapshot');
                if (!snap) return true;

                var currDate  = (rowElement.find('.cmp-date-picker').val() || '').toString().trim();
                var currChef  = rowElement.find('.cmp-chefs-choice').is(':checked');

                if (currDate !== snap.date) return true;
                if (currChef !== snap.chefsChoice) return true;

                var isDiff = false;
                rowElement.find('[data-slot]').each(function() {
                    var slot = $(this).data('slot');
                    var currVal = ($(this).val() || '').toString().trim();
                    var snapVal = (snap.meals && snap.meals[slot] !== undefined) ? snap.meals[slot] : '';
                    if (currVal !== snapVal) {
                        isDiff = true;
                        return false;
                    }
                });
                return isDiff;
            }

            function updateGlobalUnsavedState() {
                var $unsavedRows = $('tr.cmp-day-row.is-unsaved');
                hasUnsavedChanges = ($unsavedRows.length > 0);

                var $floatBar = $('#cmp-unsaved-float-bar');
                if ($unsavedRows.length > 0) {
                    var dayLabels = [];
                    $unsavedRows.each(function() {
                        dayLabels.push('Day ' + $(this).data('row'));
                    });
                    var labelStr = dayLabels.length <= 3
                        ? dayLabels.join(', ')
                        : dayLabels.slice(0, 3).join(', ') + ' (+' + (dayLabels.length - 3) + ' more)';
                    $('#cmp-unsaved-float-text').text('⚠️ Unsaved changes on ' + labelStr);
                    $floatBar.addClass('is-active');
                } else {
                    $floatBar.removeClass('is-active');
                }
            }

            function restoreRowCleanVisuals(rowElement) {
                if (!rowElement || !rowElement.length) return;
                rowElement.removeClass('is-unsaved');

                var snap    = rowElement.data('cmp-snapshot');
                var saveBtn = rowElement.find('.cmp-save-row');
                if (saveBtn.length && !saveBtn.is(':disabled') && snap) {
                    saveBtn.text(snap.btnText).css('background', snap.btnBg);
                }
                if (snap && snap.statusBadgeHtml) {
                    rowElement.find('.cmp-desktop-status-holder').html(snap.statusBadgeHtml);
                    rowElement.find('.cmp-mobile-status').html(snap.statusBadgeHtml);
                }

                syncRowCompactAndSummary(rowElement);
                updateGlobalUnsavedState();
            }

            function markRowUnsaved(rowElement) {
                if (!rowElement || !rowElement.length) return;
                var saveBtn = rowElement.find('.cmp-save-row');
                if (!saveBtn.length || saveBtn.is(':disabled')) return;

                rowElement.addClass('is-unsaved');
                saveBtn.text('Save Required!');

                var unsavedBadge = '<span class="cmp-status-pill pill-unsaved">Unsaved!</span>';
                rowElement.find('.cmp-desktop-status-holder').html(unsavedBadge);
                rowElement.find('.cmp-mobile-status').html(unsavedBadge);

                syncRowCompactAndSummary(rowElement);
                updateGlobalUnsavedState();
            }

            function evaluateRowDirtyState(rowElement) {
                if (!rowElement || !rowElement.length) return;
                if (isRowDifferentFromSnapshot(rowElement)) {
                    markRowUnsaved(rowElement);
                } else {
                    restoreRowCleanVisuals(rowElement);
                }
            }

            function restoreRowFromSnapshot(rowElement) {
                if (!rowElement || !rowElement.length) return;
                var snap = rowElement.data('cmp-snapshot');
                if (!snap) return;

                rowElement.find('.cmp-date-picker').val(snap.date);
                rowElement.find('.cmp-chefs-choice').prop('checked', snap.chefsChoice);

                rowElement.find('[data-slot]').each(function() {
                    var slot = $(this).data('slot');
                    var origVal = (snap.meals && snap.meals[slot] !== undefined) ? snap.meals[slot] : '';
                    if ($(this).is('select')) {
                        $(this).data('saved-val', origVal);
                    } else {
                        $(this).val(origVal);
                    }
                });

                var hasFood = populateDynamicDropdowns(rowElement);
                if (hasFood) {
                    rowElement.find('[data-slot]').each(function() {
                        var slot = $(this).data('slot');
                        var origVal = (snap.meals && snap.meals[slot] !== undefined) ? snap.meals[slot] : '';
                        $(this).val(origVal);
                    });

                    var inputs = rowElement.find('select, input.cmp-meal-custom');
                    if (snap.chefsChoice && !isChefOverride) {
                        inputs.prop('disabled', true).removeClass('quota-locked');
                    } else {
                        inputs.prop('disabled', false);
                    }
                    calculateMacros(rowElement);
                    enforceMealQuota(rowElement);
                }

                if (!snap.date) {
                    rowElement.addClass('is-collapsed-desktop');
                }
                restoreRowCleanVisuals(rowElement);
            }

            function markRowSaved(rowElement) {
                if (!rowElement || !rowElement.length) return;
                rowElement.removeClass('is-unsaved');
                updateGlobalUnsavedState();
            }

            window.addEventListener('beforeunload', function(e) {
                if (hasUnsavedChanges && !isIntentionalReload) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved meal selections. Are you sure you want to leave without saving?';
                    return e.returnValue;
                }
            });

            // Back to Top Button Visibility & Action
            var $backToTopBtn = $('#cmp-back-to-top');
            $(window).on('scroll', function() {
                if ($(this).scrollTop() > 280) {
                    $backToTopBtn.addClass('is-visible');
                } else {
                    $backToTopBtn.removeClass('is-visible');
                }
            });
            $backToTopBtn.on('click', function(e) {
                e.preventDefault();
                $('html, body').stop(true).animate({ scrollTop: 0 }, 350);
            });

            function ensureNonBlackoutDate(dateStr) {
                if (!dateStr) return '';
                var d = new Date(dateStr + 'T00:00:00');
                if (isNaN(d.getTime())) return dateStr;
                var safety = 0;
                while (safety < 60) {
                    var yyyy = d.getFullYear();
                    var mm   = String(d.getMonth() + 1).padStart(2, '0');
                    var dd   = String(d.getDate()).padStart(2, '0');
                    var candidate = yyyy + '-' + mm + '-' + dd;
                    if (!blackoutDates.includes(candidate)) {
                        return candidate;
                    }
                    d.setDate(d.getDate() + 1);
                    safety++;
                }
                return dateStr;
            }

            function getNextValidDeliveryDate(baseDateStr) {
                var d = new Date(baseDateStr + 'T00:00:00');
                if (isNaN(d.getTime())) return '';
                var safety = 0;
                do {
                    d.setDate(d.getDate() + 1);
                    var yyyy = d.getFullYear();
                    var mm   = String(d.getMonth() + 1).padStart(2, '0');
                    var dd   = String(d.getDate()).padStart(2, '0');
                    var candidate = yyyy + '-' + mm + '-' + dd;
                    if (!canBypassDateLock && customerMinDate && candidate < customerMinDate) {
                        continue;
                    }
                    if (!blackoutDates.includes(candidate)) {
                        return candidate;
                    }
                    safety++;
                } while (safety < 60);
                return '';
            }

            function getRowEarliestValidDate(rowInput) {
                var container = rowInput.closest('.cmp-portal-container');
                var rowNumber = parseInt(rowInput.data('row')) || 1;
                var candidate = (!canBypassDateLock && customerMinDate) ? customerMinDate : todayYmd;

                var minAttr = rowInput.attr('min');
                if (minAttr && minAttr > candidate) {
                    candidate = minAttr;
                }

                if (rowNumber > 1 && container.length) {
                    for (var r = rowNumber - 1; r >= 1; r--) {
                        var $prevInput = container.find('.cmp-date-picker[data-row="' + r + '"]');
                        var $prevRow   = $prevInput.closest('tr.cmp-day-row');
                        if ($prevInput.length && !$prevRow.hasClass('status-void') && $prevInput.val()) {
                            var nextAfterPrev = getNextValidDeliveryDate($prevInput.val());
                            if (nextAfterPrev && nextAfterPrev > candidate) {
                                candidate = nextAfterPrev;
                            }
                            break;
                        }
                    }
                }

                return ensureNonBlackoutDate(candidate);
            }

            function applyValidDateToRow(rowInput, selectedDate) {
                var container = rowInput.closest('.cmp-portal-container');
                var rowNumber = parseInt(rowInput.data('row'));
                var totalDays = parseInt(container.data('total-days'));

                if (selectedDate) {
                    var nextDateString = getNextValidDeliveryDate(selectedDate);
                    for (var i = rowNumber + 1; i <= (totalDays + 10); i++) {
                        var targetInput = container.find('.cmp-date-picker[data-row="' + i + '"]');
                        if (targetInput.length) {
                            if (!canBypassDateLock && nextDateString) {
                                targetInput.attr('min', nextDateString);
                                if (targetInput.val() && targetInput.val() < nextDateString) {
                                    targetInput.val('');
                                }
                            } else {
                                targetInput.removeAttr('min');
                            }
                        }
                    }
                }

                var rowElement = rowInput.closest('tr');
                rowElement.removeClass('is-collapsed-desktop');
                var hasFood = populateDynamicDropdowns(rowElement);
                if (hasFood) {
                    calculateMacros(rowElement);
                    enforceMealQuota(rowElement);
                } else {
                    syncRowCompactAndSummary(rowElement);
                }
                evaluateRowDirtyState(rowElement);
            }

            function syncRowCompactAndSummary(rowElement) {
                var summaryTextEl   = rowElement.find('.cmp-summary-text');
                var mobileDateBadge = rowElement.find('.cmp-mobile-date-badge');
                var mobilePreviewEl = rowElement.find('.cmp-mobile-meal-preview');
                var dateVal         = (rowElement.find('.cmp-date-picker').val() || '').toString().trim();

                var shortDateStr = formatShortMobileDate(dateVal);
                if (mobileDateBadge.length) {
                    if (shortDateStr) {
                        mobileDateBadge.text(shortDateStr).addClass('has-date');
                    } else {
                        mobileDateBadge.text('').removeClass('has-date');
                    }
                }

                if (rowElement.hasClass('status-void')) {
                    if (summaryTextEl.length) {
                        summaryTextEl.html('<span>This delivery was cancelled &mdash; a replacement day has been added to your schedule</span>');
                    }
                    if (mobilePreviewEl.length) {
                        mobilePreviewEl.text('Cancelled — Replacement day added below').addClass('has-preview');
                    }
                    return;
                }

                var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
                var isUnsaved     = rowElement.hasClass('is-unsaved');
                var saveBtn       = rowElement.find('.cmp-save-row');
                var logId         = saveBtn.length ? (parseInt(saveBtn.data('log-id')) || 0) : 0;
                var hasValues     = false;
                var pickedNames   = [];

                rowElement.find('select.cmp-meal-select, select[class^="cmp-juice-"]').each(function() {
                    var val = $(this).val();
                    if (val && val !== "") {
                        hasValues = true;
                        var txt = $(this).find('option:selected').text().trim();
                        if (txt) pickedNames.push(txt);
                    }
                });
                rowElement.find('input.cmp-meal-custom').each(function() {
                    var val = $(this).val();
                    if (val && val.trim() !== "") {
                        hasValues = true;
                        pickedNames.push(val.trim());
                    }
                });

                if (isChefsChoice && !isChefOverride && !hasValues) {
                    rowElement.addClass('is-chefs-choice-compact');
                } else {
                    rowElement.removeClass('is-chefs-choice-compact');
                }

                if (summaryTextEl.length) {
                    var unsavedPrefix = isUnsaved ? '<strong style="color:#d97706;">[Unsaved Changes &mdash; Click Save Required! or Undo]</strong> ' : '';
                    if (isChefsChoice && !hasValues) {
                        summaryTextEl.html(unsavedPrefix + '<strong style="color:#b45309;">' + chefsChoiceLabel + ' Selected</strong> &mdash; Click to expand');
                    } else if (pickedNames.length > 0) {
                        summaryTextEl.html(unsavedPrefix + '<strong style="color:#1B5E20;">Selected Meals:</strong> ' + pickedNames.join('  &bull;  '));
                    } else if (logId > 0 && !isUnsaved) {
                        summaryTextEl.html('<strong style="color:#c2410c;">Date Reserved</strong> &mdash; Click to expand and select meals');
                    } else {
                        summaryTextEl.html(unsavedPrefix + 'Pending selection — Click to expand and choose meals');
                    }
                }

                if (mobilePreviewEl.length) {
                    if (isChefsChoice && !hasValues) {
                        mobilePreviewEl.text(chefsChoiceLabel + ' Selected').addClass('has-preview');
                    } else if (pickedNames.length > 0) {
                        var prefix = isChefsChoice ? ('[' + chefsChoiceLabel + '] ') : '';
                        mobilePreviewEl.text(prefix + pickedNames.join(' • ')).addClass('has-preview');
                    } else if (logId > 0 && !isUnsaved) {
                        mobilePreviewEl.text('Date Reserved — Tap to choose meals').addClass('has-preview');
                    } else {
                        mobilePreviewEl.text('').removeClass('has-preview');
                    }
                }
            }

            // =========================================================
            // NON-BLOCKING TOAST & INLINE CONFIRMATION MODAL HELPERS
            // =========================================================
            function cmpShowNotice(message, type) {
                $('.cmp-toast-notice').remove();
                var bg = (type === 'success') ? '#1B5E20' : '#dc2626';
                var border = (type === 'success') ? '#68B946' : '#fca5a5';
                var $toast = $('<div>', {
                    'class': 'cmp-toast-notice',
                    'text': message,
                    'css': {
                        position: 'fixed',
                        top: '22px',
                        left: '50%',
                        transform: 'translateX(-50%)',
                        background: bg,
                        color: '#ffffff',
                        border: '1.5px solid ' + border,
                        borderRadius: '10px',
                        padding: '11px 20px',
                        fontSize: '0.88rem',
                        fontWeight: '800',
                        boxShadow: '0 10px 25px rgba(15, 23, 42, 0.25)',
                        zIndex: 100000,
                        maxWidth: '92vw',
                        textAlign: 'center'
                    }
                }).appendTo('body');

                setTimeout(function() {
                    $toast.fadeOut(250, function() { $(this).remove(); });
                }, 3600);
            }

            function cmpConfirmAction(message, onConfirmCallback) {
                $('.cmp-confirm-modal-backdrop').remove();
                var $backdrop = $('<div>', {
                    'class': 'cmp-confirm-modal-backdrop',
                    'css': {
                        position: 'fixed',
                        inset: '0',
                        background: 'rgba(15, 23, 42, 0.55)',
                        backdropFilter: 'blur(4px)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        zIndex: 100001,
                        padding: '16px'
                    }
                });

                var $box = $('<div>', {
                    'css': {
                        background: '#ffffff',
                        border: '2px solid #DCECC9',
                        borderTop: '5px solid #379237',
                        borderRadius: '14px',
                        maxWidth: '420px',
                        width: '100%',
                        padding: '22px 20px',
                        boxShadow: '0 20px 40px rgba(15, 23, 42, 0.25)',
                        textAlign: 'center'
                    }
                });

                var $msg = $('<p>', {
                    'text': message,
                    'css': {
                        margin: '0 0 18px 0',
                        color: '#1e293b',
                        fontSize: '0.95rem',
                        fontWeight: '700',
                        lineHeight: '1.45',
                        whiteSpace: 'pre-line'
                    }
                });

                var $btnRow = $('<div>', {
                    'css': {
                        display: 'flex',
                        gap: '10px',
                        justifyContent: 'center'
                    }
                });

                var $cancelBtn = $('<button>', {
                    'type': 'button',
                    'text': 'Cancel',
                    'css': {
                        flex: '1',
                        padding: '10px 14px',
                        borderRadius: '8px',
                        border: '1.5px solid #cbd5e1',
                        background: '#f8fafc',
                        color: '#334155',
                        fontWeight: '800',
                        cursor: 'pointer'
                    }
                }).on('click', function() {
                    $backdrop.remove();
                });

                var $okBtn = $('<button>', {
                    'type': 'button',
                    'text': 'Confirm',
                    'css': {
                        flex: '1',
                        padding: '10px 14px',
                        borderRadius: '8px',
                        border: 'none',
                        background: '#379237',
                        color: '#ffffff',
                        fontWeight: '800',
                        cursor: 'pointer'
                    }
                }).on('click', function() {
                    $backdrop.remove();
                    if (typeof onConfirmCallback === 'function') {
                        onConfirmCallback();
                    }
                });

                $btnRow.append($cancelBtn, $okBtn);
                $box.append($msg, $btnRow);
                $backdrop.append($box).appendTo('body');
            }

            // =========================================================
            // FOH / STAFF INLINE LOGISTICS EDIT + HYBRID CUSTOM TIMING
            // =========================================================
            $('.toggle-logistics-edit').on('click', function(e) {
                e.preventDefault();
                var subId = $(this).data('sub-id');
                var viewDiv = $('#logistics-view-' + subId);
                var editDiv = $('#logistics-edit-' + subId);

                if (editDiv.is(':visible')) {
                    editDiv.hide();
                    viewDiv.show();
                    $(this).text('Edit');
                } else {
                    viewDiv.hide();
                    editDiv.show();
                    $(this).text('Cancel');
                }
            });

            // 1-Click Quick-Fill from Customer's Saved Logistics & Allergy Profile History
            $(document).on('change', '.cmp-saved-profile-select', function() {
                var $sel  = $(this);
                var subId = $sel.data('sub-id');
                var $opt  = $sel.find('option:selected');
                if (!$opt.val()) return;

                var profTiming    = ($opt.attr('data-timing') || '').toString();
                var profSlot      = ($opt.attr('data-slot') || '').toString();
                var profAllergies = ($opt.attr('data-allergies') || '').toString();

                var $timingSelect = $('#edit-timing-' + subId);
                var $slotSelect   = $('#edit-timeslot-' + subId);

                if (profTiming) {
                    if ($timingSelect.find('option[value="' + profTiming.replace(/"/g, '\\"') + '"]').length === 0) {
                        $('<option>', { value: profTiming, text: profTiming }).insertBefore($timingSelect.find('option[value="__custom__"]'));
                    }
                    $timingSelect.val(profTiming).trigger('change');
                }
                if (profSlot) {
                    if ($slotSelect.find('option[value="' + profSlot.replace(/"/g, '\\"') + '"]').length === 0) {
                        $('<option>', { value: profSlot, text: profSlot }).insertBefore($slotSelect.find('option[value="__custom__"]'));
                    }
                    $slotSelect.val(profSlot).trigger('change');
                }
                $('#edit-allergies-' + subId).val(profAllergies);
                cmpShowNotice('Loaded saved logistics & allergy profile into form. Click "Save Logistics Changes" to apply.', 'success');
            });

            $(document).on('change', '.cmp-logistics-timing-select', function() {
                var subId = $(this).data('sub-id');
                if ($(this).val() === '__custom__') {
                    $('#custom-timing-wrap-' + subId).slideDown(150);
                    $('#custom-timing-input-' + subId).focus();
                } else {
                    $('#custom-timing-wrap-' + subId).slideUp(150);
                }
            });

            $(document).on('change', '.cmp-logistics-slot-select', function() {
                var subId = $(this).data('sub-id');
                if ($(this).val() === '__custom__') {
                    $('#custom-slot-wrap-' + subId).slideDown(150);
                    $('#custom-slot-input-' + subId).focus();
                } else {
                    $('#custom-slot-wrap-' + subId).slideUp(150);
                }
            });

            $('.save-logistics-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var subId = btn.data('sub-id');
                var userId = btn.data('user-id');
                var orderId = btn.data('order-id');

                var timingSelect = $('#edit-timing-' + subId);
                var slotSelect   = $('#edit-timeslot-' + subId);
                var isCustomTiming = (timingSelect.val() === '__custom__');
                var isCustomSlot   = (slotSelect.val() === '__custom__');

                var timing   = isCustomTiming ? ($('#custom-timing-input-' + subId).val() || '').trim() : timingSelect.val();
                var timeSlot = isCustomSlot   ? ($('#custom-slot-input-' + subId).val() || '').trim()   : slotSelect.val();
                var allergies = $('#edit-allergies-' + subId).val();
                var saveCustomTiming = isCustomTiming && $('#save-custom-timing-' + subId).is(':checked') ? 1 : 0;
                var saveCustomSlot   = isCustomSlot   && $('#save-custom-slot-' + subId).is(':checked')   ? 1 : 0;

                if (!timing) {
                    cmpShowNotice('Please enter or select a valid Delivery Timing (Receive By) option.', 'error');
                    return;
                }
                if (!timeSlot) {
                    cmpShowNotice('Please enter or select a valid Time Slot option.', 'error');
                    return;
                }

                var origText = btn.text();
                btn.text('Saving...').prop('disabled', true);

                var data = {
                    action: 'cmp_cp_update_customer_logistics',
                    nonce: portalNonce,
                    user_id: userId,
                    order_id: orderId,
                    sub_id: subId,
                    delivery_timing: timing,
                    time_slot: timeSlot,
                    allergies: allergies,
                    save_custom_timing: saveCustomTiming,
                    save_custom_slot: saveCustomSlot
                };

                $.post(ajaxUrl, data, function(res) {
                    if (res.success) {
                        $('#timing-val-' + subId).text(res.data.timing);
                        $('#time-slot-val-' + subId).text(res.data.time_slot);
                        $('#allergies-val-' + subId).text(res.data.allergies);
                        $('#mobile-drawer-summary-' + subId).text(res.data.timing + ' (' + res.data.time_slot + ') • ' + res.data.allergies);

                        if (isCustomTiming) {
                            if (timingSelect.find('option[value="' + res.data.timing.replace(/"/g, '\\"') + '"]').length === 0) {
                                $('<option>', { value: res.data.timing, text: res.data.timing }).insertBefore(timingSelect.find('option[value="__custom__"]'));
                            }
                            timingSelect.val(res.data.timing);
                            $('#custom-timing-wrap-' + subId).hide();
                            $('#custom-timing-input-' + subId).val('');
                        }
                        if (isCustomSlot) {
                            if (slotSelect.find('option[value="' + res.data.time_slot.replace(/"/g, '\\"') + '"]').length === 0) {
                                $('<option>', { value: res.data.time_slot, text: res.data.time_slot }).insertBefore(slotSelect.find('option[value="__custom__"]'));
                            }
                            slotSelect.val(res.data.time_slot);
                            $('#custom-slot-wrap-' + subId).hide();
                            $('#custom-slot-input-' + subId).val('');
                        }

                        // Refresh Saved Logistics Profile dropdowns across all plan tabs for this customer
                        if (res.data.saved_profiles && Array.isArray(res.data.saved_profiles)) {
                            $('.cmp-saved-profile-select[data-user-id="' + userId + '"]').each(function() {
                                var $profSelect = $(this);
                                $profSelect.empty().append('<option value="">— Quick-Fill from Customer\'s Saved Profiles —</option>');
                                res.data.saved_profiles.forEach(function(p, idx) {
                                    var labelParts = [];
                                    if (p.plan_name) labelParts.push(p.plan_name);
                                    if (p.timing)    labelParts.push(p.timing);
                                    if (p.time_slot) labelParts.push('(' + p.time_slot + ')');
                                    if (p.allergies) labelParts.push('• Allergies: ' + p.allergies);
                                    $('<option>', {
                                        value: 'prof_' + idx,
                                        text: labelParts.join(' '),
                                        'data-timing': p.timing || '',
                                        'data-slot': p.time_slot || '',
                                        'data-allergies': p.allergies || ''
                                    }).appendTo($profSelect);
                                });
                                $profSelect.closest('.cmp-saved-profile-bar').show();
                            });
                        }

                        btn.text('✓ Saved').css('background', '#1B5E20');
                        cmpShowNotice('Logistics updated for this plan.', 'success');

                        setTimeout(function() {
                            $('#logistics-edit-' + subId).hide();
                            $('#logistics-view-' + subId).show();
                            $('.toggle-logistics-edit[data-sub-id="' + subId + '"]').text('Edit');
                            btn.text(origText).css('background', '#379237').prop('disabled', false);
                        }, 900);
                    } else {
                        cmpShowNotice('Error: ' + res.data, 'error');
                        btn.text(origText).prop('disabled', false);
                    }
                });
            });

            // ADMIN ACCOUNT DETAILS EDIT LOGIC
            $('.toggle-account-edit').on('click', function(e) {
                e.preventDefault();
                var subId = $(this).data('sub-id');
                var viewDiv = $('#account-view-' + subId);
                var editDiv = $('#account-edit-' + subId);

                if (editDiv.is(':visible')) {
                    editDiv.hide();
                    viewDiv.show();
                    $(this).text('Edit');
                } else {
                    viewDiv.hide();
                    editDiv.show();
                    $(this).text('Cancel');
                }
            });

            $('.save-account-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var subId = btn.data('sub-id');
                var userId = btn.data('user-id');
                var orderId = btn.data('order-id');
                var fName = $('#edit-fname-' + subId).val();
                var lName = $('#edit-lname-' + subId).val();
                var recipient = $('#edit-recipient-' + subId).val();
                var addr = $('#edit-address-' + subId).val();
                var origText = btn.text();

                if (!fName || !addr) {
                    cmpShowNotice('First Name and Address are required.', 'error');
                    return;
                }

                btn.text('Saving...').prop('disabled', true);

                var data = {
                    action: 'cmp_cp_admin_update_account_details',
                    nonce: portalNonce,
                    user_id: userId,
                    order_id: orderId,
                    sub_id: subId,
                    first_name: fName,
                    last_name: lName,
                    recipient_name: recipient,
                    address: addr
                };

                $.post(ajaxUrl, data, function(res) {
                    if (res.success) {
                        $('#name-val-' + subId).text(res.data.name);
                        $('#address-val-' + subId).text(res.data.address);

                        if (res.data.recipient) {
                            $('#recipient-val-' + subId).text(res.data.recipient);
                            $('#recipient-row-' + subId).css('display', 'inline-flex');
                        } else {
                            $('#recipient-row-' + subId).hide();
                        }

                        if (res.data.plan_name) {
                            $('#tab-title-' + subId).text(res.data.plan_name);
                        }

                        btn.text('✓ Saved').css('background', '#1B5E20');
                        cmpShowNotice('Account details saved.', 'success');

                        setTimeout(function() {
                            $('#account-edit-' + subId).hide();
                            $('#account-view-' + subId).show();
                            $('.toggle-account-edit[data-sub-id="' + subId + '"]').text('Edit');
                            btn.text(origText).css('background', '#379237').prop('disabled', false);
                        }, 1000);
                    } else {
                        cmpShowNotice('Error: ' + res.data, 'error');
                        btn.text(origText).prop('disabled', false);
                    }
                });
            });

            // PLAN DAYS CONTROLS: ADD / REMOVE DAYS LOGIC
            $('.admin-adjust-days-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var subId = btn.data('sub-id');
                var op = btn.data('operation');

                var confirmMsg = op === 'add' ? "Are you sure you want to ADD an extra day to this plan?" : "Are you sure you want to REMOVE a day from this plan?";
                cmpConfirmAction(confirmMsg, function() {
                    var origText = btn.text();
                    btn.text('Updating...').prop('disabled', true);

                    $.post(ajaxUrl, {
                        action: 'cmp_cp_admin_adjust_days',
                        nonce: portalNonce,
                        sub_id: subId,
                        operation: op
                    }, function(res) {
                        if(res.success) {
                            isIntentionalReload = true;
                            location.reload();
                        } else {
                            cmpShowNotice('Error: ' + res.data, 'error');
                            btn.text(origText).prop('disabled', false);
                        }
                    }).fail(function() {
                        cmpShowNotice('Connection error.', 'error');
                        btn.text(origText).prop('disabled', false);
                    });
                });
            });

            function populateDynamicDropdowns(rowElement) {
                var isCustom = rowElement.data('allow-custom') == 1;
                var dateVal = rowElement.find('.cmp-date-picker').val();
                var selects = rowElement.find('select.cmp-meal-select, select[class^="cmp-juice-"]');
                var inputs = rowElement.find('input.cmp-meal-custom');
                var snacksGrid = rowElement.find('.cmp-snacks-grid');

                var chefChoiceCell = rowElement.find('.cmp-cell-chef');
                var macroCell = rowElement.find('.cmp-cell-macros');
                var btn = rowElement.find('.cmp-save-row');

                rowElement.find('.boundary-overlay').remove();
                rowElement.find('.date-prompt-overlay').remove();

                var selectContainer = rowElement.find('td.cmp-cell-meal, td.cmp-cell-snack, td.cmp-cell-juice');

                if (!dateVal) {
                    selects.hide();
                    inputs.hide();
                    snacksGrid.hide();
                    chefChoiceCell.find('.cmp-chef-box').hide();
                    macroCell.css('opacity', '0.45');

                    selectContainer.append('<div class="date-prompt-overlay" style="color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; font-size:0.82em; font-style:italic; text-align:center; padding:8px;">&larr; Pick a date first to view meals.</div>');
                    return false;
                }

                macroCell.css('opacity', '1');

                if (isCustom) {
                    inputs.show();
                    snacksGrid.show();
                    chefChoiceCell.find('.cmp-chef-box').show();
                    if (btn.text() === 'Menu Pending') {
                        var btnTxt = rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') > 0 ? 'Update' : 'Save');
                        btn.text(btnTxt).css({'background':'#379237', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
                    }
                    return true;
                }

                var availableFoods = cmpGlobalFoodData.filter(function(food) {
                    if (!food.valid_from || !food.valid_until) return true;
                    return (dateVal >= food.valid_from && dateVal <= food.valid_until);
                });

                if (availableFoods.length === 0) {
                    selects.hide();
                    snacksGrid.hide();
                    chefChoiceCell.find('.cmp-chef-box').show();

                    selectContainer.append('<div class="boundary-overlay" style="color:#b45309; font-weight:bold; font-size:0.88em; background:#fef3c7; border:1px solid #fde68a; padding:11px; border-radius:8px; text-align:center; line-height:1.4;">Menu Pending Release</div>');

                    if (isChefOverride) {
                        btn.text('Menu Pending').css({'background':'#94a3b8', 'color':'#fff', 'cursor':'not-allowed'}).prop('disabled', true);
                    } else {
                        if (btn.text() === 'Menu Pending') {
                            var btnTxt = rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') > 0 ? 'Update' : 'Save');
                            btn.text(btnTxt).css({'background':'#379237', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
                        }
                    }
                    return false;
                }

                selects.show();
                snacksGrid.show();
                chefChoiceCell.find('.cmp-chef-box').show();

                if (btn.text() === 'Menu Pending') {
                    var btnTxt = rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') > 0 ? 'Update' : 'Save');
                    btn.text(btnTxt).css({'background':'#379237', 'color':'#fff', 'cursor':'pointer'}).prop('disabled', false);
                }

                selects.each(function() {
                    var select = $(this);
                    var category = select.data('cat');
                    var savedVal = select.data('saved-val');
                    var placeholder = select.find('option').first().text();

                    select.empty().append('<option value="">' + placeholder + '</option>');

                    if (category === 'Any') {
                        var optBreakfast = $('<optgroup label="— Breakfast —"></optgroup>');
                        var optLunch = $('<optgroup label="— Lunch —"></optgroup>');
                        var optDinner = $('<optgroup label="— Dinner —"></optgroup>');

                        availableFoods.forEach(function(food) {
                            if (food.category_name === 'Breakfast' || food.category_name === 'Lunch' || food.category_name === 'Dinner') {
                                var isSelected = (savedVal == food.id) ? 'selected' : '';
                                var optionHtml = '<option value="'+food.id+'" data-cal="'+food.calories+'" data-fat="'+food.total_fat+'" data-carbs="'+food.carbohydrates+'" data-pro="'+food.protein+'" '+isSelected+'>'+food.food_name+'</option>';

                                if (food.category_name === 'Breakfast') optBreakfast.append(optionHtml);
                                if (food.category_name === 'Lunch') optLunch.append(optionHtml);
                                if (food.category_name === 'Dinner') optDinner.append(optionHtml);
                            }
                        });

                        if (optBreakfast.children().length > 0) select.append(optBreakfast);
                        if (optLunch.children().length > 0) select.append(optLunch);
                        if (optDinner.children().length > 0) select.append(optDinner);

                    } else {
                        availableFoods.forEach(function(food) {
                            if (food.category_name === category) {
                                var isSelected = (savedVal == food.id) ? 'selected' : '';
                                select.append('<option value="'+food.id+'" data-cal="'+food.calories+'" data-fat="'+food.total_fat+'" data-carbs="'+food.carbohydrates+'" data-pro="'+food.protein+'" '+isSelected+'>'+food.food_name+'</option>');
                            }
                        });
                    }
                });

                return true;
            }

            $(document).on('focusin', '.cmp-day-row select, .cmp-day-row input, .cmp-day-row button', function() {
                if ($(window).width() <= 768) {
                    $('body').addClass('cmp-mobile-input-active');
                }
            });
            $(document).on('focusout', '.cmp-day-row select, .cmp-day-row input, .cmp-day-row button', function() {
                setTimeout(function() {
                    if (!$(document.activeElement).closest('.cmp-day-row').length) {
                        $('body').removeClass('cmp-mobile-input-active');
                    }
                }, 150);
            });

            $('.cmp-day-row td.cmp-cell-day, .cmp-day-row td.cmp-collapsed-summary').on('click', function() {
                var $row = $(this).closest('tr.cmp-day-row');
                if ($row.hasClass('status-void')) {
                    return;
                }
                if ($(window).width() <= 768) {
                    var willOpen = !$row.hasClass('is-open');
                    if (willOpen) {
                        $row.siblings('tr.cmp-day-row.is-open:not(.is-unsaved)').removeClass('is-open');
                        $row.addClass('is-open');
                        setTimeout(function() {
                            $('html, body').stop(true).animate({
                                scrollTop: Math.max(0, $row.offset().top - 10)
                            }, 220);
                        }, 40);
                    } else {
                        $row.removeClass('is-open');
                        $('body').removeClass('cmp-mobile-input-active');
                    }
                } else {
                    $row.toggleClass('is-collapsed-desktop');
                }
            });

            $('.cmp-collapse-all-btn').on('click', function() {
                var $container = $(this).closest('.cmp-portal-container');
                $container.find('tr.cmp-day-row').addClass('is-collapsed-desktop').removeClass('is-open');
            });

            $('.cmp-expand-all-btn').on('click', function() {
                var $container = $(this).closest('.cmp-portal-container');
                $container.find('tr.cmp-day-row:not(.status-void)').removeClass('is-collapsed-desktop');
            });

            function calculateMacros(rowElement) {
                var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
                var macroDisplay = rowElement.find('.cmp-macro-display');
                if (!macroDisplay.length) {
                    syncRowCompactAndSummary(rowElement);
                    return;
                }

                var isCustom = rowElement.data('allow-custom') == 1;
                var selects = rowElement.find('select');
                var inputs = rowElement.find('input.cmp-meal-custom');

                var hasValues = false;
                selects.each(function() { if ($(this).val() !== "") hasValues = true; });
                inputs.each(function() { if ($(this).val().trim() !== "") hasValues = true; });

                if (isChefsChoice && !hasValues) {
                    macroDisplay.html(
                        '<div class="cmp-macro-banner-state" style="background:#fffbeb; border:1px solid #fde68a; color:#b45309;">' +
                        '<strong>' + chefsChoiceLabel + ' Selected</strong> &mdash; Macros will appear once assigned by the Chef.' +
                        '</div>'
                    );
                    syncRowCompactAndSummary(rowElement);
                    return;
                }

                if (isCustom) {
                    macroDisplay.html(
                        '<div class="cmp-macro-banner-state" style="background:#EEF7E3; border:1px solid #DCECC9; color:#1B5E20;">' +
                        'Custom Meal Plan &mdash; Standard Macro Calculation N/A' +
                        '</div>'
                    );
                    syncRowCompactAndSummary(rowElement);
                    return;
                }

                var cal = 0, fat = 0, carbs = 0, pro = 0;
                selects.each(function() {
                    var selected = $(this).find('option:selected');
                    if (selected.length > 0 && selected.val() !== "") {
                        cal   += parseFloat(selected.data('cal'))   || 0;
                        fat   += parseFloat(selected.data('fat'))   || 0;
                        carbs += parseFloat(selected.data('carbs')) || 0;
                        pro   += parseFloat(selected.data('pro'))   || 0;
                    }
                });

                cal   = Math.round(cal);
                pro   = Math.round(pro * 10) / 10;
                carbs = Math.round(carbs * 10) / 10;
                fat   = Math.round(fat * 10) / 10;

                var chefPrefix = (isChefsChoice && hasValues)
                    ? '<div class="cmp-macro-chef-tag">Chef Assigned</div>'
                    : '';

                var tilesHtml = chefPrefix +
                    '<div class="cmp-macro-tiles-grid">' +
                        '<div class="cmp-macro-tile tile-cal"><span class="tile-label">Calories</span><span class="tile-val">' + cal + ' kcal</span></div>' +
                        '<div class="cmp-macro-tile tile-pro"><span class="tile-label">Protein</span><span class="tile-val">' + pro + 'g</span></div>' +
                        '<div class="cmp-macro-tile tile-carb"><span class="tile-label">Carbs</span><span class="tile-val">' + carbs + 'g</span></div>' +
                        '<div class="cmp-macro-tile tile-fat"><span class="tile-label">Fat</span><span class="tile-val">' + fat + 'g</span></div>' +
                    '</div>';

                macroDisplay.html(tilesHtml);
                syncRowCompactAndSummary(rowElement);
            }

            function enforceMealQuota(rowElement) {
                var container = rowElement.closest('.cmp-portal-container');
                var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
                var isJuice = container.data('is-juice') === 1;

                if (isJuice || allowedMeals <= 0) return;

                var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked');
                if (isChefsChoice && !isChefOverride) return;

                var mainSelects = rowElement.find('.cmp-main-meal');
                if (mainSelects.first().is(':disabled') && !mainSelects.first().hasClass('quota-locked') && !canBypassDateLock) {
                    return;
                }

                var selectedCount = 0;
                mainSelects.each(function() {
                    if ($(this).val().trim() !== "") selectedCount++;
                });

                if (selectedCount >= allowedMeals) {
                    mainSelects.each(function() {
                        if ($(this).val().trim() === "") {
                            $(this).prop('disabled', true).addClass('quota-locked');
                        } else {
                            $(this).prop('disabled', false).removeClass('quota-locked');
                        }
                    });
                } else {
                    mainSelects.each(function() {
                        $(this).prop('disabled', false).removeClass('quota-locked');
                    });
                }
            }

            $('.cmp-day-row').each(function() {
                var $row = $(this);
                var hasFood = populateDynamicDropdowns($row);
                if (hasFood) {
                    calculateMacros($row);
                    enforceMealQuota($row);
                } else {
                    syncRowCompactAndSummary($row);
                }
                captureRowSnapshot($row);
            });
            updatePendingCounts();

            $(document).on('change input', '.cmp-meal-select, .cmp-meal-custom, select[class^="cmp-juice-"]', function() {
                var rowElement = $(this).closest('tr');
                if ($(this).is('select')) {
                    $(this).data('saved-val', $(this).val());
                }
                calculateMacros(rowElement);
                enforceMealQuota(rowElement);
                evaluateRowDirtyState(rowElement);
            });

            $(document).on('click', '.cmp-undo-row', function(e) {
                e.preventDefault();
                var rowElement = $(this).closest('tr.cmp-day-row');
                restoreRowFromSnapshot(rowElement);
            });

            $('#cmp-float-jump-btn').on('click', function(e) {
                e.preventDefault();
                var $firstUnsaved = $('tr.cmp-day-row.is-unsaved').first();
                if ($firstUnsaved.length) {
                    focusRowFirstInput($firstUnsaved);
                }
            });

            $('#cmp-float-undo-all-btn').on('click', function(e) {
                e.preventDefault();
                $('tr.cmp-day-row.is-unsaved').each(function() {
                    restoreRowFromSnapshot($(this));
                });
            });

            $('.cmp-chefs-choice').on('change', function() {
                var rowElement = $(this).closest('tr');
                var inputs = rowElement.find('select, input.cmp-meal-custom');
                if ($(this).is(':checked')) {
                    inputs.prop('disabled', true).val('');
                    inputs.removeClass('quota-locked');
                } else {
                    inputs.prop('disabled', false);
                }
                calculateMacros(rowElement);
                enforceMealQuota(rowElement);
                evaluateRowDirtyState(rowElement);

                if (!$(this).is(':checked')) {
                    rowElement.removeClass('is-collapsed-desktop');
                    var firstMeal = rowElement.find('select.cmp-meal-select:visible:enabled, input.cmp-meal-custom:visible:enabled').first();
                    if (firstMeal.length) {
                        setTimeout(function() { firstMeal.focus(); }, 40);
                    }
                }
            });

            // =========================================================
            // iPHONE (iOS SAFARI) & CROSS-BROWSER DATE PICKER ENGINE
            // =========================================================
            $(document).on('touchstart focus', '.cmp-date-picker', function() {
                var rowInput = $(this);
                if (rowInput.is(':disabled') || canBypassDateLock) return;

                if (!rowInput.val()) {
                    var seedDate = getRowEarliestValidDate(rowInput);
                    if (seedDate) {
                        rowInput.attr('min', seedDate);
                        rowInput.val(seedDate);
                        applyValidDateToRow(rowInput, seedDate);
                    }
                }
            });

            function handleDatePickerValidation(rowInput, isBlurEvent) {
                var selectedDate = (rowInput.val() || '').toString().trim();
                var rowElement   = rowInput.closest('tr');

                if (!selectedDate) {
                    rowElement.removeClass('is-collapsed-desktop');
                    populateDynamicDropdowns(rowElement);
                    syncRowCompactAndSummary(rowElement);
                    evaluateRowDirtyState(rowElement);
                    return;
                }

                var earliestForRow = getRowEarliestValidDate(rowInput);
                var isLockedDate   = !canBypassDateLock && (selectedDate <= todayYmd || (customerMinDate && selectedDate < customerMinDate) || (earliestForRow && selectedDate < earliestForRow));
                var isBlackout     = blackoutDates.includes(selectedDate);

                if (isLockedDate || isBlackout) {
                    if (isIOSOrTouch && !isBlurEvent && rowInput.is(':focus')) {
                        return;
                    }

                    var snap         = rowElement.data('cmp-snapshot');
                    var fallbackDate = (snap && snap.date && snap.date >= earliestForRow && !blackoutDates.includes(snap.date))
                        ? snap.date
                        : earliestForRow;

                    if (isLockedDate) {
                        cmpShowNotice('Past dates, today\'s date, and dates past the cut-off time cannot be selected. Earliest available date: ' + (earliestForRow || customerMinDate), 'error');
                    } else {
                        cmpShowNotice('The kitchen is closed on this date. Please select a different day.', 'error');
                    }

                    if (isIOSOrTouch && fallbackDate) {
                        rowInput.val(fallbackDate);
                        applyValidDateToRow(rowInput, fallbackDate);
                    } else {
                        rowInput.val((snap && snap.date) ? snap.date : '');
                        applyValidDateToRow(rowInput, rowInput.val());
                    }
                    return;
                }

                applyValidDateToRow(rowInput, selectedDate);
            }

            $('.cmp-date-picker').on('change', function() {
                handleDatePickerValidation($(this), false);
            });

            $('.cmp-date-picker').on('blur', function() {
                handleDatePickerValidation($(this), true);
            });

            // =========================================================
            // SCROLL-TO-PENDING & KEYBOARD CONTINUOUS INPUT
            // =========================================================
            function focusRowFirstInput($targetRow) {
                if (!$targetRow || !$targetRow.length) return;
                $targetRow.removeClass('is-collapsed-desktop cmp-pulse-target');
                void $targetRow[0].offsetWidth;
                $targetRow.addClass('cmp-pulse-target');

                var scrollOffset = 110;
                if ($(window).width() <= 768) {
                    $targetRow.siblings('tr.cmp-day-row.is-open:not(.is-unsaved)').removeClass('is-open');
                    $targetRow.addClass('is-open');
                    scrollOffset = 10;
                }

                $('html, body').stop(true).animate({
                    scrollTop: Math.max(0, $targetRow.offset().top - scrollOffset)
                }, 280);

                setTimeout(function() {
                    var $dp = $targetRow.find('.cmp-date-picker');
                    if (!$dp.is(':disabled') && !$dp.val()) {
                        $dp.focus();
                    } else {
                        var $firstMeal = $targetRow.find('select.cmp-meal-select:visible:enabled, input.cmp-meal-custom:visible:enabled').first();
                        if ($firstMeal.length) {
                            $firstMeal.focus();
                        } else if (!$dp.is(':disabled')) {
                            $dp.focus();
                        } else {
                            $targetRow.find('.cmp-save-row:enabled').focus();
                        }
                    }
                }, 80);
            }

            $(document).on('click', '.cmp-jump-pending-btn', function(e) {
                e.preventDefault();
                var $activeContainer = $('.cmp-tab-content.active');
                if (!$activeContainer.length) return;

                var $firstPending = $activeContainer.find('tr.cmp-day-row.status-pending, tr.cmp-day-row.is-unsaved').first();
                if ($firstPending.length) {
                    focusRowFirstInput($firstPending);
                } else {
                    cmpShowNotice('All days in this meal plan have already been scheduled!', 'success');
                }
            });

            $(document).on('focusin', 'tr.cmp-day-row', function() {
                $(this).removeClass('is-collapsed-desktop');
            });

            $(document).on('keydown', 'tr.cmp-day-row', function(e) {
                var $row = $(this);
                var $saveBtn = $row.find('.cmp-save-row:enabled');

                if (e.key === 'Escape' && $row.hasClass('is-unsaved')) {
                    e.preventDefault();
                    restoreRowFromSnapshot($row);
                    return;
                }

                if (e.altKey && e.key === 'ArrowDown') {
                    e.preventDefault();
                    var $next = $row.nextAll('tr.cmp-day-row').first();
                    focusRowFirstInput($next);
                    return;
                }

                if (e.altKey && e.key === 'ArrowUp') {
                    e.preventDefault();
                    var $prev = $row.prevAll('tr.cmp-day-row').first();
                    focusRowFirstInput($prev);
                    return;
                }

                if (e.altKey && (e.key === 'c' || e.key === 'C')) {
                    var $cc = $row.find('.cmp-chefs-choice:enabled');
                    if ($cc.length) {
                        e.preventDefault();
                        $cc.prop('checked', !$cc.is(':checked')).trigger('change');
                    }
                    return;
                }

                var isCtrlEnter = (e.ctrlKey || e.metaKey) && e.key === 'Enter';
                var isDirectEnter = (e.key === 'Enter') && !$(e.target).is('select');

                if ((isCtrlEnter || isDirectEnter) && $saveBtn.length) {
                    e.preventDefault();
                    $saveBtn.data('kbd-advance', 1).trigger('click');
                }
            });

            $('.cmp-save-row').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var shouldKeyboardAdvance = (btn.data('kbd-advance') == 1);
                btn.data('kbd-advance', 0);

                var rowElement = btn.closest('tr');
                var container = btn.closest('.cmp-portal-container');

                var dateVal = rowElement.find('.cmp-date-picker').val();
                var isChefsChoice = rowElement.find('.cmp-chefs-choice').is(':checked') ? 1 : 0;
                var allowedMeals = parseInt(container.data('allowed-meals')) || 0;
                var snackQuota = parseInt(container.data('snack-quota')) || 0;
                var isJuice = container.data('is-juice') === 1;
                var isCustom = rowElement.data('allow-custom') == 1;

                if(!dateVal) {
                    rowElement.removeClass('is-collapsed-desktop');
                    cmpShowNotice('Please select a date from the calendar first.', 'error');
                    return;
                }

                if (!canBypassDateLock && (dateVal <= todayYmd || (customerMinDate && dateVal < customerMinDate))) {
                    cmpShowNotice('This date is locked. You may only save or update selections from ' + customerMinDate + ' onwards.', 'error');
                    return;
                }

                var isMenuPending = rowElement.find('.boundary-overlay').length > 0;

                if (!canBypassQuota && !isMenuPending && !isChefsChoice && !isCustom) {
                    if (isJuice) {
                        var juiceSelects = rowElement.find('select[class^="cmp-juice-"]');
                        var missingJuice = false;
                        juiceSelects.each(function() { if ($(this).val() === '') missingJuice = true; });
                        if (missingJuice) {
                            rowElement.removeClass('is-collapsed-desktop');
                            cmpShowNotice('Quota Requirement: Please select all 3 juices to complete this day.', 'error');
                            return;
                        }
                    } else {
                        var selectedMainCount = 0;
                        rowElement.find('.cmp-main-meal').each(function() {
                            if ($(this).val() !== "") selectedMainCount++;
                        });

                        if (selectedMainCount !== allowedMeals) {
                            rowElement.removeClass('is-collapsed-desktop');
                            cmpShowNotice('Quota Requirement: Please select exactly ' + allowedMeals + ' main meal(s) as allocated in this subscription before saving.', 'error');
                            return;
                        }

                        var selectedSnackCount = 0;
                        rowElement.find('select[data-cat="Snacks"]').each(function() {
                            if ($(this).val() !== "") selectedSnackCount++;
                        });

                        if (selectedSnackCount !== snackQuota) {
                            rowElement.removeClass('is-collapsed-desktop');
                            cmpShowNotice('Quota Requirement: Please select exactly ' + snackQuota + ' snack(s) as allocated in this subscription before saving.', 'error');
                            return;
                        }
                    }
                } else if (!canBypassQuota && !isChefsChoice && isCustom) {
                    if (isJuice) {
                        var juiceInputs = rowElement.find('.cmp-juice-1, .cmp-juice-2, .cmp-juice-3');
                        var missingJuice = false;
                        juiceInputs.each(function() { if ($(this).val().trim() === '') missingJuice = true; });
                        if (missingJuice) {
                            rowElement.removeClass('is-collapsed-desktop');
                            cmpShowNotice('Quota Requirement: Please complete all 3 juices to complete this day.', 'error');
                            return;
                        }
                    } else {
                        var selectedMainCount = 0;
                        rowElement.find('.cmp-main-meal').each(function() {
                            if ($(this).val().trim() !== "") selectedMainCount++;
                        });
                        if (selectedMainCount !== allowedMeals) {
                            rowElement.removeClass('is-collapsed-desktop');
                            cmpShowNotice('Quota Requirement: Please provide exactly ' + allowedMeals + ' main meal(s) as allocated in this subscription before saving.', 'error');
                            return;
                        }

                        var selectedSnackCount = 0;
                        rowElement.find('.cmp-snack-1, .cmp-snack-2').each(function() {
                            if ($(this).val().trim() !== "") selectedSnackCount++;
                        });
                        if (selectedSnackCount !== snackQuota) {
                            rowElement.removeClass('is-collapsed-desktop');
                            cmpShowNotice('Quota Requirement: Please provide exactly ' + snackQuota + ' snack(s) as allocated in this subscription before saving.', 'error');
                            return;
                        }
                    }
                } else if (isMenuPending && !isChefsChoice && !canBypassQuota && !isCustom) {
                    rowElement.removeClass('is-collapsed-desktop');
                    cmpShowNotice('The specific menu is not available yet. Please select "' + chefsChoiceLabel + '" to secure this date.', 'error');
                    return;
                }

                btn.text('Saving...').prop('disabled', true);

                var data = {
                    action: 'cmp_save_daily_log',
                    nonce: portalNonce,
                    log_id: btn.data('log-id'),
                    sub_id: btn.data('sub-id'),
                    date: dateVal,
                    chefs_choice: isChefsChoice,
                    is_override_mode: canBypassDateLock ? 1 : 0
                };

                if (isCustom) {
                    data.custom_breakfast = rowElement.find('[data-slot="breakfast"]').val() || null;
                    data.custom_lunch     = rowElement.find('[data-slot="lunch"]').val() || null;
                    data.custom_dinner    = rowElement.find('[data-slot="dinner"]').val() || null;
                    data.custom_snack_1   = rowElement.find('[data-slot="snack_1"]').val() || null;
                    data.custom_snack_2   = rowElement.find('[data-slot="snack_2"]').val() || null;
                    data.custom_juice_1   = rowElement.find('[data-slot="juice_1"]').val() || null;
                    data.custom_juice_2   = rowElement.find('[data-slot="juice_2"]').val() || null;
                    data.custom_juice_3   = rowElement.find('[data-slot="juice_3"]').val() || null;
                } else {
                    data.breakfast = isMenuPending ? null : (rowElement.find('[data-slot="breakfast"]').val() || null);
                    data.lunch     = isMenuPending ? null : (rowElement.find('[data-slot="lunch"]').val() || null);
                    data.dinner    = isMenuPending ? null : (rowElement.find('[data-slot="dinner"]').val() || null);
                    data.snack_1   = isMenuPending ? null : (rowElement.find('[data-slot="snack_1"]').val() || null);
                    data.snack_2   = isMenuPending ? null : (rowElement.find('[data-slot="snack_2"]').val() || null);
                    data.juice_1   = isMenuPending ? null : (rowElement.find('[data-slot="juice_1"]').val() || null);
                    data.juice_2   = isMenuPending ? null : (rowElement.find('[data-slot="juice_2"]').val() || null);
                    data.juice_3   = isMenuPending ? null : (rowElement.find('[data-slot="juice_3"]').val() || null);
                }

                $.post(ajaxUrl, data, function(response) {
                    if(response.success) {
                        if(response.data && response.data.new_log_id) {
                            btn.data('log-id', response.data.new_log_id);
                        }

                        var hasSavedMeals = false;
                        rowElement.find('select.cmp-meal-select, select[class^="cmp-juice-"], input.cmp-meal-custom').each(function() {
                            if (($(this).val() || '').toString().trim() !== '') {
                                hasSavedMeals = true;
                            }
                        });

                        var newBadge = (!isChefsChoice && !hasSavedMeals)
                            ? '<span class="cmp-status-pill pill-meals-pending">Meals Pending</span>'
                            : '<span class="cmp-status-pill pill-confirmed">Selected</span>';
                        rowElement.find('.cmp-desktop-status-holder').html(newBadge);
                        rowElement.find('.cmp-mobile-status').html(newBadge);

                        if (canViewPosCheck) {
                            var isPosChecked = (response.data && parseInt(response.data.pos_updated) === 1);
                            var desktopPosHtml = isPosChecked
                                ? '<span class="cmp-pos-readonly-pill pos-is-checked" title="POS Reconciled">✓ POS Checked</span>'
                                : '<span class="cmp-pos-readonly-pill pos-is-pending" title="Pending POS Check">POS Pending</span>';
                            var mobilePosHtml = isPosChecked
                                ? '<span class="cmp-pos-micro-pill pos-is-checked" title="POS Checked">POS ✓</span>'
                                : '<span class="cmp-pos-micro-pill pos-is-pending" title="Pending POS Check">POS •</span>';
                            rowElement.find('.cmp-desktop-pos-holder').html(desktopPosHtml);
                            rowElement.find('.cmp-mobile-pos-holder').html(mobilePosHtml);
                        }

                        markRowSaved(rowElement);
                        rowElement.removeClass('status-pending').addClass('status-saved is-collapsed-desktop');
                        captureRowSnapshot(rowElement);
                        syncRowCompactAndSummary(rowElement);
                        updatePendingCounts(container);

                        if (!canBypassDateLock) {
                            var isTimeLocked = (dateVal <= todayYmd || (customerMinDate && dateVal < customerMinDate));

                            if (isTimeLocked) {
                                rowElement.find('.cmp-date-picker, .cmp-chefs-choice, select, input.cmp-meal-custom').prop('disabled', true).removeClass('quota-locked');
                                rowElement.find('.cmp-action-box').html('<span style="color:#94a3b8; font-size:0.85em; font-weight:bold; display:block; text-align:center; width:100%; padding:10px 0;">Locked (Cutoff Passed)</span>');
                            } else {
                                btn.text('✓ Updated').css({'background':'#1B5E20'}).prop('disabled', false);
                            }
                        } else {
                            btn.text('✓ Updated').css({'background':'#1B5E20'}).prop('disabled', false);
                        }

                        var $nextRow = rowElement.nextAll('tr.cmp-day-row.status-pending').first();
                        if ($(window).width() <= 768) {
                            rowElement.removeClass('is-open');
                            if ($nextRow.length) {
                                focusRowFirstInput($nextRow);
                            }
                        } else if (shouldKeyboardAdvance || $nextRow.length) {
                            focusRowFirstInput($nextRow);
                        }

                    } else {
                        cmpShowNotice('Error: ' + response.data, 'error');
                        btn.text(rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') ? 'Update' : 'Save')).prop('disabled', false);
                    }
                }).fail(function() {
                    cmpShowNotice('Connection error. Please try saving again.', 'error');
                    btn.text(rowElement.hasClass('is-unsaved') ? 'Save Required!' : (btn.data('log-id') ? 'Update' : 'Save')).prop('disabled', false);
                });
            });

            // CLEAR / RESET SELECTION LOGIC
            $(document).on('click', '.cmp-clear-row', function(e) {
                e.preventDefault();
                var btn = $(this);
                var rowElement = btn.closest('tr');
                var dateVal = rowElement.find('.cmp-date-picker').val();

                if (!canBypassDateLock && dateVal && (dateVal <= todayYmd || (customerMinDate && dateVal < customerMinDate))) {
                    cmpShowNotice('Past dates, today\'s date, and dates past the cut-off time cannot be reset.', 'error');
                    return;
                }

                cmpConfirmAction('Are you sure you want to clear this day? This will reset the selection and return the quota so a new date can be picked.', function() {
                    var origHTML = btn.html();
                    btn.html('...').prop('disabled', true);

                    var data = {
                        action: 'cmp_clear_daily_log',
                        nonce: portalNonce,
                        log_id: btn.data('log-id'),
                        sub_id: btn.data('sub-id'),
                        is_override_mode: canBypassDateLock ? 1 : 0
                    };

                    $.post(ajaxUrl, data, function(response) {
                        if (response.success) {
                            markRowSaved(rowElement);
                            isIntentionalReload = true;
                            location.reload();
                        } else {
                            cmpShowNotice('Error: ' + response.data, 'error');
                            btn.html(origHTML).prop('disabled', false);
                        }
                    }).fail(function() {
                        cmpShowNotice('Connection error.', 'error');
                        btn.html(origHTML).prop('disabled', false);
                    });
                });
            });

            // CANCEL DAY LOGIC
            $(document).on('click', '.cmp-cancel-day-row', function(e) {
                e.preventDefault();
                var btn = $(this);
                var rowElement = btn.closest('tr');
                var dateVal = rowElement.find('.cmp-date-picker').val();

                if (!canBypassDateLock && dateVal && (dateVal <= todayYmd || (customerMinDate && dateVal < customerMinDate))) {
                    cmpShowNotice('Past dates, today\'s date, and dates past the cut-off time cannot be cancelled.', 'error');
                    return;
                }

                cmpConfirmAction('Are you sure you want to CANCEL this specific day?\n\nThis will permanently void this date, display it as "Cancelled", and push a replacement day to the bottom of the plan.', function() {
                    var origHTML = btn.html();
                    btn.html('...').prop('disabled', true);

                    var data = {
                        action: 'cmp_cancel_daily_log',
                        nonce: portalNonce,
                        log_id: btn.data('log-id'),
                        sub_id: btn.data('sub-id'),
                        is_override_mode: canBypassDateLock ? 1 : 0
                    };

                    $.post(ajaxUrl, data, function(response) {
                        if (response.success) {
                            markRowSaved(rowElement);
                            isIntentionalReload = true;
                            location.reload();
                        } else {
                            cmpShowNotice('Error: ' + response.data, 'error');
                            btn.html(origHTML).prop('disabled', false);
                        }
                    }).fail(function() {
                        cmpShowNotice('Connection error.', 'error');
                        btn.html(origHTML).prop('disabled', false);
                    });
                });
            });

            // =========================================================
            // OPTION 4A: CUSTOMER EXPERIENCE FEEDBACK SURVEY HANDLERS
            // =========================================================
            var ratingLabels = ['', '1/5 - Poor', '2/5 - Fair', '3/5 - Good', '4/5 - Very Good', '5/5 - Excellent'];

            $(document).on('click', '.cmp-star-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var val  = parseInt($btn.data('val')) || 0;
                var $grp = $btn.closest('.cmp-star-group');
                $grp.attr('data-rating', val).data('rating', val);

                $grp.find('.cmp-star-btn').each(function() {
                    var sVal = parseInt($(this).data('val')) || 0;
                    $(this).toggleClass('is-active', sVal <= val);
                });
                $grp.find('.cmp-rating-score-txt').text(ratingLabels[val] || '');
            });

            $(document).on('click', '.cmp-feedback-tag-pill', function(e) {
                e.preventDefault();
                $(this).toggleClass('is-selected');
            });

            $(document).on('change', '.cmp-feedback-identity-chk', function() {
                var subId = $(this).data('sub-id');
                var isChecked = $(this).is(':checked');
                var $pill = $('#feedback-id-pill-' + subId);
                if (isChecked) {
                    $pill.text('Details Included').removeClass('is-anon');
                } else {
                    $pill.text('Anonymous Mode').addClass('is-anon');
                }
            });

            $(document).on('click', '.cmp-submit-feedback-btn', function(e) {
                e.preventDefault();
                var $btn  = $(this);
                var subId = $btn.data('sub-id');
                var $body = $('#feedback-body-' + subId);

                var serviceRating = parseInt($body.find('.cmp-star-group[data-rating-type="service"]').attr('data-rating')) || 0;
                var portalRating  = parseInt($body.find('.cmp-star-group[data-rating-type="portal"]').attr('data-rating')) || 0;

                if (serviceRating < 1 && portalRating < 1) {
                    cmpShowNotice('Please tap a star rating for Meal Plan Service or Online Portal Experience.', 'error');
                    return;
                }

                var selectedTags = [];
                $('#feedback-tags-' + subId + ' .cmp-feedback-tag-pill.is-selected').each(function() {
                    selectedTags.push($(this).data('tag'));
                });

                var comments       = ($('#feedback-comments-' + subId).val() || '').trim();
                var includeDetails = $('#feedback-identity-' + subId).is(':checked') ? 1 : 0;

                $btn.text('Submitting...').prop('disabled', true);

                $.post(ajaxUrl, {
                    action: 'cmp_submit_customer_feedback',
                    nonce: portalNonce,
                    sub_id: subId,
                    service_rating: serviceRating,
                    portal_rating: portalRating,
                    quick_tags: selectedTags.join(', '),
                    comments: comments,
                    include_details: includeDetails
                }, function(res) {
                    if (res.success) {
                        $body.html(
                            '<div style="padding:18px; background:#f0fdf4; border:1.5px solid #86efac; border-radius:12px; text-align:center; color:#166534; font-weight:800;">' +
                            '✓ ' + res.data.msg +
                            '</div>'
                        );
                    } else {
                        cmpShowNotice('Error: ' + res.data, 'error');
                        $btn.text('Submit Feedback').prop('disabled', false);
                    }
                }).fail(function() {
                    cmpShowNotice('Connection error. Please try again.', 'error');
                    $btn.text('Submit Feedback').prop('disabled', false);
                });
            });

        });
        </script>
        <?php
    }
}
