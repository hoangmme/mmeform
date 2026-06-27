<?php

if (!defined('ABSPATH')) {
    exit;
}

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
            $attributes = shortcode_atts(array('id' => 0), $attributes, 'mme_form');
            return MME_Form_Renderer::render(absint($attributes['id']));
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

        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(get_the_title($form_id)); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(MME_FORM_URL . 'assets/public.css?ver=' . MME_FORM_VERSION); ?>">
    <style>
        body.mme-form-embed-page {
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body class="mme-form-embed-page">
<?php echo MME_Form_Renderer::render($form_id, array('embed' => true, 'parent_url' => $parent_url)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
            'image_position' => 'left',
            'button_color' => '#0061ff',
            'secondary_color' => '#10b981',
            'accent_color' => '#14b8a6',
            'background_color' => '#f4f7fb',
            'text_color' => '#0f172a',
            'font_family' => 'system',
            'trust_items' => array('Phản hồi nhanh', 'Bảo mật thông tin', 'Tư vấn miễn phí'),
            'facebook_url' => '',
            'zalo_url' => '',
            'linkedin_url' => '',
            'chatbot_enabled' => 'yes',
            'chatbot_base_url' => 'https://chat.mme.vn',
            'chatbot_tenant' => 'mme',
            'chatbot_button_text' => 'Hỏi AI trước khi gửi',
            'webhook_enabled' => 'no',
            'webhook_url' => '',
            'webhook_secret' => '',
            'twenty_enabled' => 'no',
            'twenty_base_url' => '',
            'twenty_api_key' => '',
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
}
