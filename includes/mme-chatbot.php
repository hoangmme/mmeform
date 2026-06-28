<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('MME_CHATBOT_VERSION', '1.2.0');
define('MME_CHATBOT_CONTENT_DIR', 'mme-content');

// Capture the actual template used on frontend pages so the iframe can send
// precise context to chat.mme.vn.
add_filter('template_include', 'mme_chatbot_capture_template_path', 999);
function mme_chatbot_capture_template_path($template) {
    $theme_dir = wp_normalize_path(get_stylesheet_directory());
    $template_path = wp_normalize_path($template);
    if (strpos($template_path, $theme_dir . '/') === 0) {
        $GLOBALS['mme_chatbot_current_template_file'] = ltrim(substr($template_path, strlen($theme_dir)), '/');
    } else {
        $GLOBALS['mme_chatbot_current_template_file'] = basename($template);
    }
    return $template;
}

// --- MME Editable Template Standard helpers ---

if (!function_exists('mme_content')) {
    function mme_content($path, $fallback = '', $file = '') {
        $data = mme_chatbot_content_data($file);
        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $fallback;
            }
            $value = $value[$key];
        }
        return is_scalar($value) ? (string) $value : $fallback;
    }
}

if (!function_exists('mme_text')) {
    function mme_text($path, $fallback = '', $file = '') {
        return esc_html(mme_content($path, $fallback, $file));
    }
}

if (!function_exists('mme_url')) {
    function mme_url($path, $fallback = '', $file = '') {
        return esc_url(mme_content($path, $fallback, $file));
    }
}

if (!function_exists('mme_image')) {
    function mme_image($path, $fallback = '', $file = '') {
        return esc_url(mme_content($path, $fallback, $file));
    }
}

if (!function_exists('mme_html')) {
    function mme_html($path, $fallback = '', $file = '') {
        return wp_kses_post(mme_content($path, $fallback, $file));
    }
}

function mme_chatbot_content_data($file = '') {
    static $cache = [];
    $cache_key = $file ?: '__merged__';
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    if ($file) {
        $cache[$cache_key] = mme_chatbot_read_json_file($file);
        return $cache[$cache_key];
    }

    $merged = [];
    $content_dir = trailingslashit(get_stylesheet_directory()) . MME_CHATBOT_CONTENT_DIR;
    $default_file = $content_dir . '/content.json';
    if (file_exists($default_file)) {
        $merged = array_replace_recursive($merged, mme_chatbot_decode_json_file($default_file));
    }

    foreach (glob($content_dir . '/*.json') ?: [] as $json_file) {
        if (basename($json_file) === 'manifest.json' || basename($json_file) === 'content.json') {
            continue;
        }
        $merged = array_replace_recursive($merged, mme_chatbot_decode_json_file($json_file));
    }

    foreach (glob($content_dir . '/pages/*.json') ?: [] as $json_file) {
        $merged = array_replace_recursive($merged, mme_chatbot_decode_json_file($json_file));
    }

    $cache[$cache_key] = $merged;
    return $merged;
}

function mme_chatbot_read_json_file($relative_file) {
    $relative_file = ltrim(str_replace(['..', '\\'], ['', '/'], $relative_file), '/');
    $full_path = trailingslashit(get_stylesheet_directory()) . $relative_file;
    return mme_chatbot_decode_json_file($full_path);
}

function mme_chatbot_decode_json_file($full_path) {
    if (!file_exists($full_path)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($full_path), true);
    return is_array($decoded) ? $decoded : [];
}

function mme_chatbot_manifest_path() {
    return trailingslashit(get_stylesheet_directory()) . MME_CHATBOT_CONTENT_DIR . '/manifest.json';
}

function mme_chatbot_get_manifest() {
    return mme_chatbot_decode_json_file(mme_chatbot_manifest_path());
}

function mme_chatbot_template_exists($template_file) {
    if (!$template_file) {
        return false;
    }
    return file_exists(trailingslashit(get_stylesheet_directory()) . ltrim($template_file, '/'));
}

