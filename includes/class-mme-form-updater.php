<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MME_Form_Updater')) {
final class MME_Form_Updater
{
    private static ?MME_Form_Updater $instance = null;

    public static function instance(): MME_Form_Updater
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        add_action('wp_ajax_mme_form_force_update', array($this, 'handle_force_update'));
    }

    public function handle_force_update()
    {
        check_ajax_referer('mme_form_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Bạn không có quyền quản trị để cập nhật plugin.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem) {
            wp_send_json_error('Không thể khởi tạo WP_Filesystem.');
        }

        $zip_url = 'https://github.com/hoangmme/mmeform/archive/refs/heads/main.zip';
        $temp_file = download_url($zip_url, 60);

        if (is_wp_error($temp_file)) {
            wp_send_json_error('Không thể tải từ GitHub: ' . $temp_file->get_error_message());
        }

        $temp_dir = get_temp_dir() . 'mmeform_update_' . time() . '/';
        $wp_filesystem->mkdir($temp_dir);

        $unzip_result = unzip_file($temp_file, $temp_dir);
        @unlink($temp_file);

        if (is_wp_error($unzip_result)) {
            $wp_filesystem->delete($temp_dir, true);
            wp_send_json_error('Lỗi giải nén ZIP: ' . $unzip_result->get_error_message());
        }

        // GitHub zips contain a root folder: "mmeform-main"
        $source_dir = $temp_dir . 'mmeform-main/';
        if (!file_exists($source_dir)) {
            $files = scandir($temp_dir);
            if (is_array($files)) {
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && is_dir($temp_dir . $file)) {
                        $source_dir = $temp_dir . $file . '/';
                        break;
                    }
                }
            }
        }

        if (!file_exists($source_dir)) {
            $wp_filesystem->delete($temp_dir, true);
            wp_send_json_error('Không tìm thấy thư mục plugin trong file ZIP tải về.');
        }

        // Overwrite the current active plugin directory
        $plugin_dir = defined('MME_FORM_DIR') ? MME_FORM_DIR : plugin_dir_path(dirname(__FILE__));
        $copy_result = copy_dir($source_dir, $plugin_dir);

        // Clean up temp
        $wp_filesystem->delete($temp_dir, true);

        if (is_wp_error($copy_result)) {
            wp_send_json_error('Lỗi chép file đè lên plugin: ' . $copy_result->get_error_message());
        } elseif ($copy_result === false) {
            wp_send_json_error('Không có quyền ghi đè thư mục plugin (kiểm tra phân quyền thư mục wp-content/plugins/mmeform).');
        }

        wp_send_json_success(array('message' => 'Cập nhật MME Form thành công!'));
    }
}
}
