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
        add_action('wp_ajax_mme_twenty_check_fields', array($this, 'ajax_twenty_check_fields'));
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
            <h4 class="mme-admin-span-2" style="margin-bottom: 0; padding-bottom: 5px; border-bottom: 1px solid #ddd;">Cột bên trái (Thông tin)</h4>
            <?php $this->text_input('kicker', 'Chữ nhỏ trên đầu', $settings['kicker']); ?>
            <?php $this->text_input('heading', 'Tiêu đề trái', $settings['heading']); ?>
            <?php $this->text_input('subheading', 'Tiêu đề trái (dưới)', $settings['subheading']); ?>
            <?php $this->text_input('description', 'Mô tả ngắn', $settings['description']); ?>
            <?php $this->text_input('hotline_label', 'Nhãn Hotline', $settings['hotline_label']); ?>
            <?php $this->text_input('hotline', 'Hotline', $settings['hotline']); ?>
            <?php $this->text_input('email_label', 'Nhãn Email', $settings['email_label']); ?>
            <?php $this->text_input('support_email', 'Email hỗ trợ', $settings['support_email']); ?>
            <?php $this->text_input('social_label', 'Nhãn Mạng xã hội', $settings['social_label']); ?>
            
            <label class="mme-admin-field mme-admin-span-2">
                <span>Điểm nổi bật (mỗi dòng một mục, tối đa 4)</span>
                <textarea name="mme_settings[trust_items]" rows="4"><?php echo esc_textarea(implode("\n", (array) $settings['trust_items'])); ?></textarea>
            </label>

            <?php $this->url_input('facebook_url', 'Facebook URL', $settings['facebook_url']); ?>
            <?php $this->url_input('zalo_url', 'Zalo URL', $settings['zalo_url']); ?>
            <?php $this->url_input('linkedin_url', 'LinkedIn URL', $settings['linkedin_url']); ?>

            <h4 class="mme-admin-span-2" style="margin-top: 15px; margin-bottom: 0; padding-bottom: 5px; border-bottom: 1px solid #ddd;">Cột bên phải (Form)</h4>
            <?php $this->text_input('form_heading', 'Tiêu đề Form', $settings['form_heading']); ?>
            <?php $this->text_input('button_text', 'Chữ trên nút', $settings['button_text']); ?>
            <?php $this->text_input('form_footer', 'Chữ dưới cùng Form (Footer)', $settings['form_footer']); ?>
            <?php $this->text_input('success_message', 'Thông báo thành công', $settings['success_message']); ?>
            
            <h4 class="mme-admin-span-2" style="margin-top: 15px; margin-bottom: 0; padding-bottom: 5px; border-bottom: 1px solid #ddd;">Thiết kế chung</h4>

            <label class="mme-admin-field">
                <span>Vị trí cột thông tin</span>
                <select name="mme_settings[image_position]">
                    <?php foreach (array('left' => 'Bên trái', 'right' => 'Bên phải', 'top' => 'Bên trên') as $value => $label) : ?>
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

            <?php $this->color_input('button_color', 'Màu chính (Primary)', $settings['button_color']); ?>
            <?php $this->color_input('secondary_color', 'Màu phụ (Secondary)', $settings['secondary_color']); ?>
            <?php $this->color_input('accent_color', 'Màu nhấn', $settings['accent_color']); ?>
            <?php $this->color_input('background_color', 'Màu nền', $settings['background_color']); ?>
            <?php $this->color_input('text_color', 'Màu chữ', $settings['text_color']); ?>

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
        <p style="margin-bottom: 16px;">
            <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank" class="button button-primary button-large">Xem Preview giao diện Form (mở tab mới)</a>
        </p>
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
        update_post_meta($post_id, '_mme_form_fields', $fields ?: MME_Form_Plugin::default_fields());

        $submitted = isset($_POST['mme_settings']) && is_array($_POST['mme_settings'])
            ? wp_unslash($_POST['mme_settings'])
            : array();
        $current = $this->settings($post_id);
        $settings = MME_Form_Plugin::default_settings();

        foreach (array('kicker', 'heading', 'description', 'form_heading', 'form_footer', 'hotline', 'support_email', 'button_text', 'success_message', 'chatbot_tenant', 'chatbot_button_text') as $key) {
            $settings[$key] = sanitize_text_field($submitted[$key] ?? $current[$key] ?? '');
        }
        foreach (array('facebook_url', 'zalo_url', 'linkedin_url', 'chatbot_base_url', 'webhook_url', 'twenty_base_url') as $key) {
            $settings[$key] = esc_url_raw($submitted[$key] ?? ($current[$key] ?? ''));
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

    public function ajax_twenty_check_fields(): void
    {
        check_ajax_referer('mme_twenty_check', 'nonce');
        
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Permission denied');
        }
        
        $settings = $this->settings($post_id);
        
        if (empty($settings['twenty_base_url']) || empty($settings['twenty_api_key'])) {
            wp_send_json_error('Vui lòng điền đủ Twenty URL và API Key trước khi kiểm tra.');
        }
        
        $base_url = untrailingslashit(esc_url_raw($settings['twenty_base_url']));
        $api_key = sanitize_text_field($settings['twenty_api_key']);
        $url = $base_url . (str_ends_with(strtolower($base_url), '/rest') ? '/metadata/objects' : '/rest/metadata/objects');
        
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
        
        $submissions = new MME_Form_Submissions();
        $settings = $this->settings($post_id);
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
