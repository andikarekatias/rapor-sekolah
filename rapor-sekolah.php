<?php 
/**
 * @package Rapor Sekolah
 */
/**
* Plugin Name: Rapor Sekolah
* Plugin URI: https://github.com/andikarekatias/rapor-sekolah
* Description: Provides a form to access custom post type "Rapor" based on email and password.
* Version: 1.0.0
* Tested up to: 6.2
* Author: Andika Rekatias
* Author URI: https://andikarekatias.com/contact.html
* License: GPL2 or later
* Text Domain: rapor
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
// Activation hook to display instructions
register_activation_hook(__FILE__, 'rapor_activation_notice');

function rapor_activation_notice() {
    add_option('rapor_activation_notice', true);
}

// Admin notice function
add_action('admin_notices', 'rapor_admin_notice');

function rapor_admin_notice() {
    if (get_option('rapor_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Rapor Sekolah plugin activated! Please create a page named "Rapor" and add the shortcode <code>[rapor_access_form]</code> to it to use the plugin.', 'rapor'); ?></p>
        </div>
        <?php
        delete_option('rapor_activation_notice'); // Remove the option to avoid showing it again
    }
}

// Define constants
define('RAPOR_LOGIN_NONCE', 'rapor_login');
define('RAPOR_LOGOUT_NONCE', 'rapor_logout');
define('RAPOR_SESSION_KEY', 'rapor_access_granted');
define('RAPOR_SESSION_ID', 'rapor_id');

// Register the custom post type 'rapor'
add_action('init', function() {
    register_post_type('rapor', array(
        'labels' => array(
            'name' => 'Rapors',
            'singular_name' => 'Rapor',
            'menu_name' => 'Raports',
            'all_items' => 'All Raports',
            'edit_item' => 'Edit Raport',
            'view_item' => 'View Raport',
            'add_new_item' => 'Add New Raport',
            'new_item' => 'New Raport',
            'search_items' => 'Search Raports',
            'not_found' => 'No raports found',
            'not_found_in_trash' => 'No raports found in Trash',
        ),
        'public' => true,
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-analytics',
        'supports' => array('title', 'editor', 'thumbnail'),
    ));
});

// Start session only once
add_action('init', function() {
    if (!session_id()) {
        session_start();
    }
});

// Add meta boxes for Email and Password
add_action('add_meta_boxes', function() {
    add_meta_box('rapor_fields', 'Rapor Fields', 'rapor_fields_callback', 'rapor', 'normal', 'high');
});

// Meta box callback function
function rapor_fields_callback($post) {
    wp_nonce_field('rapor_save_meta_box_data', 'rapor_meta_box_nonce');

    $email_siswa = get_post_meta($post->ID, '_email_siswa', true);
    $password_siswa = get_post_meta($post->ID, '_password_siswa', true);
    $nisn = get_post_meta($post->ID, '_nisn', true);

    echo '<label for="email_siswa">Email Siswa</label>';
    echo '<input type="email" id="email_siswa" name="email_siswa" value="' . esc_attr($email_siswa) . '" size="25" />';
    echo '<button type="button" id="check_email" class="button">Check Email</button>';
    echo '<div id="email_check_result"></div><br>';
    echo '<label for="nisn">NISN</label>';
    echo '<input type="text" id="nisn" name="nisn" value="' . esc_attr($nisn) . '" size="25" /> <br><br>';
    echo '<label for="password_siswa">Password Siswa</label>';
    echo '<input type="password" id="password_siswa" name="password_siswa" value="' . esc_attr($password_siswa) . '" size="25" />';
}


// Save the custom meta fields
add_action('save_post', function($post_id) {
    // Check for the nonce and permission
    if (!isset($_POST['rapor_meta_box_nonce']) || !wp_verify_nonce($_POST['rapor_meta_box_nonce'], 'rapor_save_meta_box_data')) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Check for the email input
    if (isset($_POST['email_siswa'])) {
        $email_siswa = sanitize_email($_POST['email_siswa']);
        
        // Check if the email already exists in another post
        $args = array(
            'post_type' => 'rapor',
            'meta_query' => array(
                array(
                    'key' => '_email_siswa',
                    'value' => $email_siswa,
                    'compare' => '='
                )
            ),
            'post__not_in' => array($post_id), // Exclude the current post from the query
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            // Email already exists
            add_filter('redirect_post_location', function($location) {
                return add_query_arg('email_exists', '1', $location);
            });
            set_transient('email_exists_error', 'The email "' . esc_html($email_siswa) . '" already exists for another student. Please use a unique email.', 30);
            return; // Prevent saving the post
        }
        
        // Save the email if it's unique
        update_post_meta($post_id, '_email_siswa', $email_siswa);
    }

    if (isset($_POST['nisn'])) {
        $nisn = sanitize_text_field($_POST['nisn']);
        update_post_meta($post_id, '_nisn', $nisn); // Save NISN to post meta
    }

    // Save the password as usual
    if (isset($_POST['password_siswa'])) {
        $password_siswa = sanitize_text_field($_POST['password_siswa']);
        $hashed_password = wp_hash_password($password_siswa); // Hash the password before saving
        update_post_meta($post_id, '_password_siswa', $hashed_password);
    }
});

// Show an error message if email already exists
add_action('admin_notices', function() {
    if (isset($_GET['email_exists']) || get_transient('email_exists_error')) {
        $error_message = get_transient('email_exists_error') ?: 'The email already exists for another student. Please use a unique email.';
        echo '<div class="error"><p>' . esc_html($error_message) . '</p></div>';
        delete_transient('email_exists_error'); // Clear the transient
    }
});

// Handle login logic
function handle_login() {
    $error_message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['rapor_login_nonce']) || !wp_verify_nonce($_POST['rapor_login_nonce'], RAPOR_LOGIN_NONCE)) {
            return 'Security check failed.';
        }
        $email_siswa = sanitize_email($_POST['email_siswa']);
        $password_siswa = sanitize_text_field($_POST['password_siswa']);
        
        $args = array(
            'post_type' => 'rapor',
            'meta_query' => array(
                array(
                    'key' => '_email_siswa',
                    'value' => $email_siswa,
                    'compare' => '='
                )
            )
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $stored_hashed_password = get_post_meta(get_the_ID(), '_password_siswa', true);
                if (wp_check_password($password_siswa, $stored_hashed_password)) {
                    $_SESSION[RAPOR_SESSION_KEY] = true;
                    $_SESSION[RAPOR_SESSION_ID] = get_the_ID();
                    wp_redirect(site_url('/rapor'));
                    exit;
                }
            }
            wp_reset_postdata();
            $error_message = 'Invalid password. Please try again.'; // Specific error message
        } else {
            $error_message = 'Email not found. Please check and try again.'; // More specific error message
        }
    }
    return $error_message;
}

// Combined template redirect action for authentication
add_action('template_redirect', function() {
    if (is_singular('rapor')) {
        if (!isset($_SESSION[RAPOR_SESSION_KEY]) || $_SESSION[RAPOR_SESSION_KEY] !== true) {
            handle_login(); // Handle login process
            wp_redirect(site_url('/rapor'));
            exit;
        }
    }
});

// Final shortcode and session handling
add_shortcode('rapor_access_form', function() {
    ob_start();
    
    // Logout logic at the beginning
    if (isset($_POST['logout'])) {
        if (isset($_POST['rapor_logout_nonce']) && wp_verify_nonce($_POST['rapor_logout_nonce'], RAPOR_LOGOUT_NONCE)) {
            session_unset(); // Clear all session variables
            session_destroy(); // Destroy the session
            wp_redirect(site_url('/rapor'));
            exit;
        }
    }

    // Check if the user is authenticated
    if (isset($_SESSION[RAPOR_SESSION_KEY]) && $_SESSION[RAPOR_SESSION_KEY] === true && isset($_SESSION[RAPOR_SESSION_ID])) {
        $post_id = $_SESSION[RAPOR_SESSION_ID];
        $post = get_post($post_id);
        if ($post) {             
            ?>
            <div class="rapor-container">                              
                <div class="avatar-container">
                    <?php
                    // Display the featured image as an avatar
                    if (has_post_thumbnail($post_id)) {
                        echo get_the_post_thumbnail($post_id, array(250, 250), array('class' => 'avatar')); // Change 'full' to your preferred size
                    }
                    ?>
                    <div class="text-container">
                        <h2><?php echo esc_html($post->post_title); ?></h2>
                        <h2><?php echo esc_html(get_post_meta($post_id, '_nisn', true)); ?></h2>
                    </div>
                </div>                
                <div class="rapor-content">
                    <?= wp_kses_post($post->post_content) ?>
                </div>
                <div class="rapor-logout">
                    <form method="POST" action="" class="logout-form">
                        <input type="hidden" name="rapor_logout_nonce" value="<?php echo wp_create_nonce(RAPOR_LOGOUT_NONCE); ?>">
                        <input type="submit" name="logout" value="Logout">
                    </form>
                </div>
            </div>
            <?php
        }
    } else {
        // Handle login
        $error_message = handle_login();
        if ($error_message) {
            echo '<p>' . esc_html($error_message) . '</p>'; // Display the error message
        }
        // Display login form
        ?>
        <form method="POST" action="" class="rapor-login-form">
            <input type="hidden" name="rapor_login_nonce" value="<?php echo wp_create_nonce(RAPOR_LOGIN_NONCE); ?>">
            <label for="email_siswa">Email:</label>
            <input type="email" id="email_siswa" name="email_siswa" required><br>            
            <label for="password_siswa">Password:</label>
            <input type="password" id="password_siswa" name="password_siswa" required><br>
            <input type="submit" value="Login">
        </form>
        <?php
    }

    return ob_get_clean();
});

// AJAX handler to check for duplicate email
add_action('wp_ajax_check_email', 'check_email');
add_action('wp_ajax_nopriv_check_email', 'check_email');

function check_email() {
    if (isset($_POST['email'])) {
        $email = sanitize_email($_POST['email']);
        
        // Check if the email already exists in another post
        $args = array(
            'post_type' => 'rapor',
            'meta_query' => array(
                array(
                    'key' => '_email_siswa',
                    'value' => $email,
                    'compare' => '='
                )
            ),
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            wp_send_json_error('This email is already in use.');
        } else {
            wp_send_json_success('This email is available.');
        }
    }
    wp_die(); // Always die in AJAX functions
}

// Enqueue the JavaScript for AJAX email check
add_action('admin_enqueue_scripts', function() {
    wp_enqueue_script('check-email-script', plugin_dir_url(__FILE__) . 'js/check-email.js', array('jquery'), null, true);
    wp_localize_script('check-email-script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
});

// Enqueue the JavaScript and CSS for the plugin
add_action('wp_enqueue_scripts', 'rapor_enqueue_styles');

function rapor_enqueue_styles() {
    // Enqueue the CSS file
    wp_enqueue_style('rapor-custom-style', plugin_dir_url(__FILE__) . 'css/rapor-style.css');
}
