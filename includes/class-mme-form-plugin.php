<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MME_Form_Plugin')) {
final class MME_Form_Plugin
{
    private static ?MME_Form_Plugin $instance = null;

    public static function instance(): MME_Form_Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('init', array($this, 'register_post_types'));
        add_action('init', array($this, 'register_shortcode'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('template_redirect', array($this, 'serve_embed'), 0);

        MME_Form_Admin::instance()->boot();
        MME_Form_Submissions::instance()->boot();
        MME_Form_Updater::instance()->boot();
    }

    public function register_post_types(): void
    {
        register_post_type('mme_form', array(
            'labels' => array(
                'name' => __('MME Forms', 'mme-form'),
                'singular_name' => __('MME Form', 'mme-form'),
                'add_new_item' => __('Add compact form', 'mme-form'),
                'edit_item' => __('Edit form', 'mme-form'),
                'menu_name' => __('MME Forms', 'mme-form'),
            ),
            'public' => false,
            'publicly_queryable' => true,
            'exclude_from_search' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-feedback',
            'supports' => array('title'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));

        register_post_type('mme_form_submission', array(
            'labels' => array(
                'name' => __('Submissions', 'mme-form'),
                'singular_name' => __('Submission', 'mme-form'),
                'menu_name' => __('Submissions', 'mme-form'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=mme_form',
            'supports' => array('title'),
            'capabilities' => array(
                'create_posts' => 'do_not_allow',
            ),
            'map_meta_cap' => true,
        ));
    }

    public function register_shortcode(): void
    {
        add_shortcode('mme_form', static function (array $attributes = array()): string {
            $attributes = shortcode_atts(array(
                'id' => 0,
                'mode' => '',
                'card' => '',
                'heading' => '',
                'bg' => '',
                'field_bg' => '',
                'label_color' => '',
                'placeholder_color' => '',
            ), $attributes, 'mme_form');

            $args = array();
            if ($attributes['mode'] === 'fields_only') {
                $args['fields_only'] = true;
            } elseif ($attributes['mode'] === 'form_only') {
                $args['form_only'] = true;
            }
            if (!empty($attributes['card'])) {
                $args['card'] = in_array(strtolower($attributes['card']), array('yes', '1', 'true', 'card'), true) ? 'card' : 'transparent';
            }
            if (!empty($attributes['heading'])) {
                $args['heading'] = in_array(strtolower($attributes['heading']), array('yes', '1', 'true'), true) ? 'yes' : 'no';
            }
            if (!empty($attributes['bg'])) {
                $args['bg'] = sanitize_text_field($attributes['bg']);
            }
            if (!empty($attributes['field_bg'])) {
                $args['field_bg'] = sanitize_text_field($attributes['field_bg']);
            }
            if (!empty($attributes['label_color'])) {
                $args['label_color'] = sanitize_text_field($attributes['label_color']);
            }
            if (!empty($attributes['placeholder_color'])) {
                $args['placeholder_color'] = sanitize_text_field($attributes['placeholder_color']);
            }

            return MME_Form_Renderer::render(absint($attributes['id']), $args);
        });

        add_shortcode('mme_form_fields', static function (array $attributes = array()): string {
            $attributes = shortcode_atts(array(
                'id' => 0,
                'card' => '',
                'heading' => '',
                'bg' => '',
                'field_bg' => '',
                'label_color' => '',
                'placeholder_color' => '',
            ), $attributes, 'mme_form_fields');

            $args = array(
                'fields_only' => true,
            );
            if (!empty($attributes['card'])) {
                $args['card'] = in_array(strtolower($attributes['card']), array('yes', '1', 'true', 'card'), true) ? 'card' : 'transparent';
            }
            if (!empty($attributes['heading'])) {
                $args['heading'] = in_array(strtolower($attributes['heading']), array('yes', '1', 'true'), true) ? 'yes' : 'no';
            }
            if (!empty($attributes['bg'])) {
                $args['bg'] = sanitize_text_field($attributes['bg']);
            }
            if (!empty($attributes['field_bg'])) {
                $args['field_bg'] = sanitize_text_field($attributes['field_bg']);
            }
            if (!empty($attributes['label_color'])) {
                $args['label_color'] = sanitize_text_field($attributes['label_color']);
            }
            if (!empty($attributes['placeholder_color'])) {
                $args['placeholder_color'] = sanitize_text_field($attributes['placeholder_color']);
            }

            return MME_Form_Renderer::render(absint($attributes['id']), $args);
        });
    }

    public function register_query_vars(array $query_vars): array
    {
        $query_vars[] = 'mme_form_embed';
        return $query_vars;
    }

    public function serve_embed(): void
    {
        $form_id = 0;
        if (get_query_var('mme_form_embed')) {
            $form_id = absint(get_query_var('mme_form_embed'));
        } elseif (is_singular('mme_form')) {
            $form_id = get_the_ID();
        }

        if (!$form_id) {
            return;
        }

        $form = get_post($form_id);
        if (!$form || $form->post_type !== 'mme_form') {
            return;
        }

        if ($form->post_status !== 'publish' && !current_user_can('edit_post', $form_id)) {
            status_header(404);
            exit('Form not found.');
        }

        header_remove('X-Frame-Options');
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        header('Content-Security-Policy: frame-ancestors *');
        header('X-Robots-Tag: noindex, nofollow', true);
        nocache_headers();

        $parent_url = isset($_GET['mme_parent_url'])
            ? esc_url_raw(wp_unslash($_GET['mme_parent_url']))
            : '';

        $mode = isset($_GET['mode']) ? sanitize_key($_GET['mode']) : '';
        $bg_sim = isset($_GET['bg_sim']) ? sanitize_key($_GET['bg_sim']) : '';
        $card = isset($_GET['card']) ? sanitize_key($_GET['card']) : '';
        $in_modal = !empty($_GET['in_modal']);

        $render_args = array(
            'embed' => true,
            'parent_url' => $parent_url,
        );
        if ($mode === 'fields_only' || $mode === 'form_only') {
            $render_args['fields_only'] = true;
        }
        if (!empty($card)) {
            $render_args['card'] = $card;
        }

        $body_bg = '#f8fafc';
        if ($bg_sim === 'white') {
            $body_bg = '#ffffff';
        } elseif ($bg_sim === 'gray') {
            $body_bg = '#e2e8f0';
        } elseif ($bg_sim === 'dark') {
            $body_bg = '#0f172a';
        } elseif ($bg_sim === 'primary') {
            $body_bg = '#1e3a8a';
        }

        $show_preview_bar = current_user_can('edit_post', $form_id) && empty($parent_url) && !$in_modal;

        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(get_the_title($form_id)); ?> - Preview</title>
    <link rel="stylesheet" href="<?php echo esc_url(MME_FORM_URL . 'assets/public.css?ver=' . MME_FORM_VERSION); ?>">
    <style>
        body.mme-form-embed-page {
            margin: 0;
            padding: <?php echo $show_preview_bar ? '48px 0 0' : '0'; ?>;
            min-height: 100vh;
            background: <?php echo esc_attr($body_bg); ?>;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
            box-sizing: border-box;
        }
        .mme-preview-topbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 48px;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(8px);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 99999;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 13px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .mme-preview-topbar a, .mme-preview-topbar button {
            color: #e2e8f0;
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
        }
        .mme-preview-topbar a:hover, .mme-preview-topbar button:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
        }
        .mme-preview-topbar a.is-active, .mme-preview-topbar button.is-active {
            background: #2563eb;
            border-color: #3b82f6;
            color: #fff;
            font-weight: 600;
        }
        .mme-preview-topbar-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body class="mme-form-embed-page">
<?php if ($show_preview_bar) :
    $base_url = remove_query_arg(array('mode', 'bg_sim', 'card'));
?>
<div class="mme-preview-topbar">
    <div class="mme-preview-topbar-group">
        <span style="font-weight: 700; color: #60a5fa;">MME Form Preview:</span>
        <a href="<?php echo esc_url(add_query_arg(array('mode' => 'full'), $base_url)); ?>" class="<?php echo $mode !== 'fields_only' ? 'is-active' : ''; ?>">Giao diện Đầy đủ</a>
        <a href="<?php echo esc_url(add_query_arg(array('mode' => 'fields_only'), $base_url)); ?>" class="<?php echo $mode === 'fields_only' ? 'is-active' : ''; ?>">Chỉ Form ([mme_form_fields])</a>
    </div>
    <div class="mme-preview-topbar-group">
        <span style="color: #94a3b8;">Thử màu nền section:</span>
        <a href="<?php echo esc_url(add_query_arg('bg_sim', 'white', $base_url)); ?>" class="<?php echo $bg_sim === 'white' ? 'is-active' : ''; ?>">Trắng</a>
        <a href="<?php echo esc_url(add_query_arg('bg_sim', 'gray', $base_url)); ?>" class="<?php echo $bg_sim === 'gray' || empty($bg_sim) ? 'is-active' : ''; ?>">Xám nhạt</a>
        <a href="<?php echo esc_url(add_query_arg('bg_sim', 'dark', $base_url)); ?>" class="<?php echo $bg_sim === 'dark' ? 'is-active' : ''; ?>">Tối (Dark)</a>
        <a href="<?php echo esc_url(add_query_arg('bg_sim', 'primary', $base_url)); ?>" class="<?php echo $bg_sim === 'primary' ? 'is-active' : ''; ?>">Màu thương hiệu</a>
    </div>
    <div class="mme-preview-topbar-group">
        <a href="<?php echo esc_url(get_edit_post_link($form_id)); ?>">← Quay lại sửa Form</a>
    </div>
</div>
<?php endif; ?>

<?php echo MME_Form_Renderer::render($form_id, $render_args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<script src="<?php echo esc_url(MME_FORM_URL . 'assets/public.js?ver=' . MME_FORM_VERSION); ?>"></script>
</body>
</html><?php
        exit;
    }

    public static function default_fields(): array
    {
        return array(
            array('name' => 'full_name', 'label' => 'Họ và tên', 'type' => 'text', 'width' => '100', 'placeholder' => 'Nguyễn Văn A', 'required' => true, 'options' => array()),
            array('name' => 'phone', 'label' => 'Số điện thoại', 'type' => 'tel', 'width' => '50', 'placeholder' => '09xx xxx xxx', 'required' => true, 'options' => array()),
            array('name' => 'email', 'label' => 'Email', 'type' => 'email', 'width' => '50', 'placeholder' => 'email@example.com', 'required' => false, 'options' => array()),
            array('name' => 'need', 'label' => 'Nhu cầu tư vấn', 'type' => 'textarea', 'width' => '100', 'placeholder' => 'Bạn đang quan tâm điều gì?', 'required' => false, 'options' => array()),
            array('name' => 'contact_channel', 'label' => 'Kênh liên hệ', 'type' => 'radio', 'width' => '100', 'placeholder' => '', 'required' => false, 'options' => array('Điện thoại', 'Zalo', 'Email')),
        );
    }

    public static function default_settings(): array
    {
        return array(
            'kicker' => 'HỖ TRỢ 24/7',
            'heading' => 'Sẵn sàng để',
            'subheading' => 'chuyển đổi số?',
            'description' => 'Để lại thông tin, chuyên viên của MMe sẽ liên hệ hỗ trợ bạn thiết lập hệ thống Bot AI và tư vấn lộ trình tự động hóa hoàn toàn miễn phí.',
            'form_heading' => 'Nhận tư vấn giải pháp MMe Suite cho doanh nghiệp bạn',
            'form_footer' => 'Thông tin của bạn được bảo mật tuyệt đối.',
            'hotline' => '0906 220 284',
            'hotline_label' => 'Hotline tư vấn',
            'support_email' => 'admin@mme.vn',
            'email_label' => 'Email hỗ trợ',
            'social_label' => 'Theo dõi',
            'button_text' => 'Đăng ký nhận tư vấn',
            'success_message' => 'Đã nhận thông tin. MME sẽ liên hệ với bạn sớm!',
            'layout_mode' => 'full',
            'image_position' => 'left',
            'fields_card_style' => 'card',
            'fields_show_heading' => 'yes',
            'form_padding' => 'default',
            'form_field_gap' => 'default',
            'form_border_radius' => 'default',
            'form_shadow' => 'default',
            'form_max_width' => '680px',
            'form_card_bg' => '#ffffff',
            'form_card_border' => '#e2e8f0',
            'field_bg_color' => '#f8fafc',
            'field_border_color' => '#e2e8f0',
            'field_text_color' => '#1e293b',
            'field_label_color' => '#1e293b',
            'field_placeholder_color' => '#94a3b8',
            'form_heading_color' => '#0f172a',
            'button_color' => '#0061ff',
            'button_text_color' => '#ffffff',
            'secondary_color' => '#10b981',
            'accent_color' => '#14b8a6',
            'background_color' => '#f4f7fb',
            'text_color' => '#0f172a',
            'font_family' => 'system',
            'trust_items' => array('Phản hồi nhanh', 'Bảo mật thông tin', 'Tư vấn miễn phí'),
            'facebook_url' => '',
            'zalo_url' => '',
            'linkedin_url' => '',
            'tiktok_url' => '',
            'youtube_url' => '',
            'otp_enabled' => 'no',
            'otp_email_subject' => '[{site_name}] Mã xác thực đăng ký: {otp}',
            'otp_expiry_minutes' => 5,
            'otp_cooldown_seconds' => 60,
            'chatbot_enabled' => 'no',
            'chatbot_base_url' => 'https://chat.mme.vn',
            'chatbot_tenant' => 'mme',
            'chatbot_button_text' => 'Hỏi AI trước khi gửi',
            'webhook_enabled' => 'no',
            'webhook_url' => '',
            'webhook_secret' => '',
            'twenty_enabled' => 'no',
            'twenty_base_url' => '',
            'twenty_api_key' => '',
            'pancake_enabled' => 'no',
            'pancake_workspace_id' => '',
            'pancake_api_key' => '',
            'pancake_table' => 'contact',
        );
    }

    public static function activate(): void
    {
        self::instance()->register_post_types();

        if (get_option('mme_form_default_created')) {
            return;
        }

        $form_id = wp_insert_post(array(
            'post_type' => 'mme_form',
            'post_status' => 'publish',
            'post_title' => 'Form tư vấn MME',
        ));

        if (!is_wp_error($form_id)) {
            update_post_meta($form_id, '_mme_form_fields', self::default_fields());
            update_post_meta($form_id, '_mme_form_settings', self::default_settings());
            update_option('mme_form_default_created', (int) $form_id, false);
        }
    }

    public static function get_version(): string
    {
        return defined('MME_FORM_VERSION') ? MME_FORM_VERSION : '0.5.0';
    }

    public static function get_build_info(): array
    {
        $version = self::get_version();
        $fallback_time = defined('MME_FORM_BUILD_TIME') ? MME_FORM_BUILD_TIME : '2026-10-16 12:05:00';
        $commit = '';
        $date = $fallback_time;
        $branch = 'main';

        $base_dir = defined('MME_FORM_DIR') ? MME_FORM_DIR : dirname(__DIR__) . '/';
        $git_dir = rtrim($base_dir, '/\\') . '/.git';

        // Đọc an toàn trực tiếp từ .git/HEAD và ref (không cần shell exec)
        if (is_dir($git_dir)) {
            $head_file = $git_dir . '/HEAD';
            if (file_exists($head_file)) {
                $head = trim((string) @file_get_contents($head_file));
                if (substr($head, 0, 5) === 'ref: ') {
                    $ref = trim(substr($head, 5));
                    $branch = basename($ref);
                    $ref_path = $git_dir . '/' . $ref;
                    if (file_exists($ref_path)) {
                        $commit = substr(trim((string) @file_get_contents($ref_path)), 0, 7);
                        $mtime = @filemtime($ref_path);
                        if ($mtime) {
                            $date = function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $mtime) : date('Y-m-d H:i:s', $mtime);
                        }
                    }
                } elseif (strlen($head) >= 7) {
                    $commit = substr($head, 0, 7);
                }
            }
        }

        // Dự phòng nếu không có .git: dùng filemtime của file chính
        if (!$commit && defined('MME_FORM_FILE')) {
            $file_mtime = @filemtime(MME_FORM_FILE);
            if ($file_mtime) {
                $date = function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $file_mtime) : date('Y-m-d H:i:s', $file_mtime);
            }
        }

        return array(
            'version' => $version,
            'commit' => $commit,
            'branch' => $branch,
            'date' => $date,
        );
    }
}
}

