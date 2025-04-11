<?php
/**
 * Plugin Name:       OctaHexa Product Reservation Lock
 * Plugin URI:        https://octahexa.com/plugins/octahexa-product-reservation-lock
 * Description:       Locks WooCommerce products during checkout to prevent duplicate sales of unique items.
 * Version:           1.0.0
 * Author:            OctaHexa
 * Author URI:        https://octahexa.com
 * Text Domain:       octahexa-product-reservation
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.6
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: https://github.com/WPSpeedExpert/octahexa-product-reservation-lock
 * GitHub Branch:     main
 * 
 * WC requires at least: 3.0.0
 * WC tested up to:      8.5.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

//===============================================
// 1. PLUGIN SETUP
//===============================================

// 1.1. Define Constants
//---------------------------------------
define('OH_PRL_VERSION', '1.0.0');
define('OH_PRL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OH_PRL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OH_PRL_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('OH_PRL_TABLE_NAME', 'oh_product_reservation_locks');

// 1.2. Plugin Activation
//---------------------------------------
register_activation_hook(__FILE__, 'oh_product_reservation_activate');

function oh_product_reservation_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        variation_id bigint(20) DEFAULT 0,
        user_id bigint(20) DEFAULT 0,
        session_id varchar(255) NOT NULL,
        lock_time datetime NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY product_id (product_id),
        KEY session_id (session_id),
        KEY expires_at (expires_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Set default options
    add_option('oh_prl_lock_duration', 10); // Default: 10 minutes
    add_option('oh_prl_show_warning', 'yes'); // Default: Show warning
}

// 1.3. Plugin Deactivation
//---------------------------------------
register_deactivation_hook(__FILE__, 'oh_product_reservation_deactivate');

function oh_product_reservation_deactivate() {
    // Clear scheduled cleanup events
    wp_clear_scheduled_hook('oh_prl_cleanup_expired_locks');
}

// 1.4. Plugin Initialization
//---------------------------------------
add_action('plugins_loaded', 'oh_product_reservation_init');

function oh_product_reservation_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'oh_product_reservation_woocommerce_notice');
        return;
    }
    
    // Load text domain
    load_plugin_textdomain('octahexa-product-reservation', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Schedule cleanup event (if not already scheduled)
    if (!wp_next_scheduled('oh_prl_cleanup_expired_locks')) {
        wp_schedule_event(time(), 'hourly', 'oh_prl_cleanup_expired_locks');
    }
}

function oh_product_reservation_woocommerce_notice() {
    ?>
    <div class="error">
        <p><?php _e('OctaHexa Product Reservation Lock requires WooCommerce to be installed and active.', 'octahexa-product-reservation'); ?></p>
    </div>
    <?php
}

//===============================================
// 2. ADMIN SETTINGS
//===============================================

// 2.1. Add Settings Page
//---------------------------------------
add_action('admin_menu', 'oh_product_reservation_menu');

function oh_product_reservation_menu() {
    add_submenu_page(
        'woocommerce',
        __('Product Reservation Settings', 'octahexa-product-reservation'),
        __('Product Reservation', 'octahexa-product-reservation'),
        'manage_woocommerce',
        'oh-product-reservation',
        'oh_product_reservation_settings_page'
    );
}

// 2.2. Register Settings
//---------------------------------------
add_action('admin_init', 'oh_product_reservation_register_settings');

function oh_product_reservation_register_settings() {
    register_setting('oh_product_reservation_options', 'oh_prl_lock_duration', array(
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 10,
    ));
    
    register_setting('oh_product_reservation_options', 'oh_prl_show_warning', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'yes',
    ));
    
    add_settings_section(
        'oh_prl_settings_section',
        __('Product Reservation Settings', 'octahexa-product-reservation'),
        'oh_product_reservation_settings_section_callback',
        'oh-product-reservation'
    );
    
    add_settings_field(
        'oh_prl_lock_duration',
        __('Lock Duration (minutes)', 'octahexa-product-reservation'),
        'oh_product_reservation_lock_duration_callback',
        'oh-product-reservation',
        'oh_prl_settings_section'
    );
    
    add_settings_field(
        'oh_prl_show_warning',
        __('Show Reservation Warning', 'octahexa-product-reservation'),
        'oh_product_reservation_show_warning_callback',
        'oh-product-reservation',
        'oh_prl_settings_section'
    );
}

function oh_product_reservation_settings_section_callback() {
    echo '<p>' . __('Configure how product reservation works during checkout.', 'octahexa-product-reservation') . '</p>';
    echo '<p>' . __('Note: For best results, enable WooCommerce\'s built-in "Hold Stock" option and set it to the same duration.', 'octahexa-product-reservation') . '</p>';
}

function oh_product_reservation_lock_duration_callback() {
    $duration = get_option('oh_prl_lock_duration', 10);
    echo '<input type="number" id="oh_prl_lock_duration" name="oh_prl_lock_duration" value="' . esc_attr($duration) . '" min="1" max="60" step="1" />';
    echo '<p class="description">' . __('Number of minutes to lock a product during checkout. After this time, the lock will be released.', 'octahexa-product-reservation') . '</p>';
}

function oh_product_reservation_show_warning_callback() {
    $show_warning = get_option('oh_prl_show_warning', 'yes');
    echo '<select id="oh_prl_show_warning" name="oh_prl_show_warning">';
    echo '<option value="yes" ' . selected($show_warning, 'yes', false) . '>' . __('Yes', 'octahexa-product-reservation') . '</option>';
    echo '<option value="no" ' . selected($show_warning, 'no', false) . '>' . __('No', 'octahexa-product-reservation') . '</option>';
    echo '</select>';
    echo '<p class="description">' . __('Whether to show a warning message to customers that products will be reserved for a limited time.', 'octahexa-product-reservation') . '</p>';
}

// 2.3. Settings Page Content
//---------------------------------------
function oh_product_reservation_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('oh_product_reservation_options');
            do_settings_sections('oh-product-reservation');
            submit_button();
            ?>
        </form>
        
        <div class="oh-prl-settings-help">
            <h2><?php _e('How It Works', 'octahexa-product-reservation'); ?></h2>
            <p><?php _e('This plugin locks products when customers reach the checkout page, preventing other customers from purchasing the same product while it\'s in the checkout process.', 'octahexa-product-reservation'); ?></p>
            
            <h3><?php _e('Recommended WooCommerce Settings', 'octahexa-product-reservation'); ?></h3>
            <ol>
                <li><?php _e('Go to WooCommerce → Settings → Products → Inventory', 'octahexa-product-reservation'); ?></li>
                <li><?php _e('Enable "Hold Stock" and set it to the same duration as configured above', 'octahexa-product-reservation'); ?></li>
                <li><?php _e('This provides a double layer of protection for your unique products', 'octahexa-product-reservation'); ?></li>
            </ol>
        </div>
    </div>
    <?php
}

//===============================================
// 3. PRODUCT LOCKING CORE FUNCTIONALITY
//===============================================

// 3.1. Lock Products on Checkout Page
//---------------------------------------
add_action('template_redirect', 'oh_product_reservation_check_checkout_page');

function oh_product_reservation_check_checkout_page() {
    // Only proceed if we're on the checkout page
    if (!is_checkout()) {
        return;
    }
    
    // Get current cart
    $cart = WC()->cart;
    if (empty($cart) || $cart->is_empty()) {
        return;
    }
    
    // Lock each product in the cart
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
        
        // Lock the product
        oh_lock_product($product_id, $variation_id);
    }
    
    // Show warning message if enabled
    if (get_option('oh_prl_show_warning', 'yes') === 'yes') {
        $lock_duration = get_option('oh_prl_lock_duration', 10);
        $message = sprintf(
            __('Products in your cart are reserved for %d minutes. Please complete your order within this time.', 'octahexa-product-reservation'),
            $lock_duration
        );
        wc_add_notice($message, 'notice');
    }
}

// 3.2. Lock Product Function
//---------------------------------------
function oh_lock_product($product_id, $variation_id = 0) {
    global $wpdb;
    
    // Get current user info
    $user_id = get_current_user_id();
    $session_id = WC()->session->get_customer_id();
    
    // Check if product is already locked by this user/session
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    $existing_lock = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_name 
        WHERE product_id = %d 
        AND variation_id = %d 
        AND session_id = %s",
        $product_id,
        $variation_id,
        $session_id
    ));
    
    // If already locked by this user, update the expiration time
    $lock_duration = get_option('oh_prl_lock_duration', 10);
    $expires_at = date('Y-m-d H:i:s', time() + ($lock_duration * 60));
    
    if ($existing_lock) {
        $wpdb->update(
            $table_name,
            array(
                'lock_time' => current_time('mysql'),
                'expires_at' => $expires_at
            ),
            array('id' => $existing_lock->id),
            array('%s', '%s'),
            array('%d')
        );
    } else {
        // Insert new lock
        $wpdb->insert(
            $table_name,
            array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'user_id' => $user_id,
                'session_id' => $session_id,
                'lock_time' => current_time('mysql'),
                'expires_at' => $expires_at
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s')
        );
    }
}

// 3.3. Check Product Lock Status
//---------------------------------------
function oh_is_product_locked($product_id, $variation_id = 0) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    $session_id = WC()->session ? WC()->session->get_customer_id() : '';
    
    // Check if product is locked by someone else
    $lock = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_name 
        WHERE product_id = %d 
        AND variation_id = %d 
        AND session_id != %s 
        AND expires_at > %s",
        $product_id,
        $variation_id,
        $session_id,
        current_time('mysql')
    ));
    
    return !empty($lock);
}

// 3.4. Cleanup Expired Locks
//---------------------------------------
add_action('oh_prl_cleanup_expired_locks', 'oh_cleanup_expired_product_locks');

function oh_cleanup_expired_product_locks() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    
    // Delete expired locks
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name WHERE expires_at < %s",
        current_time('mysql')
    ));
}

//===============================================
// 4. WOOCOMMERCE INTEGRATION
//===============================================

// 4.1. Prevent Adding Locked Products to Cart
//---------------------------------------
add_filter('woocommerce_add_to_cart_validation', 'oh_validate_product_not_locked', 10, 3);

function oh_validate_product_not_locked($valid, $product_id, $quantity) {
    // If product is already locked by someone else, prevent adding to cart
    if (oh_is_product_locked($product_id)) {
        wc_add_notice(
            __('Sorry, this product is currently reserved by another customer and cannot be added to your cart.', 'octahexa-product-reservation'),
            'error'
        );
        return false;
    }
    
    return $valid;
}

// 4.2. Prevent Adding Locked Variable Products
//---------------------------------------
add_filter('woocommerce_add_to_cart_validation', 'oh_validate_variation_not_locked', 10, 5);

function oh_validate_variation_not_locked($valid, $product_id, $quantity, $variation_id = 0, $variations = array()) {
    if ($variation_id && oh_is_product_locked($product_id, $variation_id)) {
        wc_add_notice(
            __('Sorry, this product variation is currently reserved by another customer and cannot be added to your cart.', 'octahexa-product-reservation'),
            'error'
        );
        return false;
    }
    
    return $valid;
}

// 4.3. Release Locks on Order Completion
//---------------------------------------
add_action('woocommerce_order_status_changed', 'oh_release_locks_on_order_status_change', 10, 3);

function oh_release_locks_on_order_status_change($order_id, $old_status, $new_status) {
    // Get the order
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    // Only process on specific status changes
    $processing_statuses = array('processing', 'completed', 'failed', 'cancelled', 'refunded');
    if (!in_array($new_status, $processing_statuses)) {
        return;
    }
    
    // Get customer session ID
    $customer_id = $order->get_customer_id();
    $session_id = '';
    
    // For guest orders, try to get session from order meta
    if ($customer_id === 0) {
        $session_id = get_post_meta($order_id, '_customer_session_id', true);
    }
    
    // Release locks for this customer/session
    if ($customer_id || $session_id) {
        oh_release_locks_for_customer($customer_id, $session_id);
    }
}

// 4.4. Release Locks Function
//---------------------------------------
function oh_release_locks_for_customer($user_id = 0, $session_id = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    
    if ($user_id > 0) {
        // Delete locks for this user
        $wpdb->delete(
            $table_name,
            array('user_id' => $user_id),
            array('%d')
        );
    } elseif (!empty($session_id)) {
        // Delete locks for this session
        $wpdb->delete(
            $table_name,
            array('session_id' => $session_id),
            array('%s')
        );
    }
}

// 4.5. Release Locks on Cart Empty
//---------------------------------------
add_action('woocommerce_cart_emptied', 'oh_release_locks_on_cart_empty');

function oh_release_locks_on_cart_empty() {
    // Get current user/session info
    $user_id = get_current_user_id();
    $session_id = WC()->session ? WC()->session->get_customer_id() : '';
    
    // Release locks
    oh_release_locks_for_customer($user_id, $session_id);
}

// 4.6. Add Meta Box to Product Admin
//---------------------------------------
add_action('add_meta_boxes', 'oh_add_product_locks_meta_box');

function oh_add_product_locks_meta_box() {
    add_meta_box(
        'oh_product_locks',
        __('Product Reservation Locks', 'octahexa-product-reservation'),
        'oh_product_locks_meta_box_callback',
        'product',
        'side',
        'default'
    );
}

function oh_product_locks_meta_box_callback($post) {
    global $wpdb;
    
    $product_id = $post->ID;
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    
    // Get active locks for this product
    $active_locks = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name 
        WHERE product_id = %d 
        AND expires_at > %s 
        ORDER BY lock_time DESC",
        $product_id,
        current_time('mysql')
    ));
    
    if (empty($active_locks)) {
        echo '<p>' . __('No active reservation locks for this product.', 'octahexa-product-reservation') . '</p>';
        return;
    }
    
    echo '<ul>';
    foreach ($active_locks as $lock) {
        $user_info = '';
        if ($lock->user_id > 0) {
            $user = get_userdata($lock->user_id);
            $user_info = $user ? $user->user_login : __('Unknown User', 'octahexa-product-reservation');
        } else {
            $user_info = __('Guest', 'octahexa-product-reservation');
        }
        
        $variation_text = '';
        if ($lock->variation_id > 0) {
            $variation = wc_get_product($lock->variation_id);
            $variation_text = $variation ? ' (' . $variation->get_formatted_name() . ')' : '';
        }
        
        $time_remaining = human_time_diff(strtotime($lock->expires_at), time());
        
        echo '<li>';
        echo sprintf(
            __('Locked by %1$s%2$s for %3$s more', 'octahexa-product-reservation'),
            esc_html($user_info),
            esc_html($variation_text),
            esc_html($time_remaining)
        );
        echo '</li>';
    }
    echo '</ul>';
    
    // Add manual release button
    echo '<p><a href="' . wp_nonce_url(admin_url('admin-post.php?action=oh_release_product_lock&product_id=' . $product_id), 'oh_release_lock') . '" class="button">' . __('Release All Locks', 'octahexa-product-reservation') . '</a></p>';
}

// 4.7. Handle Manual Lock Release
//---------------------------------------
add_action('admin_post_oh_release_product_lock', 'oh_handle_release_product_lock');

function oh_handle_release_product_lock() {
    // Check nonce
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'oh_release_lock')) {
        wp_die(__('Security check failed', 'octahexa-product-reservation'));
    }
    
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to do this', 'octahexa-product-reservation'));
    }
    
    // Get product ID
    $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
    if (!$product_id) {
        wp_die(__('No product specified', 'octahexa-product-reservation'));
    }
    
    // Release locks for this product
    global $wpdb;
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    
    $wpdb->delete(
        $table_name,
        array('product_id' => $product_id),
        array('%d')
    );
    
    // Redirect back
    wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('post.php?post=' . $product_id . '&action=edit'));
    exit;
}

//===============================================
// 5. PLUGIN UTILITIES AND EXTRAS
//===============================================

// 5.1. Add Settings Link on Plugins Page
//---------------------------------------
add_filter('plugin_action_links_' . OH_PRL_PLUGIN_BASENAME, 'oh_product_reservation_action_links');

function oh_product_reservation_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=oh-product-reservation') . '">' . __('Settings', 'octahexa-product-reservation') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// 5.2. Add Row to Product List for Lock Status
//---------------------------------------
add_filter('manage_edit-product_columns', 'oh_add_product_lock_column', 20);

function oh_add_product_lock_column($columns) {
    $columns['product_lock'] = __('Lock Status', 'octahexa-product-reservation');
    return $columns;
}

add_action('manage_product_posts_custom_column', 'oh_show_product_lock_column', 10, 2);

function oh_show_product_lock_column($column, $post_id) {
    if ($column != 'product_lock') {
        return;
    }
    
    if (oh_is_product_locked($post_id)) {
        echo '<span class="dashicons dashicons-lock" style="color:red;" title="' . esc_attr__('Product is locked', 'octahexa-product-reservation') . '"></span>';
    } else {
        echo '<span class="dashicons dashicons-unlock" style="color:green;" title="' . esc_attr__('Product is available', 'octahexa-product-reservation') . '"></span>';
    }
}

// 5.3. Debug Function (Disabled by Default)
//---------------------------------------
function oh_debug_product_locks($enabled = false) {
    if (!$enabled || !current_user_can('manage_options')) {
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    
    $locks = $wpdb->get_results("SELECT * FROM $table_name ORDER BY lock_time DESC LIMIT 50");
    
    echo '<div class="oh-prl-debug" style="margin: 20px; padding: 20px; border: 1px solid #ccc;">';
    echo '<h3>Product Reservation Locks Debug</h3>';
    
    if (empty($locks)) {
        echo '<p>No locks found</p>';
    } else {
        echo '<table style="width: 100%; border-collapse: collapse;">';
        echo '<tr>';
        echo '<th style="border: 1px solid #ccc; padding: 5px;">ID</th>';
        echo '<th style="border: 1px solid #ccc; padding: 5px;">Product</th>';
        echo '<th style="border: 1px solid #ccc; padding: 5px;">Variation</th>';
        echo '<th style="border: 1px solid #ccc; padding: 5px;">User</th>';
        echo '<th style="border: 1px solid #ccc; padding: 5px;">Session</th>';
        echo '<th style="border: 1px solid #ccc; padding: 5px;">Lock Time</th>';
        echo '<th style="border: 1px solid #ccc; padding: 5px;">Expires</th>';
        echo '</tr>';
        
        foreach ($locks as $lock) {
            $product = wc_get_product($lock->product_id);
            $product_name = $product ? $product->get_name() : 'Unknown Product';
            
            $variation = $lock->variation_id > 0 ? wc_get_product($lock->variation_id) : null;
            $variation_name = $variation ? $variation->get_formatted_name() : 'N/A';
            
            $user = $lock->user_id > 0 ? get_userdata($lock->user_id) : null;
            $user_name = $user ? $user->user_login : 'Guest';
            
            echo '<tr>';
            echo '<td style="border: 1px solid #ccc; padding: 5px;">' . esc_html($lock->id) . '</td>';
            echo '<td style="border: 1px solid #ccc; padding: 5px;">' . esc_html($product_name) . ' (#' . esc_html($lock->product_id) . ')</td>';
            echo '<td style="border: 1px solid #ccc; padding: 5px;">' . esc_html($variation_name) . '</td>';
            echo '<td style="border: 1px solid #ccc; padding: 5px;">' . esc_html($user_name) . '</td>';
            echo '<td style="border: 1px solid #ccc; padding: 5px;">' . esc_html(substr($lock->session_id, 0, 10)) . '...</td>';
            echo '<td style="border: 1px solid #ccc; padding: 5px;">' . esc_html($lock->lock_time) . '</td>';
            echo '<td style="border: 1px solid #ccc; padding: 5px;">' . esc_html($lock->expires_at) . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
    }
    
    echo '</div>';
}

// 5.4. AJAX Check Lock Status
//---------------------------------------
add_action('wp_ajax_oh_check_product_lock', 'oh_ajax_check_product_lock');
add_action('wp_ajax_nopriv_oh_check_product_lock', 'oh_ajax_check_product_lock');

function oh_ajax_check_product_lock() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'oh_product_lock_nonce')) {
        wp_send_json_error('Invalid security token');
    }
    
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
    
    if (!$product_id) {
        wp_send_json_error('No product specified');
    }
    
    $is_locked = oh_is_product_locked($product_id, $variation_id);
    
    wp_send_json_success(array(
        'locked' => $is_locked,
        'message' => $is_locked ? __('This product is currently reserved by another customer.', 'octahexa-product-reservation') : '',
    ));
}

// 5.5. Add Frontend Scripts (Optional)
//---------------------------------------
add_action('wp_enqueue_scripts', 'oh_product_reservation_scripts');

function oh_product_reservation_scripts() {
    // Only load on product or cart pages
    if (!is_product() && !is_cart() && !is_checkout()) {
        return;
    }
    
    wp_enqueue_script(
        'oh-product-reservation',
        OH_PRL_PLUGIN_URL . 'assets/js/product-reservation.js',
        array('jquery'),
        OH_PRL_VERSION,
        true
    );
    
    wp_localize_script(
        'oh-product-reservation',
        'ohPRL',
        array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oh_product_lock_nonce'),
            'lock_duration' => get_option('oh_prl_lock_duration', 10),
            'i18n' => array(
                'product_locked' => __('This product is currently reserved by another customer.', 'octahexa-product-reservation'),
                'please_wait' => __('Please wait...', 'octahexa-product-reservation'),
            )
        )
    );
}

//===============================================
// 6. PLUGIN ASSETS AND RESOURCES
//===============================================

// 6.1. Ensure Assets Directory
//---------------------------------------
function oh_product_reservation_create_assets() {
    // Check if assets directory exists, if not create it
    $assets_dir = OH_PRL_PLUGIN_DIR . 'assets/js';
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }
    
    // Create JavaScript file if it doesn't exist
    $js_file = $assets_dir . '/product-reservation.js';
    if (!file_exists($js_file)) {
        $js_content = <<<EOT
/**
 * OctaHexa Product Reservation Lock
 * Frontend JavaScript functionality
 * 
 * Version: 1.0.0
 */