function mme_chatbot_relative_theme_file_exists($relative_file) {
    if (!$relative_file) {
        return false;
    }
    return file_exists(trailingslashit(get_stylesheet_directory()) . ltrim($relative_file, '/'));
}

function mme_chatbot_get_acf_field_names($post_id) {
    if (!$post_id || !function_exists('get_field_objects')) {
        return [];
    }
    $objects = get_field_objects($post_id);
    if (!is_array($objects)) {
        return [];
    }
    $names = [];
    foreach ($objects as $field) {
        if (!empty($field['name'])) {
            $names[] = $field['name'];
        }
    }
    return array_values(array_unique($names));
}

function mme_chatbot_current_page_context($mode = 'editor') {
    $post_id = get_queried_object_id();
    if (is_admin() && isset($_GET['post'])) {
        $post_id = absint($_GET['post']);
    }

    $template_slug = $post_id ? get_page_template_slug($post_id) : '';
    $template_file = $GLOBALS['mme_chatbot_current_template_file'] ?? $template_slug;
    if (!$template_file && $post_id) {
        $template_file = get_post_type($post_id) === 'page' ? 'page.php' : 'single.php';
    }

    $acf_fields = mme_chatbot_get_acf_field_names($post_id);

    return [
        'current_url' => is_admin() ? admin_url() : home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? '')),
        'site_url' => home_url('/'),
        'title' => $post_id ? get_the_title($post_id) : wp_get_document_title(),
        'post_id' => $post_id ?: null,
        'post_type' => $post_id ? get_post_type($post_id) : null,
        'is_front_page' => !is_admin() && is_front_page(),
        'is_home' => !is_admin() && is_home(),
        'is_singular' => !is_admin() && is_singular(),
        'template_slug' => $template_slug ?: '',
        'template_file' => $template_file ?: '',
        'theme' => wp_get_theme()->get_stylesheet(),
        'admin_mode' => $mode,
        'acf_detected' => !empty($acf_fields),
        'acf_fields' => $acf_fields,
        'manifest_exists' => file_exists(mme_chatbot_manifest_path()),
        'content_dir' => MME_CHATBOT_CONTENT_DIR,
    ];
}

// 1. ĐĂNG KÝ TRANG CÀI ĐẶT
add_action('admin_menu', 'mme_chatbot_add_admin_menu');
function mme_chatbot_add_admin_menu() {
    add_submenu_page('edit.php?post_type=mme_form', 'Cài đặt chung', 'Cài đặt chung', 'manage_options', 'mme_chatbot', 'mme_chatbot_options_page');
}

function mme_chatbot_options_page() {
    ?>
    <div class="wrap">
        <h2>MME Chatbot Settings</h2>
        <form action="options.php" method="post">
            <?php
            settings_fields('mme_chatbot_options');
            do_settings_sections('mme_chatbot');
            submit_button();
            ?>
        </form>
        
        <hr style="margin: 30px 0;">
        <h2>Phát triển & Cập nhật</h2>
        <p>Tính năng này giúp bạn đồng bộ (pull) mã nguồn mới nhất của plugin <strong>MME Form</strong> từ GitHub về máy chủ ngay lập tức.</p>
        <button type="button" id="mme-form-git-pull-btn" class="button button-secondary">Đồng bộ code MME Form từ GitHub (Git Pull)</button>
        <div id="mme-form-git-pull-result" style="margin-top: 15px; padding: 15px; background: #1e1e1e; color: #00ff00; font-family: monospace; border-radius: 4px; display: none; white-space: pre-wrap;"></div>

        <script>
        document.getElementById('mme-form-git-pull-btn').addEventListener('click', function() {
            var btn = this;
            var resultDiv = document.getElementById('mme-form-git-pull-result');
            
            btn.disabled = true;
            btn.textContent = 'Đang đồng bộ...';
            resultDiv.style.display = 'block';
            resultDiv.textContent = 'Đang chạy git pull...';

            var formData = new FormData();
            formData.append('action', 'mme_form_git_pull');
            formData.append('_ajax_nonce', '<?php echo wp_create_nonce('mme_form_git_pull_nonce'); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.textContent = data.data;
                } else {
                    resultDiv.textContent = 'Lỗi: ' + (data.data || 'Không rõ nguyên nhân');
                    resultDiv.style.color = '#ff5555';
                }
            })
            .catch(error => {
                resultDiv.textContent = 'Lỗi kết nối: ' + error;
                resultDiv.style.color = '#ff5555';
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = 'Đồng bộ code MME Form từ GitHub (Git Pull)';
            });
        });
        </script>

        <?php mme_chatbot_render_content_status(); ?>
    </div>
    <?php
}

