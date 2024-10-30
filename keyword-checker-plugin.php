<?php
/*
Plugin Name: Keyword Checker
Description: Checks if three keywords are present in Title, Meta Description, H1, H2, H3, and internal links in each post/page.
Version: 1.5.1
Author: Kha Creation LLC
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tested up to: 6.6
Requires PHP: 7.4
Requires WP: 5.7
Author URI: https://khacreationusa.com/
Plugin URI: https://khacreationusa.com/
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Enqueue scripts and styles
add_action('admin_enqueue_scripts', 'keyword_checker_enqueue_scripts');

function keyword_checker_enqueue_scripts($hook) {
    // Only enqueue on post edit screens
    if ( 'post.php' != $hook && 'post-new.php' != $hook ) {
        return;
    }

    // Enqueue the JavaScript file
    wp_enqueue_script(
        'keyword-checker-js',
        plugin_dir_url(__FILE__) . 'js/keyword-checker.js',
        array('jquery'), // Dependencies
        '1.5',
        true // Load in footer
    );

    // Enqueue the CSS file
    wp_enqueue_style(
        'keyword-checker-css',
        plugin_dir_url(__FILE__) . 'css/keyword-checker.css',
        array(),
        '1.5'
    );

    // Localize script to pass AJAX URL and nonce
    wp_localize_script(
        'keyword-checker-js',
        'keywordChecker',
        array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('keyword_checker_scan_ajax'),
            'postId'  => isset($_GET['post']) ? intval($_GET['post']) : 0,
        )
    );
}

// Add a meta box to the post editor screen for keywords
add_action('add_meta_boxes', 'keyword_checker_add_meta_box');

function keyword_checker_add_meta_box() {
    add_meta_box(
        'keyword-checker-meta-box',
        'Keyword Checker',
        'keyword_checker_meta_box_callback',
        ['post', 'page'], // Include 'page' if needed
        'normal',
        'high'
    );
}

// Callback function for the meta box
function keyword_checker_meta_box_callback($post) {
    // Add a nonce field for verification
    wp_nonce_field('keyword_checker_save_meta_box_data', 'keyword_checker_meta_box_nonce');

    // Retrieve existing keywords from post meta if available
    $keyword1 = esc_attr(get_post_meta($post->ID, '_keyword1', true));
    $keyword2 = esc_attr(get_post_meta($post->ID, '_keyword2', true));
    $keyword3 = esc_attr(get_post_meta($post->ID, '_keyword3', true));
    ?>
    <label for="keyword1">Keyword 1: </label>
    <input type="text" name="keyword1" value="<?php echo $keyword1; ?>" size="25" /><br><br>
    
    <label for="keyword2">Keyword 2: </label>
    <input type="text" name="keyword2" value="<?php echo $keyword2; ?>" size="25" /><br><br>

    <label for="keyword3">Keyword 3: </label>
    <input type="text" name="keyword3" value="<?php echo $keyword3; ?>" size="25" /><br><br>

    <!-- Check Now Button -->
    <button type="button" id="keyword-check-now" class="button button-primary">Check Now</button>

    <div id="keyword-checker-results">
        <!-- The results will be inserted here by JavaScript -->
    </div>
    <?php
}

// Save the keywords when the post is saved
add_action('save_post', 'keyword_checker_save_meta_box_data');

function keyword_checker_save_meta_box_data($post_id) {
    // Check if nonce is set
    if (!isset($_POST['keyword_checker_meta_box_nonce'])) {
        return;
    }

    $nonce = sanitize_text_field(wp_unslash($_POST['keyword_checker_meta_box_nonce']));

    // Verify nonce
    if (!wp_verify_nonce($nonce, 'keyword_checker_save_meta_box_data')) {
        return;
    }

    // Check for autosave and permissions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Sanitize and update meta fields
    $keywords = [
        'keyword1' => isset($_POST['keyword1']) ? sanitize_text_field(wp_unslash($_POST['keyword1'])) : '',
        'keyword2' => isset($_POST['keyword2']) ? sanitize_text_field(wp_unslash($_POST['keyword2'])) : '',
        'keyword3' => isset($_POST['keyword3']) ? sanitize_text_field(wp_unslash($_POST['keyword3'])) : '',
    ];

    foreach ($keywords as $key => $value) {
        update_post_meta($post_id, '_' . $key, $value);
    }
}

// Function to check keywords in the post
function keyword_checker_scan($post_id) {
    // Ensure post ID is valid
    if (!is_numeric($post_id) || $post_id <= 0) {
        return "<p style='color: red;'>Invalid post ID.</p>";
    }

    $post = get_post($post_id);
    if (!$post || get_post_status($post_id) != 'publish') {
        return "<p style='color: red;'>Post not found or not published.</p>";
    }

    $keyword1 = sanitize_text_field(get_post_meta($post_id, '_keyword1', true));
    $keyword2 = sanitize_text_field(get_post_meta($post_id, '_keyword2', true));
    $keyword3 = sanitize_text_field(get_post_meta($post_id, '_keyword3', true));

    $keywords = array_filter([$keyword1, $keyword2, $keyword3]); // Remove empty keywords

    if (empty($keywords)) {
        return "<p style='color: red;'>No keywords entered.</p>";
    }

    $title = $post->post_title;
    $content = $post->post_content;

    // Check Title, Meta Description, H1, H2, H3, and internal links
    $meta_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true); // Assuming Yoast SEO is installed for meta description
    $title_check = keyword_present($title, $keywords);
    $meta_check = keyword_present($meta_desc, $keywords);
    $content_check = keyword_in_headings($content, $keywords);
    $internal_link_check = keyword_in_internal_links($content, $keywords);

    // Build response with green ticks (✅) and red crosses (❌)
    $results = "<h3>Keyword Check Result:</h3>";
    $results .= "<strong>Keyword in Title:</strong> " . ($title_check ? '✅' : '❌') . "<br>";
    $results .= "<strong>Keyword in Meta Description:</strong> " . ($meta_check ? '✅' : '❌') . "<br>";
    $results .= "<strong>Keyword in H1, H2, H3:</strong> " . ($content_check ? '✅' : '❌') . "<br>";
    $results .= "<strong>Keyword in Internal Links:</strong> " . ($internal_link_check ? '✅' : '❌') . "<br>";

    return $results; // We'll handle escaping in AJAX response
}

// Handle AJAX request for keyword check
add_action('wp_ajax_keyword_checker_scan_ajax', 'keyword_checker_scan_ajax');

function keyword_checker_scan_ajax() {
    // Check nonce and validate the post_id
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'keyword_checker_scan_ajax')) {
        wp_send_json_error('Nonce verification failed.');
    }

    if (!isset($_POST['post_id']) || !is_numeric($_POST['post_id'])) {
        wp_send_json_error('Invalid post ID.');
    }

    $post_id = intval($_POST['post_id']);

    $results = keyword_checker_scan($post_id);

    wp_send_json_success($results);
}

// Helper function to check if a keyword is present
function keyword_present($text, $keywords) {
    if (empty($text)) {
        return false;
    }

    foreach ($keywords as $keyword) {
        if (stripos($text, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

// Helper function to check if keywords are in H1, H2, H3
function keyword_in_headings($content, $keywords) {
    if (empty($content)) {
        return false;
    }

    // Use a regular expression to find H1, H2, and H3 tags and their contents
    preg_match_all('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/', $content, $matches);

    if (!empty($matches[1])) {
        foreach ($matches[1] as $heading) {
            if (keyword_present($heading, $keywords)) {
                return true;
            }
        }
    }

    return false;
}

// Helper function to check internal links
function keyword_in_internal_links($content, $keywords) {
    if (empty($content)) {
        return false;
    }

    preg_match_all('/<a\s+href=["\'](.*?)["\'].*?>(.*?)<\/a>/i', $content, $matches);
    $internal_domain = wp_parse_url(home_url(), PHP_URL_HOST);

    foreach ($matches[1] as $index => $url) {
        if (stripos($url, $internal_domain) !== false && keyword_present($matches[2][$index], $keywords)) {
            return true;
        }
    }

    return false;
}