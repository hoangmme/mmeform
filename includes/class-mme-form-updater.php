<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cập nhật MME Form từ GitHub qua luồng cập nhật chuẩn của WordPress (giống MMe Elementor Kit).
 *
 * Header "Version:" của mme-form.php trên nhánh main là phiên bản mới nhất. Khi nó lớn hơn
 * MME_FORM_VERSION, WordPress hiện "Có bản cập nhật" ở trang Plugins, cho bật tự động cập nhật,
 * và Plugin_Upgrader cài file zip của nhánh (thay cả thư mục, nên file đã xoá khỏi repo cũng mất theo).
 */
if (!class_exists('MME_Form_Updater')) {
final class MME_Form_Updater
{
    public const REPO = 'hoangmme/mmeform';
    public const BRANCH = 'main';

    /** Slug cho popup "Xem chi tiết". */
    public const SLUG = 'mme-form';

    /** Site transient lưu thông tin phiên bản trên GitHub. */
    public const CACHE = 'mme_form_update_info';

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
        add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        add_action('upgrader_process_complete', array($this, 'purge_cache'));

        // Nút "Cập nhật từ GitHub" trong admin (giữ tên action cũ để giao diện không phải đổi)
        add_action('wp_ajax_mme_form_force_update', array($this, 'handle_force_update'));
    }

    public function basename(): string
    {
        return plugin_basename(MME_FORM_FILE);
    }

    private function raw_url(string $file): string
    {
        return 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/' . $file;
    }

    public function package_url(): string
    {
        return 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
    }

    public function repo_url(): string
    {
        return 'https://github.com/' . self::REPO;
    }

    /**
     * Phiên bản mới nhất trên GitHub, cache 6 giờ (1 giờ nếu lỗi).
     *
     * @param bool $force Bỏ qua cache (và cache ~5 phút của raw.githubusercontent.com).
     * @return array{version?: string, requires?: string, requires_php?: string, changelog?: string, error?: string, checked: int}
     */
    public function remote_info(bool $force = false): array
    {
        $cached = get_site_transient(self::CACHE);
        if (!$force && is_array($cached)) {
            return $cached;
        }

        $bust = $force ? '?t=' . time() : '';
        $response = wp_remote_get($this->raw_url('mme-form.php') . $bust, array('timeout' => 15));
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $headers = $code === 200 ? $this->parse_headers((string) wp_remote_retrieve_body($response)) : array();

        if (empty($headers['Version'])) {
            if (is_wp_error($response)) {
                $error = $response->get_error_message();
            } elseif ($code === 404) {
                $error = 'Không tìm thấy repo hoặc nhánh trên GitHub. Repo có đang để public không?';
            } else {
                $error = sprintf('Không đọc được phiên bản mới nhất từ GitHub (HTTP %d).', $code);
            }
            $info = array('error' => $error, 'checked' => time());
            set_site_transient(self::CACHE, $info, HOUR_IN_SECONDS);
            return $info;
        }

        $readme = wp_remote_get($this->raw_url('readme.txt') . $bust, array('timeout' => 15));

        $info = array(
            'version' => $headers['Version'],
            'requires' => $headers['Requires at least'] ?? '',
            'requires_php' => $headers['Requires PHP'] ?? '',
            'changelog' => is_wp_error($readme) ? '' : $this->parse_changelog((string) wp_remote_retrieve_body($readme)),
            'checked' => time(),
        );
        set_site_transient(self::CACHE, $info, 6 * HOUR_IN_SECONDS);
        return $info;
    }

    /**
     * Đọc các dòng "Tên: giá trị" trong header plugin. Phiên bản chỉ lấy phần số
     * (header cũ có dạng "0.6.4 (2026-11-09 19:30)").
     */
    public function parse_headers(string $contents): array
    {
        $headers = array();
        foreach (array('Version', 'Requires at least', 'Requires PHP') as $name) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $contents, $match)) {
                $headers[$name] = trim($match[1]);
            }
        }
        if (isset($headers['Version']) && preg_match('/\d+(?:\.\d+)+/', $headers['Version'], $match)) {
            $headers['Version'] = $match[0];
        }
        return $headers;
    }

    /** Mục "== Changelog ==" của readme.txt thành HTML đơn giản. */
    public function parse_changelog(string $readme): string
    {
        $start = strpos($readme, '== Changelog ==');
        if ($start === false) {
            return '';
        }

        $html = '';
        $in_list = false;
        foreach (explode("\n", substr($readme, $start + strlen('== Changelog =='))) as $line) {
            $line = trim($line);
            if (preg_match('/^==[^=]/', $line)) {
                break;
            }
            if (strpos($line, '* ') === 0) {
                if (!$in_list) {
                    $html .= '<ul>';
                    $in_list = true;
                }
                $html .= '<li>' . esc_html(substr($line, 2)) . '</li>';
                continue;
            }
            if ($in_list) {
                $html .= '</ul>';
                $in_list = false;
            }
            if (preg_match('/^=\s*(.+?)\s*=$/', $line, $match)) {
                $html .= '<h4>' . esc_html($match[1]) . '</h4>';
            }
        }
        return $in_list ? $html . '</ul>' : $html;
    }

    /**
     * GitHub có bản mới hơn bản đang cài không.
     *
     * @param bool $fetch false: chỉ xem cache, không gọi GitHub.
     */
    public function has_update(bool $fetch = true): bool
    {
        $info = $fetch ? $this->remote_info() : get_site_transient(self::CACHE);
        return !empty($info['version']) && version_compare($info['version'], MME_FORM_VERSION, '>');
    }

    /** Đưa MME Form vào danh sách cập nhật của WordPress. */
    public function inject_update($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $info = $this->remote_info();
        if (empty($info['version'])) {
            return $transient;
        }

        $item = (object) array(
            'id' => 'github.com/' . self::REPO,
            'slug' => self::SLUG,
            'plugin' => $this->basename(),
            'new_version' => $info['version'],
            'url' => $this->repo_url(),
            'package' => $this->package_url(),
            'requires' => $info['requires'],
            'requires_php' => $info['requires_php'],
        );

        if (version_compare($info['version'], MME_FORM_VERSION, '>')) {
            $transient->response[$this->basename()] = $item;
        } else {
            $transient->no_update[$this->basename()] = $item;
        }
        return $transient;
    }

    /** Dữ liệu cho popup "Xem chi tiết". */
    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }

        $info = $this->remote_info();
        return (object) array(
            'name' => 'MME Form',
            'slug' => self::SLUG,
            'version' => $info['version'] ?? MME_FORM_VERSION,
            'author' => '<a href="https://mme.vn">MME</a>',
            'homepage' => 'https://mme.vn',
            'requires' => $info['requires'] ?? '',
            'requires_php' => $info['requires_php'] ?? '',
            'download_link' => $this->package_url(),
            'sections' => array(
                'description' => 'Form thu lead gọn nhẹ: nhúng ngoài website, lưu nguồn URL/UTM, chatbot, Google Sheets, Twenty CRM, Pancake CRM, xác minh SĐT qua Zalo.',
                'changelog' => $info['changelog'] ?? '',
            ),
        );
    }

    /**
     * Zip của GitHub giải nén ra "mmeform-main/". Đổi về đúng tên thư mục đang cài,
     * nếu không WordPress sẽ coi bản cập nhật là một plugin mới (sinh thư mục trùng).
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = array())
    {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename()) {
            return $source;
        }

        $desired = trailingslashit($remote_source) . dirname($this->basename()) . '/';
        if (trailingslashit($source) === $desired) {
            return $source;
        }

        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->move($source, $desired, true)) {
            return $desired;
        }
        return new WP_Error('mme_form_rename_failed', 'Không chuẩn bị được thư mục cập nhật MME Form.');
    }

    public function purge_cache(): void
    {
        delete_site_transient(self::CACHE);
    }

    /**
     * Cài bản mới nhất từ GitHub ngay (nút "Cập nhật từ GitHub" và ability MCP).
     *
     * @return array{success: bool, up_to_date?: bool, from?: string, to?: string, message?: string, error?: string}
     */
    public function upgrade_now(): array
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $info = $this->remote_info(true);
        if (!empty($info['error'])) {
            return array('success' => false, 'error' => $info['error']);
        }
        if (!version_compare($info['version'], MME_FORM_VERSION, '>')) {
            return array(
                'success' => true,
                'up_to_date' => true,
                'from' => MME_FORM_VERSION,
                'to' => MME_FORM_VERSION,
                'message' => sprintf('Bạn đang dùng bản mới nhất (%s).', MME_FORM_VERSION),
            );
        }
        if (get_filesystem_method() !== 'direct') {
            return array('success' => false, 'error' => 'Máy chủ cần thông tin FTP để ghi file plugin. Hãy cập nhật trong trang Plugins.');
        }

        // Đưa gói cập nhật vào danh sách của WordPress (inject_update chạy khi lưu transient)
        $transient = get_site_transient('update_plugins');
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        if (empty($transient->checked) || !is_array($transient->checked)) {
            $transient->checked = array();
        }
        $transient->checked[$this->basename()] = MME_FORM_VERSION;
        set_site_transient('update_plugins', $transient);

        $was_active = is_plugin_active($this->basename());
        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($this->basename());

        $error = null;
        if (is_wp_error($result)) {
            $error = $result;
        } elseif (is_wp_error($skin->result)) {
            $error = $skin->result;
        } elseif ($skin->get_errors()->has_errors()) {
            $error = $skin->get_errors();
        }
        if ($error) {
            return array('success' => false, 'error' => $error->get_error_message());
        }
        if (!$result) {
            return array('success' => false, 'error' => 'Cập nhật không chạy được.');
        }

        // WordPress cũ để plugin ở trạng thái tắt sau khi nâng cấp ngoài màn hình cập nhật
        if ($was_active && !is_plugin_active($this->basename())) {
            activate_plugin($this->basename(), '', false, true);
        }

        $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->basename(), false, false);
        $to = $this->parse_headers('Version: ' . $data['Version'])['Version'] ?? $data['Version'];
        return array(
            'success' => true,
            'from' => MME_FORM_VERSION,
            'to' => $to,
            'message' => sprintf('Đã cập nhật lên bản %s.', $to),
        );
    }

    public function handle_force_update(): void
    {
        check_ajax_referer('mme_form_admin_nonce', 'nonce');

        if (!current_user_can('update_plugins')) {
            wp_send_json_error('Bạn không có quyền cập nhật plugin.');
        }

        $result = $this->upgrade_now();
        if ($result['success']) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result['error'] ?? 'Lỗi không xác định khi cập nhật.');
    }
}
}