add_action('admin_init', 'mme_chatbot_settings_init');
function mme_chatbot_settings_init() {
    register_setting('mme_chatbot_options', 'mme_chatbot_slug', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('mme_chatbot_options', 'mme_chatbot_token', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('mme_chatbot_options', 'mme_chatbot_base_url', ['sanitize_callback' => 'esc_url_raw']);
    register_setting('mme_chatbot_options', 'mme_chatbot_github_repo', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('mme_chatbot_options', 'mme_chatbot_github_branch', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('mme_chatbot_options', 'mme_chatbot_github_token', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('mme_chatbot_options', 'mme_chatbot_sync_token', ['sanitize_callback' => 'sanitize_text_field']);
    
    add_settings_section('mme_chatbot_main', 'Cấu hình kết nối API', null, 'mme_chatbot');
    add_settings_section('mme_chatbot_sync', 'Content Sync Webhook', 'mme_chatbot_sync_section_render', 'mme_chatbot');
    
    add_settings_field('mme_chatbot_base_url', 'Chatbot Base URL', 'mme_chatbot_base_url_render', 'mme_chatbot', 'mme_chatbot_main');
    add_settings_field('mme_chatbot_slug', 'Chatbot Slug', 'mme_chatbot_slug_render', 'mme_chatbot', 'mme_chatbot_main');
    add_settings_field('mme_chatbot_token', 'Admin Token (Secret)', 'mme_chatbot_token_render', 'mme_chatbot', 'mme_chatbot_main');
    add_settings_field('mme_chatbot_github_repo', 'GitHub Repo', 'mme_chatbot_github_repo_render', 'mme_chatbot', 'mme_chatbot_sync');
    add_settings_field('mme_chatbot_github_branch', 'GitHub Branch', 'mme_chatbot_github_branch_render', 'mme_chatbot', 'mme_chatbot_sync');
    add_settings_field('mme_chatbot_github_token', 'GitHub Token', 'mme_chatbot_github_token_render', 'mme_chatbot', 'mme_chatbot_sync');
    add_settings_field('mme_chatbot_sync_token', 'Sync Webhook Secret', 'mme_chatbot_sync_token_render', 'mme_chatbot', 'mme_chatbot_sync');
    add_settings_field('mme_chatbot_sync_url', 'Deploy Webhook URL', 'mme_chatbot_sync_url_render', 'mme_chatbot', 'mme_chatbot_sync');
}

function mme_chatbot_base_url_render() {
    $val = get_option('mme_chatbot_base_url', 'https://chat.mme.vn');
    echo "<input type='url' name='mme_chatbot_base_url' value='" . esc_attr($val) . "' style='width:300px;' placeholder='https://...' />";
    echo "<p class='description'>Đường dẫn gốc tới server MME Chatbot của bạn (VD: https://chat.mme.vn).</p>";
}

function mme_chatbot_slug_render() {
    $val = get_option('mme_chatbot_slug');
    echo "<input type='text' name='mme_chatbot_slug' value='" . esc_attr($val) . "' style='width:300px;' placeholder='VD: khach-a' />";
}
function mme_chatbot_token_render() {
    $val = get_option('mme_chatbot_token');
    echo "<input type='password' name='mme_chatbot_token' value='" . esc_attr($val) . "' style='width:300px;' placeholder='MME Admin Token' />";
    echo "<p class='description'>Token này dùng để mở khóa tính năng AI Editor & SEO Writer cho Admin.</p>";
}

function mme_chatbot_sync_section_render() {
    echo "<p>Webhook này cho phép <code>chat.mme.vn</code> sau khi sửa JSON trên GitHub sẽ gọi về website, plugin tải lại <code>mme-content/*.json</code> và chạy sync nếu theme có <code>mme-content/sync-to-wp.php</code>.</p>";
}

function mme_chatbot_github_repo_render() {
    $val = get_option('mme_chatbot_github_repo');
    echo "<input type='text' name='mme_chatbot_github_repo' value='" . esc_attr($val) . "' style='width:300px;' placeholder='hoangmme/aptrubber' />";
    echo "<p class='description'>Repo theme chứa thư mục <code>mme-content</code>.</p>";
}

function mme_chatbot_github_branch_render() {
    $val = get_option('mme_chatbot_github_branch', 'main');
    echo "<input type='text' name='mme_chatbot_github_branch' value='" . esc_attr($val) . "' style='width:300px;' placeholder='main' />";
}

function mme_chatbot_github_token_render() {
    $val = get_option('mme_chatbot_github_token');
    echo "<input type='password' name='mme_chatbot_github_token' value='" . esc_attr($val) . "' style='width:300px;' placeholder='Fine-grained PAT nếu repo private' autocomplete='off' />";
    echo "<p class='description'>Repo private cần token có quyền Contents: Read. Repo public có thể để trống.</p>";
}

function mme_chatbot_sync_token_render() {
    $val = get_option('mme_chatbot_sync_token');
    echo "<input type='password' name='mme_chatbot_sync_token' value='" . esc_attr($val) . "' style='width:300px;' placeholder='Chuỗi bí mật webhook' autocomplete='off' />";
    echo "<p class='description'>Tạo chuỗi dài ngẫu nhiên, ví dụ: <code>" . esc_html(wp_generate_password(40, false, false)) . "</code></p>";
}

function mme_chatbot_sync_url_render() {
    $secret = get_option('mme_chatbot_sync_token');
    if (!$secret) {
        echo "<p class='description'>Nhập và lưu <strong>Sync Webhook Secret</strong> trước để tạo URL.</p>";
        return;
    }
    $url = add_query_arg('token', $secret, rest_url('mme-chatbot/v1/sync-content'));
    echo "<code style='display:inline-block;max-width:100%;padding:8px;background:#f6f7f7;word-break:break-all;'>" . esc_html($url) . "</code>";
    echo "<p class='description'>Dán URL này vào <strong>Deploy webhook URL</strong> trong admin <code>chat.mme.vn</code> của chatbot/tenant tương ứng.</p>";
}

function mme_chatbot_render_content_status() {
    $manifest_path = mme_chatbot_manifest_path();
    $manifest = mme_chatbot_get_manifest();
    $docs_path = dirname(plugin_dir_path(__FILE__)) . '/docs/MME_EDITABLE_TEMPLATE_STANDARD.md';
    ?>
    <hr>
    <h2>MME AI Editable Template Status</h2>
    <p>
        Chuẩn template: <code>mme-content/manifest.json</code> + JSON content + helper
        <code>mme_text()</code>, <code>mme_url()</code>, <code>mme_image()</code>, <code>mme_html()</code>.
    </p>
    <p>
        File hướng dẫn local:
        <code><?php echo esc_html($docs_path); ?></code>
    </p>
    <table class="widefat striped" style="max-width: 980px;">
        <tbody>
            <tr>
                <th style="width: 220px;">Manifest</th>
                <td>
                    <?php if (file_exists($manifest_path)): ?>
                        <span style="color:#008a20;font-weight:600;">OK</span>
                        <code><?php echo esc_html(str_replace(get_stylesheet_directory() . '/', '', $manifest_path)); ?></code>
                    <?php else: ?>
                        <span style="color:#b32d2e;font-weight:600;">Missing</span>
                        <code>mme-content/manifest.json</code>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>JSON valid</th>
                <td>
                    <?php echo !empty($manifest) ? '<span style="color:#008a20;font-weight:600;">OK</span>' : '<span style="color:#b32d2e;font-weight:600;">No manifest data</span>'; ?>
                </td>
            </tr>
        </tbody>
    </table>
    <?php if (!empty($manifest['pages']) && is_array($manifest['pages'])): ?>
        <h3>Pages hỗ trợ AI edit</h3>
        <table class="widefat striped" style="max-width: 980px;">
            <thead>
                <tr>
                    <th>Page key</th>
                    <th>Template</th>
                    <th>Editable file</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($manifest['pages'] as $page_key => $page): ?>
                    <?php
                    $templates = $page['templates'] ?? [$page['template'] ?? ''];
                    $template_status = true;
                    foreach ($templates as $template_file) {
                        if ($template_file && !mme_chatbot_template_exists($template_file)) {
                            $template_status = false;
                        }
                    }
                    $editable_file = $page['editable_file'] ?? ($manifest['default_editable_file'] ?? '');
                    $editable_exists = mme_chatbot_relative_theme_file_exists($editable_file);
                    $ok = $template_status && $editable_exists;
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($page_key); ?></code></td>
                        <td><code><?php echo esc_html(implode(', ', array_filter($templates))); ?></code></td>
                        <td><code><?php echo esc_html($editable_file); ?></code></td>
                        <td>
                            <?php if ($ok): ?>
                                <span style="color:#008a20;font-weight:600;">OK</span>
                            <?php else: ?>
                                <span style="color:#b32d2e;font-weight:600;">Check template/content file</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

// 2. REST WEBHOOK: SYNC mme-content JSON FROM GITHUB TO CURRENT THEME
add_action('rest_api_init', 'mme_chatbot_register_rest_routes');
function mme_chatbot_register_rest_routes() {
    register_rest_route('mme-chatbot/v1', '/sync-content', [
        'methods' => ['GET', 'POST'],
        'callback' => 'mme_chatbot_rest_sync_content',
        'permission_callback' => '__return_true',
    ]);
}

function mme_chatbot_rest_sync_content(WP_REST_Request $request) {
    $configured_secret = (string) get_option('mme_chatbot_sync_token');
    $request_secret = (string) ($request->get_param('token') ?: $request->get_header('x-mme-sync-token'));

    if (!$configured_secret || !$request_secret || !hash_equals($configured_secret, $request_secret)) {
        return new WP_Error('mme_chatbot_forbidden', 'Invalid sync webhook token.', ['status' => 403]);
    }

    $repo = trim((string) get_option('mme_chatbot_github_repo'));
    $branch = trim((string) get_option('mme_chatbot_github_branch', 'main')) ?: 'main';

    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
        return new WP_Error('mme_chatbot_bad_repo', 'GitHub Repo must use owner/repo format.', ['status' => 400]);
    }

    $tree = mme_chatbot_github_fetch_tree($repo, $branch);
    if (is_wp_error($tree)) {
        return $tree;
    }

    $json_items = [];
    foreach (($tree['tree'] ?? []) as $item) {
        $path = $item['path'] ?? '';
        if (($item['type'] ?? '') !== 'blob') {
            continue;
        }
        if (strpos($path, MME_CHATBOT_CONTENT_DIR . '/') !== 0) {
            continue;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            continue;
        }
        $json_items[] = $item;
    }

    if (!$json_items) {
        return new WP_Error('mme_chatbot_no_json', 'No mme-content JSON files found in GitHub repo.', ['status' => 404]);
    }

    $theme_dir = trailingslashit(get_stylesheet_directory());
    $updated_files = [];

    foreach ($json_items as $item) {
        $path = $item['path'];
        $content = mme_chatbot_github_fetch_blob_content($repo, $item['sha']);
        if (is_wp_error($content)) {
            return $content;
        }

        json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'mme_chatbot_invalid_json',
                sprintf('Invalid JSON from GitHub: %s (%s)', $path, json_last_error_msg()),
                ['status' => 422]
            );
        }

        $target_path = $theme_dir . $path;
        $target_dir = dirname($target_path);
        if (!wp_mkdir_p($target_dir)) {
            return new WP_Error('mme_chatbot_mkdir_failed', sprintf('Cannot create directory: %s', $target_dir), ['status' => 500]);
        }

        $written = file_put_contents($target_path, $content);
        if ($written === false) {
            return new WP_Error('mme_chatbot_write_failed', sprintf('Cannot write file: %s', $path), ['status' => 500]);
        }

        $updated_files[] = $path;
    }

    // Clear helper cache by forcing a fresh request next page load; current request
    // may still have static cache from earlier theme reads, which is fine for sync.
    $sync_output = '';
    $sync_script = $theme_dir . MME_CHATBOT_CONTENT_DIR . '/sync-to-wp.php';
    if (file_exists($sync_script)) {
        ob_start();
        include $sync_script;
        $sync_output = trim((string) ob_get_clean());
    }

    return rest_ensure_response([
        'status' => 'ok',
        'repo' => $repo,
        'branch' => $branch,
        'updated_files' => $updated_files,
        'updated_count' => count($updated_files),
        'sync_script' => file_exists($sync_script) ? MME_CHATBOT_CONTENT_DIR . '/sync-to-wp.php' : null,
        'sync_output' => substr($sync_output, 0, 2000),
    ]);
}

function mme_chatbot_github_repo_path($repo) {
    [$owner, $name] = explode('/', $repo, 2);
    return rawurlencode($owner) . '/' . rawurlencode($name);
}

function mme_chatbot_github_headers() {
    $headers = [
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'MME-Chatbot-Plugin/' . MME_CHATBOT_VERSION,
    ];
    $token = trim((string) get_option('mme_chatbot_github_token'));
    if ($token) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    return $headers;
}

function mme_chatbot_github_get_json($url) {
    $response = wp_remote_get($url, [
        'timeout' => 30,
        'headers' => mme_chatbot_github_headers(),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code < 200 || $code >= 300) {
        $message = is_array($data) && !empty($data['message']) ? $data['message'] : $body;
        return new WP_Error('mme_chatbot_github_error', 'GitHub API error: ' . $message, ['status' => 502]);
    }

    if (!is_array($data)) {
        return new WP_Error('mme_chatbot_github_bad_json', 'GitHub API returned invalid JSON.', ['status' => 502]);
    }

    return $data;
}

function mme_chatbot_github_fetch_tree($repo, $branch) {
    $repo_path = mme_chatbot_github_repo_path($repo);
    $branch_url = 'https://api.github.com/repos/' . $repo_path . '/branches/' . rawurlencode($branch);
    $branch_data = mme_chatbot_github_get_json($branch_url);
    if (is_wp_error($branch_data)) {
        return $branch_data;
    }

    $tree_sha = $branch_data['commit']['commit']['tree']['sha'] ?? '';
    if (!$tree_sha) {
        return new WP_Error('mme_chatbot_github_no_tree', 'Cannot resolve GitHub branch tree SHA.', ['status' => 502]);
    }

    $tree_url = 'https://api.github.com/repos/' . $repo_path . '/git/trees/' . rawurlencode($tree_sha) . '?recursive=1';
    return mme_chatbot_github_get_json($tree_url);
}

function mme_chatbot_github_fetch_blob_content($repo, $sha) {
    $blob_url = 'https://api.github.com/repos/' . mme_chatbot_github_repo_path($repo) . '/git/blobs/' . rawurlencode($sha);
    $blob = mme_chatbot_github_get_json($blob_url);
    if (is_wp_error($blob)) {
        return $blob;
    }

    if (($blob['encoding'] ?? '') !== 'base64' || empty($blob['content'])) {
        return new WP_Error('mme_chatbot_github_blob_encoding', 'GitHub blob is not base64 encoded.', ['status' => 502]);
    }

    $decoded = base64_decode(str_replace(["\n", "\r"], '', $blob['content']), true);
    if ($decoded === false) {
        return new WP_Error('mme_chatbot_github_blob_decode', 'Cannot decode GitHub blob content.', ['status' => 502]);
    }

    return $decoded;
}

// 3. NHÚNG CHATBOT VÀO FRONTEND
add_action('wp_footer', 'mme_chatbot_render_frontend');
function mme_chatbot_render_frontend() {
    $slug = get_option('mme_chatbot_slug');
    $token = get_option('mme_chatbot_token');
    $base_url = get_option('mme_chatbot_base_url', 'https://chat.mme.vn');
    $base_url = rtrim($base_url, '/'); // Xóa dấu gạch chéo thừa
    $parent_origin = home_url('/');
    
    if (empty($slug)) return;
    
    // Luôn nhúng iframe
    echo '<iframe id="mme-ai-support" src="' . esc_url($base_url . '/embed/' . $slug . '?launcher=1&parentOrigin=' . rawurlencode($parent_origin)) . '" style="position:fixed;left:20px;bottom:20px;z-index:2147483646;width:72px;height:72px;border:0;border-radius:20px;background:transparent" allow="clipboard-write"></iframe>';
    
    // Script xử lý resize (áp dụng chung)
    echo '<script>
      (function () {
        var frame = document.getElementById("mme-ai-support");
        if (!frame) return;
        window.addEventListener("message", function (event) {
          var data = event.data || {};
          if (data.type !== "MME_CHAT_RESIZE" || data.slug !== "' . esc_js($slug) . '") return;
          frame.style.width = data.open ? "min(420px, calc(100vw - 32px))" : "72px";
          frame.style.height = data.open ? "min(640px, calc(100vh - 40px))" : "72px";
          frame.style.borderRadius = data.open ? "18px" : "20px";
        });
      })();
    </script>';

    // NẾU LÀ ADMIN -> Kích hoạt Admin Mode (Website Editor)
    if (is_user_logged_in() && current_user_can('administrator') && !empty($token)) {
        $page_context = wp_json_encode(mme_chatbot_current_page_context('editor'));
        echo '<script>
        window.addEventListener("load", function() {
            var frame = document.getElementById("mme-ai-support");
            if (frame && frame.contentWindow) {
                // Đợi 2 giây cho iframe load xong
                setTimeout(() => {
                    frame.contentWindow.postMessage({
                        type: "MME_ADMIN_INIT",
                        mode: "editor",
                        token: "' . esc_js($token) . '",
                        pageContext: ' . $page_context . '
                    }, "' . esc_js($base_url) . '");
                }, 2000);
            }
        });
        </script>';
    }
}

// 4. NHÚNG CHATBOT VÀO BACKEND (CHỈ TRANG EDIT POST/PAGE CÓ RANKMATH)
add_action('admin_footer', 'mme_chatbot_render_backend');
function mme_chatbot_render_backend() {
    $slug = get_option('mme_chatbot_slug');
    $token = get_option('mme_chatbot_token');
    $base_url = get_option('mme_chatbot_base_url', 'https://chat.mme.vn');
    $base_url = rtrim($base_url, '/');

    if (empty($slug) || empty($token)) return;

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->base, ['post'])) {
        return; // Không phải trang chỉnh sửa post/page/product
    }

    // Kiểm tra RankMath có active không
    if (!is_plugin_active('seo-by-rank-math/rank-math.php')) {
        return; // Yêu cầu chỉ bật khi có RankMath
    }


    // Nhúng iframe vào góc trái backend giống như frontend
    echo '<iframe id="mme-ai-support-admin" src="' . esc_url($base_url . '/embed/' . $slug . '?launcher=1&parentOrigin=' . rawurlencode(admin_url())) . '" style="position:fixed;left:20px;bottom:20px;z-index:999999;width:72px;height:72px;border:0;border-radius:20px;background:transparent;" allow="clipboard-write"></iframe>';
    
    // Script gửi token SEO Writer và xử lý inject content
    $page_context = wp_json_encode(mme_chatbot_current_page_context('seo_writer'));
    echo '<script>
      (function () {
        var frame = document.getElementById("mme-ai-support-admin");
        if (!frame) return;
        
        // Handle Resize
        window.addEventListener("message", function (event) {
          var data = event.data || {};
          if (data.type === "MME_CHAT_RESIZE" && data.slug === "' . esc_js($slug) . '") {
              frame.style.width = data.open ? "420px" : "72px";
              frame.style.height = data.open ? "640px" : "72px";
              frame.style.borderRadius = data.open ? "18px" : "20px";
          }
          
          // Lắng nghe payload JSON từ AI
          if (data.type === "MME_INJECT_CONTENT" && data.payload) {
              const payload = data.payload;
              
              // 1. Bắn nội dung vào Classic Editor
              if (payload.title) {
                  const titleInput = document.getElementById("title");
                  if (titleInput) {
                      titleInput.value = payload.title;
                      titleInput.dispatchEvent(new Event("input", { bubbles: true }));
                  }
              }
              if (payload.content) {
                  if (typeof tinyMCE !== "undefined" && tinyMCE.get("content")) {
                      tinyMCE.get("content").setContent(payload.content);
                  } else {
                      const contentInput = document.getElementById("content");
                      if (contentInput) {
                          contentInput.value = payload.content;
                          contentInput.dispatchEvent(new Event("input", { bubbles: true }));
                      }
                  }
              }
              
              // 2. Điền RankMath Focus Keyword
              const rankMathKeywordInput = document.getElementById("rank_math_focus_keyword");
              if (rankMathKeywordInput && payload.focus_keyword) {
                  rankMathKeywordInput.value = payload.focus_keyword;
                  // Trigger input event to make RankMath save it
                  rankMathKeywordInput.dispatchEvent(new Event("input", { bubbles: true }));
              }
              
              // 3. Điền RankMath Meta Description
              // RankMath UI dùng React, thường ô description nằm trong snippet editor
              const rankMathDescInput = document.querySelector(".rank-math-snippet-description-input");
              if (rankMathDescInput && payload.meta_description) {
                  rankMathDescInput.value = payload.meta_description;
                  rankMathDescInput.dispatchEvent(new Event("input", { bubbles: true }));
              }
              
              alert("AI đã điền xong nội dung bài viết và SEO Meta! Vui lòng kiểm tra lại trước khi Lưu/Đăng.");
          }
        });

        // Khởi tạo Admin Mode
        window.addEventListener("load", function() {
            setTimeout(() => {
                frame.contentWindow.postMessage({
                    type: "MME_ADMIN_INIT",
                    mode: "seo_writer",
                    token: "' . esc_js($token) . '",
                    pageContext: ' . $page_context . '
                }, "' . esc_js($base_url) . '");
            }, 2000);
        });
      })();
    </script>';
}

// Thêm AJAX handler cho nút Git Pull
add_action('wp_ajax_mme_form_git_pull', 'mme_form_git_pull_handler');
function mme_form_git_pull_handler() {
    check_ajax_referer('mme_form_git_pull_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Không đủ quyền hạn');
    }

    $domain = $_SERVER['HTTP_HOST'] ?? '';
    $domain = preg_replace('/^www\./', '', $domain);

    if (empty($domain)) {
        wp_send_json_error('Không thể xác định tên miền.');
    }

    $daemon_url = "http://127.0.0.1:8989/hooks/" . urlencode($domain);

    $args = array(
        'method'      => 'POST',
        'timeout'     => 5,
        'redirection' => 5,
        'httpversion' => '1.0',
        'blocking'    => false,
        'headers'     => array(),
        'body'        => '',
        'cookies'     => array()
    );

    wp_remote_post($daemon_url, $args);
    
    wp_send_json_success('Đã gửi lệnh kích hoạt Deploy cho domain: ' . $domain . '. Vui lòng chờ vài giây để server tự động cập nhật!');
}
