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

        $result = $this->perform_update();
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Lỗi không xác định khi cập nhật.');
        }
    }

    public function perform_update(): array
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem) {
            return array('success' => false, 'error' => 'Không thể khởi tạo WP_Filesystem.');
        }

        $release = $this->latest_release();
        if ($release && version_compare($release['version'], MME_FORM_VERSION, '<=')) {
            return array(
                'success' => true,
                'up_to_date' => true,
                'message' => sprintf('Bạn đang dùng bản mới nhất (%s).', MME_FORM_VERSION),
            );
        }
        // Có GitHub Release thì chỉ cài bản đã phát hành; chưa có release nào mới dùng nhánh main như trước
        $zip_url = $release ? $release['zip_url'] : 'https://github.com/hoangmme/mmeform/archive/refs/heads/main.zip';
        $temp_file = download_url($zip_url, 60);

        if (is_wp_error($temp_file)) {
            return array('success' => false, 'error' => 'Không thể tải từ GitHub: ' . $temp_file->get_error_message());
        }

        $temp_dir = get_temp_dir() . 'mmeform_update_' . time() . '/';
        $wp_filesystem->mkdir($temp_dir);

        $unzip_result = unzip_file($temp_file, $temp_dir);
        @unlink($temp_file);

        if (is_wp_error($unzip_result)) {
            $wp_filesystem->delete($temp_dir, true);
            return array('success' => false, 'error' => 'Lỗi giải nén ZIP: ' . $unzip_result->get_error_message());
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
            return array('success' => false, 'error' => 'Không tìm thấy thư mục plugin trong file ZIP tải về.');
        }

        // Overwrite the current active plugin directory
        $plugin_dir = defined('MME_FORM_DIR') ? MME_FORM_DIR : plugin_dir_path(dirname(__FILE__));
        $copy_result = copy_dir($source_dir, $plugin_dir);

        // Clean up temp
        $wp_filesystem->delete($temp_dir, true);

        if (is_wp_error($copy_result)) {
            return array('success' => false, 'error' => 'Lỗi chép file đè lên plugin: ' . $copy_result->get_error_message());
        } elseif ($copy_result === false) {
            return array('success' => false, 'error' => 'Không có quyền ghi đè thư mục plugin (kiểm tra phân quyền thư mục wp-content/plugins/mmeform).');
        }

        // Để bản mới tự dọn file cũ (copy_dir không xoá file đã bị gỡ khỏi repo) ở lần tải admin kế tiếp
        delete_option('mme_form_installed_version');

        return array(
            'success' => true,
            'message' => $release ? sprintf('Đã cập nhật lên bản %s.', $release['version']) : 'Cập nhật MME Form thành công!',
        );
    }

    /** @return array{version: string, zip_url: string}|null */
    private function latest_release(): ?array
    {
        $response = wp_remote_get('https://api.github.com/repos/hoangmme/mmeform/releases/latest', array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'mme-form-updater'),
        ));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['tag_name']) || empty($data['zipball_url'])) {
            return null;
        }
        return array(
            'version' => ltrim((string) $data['tag_name'], 'vV'),
            'zip_url' => esc_url_raw((string) $data['zipball_url']),
        );
    }
}
}
