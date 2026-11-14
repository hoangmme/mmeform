<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MME_Form_Admin')) {
final class MME_Form_Admin
{
    private static ?MME_Form_Admin $instance = null;

    public static function instance(): MME_Form_Admin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
        add_action('save_post_mme_form', array($this, 'save_form'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_filter('manage_mme_form_posts_columns', array($this, 'form_columns'));
        add_action('manage_mme_form_posts_custom_column', array($this, 'render_form_column'), 10, 2);
        add_filter('manage_mme_form_submission_posts_columns', array($this, 'submission_columns'));
        add_action('manage_mme_form_submission_posts_custom_column', array($this, 'render_submission_column'), 10, 2);
        add_filter('post_row_actions', array($this, 'remove_submission_quick_actions'), 10, 2);
        add_action('wp_ajax_mme_twenty_check_fields', array($this, 'ajax_twenty_check_fields'));
        add_action('wp_ajax_mme_submission_quick_view', array($this, 'ajax_submission_quick_view'));
        add_action('wp_ajax_mme_submission_retry', array($this, 'ajax_submission_retry'));
        add_action('restrict_manage_posts', array($this, 'render_submission_filters'), 10, 2);
        add_action('pre_get_posts', array($this, 'filter_submissions_query'));
        add_action('load-edit.php', array($this, 'maybe_export_submissions'));
        add_action('admin_menu', array($this, 'register_settings_page'));
        add_action('admin_post_mme_form_save_global', array($this, 'save_global_settings'));
        add_action('admin_footer', array($this, 'render_submission_quick_view_modal'));
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        add_filter('admin_footer_text', array($this, 'admin_footer_text'), 11);
        add_action('admin_notices', array($this, 'render_admin_version_notice'));
    }

    public function enqueue_assets(string $hook): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $is_form_screen = in_array($screen->post_type, array('mme_form', 'mme_form_submission'), true);
        $is_plugins_screen = ($screen->base === 'plugins' || $screen->id === 'plugins');

        if (!$is_form_screen && !$is_plugins_screen) {
            return;
        }

        if ($is_form_screen) {
            wp_enqueue_style('mme-form-admin', MME_FORM_URL . 'assets/admin.css', array(), MME_FORM_VERSION);
            if ($screen->post_type === 'mme_form' && $screen->base === 'post') {
                wp_enqueue_media();
            }
        }

        wp_enqueue_script('mme-form-admin', MME_FORM_URL . 'assets/admin.js', array(), MME_FORM_VERSION, true);
        wp_localize_script('mme-form-admin', 'mme_form_admin_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mme_form_admin_nonce'),
        ));
    }

    public function register_meta_boxes(): void
    {
        add_meta_box('mme-form-editor', 'Trình sửa form', array($this, 'render_editor_box'), 'mme_form', 'normal', 'high');
        add_meta_box('mme-form-embed', 'Xem trước & nhúng', array($this, 'render_embed_box'), 'mme_form', 'side', 'high');
        add_meta_box('mme-form-submission-data', __('Submission data', 'mme-form'), array($this, 'render_submission_box'), 'mme_form_submission', 'normal', 'high');
    }

    public function render_fields_box(WP_Post $post): void
    {
        $fields = get_post_meta($post->ID, '_mme_form_fields', true);
        $fields = is_array($fields) && $fields ? $fields : MME_Form_Plugin::default_fields();
        wp_nonce_field('mme_form_save', 'mme_form_nonce');
        ?>
        <p class="mme-pane-intro">Các ô khách sẽ điền. Kéo <span aria-hidden="true">⋮⋮</span> để đổi thứ tự; "Tên biến" là khoá gửi sang Google Sheets / CRM.</p>
        <div id="mme-form-builder" data-fields="<?php echo esc_attr(wp_json_encode($fields)); ?>">
            <input type="hidden" id="mme-form-fields-json" name="mme_form_fields_json" value="<?php echo esc_attr(wp_json_encode($fields)); ?>">
            <div id="mme-fields-body" class="mme-fcards"></div>
            <div class="mme-fields-actions">
                <button type="button" class="button button-primary" id="mme-add-field"><span class="dashicons dashicons-plus-alt2"></span> Thêm trường</button>
                <button type="button" class="button button-link" id="mme-import-json-btn">Import JSON (Nâng cao)</button>
                <button type="button" class="button button-link" id="mme-copy-ai-prompt-builder-btn" style="color: #007cba; font-weight: 600;">✨ Copy Prompt cho AI code web</button>
                <span id="mme-copy-ai-prompt-builder-status" style="color: #10b981; font-weight: 600; display: none;">✔ Đã copy Prompt!</span>
            </div>
            <div id="mme-import-json-wrap" style="display: none; margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <p style="margin-top: 0;"><strong>Import Form Fields từ JSON</strong></p>
                <p class="description">Dán mảng JSON chứa định dạng các field vào đây. (Có thể yêu cầu AI viết cấu trúc dựa theo file README.md, sau đó copy dán vào đây để khởi tạo nhanh form).</p>
                <textarea id="mme-import-json-text" rows="8" class="large-text code" style="font-family: monospace;"></textarea>
                <div style="margin-top: 10px;">
                    <button type="button" class="button button-primary" id="mme-import-json-submit">Xác nhận Import (Ghi đè)</button>
                </div>
            </div>
            <textarea id="mme-copy-ai-prompt-builder-content" style="display: none;"><?php echo esc_textarea("Khi code Landing Page WordPress bằng PHP template, hãy sử dụng hàm PHP mme_form_auto(\$slug, \$title, \$json_fields) của plugin MME Form để hiển thị form.\nHàm này tự động kiểm tra/tạo form và trả về shortcode Chỉ Form (ẩn cột thông tin bên trái để lọt vào khung giao diện của bạn).\n\nQuy tắc viết mảng \$json_fields (JSON array các object):\n- Các thuộc tính hỗ trợ:\n  + type: text, email, tel, textarea, select, hoặc radio.\n  + name: Tên biến (viết thường, không dấu, dùng dấu gạch dưới, VD: full_name, phone).\n  + label: Nhãn hiển thị (VD: \"Họ và tên\").\n  + placeholder: Gợi ý mờ trong ô nhập.\n  + width: \"100\" (Full dòng) hoặc \"50\" (Chia đôi dòng).\n  + required: true hoặc false.\n  + options: Chỉ cho select/radio, các lựa chọn cách nhau bằng \\n (xuống dòng).\n\nVí dụ PHP:\n<" . "?php\n\$fields = '[\n  {\"type\":\"text\", \"name\":\"fullname\", \"label\":\"Họ tên\", \"placeholder\":\"Nhập tên...\", \"required\":true, \"width\":\"100\"},\n  {\"type\":\"tel\", \"name\":\"phone\", \"label\":\"Số điện thoại\", \"placeholder\":\"09xx...\", \"required\":true, \"width\":\"50\"}\n]';\nif (function_exists('mme_form_auto')) {\n    echo mme_form_auto('landing-su-kien-2026', 'Form Sự Kiện', \$fields);\n}\n?" . ">\nLưu ý: Bạn hãy tự thiết kế Container/Card giao diện cho form đẹp mắt theo bố cục website rồi gọi echo mme_form_auto(...) vào đúng vị trí!"); ?></textarea>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var copyBtn = document.getElementById('mme-copy-ai-prompt-builder-btn');
                if (copyBtn) {
                    copyBtn.addEventListener('click', function() {
                        var promptEl = document.getElementById('mme-copy-ai-prompt-builder-content');
                        var promptText = promptEl ? promptEl.value : '';
                        navigator.clipboard.writeText(promptText).then(function() {
                            var status = document.getElementById('mme-copy-ai-prompt-builder-status');
                            if (status) {
                                status.style.display = 'inline';
                                setTimeout(function() { status.style.display = 'none'; }, 3000);
                            }
                        });
                    });
                }
            });
            </script>
        </div>
        <?php
    }

    /**
     * Trình sửa form: một khung có tab (Trường · Giao diện · Bố cục 2 cột · Chống spam · Tích hợp · Nhúng)
     * và khung xem trước trực tiếp bên phải, cập nhật theo thay đổi chưa lưu.
     */
    public function render_editor_box(WP_Post $post): void
    {
        $tabs = array(
            'fields' => array('Trường', 'dashicons-feedback'),
            'design' => array('Giao diện', 'dashicons-art'),
            'layout' => array('Bố cục 2 cột', 'dashicons-columns'),
            'protect' => array('Chống spam', 'dashicons-shield'),
            'integrations' => array('Tích hợp', 'dashicons-randomize'),
            'embed' => array('Nhúng', 'dashicons-editor-code'),
        );
        $preview_url = add_query_arg(array('mme_form_embed' => $post->ID, 'in_modal' => '1'), home_url('/'));
        ?>
        <div class="mme-editor" data-preview-url="<?php echo esc_url($preview_url); ?>" data-preview-nonce="<?php echo esc_attr(wp_create_nonce('mme_form_preview_' . $post->ID)); ?>">
            <div class="mme-editor__main">
                <div class="mme-editor__bar">
                    <nav class="mme-editor__tabs" role="tablist" aria-label="Phần cài đặt form">
                        <?php foreach ($tabs as $key => $tab) : ?>
                            <button type="button" role="tab" class="mme-editor__tab<?php echo $key === 'fields' ? ' is-active' : ''; ?>" data-pane="<?php echo esc_attr($key); ?>" aria-selected="<?php echo $key === 'fields' ? 'true' : 'false'; ?>">
                                <span class="dashicons <?php echo esc_attr($tab[1]); ?>" aria-hidden="true"></span><?php echo esc_html($tab[0]); ?>
                            </button>
                        <?php endforeach; ?>
                    </nav>
                    <div class="mme-editor__actions">
                        <button type="button" class="button mme-preview-toggle" data-mme-preview-toggle aria-pressed="false">
                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="mme-preview-toggle__label">Xem trước</span>
                        </button>
                        <button type="button" class="button button-primary mme-editor-save" data-mme-save>Lưu</button>
                    </div>
                </div>

                <section class="mme-editor__pane is-active" data-pane="fields" role="tabpanel"><?php $this->render_fields_box($post); ?></section>
                <section class="mme-editor__pane" data-pane="design" role="tabpanel" hidden><?php $this->render_design_pane($post); ?></section>
                <section class="mme-editor__pane" data-pane="layout" role="tabpanel" hidden><?php $this->render_layout_pane($post); ?></section>
                <section class="mme-editor__pane" data-pane="protect" role="tabpanel" hidden><?php $this->render_protect_pane($post); ?></section>
                <section class="mme-editor__pane" data-pane="integrations" role="tabpanel" hidden><?php $this->render_integrations_box($post); ?></section>
                <section class="mme-editor__pane" data-pane="embed" role="tabpanel" hidden><?php $this->render_embed_pane($post); ?></section>
            </div>

            <aside class="mme-editor__preview" aria-label="Xem trước trực tiếp" hidden>
                <div class="mme-preview-bar">
                    <div class="mme-seg" data-mme-preview-opt="mode">
                        <button type="button" class="is-active" data-value="full">Đầy đủ</button>
                        <button type="button" data-value="fields_only">Chỉ form</button>
                    </div>
                    <div class="mme-seg" data-mme-preview-opt="device">
                        <button type="button" class="is-active" data-value="desktop" title="Máy tính"><span class="dashicons dashicons-desktop"></span></button>
                        <button type="button" data-value="mobile" title="Điện thoại"><span class="dashicons dashicons-smartphone"></span></button>
                    </div>
                    <div class="mme-seg" data-mme-preview-opt="bg">
                        <button type="button" class="is-active" data-value="white" title="Nền trắng"><i style="background:#fff"></i></button>
                        <button type="button" data-value="gray" title="Nền xám"><i style="background:#e2e8f0"></i></button>
                        <button type="button" data-value="dark" title="Nền tối"><i style="background:#0f172a"></i></button>
                    </div>
                </div>
                <div class="mme-preview-stage">
                    <iframe name="mme-live-preview" class="mme-preview-frame" title="Xem trước form" src="about:blank"></iframe>
                    <span class="mme-preview-loading" aria-hidden="true"></span>
                </div>
                <p class="mme-preview-note">Theo thay đổi chưa lưu. Form trong khung này không gửi đi.</p>
            </aside>
        </div>
        <?php
    }

    /** Bộ màu nhanh: giá trị điền vào các ô màu của tab Giao diện. */
    private function color_presets(): array
    {
        return array(
            'light' => array('label' => 'Sáng', 'swatch' => array('#ffffff', '#f8fafc'), 'values' => array(
                'fields_card_style' => 'card', 'form_card_bg' => '#ffffff', 'form_card_border' => '#e2e8f0',
                'field_bg_color' => '#f8fafc', 'field_border_color' => '#e2e8f0', 'field_label_color' => '#1e293b',
                'field_placeholder_color' => '#94a3b8', 'field_text_color' => '#1e293b', 'form_heading_color' => '#0f172a',
            )),
            'soft' => array('label' => 'Xám nhạt', 'swatch' => array('#f1f5f9', '#ffffff'), 'values' => array(
                'fields_card_style' => 'card', 'form_card_bg' => '#f1f5f9', 'form_card_border' => '#e2e8f0',
                'field_bg_color' => '#ffffff', 'field_border_color' => '#dbe3ec', 'field_label_color' => '#1e293b',
                'field_placeholder_color' => '#94a3b8', 'field_text_color' => '#0f172a', 'form_heading_color' => '#0f172a',
            )),
            'dark' => array('label' => 'Tối', 'swatch' => array('#0f172a', '#1e293b'), 'values' => array(
                'fields_card_style' => 'card', 'form_card_bg' => '#0f172a', 'form_card_border' => '#1e293b',
                'field_bg_color' => '#1e293b', 'field_border_color' => '#334155', 'field_label_color' => '#e2e8f0',
                'field_placeholder_color' => '#64748b', 'field_text_color' => '#f8fafc', 'form_heading_color' => '#f8fafc',
            )),
            'transparent' => array('label' => 'Hoà vào nền', 'swatch' => array('transparent', '#ffffff'), 'values' => array(
                'fields_card_style' => 'transparent', 'field_bg_color' => '#ffffff', 'field_border_color' => '#dbe3ec',
                'field_label_color' => '#1e293b', 'field_placeholder_color' => '#94a3b8', 'field_text_color' => '#0f172a',
                'form_heading_color' => '#0f172a',
            )),
        );
    }

    private function select_options(string $key, string $label, string $value, array $options, string $id = '', string $class = ''): void
    {
        echo '<label class="mme-admin-field' . ($class ? ' ' . esc_attr($class) : '') . '"><span>' . esc_html($label) . '</span><select name="mme_settings[' . esc_attr($key) . ']"' . ($id ? ' id="' . esc_attr($id) . '"' : '') . '>';
        foreach ($options as $opt_val => $opt_label) {
            echo '<option value="' . esc_attr($opt_val) . '" ' . selected($value, $opt_val, false) . '>' . esc_html($opt_label) . '</option>';
        }
        echo '</select></label>';
    }

    public function render_design_pane(WP_Post $post): void
    {
        $settings = $this->settings($post->ID);
        ?>
        <p class="mme-pane-intro">Áp dụng cho cả shortcode đầy đủ <code>[mme_form id="<?php echo esc_attr((string) $post->ID); ?>"]</code> và chỉ form <code>[mme_form_fields id="<?php echo esc_attr((string) $post->ID); ?>"]</code>.</p>

        <div class="mme-section">
            <h3 class="mme-section__title">Bộ màu nhanh</h3>
            <div class="mme-presets" role="group" aria-label="Bộ màu nhanh">
                <?php foreach ($this->color_presets() as $key => $preset) : ?>
                    <button type="button" class="mme-preset" data-mme-preset="<?php echo esc_attr(wp_json_encode($preset['values'])); ?>">
                        <span class="mme-preset__swatch" style="--a:<?php echo esc_attr($preset['swatch'][0]); ?>;--b:<?php echo esc_attr($preset['swatch'][1]); ?>"></span>
                        <?php echo esc_html($preset['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="mme-admin-grid">
                <?php $this->color_input('button_color', 'Màu chính (nút gửi)', $settings['button_color']); ?>
                <?php $this->color_input('button_text_color', 'Màu chữ trên nút', $settings['button_text_color']); ?>
            </div>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Tiêu đề form</h3>
            <div class="mme-admin-grid">
                <?php $this->select_options('fields_show_heading', 'Hiển thị', $settings['fields_show_heading'] ?? 'yes', array('yes' => 'Hiện tiêu đề', 'no' => 'Ẩn tiêu đề'), 'mme-fields-show-heading'); ?>
                <?php $this->color_input('form_heading_color', 'Màu tiêu đề', $settings['form_heading_color'] ?? '#0f172a'); ?>
                <div class="mme-admin-span-2" id="mme-heading-text-wrapper">
                    <?php $this->text_input('form_heading', 'Nội dung tiêu đề', $settings['form_heading']); ?>
                </div>
            </div>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Khung form</h3>
            <div class="mme-admin-grid">
                <?php $this->select_options('fields_card_style', 'Kiểu khung', $settings['fields_card_style'] ?? 'card', array('card' => 'Thẻ riêng (nền, viền, bo góc, bóng)', 'transparent' => 'Trong suốt (hoà vào nền section)'), 'mme-fields-card-style', 'mme-admin-span-2'); ?>
            </div>
            <div id="mme-card-options-group" class="mme-admin-grid mme-subgroup">
                <?php $this->color_input('form_card_bg', 'Màu nền khung', $settings['form_card_bg']); ?>
                <?php $this->color_input('form_card_border', 'Màu viền khung', $settings['form_card_border']); ?>
                <?php $this->select_options('form_max_width', 'Độ rộng tối đa', $settings['form_max_width'] ?? '680px', array('540px' => 'Gọn (540px)', '680px' => 'Tiêu chuẩn (680px)', '800px' => 'Rộng (800px)', '100%' => 'Theo khung chứa (100%)')); ?>
                <?php $this->select_options('form_border_radius', 'Bo góc', $settings['form_border_radius'] ?? 'default', array('none' => 'Vuông (0)', 'small' => 'Nhẹ (8px)', 'medium' => 'Vừa (16px)', 'default' => 'Nhiều (28px)', 'full' => 'Rất nhiều (36px)')); ?>
                <?php $this->select_options('form_shadow', 'Đổ bóng', $settings['form_shadow'] ?? 'default', array('none' => 'Không', 'subtle' => 'Nhẹ', 'default' => 'Mềm (mặc định)', 'strong' => 'Nổi bật')); ?>
                <?php $this->select_options('form_padding', 'Khoảng đệm trong', $settings['form_padding'] ?? 'default', array('none' => 'Không (0)', 'small' => 'Nhỏ (16 / 14px)', 'compact' => 'Vừa (28 / 20px)', 'default' => 'Tiêu chuẩn (40 / 24px)', 'spacious' => 'Rộng (56 / 32px)')); ?>
            </div>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Nút gửi &amp; thông báo</h3>
            <div class="mme-admin-grid">
                <?php $this->text_input('button_text', 'Chữ trên nút gửi', $settings['button_text']); ?>
                <?php $this->text_input('form_footer', 'Dòng chữ dưới nút (bảo mật)', $settings['form_footer']); ?>
                <div class="mme-admin-span-2">
                    <?php $this->text_input('success_message', 'Thông báo khi gửi thành công', $settings['success_message']); ?>
                </div>
            </div>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Chữ &amp; khoảng cách</h3>
            <div class="mme-admin-grid">
                <?php $this->select_options('font_family', 'Font chữ', $settings['font_family'], array('system' => 'Theo hệ thống', 'inter' => 'Inter', 'arial' => 'Arial', 'georgia' => 'Georgia')); ?>
                <?php $this->select_options('form_field_gap', 'Khoảng cách giữa các ô', $settings['form_field_gap'] ?? 'default', array('small' => 'Nhỏ (10px)', 'compact' => 'Gọn (14px)', 'default' => 'Tiêu chuẩn (20px)', 'relaxed' => 'Rộng (28px)')); ?>
            </div>
        </div>

        <details class="mme-section mme-details">
            <summary class="mme-section__title">Tuỳ chỉnh màu chi tiết</summary>
            <div class="mme-admin-grid mme-admin-grid--3">
                <?php $this->color_input('field_bg_color', 'Nền ô nhập', $settings['field_bg_color']); ?>
                <?php $this->color_input('field_border_color', 'Viền ô nhập', $settings['field_border_color']); ?>
                <?php $this->color_input('field_text_color', 'Chữ khi nhập', $settings['field_text_color']); ?>
                <?php $this->color_input('field_label_color', 'Nhãn ô nhập', $settings['field_label_color'] ?? '#1e293b'); ?>
                <?php $this->color_input('field_placeholder_color', 'Chữ gợi ý (placeholder)', $settings['field_placeholder_color'] ?? '#94a3b8'); ?>
                <?php $this->color_input('accent_color', 'Màu nhấn', $settings['accent_color']); ?>
                <?php $this->color_input('secondary_color', 'Màu phụ (dấu tích, nhãn nhỏ)', $settings['secondary_color']); ?>
            </div>
        </details>
        <?php
    }

    public function render_layout_pane(WP_Post $post): void
    {
        $settings = $this->settings($post->ID);
        ?>
        <p class="mme-pane-intro">Chỉ dùng cho shortcode đầy đủ <code>[mme_form id="<?php echo esc_attr((string) $post->ID); ?>"]</code>: cột giới thiệu đặt cạnh form. Trên điện thoại form luôn hiện ngay dưới tiêu đề.</p>

        <div class="mme-section">
            <h3 class="mme-section__title">Vị trí</h3>
            <div class="mme-admin-grid">
                <?php $this->select_options('image_position', 'Cột giới thiệu', $settings['image_position'], array('left' => 'Bên trái form', 'right' => 'Bên phải form', 'top' => 'Phía trên form', 'hidden' => 'Ẩn (chỉ còn form)')); ?>
            </div>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Tiêu đề &amp; mô tả</h3>
            <div class="mme-admin-grid">
                <?php $this->text_input('kicker', 'Nhãn nhỏ phía trên', $settings['kicker']); ?>
                <?php $this->text_input('heading', 'Tiêu đề', $settings['heading']); ?>
                <?php $this->text_input('subheading', 'Tiêu đề phụ (chữ màu)', $settings['subheading']); ?>
                <?php $this->text_input('description', 'Mô tả ngắn', $settings['description']); ?>
                <label class="mme-admin-field mme-admin-span-2">
                    <span>Điểm nổi bật (mỗi dòng một ý, tối đa 4)</span>
                    <textarea name="mme_settings[trust_items]" rows="4"><?php echo esc_textarea(implode("\n", (array) $settings['trust_items'])); ?></textarea>
                </label>
            </div>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Liên hệ trực tiếp</h3>
            <div class="mme-admin-grid">
                <?php $this->text_input('hotline_label', 'Nhãn hotline', $settings['hotline_label']); ?>
                <?php $this->text_input('hotline', 'Số hotline', $settings['hotline']); ?>
                <?php $this->text_input('email_label', 'Nhãn email', $settings['email_label']); ?>
                <?php $this->text_input('support_email', 'Email hỗ trợ', $settings['support_email']); ?>
            </div>
        </div>

        <details class="mme-section mme-details">
            <summary class="mme-section__title">Mạng xã hội &amp; màu nền</summary>
            <div class="mme-admin-grid">
                <?php $this->text_input('social_label', 'Nhãn mạng xã hội', $settings['social_label']); ?>
                <?php $this->url_input('facebook_url', 'Facebook', $settings['facebook_url']); ?>
                <?php $this->url_input('zalo_url', 'Zalo', $settings['zalo_url']); ?>
                <?php $this->url_input('linkedin_url', 'LinkedIn', $settings['linkedin_url']); ?>
                <?php $this->url_input('tiktok_url', 'TikTok', $settings['tiktok_url']); ?>
                <?php $this->url_input('youtube_url', 'YouTube', $settings['youtube_url']); ?>
                <?php $this->color_input('background_color', 'Màu nền toàn khối', $settings['background_color']); ?>
                <?php $this->color_input('text_color', 'Màu chữ cột giới thiệu', $settings['text_color']); ?>
            </div>
        </details>
        <?php
    }

    public function render_protect_pane(WP_Post $post): void
    {
        $settings = $this->settings($post->ID);
        $site_key = MME_Form_Plugin::global_settings()['mmeauth_site_key'];
        $settings_url = admin_url('edit.php?post_type=mme_form&page=mme-form-settings');
        ?>
        <p class="mme-pane-intro">Chặn lead ảo: khách bấm "Xác minh bằng Zalo", quét QR (máy tính) hoặc mở Zalo (điện thoại) rồi xác nhận một chạm. Số điện thoại do Zalo xác minh được điền sẵn, không cần OTP.</p>

        <div class="mme-section">
            <?php $this->toggle('mmeauth_enabled', 'Bắt buộc xác minh số điện thoại qua Zalo trước khi gửi', $settings['mmeauth_enabled'] ?? 'no'); ?>
            <div class="mme-callout <?php echo $site_key ? 'is-ok' : 'is-warn'; ?>">
                <?php if ($site_key) : ?>
                    Site key MMeAuth: <code><?php echo esc_html($site_key); ?></code> · <a href="<?php echo esc_url($settings_url); ?>">Tích hợp chung</a>
                <?php else : ?>
                    Chưa có site key MMeAuth. Điền ở <a href="<?php echo esc_url($settings_url); ?>">Tích hợp chung</a>; khi chưa có, form vẫn gửi bình thường.
                <?php endif; ?>
            </div>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Nhận lead từ Mini App</h3>
            <p class="description">Đặt URL này làm <code>LEAD_WEBHOOK_URL</code> của gateway MMeAuth. Lead "Nhận tư vấn" sẽ vào Dữ liệu gửi và chạy tích hợp của form này.</p>
            <div class="mme-copy-row">
                <input type="text" class="widefat code" id="mme-mmeauth-lead-url" value="<?php echo esc_attr(rest_url('mme-form/v1/mmeauth-lead/' . $post->ID)); ?>" readonly>
                <button type="button" class="button mme-copy-btn" data-target="mme-mmeauth-lead-url">Copy</button>
            </div>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Luôn bật sẵn</h3>
            <ul class="mme-checklist">
                <li>Ô bẫy ẩn (honeypot) chặn bot tự điền</li>
                <li>Chặn gửi quá nhanh và gửi trùng trong 2 phút</li>
                <li>Kiểm tra số điện thoại theo quốc gia</li>
            </ul>
        </div>
        <?php
    }

    public function render_embed_pane(WP_Post $post): void
    {
        $endpoint = add_query_arg('mme_form_embed', $post->ID, home_url('/'));
        $script = sprintf(
            '<script src="%s" data-mme-form="%d" data-endpoint="%s"></script>',
            MME_FORM_URL . 'assets/embed.js?ver=' . MME_FORM_VERSION,
            $post->ID,
            $endpoint
        );
        $codes = array(
            'mme-embed-fields' => array('Chỉ form (dùng nhiều nhất)', '[mme_form_fields id="' . $post->ID . '"]', 'Chèn vào bất kỳ section nào của trang, Elementor, Gutenberg…'),
            'mme-embed-full' => array('Đầy đủ (cột giới thiệu + form)', '[mme_form id="' . $post->ID . '"]', 'Dùng cho trang liên hệ hoặc landing page.'),
        );
        $attributes = array(
            'card="no"' => 'Bỏ khung thẻ, form hoà vào nền section',
            'heading="no"' => 'Ẩn tiêu đề form',
            'bg="#ffffff"' => 'Màu nền khung form',
            'field_bg="#f8fafc"' => 'Màu nền ô nhập',
            'label_color="#1e293b"' => 'Màu nhãn ô nhập',
            'placeholder_color="#94a3b8"' => 'Màu chữ gợi ý',
        );
        ?>
        <p class="mme-pane-intro">Copy mã phù hợp rồi dán vào nơi muốn hiện form.</p>

        <?php foreach ($codes as $id => $code) : ?>
            <div class="mme-section">
                <h3 class="mme-section__title"><?php echo esc_html($code[0]); ?></h3>
                <div class="mme-copy-row">
                    <input type="text" class="widefat code" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($code[1]); ?>" readonly>
                    <button type="button" class="button mme-copy-btn" data-target="<?php echo esc_attr($id); ?>">Copy</button>
                </div>
                <p class="description"><?php echo esc_html($code[2]); ?></p>
            </div>
        <?php endforeach; ?>

        <div class="mme-section">
            <h3 class="mme-section__title">Tuỳ chọn thêm cho shortcode</h3>
            <table class="mme-attr-table">
                <?php foreach ($attributes as $attr => $help) : ?>
                    <tr><td><code><?php echo esc_html($attr); ?></code></td><td><?php echo esc_html($help); ?></td></tr>
                <?php endforeach; ?>
            </table>
            <p class="description">Ví dụ: <code>[mme_form_fields id="<?php echo esc_attr((string) $post->ID); ?>" card="no" heading="no"]</code></p>
        </div>

        <div class="mme-section">
            <h3 class="mme-section__title">Nhúng vào website khác</h3>
            <textarea class="widefat code" id="mme-embed-script" rows="3" readonly><?php echo esc_textarea($script); ?></textarea>
            <div class="mme-copy-row mme-copy-row--end">
                <button type="button" class="button mme-copy-btn" data-target="mme-embed-script">Copy mã nhúng</button>
            </div>
            <p class="description">Form tự co giãn chiều cao, giữ URL trang gốc và UTM, gửi sự kiện về GTM / Meta Pixel của website đó.</p>
        </div>
        <?php
    }

    public function render_integrations_box(WP_Post $post): void
    {
        $settings = $this->settings($post->ID);
        ?>
        <p class="description" style="margin: 4px 0 12px;">
            Ô URL / API key / Workspace để trống sẽ dùng giá trị ở <a href="<?php echo esc_url(admin_url('edit.php?post_type=mme_form&page=mme-form-settings')); ?>">Tích hợp chung</a> — chỉ cần bật tích hợp cho form này.
        </p>
        <div class="mme-integration-card">
            <h3>MME Chatbot</h3>
            <?php $this->toggle('chatbot_enabled', 'Hiện nút chatbot trong form', $settings['chatbot_enabled']); ?>
            <div class="mme-admin-grid">
                <?php $this->url_input('chatbot_base_url', 'Chatbot base URL', $settings['chatbot_base_url']); ?>
                <?php $this->text_input('chatbot_tenant', 'Bot / tenant slug', $settings['chatbot_tenant']); ?>
                <?php $this->text_input('chatbot_button_text', 'Chữ trên nút chat', $settings['chatbot_button_text']); ?>
            </div>
        </div>

        <div class="mme-integration-card">
            <h3>Google Sheets / Webhook</h3>
            <?php $this->toggle('webhook_enabled', 'Gửi submission tới webhook', $settings['webhook_enabled']); ?>
            <div class="mme-admin-grid">
                <?php $this->url_input('webhook_url', 'Webhook URL', $settings['webhook_url']); ?>
                <?php $this->password_input('webhook_secret', 'Signing secret', !empty($settings['webhook_secret'])); ?>
            </div>
            <p class="description">
                Google Apps Script có thể dùng URL dạng <code>.../exec?secret=...</code>. Payload dùng chuẩn <code>lead_collected</code> giống mmechatbot.<br>
                <button type="button" class="button button-small" id="mme-copy-gas" style="margin-top:8px;">Copy mã Google Apps Script (tự động tạo Sheet & cột)</button>
            </p>
            <script type="text/template" id="mme-gas-template">
function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return ContentService.createTextOutput(JSON.stringify({success: false, error: "No data"}))
        .setMimeType(ContentService.MimeType.JSON);
    }
    
    var data = JSON.parse(e.postData.contents);
    if (data.event !== "lead_collected") {
      return ContentService.createTextOutput(JSON.stringify({success: false, error: "Not lead_collected event"}))
        .setMimeType(ContentService.MimeType.JSON);
    }
    
    // 1. Secret verification (tuỳ chọn, thay bằng secret bạn nhập trong cấu hình plugin)
    var EXPECTED_SECRET = ""; 
    if (EXPECTED_SECRET && data.secret !== EXPECTED_SECRET) {
      return ContentService.createTextOutput(JSON.stringify({success: false, error: "Invalid secret"}))
        .setMimeType(ContentService.MimeType.JSON);
    }
    
    // 2. Mở hoặc tạo sheet "data"
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = ss.getSheetByName("data");
    if (!sheet) {
      sheet = ss.insertSheet("data");
    }
    
    // 3. Xây dựng dữ liệu dòng
    var rowData = {};
    rowData["Date"] = new Date();
    
    if (data.contact) {
      if (data.contact.name) rowData["Name"] = data.contact.name;
      if (data.contact.phone) rowData["Phone"] = data.contact.phone;
      if (data.contact.email) rowData["Email"] = data.contact.email;
    }
    
    if (data.lead && data.lead.need) {
      rowData["Need"] = data.lead.need;
    }
    
    if (data.fields) {
      for (var key in data.fields) {
        rowData[key] = data.fields[key];
      }
    }
    
    if (data.source) {
      if (data.source.url) rowData["Source URL"] = data.source.url;
      if (data.source.referrer) rowData["Referrer"] = data.source.referrer;
    }
    if (data.attribution) {
      for (var key in data.attribution) {
        rowData[key] = data.attribution[key];
      }
    }
    
    // 4. Cập nhật tiêu đề cột (Headers) động
    var lastColumn = sheet.getLastColumn();
    var headers = [];
    if (lastColumn > 0) {
      headers = sheet.getRange(1, 1, 1, lastColumn).getValues()[0];
    }
    
    var headersUpdated = false;
    for (var key in rowData) {
      if (headers.indexOf(key) === -1) {
        headers.push(key);
        headersUpdated = true;
      }
    }
    
    if (headersUpdated) {
      sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
      sheet.getRange(1, 1, 1, headers.length).setFontWeight("bold");
    }
    
    // 5. Thêm dòng mới
    var newRow = [];
    for (var i = 0; i < headers.length; i++) {
      var header = headers[i];
      newRow.push(rowData[header] !== undefined ? rowData[header] : "");
    }
    sheet.appendRow(newRow);
    
    return ContentService.createTextOutput(JSON.stringify({success: true}))
      .setMimeType(ContentService.MimeType.JSON);
      
  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({success: false, error: error.toString()}))
      .setMimeType(ContentService.MimeType.JSON);
  }
}
            </script>
        </div>

        <div class="mme-integration-card">
            <h3>Twenty CRM</h3>
            <?php $this->toggle('twenty_enabled', 'Tạo People trong Twenty CRM', $settings['twenty_enabled']); ?>
            <div class="mme-admin-grid">
                <?php $this->url_input('twenty_base_url', 'Twenty base URL', $settings['twenty_base_url']); ?>
                <?php $this->password_input('twenty_api_key', 'Twenty API key', !empty($settings['twenty_api_key'])); ?>
            </div>
            <div style="margin-top: 15px;">
                <button type="button" class="button" id="mme-twenty-check-fields" data-nonce="<?php echo wp_create_nonce('mme_twenty_check'); ?>" data-post-id="<?php echo esc_attr((string)$post->ID); ?>">Kiểm tra trùng khớp tên Field</button>
                <span id="mme-twenty-check-status" style="margin-left: 10px; font-weight: 500;"></span>
                <div id="mme-twenty-check-results" style="margin-top: 10px; display: none; background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);"></div>
            </div>
        </div>

        <div class="mme-integration-card">
            <h3>Pancake CRM (crm.pancake.vn)</h3>
            <?php $this->toggle('pancake_enabled', 'Đồng bộ khách hàng sang Pancake CRM', $settings['pancake_enabled']); ?>
            <div class="mme-admin-grid">
                <?php $this->text_input('pancake_workspace_id', 'Workspace ID (Shop ID)', $settings['pancake_workspace_id']); ?>
                <?php $this->password_input('pancake_api_key', 'Pancake API Key', !empty($settings['pancake_api_key'])); ?>
                <?php $this->select_input('pancake_table', 'Bảng đích (Table)', $settings['pancake_table'], array('contact' => 'Contacts (Khách hàng)', 'lead' => 'Leads (Tiềm năng)')); ?>
            </div>
            <p class="description" style="margin-top: 10px; font-size: 12px; color: #64748b;">
                <strong>Hướng dẫn:</strong> Lấy API key tại: <code>crm.pancake.vn → Cài đặt (Settings) → Công cụ (Tools) → Tạo API Key</code>.<br>
                Workspace ID là dãy số trên URL khi đăng nhập workspace (ví dụ: <code>crm.pancake.vn/workspaces/<strong>1720028340</strong>/...</code>).
            </p>
        </div>
        <?php
    }

    public function render_embed_box(WP_Post $post): void
    {
        $shortcode_fields = '[mme_form_fields id="' . $post->ID . '"]';
        $preview_full_url = add_query_arg(array('mme_form_embed' => $post->ID, 'mode' => 'full'), home_url('/'));
        $build_info = MME_Form_Plugin::get_build_info();
        ?>
        <button type="button" class="button button-primary button-large mme-side-preview" data-mme-preview-toggle aria-pressed="false">
            <span class="dashicons dashicons-visibility" aria-hidden="true"></span> Xem trước trực tiếp
        </button>
        <a class="mme-side-link" href="<?php echo esc_url($preview_full_url); ?>" target="_blank" rel="noopener">Mở bản đã lưu trong tab mới ↗</a>

        <div class="mme-side-block">
            <strong>Shortcode chỉ form</strong>
            <div class="mme-copy-row">
                <input type="text" class="widefat code" id="mme-side-shortcode" value="<?php echo esc_attr($shortcode_fields); ?>" readonly>
                <button type="button" class="button mme-copy-btn" data-target="mme-side-shortcode">Copy</button>
            </div>
            <a href="#" class="mme-side-link" data-mme-open-pane="embed">Mã khác &amp; tuỳ chọn →</a>
        </div>

        <div class="mme-side-version">
            <span>MME Form <strong>v<?php echo esc_html($build_info['version']); ?></strong></span>
            <?php if (current_user_can('update_plugins')) : ?>
                <a href="#" class="mme-force-update-btn">Kiểm tra cập nhật</a>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_submission_box(WP_Post $post): void
    {
        echo $this->get_submission_html($post->ID, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function get_submission_html(int $post_id, bool $in_modal = true): string
    {
        $payload = (array) get_post_meta($post_id, '_mme_submission_payload', true);
        $integrations = array_filter((array) get_post_meta($post_id, '_mme_submission_integrations', true));
        $dispatch = (array) get_post_meta($post_id, '_mme_submission_dispatch', true);

        if (empty($payload)) {
            return '<div style="padding: 24px; text-align: center; color: #64748b;">Không có dữ liệu payload cho submission này.</div>';
        }

        $form_id = absint($payload['form']['id'] ?? get_post_meta($post_id, '_mme_submission_form_id', true));

        // Map form field names to human labels
        $field_labels = array();
        if ($form_id) {
            $raw_fields = get_post_meta($form_id, '_mme_form_fields', true);
            if (is_array($raw_fields)) {
                foreach ($raw_fields as $f) {
                    $name = $f['name'] ?? '';
                    $label = $f['label'] ?? $name;
                    if ($name) {
                        $field_labels[$name] = $label;
                        $field_labels[sanitize_key($name)] = $label;
                        $field_labels[trim(preg_replace('/\s+/', '_', $name))] = $label;
                    }
                }
            }
        }

        $contact = (array) ($payload['contact'] ?? array());
        $fields_data = (array) ($payload['fields'] ?? array());
        $source = (array) ($payload['source'] ?? array());
        $attribution = (array) ($payload['attribution'] ?? array());

        ob_start();
        ?>
        <div class="mme-qv-wrap">
            <!-- 1. THÔNG TIN KHÁCH HÀNG -->
            <div class="mme-qv-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; margin-bottom: 16px;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-admin-users" style="color: #2563eb;"></span> Khách hàng liên hệ
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                    <div>
                        <span style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Họ và tên</span>
                        <div style="font-size: 16px; font-weight: 700; color: #0f172a; margin-top: 2px;">
                            <?php echo esc_html($contact['name'] ?: 'Chưa cung cấp'); ?>
                        </div>
                    </div>
                    <div>
                        <span style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Số điện thoại</span>
                        <div style="font-size: 15px; font-weight: 600; color: #0f172a; margin-top: 2px;">
                            <?php if (!empty($contact['phone'])) : ?>
                                <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $contact['phone'])); ?>" style="color: #2563eb; text-decoration: none;">📞 <?php echo esc_html($contact['phone']); ?></a>
                            <?php else : ?>
                                <span style="color: #94a3b8;">Chưa có</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <span style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Email</span>
                        <div style="font-size: 15px; font-weight: 600; color: #0f172a; margin-top: 2px;">
                            <?php if (!empty($contact['email'])) : ?>
                                <a href="mailto:<?php echo esc_attr($contact['email']); ?>" style="color: #2563eb; text-decoration: none;">✉️ <?php echo esc_html($contact['email']); ?></a>
                            <?php else : ?>
                                <span style="color: #94a3b8;">Chưa có</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. CHI TIẾT TẤT CẢ CÁC TRƯỜNG KHÁCH ĐIỀN -->
            <div class="mme-qv-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; margin-bottom: 16px;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-clipboard" style="color: #10b981;"></span> Nội dung Form khách đã gửi
                </div>
                
                <?php if (!empty($fields_data)) : ?>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #f8fafc; text-align: left; color: #475569; border-bottom: 1px solid #e2e8f0;">
                                <th style="padding: 9px 12px; width: 35%;">Trường thông tin</th>
                                <th style="padding: 9px 12px;">Dữ liệu khách điền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $i = 0;
                            foreach ($fields_data as $key => $val) : 
                                $label = $field_labels[$key] ?? $field_labels[sanitize_key($key)] ?? ucfirst(str_replace('_', ' ', $key));
                                $bg = ($i++ % 2 === 0) ? '#ffffff' : '#f8fafc';
                                $val_display = is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE);
                            ?>
                                <tr style="background: <?php echo esc_attr($bg); ?>; border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px 12px; font-weight: 600; color: #334155; vertical-align: top;">
                                        <?php echo esc_html($label); ?>
                                        <div style="font-size: 11px; color: #94a3b8; font-weight: normal;">(<?php echo esc_html($key); ?>)</div>
                                    </td>
                                    <td style="padding: 10px 12px; color: #0f172a; vertical-align: top; white-space: pre-wrap; word-break: break-word;">
                                        <strong><?php echo esc_html($val_display); ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p style="color: #94a3b8; font-style: italic; margin: 0;">Không có dữ liệu trường.</p>
                <?php endif; ?>
            </div>

            <!-- 3. NGUỒN VÀ CHIẾN DỊCH THEO DÕI -->
            <div class="mme-qv-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; margin-bottom: 16px;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-admin-site-alt3" style="color: #f59e0b;"></span> Nguồn truy cập & Chiến dịch (Tracking)
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; font-size: 13px;">
                    <div>
                        <span style="color: #64748b;">Tên Form:</span>
                        <strong>
                            <?php if ($form_id) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($form_id)); ?>" target="_blank"><?php echo esc_html(get_the_title($form_id)); ?> (#<?php echo esc_html((string)$form_id); ?>) ↗</a>
                            <?php else : ?>
                                <em>Không xác định</em>
                            <?php endif; ?>
                        </strong>
                    </div>
                    <div>
                        <span style="color: #64748b;">Thời gian gửi:</span>
                        <strong><?php echo esc_html(get_the_date('d/m/Y H:i:s', $post_id)); ?></strong>
                    </div>
                    <?php if (($payload['channel'] ?? '') === 'zalo_miniapp') : ?>
                        <div style="grid-column: 1 / -1;">
                            <span style="color: #64748b;">Kênh:</span>
                            <strong>Zalo Mini App (MMeAuth)</strong>
                            <?php if (!array_filter($attribution)) : ?>
                                <span style="color: #94a3b8;">· mở trực tiếp, không có link nguồn (?src=)</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($source['url'])) : ?>
                        <div style="grid-column: 1 / -1;">
                            <span style="color: #64748b;">URL trang gửi form:</span>
                            <a href="<?php echo esc_url($source['url']); ?>" target="_blank" rel="noopener noreferrer" style="word-break: break-all;"><?php echo esc_html($source['url']); ?> ↗</a>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($source['referrer'])) : ?>
                        <div style="grid-column: 1 / -1;">
                            <span style="color: #64748b;">Nguồn giới thiệu (Referrer):</span>
                            <span style="color: #334155;"><?php echo esc_html($source['referrer']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php 
                    $utms = array_filter($attribution);
                    if (!empty($utms)) : 
                    ?>
                        <div style="grid-column: 1 / -1; margin-top: 6px; padding-top: 8px; border-top: 1px dashed #e2e8f0;">
                            <span style="font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">Thông số chiến dịch (UTM):</span>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php foreach ($utms as $utm_k => $utm_v) : ?>
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-size: 12px;">
                                        <strong><?php echo esc_html($utm_k); ?>:</strong> <?php echo esc_html((string)$utm_v); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 4. TRẠNG THÁI TÍCH HỢP -->
            <?php if (!empty($integrations) || !empty($dispatch)) : ?>
            <?php
            $dispatch_labels = array(
                'pending' => '⏳ Đang chờ gửi',
                'retrying' => '🔁 Có lỗi, sẽ tự thử lại',
                'failed' => '❌ Đã thử ' . (int) ($dispatch['attempts'] ?? 0) . ' lần, vẫn lỗi',
                'done' => '✅ Đã xử lý xong',
            );
            $dispatch_status = (string) ($dispatch['status'] ?? '');
            ?>
            <div class="mme-qv-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; margin-bottom: 16px;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    <span class="dashicons dashicons-networking" style="color: #8b5cf6;"></span> Trạng thái đồng bộ (Integrations)
                    <?php if (isset($dispatch_labels[$dispatch_status])) : ?>
                        <span style="text-transform: none; letter-spacing: 0; font-weight: 600; color: #334155;">· <?php echo esc_html($dispatch_labels[$dispatch_status]); ?></span>
                    <?php endif; ?>
                    <button type="button" class="button button-small mme-retry-integrations" data-id="<?php echo esc_attr((string) $post_id); ?>" style="margin-left: auto; text-transform: none; letter-spacing: 0;">
                        Gửi lại tích hợp lỗi
                    </button>
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 10px; font-size: 13px;">
                    <?php 
                    $int_labels = array('webhook' => 'Google Sheets Webhook', 'pancake' => 'Pancake CRM', 'twenty' => 'Twenty CRM');
                    foreach ($int_labels as $int_k => $int_title) :
                        if (isset($integrations[$int_k])) :
                            $res = $integrations[$int_k];
                            $is_ok = !empty($res['success']);
                            $is_skipped = !empty($res['skipped']);
                            $badge_color = $is_ok ? '#10b981' : ($is_skipped ? '#94a3b8' : '#ef4444');
                            $badge_bg = $is_ok ? '#ecfdf5' : ($is_skipped ? '#f8fafc' : '#fef2f2');
                            
                            $status_text = '❌ Thất bại';
                            if ($is_ok) {
                                $status_text = '✅ Thành công' . (!empty($res['status_code']) ? ' (HTTP ' . $res['status_code'] . ')' : '');
                            } elseif ($is_skipped) {
                                $status_text = '⚪ Chưa bật/cấu hình';
                            }

                            $detail_err = '';
                            if (!$is_ok && !$is_skipped) {
                                if (!empty($res['error'])) {
                                    $detail_err = (string) $res['error'];
                                } elseif (!empty($res['status_code'])) {
                                    $detail_err = 'Mã phản hồi: HTTP ' . $res['status_code'];
                                } elseif (!empty($res['response'])) {
                                    $detail_err = is_scalar($res['response']) ? (string) $res['response'] : wp_json_encode($res['response'], JSON_UNESCAPED_UNICODE);
                                }
                            }
                    ?>
                        <div style="flex: 1; min-width: 190px; padding: 8px 12px; background: <?php echo esc_attr($badge_bg); ?>; border: 1px solid <?php echo esc_attr($badge_color); ?>; border-radius: 6px;">
                            <div style="font-size: 11px; color: #64748b; font-weight: 600;"><?php echo esc_html($int_title); ?></div>
                            <div style="font-weight: 700; color: <?php echo esc_attr($badge_color); ?>; margin-top: 2px;">
                                <?php echo esc_html($status_text); ?>
                            </div>
                            <?php if ($detail_err !== '') : ?>
                                <div style="font-size: 11px; color: #b91c1c; margin-top: 4px; word-break: break-word; line-height: 1.3;">
                                    <?php echo esc_html($detail_err); ?>
                                </div>
                                <?php if (!empty($res['endpoint'])) : ?>
                                    <div style="font-size: 10px; color: #64748b; margin-top: 3px; font-family: monospace; word-break: break-all;">
                                        URL: <?php echo esc_html((string) $res['endpoint']); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($res['body']) && $is_ok) : ?>
                                <div style="font-size: 10px; color: #047857; margin-top: 3px; font-family: monospace; word-break: break-all;">
                                    Phản hồi: <?php echo esc_html($res['body']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- FOOTER ACTIONS & JSON DEBUG -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding-top: 12px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; gap: 10px;">
                <?php if ($in_modal) : ?>
                    <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" class="button button-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-edit" style="margin-top: 2px;"></span> Mở trang chi tiết Submission
                    </a>
                <?php else : ?>
                    <span style="font-size: 12px; color: #64748b;">ID Submission: #<?php echo esc_html((string)$post_id); ?></span>
                <?php endif; ?>
                <details style="font-size: 12px;">
                    <summary style="cursor: pointer; color: #64748b; font-weight: 500;">Xem mã JSON thô (Debug)</summary>
                    <pre class="mme-json-preview" style="max-height: 250px; margin-top: 8px;"><?php 
                        $debug_data = array(
                            'payload' => $payload,
                            'integrations' => $integrations,
                        );
                        echo esc_html(wp_json_encode($debug_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); 
                    ?></pre>
                </details>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function ajax_submission_quick_view(): void
    {
        check_ajax_referer('mme_form_admin_nonce', 'nonce');

        $post_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Không có quyền truy cập hoặc ID không hợp lệ.');
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'mme_form_submission') {
            wp_send_json_error('Submission không tồn tại.');
        }

        $html = $this->get_submission_html($post_id, true);

        wp_send_json_success(array(
            'title' => sprintf('Chi tiết Submission: %s', $this->plain_title($post_id)),
            'html'  => $html,
        ));
    }

    /** get_the_title() trả về thực thể HTML (vd "-" → &#8211;); admin.js gán bằng textContent nên phải giải mã. */
    private function plain_title(int $post_id): string
    {
        return html_entity_decode(get_the_title($post_id), ENT_QUOTES, 'UTF-8');
    }

    public function ajax_submission_retry(): void
    {
        check_ajax_referer('mme_form_admin_nonce', 'nonce');

        $post_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $post = $post_id ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'mme_form_submission' || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Không có quyền hoặc submission không tồn tại.');
        }

        MME_Form_Submissions::instance()->dispatch_integrations($post_id, true);

        wp_send_json_success(array(
            'title' => sprintf('Chi tiết Submission: %s', $this->plain_title($post_id)),
            'html' => $this->get_submission_html($post_id, !empty($_POST['in_modal'])),
        ));
    }

    public function register_settings_page(): void
    {
        add_submenu_page('edit.php?post_type=mme_form', 'Tích hợp chung', 'Tích hợp chung', 'manage_options', 'mme-form-settings', array($this, 'render_settings_page'));
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $global = MME_Form_Plugin::global_settings();
        $secret_keys = array('webhook_secret', 'twenty_api_key', 'pancake_api_key');
        $field = static function (string $key, string $label, string $type, string $help = '') use ($global, $secret_keys): void {
            $is_secret = in_array($key, $secret_keys, true);
            $value = $is_secret ? '' : (string) $global[$key];
            $placeholder = $is_secret && $global[$key] !== '' ? 'Đã lưu — để trống để giữ nguyên' : '';
            echo '<tr><th scope="row"><label for="mme-g-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            echo '<input class="regular-text" id="mme-g-' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="mme_global[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" autocomplete="off">';
            if ($is_secret && $global[$key] !== '') {
                echo ' <label><input type="checkbox" name="mme_global[' . esc_attr($key) . '_clear]" value="1"> Xoá</label>';
            }
            if ($help) {
                echo '<p class="description">' . wp_kses($help, array('code' => array(), 'a' => array('href' => array(), 'target' => array()), 'br' => array(), 'strong' => array())) . '</p>';
            }
            echo '</td></tr>';
        };
        $site_origin = untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_SCHEME) . '://' . wp_parse_url(home_url(), PHP_URL_HOST) . (wp_parse_url(home_url(), PHP_URL_PORT) ? ':' . wp_parse_url(home_url(), PHP_URL_PORT) : ''));
        ?>
        <div class="wrap mme-settings-page">
            <h1>Tích hợp chung</h1>
            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Đã lưu cài đặt.</p></div>
            <?php endif; ?>
            <p class="mme-settings-intro">Giá trị ở đây dùng cho <strong>mọi form</strong>. Form nào điền riêng trong tab Tích hợp thì dùng giá trị riêng của form đó.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mme_form_save_global">
                <?php wp_nonce_field('mme_form_save_global'); ?>

                <h2>Xác minh SĐT qua Zalo (MMeAuth)</h2>
                <table class="form-table" role="presentation">
                    <?php $field('mmeauth_site_key', 'Site key', 'text', 'Do MMe cấp khi đăng ký website <code>' . esc_html($site_origin) . '</code> với MMeAuth.'); ?>
                    <?php $field('mmeauth_url', 'Máy chủ MMeAuth', 'url', 'Để trống để dùng <code>' . esc_html(MME_Form_MMeAuth::DEFAULT_URL) . '</code>.'); ?>
                </table>

                <h2>Google Sheets / Webhook</h2>
                <table class="form-table" role="presentation">
                    <?php $field('webhook_url', 'Webhook URL', 'url'); ?>
                    <?php $field('webhook_secret', 'Signing secret', 'password'); ?>
                </table>

                <h2>Twenty CRM</h2>
                <table class="form-table" role="presentation">
                    <?php $field('twenty_base_url', 'Twenty base URL', 'url'); ?>
                    <?php $field('twenty_api_key', 'Twenty API key', 'password'); ?>
                </table>

                <h2>Pancake CRM</h2>
                <table class="form-table" role="presentation">
                    <?php $field('pancake_workspace_id', 'Workspace ID (Shop ID)', 'text'); ?>
                    <?php $field('pancake_api_key', 'Pancake API Key', 'password'); ?>
                </table>

                <?php submit_button('Lưu cài đặt chung'); ?>
            </form>
        </div>
        <?php
    }

    public function save_global_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bạn không có quyền.');
        }
        check_admin_referer('mme_form_save_global');

        $submitted = isset($_POST['mme_global']) && is_array($_POST['mme_global']) ? wp_unslash($_POST['mme_global']) : array();
        $current = MME_Form_Plugin::global_settings();
        $settings = array(
            'mmeauth_site_key' => sanitize_key($submitted['mmeauth_site_key'] ?? ''),
            'mmeauth_url' => esc_url_raw($submitted['mmeauth_url'] ?? ''),
            'webhook_url' => esc_url_raw($submitted['webhook_url'] ?? ''),
            'twenty_base_url' => esc_url_raw($submitted['twenty_base_url'] ?? ''),
            'pancake_workspace_id' => sanitize_text_field($submitted['pancake_workspace_id'] ?? ''),
        );
        foreach (array('webhook_secret', 'twenty_api_key', 'pancake_api_key') as $key) {
            $new_value = sanitize_text_field($submitted[$key] ?? '');
            if (!empty($submitted[$key . '_clear'])) {
                $settings[$key] = '';
            } else {
                $settings[$key] = $new_value !== '' ? $new_value : $current[$key];
            }
        }
        update_option('mme_form_global_settings', $settings, false);

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('edit.php?post_type=mme_form&page=mme-form-settings')));
        exit;
    }

    public function render_submission_quick_view_modal(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'mme_form_submission') {
            return;
        }
        ?>
        <div id="mme-quick-view-modal" class="mme-modal" style="display: none;" aria-hidden="true" role="dialog">
            <div class="mme-modal-backdrop" id="mme-quick-view-backdrop"></div>
            <div class="mme-modal-container" style="max-width: 820px; max-height: 85vh;">
                <header class="mme-modal-header" style="background: #0f172a; padding: 14px 20px;">
                    <div class="mme-modal-title" style="font-size: 14px; font-weight: 600; color: #f8fafc; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-id" style="color: #38bdf8; font-size: 20px; width: 20px; height: 20px;"></span>
                        <span id="mme-quick-view-title">Chi tiết Submission</span>
                    </div>
                    <button type="button" class="mme-modal-close" id="mme-quick-view-close" aria-label="Đóng">&times;</button>
                </header>
                <div class="mme-modal-body" id="mme-quick-view-content" style="padding: 20px; overflow-y: auto; background: #f8fafc;">
                    <div style="text-align: center; padding: 40px; color: #64748b;">
                        <span class="dashicons dashicons-update" style="animation: mme-spin 1.5s infinite linear; font-size: 28px; width: 28px; height: 28px;"></span>
                        <p style="margin-top: 10px; font-size: 14px;">Đang tải dữ liệu...</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_form(int $post_id): void
    {
        if (!isset($_POST['mme_form_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mme_form_nonce'])), 'mme_form_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = $this->sanitize_fields_input(isset($_POST['mme_form_fields_json']) ? wp_unslash($_POST['mme_form_fields_json']) : '[]');
        update_post_meta($post_id, '_mme_form_fields', $fields ?: MME_Form_Plugin::default_fields());

        $submitted = isset($_POST['mme_settings']) && is_array($_POST['mme_settings'])
            ? wp_unslash($_POST['mme_settings'])
            : array();
        update_post_meta($post_id, '_mme_form_settings', $this->sanitize_settings_input($submitted, $this->settings($post_id)));
    }

    /** Cài đặt đã lưu của form (chưa gộp Tích hợp chung). */
    public function stored_settings(int $post_id): array
    {
        return $this->settings($post_id);
    }

    /**
     * JSON các trường từ trình sửa form → mảng trường đã làm sạch.
     * Dùng khi lưu form và khi xem trước trực tiếp.
     */
    public function sanitize_fields_input(string $raw_fields): array
    {
        $decoded_fields = json_decode($raw_fields, true);
        $fields = array();
        if (is_array($decoded_fields)) {
            foreach ($decoded_fields as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $type = sanitize_key($field['type'] ?? 'text');
                if (!in_array($type, array('text', 'email', 'tel', 'date', 'time', 'textarea', 'select', 'radio'), true)) {
                    $type = 'text';
                }
                $label = sanitize_text_field($field['label'] ?? 'Field ' . ($index + 1));
                $name = sanitize_text_field($field['name'] ?? '');
                $name = trim(preg_replace('/\s+/', '_', $name));
                if (!$name) {
                    $name = sanitize_title($label);
                }
                if (!$name) {
                    $name = 'field_' . ($index + 1);
                }
                $options = array_filter(array_map('sanitize_text_field', (array) ($field['options'] ?? array())));
                $fields[] = array(
                    'label' => $label,
                    'name' => $name,
                    'width' => in_array($field['width'] ?? '', array('100', '50'), true) ? $field['width'] : '100',
                    'type' => $type,
                    'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
                    'required' => !empty($field['required']),
                    'options' => array_values($options),
                );
            }
        }
        return $fields;
    }

    /**
     * mme_settings[] từ trình sửa form → cài đặt đã làm sạch.
     * $current giữ secret và giá trị không có trong lần gửi này.
     */
    public function sanitize_settings_input(array $submitted, array $current): array
    {
        $settings = MME_Form_Plugin::default_settings();

        foreach (array('kicker', 'heading', 'subheading', 'description', 'form_heading', 'form_footer', 'hotline_label', 'hotline', 'email_label', 'support_email', 'social_label', 'button_text', 'success_message', 'chatbot_tenant', 'chatbot_button_text') as $key) {
            $settings[$key] = sanitize_text_field($submitted[$key] ?? $current[$key] ?? '');
        }
        foreach (array('facebook_url', 'zalo_url', 'linkedin_url', 'tiktok_url', 'youtube_url', 'chatbot_base_url', 'webhook_url', 'twenty_base_url') as $key) {
            $settings[$key] = esc_url_raw($submitted[$key] ?? ($current[$key] ?? ''));
        }
        $defaults = MME_Form_Plugin::default_settings();
        $color_keys = array(
            'button_color', 'button_text_color', 'secondary_color', 'accent_color',
            'background_color', 'text_color', 'form_card_bg', 'form_card_border',
            'field_bg_color', 'field_border_color', 'field_text_color',
            'field_label_color', 'field_placeholder_color', 'form_heading_color'
        );
        foreach ($color_keys as $key) {
            $val = $submitted[$key] ?? '';
            if ($val === 'transparent') {
                $settings[$key] = 'transparent';
            } else {
                $settings[$key] = sanitize_hex_color($val) ?: ($current[$key] ?? ($defaults[$key] ?? ''));
            }
        }
        $settings['layout_mode'] = in_array(($submitted['layout_mode'] ?? ''), array('full', 'form_only'), true) ? $submitted['layout_mode'] : 'full';
        $settings['fields_card_style'] = in_array(($submitted['fields_card_style'] ?? 'card'), array('card', 'transparent'), true) ? $submitted['fields_card_style'] : 'card';
        $settings['fields_show_heading'] = ($submitted['fields_show_heading'] ?? 'yes') === 'no' ? 'no' : 'yes';
        $image_pos = $submitted['image_position'] ?? '';
        if ($image_pos === 'none') {
            $image_pos = 'hidden';
        }
        $settings['image_position'] = in_array($image_pos, array('left', 'right', 'top', 'hidden'), true) ? $image_pos : 'left';
        $settings['form_padding'] = in_array(($submitted['form_padding'] ?? ''), array('default', 'spacious', 'compact', 'small', 'none'), true) ? $submitted['form_padding'] : 'default';
        $settings['form_field_gap'] = in_array(($submitted['form_field_gap'] ?? ''), array('default', 'compact', 'small', 'relaxed'), true) ? $submitted['form_field_gap'] : 'default';
        $settings['form_border_radius'] = in_array(($submitted['form_border_radius'] ?? ''), array('default', 'medium', 'small', 'none', 'full'), true) ? $submitted['form_border_radius'] : 'default';
        $settings['form_max_width'] = in_array(($submitted['form_max_width'] ?? ''), array('680px', '540px', '800px', '100%'), true) ? $submitted['form_max_width'] : '680px';
        $settings['form_shadow'] = in_array(($submitted['form_shadow'] ?? ''), array('default', 'none', 'subtle', 'strong'), true) ? $submitted['form_shadow'] : 'default';
        $settings['font_family'] = in_array(($submitted['font_family'] ?? ''), array('system', 'inter', 'arial', 'georgia'), true) ? $submitted['font_family'] : 'system';
        $settings['trust_items'] = array_slice(array_values(array_filter(array_map('sanitize_text_field', preg_split('/\r\n|\r|\n/', (string) ($submitted['trust_items'] ?? ''))))), 0, 4);
        foreach (array('chatbot_enabled', 'webhook_enabled', 'twenty_enabled', 'pancake_enabled', 'mmeauth_enabled') as $key) {
            $settings[$key] = ($submitted[$key] ?? 'no') === 'yes' ? 'yes' : 'no';
        }
        foreach (array('webhook_secret', 'twenty_api_key', 'pancake_api_key') as $key) {
            $new_value = sanitize_text_field($submitted[$key] ?? '');
            // Tick "xoá" để quay về dùng secret ở Tích hợp chung
            if (!empty($submitted[$key . '_clear'])) {
                $settings[$key] = '';
            } else {
                $settings[$key] = $new_value !== '' ? $new_value : $current[$key];
            }
        }
        $settings['pancake_workspace_id'] = sanitize_text_field($submitted['pancake_workspace_id'] ?? '');
        $settings['pancake_table'] = in_array($submitted['pancake_table'] ?? '', array('contact', 'lead'), true) ? $submitted['pancake_table'] : 'contact';

        return $settings;
    }

    public function form_columns(array $columns): array
    {
        $columns['mme_shortcode'] = 'Shortcode (Full)';
        $columns['mme_shortcode_fields'] = 'Shortcode (Chỉ Form)';
        $columns['mme_embed'] = 'External embed';
        return $columns;
    }

    public function render_form_column(string $column, int $post_id): void
    {
        if ($column === 'mme_shortcode') {
            echo '<code>[mme_form id=&quot;' . esc_html((string) $post_id) . '&quot;]</code>';
        }
        if ($column === 'mme_shortcode_fields') {
            echo '<code>[mme_form_fields id=&quot;' . esc_html((string) $post_id) . '&quot;]</code>';
        }
        if ($column === 'mme_embed') {
            echo '<code>?mme_form_embed=' . esc_html((string) $post_id) . '</code>';
        }
    }

    public function submission_columns(array $columns): array
    {
        return array(
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => 'Submission',
            'mme_quick_view' => 'Xem nhanh',
            'mme_form' => 'Form',
            'mme_contact' => 'Liên hệ',
            'mme_source' => 'Nguồn',
            'date' => 'Date',
        );
    }

    public function render_submission_column(string $column, int $post_id): void
    {
        $payload = (array) get_post_meta($post_id, '_mme_submission_payload', true);
        if ($column === 'mme_quick_view') {
            echo '<button type="button" class="button button-small mme-open-quick-view" data-id="' . esc_attr((string) $post_id) . '" style="display:inline-flex;align-items:center;gap:4px;color:#2563eb;border-color:#93c5fd;background:#eff6ff;font-weight:600;padding:2px 8px;">
                <span class="dashicons dashicons-visibility" style="font-size:15px;width:15px;height:15px;margin-top:2px;"></span> Xem nhanh
            </button>';
        } elseif ($column === 'mme_form') {
            $form_id = absint($payload['form']['id'] ?? 0);
            echo $form_id ? '<a href="' . esc_url(get_edit_post_link($form_id)) . '">' . esc_html($payload['form']['title'] ?? '#' . $form_id) . '</a>' : '—';
        } elseif ($column === 'mme_contact') {
            $contact = (array) ($payload['contact'] ?? array());
            echo esc_html(implode(' · ', array_filter(array($contact['name'] ?? '', $contact['phone'] ?? '', $contact['email'] ?? ''))) ?: '—');
        } elseif ($column === 'mme_source') {
            $url = esc_url($payload['source']['url'] ?? '');
            echo $url ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html(wp_parse_url($url, PHP_URL_HOST) ?: $url) . '</a>' : '—';
        }
    }

    /** Lọc dữ liệu gửi theo form + nút xuất CSV (theo đúng bộ lọc đang chọn). */
    public function render_submission_filters($post_type, $which = 'top'): void
    {
        if ($post_type !== 'mme_form_submission' || $which !== 'top') {
            return;
        }
        $current = isset($_GET['mme_form_filter']) ? absint($_GET['mme_form_filter']) : 0;
        $forms = get_posts(array('post_type' => 'mme_form', 'post_status' => 'any', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC'));
        echo '<label class="screen-reader-text" for="mme-form-filter">Lọc theo form</label>';
        echo '<select name="mme_form_filter" id="mme-form-filter"><option value="0">Tất cả form</option>';
        foreach ($forms as $form) {
            echo '<option value="' . esc_attr((string) $form->ID) . '" ' . selected($current, $form->ID, false) . '>' . esc_html($form->post_title ?: '#' . $form->ID) . '</option>';
        }
        echo '</select>';
        if (current_user_can('edit_posts')) {
            wp_nonce_field('mme_export_csv', 'mme_csv_nonce', false);
            echo ' <button type="submit" name="mme_export_csv" value="1" class="button mme-export-btn"><span class="dashicons dashicons-download" aria-hidden="true"></span> Xuất CSV</button>';
        }
    }

    public function filter_submissions_query(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'mme_form_submission') {
            return;
        }
        $form_id = isset($_GET['mme_form_filter']) ? absint($_GET['mme_form_filter']) : 0;
        if ($form_id) {
            $query->set('meta_query', array(array('key' => '_mme_submission_form_id', 'value' => $form_id)));
        }
    }

    /**
     * Xuất dữ liệu gửi ra CSV (UTF-8 có BOM để Excel đọc đúng tiếng Việt), theo bộ lọc form / tháng / tìm kiếm.
     */
    public function maybe_export_submissions(): void
    {
        if (empty($_GET['mme_export_csv']) || ($_GET['post_type'] ?? '') !== 'mme_form_submission') {
            return;
        }
        if (!current_user_can('edit_posts') || !isset($_GET['mme_csv_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['mme_csv_nonce'])), 'mme_export_csv')) {
            wp_die('Bạn không có quyền xuất dữ liệu.');
        }

        $args = array(
            'post_type' => 'mme_form_submission',
            'post_status' => 'publish',
            'posts_per_page' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        );
        $form_id = isset($_GET['mme_form_filter']) ? absint($_GET['mme_form_filter']) : 0;
        if ($form_id) {
            $args['meta_query'] = array(array('key' => '_mme_submission_form_id', 'value' => $form_id));
        }
        if (!empty($_GET['m'])) {
            $args['m'] = absint($_GET['m']);
        }
        if (!empty($_GET['s'])) {
            $args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
        }

        // Gom tất cả submission + nhãn của từng trường (theo cấu hình form) để làm cột
        $rows = array();
        $field_labels = array();
        $form_labels = array();
        for ($page = 1; ; $page++) {
            $ids = get_posts($args + array('paged' => $page));
            foreach ($ids as $id) {
                $payload = (array) get_post_meta($id, '_mme_submission_payload', true);
                $fid = absint($payload['form']['id'] ?? get_post_meta($id, '_mme_submission_form_id', true));
                if ($fid && !isset($form_labels[$fid])) {
                    $form_labels[$fid] = array();
                    foreach ((array) get_post_meta($fid, '_mme_form_fields', true) as $field) {
                        if (!empty($field['name'])) {
                            $form_labels[$fid][$field['name']] = $field['label'] ?? $field['name'];
                        }
                    }
                }
                foreach ((array) ($payload['fields'] ?? array()) as $key => $value) {
                    if (!isset($field_labels[$key])) {
                        $field_labels[$key] = $form_labels[$fid][$key] ?? $key;
                    }
                }
                $dispatch = (array) get_post_meta($id, '_mme_submission_dispatch', true);
                $rows[] = array('id' => $id, 'payload' => $payload, 'status' => $dispatch['status'] ?? '');
            }
            if (count($ids) < $args['posts_per_page']) {
                break;
            }
        }

        // Bỏ cột trường trùng với Họ tên / SĐT / Email (đã có cột riêng)
        foreach (array_keys($field_labels) as $key) {
            $duplicate = true;
            foreach ($rows as $row) {
                $value = (string) ($row['payload']['fields'][$key] ?? '');
                $contact = array_map('strval', (array) ($row['payload']['contact'] ?? array()));
                if ($value !== '' && !in_array($value, array($contact['name'] ?? '', $contact['phone'] ?? '', $contact['email'] ?? ''), true)) {
                    $duplicate = false;
                    break;
                }
            }
            if ($duplicate) {
                unset($field_labels[$key]);
            }
        }

        $channels = array('mme_form' => 'Website', 'zalo_miniapp' => 'Zalo Mini App');
        $utm_keys = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid');
        $header = array_merge(
            array('Thời gian', 'Form', 'Kênh', 'Họ tên', 'Số điện thoại', 'Email'),
            array_values($field_labels),
            array('Trang gửi', 'Trang trước đó'),
            $utm_keys,
            array('Tích hợp')
        );

        $filename = 'du-lieu-gui-' . ($form_id ? $form_id . '-' : '') . wp_date('Y-m-d-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $row) {
            $p = $row['payload'];
            $contact = (array) ($p['contact'] ?? array());
            $fields = (array) ($p['fields'] ?? array());
            $line = array(
                get_the_date('Y-m-d H:i:s', $row['id']),
                $p['form']['title'] ?? '',
                $channels[$p['channel'] ?? ''] ?? ($p['channel'] ?? ''),
                $contact['name'] ?? '',
                $contact['phone'] ?? '',
                $contact['email'] ?? '',
            );
            foreach (array_keys($field_labels) as $key) {
                $value = $fields[$key] ?? '';
                $line[] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
            $line[] = $p['source']['url'] ?? '';
            $line[] = $p['source']['referrer'] ?? '';
            foreach ($utm_keys as $key) {
                $line[] = $p['attribution'][$key] ?? '';
            }
            $line[] = $row['status'];
            // Chặn công thức Excel trong dữ liệu khách nhập; số điện thoại dạng +84… vẫn giữ nguyên
            $line = array_map(static function ($cell) {
                $cell = (string) $cell;
                $formula = preg_match('/^[=@\t\r]/', $cell)
                    || (preg_match('/^[+\-]/', $cell) && !preg_match('/^[+\-][\d\s().]*$/', $cell));
                return $formula ? "'" . $cell : $cell;
            }, $line);
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    public function remove_submission_quick_actions(array $actions, WP_Post $post): array
    {
        if ($post->post_type === 'mme_form_submission') {
            unset($actions['inline hide-if-no-js']);
            $actions['mme_quick_view'] = '<a href="#" class="mme-open-quick-view" data-id="' . esc_attr((string) $post->ID) . '" style="color:#2563eb;font-weight:600;">👁️ Xem nhanh</a>';
        }
        return $actions;
    }

    public function ajax_twenty_check_fields(): void
    {
        check_ajax_referer('mme_twenty_check', 'nonce');
        
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Permission denied');
        }
        
        $settings = MME_Form_Plugin::form_settings($post_id);
        
        if (empty($settings['twenty_base_url']) || empty($settings['twenty_api_key'])) {
            wp_send_json_error('Vui lòng điền đủ Twenty URL và API Key trước khi kiểm tra.');
        }
        
        $base_url = untrailingslashit(esc_url_raw($settings['twenty_base_url']));
        $api_key = sanitize_text_field($settings['twenty_api_key']);
        $url = $base_url . (substr(strtolower($base_url), -5) === '/rest' ? '/metadata/objects' : '/rest/metadata/objects');
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 10,
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Lỗi kết nối tới Twenty CRM: ' . $response->get_error_message());
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error('Lỗi kết nối tới Twenty CRM (HTTP ' . wp_remote_retrieve_response_code($response) . ')');
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $twenty_fields = array();
        
        if (!empty($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $obj) {
                if (($obj['nameSingular'] ?? '') === 'person' && !empty($obj['fields'])) {
                    foreach ($obj['fields'] as $field) {
                        if (!empty($field['name'])) {
                            $twenty_fields[$field['name']] = $field;
                        }
                    }
                    break;
                }
            }
        }
        
        if (empty($twenty_fields)) {
            wp_send_json_error('Không lấy được danh sách field từ Twenty CRM. Vui lòng kiểm tra lại URL/Key.');
        }
        
        // Cache this rich metadata for submissions
        update_post_meta($post_id, '_mme_twenty_person_metadata', $twenty_fields);
        
        $form_fields = get_post_meta($post_id, '_mme_form_fields', true);
        $form_fields = is_array($form_fields) && $form_fields ? $form_fields : MME_Form_Plugin::default_fields();
        
        $form_fields[] = array('label' => 'URL gửi form (Ẩn)', 'name' => 'current_url', 'type' => 'hidden');
        $form_fields[] = array('label' => 'Nguồn truy cập (Ẩn)', 'name' => 'referrer_url', 'type' => 'hidden');
        $form_fields[] = array('label' => 'Thời gian bắt đầu (Ẩn)', 'name' => 'started_at', 'type' => 'hidden');
        
        $standard_keys = array('full_name', 'name', 'fullname', 'ho_ten', 'hoten', 'phone', 'telephone', 'mobile', 'so_dien_thoai', 'sdt', 'email', 'email_address', 'need');
        
        $results = array();
        $all_good = true;
        
        foreach ($form_fields as $field) {
            $name = sanitize_text_field($field['name'] ?? '');
            $name = trim(preg_replace('/\s+/', '_', $name));
            if (!$name) continue;
            
            $mme_type = sanitize_text_field($field['type'] ?? 'text');
            $status = 'red';
            $message = '';
            
            if (in_array(strtolower($name), $standard_keys, true)) {
                $status = 'green';
                $message = 'Trường mặc định';
            } else {
                $matched_key = null;
                $camel_name = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
                
                if (isset($twenty_fields[$name])) {
                    $matched_key = $name;
                } elseif (isset($twenty_fields[$name . 'Custom'])) {
                    $matched_key = $name . 'Custom';
                } elseif (isset($twenty_fields[$camel_name])) {
                    $matched_key = $camel_name;
                } elseif (isset($twenty_fields[$camel_name . 'Custom'])) {
                    $matched_key = $camel_name . 'Custom';
                }
                
                if ($matched_key) {
                    $status = 'green';
                    $twenty_type = $twenty_fields[$matched_key]['type'] ?? '';
                    
                    // Cross check types
                    if (in_array($mme_type, array('select', 'radio', 'checkbox')) && !in_array($twenty_type, array('SELECT', 'MULTI_SELECT'))) {
                        $message = 'Cảnh báo: Form là ' . $mme_type . ' nhưng CRM là ' . $twenty_type . ' (Vẫn có thể hoạt động nhưng nên cẩn thận)';
                        $status = 'orange'; // Will style this dynamically in JS
                    } elseif ($mme_type === 'number' && $twenty_type !== 'NUMBER' && $twenty_type !== 'CURRENCY') {
                        $message = 'Cảnh báo: Form là Số nhưng CRM là ' . $twenty_type;
                        $status = 'orange';
                    } elseif ($twenty_type === 'SELECT' || $twenty_type === 'MULTI_SELECT') {
                        $message = 'CRM là SELECT (Plugin sẽ tự động map option value)';
                    } else {
                        $message = 'Loại dữ liệu: ' . $twenty_type;
                    }
                } else {
                    $all_good = false;
                    $message = 'Không tìm thấy field này trong Twenty CRM';
                }
            }
            
            $results[] = array(
                'label' => sanitize_text_field($field['label'] ?? $name),
                'name' => $name,
                'status' => $status,
                'message' => $message
            );
        }
        
        $dummy_fields = array();
        foreach ($form_fields as $field) {
            $name = sanitize_text_field($field['name'] ?? '');
            $name = trim(preg_replace('/\s+/', '_', $name));
            if (!$name) continue;
            
            if ($name === 'full_name' || $name === 'name' || $name === 'fullname' || $name === 'ho_ten') {
                $dummy_fields[$name] = 'MME Test Lead';
            } elseif ($name === 'phone' || $name === 'telephone' || $name === 'so_dien_thoai' || $name === 'sdt') {
                $dummy_fields[$name] = '090' . mt_rand(1000000, 9999999);
            } elseif ($name === 'email' || $name === 'email_address') {
                $dummy_fields[$name] = 'test-' . mt_rand(1000, 9999) . '@mme.vn';
            } elseif (in_array($field['type'] ?? '', array('select', 'radio', 'checkbox'))) {
                $dummy_fields[$name] = !empty($field['options'][0]) ? $field['options'][0] : 'Test Option';
            } elseif (($field['type'] ?? '') === 'number') {
                $dummy_fields[$name] = 123;
            } else {
                $dummy_fields[$name] = 'Test ' . $name;
            }
        }
        
        $dummy_payload = array(
            'contact' => array('name' => 'MME Test Lead', 'phone' => '090' . mt_rand(1000000, 9999999), 'email' => 'test-' . mt_rand(1000, 9999) . '@mme.vn'),
            'lead' => array('need' => 'Test Need'),
            'source' => array('url' => 'https://mme.vn/test', 'referrer' => 'https://google.com'),
            'started_at' => time() * 1000,
            'fields' => $dummy_fields
        );
        
        $submissions = MME_Form_Submissions::instance();
        $test_result = $submissions->send_twenty($post_id, $settings, $dummy_payload);
        
        if (!empty($test_result['success']) && !empty($test_result['response']['data']['id'])) {
            $person_id = $test_result['response']['data']['id'];
            $delete_url = rtrim($settings['twenty_base_url'], '/') . '/people/' . $person_id;
            wp_remote_request($delete_url, array(
                'method' => 'DELETE',
                'headers' => array('Authorization' => 'Bearer ' . $settings['twenty_api_key']),
            ));
        }

        wp_send_json_success(array(
            'results' => $results,
            'all_good' => $all_good,
            'twenty_fields' => array_keys($twenty_fields),
            'test_result' => $test_result
        ));
    }

    private function settings(int $post_id): array
    {
        return wp_parse_args((array) get_post_meta($post_id, '_mme_form_settings', true), MME_Form_Plugin::default_settings());
    }

    private function text_input(string $key, string $label, string $value): void
    {
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . '</span><input type="text" name="mme_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"></label>';
    }

    private function url_input(string $key, string $label, string $value): void
    {
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . '</span><input type="url" name="mme_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" placeholder="https://..."></label>';
    }

    private function color_input(string $key, string $label, string $value): void
    {
        $picker_val = (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) ? $value : '#ffffff';
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . '</span><input type="color" name="mme_settings[' . esc_attr($key) . ']" value="' . esc_attr($picker_val) . '"></label>';
    }

    private function password_input(string $key, string $label, bool $is_set): void
    {
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . ($is_set ? ' <small>(đã lưu)</small>' : '') . '</span><input type="password" name="mme_settings[' . esc_attr($key) . ']" value="" autocomplete="new-password" placeholder="' . ($is_set ? 'Để trống để giữ secret cũ' : 'Để trống để dùng Tích hợp chung') . '">';
        if ($is_set) {
            echo '<small><label><input type="checkbox" name="mme_settings[' . esc_attr($key) . '_clear]" value="1"> Xoá, dùng Tích hợp chung</label></small>';
        }
        echo '</label>';
    }

    private function select_input(string $key, string $label, string $value, array $options): void
    {
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . '</span><select name="mme_settings[' . esc_attr($key) . ']">';
        foreach ($options as $opt_val => $opt_label) {
            echo '<option value="' . esc_attr($opt_val) . '" ' . selected($value, $opt_val, false) . '>' . esc_html($opt_label) . '</option>';
        }
        echo '</select></label>';
    }

    private function toggle(string $key, string $label, string $value): void
    {
        echo '<label class="mme-admin-toggle"><input type="hidden" name="mme_settings[' . esc_attr($key) . ']" value="no"><input type="checkbox" name="mme_settings[' . esc_attr($key) . ']" value="yes" ' . checked($value, 'yes', false) . '><span>' . esc_html($label) . '</span></label>';
    }

    public function plugin_row_meta($meta = array(), $file = '')
    {
        $meta = is_array($meta) ? $meta : array();
        if (defined('MME_FORM_FILE') && is_string($file) && plugin_basename(MME_FORM_FILE) === $file) {
            $info = MME_Form_Plugin::get_build_info();
            $meta[] = sprintf(
                '<span style="color:#059669; font-weight: 600;">🕒 Bản cập nhật: %s%s</span>',
                esc_html($info['date']),
                !empty($info['commit']) ? ' (<code>' . esc_html($info['commit']) . '</code>)' : ''
            );
            if (current_user_can('update_plugins')) {
                $meta[] = '<a href="#" class="mme-force-update-btn" style="color: #0284c7; font-weight: 600;">Kiểm tra cập nhật</a>';
            }
        }
        return $meta;
    }

    public function admin_footer_text($text = '')
    {
        $text = is_scalar($text) ? (string) $text : '';
        if (!function_exists('get_current_screen')) {
            return $text;
        }
        $screen = get_current_screen();
        if ($screen && !empty($screen->post_type) && in_array($screen->post_type, array('mme_form', 'mme_form_submission'), true)) {
            $info = MME_Form_Plugin::get_build_info();
            return sprintf(
                '<strong>MME Form</strong> v%s &bull; Bản cập nhật: <strong>%s</strong>%s',
                esc_html($info['version']),
                esc_html($info['date']),
                !empty($info['commit']) ? ' &bull; Git: <code>' . esc_html($info['commit']) . '</code>' : ''
            );
        }
        return $text;
    }

    public function render_admin_version_notice(): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || empty($screen->post_type) || $screen->post_type !== 'mme_form' || empty($screen->base) || $screen->base !== 'edit') {
            return;
        }
        $info = MME_Form_Plugin::get_build_info();
        $updater = MME_Form_Updater::instance();
        $remote = get_site_transient(MME_Form_Updater::CACHE);
        $has_update = $updater->has_update(false);
        ?>
        <div class="notice notice-info" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 14px; background: #f8fafc; border-left: 4px solid <?php echo $has_update ? '#d97706' : '#0284c7'; ?>; margin: 15px 0 10px;">
            <span style="font-size: 13px; color: #0f172a;">
                <strong>MME Form v<?php echo esc_html($info['version']); ?></strong> &bull; 
                Bản cập nhật lúc: <strong><?php echo esc_html($info['date']); ?></strong>
                <?php if (!empty($info['commit'])) : ?>
                    &bull; Git Commit: <code><?php echo esc_html($info['commit']); ?></code> (<?php echo esc_html($info['branch']); ?>)
                <?php endif; ?>
            </span>
            <div style="display: flex; align-items: center; gap: 8px;">
                <?php if ($has_update && current_user_can('update_plugins')) : ?>
                    <button type="button" class="button button-primary mme-force-update-btn" style="display: inline-flex; align-items: center; gap: 4px; font-weight: 500;">
                        <span class="dashicons dashicons-download" style="margin-top: 2px;"></span> Cập nhật lên <?php echo esc_html($remote['version']); ?>
                    </button>
                <?php elseif (current_user_can('update_plugins')) : ?>
                    <button type="button" class="button button-secondary mme-force-update-btn" style="display: inline-flex; align-items: center; gap: 4px; font-weight: 500;">
                        <span class="dashicons dashicons-update" style="margin-top: 2px;"></span> Kiểm tra cập nhật
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
}

