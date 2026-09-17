<?php
/*
Plugin Name: SS Multi Marketplace Pro for WooCommerce – External Product Links, Custom Buttons & Click Analytics
Description: Replace WooCommerce Add to Cart with custom external buttons (Free Version - Limited to 5 Products)
Version: 1.0 FREE
Author: Sharesoft Technology
*/

// Free Version Configuration
define('SS_MMP_IS_FREE', true);
define('SS_MMP_MAX_PRODUCTS', 5);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// 1. Database Table Creation on Activation
register_activation_hook( __FILE__, 'ss_mmp_create_click_table' );

// Filter to ensure default icon is always available
add_filter( 'option_ss_mmp_default_custom_icon', function( $value ) {
    if ( empty( $value ) ) {
        return plugin_dir_url( __FILE__ ) . 'assets/image/online-shopping.png';
    }
    return $value;
} );

// Add Settings link to plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="admin.php?page=ss-mmp-settings">' . __('Settings', 'ss-mmp') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

function ss_mmp_create_click_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ss_mmp_clicks';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        app_name varchar(255) NOT NULL,
        country_code varchar(10) DEFAULT 'Unknown' NOT NULL,
        city_name varchar(255) DEFAULT 'Unknown' NOT NULL,
        latitude varchar(50) DEFAULT '' NOT NULL,
        longitude varchar(50) DEFAULT '' NOT NULL,
        click_count bigint(20) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY product_app_full_location (product_id, app_name, country_code, city_name, latitude, longitude)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    // Set default custom icon
    if ( ! get_option( 'ss_mmp_default_custom_icon' ) ) {
        update_option( 'ss_mmp_default_custom_icon', plugin_dir_url( __FILE__ ) . 'assets/image/online-shopping.png' );
    }
}

// Check if WooCommerce is active
add_action( 'admin_init', 'ss_mmp_check_woocommerce_dependency' );

// Enqueue Admin Styles
add_action('admin_enqueue_scripts', function($hook) {
    wp_enqueue_style('ss-mmp-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.0');
    
    // Enqueue Media & Color Picker for Settings Page
    if ($hook === 'toplevel_page_ss-mmp-settings' || $hook === 'multi-marketplace_page_ss-mmp-settings') {
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
});

// Enqueue Public Styles
add_action('wp_enqueue_scripts', function() {
    if (is_product() || is_shop() || is_product_category() || is_product_tag()) {
        wp_enqueue_style('ss-mmp-public-style', plugin_dir_url(__FILE__) . 'assets/css/public-style.css', [], '1.0.0');
        
        // Inject Dynamic Colors as CSS Variables
        $btn_color = get_option('ss_mmp_btn_color', '#a46497');
        $btn_text_color = get_option('ss_mmp_btn_text_color', '#ffffff');
        $btn_hover_color = get_option('ss_mmp_btn_hover_color', '#8a4d7a');
        $btn_font_size = get_option('ss_mmp_btn_font_size', '14');
        $scrollbar_color = get_option('ss_mmp_scrollbar_color', '#ff9900');
        
        $custom_css = "
            :root {
                --ss-mmp-btn-bg: $btn_color;
                --ss-mmp-btn-text: $btn_text_color;
                --ss-mmp-btn-hover: $btn_hover_color;
                --ss-mmp-btn-font-size: {$btn_font_size}px;
                --ss-mmp-scrollbar-thumb: $scrollbar_color;
            }
        ";
        wp_add_inline_style('ss-mmp-public-style', $custom_css);
    }
});

function ss_mmp_check_woocommerce_dependency() {
    // Ensure click table exists and has latest columns
    global $wpdb;
    $table_name = $wpdb->prefix . 'ss_mmp_clicks';
    
    // Force recreate table to ensure the most robust unique key is applied
    // This will include lat/lng in the unique check to guarantee separate rows for different locations
    $index = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM $table_name WHERE Key_name = %s", 'product_app_full_location'));
    if (empty($index)) {
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        ss_mmp_create_click_table();
    }

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'ss_mmp_woocommerce_missing_notice' );
        
        // Deactivate the plugin if WooCommerce is missing
        deactivate_plugins( plugin_basename( __FILE__ ) );
        
        if ( isset( $_GET['activate'] ) ) {
            unset( $_GET['activate'] );
        }
    }
}

function ss_mmp_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e( 'SS MultiMarketPlace plugin require WooCommerce to be installed and active.', 'ss-mmp' ); ?></p>
    </div>
    <?php
}

// Only run plugin logic if WooCommerce is active
add_action( 'plugins_loaded', 'ss_mmp_init_plugin' );