(function($) {
    'use strict';
    
    // Initialize the product reservation system
    $(document).ready(function() {
        // Check if we're on a product page
        if ($('.single-product').length > 0) {
            // Get the product ID from the add to cart button
            var productId = $('input[name="add-to-cart"]').val();
            if (!productId && $('button[name="add-to-cart"]').length) {
                productId = $('button[name="add-to-cart"]').val();
            }
            
            // Check lock status on page load for simple products
            if (productId) {
                checkProductLockStatus(productId, 0);
            }
            
            // For variable products, check when variation is selected
            $(document).on('found_variation', function(event, variation) {
                checkProductLockStatus(productId, variation.variation_id);
            });
            
            // Handle form submission for variable products
            $('form.variations_form').on('submit', function(e) {
                var variationId = $('input[name="variation_id"]').val();
                if (variationId) {
                    e.preventDefault();
                    checkProductLockStatusBeforeSubmit(productId, variationId, $(this));
                }
            });
        }
    });
    
    /**
     * Check if a product is locked
     */
    function checkProductLockStatus(productId, variationId) {
        $.ajax({
            type: 'POST',
            url: ohPRL.ajax_url,
            data: {
                action: 'oh_check_product_lock',
                nonce: ohPRL.nonce,
                product_id: productId,
                variation_id: variationId
            },
            success: function(response) {
                if (response.success && response.data.locked) {
                    // Product is locked, disable add to cart
                    $('button.single_add_to_cart_button').prop('disabled', true).addClass('disabled');
                    
                    // Show message if not already visible
                    if ($('.oh-product-lock-notice').length === 0) {
                        $('form.cart').before('<div class="woocommerce-info oh-product-lock-notice">' + 
                            response.data.message + 
                            '</div>');
                    }
                } else {
                    // Product is not locked, enable add to cart
                    $('button.single_add_to_cart_button').prop('disabled', false).removeClass('disabled');
                    $('.oh-product-lock-notice').remove();
                }
            }
        });
    }
    
    /**
     * Check lock status before submitting form
     */
    function checkProductLockStatusBeforeSubmit(productId, variationId, form) {
        // Show loading state
        form.block({
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });
        
        $.ajax({
            type: 'POST',
            url: ohPRL.ajax_url,
            data: {
                action: 'oh_check_product_lock',
                nonce: ohPRL.nonce,
                product_id: productId,
                variation_id: variationId
            },
            success: function(response) {
                form.unblock();
                
                if (response.success && response.data.locked) {
                    // Product is locked, show message
                    if ($('.oh-product-lock-notice').length === 0) {
                        form.before('<div class="woocommerce-info oh-product-lock-notice">' + 
                            response.data.message + 
                            '</div>');
                    }
                } else {
                    // Product is not locked, submit form
                    form.submit();
                }
            },
            error: function() {
                form.unblock();
                // On error, allow form submission
                form.submit();
            }
        });
    }
    
})(jQuery);
EOT;
        file_put_contents($js_file, $js_content);
    }
}

