<?php
/**
 * Plugin Name: MME Form
 * Plugin URI: https://mme.vn
 * Description: Compact form builder with external embeds, source URL tracking, chatbot, Google Sheets webhooks, and Twenty CRM sync.
 * Version: 0.5.6 (2026-10-21 11:12)
 * Author: MME
 * Text Domain: mme-form
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Polyfill cho PHP 7.4
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return (string)$needle === '' || mb_strpos((string)$haystack, (string)$needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle === '' || strncmp((string)$haystack, (string)$needle, strlen((string)$needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return (string)$needle === '' || substr((string)$haystack, -strlen((string)$needle)) === (string)$needle;
    }
}

// Ngăn chặn xung đột khi trên server tồn tại 2 thư mục plugin (ví dụ: mmeform và mmeform-main)
if (defined('MME_FORM_BOOTSTRAPPED')) {
    return;
}
define('MME_FORM_BOOTSTRAPPED', true);

if (!defined('MME_FORM_VERSION')) {
    define('MME_FORM_VERSION', '0.5.6');
}
if (!defined('MME_FORM_BUILD_TIME')) {
    define('MME_FORM_BUILD_TIME', '2026-10-21 11:12:00');
}
if (!defined('MME_FORM_FILE')) {
    define('MME_FORM_FILE', __FILE__);
}
if (!defined('MME_FORM_DIR')) {
    define('MME_FORM_DIR', plugin_dir_path(__FILE__));
}
if (!defined('MME_FORM_URL')) {
    define('MME_FORM_URL', plugin_dir_url(__FILE__));
}

require_once MME_FORM_DIR . 'includes/class-mme-form-renderer.php';
require_once MME_FORM_DIR . 'includes/class-mme-form-submissions.php';
require_once MME_FORM_DIR . 'includes/class-mme-form-admin.php';
require_once MME_FORM_DIR . 'includes/class-mme-form-plugin.php';
require_once MME_FORM_DIR . 'includes/class-mme-form-updater.php';
require_once MME_FORM_DIR . 'includes/mme-chatbot.php';

register_activation_hook(__FILE__, array('MME_Form_Plugin', 'activate'));

add_action('plugins_loaded', static function (): void {
    MME_Form_Plugin::instance()->boot();
});

/**
 * Helper cho Dev / AI: Tự động tìm hoặc tạo form theo slug, sau đó trả về HTML shortcode.
 * Giúp AI code theme/landing page có thể nhúng và tạo form ngay bằng PHP mà không cần vào wp-admin lấy ID.
 * 
 * @param string $slug Slug duy nhất của form (ví dụ: 'landing-contact-2026')
 * @param string $title Tiêu đề form nếu cần tạo mới
 * @param array|string $fields Mảng hoặc JSON string các field
 * @param array|string $settings Mảng hoặc JSON string cấu hình giao diện
 * @param bool $fields_only Mặc định true (Chỉ render phần form nhập liệu để fit vào layout của AI/dev, không hiện cột thông tin bên trái)
 * @return string HTML render của form
 */
if (!function_exists('mme_form_auto')) {
function mme_form_auto(string $slug, string $title = '', $fields = array(), $settings = array(), bool $fields_only = true): string {
    $slug = sanitize_title($slug);
    if (empty($slug)) {
        return '';
    }

    $posts = get_posts(array(
        'name'             => $slug,
        'post_type'        => 'mme_form',
        'post_status'      => 'any',
        'posts_per_page'   => 1,
        'suppress_filters' => true,
    ));

    $form_id = !empty($posts) ? $posts[0]->ID : 0;

    if (!$form_id) {
        $form_id = wp_insert_post(array(
            'post_title'  => !empty($title) ? $title : 'Form ' . $slug,
            'post_name'   => $slug,
            'post_type'   => 'mme_form',
            'post_status' => 'publish',
        ));

        if (!is_wp_error($form_id) && $form_id) {
            if (is_string($fields)) {
                $fields = json_decode($fields, true) ?: array();
            }
            if (is_array($fields) && !empty($fields)) {
                update_post_meta($form_id, '_mme_form_fields', $fields);
            }

            if (is_string($settings)) {
                $settings = json_decode($settings, true) ?: array();
            }
            update_post_meta($form_id, '_mme_form_settings', wp_parse_args(is_array($settings) ? $settings : array(), MME_Form_Plugin::default_settings()));
        }
    }

    if (!$form_id || is_wp_error($form_id)) {
        return '';
    }

    $shortcode = $fields_only ? 'mme_form_fields' : 'mme_form';
    return do_shortcode('[' . $shortcode . ' id="' . $form_id . '"]');
}
}

