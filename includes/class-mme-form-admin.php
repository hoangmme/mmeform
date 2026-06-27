<?php

if (!defined('ABSPATH')) {
    exit;
}

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
    }

    public function enqueue_assets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array('mme_form', 'mme_form_submission'), true)) {
            return;
        }

        wp_enqueue_style('mme-form-admin', MME_FORM_URL . 'assets/admin.css', array(), MME_FORM_VERSION);

        if ($screen->post_type === 'mme_form') {
            wp_enqueue_media();
            wp_enqueue_script('mme-form-admin', MME_FORM_URL . 'assets/admin.js', array(), MME_FORM_VERSION, true);
        }
    }

    public function register_meta_boxes(): void
    {
        add_meta_box('mme-form-fields', __('Form fields', 'mme-form'), array($this, 'render_fields_box'), 'mme_form', 'normal', 'high');
        add_meta_box('mme-form-appearance', __('Compact appearance', 'mme-form'), array($this, 'render_appearance_box'), 'mme_form', 'normal', 'default');
        add_meta_box('mme-form-integrations', __('Integrations', 'mme-form'), array($this, 'render_integrations_box'), 'mme_form', 'normal', 'default');
        add_meta_box('mme-form-embed', __('Publish & embed', 'mme-form'), array($this, 'render_embed_box'), 'mme_form', 'side', 'high');
        add_meta_box('mme-form-submission-data', __('Submission data', 'mme-form'), array($this, 'render_submission_box'), 'mme_form_submission', 'normal', 'high');
    }

    public function render_fields_box(WP_Post $post): void
    {
        $fields = get_post_meta($post->ID, '_mme_form_fields', true);
        $fields = is_array($fields) && $fields ? $fields : MME_Form_Plugin::default_fields();
        wp_nonce_field('mme_form_save', 'mme_form_nonce');
        ?>
        <p class="description">Text, email, phone, textarea, dropdown và radio. Kéo thả bằng nút ⋮⋮ để đổi thứ tự.</p>
        <div id="mme-form-builder" data-fields="<?php echo esc_attr(wp_json_encode($fields)); ?>">
            <input type="hidden" id="mme-form-fields-json" name="mme_form_fields_json" value="<?php echo esc_attr(wp_json_encode($fields)); ?>">
            <table class="widefat striped mme-fields-table">
                <thead>
                    <tr>
                        <th></th><th>Nhãn</th><th>Tên field</th><th>Độ rộng</th><th>Loại</th><th>Placeholder / options</th><th>Bắt buộc</th><th></th>
                    </tr>
                </thead>
                <tbody id="mme-fields-body"></tbody>
            </table>
            <button type="button" class="button button-secondary" id="mme-add-field">+ Thêm field</button>
        </div>
        <?php
    }

    public function render_appearance_box(WP_Post $post): void
    {
        $settings = $this->settings($post->ID);
        ?>
        <div class="mme-admin-grid">
            <?php $this->text_input('kicker', 'Chữ nhỏ trên đầu', $settings['kicker']); ?>
            <?php $this->text_input('heading', 'Tiêu đề', $settings['heading']); ?>
            <?php $this->text_input('description', 'Mô tả ngắn', $settings['description']); ?>
            <?php $this->text_input('button_text', 'Chữ trên nút', $settings['button_text']); ?>
            <?php $this->text_input('success_message', 'Thông báo thành công', $settings['success_message']); ?>

            <label class="mme-admin-field mme-admin-span-2">
                <span>Ảnh minh họa</span>
                <div class="mme-media-row">
                    <input type="url" id="mme-image-url" name="mme_settings[image_url]" value="<?php echo esc_attr($settings['image_url']); ?>" placeholder="https://...">
                    <button type="button" class="button" id="mme-pick-image">Chọn ảnh</button>
                </div>
            </label>

            <label class="mme-admin-field">
                <span>Vị trí ảnh</span>
                <select name="mme_settings[image_position]">
                    <?php foreach (array('left' => 'Trái', 'right' => 'Phải', 'top' => 'Trên') as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['image_position'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Font chữ</span>
                <select name="mme_settings[font_family]">
                    <?php foreach (array('system' => 'System / Inter', 'inter' => 'Inter', 'arial' => 'Arial', 'georgia' => 'Georgia') as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['font_family'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php $this->color_input('button_color', 'Màu nút', $settings['button_color']); ?>
            <?php $this->color_input('accent_color', 'Màu nhấn', $settings['accent_color']); ?>
            <?php $this->color_input('background_color', 'Màu nền', $settings['background_color']); ?>
            <?php $this->color_input('text_color', 'Màu chữ', $settings['text_color']); ?>

            <label class="mme-admin-field mme-admin-span-2">
                <span>Điểm nổi bật (mỗi dòng một mục, tối đa 4)</span>
                <textarea name="mme_settings[trust_items]" rows="4"><?php echo esc_textarea(implode("\n", (array) $settings['trust_items'])); ?></textarea>
            </label>

            <?php $this->url_input('facebook_url', 'Facebook URL', $settings['facebook_url']); ?>
            <?php $this->url_input('zalo_url', 'Zalo URL', $settings['zalo_url']); ?>
            <?php $this->url_input('linkedin_url', 'LinkedIn URL', $settings['linkedin_url']); ?>
        </div>
        <?php
    }

    public function render_integrations_box(WP_Post $post): void
    {
        $settings = $this->settings($post->ID);
        ?>
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
            <p class="description">Google Apps Script có thể dùng URL dạng <code>.../exec?secret=...</code>. Payload dùng chuẩn <code>lead_collected</code> giống mmechatbot.</p>
        </div>

        <div class="mme-integration-card">
            <h3>Twenty CRM</h3>
            <?php $this->toggle('twenty_enabled', 'Tạo People trong Twenty CRM', $settings['twenty_enabled']); ?>
            <div class="mme-admin-grid">
                <?php $this->url_input('twenty_base_url', 'Twenty base URL', $settings['twenty_base_url']); ?>
                <?php $this->password_input('twenty_api_key', 'Twenty API key', !empty($settings['twenty_api_key'])); ?>
            </div>
        </div>
        <?php
    }

    public function render_embed_box(WP_Post $post): void
    {
        $shortcode = '[mme_form id="' . $post->ID . '"]';
        $endpoint = add_query_arg('mme_form_embed', $post->ID, home_url('/'));
        $script = sprintf(
            '<script src="%s" data-mme-form="%d" data-endpoint="%s"></script>',
            MME_FORM_URL . 'assets/embed.js?ver=' . MME_FORM_VERSION,
            $post->ID,
            $endpoint
        );
        ?>
        <p><strong>Shortcode</strong></p>
        <textarea class="widefat code" rows="2" readonly><?php echo esc_textarea($shortcode); ?></textarea>
        <p><strong>Embed website khác</strong></p>
        <textarea class="widefat code" rows="6" readonly><?php echo esc_textarea($script); ?></textarea>
        <p class="description">Embed loader truyền full URL trang cha, tự resize iframe và đẩy event sang GTM, gtag hoặc Meta Pixel nếu website cha đã cài.</p>
        <?php
    }

    public function render_submission_box(WP_Post $post): void
    {
        $payload = get_post_meta($post->ID, '_mme_submission_payload', true);
        $integrations = get_post_meta($post->ID, '_mme_submission_integrations', true);
        echo '<h3>Lead payload</h3><pre class="mme-json-preview">' . esc_html(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '<h3>Integration results</h3><pre class="mme-json-preview">' . esc_html(wp_json_encode($integrations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
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

        $raw_fields = isset($_POST['mme_form_fields_json']) ? wp_unslash($_POST['mme_form_fields_json']) : '[]';
        $decoded_fields = json_decode($raw_fields, true);
        $fields = array();
        if (is_array($decoded_fields)) {
            foreach ($decoded_fields as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $type = sanitize_key($field['type'] ?? 'text');
                if (!in_array($type, array('text', 'email', 'tel', 'textarea', 'select', 'radio'), true)) {
                    $type = 'text';
                }
                $label = sanitize_text_field($field['label'] ?? 'Field ' . ($index + 1));
                $name = sanitize_key($field['name'] ?? sanitize_title($label));
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
        update_post_meta($post_id, '_mme_form_fields', $fields ?: MME_Form_Plugin::default_fields());

        $submitted = isset($_POST['mme_settings']) && is_array($_POST['mme_settings'])
            ? wp_unslash($_POST['mme_settings'])
            : array();
        $current = $this->settings($post_id);
        $settings = MME_Form_Plugin::default_settings();

        foreach (array('kicker', 'heading', 'description', 'button_text', 'success_message', 'chatbot_tenant', 'chatbot_button_text') as $key) {
            $settings[$key] = sanitize_text_field($submitted[$key] ?? $current[$key]);
        }
        foreach (array('image_url', 'facebook_url', 'zalo_url', 'linkedin_url', 'chatbot_base_url', 'webhook_url', 'twenty_base_url') as $key) {
            $settings[$key] = esc_url_raw($submitted[$key] ?? $current[$key]);
        }
        foreach (array('button_color', 'accent_color', 'background_color', 'text_color') as $key) {
            $settings[$key] = sanitize_hex_color($submitted[$key] ?? '') ?: $current[$key];
        }
        $settings['image_position'] = in_array(($submitted['image_position'] ?? ''), array('left', 'right', 'top'), true) ? $submitted['image_position'] : 'left';
        $settings['font_family'] = in_array(($submitted['font_family'] ?? ''), array('system', 'inter', 'arial', 'georgia'), true) ? $submitted['font_family'] : 'system';
        $settings['trust_items'] = array_slice(array_values(array_filter(array_map('sanitize_text_field', preg_split('/\r\n|\r|\n/', (string) ($submitted['trust_items'] ?? ''))))), 0, 4);
        foreach (array('chatbot_enabled', 'webhook_enabled', 'twenty_enabled') as $key) {
            $settings[$key] = ($submitted[$key] ?? 'no') === 'yes' ? 'yes' : 'no';
        }
        foreach (array('webhook_secret', 'twenty_api_key') as $key) {
            $new_value = sanitize_text_field($submitted[$key] ?? '');
            $settings[$key] = $new_value !== '' ? $new_value : $current[$key];
        }

        update_post_meta($post_id, '_mme_form_settings', $settings);
    }

    public function form_columns(array $columns): array
    {
        $columns['mme_shortcode'] = 'Shortcode';
        $columns['mme_embed'] = 'External embed';
        return $columns;
    }

    public function render_form_column(string $column, int $post_id): void
    {
        if ($column === 'mme_shortcode') {
            echo '<code>[mme_form id=&quot;' . esc_html((string) $post_id) . '&quot;]</code>';
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
            'mme_form' => 'Form',
            'mme_contact' => 'Liên hệ',
            'mme_source' => 'Nguồn',
            'date' => 'Date',
        );
    }

    public function render_submission_column(string $column, int $post_id): void
    {
        $payload = (array) get_post_meta($post_id, '_mme_submission_payload', true);
        if ($column === 'mme_form') {
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

    public function remove_submission_quick_actions(array $actions, WP_Post $post): array
    {
        if ($post->post_type === 'mme_form_submission') {
            unset($actions['inline hide-if-no-js']);
        }
        return $actions;
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
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . '</span><input type="color" name="mme_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"></label>';
    }

    private function password_input(string $key, string $label, bool $is_set): void
    {
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . ($is_set ? ' <small>(đã lưu)</small>' : '') . '</span><input type="password" name="mme_settings[' . esc_attr($key) . ']" value="" autocomplete="new-password" placeholder="' . ($is_set ? 'Để trống để giữ secret cũ' : '') . '"></label>';
    }

    private function toggle(string $key, string $label, string $value): void
    {
        echo '<label class="mme-admin-toggle"><input type="hidden" name="mme_settings[' . esc_attr($key) . ']" value="no"><input type="checkbox" name="mme_settings[' . esc_attr($key) . ']" value="yes" ' . checked($value, 'yes', false) . '><span>' . esc_html($label) . '</span></label>';
    }
}