// Run on plugin activation
register_activation_hook(__FILE__, 'oh_product_reservation_create_assets');

//===============================================
// 7. WOOCOMMERCE HOOKS AND FILTERS
//===============================================

// 7.1. Add Custom Order Meta for Session ID
//---------------------------------------
add_action('woocommerce_checkout_create_order', 'oh_add_session_id_to_order', 20, 2);

function oh_add_session_id_to_order($order, $data) {
    if (WC()->session) {
        $session_id = WC()->session->get_customer_id();
        $order->update_meta_data('_customer_session_id', $session_id);
    }
}

// 7.2. Add Notice on Product Page for Locked Items
//---------------------------------------
add_action('woocommerce_before_single_product', 'oh_check_single_product_lock');

function oh_check_single_product_lock() {
    global $product;
    
    if (!is_object($product)) {
        return;
    }
    
    // Check if product is locked
    if (oh_is_product_locked($product->get_id())) {
        wc_print_notice(
            __('This product is currently reserved by another customer and might not be available for purchase.', 'octahexa-product-reservation'),
            'notice'
        );
    }
}

// 7.3. Validate Cart Contents on Checkout
//---------------------------------------
add_action('woocommerce_check_cart_items', 'oh_validate_cart_product_locks');

function oh_validate_cart_product_locks() {
    // Only check on checkout page
    if (!is_checkout()) {
        return;
    }
    
    // Get current cart
    $cart = WC()->cart;
    if (empty($cart) || $cart->is_empty()) {
        return;
    }
    
    $locked_items = array();
    
    // Check each product in the cart
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        
        // Skip if product doesn't exist
        if (!$product) {
            continue;
        }
        
        // Check if product is locked by someone else
        if (oh_is_product_locked($product_id, $variation_id)) {
            $locked_items[] = $product->get_name();
        }
    }
    
    // If we found locked items, show error
    if (!empty($locked_items)) {
        $message = sprintf(
            _n(
                'The following product is no longer available and has been reserved by another customer: %s',
                'The following products are no longer available and have been reserved by other customers: %s',
                count($locked_items),
                'octahexa-product-reservation'
            ),
            '<strong>' . implode('</strong>, <strong>', $locked_items) . '</strong>'
        );
        
        wc_add_notice($message, 'error');
    }
}