function ss_mmp_init_plugin() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    // 1. Add custom product tab
    add_filter('woocommerce_product_data_tabs', function($tabs) {
        $tabs['ss_multimarketplace'] = [
            'label'   => __( 'Multi Marketplace', 'ss-mmp' ),
            'target'  => 'ss_multimarketplace_data',
            'class'   => [ 'show_if_simple', 'show_if_variable', 'show_if_external' ],
            'priority' => 50,
        ];
        return $tabs;
    });

    // 3. Add custom fields in the new tab
    add_action('woocommerce_product_data_panels', function() {
        global $post;
        $buttons = get_post_meta($post->ID, '_ss_mmp_buttons', true);
        if (!is_array($buttons)) {
            $buttons = [];
        }
        
        // Free version: Check product limit
        if (SS_MMP_IS_FREE) {
            $product_count = ss_mmp_count_products_with_buttons();
            $has_buttons = !empty($buttons);
            
            if ($product_count >= SS_MMP_MAX_PRODUCTS && !$has_buttons) {
                // Show upgrade message if limit reached and current product doesn't have buttons
                ?>
                <div id="ss_multimarketplace_data" class="panel woocommerce_options_panel">
                    <div class="options_group">
                        <div style="background: #ffebcd; border: 2px solid #ff6b35; padding: 20px; margin: 20px 0; border-radius: 8px; text-align: center;">
                            <h3 style="color: #ff6b35; margin-top: 0;">🔒 Product Limit Reached!</h3>
                            <p style="font-size: 16px; margin-bottom: 15px;">You have reached the maximum limit of <strong><?php echo SS_MMP_MAX_PRODUCTS; ?> products</strong> in the free version.</p>
                            <p style="margin-bottom: 20px; color: #666;">To add marketplace buttons to unlimited products and unlock premium features, upgrade now!</p>
                            <a href="https://www.sharesoft.in/products/ss-multi-marketplace-pro-for-woocommerce-external-product-links-custom-buttons-click-analytics/" target="_blank" style="background: #ff6b35; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;">
                                🚀 Buy Pro Version Now
                            </a>
                        </div>
                    </div>
                </div>
                <?php
                return;
            }
        }
        
        ?>
        <div id="ss_multimarketplace_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php if (SS_MMP_IS_FREE): ?>
                    <div style="background: #e8f4f8; border-left: 4px solid #0073aa; padding: 10px; margin-bottom: 15px;">
                        <strong>Free Version:</strong> You can add marketplace buttons to maximum <?php echo SS_MMP_MAX_PRODUCTS; ?> products. 
                        Currently used: <strong><?php echo $product_count; ?>/<?php echo SS_MMP_MAX_PRODUCTS; ?></strong>
                    </div>
                <?php endif; ?>
                <p><strong>Add Marketplace Buttons</strong></p>
                <table id="ss-mmp-repeater-table">
                    <thead>
                        <tr>
                            <th>App Name (e.g. Amazon)</th>
                            <th>Product Link</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="ss-mmp-repeater-body">
                        <?php if (!empty($buttons)): ?>
                            <?php foreach ($buttons as $button): ?>
                                <tr class="ss-mmp-row">
                                    <td><input type="text" name="ss_mmp_text[]" value="<?php echo esc_attr($button['text']); ?>" placeholder="Buy on Amazon" style="width:100%;"></td>
                                    <td><input type="url" name="ss_mmp_link[]" value="<?php echo esc_url($button['link']); ?>" placeholder="https://..." style="width:100%;"></td>
                                    <td><button type="button" class="button ss-mmp-remove-row"><?php _e('Remove', 'ss-mmp'); ?></button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" id="ss-mmp-add-row" class="button button-primary"><?php _e('Add New Button', 'ss-mmp'); ?></button>
                    <?php if (SS_MMP_IS_FREE): ?>
                        <small style="color: #666; margin-left: 10px;">
                            <em>Free version: Maximum 5 buttons per product</em>
                        </small>
                    <?php endif; ?>
                </p>
            </div>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#ss-mmp-add-row').on('click', function(e) {
                        e.preventDefault();
                        
                        // Check if we've reached the maximum number of buttons (5 for free version)
                        var currentRows = $('#ss-mmp-repeater-body tr').length;
                        if (currentRows >= 5) {
                            // Show upgrade prompt instead of adding new row
                            if (confirm('🔒 Free Version Limit: You can add maximum 5 marketplace buttons per product.\n\nClick OK to upgrade to Pro Version for unlimited buttons, or Cancel to continue.')) {
                                window.open('http://www.sharesoft.in/ss-multimarketplace-pro/', '_blank');
                            }
                            return false;
                        }
                        
                        var newRow = `
                            <tr class="ss-mmp-row">
                                <td><input type="text" name="ss_mmp_text[]" value="" placeholder="Text" style="width:100%;"></td>
                                <td><input type="url" name="ss_mmp_link[]" value="" placeholder="https://..." style="width:100%;"></td>
                                <td><button type="button" class="button ss-mmp-remove-row">Remove</button></td>
                            </tr>`;
                        $('#ss-mmp-repeater-body').append(newRow);
                    });

                    $(document).on('click', '.ss-mmp-remove-row', function(e) {
                        e.preventDefault();
                        $(this).closest('tr').remove();
                    });
                });
            </script>
        </div>
        <?php
    });

    // 4. Save custom fields
    add_action('woocommerce_process_product_meta', function($post_id) {
        if (isset($_POST['ss_mmp_text']) && isset($_POST['ss_mmp_link'])) {
            $texts = $_POST['ss_mmp_text'];
            $links = $_POST['ss_mmp_link'];
            $buttons = [];

            if (is_array($texts)) {
                foreach ($texts as $i => $text) {
                    $link = isset($links[$i]) ? $links[$i] : '';
                    if (!empty($text) && !empty($link)) {
                        $buttons[] = [
                            'text' => sanitize_text_field($text),
                            'link' => esc_url_raw($link)
                        ];
                    }
                }
            }
            
            // Free version: Check product limit before saving
            if (SS_MMP_IS_FREE && !empty($buttons)) {
                $current_buttons = get_post_meta($post_id, '_ss_mmp_buttons', true);
                $had_buttons_before = !empty($current_buttons) && is_array($current_buttons);
                
                if (!$had_buttons_before) {
                    // This is a new product getting buttons
                    $product_count = ss_mmp_count_products_with_buttons();
                    if ($product_count >= SS_MMP_MAX_PRODUCTS) {
                        // Prevent saving and show error
                        add_action('admin_notices', function() {
                            echo '<div class="error notice"><p><strong>Free Version Limit:</strong> You can only add marketplace buttons to ' . SS_MMP_MAX_PRODUCTS . ' products. <a href="http://www.sharesoft.in/ss-multimarketplace-pro/" target="_blank" style="color: #ff6b35; font-weight: bold; text-decoration: none;">Buy Pro Version to get unlimited products →</a></p></div>';
                        });
                        return;
                    }
                }
            }
            
            if (!empty($buttons)) {
                update_post_meta($post_id, '_ss_mmp_buttons', $buttons);
            } else {
                delete_post_meta($post_id, '_ss_mmp_buttons');
            }
        } else {
            delete_post_meta($post_id, '_ss_mmp_buttons');
        }
    });
}

    // 4. Helper function to render buttons
    function ss_mmp_render_external_buttons($post_id) {
        static $rendered_styles = [];
        // Check User Role Visibility
        $selected_roles = get_option('ss_mmp_user_roles', []);
        if (!empty($selected_roles) && is_array($selected_roles)) {
            $user = wp_get_current_user();
            $user_roles = (array) $user->roles;
            $has_access = false;
            foreach ($user_roles as $role) {
                if (in_array($role, $selected_roles)) {
                    $has_access = true;
                    break;
                }
            }
            if (!$has_access) return;
        }

        $buttons = get_post_meta($post_id, '_ss_mmp_buttons', true);
        $prefix = get_option('ss_mmp_btn_prefix', 'Buy on');
        $app_styles = get_option('ss_mmp_app_styles', []);
        $default_custom_icon = get_option('ss_mmp_default_custom_icon', plugin_dir_url(__FILE__) . 'assets/image/online-shopping.png');

        if (!empty($buttons) && is_array($buttons)) {
            echo '<div class="ss-external-buttons-container">';
            foreach ($buttons as $button) {
                $original_text = $button['text'];
                $text = $original_text;
                if (!empty($prefix) && stripos($text, $prefix) === false) {
                    $text = $prefix . ' ' . $text;
                }

                // Check for app specific styles
                $custom_style = '';
                $icon_html = '';
                $app_key = strtolower(trim($original_text));
                
                if (!empty($app_styles) && is_array($app_styles)) {
                    foreach ($app_styles as $style) {
                        if (strtolower(trim($style['app_name'])) === $app_key) {
                            $bg = !empty($style['bg_color']) ? $style['bg_color'] : '';
                            $txt = !empty($style['text_color']) ? $style['text_color'] : '';
                            $hov = !empty($style['hover_color']) ? $style['hover_color'] : '';
                            $custom_icon = !empty($style['custom_icon']) ? $style['custom_icon'] : '';

                            $unique_id = 'ss-btn-' . substr(md5($app_key), 0, 8);
                            if (($bg || $txt || $hov) && !isset($rendered_styles[$unique_id])) {
                                echo '<style>';
                                if ($bg) echo ".ss-external-buttons-container a.ss-external-btn.{$unique_id} { background-color: {$bg} !important; }";
                                if ($txt) echo ".ss-external-buttons-container a.ss-external-btn.{$unique_id} { color: {$txt} !important; }";
                                if ($hov) echo ".ss-external-buttons-container a.ss-external-btn.{$unique_id}:hover { background-color: {$hov} !important; }";
                                echo '</style>';
                                $rendered_styles[$unique_id] = true;
                            }
                            $custom_style = $unique_id;
                            
                            if ($custom_icon) {
                                $icon_html = '<img src="' . esc_url($custom_icon) . '" style="width:18px; height:18px; margin-right:8px; vertical-align:middle; object-fit:contain;">';
                            }
                            break;
                        }
                    }
                }

                // If no app-specific icon, use default custom icon
                if (empty($icon_html) && !empty($default_custom_icon)) {
                    $icon_html = '<img src="' . esc_url($default_custom_icon) . '" style="width:18px; height:18px; margin-right:8px; vertical-align:middle; object-fit:contain;">';
                }

                echo '<a href="'.esc_url($button['link']).'" target="_blank" class="button alt ss-external-btn '.esc_attr($custom_style).'" data-product-id="'.esc_attr($post_id).'" data-app-name="'.esc_attr($original_text).'">'.$icon_html.esc_html($text).'</a>';
             }
             echo '</div>';
         }
    }

    // Helper to count products with marketplace buttons (for free version limit)
    function ss_mmp_count_products_with_buttons() {
        global $wpdb;
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_ss_mmp_buttons' 
            AND meta_value != '' 
            AND meta_value != 'a:0:{}'
        ");
        return intval($count);
    }

    // Helper to get all unique app names from all products
    function ss_mmp_get_all_unique_apps() {
        global $wpdb;
        $results = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ss_mmp_buttons'");
        $apps = [];
        if (!empty($results)) {
            foreach ($results as $meta_value) {
                $buttons = maybe_unserialize($meta_value);
                if (is_array($buttons)) {
                    foreach ($buttons as $btn) {
                        if (!empty($btn['text'])) {
                            $apps[] = trim($btn['text']);
                        }
                    }
                }
            }
        }
        return array_unique($apps);
    }

    // 5. Apply display logic based on settings
    add_action('wp', function() {
        $hide_cart = get_option('ss_mmp_hide_cart_btn', 0);
        $show_on_product = get_option('ss_mmp_show_on_product', 1);
        $show_on_shop = get_option('ss_mmp_show_on_shop', 0);
        $placement = get_option('ss_mmp_button_placement', 'below');

        // 1. Single Product Page Logic
        if (is_product()) {
            if ($hide_cart) {
                // Remove standard Add to Cart actions
                remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
                remove_action('woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart', 30);
                remove_action('woocommerce_variable_add_to_cart', 'woocommerce_variable_add_to_cart', 30);
                remove_action('woocommerce_grouped_add_to_cart', 'woocommerce_grouped_add_to_cart', 30);
                remove_action('woocommerce_external_add_to_cart', 'woocommerce_external_add_to_cart', 30);
                remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);
                
                // Ensure quantity input is also hidden if cart is hidden
                add_filter('woocommerce_is_purchasable', '__return_false');
            }

            if ($show_on_product) {
                // Above Add to Cart (Priority 25) or Below (Priority 50)
                $priority = ($placement === 'above') ? 25 : 50;
                add_action('woocommerce_single_product_summary', function() {
                    global $post;
                    ss_mmp_render_external_buttons($post->ID);
                }, $priority);
            }
        }

        // 2. Shop / Archive Pages Logic
        if (is_shop() || is_product_category() || is_product_tag()) {
            if ($hide_cart) {
                // Remove standard Loop Add to Cart (Priority 10)
                remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);
            }

            if ($show_on_shop) {
                // Always show after Loop Add to Cart area (Priority 15)
                add_action('woocommerce_after_shop_loop_item', function() {
                    global $product;
                    ss_mmp_render_external_buttons($product->get_id());
                }, 15);
            }
        }
    }, 99);

    add_action('wp_head', function() {
        if (is_product() || is_shop() || is_product_category() || is_product_tag()) {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                var userLat = '', userLng = '', userCountry = '', userCity = '';

                // Get Geolocation if possible
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        userLat = position.coords.latitude;
                        userLng = position.coords.longitude;
                        
                        // Reverse Geocode for City/Country (Optional but helps for UI or validation)
                        fetch(`https://nominatim.openstreetmap.org/reverse?lat=${userLat}&lon=${userLng}&format=json`)
                        .then(res => res.json())
                        .then(data => {
                            if(data.address) {
                                userCountry = data.address.country_code || '';
                                userCity = data.address.city || data.address.town || data.address.village || '';
                            }
                        });
                    }, function(error) {
                        console.log("Geolocation error:", error.message);
                    }, { enableHighAccuracy: true, timeout: 5000 });
                }

                $(document).on('click', '.ss-external-btn', function(e) {
                    var $btn = $(this);
                    var productId = $btn.data('product-id');
                    var appName = $btn.data('app-name');

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'ss_mmp_track_click',
                            product_id: productId,
                            app_name: appName,
                            lat: userLat,
                            lng: userLng,
                            client_country: userCountry,
                            client_city: userCity
                        },
                        success: function(response) {
                            console.log('Click tracked');
                        }
                    });
                });
            });
            </script>
            <?php
        }
    });

    // 6. AJAX Click Tracking Handler
    add_action('wp_ajax_ss_mmp_track_click', 'ss_mmp_handle_click_tracking');
    add_action('wp_ajax_nopriv_ss_mmp_track_click', 'ss_mmp_handle_click_tracking');

    function ss_mmp_handle_click_tracking() {
        if (isset($_POST['product_id']) && isset($_POST['app_name'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'ss_mmp_clicks';
            $product_id = intval($_POST['product_id']);
            $app_name = sanitize_text_field($_POST['app_name']);
            
            // Round coordinates to 4 decimal places for better precision (approx 10m)
            $latitude = isset($_POST['lat']) && is_numeric($_POST['lat']) ? number_format(floatval($_POST['lat']), 4, '.', '') : '';
            $longitude = isset($_POST['lng']) && is_numeric($_POST['lng']) ? number_format(floatval($_POST['lng']), 4, '.', '') : '';
            $client_country = isset($_POST['client_country']) ? sanitize_text_field($_POST['client_country']) : '';
            $client_city = isset($_POST['client_city']) ? sanitize_text_field($_POST['client_city']) : '';

            $country_code = 'Unknown';
            $city_name = 'Unknown';
            
            // 1. Get User IP (Improved for VPN/Proxy)
            $ip_address = '';
            if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
                $ip_address = $_SERVER['HTTP_CLIENT_IP'];
            } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $ip_address = trim(explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0]);
            } elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
                $ip_address = $_SERVER['HTTP_X_REAL_IP'];
            } elseif ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
                $ip_address = $_SERVER['HTTP_CF_CONNECTING_IP'];
            } else {
                $ip_address = $_SERVER['REMOTE_ADDR'];
            }

            // 2. Try Geolocation (Enhanced with multiple backup APIs)
            $ip_country = 'Unknown';
            $ip_city = 'Unknown';
            
            // Step A: Try IP-API (Primary)
            $api_url = 'http://ip-api.com/json/' . $ip_address; 
            $response = wp_remote_get( $api_url, array( 'timeout' => 5 ) );
            if ( ! is_wp_error( $response ) ) {
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $data ) && isset($data['status']) && $data['status'] === 'success' ) {
                    $ip_country = !empty($data['countryCode']) ? strtoupper($data['countryCode']) : 'Unknown';
                    $ip_city = !empty($data['city']) ? $data['city'] : 'Unknown';
                    if (!empty($data['regionName'])) {
                        $ip_city .= ', ' . $data['regionName'];
                    }
                }
            }

            // Step B: Backup IP Geolocation (Cloudflare or alternative if IP-API fails)
            if ($ip_country === 'Unknown') {
                $backup_url = 'https://ipapi.co/' . $ip_address . '/json/';
                $backup_response = wp_remote_get( $backup_url, array( 'timeout' => 5 ) );
                if ( ! is_wp_error( $backup_response ) ) {
                    $data = json_decode( wp_remote_retrieve_body( $backup_response ), true );
                    if ( ! empty( $data ) && !isset($data['error']) ) {
                        $ip_country = !empty($data['country_code']) ? strtoupper($data['country_code']) : 'Unknown';
                        $ip_city = !empty($data['city']) ? $data['city'] : 'Unknown';
                        if (!empty($data['region'])) {
                            $ip_city .= ', ' . $data['region'];
                        }
                    }
                }
            }

            // Step C: Use Client-side detected location if server-side failed
            if ($ip_country === 'Unknown' && !empty($client_country)) {
                $ip_country = strtoupper($client_country);
                $ip_city = !empty($client_city) ? $client_city : 'Unknown';
            }

            // Step D: Use GPS if available, validate against IP to ensure fresh data
            if ( ! empty( $latitude ) && ! empty( $longitude ) ) {
                $geo_url = "https://nominatim.openstreetmap.org/reverse?lat={$latitude}&lon={$longitude}&format=json";
                $geo_response = wp_remote_get( $geo_url, array( 'timeout' => 5, 'user-agent' => 'SS-MultiMarketPlace-Plugin' ) );
                
                if ( ! is_wp_error( $geo_response ) ) {
                    $geo_data = json_decode( wp_remote_retrieve_body( $geo_response ), true );
                    if ( ! empty( $geo_data['address'] ) ) {
                        $gps_country = ! empty( $geo_data['address']['country_code'] ) ? strtoupper( $geo_data['address']['country_code'] ) : '';
                        $gps_city = ! empty( $geo_data['address']['city'] ) ? $geo_data['address']['city'] : ( ! empty( $geo_data['address']['town'] ) ? $geo_data['address']['town'] : ( ! empty( $geo_data['address']['village'] ) ? $geo_data['address']['village'] : '' ) );
                        if ( ! empty( $geo_data['address']['state'] ) ) {
                            $gps_city .= ', ' . $geo_data['address']['state'];
                        }

                        // Trust GPS only if it's not conflicting with IP-detected country (avoids browser cache issues)
                        if ($ip_country === 'Unknown' || $gps_country === $ip_country) {
                            $country_code = !empty($gps_country) ? $gps_country : $ip_country;
                            $city_name = !empty($gps_city) ? $gps_city : $ip_city;
                        } else {
                            $country_code = $ip_country;
                            $city_name = $ip_city;
                        }
                    }
                }
            }

            // Final fallback if GPS failed or wasn't used
            if ($country_code === 'Unknown') {
                $country_code = $ip_country;
                $city_name = $ip_city;
            }

            // Final Fallback to WooCommerce Geolocation if still unknown
            if ( $country_code === 'Unknown' && class_exists( 'WC_Geolocation' ) ) {
                $location = WC_Geolocation::geolocate_ip( $ip_address );
                if ( ! empty( $location['country'] ) ) {
                    $country_code = strtoupper($location['country']);
                }
            }

            // Final check to prevent empty strings
            $country_code = (empty($country_code) || $country_code === 'Unknown') ? 'Unknown' : $country_code;
            $city_name = (empty($city_name) || $city_name === 'Unknown') ? 'Unknown Location' : $city_name;

            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table_name (product_id, app_name, country_code, city_name, latitude, longitude, click_count) 
                 VALUES (%d, %s, %s, %s, %s, %s, 1) 
                 ON DUPLICATE KEY UPDATE click_count = click_count + 1",
                $product_id, $app_name, $country_code, $city_name, $latitude, $longitude
            ));
        }
        wp_die();
    }

    // 7. Admin Settings & Dashboard
    add_action('admin_init', 'ss_mmp_register_settings');
    function ss_mmp_register_settings() {
        register_setting('ss_mmp_settings_group', 'ss_mmp_btn_color');
        register_setting('ss_mmp_settings_group', 'ss_mmp_btn_text_color');
        register_setting('ss_mmp_settings_group', 'ss_mmp_btn_hover_color');
        register_setting('ss_mmp_settings_group', 'ss_mmp_btn_font_size');
        register_setting('ss_mmp_settings_group', 'ss_mmp_scrollbar_color');
        register_setting('ss_mmp_settings_group', 'ss_mmp_default_custom_icon');
        register_setting('ss_mmp_settings_group', 'ss_mmp_btn_prefix');
        register_setting('ss_mmp_settings_group', 'ss_mmp_app_styles');
        register_setting('ss_mmp_settings_group', 'ss_mmp_button_placement');

        // New Display Controls
        register_setting('ss_mmp_settings_group', 'ss_mmp_show_on_product');
        register_setting('ss_mmp_settings_group', 'ss_mmp_show_on_shop');
        register_setting('ss_mmp_settings_group', 'ss_mmp_hide_cart_btn');
        register_setting('ss_mmp_settings_group', 'ss_mmp_user_roles');

        // Handle CSV Export and Sample Download early to avoid "headers already sent"
        if (isset($_POST['ss_mmp_export'])) {
            ss_mmp_handle_export();
        }
        if (isset($_POST['ss_mmp_sample_csv'])) {
            ss_mmp_handle_sample_csv();
        }
    }

    add_action('admin_menu', function() {
        add_menu_page(
            'SS Multi Marketplace',
            'Multi Marketplace',
            'manage_options',
            'ss-mmp-dashboard',
            'ss_mmp_render_dashboard',
            'dashicons-chart-bar',
            56
        );

        add_submenu_page(
            'ss-mmp-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'ss-mmp-settings',
            'ss_mmp_render_settings_page'
        );

        // Premium features in free version - show as locked
        if (SS_MMP_IS_FREE) {
            add_submenu_page(
                'ss-mmp-dashboard',
                'Analytics (Pro)',
                '🔒 Analytics (Pro)',
                'manage_options',
                'ss-mmp-analytics-pro',
                'ss_mmp_render_pro_page'
            );

            add_submenu_page(
                'ss-mmp-dashboard',
                'Import/Export (Pro)',
                '🔒 Import/Export (Pro)',
                'manage_options',
                'ss-mmp-import-export-pro',
                'ss_mmp_render_pro_page'
            );

            // Free version gets actual documentation
            add_submenu_page(
                'ss-mmp-dashboard',
                'Documentation',
                'Documentation',
                'manage_options',
                'ss-mmp-docs-free',
                'ss_mmp_render_free_docs_page'
            );
        } else {
            add_submenu_page(
                'ss-mmp-dashboard',
                'Analytics',
                'Analytics',
                'manage_options',
                'ss-mmp-analytics',
                'ss_mmp_render_analytics_page'
            );

            add_submenu_page(
                'ss-mmp-dashboard',
                'Import/Export',
                'Import/Export',
                'manage_options',
                'ss-mmp-import-export',
                'ss_mmp_render_import_export_page'
            );

            add_submenu_page(
                'ss-mmp-dashboard',
                'Documentation',
                'Documentation',
                'manage_options',
                'ss-mmp-docs',
                'ss_mmp_render_docs_page'
            );
        }
    });

    // Free Version Documentation
    function ss_mmp_render_free_docs_page() {
        ?>
        <div class="wrap ss-mmp-docs-container">
            <h1 class="ss-mmp-docs-header">📚 SS MultiMarketPlace - Free Version Guide</h1>
            
            <div class="ss-mmp-docs-card">
                <h2>🚀 Welcome to SS MultiMarketPlace Free Version!</h2>
                <p>This plugin helps you replace the "Add to Cart" button with custom marketplace buttons like Amazon, Flipkart, etc.</p>
                
                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <h2>⚡ Quick Start Guide</h2>
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #28a745;">Step 1: Edit Your Product</h3>
                    <ol style="line-height: 1.8; font-size: 15px;">
                        <li>Go to <strong>Products → All Products</strong></li>
                        <li>Click <strong>Edit</strong> on any product</li>
                        <li>Scroll down and click the <strong>"Multi Marketplace"</strong> tab</li>
                    </ol>
                </div>

                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #007cba;">Step 2: Add Marketplace Buttons</h3>
                    <ol style="line-height: 1.8; font-size: 15px;">
                        <li>Enter <strong>App Name</strong> (Example: Amazon, Flipkart, Myntra)</li>
                        <li>Enter <strong>Product Link</strong> (The URL where customers can buy)</li>
                        <li>Click <strong>"Add New Button"</strong> to add more marketplace buttons</li>
                        <li>Click <strong>"Update Product"</strong> to save</li>
                    </ol>
                </div>

                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #ff6b35;">Step 3: Customize Settings</h3>
                    <ol style="line-height: 1.8; font-size: 15px;">
                        <li>Go to <strong>Multi Marketplace → Settings</strong></li>
                        <li>Set <strong>Button Text Prefix</strong> (Example: "Buy on")</li>
                        <li>Upload a <strong>Default Icon</strong> for all buttons</li>
                        <li>Save your settings</li>
                    </ol>
                </div>

                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <h2>🎯 Free Version Features</h2>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">
                    <div style="background: #e8f5e8; padding: 15px; border-radius: 6px; border-left: 4px solid #28a745;">
                        <h3 style="margin-top: 0; color: #28a745;">✅ What You Can Do</h3>
                        <ul style="line-height: 1.6; margin-left: 20px;">
                            <li>Add marketplace buttons to <strong>5 products</strong></li>
                            <li>Up to <strong>5 buttons per product</strong></li>
                            <li>Customize button text prefix</li>
                            <li>Upload default icon</li>
                            <li>Hide/show cart buttons</li>
                            <li>Basic click tracking</li>
                        </ul>
                    </div>
                    
                    <div style="background: #fff3cd; padding: 15px; border-radius: 6px; border-left: 4px solid #ffc107;">
                        <h3 style="margin-top: 0; color: #856404;">⚠️ Free Version Limits</h3>
                        <ul style="line-height: 1.6; margin-left: 20px;">
                            <li>Maximum <strong>5 products only</strong></li>
                            <li>No advanced analytics</li>
                            <li>No app-specific colors</li>
                            <li>No bulk import/export</li>
                            <li>Basic styling options only</li>
                        </ul>
                    </div>
                </div>

                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <h2>🛠️ Common Examples</h2>
                
                <div style="background: #f1f3f4; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0;">📱 Example 1: T-Shirt Product</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="background: #fff;">
                            <th style="padding: 10px; border: 1px solid #ddd; text-align: left;">App Name</th>
                            <th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Product Link</th>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd;">Amazon</td>
                            <td style="padding: 10px; border: 1px solid #ddd;">https://amazon.in/dp/your-product-id</td>
                        </tr>
                        <tr style="background: #f9f9f9;">
                            <td style="padding: 10px; border: 1px solid #ddd;">Flipkart</td>
                            <td style="padding: 10px; border: 1px solid #ddd;">https://flipkart.com/your-product-link</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd;">Myntra</td>
                            <td style="padding: 10px; border: 1px solid #ddd;">https://myntra.com/your-product-link</td>
                        </tr>
                    </table>
                    <p style="margin-top: 15px; color: #666;"><strong>Result:</strong> Customers will see "Buy on Amazon", "Buy on Flipkart", "Buy on Myntra" buttons</p>
                </div>

                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <h2>❓ Frequently Asked Questions</h2>
                
                <div style="margin: 20px 0;">
                    <h3 style="color: #333;">Q: How do I add buttons to more than 5 products?</h3>
                    <p style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 10px 0;">
                        <strong>A:</strong> The free version is limited to 5 products. To add buttons to unlimited products, you need to upgrade to the Pro version.
                    </p>
                </div>

                <div style="margin: 20px 0;">
                    <h3 style="color: #333;">Q: Can I customize button colors?</h3>
                    <p style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 10px 0;">
                        <strong>A:</strong> The free version has basic styling. Advanced color customization and app-specific styling are available in the Pro version.
                    </p>
                </div>

                <div style="margin: 20px 0;">
                    <h3 style="color: #333;">Q: Where will the buttons appear?</h3>
                    <p style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 10px 0;">
                        <strong>A:</strong> The marketplace buttons will appear on your product pages, either above or below the "Add to Cart" button. You can control this in Settings.
                    </p>
                </div>

                <div style="margin: 20px 0;">
                    <h3 style="color: #333;">Q: Do the buttons work on mobile?</h3>
                    <p style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 10px 0;">
                        <strong>A:</strong> Yes! The buttons are fully responsive and work perfectly on mobile devices.
                    </p>
                </div>

                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <div style="text-align: center; background: #e3f2fd; padding: 30px; border-radius: 10px; margin: 30px 0;">
                    <h3 style="margin-top: 0; color: #1976d2;">🎉 Need More Features?</h3>
                    <p style="margin-bottom: 20px; color: #424242;">Get unlimited products, advanced analytics, custom styling, and more!</p>
                    <a href="https://www.sharesoft.in/products/ss-multi-marketplace-pro-for-woocommerce-external-product-links-custom-buttons-click-analytics/" target="_blank" style="background: #1976d2; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;">
                        🚀 View Pro Version Features
                    </a>
                </div>
            </div>

            <p style="text-align: center; color: #646970; margin-top: 30px;">
                Version 1.0 FREE | Need Help? Contact <a href="http://www.sharesoft.in/" target="_blank">Sharesoft Technology</a>
            </p>
        </div>
        <?php
    }

    // Pro Version Documentation
    function ss_mmp_render_pro_page() {
        ?>
        <div class="wrap">
            <div style="text-align: center; padding: 50px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin: 0 -20px 30px -20px;">
                <h1 style="color: white; font-size: 2.5em; margin-bottom: 10px;">🚀 Upgrade to Pro Version</h1>
                <p style="font-size: 1.2em; opacity: 0.9;">Unlock all powerful features and remove limitations</p>
            </div>
            
            <div style="max-width: 1000px; margin: 0 auto;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 40px;">
                    <div style="background: #f9f9f9; padding: 25px; border-radius: 10px; border-left: 5px solid #ff6b35;">
                        <h3 style="color: #ff6b35; margin-top: 0;">📈 Advanced Analytics</h3>
                        <ul style="line-height: 1.8;">
                            <li>✅ Detailed click tracking with geolocation</li>
                            <li>✅ Top performing products analysis</li>
                            <li>✅ Marketplace performance by country</li>
                            <li>✅ Export analytics data to CSV</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 25px; border-radius: 10px; border-left: 5px solid #28a745;">
                        <h3 style="color: #28a745; margin-top: 0;">🎨 Custom Styling</h3>
                        <ul style="line-height: 1.8;">
                            <li>✅ App-specific colors & icons</li>
                            <li>✅ Custom button styles per marketplace</li>
                            <li>✅ Advanced color picker</li>
                            <li>✅ Upload custom icons for each app</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 25px; border-radius: 10px; border-left: 5px solid #6f42c1;">
                        <h3 style="color: #6f42c1; margin-top: 0;">📦 Unlimited Products</h3>
                        <ul style="line-height: 1.8;">
                            <li>✅ Add buttons to unlimited products</li>
                            <li>✅ No restriction on marketplace links</li>
                            <li>✅ Bulk import/export via CSV</li>
                            <li>✅ Advanced product management</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 25px; border-radius: 10px; border-left: 5px solid #dc3545;">
                        <h3 style="color: #dc3545; margin-top: 0;">⚡ Premium Support</h3>
                        <ul style="line-height: 1.8;">
                            <li>✅ Priority email support</li>
                            <li>✅ Regular updates & new features</li>
                            <li>✅ Complete documentation</li>
                            <li>✅ Custom development requests</li>
                        </ul>
                    </div>
                </div>
                
                <div style="text-align: center; background: #fff; padding: 40px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                    <h2 style="color: #333; margin-bottom: 20px;">Ready to Upgrade?</h2>
                    <p style="font-size: 1.1em; color: #666; margin-bottom: 25px;">Get instant access to all pro features</p>
                    
                    <div style="margin-bottom: 25px;">
                        <span style="font-size: 2em; color: #ff6b35; font-weight: bold;">$29</span>
                        <span style="color: #999;"> / one-time payment</span>
                    </div>
                    
                    <a href="https://www.sharesoft.in/products/ss-multi-marketplace-pro-for-woocommerce-external-product-links-custom-buttons-click-analytics/" target="_blank" style="background: linear-gradient(45deg, #ff6b35, #f7931e); color: white; padding: 15px 40px; text-decoration: none; border-radius: 25px; font-size: 1.1em; font-weight: bold; display: inline-block; margin-bottom: 15px;">
                        💳 Buy Pro Version Now
                    </a>
                    
                    <p style="color: #999; font-size: 0.9em;">30-day money-back guarantee • Lifetime updates</p>
                </div>
            </div>
        </div>
        <?php
    }

    function ss_mmp_render_import_export_page() {
        if (isset($_POST['ss_mmp_import'])) {
            if (!empty($_FILES['ss_mmp_import_file']['tmp_name'])) {
                $result = ss_mmp_handle_import($_FILES['ss_mmp_import_file']['tmp_name']);
                if (is_wp_error($result)) {
                    echo '<div class="error notice"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    echo '<div class="updated notice"><p>' . esc_html($result) . '</p></div>';
                }
            } else {
                echo '<div class="error notice"><p>Please select a CSV file to import.</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1>Import/Export Marketplace Links</h1>
            <p>Export your marketplace buttons to a CSV file or import them using Product SKUs.</p>

            <div class="ss-mmp-analytics-grid">
         

                <!-- Import Section -->
                <div class="postbox ss-mmp-postbox">
                    <div class="postbox-header ss-mmp-postbox-header"><h2>📥 Import Data</h2></div>
                    <div class="inside">
                        <p>Upload a CSV file to update marketplace links. The CSV must have <strong>sku</strong>, <strong>app_name</strong>, and <strong>product_link</strong> columns.</p>
                        
                        <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 20px;">
                            <h3 style="margin-top:0;">1. Download Sample</h3>
                            <p>Use this as a template for your data.</p>
                            <form method="post">
                                <input type="hidden" name="ss_mmp_sample_csv" value="1">
                                <button type="submit" class="button button-secondary">Download Sample CSV</button>
                            </form>
                        </div>

                        <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
                            <h3 style="margin-top:0;">2. Upload & Import</h3>
                            <form method="post" enctype="multipart/form-data">
                                <input type="file" name="ss_mmp_import_file" accept=".csv" style="margin-bottom: 10px; display: block;">
                                <input type="hidden" name="ss_mmp_import" value="1">
                                <?php submit_button('Upload and Import CSV', 'primary', 'ss_mmp_import_btn', false); ?>
                            </form>
                        </div>
                    </div>
                </div>

                       <!-- Export Section -->
                <div class="postbox ss-mmp-postbox">
                    <div class="postbox-header ss-mmp-postbox-header"><h2>📤 Export Data</h2></div>
                    <div class="inside">
                        <p>Download a CSV file containing all products with their marketplace links and SKUs.</p>
                        <form method="post">
                            <input type="hidden" name="ss_mmp_export" value="1">
                            <?php submit_button('Download Export CSV', 'primary', 'ss_mmp_export_btn'); ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    function ss_mmp_handle_export() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_ss_mmp_buttons',
                    'compare' => 'EXISTS',
                ],
            ],
        ];
        $products = get_posts($args);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ss-mmp-export-' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['sku', 'app_name', 'product_link']);

        foreach ($products as $product_post) {
            $product = wc_get_product($product_post->ID);
            $sku = $product->get_sku();
            $id = $product->get_id();
            
            // Use SKU if available, otherwise use ID (just the number)
            $identifier = !empty($sku) ? $sku : $id;

            $buttons = get_post_meta($id, '_ss_mmp_buttons', true);

            if (is_array($buttons) && !empty($buttons)) {
                foreach ($buttons as $btn) {
                    if (empty($btn['text']) || empty($btn['link'])) continue;
                    
                    fputcsv($output, [
                        $identifier,
                        $btn['text'],
                        $btn['link']
                    ]);
                }
            }
        }
        fclose($output);
        exit;
    }

    function ss_mmp_handle_sample_csv() {
        if (!current_user_can('manage_options')) return;

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ss-mmp-sample.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['sku', 'app_name', 'product_link']);
        fputcsv($output, ['PROD-001', 'Amazon', 'https://amazon.in/dp/example']);
        fputcsv($output, ['PROD-001', 'Flipkart', 'https://flipkart.com/example']);
        fputcsv($output, ['PROD-002', 'Ajio', 'https://ajio.com/example']);
        fclose($output);
        exit;
    }

    function ss_mmp_handle_import($file_path) {
        if (!current_user_can('manage_options')) return new WP_Error('denied', 'Unauthorized access.');

        if (($handle = fopen($file_path, "r")) !== FALSE) {
            $header = fgetcsv($handle, 1000, ",");
            
            // Validate header
            $sku_idx = array_search('sku', $header);
            $text_idx = array_search('app_name', $header);
            $link_idx = array_search('product_link', $header);

            if ($sku_idx === FALSE || $text_idx === FALSE || $link_idx === FALSE) {
                return new WP_Error('invalid_csv', 'CSV must contain sku, app_name, and product_link columns.');
            }

            $imported_data = [];
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $sku = sanitize_text_field($data[$sku_idx]);
                $text = sanitize_text_field($data[$text_idx]);
                $link = esc_url_raw($data[$link_idx]);

                if (empty($sku) || empty($text) || empty($link)) continue;

                if (!isset($imported_data[$sku])) {
                    $imported_data[$sku] = [];
                }
                $imported_data[$sku][] = ['text' => $text, 'link' => $link];
            }
            fclose($handle);

            $count = 0;
            foreach ($imported_data as $sku => $new_buttons) {
                $product_id = wc_get_product_id_by_sku($sku);

                // If SKU not found, check if it's a valid Product ID
                if (!$product_id && is_numeric($sku)) {
                    $potential_product = wc_get_product(intval($sku));
                    if ($potential_product) {
                        $product_id = $potential_product->get_id();
                    }
                }

                if ($product_id) {
                    $existing_buttons = get_post_meta($product_id, '_ss_mmp_buttons', true);
                    if (!is_array($existing_buttons)) {
                        $existing_buttons = [];
                    }

                    // Append new buttons, avoiding duplicates (Only replace/skip if BOTH app name and link are same)
                    foreach ($new_buttons as $new_btn) {
                        $is_duplicate = false;
                        $new_text_clean = strtolower(trim($new_btn['text']));
                        $new_link_clean = trim($new_btn['link']);

                        foreach ($existing_buttons as $existing_btn) {
                            $existing_text_clean = strtolower(trim($existing_btn['text']));
                            $existing_link_clean = trim($existing_btn['link']);

                            if ($existing_text_clean === $new_text_clean && $existing_link_clean === $new_link_clean) {
                                $is_duplicate = true;
                                break;
                            }
                        }
                        if (!$is_duplicate) {
                            $existing_buttons[] = $new_btn;
                        }
                    }

                    update_post_meta($product_id, '_ss_mmp_buttons', $existing_buttons);
                    $count++;
                }
            }

            return sprintf('Successfully updated %d products.', $count);
        }
        return new WP_Error('read_error', 'Could not read the uploaded file.');
    }

    function ss_mmp_render_docs_page() {
        ?>
        <div class="wrap ss-mmp-docs-container">
            <h1 class="ss-mmp-docs-header">SS MultiMarketPlace Pro - Documentation</h1>
            
            <div class="ss-mmp-docs-card">
                <h2>🚀 Introduction</h2>
                <p>This plugin allows you to replace the standard WooCommerce "Add to Cart" button with multiple external marketplace buttons like Amazon, Flipkart, Ajio, etc. It includes advanced click tracking and geolocation features.</p>
                
                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <h2>🛠️ How to Use?</h2>
                <div style="display: flex; gap: 20px; align-items: flex-start; margin-bottom: 20px;">
                    <div style="flex: 1;">
                        <ol style="line-height: 1.8;">
                            <li><strong>Product Editor:</strong> Open any product and scroll down to the <strong>Multi Marketplace</strong> tab.</li>
                            <li><strong>Add Links:</strong> Enter the App/Marketplace name (e.g., Flipkart) and the product URL. Save the product.</li>
                            <li><strong>Frontend:</strong> Custom buttons will now appear on your product page instead of the cart button.</li>
                        </ol>
                    </div>
                </div>

                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <h2>⚙️ Settings & Customization</h2>
                <p>Navigate to <strong>Multi Marketplace > Settings</strong> to customize the appearance:</p>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div style="background: #fdfdfd; padding: 15px; border: 1px solid #eee; border-radius: 6px;">
                        <h3>🎨 Visual Styles</h3>
                        <ul style="list-style-type: square; margin-left: 20px; line-height: 1.6;">
                            <li><strong>Colors:</strong> Change default background, text, and hover colors.</li>
                            <li><strong>Color Picker:</strong> Use the advanced color picker or enter Hex codes directly.</li>
                            <li><strong>Font Size:</strong> Adjust the button text size in pixels.</li>
                            <li><strong>Scrollbar:</strong> Customize the color of the scrollbar for the button container.</li>
                        </ul>
                    </div>
                    <div style="background: #fdfdfd; padding: 15px; border: 1px solid #eee; border-radius: 6px;">
                        <h3>🖼️ Icons & Placement</h3>
                        <ul style="list-style-type: square; margin-left: 20px; line-height: 1.6;">
                            <li><strong>Default Icon:</strong> Upload a global icon for all buttons.</li>
                            <li><strong>App Specific Icons:</strong> Upload unique icons for specific apps (e.g. Amazon logo).</li>
                            <li><strong>Flexible Placement:</strong> Position buttons <strong>Above</strong> or <strong>Below</strong> the Add to Cart button.</li>
                        </ul>
                    </div>
                </div>

                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <h2>📥 Import/Export</h2>
                <p>Manage your data efficiently using CSV files:</p>
                <ul style="line-height: 1.6;">
                    <li><strong>Bulk Export:</strong> Download all your marketplace links in one CSV file.</li>
                    <li><strong>Bulk Import:</strong> Update or add links for multiple products using SKUs.</li>
                    <li><strong>Sample CSV:</strong> Download a template to ensure your data format is correct.</li>
                </ul>

                <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                <h2>📊 Analytics & Dashboard</h2>
                <p>Track your performance with real-time data:</p>
                <ul style="line-height: 1.6;">
                    <li><strong>Analytics:</strong> View top 10 most clicked products and top marketplaces grouped by country.</li>
                    <li><strong>Dashboard:</strong> Detailed logs for every click, including Country, City.</li>
                    <li><strong>Location Tracking:</strong> Uses a hybrid approach (IP + GPS) to detect precise locations.</li>
                </ul>

                <div class="ss-mmp-docs-note">
                    <strong>💡 Pro Tip:</strong> Use high-quality PNG/SVG icons (100x100px recommended) for the best visual experience on the frontend buttons.
                </div>
            </div>

            <p style="text-align: center; color: #646970; margin-top: 30px;">
                Version 1.0 PRO | Developed by <a href="http://www.sharesoft.in/" target="_blank"">Sharesoft Technology</a>
            </p>
        </div>
        <?php
    }

    function ss_mmp_render_analytics_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_mmp_clicks';

        // Query for top 10 clicked products
        $top_products = $wpdb->get_results("
            SELECT product_id, SUM(click_count) as total_clicks 
            FROM $table_name 
            GROUP BY product_id 
            ORDER BY total_clicks DESC 
            LIMIT 10
        ");

        // Query for top 5 clicked apps/marketplaces grouped by country
        $top_apps = $wpdb->get_results("
            SELECT app_name, country_code, SUM(click_count) as total_clicks 
            FROM $table_name 
            GROUP BY app_name, country_code 
            ORDER BY total_clicks DESC 
            LIMIT 10
        ");
        $all_countries = class_exists('WooCommerce') ? WC()->countries->get_countries() : [];
        ?>
        <div class="wrap">
            <h1>SS Multi Marketplace Analytics</h1>
            <p>Track your best performing products and marketplaces by region.</p>

            <div class="ss-mmp-analytics-grid">
                <!-- Top Products Table -->
                <div class="postbox ss-mmp-postbox">
                    <div class="postbox-header ss-mmp-postbox-header"><h2 class="hndle">🔥 Top 10 Clicked Products</h2></div>
                    <div class="inside">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th style="width: 100px; text-align: center;">Total Clicks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_products)): ?>
                                    <tr><td colspan="2">No data available yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($top_products as $row): 
                                        $product = get_post($row->product_id);
                                        $name = $product ? $product->post_title : 'Deleted Product (ID: '.$row->product_id.')';
                                    ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($name); ?></strong></td>
                                            <td style="text-align: center;">
                                                <span class="ss-mmp-badge-blue">
                                                    <?php echo esc_html($row->total_clicks); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Apps/Marketplaces Table -->
                <div class="postbox ss-mmp-postbox">
                    <div class="postbox-header ss-mmp-postbox-header"><h2 class="hndle">🌍 Top Marketplaces by Country</h2></div>
                    <div class="inside">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>App / Marketplace</th>
                                    <th style="width: 120px; text-align: center;">Country</th>
                                    <th style="width: 80px; text-align: center;">Clicks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_apps)): ?>
                                    <tr><td colspan="3">No data available yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($top_apps as $row): 
                                        $country_name = isset($all_countries[$row->country_code]) ? $all_countries[$row->country_code] : $row->country_code;
                                        $flag = ($row->country_code === 'IN') ? '🇮🇳' : (($row->country_code === 'US') ? '🇺🇸' : '🌐');
                                    ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($row->app_name); ?></strong></td>
                                            <td style="text-align: center;">
                                                <?php echo $flag . ' ' . esc_html($country_name); ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="ss-mmp-total-badge" style="padding: 2px 8px; font-size: 12px;">
                                                    <?php echo esc_html($row->total_clicks); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    function ss_mmp_render_settings_page() {
        ?>
        <div class="wrap">
            <h1>SS Multi Marketplace Settings</h1>
            
            <?php if (SS_MMP_IS_FREE): ?>
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
                    <strong>🎯 Free Version Limitations:</strong> 
                    You can add marketplace buttons to maximum <strong><?php echo SS_MMP_MAX_PRODUCTS; ?> products</strong>. 
                    Advanced styling features are available in Pro version.
                    <a href="?page=ss-mmp-analytics-pro" style="color: #ff6b35; text-decoration: none; font-weight: bold;"> Upgrade to Pro →</a>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('ss_mmp_settings_group');
                do_settings_sections('ss_mmp_settings_group');
                ?>
                
                <?php if (SS_MMP_IS_FREE): ?>
                    <!-- Free Version: Limited Settings -->
                    <h2>Basic Button Settings</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Button Text Prefix</th>
                            <td><input type="text" name="ss_mmp_btn_prefix" value="<?php echo esc_attr(get_option('ss_mmp_btn_prefix', 'Buy on')); ?>" placeholder="e.g. Buy on" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Default Custom Icon (Image)</th>
                            <td class="custon_icon">
                                <?php $default_icon = get_option('ss_mmp_default_custom_icon', plugin_dir_url(__FILE__) . 'assets/image/online-shopping.png'); ?>
                                <div class="ss-mmp-icon-preview" style="margin-bottom: 5px;">
                                    <?php if ($default_icon): ?>
                                        <img src="<?php echo esc_url($default_icon); ?>" style="max-width: 32px; max-height: 32px; display: block;">
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="ss_mmp_default_custom_icon" class="ss-mmp-custom-icon-url" value="<?php echo esc_attr($default_icon); ?>">
                                <button type="button" class="button ss-mmp-upload-icon">Upload</button>
                                <button type="button" class="button ss-mmp-remove-icon" <?php echo empty($default_icon) ? 'style="display:none;"' : ''; ?>>×</button>
                                <p class="description">Max size allowed: <strong>100x100px</strong>.</p>
                           </td>
                        </tr>
                    </table>

                    <!-- Pro Features Locked -->
                    <hr>
                    <div style="position: relative; opacity: 0.6;">
                        <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.8); z-index: 10; display: flex; align-items: center; justify-content: center; border-radius: 5px;">
                            <div style="background: #ff6b35; color: white; padding: 15px 25px; border-radius: 5px; text-align: center;">
                                <strong>🔒 PRO FEATURE</strong><br>
                                <a href="?page=ss-mmp-analytics-pro" style="color: white; text-decoration: underline;">Upgrade to unlock advanced styling</a>
                            </div>
                        </div>
                        <h2>App Specific Styles (Custom Colors & Icons) - PRO ONLY</h2>
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row">Button Font Size (px)</th>
                                <td><input type="number" disabled value="14" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Default Button Background</th>
                                <td><input type="text" disabled value="#a46497" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Default Button Text Color</th>
                                <td><input type="text" disabled value="#ffffff" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Default Button Hover Color</th>
                                <td><input type="text" disabled value="#8a4d7a" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Scrollbar Thumb Color</th>
                                <td><input type="text" disabled value="#ff9900" /></td>
                            </tr>
                        </table>
                        
                        <p class="description">Custom colors and styles for individual marketplace apps (Amazon, Flipkart, etc.)</p>
                        <table class="widefat fixed striped" style="margin-bottom: 20px;">
                            <thead>
                                <tr>
                                    <th>App Name</th>
                                    <th>Background Color</th>
                                    <th>Text Color</th>
                                    <th>Hover Color</th>
                                    <th>Custom Icon</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><input type="text" disabled value="Amazon" style="width:100%;"></td>
                                    <td><input type="text" disabled value="#ff9900" style="width:100%;"></td>
                                    <td><input type="text" disabled value="#ffffff" style="width:100%;"></td>
                                    <td><input type="text" disabled value="#e68900" style="width:100%;"></td>
                                    <td><button type="button" disabled class="button">Upload Icon</button></td>
                                    <td><button type="button" disabled class="button">Remove</button></td>
                                </tr>
                                <tr>
                                    <td><input type="text" disabled value="Flipkart" style="width:100%;"></td>
                                    <td><input type="text" disabled value="#2874f0" style="width:100%;"></td>
                                    <td><input type="text" disabled value="#ffffff" style="width:100%;"></td>
                                    <td><input type="text" disabled value="#1a5cb8" style="width:100%;"></td>
                                    <td><button type="button" disabled class="button">Upload Icon</button></td>
                                    <td><button type="button" disabled class="button">Remove</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>
                    <!-- Pro Version: Full Settings -->
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Button Text Prefix</th>
                            <td><input type="text" name="ss_mmp_btn_prefix" value="<?php echo esc_attr(get_option('ss_mmp_btn_prefix', 'Buy on')); ?>" placeholder="e.g. Buy on" /></td>
                        </tr>
                          <tr valign="top">
                            <th scope="row">Button Font Size (px)</th>
                            <td><input type="number" name="ss_mmp_btn_font_size" value="<?php echo esc_attr(get_option('ss_mmp_btn_font_size', '14')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Default Button Background</th>
                            <td><input type="text" name="ss_mmp_btn_color" class="ss-mmp-color-picker" value="<?php echo esc_attr(get_option('ss_mmp_btn_color', '#a46497')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Default Button Text Color</th>
                            <td><input type="text" name="ss_mmp_btn_text_color" class="ss-mmp-color-picker" value="<?php echo esc_attr(get_option('ss_mmp_btn_text_color', '#ffffff')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Default Button Hover Color</th>
                            <td><input type="text" name="ss_mmp_btn_hover_color" class="ss-mmp-color-picker" value="<?php echo esc_attr(get_option('ss_mmp_btn_hover_color', '#8a4d7a')); ?>" /></td>
                        </tr>
                      
                        <tr valign="top">
                            <th scope="row">Scrollbar Thumb Color</th>
                            <td><input type="text" name="ss_mmp_scrollbar_color" class="ss-mmp-color-picker" value="<?php echo esc_attr(get_option('ss_mmp_scrollbar_color', '#ff9900')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Default Custom Icon (Image)</th>
                            <td class="custon_icon">
                                <?php $default_icon = get_option('ss_mmp_default_custom_icon', plugin_dir_url(__FILE__) . 'assets/image/online-shopping.png'); ?>
                                <div class="ss-mmp-icon-preview" style="margin-bottom: 5px;">
                                    <?php if ($default_icon): ?>
                                        <img src="<?php echo esc_url($default_icon); ?>" style="max-width: 32px; max-height: 32px; display: block;">
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="ss_mmp_default_custom_icon" class="ss-mmp-custom-icon-url" value="<?php echo esc_attr($default_icon); ?>">
                                <button type="button" class="button ss-mmp-upload-icon">Upload</button>
                                <button type="button" class="button ss-mmp-remove-icon" <?php echo empty($default_icon) ? 'style="display:none;"' : ''; ?>>×</button>
                                <p class="description">Max size allowed: <strong>100x100px</strong>.</p>
                           </td>
                         
                        </tr>
                    </table>

                    <hr>
                    <h2>App Specific Styles (Custom Colors & Icons)</h2>
                    <p class="description">Add custom styles for specific marketplace apps (e.g. Amazon, Flipkart). If an app is not listed here, it will use the default settings above.</p>
                    
                    <table class="widefat fixed striped" style="margin-bottom: 20px;">
                        <thead>
                            <tr>
                                <th>App Name</th>
                                <th>Background Color</th>
                                <th>Text Color</th>
                                <th>Hover Color</th>
                                <th>Custom Icon (Image)</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="ss-mmp-app-styles-body">
                            <?php 
                            $app_styles = get_option('ss_mmp_app_styles', []);
                            $all_apps = ss_mmp_get_all_unique_apps();
                            
                            if (!empty($app_styles) && is_array($app_styles)): 
                                foreach ($app_styles as $index => $style): 
                                    $custom_icon = isset($style['custom_icon']) ? $style['custom_icon'] : '';
                                    ?>
                                    <tr>
                                        <td>
                                            <select name="ss_mmp_app_styles[<?php echo $index; ?>][app_name]" style="width:100%;">
                                                <option value="">Select App</option>
                                                <?php foreach ($all_apps as $app_name): ?>
                                                    <option value="<?php echo esc_attr($app_name); ?>" <?php selected($style['app_name'], $app_name); ?>><?php echo esc_html($app_name); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="text" name="ss_mmp_app_styles[<?php echo $index; ?>][bg_color]" class="ss-mmp-color-picker" value="<?php echo esc_attr($style['bg_color']); ?>"></td>
                                        <td><input type="text" name="ss_mmp_app_styles[<?php echo $index; ?>][text_color]" class="ss-mmp-color-picker" value="<?php echo esc_attr($style['text_color']); ?>"></td>
                                        <td><input type="text" name="ss_mmp_app_styles[<?php echo $index; ?>][hover_color]" class="ss-mmp-color-picker" value="<?php echo esc_attr($style['hover_color']); ?>"></td>
                                        <td class="custon_icon">
                                            <div class="ss-mmp-icon-preview" style="margin-bottom: 5px;">
                                                <?php if ($custom_icon): ?>
                                                    <img src="<?php echo esc_url($custom_icon); ?>" style="max-width: 32px; max-height: 32px; display: block;">
                                                <?php endif; ?>
                                            </div>
                                            <input type="hidden" name="ss_mmp_app_styles[<?php echo $index; ?>][custom_icon]" class="ss-mmp-custom-icon-url" value="<?php echo esc_attr($custom_icon); ?>">
                                            <button type="button" class="button ss-mmp-upload-icon">Upload</button>
                                            <button type="button" class="button ss-mmp-remove-icon" <?php echo empty($custom_icon) ? 'style="display:none;"' : ''; ?>>×</button>
                                        </td>
                                        <td><button type="button" class="button ss-mmp-remove-style">Remove</button></td>
                                    </tr>
                                <?php endforeach; 
                            endif; ?>
                        </tbody>
                    </table>
                    <p><button type="button" id="ss-mmp-add-style" class="button">Add New App Style</button></p>
                <?php endif; ?>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        <?php if (!SS_MMP_IS_FREE): // Only load JS for pro version ?>
                        var allApps = <?php echo json_encode(ss_mmp_get_all_unique_apps()); ?>;

                        $('#ss-mmp-add-style').on('click', function() {
                            var index = $('#ss-mmp-app-styles-body tr').length;
                            var appOptions = '<option value="">Select App</option>';
                            $.each(allApps, function(i, app) {
                                appOptions += `<option value="${app}">${app}</option>`;
                            });

                            var row = `<tr>
                                <td>
                                    <select name="ss_mmp_app_styles[${index}][app_name]" style="width:100%;">
                                        ${appOptions}
                                    </select>
                                </td>
                                <td><input type="text" name="ss_mmp_app_styles[${index}][bg_color]" class="ss-mmp-color-picker" value="#a46497"></td>
                                <td><input type="text" name="ss_mmp_app_styles[${index}][text_color]" class="ss-mmp-color-picker" value="#ffffff"></td>
                                <td><input type="text" name="ss_mmp_app_styles[${index}][hover_color]" class="ss-mmp-color-picker" value="#8a4d7a"></td>
                                <td class="custon_icon">
                                    <div class="ss-mmp-icon-preview" style="margin-bottom: 5px;"></div>
                                    <input type="hidden" name="ss_mmp_app_styles[${index}][custom_icon]" class="ss-mmp-custom-icon-url" value="">
                                    <button type="button" class="button ss-mmp-upload-icon">Upload</button>
                                    <button type="button" class="button ss-mmp-remove-icon" style="display:none;">×</button>
                                    <p class="description" style="margin-top:5px; font-size:11px;">Max: 100x100px</p>
                                </td>
                                <td><button type="button" class="button ss-mmp-remove-style">Remove</button></td>
                            </tr>`;
                            var $row = $(row);
                            $('#ss-mmp-app-styles-body').append($row);
                            $row.find('.ss-mmp-color-picker').wpColorPicker();
                        });

                        $(document).on('click', '.ss-mmp-remove-style', function() {
                            $(this).closest('tr').remove();
                        });
                        
                        // Initialize Color Picker for existing fields
                        $('.ss-mmp-color-picker').wpColorPicker();
                        <?php endif; ?>

                        // Media Uploader Logic (Available in both free and pro)
                        $(document).on('click', '.ss-mmp-upload-icon', function(e) {
                            e.preventDefault();
                            var button = $(this);
                            var custom_uploader = wp.media({
                                title: 'Select Icon',
                                button: { text: 'Use this icon' },
                                multiple: false
                            }).on('select', function() {
                                var attachment = custom_uploader.state().get('selection').first().toJSON();
                                
                                // Dimension Check (Max 100px)
                                if (attachment.width > 100 || attachment.height > 100) {
                                    alert('Error: Image size is ' + attachment.width + 'x' + attachment.height + 'px. Maximum allowed size is 100x100px.');
                                    return;
                                }

                                button.siblings('.ss-mmp-custom-icon-url').val(attachment.url);
                                button.siblings('.ss-mmp-icon-preview').html('<img src="' + attachment.url + '" style="max-width: 32px; max-height: 32px; display: block;">');
                                button.siblings('.ss-mmp-remove-icon').show();
                            }).open();
                        });

                        $(document).on('click', '.ss-mmp-remove-icon', function() {
                            $(this).siblings('.ss-mmp-custom-icon-url').val('');
                            $(this).siblings('.ss-mmp-icon-preview').empty();
                            $(this).hide();
                        });
                    });
                </script>

                <hr>
                <h2>Button Display Controls</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Show on Product Pages</th>
                        <td>
                            <input type="checkbox" name="ss_mmp_show_on_product" value="1" <?php checked(1, get_option('ss_mmp_show_on_product', 1)); ?> />
                            <p class="description">Display external buttons on individual product pages.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Show on Shop Pages</th>
                        <td>
                            <input type="checkbox" name="ss_mmp_show_on_shop" value="1" <?php checked(1, get_option('ss_mmp_show_on_shop', 0)); ?> />
                            <p class="description">Add external buttons to shop listing and archive pages.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Hide Add-to-Cart Button</th>
                        <td>
                            <input type="checkbox" name="ss_mmp_hide_cart_btn" value="1" <?php checked(1, get_option('ss_mmp_hide_cart_btn', 0)); ?> />
                            <p class="description">Option to hide the default WooCommerce Add to Cart button.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Button Placement</th>
                        <td>
                            <?php $placement = get_option('ss_mmp_button_placement', 'below'); ?>
                            <select name="ss_mmp_button_placement">
                                <option value="above" <?php selected($placement, 'above'); ?>>Above Add to Cart</option>
                                <option value="below" <?php selected($placement, 'below'); ?>>Below Add to Cart</option>
                            </select>
                            <p class="description">Choose where the marketplace buttons should appear relative to the "Add to Cart" button.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">User Role Visibility</th>
                        <td>
                            <?php
                            $selected_roles = get_option('ss_mmp_user_roles', []);
                            if (!is_array($selected_roles)) $selected_roles = [];
                            
                            $wp_roles = wp_roles()->get_names();
                            foreach ($wp_roles as $role_value => $role_name): ?>
                                <label style="margin-right: 15px;">
                                    <input type="checkbox" name="ss_mmp_user_roles[]" value="<?php echo esc_attr($role_value); ?>" <?php checked(in_array($role_value, $selected_roles)); ?> />
                                    <?php echo esc_html($role_name); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Leave all unchecked to show to everyone. Otherwise, select specific roles.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    function ss_mmp_render_dashboard() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_mmp_clicks';
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">SS Multi Marketplace - Dashboard</h1>
            
            <?php if (SS_MMP_IS_FREE): ?>
                <!-- Free Version Dashboard -->
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; margin: 20px 0; border-radius: 10px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h2 style="color: white; margin: 0 0 10px 0;">📊 Free Version Stats</h2>
                            <p style="margin: 0; opacity: 0.9;">Products with marketplace buttons: <strong><?php echo ss_mmp_count_products_with_buttons(); ?>/<?php echo SS_MMP_MAX_PRODUCTS; ?></strong></p>
                        </div>
                        <a href="?page=ss-mmp-analytics-pro" style="background: rgba(255,255,255,0.2); color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px; border: 2px solid rgba(255,255,255,0.3);">
                            🚀 Upgrade for Full Analytics
                        </a>
                    </div>
                </div>
                
                <!-- Basic Product List -->
                <div class="postbox">
                    <div class="postbox-header"><h2>📦 Your Products with Marketplace Buttons</h2></div>
                    <div class="inside">
                        <?php
                        $products_with_buttons = $wpdb->get_results("
                            SELECT post_id 
                            FROM {$wpdb->postmeta} 
                            WHERE meta_key = '_ss_mmp_buttons' 
                            AND meta_value != '' 
                            AND meta_value != 'a:0:{}'
                            LIMIT " . SS_MMP_MAX_PRODUCTS
                        );
                        
                        if (empty($products_with_buttons)): ?>
                            <p>No products configured yet. <a href="<?php echo admin_url('edit.php?post_type=product'); ?>">Add marketplace buttons to your products</a> to get started.</p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th>Marketplace Buttons</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products_with_buttons as $row): 
                                        $product = get_post($row->post_id);
                                        $buttons = get_post_meta($row->post_id, '_ss_mmp_buttons', true);
                                        if ($product && is_array($buttons)):
                                    ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($product->post_title); ?></strong></td>
                                            <td>
                                                <?php foreach ($buttons as $btn): ?>
                                                    <span style="background: #f0f0f1; padding: 3px 8px; margin-right: 5px; border-radius: 3px; font-size: 12px;">
                                                        <?php echo esc_html($btn['text']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <a href="<?php echo get_edit_post_link($row->post_id); ?>" class="button button-small">Edit</a>
                                                <a href="<?php echo get_permalink($row->post_id); ?>" class="button button-small" target="_blank">View</a>
                                            </td>
                                        </tr>
                                    <?php endif; endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        
                        <div style="margin-top: 20px; text-align: center; padding: 20px; background: #f9f9f9; border-radius: 5px;">
                            <p><strong>Want detailed analytics, unlimited products, and advanced features?</strong></p>
                            <a href="?page=ss-mmp-analytics-pro" class="button button-primary">🚀 Upgrade to Pro Version</a>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Pro Version Dashboard - Keep existing functionality -->
        
        // 1. Handle Delete Actions
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['product_id'])) {
            check_admin_referer('ss_mmp_delete_clicks');
            $wpdb->delete($table_name, ['product_id' => intval($_GET['product_id'])]);
            echo '<div class="updated notice is-dismissible"><p>Product click stats deleted.</p></div>';
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete_all') {
            check_admin_referer('ss_mmp_delete_all');
            $wpdb->query("TRUNCATE TABLE $table_name");
            echo '<div class="updated notice is-dismissible"><p>All click stats cleared.</p></div>';
        }

        // 2. Search & Pagination Logic
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $per_page = 10;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Build Query
        $where_clause = "";
        if (!empty($search)) {
            $product_ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_type = 'product'", '%' . $wpdb->esc_like($search) . '%'));
            if (!empty($product_ids)) {
                $ids_str = implode(',', array_map('intval', $product_ids));
                $where_clause = " WHERE product_id IN ($ids_str) OR app_name LIKE '%" . $wpdb->esc_like($search) . "%'";
            } else {
                $where_clause = " WHERE app_name LIKE '%" . $wpdb->esc_like($search) . "%'";
            }
        }

        // Get total count for pagination
        $total_items = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM $table_name $where_clause");
        $total_pages = ceil($total_items / $per_page);

        // Final Results Query - Grouped by location AND coordinates to ensure clean separate rows
        $results = $wpdb->get_results("
            SELECT product_id, app_name, country_code, city_name, latitude, longitude, SUM(click_count) as total_click_count 
            FROM $table_name $where_clause 
            GROUP BY product_id, app_name, country_code, city_name, latitude, longitude 
            ORDER BY product_id DESC 
            LIMIT $per_page OFFSET $offset
        ");
        $all_countries = class_exists('WooCommerce') ? WC()->countries->get_countries() : [];

        // Grouping logic (One postbox per product, rows inside for each app + location)
        $grouped_data = [];
        foreach ($results as $row) {
            $pid = $row->product_id;
            if (!isset($grouped_data[$pid])) {
                $product = get_post($pid);
                $grouped_data[$pid] = [
                    'name' => $product ? $product->post_title : 'Deleted Product (ID: '.$pid.')',
                    'edit_link' => get_edit_post_link($pid),
                    'view_link' => get_permalink($pid),
                    'stats' => [],
                    'total_product_clicks' => 0
                ];
            }

            $country_name = isset($all_countries[$row->country_code]) ? $all_countries[$row->country_code] : $row->country_code;
            $icon = ($row->country_code === 'IN') ? '🇮🇳' : (($row->country_code === 'US') ? '🇺🇸' : '🌐');
            
            $grouped_data[$pid]['stats'][] = [
                'app' => $row->app_name,
                'location' => $icon . ' ' . $country_name . ' (' . $row->city_name . ')',
                'count' => $row->total_click_count
            ];
            
            $grouped_data[$pid]['total_product_clicks'] += $row->total_click_count;
        }
        ?>
            <h1 class="wp-heading-inline">SS Multi Marketplace Pro - Stats</h1>
            
            <div class="ss-mmp-dashboard-wrap ss-mmp-flex-between">
                <!-- Search Form -->
                <form method="get" style="display: flex; gap: 5px;">
                    <input type="hidden" name="page" value="ss-mmp-dashboard">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search product or app...">
                    <button type="submit" class="button">Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="?page=ss-mmp-dashboard" class="button">Clear</a>
                    <?php endif; ?>
                </form>

                <!-- Bulk Actions -->
                <div>
                    <a href="<?php echo wp_nonce_url('?page=ss-mmp-dashboard&action=delete_all', 'ss_mmp_delete_all'); ?>" 
                       class="button button-link-delete" 
                       onclick="return confirm('Are you sure you want to delete ALL statistics?');" 
                       style="color: #d63638; text-decoration: none;">
                       Clear All Stats
                    </a>
                </div>
            </div>

            <?php if (empty($grouped_data)): ?>
                <div class="notice notice-info"><p>No results found.</p></div>
            <?php else: ?>
                <?php foreach ($grouped_data as $pid => $data): ?>
                    <div class="postbox ss-mmp-postbox">
                        <div class="postbox-header ss-mmp-postbox-header">
                            <h2>
                                📦 <strong><?php echo esc_html($data['name']); ?></strong>
                                <span style="margin-left: 10px; font-weight: normal; font-size: 13px;">
                                    <a href="<?php echo esc_url($data['edit_link']); ?>" target="_blank">Edit</a> | 
                                    <a href="<?php echo esc_url($data['view_link']); ?>" target="_blank">View</a> | 
                                    <a href="<?php echo wp_nonce_url("?page=ss-mmp-dashboard&action=delete&product_id=$pid", 'ss_mmp_delete_clicks'); ?>" 
                                       onclick="return confirm('Delete stats for this product?');" 
                                       style="color: #d63638;">Delete Stats</a>
                                </span>
                            </h2>
                            <span class="ss-mmp-total-badge">
                                Total Clicks: <?php echo esc_html($data['total_product_clicks']); ?>
                            </span>
                        </div>
                        <div class="inside" style="padding: 0; margin: 0;">
                            <table class="wp-list-table widefat fixed striped" style="border: none;">
                                <thead>
                                    <tr>
                                        <th style="width: 30%; padding-left: 20px;">App / Marketplace</th>
                                        <th style="width: 50%;">Location (Country & City)</th>
                                        <th style="width: 20%; text-align: center;">Clicks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['stats'] as $stat): ?>
                                        <tr>
                                            <td style="padding-left: 20px;"><strong><?php echo esc_html($stat['app']); ?></strong></td>
                                            <td><?php echo esc_html($stat['location']); ?></td>
                                            <td style="text-align: center;">
                                                <span class="ss-mmp-badge-blue">
                                                    <?php echo esc_html($stat['count']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php echo $total_items; ?> products</span>
                            <?php
                            echo paginate_links([
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $current_page,
                            ]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        <?php endif; ?>
        </div>
        <?php
    }
// End of plugin logic

?>