// 7.4. Add Product Reservation Data to API (for Headless/Mobile)
//---------------------------------------
add_filter('woocommerce_rest_prepare_product_object', 'oh_add_lock_data_to_api', 10, 3);

function oh_add_lock_data_to_api($response, $product, $request) {
    $data = $response->get_data();
    
    // Add product lock information
    $data['is_locked'] = oh_is_product_locked($product->get_id());
    $data['lock_duration'] = get_option('oh_prl_lock_duration', 10);
    
    $response->set_data($data);
    return $response;
}

//===============================================
// 8. PLUGIN COMPATIBILITY FUNCTIONS
//===============================================

// 8.1. Support for WooCommerce Quick View
//---------------------------------------
add_action('woocommerce_quick_view_product_summary', 'oh_quick_view_lock_notice', 5);

function oh_quick_view_lock_notice() {
    global $product;
    
    if (!is_object($product)) {
        return;
    }
    
    // Check if product is locked
    if (oh_is_product_locked($product->get_id())) {
        echo '<div class="woocommerce-info">' . 
            __('This product is currently reserved by another customer and might not be available for purchase.', 'octahexa-product-reservation') . 
            '</div>';
    }
}

// 8.2. WPML/Polylang Support
//---------------------------------------
add_action('plugins_loaded', 'oh_product_reservation_wpml_register_strings');

function oh_product_reservation_wpml_register_strings() {
    // Check if WPML or Polylang is active and has the required function
    if (function_exists('icl_register_string')) {
        // Register translatable strings
        icl_register_string('octahexa-product-reservation', 'Product locked message', 'This product is currently reserved by another customer and cannot be added to your cart.');
        icl_register_string('octahexa-product-reservation', 'Checkout reservation notice', 'Products in your cart are reserved for %d minutes. Please complete your order within this time.');
    }
}

// 8.3. Compatibility with Cache Plugins
//---------------------------------------
add_action('init', 'oh_nocache_headers_for_product_pages');

function oh_nocache_headers_for_product_pages() {
    // Only apply to product pages
    if (is_product()) {
        global $product;
        
        // If product object exists and we can check if it's locked
        if (is_object($product) && function_exists('oh_is_product_locked')) {
            // Add nocache headers to ensure real-time lock status
            nocache_headers();
        }
    }
}

// 8.4. WooCommerce Block Compatibility
//---------------------------------------
add_filter('woocommerce_blocks_product_grid_item_html', 'oh_modify_product_block_html', 10, 3);

function oh_modify_product_block_html($html, $data, $product) {
    // Check if product is locked
    if (oh_is_product_locked($product->get_id())) {
        // Add locked class to product item
        $html = str_replace('class="wc-block-grid__product"', 'class="wc-block-grid__product oh-product-locked"', $html);
        
        // Add locked badge
        $locked_badge = '<span class="oh-locked-badge">' . __('Reserved', 'octahexa-product-reservation') . '</span>';
        $html = str_replace('<li', '<li data-locked="true" ', $html);
        $html = str_replace('<a href="', $locked_badge . '<a href="', $html);
    }
    
    return $html;
}

//===============================================
// 9. PLUGIN UPDATES AND MAINTENANCE
//===============================================

// 9.1. Database Version Management
//---------------------------------------
function oh_get_db_version() {
    return get_option('oh_prl_db_version', '1.0.0');
}

function oh_update_db_version($version) {
    update_option('oh_prl_db_version', $version);
}

// 9.2. Plugin Update Function
//---------------------------------------
add_action('plugins_loaded', 'oh_product_reservation_check_updates');

function oh_product_reservation_check_updates() {
    $current_version = oh_get_db_version();
    
    // Version-specific updates can be added here
    if (version_compare($current_version, '1.0.0', '<')) {
        // Update to 1.0.0
        oh_update_db_version('1.0.0');
    }
    
    // Always ensure the database table exists
    if (version_compare($current_version, OH_PRL_VERSION, '<')) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            variation_id bigint(20) DEFAULT 0,
            user_id bigint(20) DEFAULT 0,
            session_id varchar(255) NOT NULL,
            lock_time datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY session_id (session_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Update version
        oh_update_db_version(OH_PRL_VERSION);
    }
}

//===============================================
// 10. PLUGIN UNINSTALLATION
//===============================================

// Define uninstall function
register_uninstall_hook(__FILE__, 'oh_product_reservation_uninstall');

function oh_product_reservation_uninstall() {
    // Remove database table
    global $wpdb;
    $table_name = $wpdb->prefix . OH_PRL_TABLE_NAME;
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    
    // Remove options
    delete_option('oh_prl_lock_duration');
    delete_option('oh_prl_show_warning');
    delete_option('oh_prl_db_version');
    
    // Clear any scheduled events
    wp_clear_scheduled_hook('oh_prl_cleanup_expired_locks');
}
