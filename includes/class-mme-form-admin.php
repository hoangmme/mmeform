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
        add_action('admin_footer', array($this, 'render_preview_modal'));
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
            <div style="margin-top: 15px; display: flex; gap: 10px; align-items: center;">
                <button type="button" class="button button-secondary" id="mme-add-field">+ Thêm field</button>
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
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var copyBtn = document.getElementById('mme-copy-ai-prompt-builder-btn');
                if (copyBtn) {
                    copyBtn.addEventListener('click', function() {
                        var promptText = "Khi code Landing Page WordPress bằng PHP template, hãy sử dụng hàm PHP mme_form_auto($slug, $title, $json_fields) của plugin MME Form để hiển thị form.\nHàm này tự động kiểm tra/tạo form và trả về shortcode Chỉ Form (ẩn cột thông tin bên trái để lọt vào khung giao diện của bạn).\n\nQuy tắc viết mảng $json_fields (JSON array các object):\n- Các thuộc tính hỗ trợ:\n  + type: text, email, tel, textarea, select, hoặc radio.\n  + name: Tên biến (viết thường, không dấu, dùng dấu gạch dưới, VD: full_name, phone).\n  + label: Nhãn hiển thị (VD: \"Họ và tên\").\n  + placeholder: Gợi ý mờ trong ô nhập.\n  + width: \"100\" (Full dòng) hoặc \"50\" (Chia đôi dòng).\n  + required: true hoặc false.\n  + options: Chỉ cho select/radio, các lựa chọn cách nhau bằng \\n (xuống dòng).\n\nVí dụ PHP:\n<?php\n$fields = '[\n  {\"type\":\"text\", \"name\":\"fullname\", \"label\":\"Họ tên\", \"placeholder\":\"Nhập tên...\", \"required\":true, \"width\":\"100\"},\n  {\"type\":\"tel\", \"name\":\"phone\", \"label\":\"Số điện thoại\", \"placeholder\":\"09xx...\", \"required\":true, \"width\":\"50\"}\n]';\nif (function_exists('mme_form_auto')) {\n    echo mme_form_auto('landing-su-kien-2026', 'Form Sự Kiện', $fields);\n}\n?>\nLưu ý: Bạn hãy tự thiết kế Container/Card giao diện cho form đẹp mắt theo bố cục website rồi gọi echo mme_form_auto(...) vào đúng vị trí!";
                        navigator.clipboard.writeText(promptText).then(function() {
                            var status = document.getElementById('mme-copy-ai-prompt-builder-status');
                            status.style.display = 'inline';
                            setTimeout(function() { status.style.display = 'none'; }, 3000);
                        });
                    });
                }
            });
            </script>
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
            <?php $this->url_input('tiktok_url', 'TikTok URL', $settings['tiktok_url']); ?>
            <?php $this->url_input('youtube_url', 'YouTube URL', $settings['youtube_url']); ?>

            <h4 class="mme-admin-span-2" style="margin-top: 15px; margin-bottom: 0; padding-bottom: 5px; border-bottom: 1px solid #ddd;">Cột bên phải (Form)</h4>
            <?php $this->text_input('form_heading', 'Tiêu đề Form', $settings['form_heading']); ?>
            <?php $this->text_input('button_text', 'Chữ trên nút', $settings['button_text']); ?>
            <?php $this->text_input('form_footer', 'Chữ dưới cùng Form (Footer)', $settings['form_footer']); ?>
            <?php $this->text_input('success_message', 'Thông báo thành công', $settings['success_message']); ?>
            
            <h4 class="mme-admin-span-2" style="margin-top: 15px; margin-bottom: 0; padding-bottom: 5px; border-bottom: 1px solid #ddd;">Bố cục & Kích thước Form</h4>

            <label class="mme-admin-field">
                <span>Bố cục tổng thể (Layout Mode)</span>
                <select name="mme_settings[layout_mode]">
                    <option value="full" <?php selected($settings['layout_mode'] ?? 'full', 'full'); ?>>Giao diện đầy đủ (Landing Page - có padding ngoài & blob)</option>
                    <option value="form_only" <?php selected($settings['layout_mode'] ?? 'full', 'form_only'); ?>>Chỉ khung Form (Tối giản - Bỏ padding ngoài & blob)</option>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Vị trí cột thông tin (khi ở chế độ đầy đủ)</span>
                <select name="mme_settings[image_position]">
                    <?php foreach (array('left' => 'Bên trái', 'right' => 'Bên phải', 'top' => 'Bên trên', 'hidden' => 'Ẩn cột thông tin') as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['image_position'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Padding bên trong Form Card</span>
                <select name="mme_settings[form_padding]">
                    <option value="default" <?php selected($settings['form_padding'] ?? 'default', 'default'); ?>>Tiêu chuẩn (40px / Mobile 24px)</option>
                    <option value="spacious" <?php selected($settings['form_padding'] ?? 'default', 'spacious'); ?>>Rộng rãi (56px / Mobile 32px)</option>
                    <option value="compact" <?php selected($settings['form_padding'] ?? 'default', 'compact'); ?>>Vừa phải (28px / Mobile 20px)</option>
                    <option value="small" <?php selected($settings['form_padding'] ?? 'default', 'small'); ?>>Nhỏ gọn (16px / Mobile 14px)</option>
                    <option value="none" <?php selected($settings['form_padding'] ?? 'default', 'none'); ?>>Không padding (0px - Phẳng theo khung)</option>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Khoảng cách giữa các ô nhập (Field Gap)</span>
                <select name="mme_settings[form_field_gap]">
                    <option value="default" <?php selected($settings['form_field_gap'] ?? 'default', 'default'); ?>>Tiêu chuẩn (20px)</option>
                    <option value="compact" <?php selected($settings['form_field_gap'] ?? 'default', 'compact'); ?>>Gọn gàng (14px)</option>
                    <option value="small" <?php selected($settings['form_field_gap'] ?? 'default', 'small'); ?>>Nhỏ gọn (10px)</option>
                    <option value="relaxed" <?php selected($settings['form_field_gap'] ?? 'default', 'relaxed'); ?>>Rộng rãi (28px)</option>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Độ rộng tối đa Form Card (Max Width)</span>
                <select name="mme_settings[form_max_width]">
                    <option value="680px" <?php selected($settings['form_max_width'] ?? '680px', '680px'); ?>>Tiêu chuẩn (680px)</option>
                    <option value="540px" <?php selected($settings['form_max_width'] ?? '680px', '540px'); ?>>Gọn gàng (540px)</option>
                    <option value="800px" <?php selected($settings['form_max_width'] ?? '680px', '800px'); ?>>Rộng rãi (800px)</option>
                    <option value="100%" <?php selected($settings['form_max_width'] ?? '680px', '100%'); ?>>100% (Tự co giãn theo khung chứa / Elementor)</option>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Bo góc Form Card (Border Radius)</span>
                <select name="mme_settings[form_border_radius]">
                    <option value="default" <?php selected($settings['form_border_radius'] ?? 'default', 'default'); ?>>Bo tròn nhiều (28px)</option>
                    <option value="medium" <?php selected($settings['form_border_radius'] ?? 'default', 'medium'); ?>>Bo tròn vừa (16px)</option>
                    <option value="small" <?php selected($settings['form_border_radius'] ?? 'default', 'small'); ?>>Bo nhẹ (8px)</option>
                    <option value="none" <?php selected($settings['form_border_radius'] ?? 'default', 'none'); ?>>Vuông vức (0px)</option>
                    <option value="full" <?php selected($settings['form_border_radius'] ?? 'default', 'full'); ?>>Siêu bo tròn (36px)</option>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Đổ bóng Form Card (Shadow)</span>
                <select name="mme_settings[form_shadow]">
                    <option value="default" <?php selected($settings['form_shadow'] ?? 'default', 'default'); ?>>Đổ bóng mềm (Mặc định)</option>
                    <option value="none" <?php selected($settings['form_shadow'] ?? 'default', 'none'); ?>>Không đổ bóng (Phẳng)</option>
                    <option value="subtle" <?php selected($settings['form_shadow'] ?? 'default', 'subtle'); ?>>Đổ bóng nhẹ</option>
                    <option value="strong" <?php selected($settings['form_shadow'] ?? 'default', 'strong'); ?>>Đổ bóng nổi bật</option>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Khung nền Shortcode Chỉ Form ([mme_form_fields])</span>
                <select name="mme_settings[fields_card_style]">
                    <option value="card" <?php selected($settings['fields_card_style'] ?? 'card', 'card'); ?>>Thẻ Form Card (Nền trắng/theo cài đặt, có padding, bo góc & bóng - Đề xuất cho mọi Section)</option>
                    <option value="transparent" <?php selected($settings['fields_card_style'] ?? 'card', 'transparent'); ?>>Trong suốt phẳng (Không nền, không padding thẻ - Tự lồng ghép)</option>
                </select>
            </label>

            <label class="mme-admin-field">
                <span>Hiện Tiêu đề Form khi dùng Shortcode Chỉ Form</span>
                <select name="mme_settings[fields_show_heading]">
                    <option value="yes" <?php selected($settings['fields_show_heading'] ?? 'yes', 'yes'); ?>>Hiện tiêu đề Form</option>
                    <option value="no" <?php selected($settings['fields_show_heading'] ?? 'yes', 'no'); ?>>Ẩn tiêu đề Form</option>
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

            <h4 class="mme-admin-span-2" style="margin-top: 15px; margin-bottom: 0; padding-bottom: 5px; border-bottom: 1px solid #ddd;">Màu sắc Form & Giao diện</h4>

            <?php $this->color_input('button_color', 'Màu nút bấm chính (Primary)', $settings['button_color']); ?>
            <?php $this->color_input('button_text_color', 'Màu chữ trên nút bấm', $settings['button_text_color']); ?>
            <?php $this->color_input('secondary_color', 'Màu phụ (Secondary / Badge)', $settings['secondary_color']); ?>
            <?php $this->color_input('accent_color', 'Màu nhấn (Accent)', $settings['accent_color']); ?>
            <?php $this->color_input('form_card_bg', 'Màu nền Form Card (Ví dụ: Nền trắng #ffffff)', $settings['form_card_bg']); ?>
            <?php $this->color_input('form_card_border', 'Màu viền Form Card', $settings['form_card_border']); ?>
            <?php $this->color_input('form_heading_color', 'Màu chữ tiêu đề Form (Heading Form)', $settings['form_heading_color'] ?? '#0f172a'); ?>
            <?php $this->color_input('field_label_color', 'Màu chữ nhãn trường (Field Title / Label - Họ tên, SĐT,...)', $settings['field_label_color'] ?? '#1e293b'); ?>
            <?php $this->color_input('field_bg_color', 'Màu nền ô nhập liệu', $settings['field_bg_color']); ?>
            <?php $this->color_input('field_border_color', 'Màu viền ô nhập liệu', $settings['field_border_color']); ?>
            <?php $this->color_input('field_text_color', 'Màu chữ ô nhập liệu', $settings['field_text_color']); ?>
            <?php $this->color_input('background_color', 'Màu nền ngoài cùng (Wrapper)', $settings['background_color']); ?>
            <?php $this->color_input('text_color', 'Màu chữ ngoài cùng', $settings['text_color']); ?>

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
        $shortcode_full = '[mme_form id="' . $post->ID . '"]';
        $shortcode_fields = '[mme_form_fields id="' . $post->ID . '"]';
        $endpoint = add_query_arg('mme_form_embed', $post->ID, home_url('/'));
        $preview_full_url = add_query_arg(array('mme_form_embed' => $post->ID, 'mode' => 'full'), home_url('/'));
        $preview_fields_url = add_query_arg(array('mme_form_embed' => $post->ID, 'mode' => 'fields_only'), home_url('/'));
        $script = sprintf(
            '<script src="%s" data-mme-form="%d" data-endpoint="%s"></script>',
            MME_FORM_URL . 'assets/embed.js?ver=' . MME_FORM_VERSION,
            $post->ID,
            $endpoint
        );
        ?>
        <div style="margin-bottom: 14px;">
            <button type="button" id="mme-open-preview-modal-btn" class="button button-primary button-large" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; font-weight: 600; padding: 6px 12px; margin-bottom: 8px;">
                <span class="dashicons dashicons-visibility" style="margin-top: 2px;"></span> Xem trước Form (Live Preview)
            </button>
            <div style="display: flex; gap: 6px;">
                <a href="<?php echo esc_url($preview_full_url); ?>" target="_blank" class="button button-secondary" style="flex: 1; text-align: center; font-size: 11px; padding: 0 4px;" title="Mở preview bản đầy đủ trong tab mới">Xem Full tab mới ↗</a>
                <a href="<?php echo esc_url($preview_fields_url); ?>" target="_blank" class="button button-secondary" style="flex: 1; text-align: center; font-size: 11px; padding: 0 4px;" title="Mở preview chỉ form trong tab mới">Xem Chỉ Form ↗</a>
            </div>
        </div>

        <div class="mme-shortcode-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                <strong>Shortcode (Đầy đủ)</strong>
                <span class="mme-copy-feedback" id="mme-copied-full">Đã chép!</span>
            </div>
            <div style="display: flex; gap: 6px;">
                <input type="text" class="widefat code" id="mme-shortcode-full-input" value="<?php echo esc_attr($shortcode_full); ?>" readonly>
                <button type="button" class="button mme-copy-btn" data-target="mme-shortcode-full-input" data-feedback="mme-copied-full">Copy</button>
            </div>
            <p class="description" style="margin: 4px 0 0; font-size: 11px;">Hiển thị trọn vẹn cả cột giới thiệu & form.</p>
        </div>

        <div class="mme-shortcode-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                <strong>Shortcode (Chỉ Form)</strong>
                <span class="mme-copy-feedback" id="mme-copied-fields">Đã chép!</span>
            </div>
            <div style="display: flex; gap: 6px;">
                <input type="text" class="widefat code" id="mme-shortcode-fields-input" value="<?php echo esc_attr($shortcode_fields); ?>" readonly>
                <button type="button" class="button mme-copy-btn" data-target="mme-shortcode-fields-input" data-feedback="mme-copied-fields">Copy</button>
            </div>
            <p class="description" style="margin: 4px 0 0; font-size: 11px; color: #475569;">
                Chỉ hiện khung Form (thích hợp chèn vào mọi Section).<br>
                <strong>Tùy chọn thêm vào shortcode:</strong><br>
                • <code>card="yes"</code> : Giữ nền trắng Form Card (mặc định)<br>
                • <code>card="no"</code> : Trong suốt phẳng<br>
                • <code>heading="no"</code> : Ẩn tiêu đề Form
            </p>
        </div>

        <div class="mme-shortcode-card" style="margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                <strong>Embed website khác</strong>
                <span class="mme-copy-feedback" id="mme-copied-embed">Đã chép!</span>
            </div>
            <textarea class="widefat code" id="mme-embed-script-input" rows="4" readonly><?php echo esc_textarea($script); ?></textarea>
            <div style="margin-top: 6px; text-align: right;">
                <button type="button" class="button mme-copy-btn" data-target="mme-embed-script-input" data-feedback="mme-copied-embed">Copy mã Embed</button>
            </div>
        </div>

        <?php
        $build_info = MME_Form_Plugin::get_build_info();
        ?>
        <div class="mme-shortcode-card" style="margin-top: 14px; margin-bottom: 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; font-size: 11px; color: #475569; line-height: 1.5;">
            <div style="font-weight: 600; color: #0f172a; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center;">
                <span>📦 Phiên bản MME Form</span>
                <span style="background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 4px; font-weight: 600;">v<?php echo esc_html($build_info['version']); ?></span>
            </div>
            <div>🕒 Cập nhật: <strong><?php echo esc_html($build_info['date']); ?></strong></div>
            <?php if (!empty($build_info['commit'])) : ?>
                <div>🔖 Git Commit: <code><?php echo esc_html($build_info['commit']); ?></code> (<?php echo esc_html($build_info['branch']); ?>)</div>
            <?php endif; ?>
        </div>
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

        foreach (array('kicker', 'heading', 'subheading', 'description', 'form_heading', 'form_footer', 'hotline', 'support_email', 'social_label', 'button_text', 'success_message', 'chatbot_tenant', 'chatbot_button_text') as $key) {
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
            'field_label_color', 'form_heading_color'
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
        foreach (array('chatbot_enabled', 'webhook_enabled', 'twenty_enabled', 'pancake_enabled') as $key) {
            $settings[$key] = ($submitted[$key] ?? 'no') === 'yes' ? 'yes' : 'no';
        }
        foreach (array('webhook_secret', 'twenty_api_key', 'pancake_api_key') as $key) {
            $new_value = sanitize_text_field($submitted[$key] ?? '');
            $settings[$key] = $new_value !== '' ? $new_value : $current[$key];
        }
        $settings['pancake_workspace_id'] = sanitize_text_field($submitted['pancake_workspace_id'] ?? '');
        $settings['pancake_table'] = in_array($submitted['pancake_table'] ?? '', array('contact', 'lead'), true) ? $submitted['pancake_table'] : 'contact';

        update_post_meta($post_id, '_mme_form_settings', $settings);
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
        $picker_val = (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) ? $value : '#ffffff';
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . '</span><input type="color" name="mme_settings[' . esc_attr($key) . ']" value="' . esc_attr($picker_val) . '"></label>';
    }

    private function password_input(string $key, string $label, bool $is_set): void
    {
        echo '<label class="mme-admin-field"><span>' . esc_html($label) . ($is_set ? ' <small>(đã lưu)</small>' : '') . '</span><input type="password" name="mme_settings[' . esc_attr($key) . ']" value="" autocomplete="new-password" placeholder="' . ($is_set ? 'Để trống để giữ secret cũ' : '') . '"></label>';
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

    public function render_preview_modal(): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'mme_form' || $screen->base !== 'post') {
            return;
        }

        global $post;
        if (!$post || $post->post_type !== 'mme_form') {
            return;
        }

        $form_id = $post->ID;
        $endpoint = add_query_arg(array('mme_form_embed' => $form_id, 'in_modal' => '1'), home_url('/'));
        ?>
        <div id="mme-preview-modal" class="mme-modal" style="display: none;" aria-hidden="true" role="dialog" aria-modal="true" data-base-url="<?php echo esc_url($endpoint); ?>">
            <div class="mme-modal-backdrop"></div>
            <div class="mme-modal-container">
                <div class="mme-modal-header">
                    <div class="mme-modal-title">
                        <span class="dashicons dashicons-visibility" style="margin-top: 2px;"></span>
                        <span>Xem trước giao diện Form (Live Preview)</span>
                    </div>
                    <div class="mme-modal-toolbar">
                        <div class="mme-modal-toggle-group mme-mode-toggles">
                            <button type="button" class="button mme-btn-mode is-active" data-mode="full">Giao diện Đầy đủ</button>
                            <button type="button" class="button mme-btn-mode" data-mode="fields_only">Chỉ Form ([mme_form_fields])</button>
                        </div>
                        <div class="mme-modal-toggle-group mme-bg-toggles">
                            <span class="mme-bg-label">Thử nền:</span>
                            <button type="button" class="button mme-btn-bg is-active" data-bg="white" title="Nền trắng">Trắng</button>
                            <button type="button" class="button mme-btn-bg" data-bg="gray" title="Nền xám">Xám</button>
                            <button type="button" class="button mme-btn-bg" data-bg="dark" title="Nền tối (Dark)">Tối</button>
                        </div>
                    </div>
                    <button type="button" class="mme-modal-close" aria-label="Đóng">&times;</button>
                </div>
                <div class="mme-modal-body" id="mme-modal-body-wrapper">
                    <iframe id="mme-preview-iframe" src="about:blank" title="MME Form Preview" frameborder="0"></iframe>
                </div>
            </div>
        </div>
        <?php
    }

    public function plugin_row_meta(array $meta, string $file): array
    {
        if (defined('MME_FORM_FILE') && plugin_basename(MME_FORM_FILE) === $file) {
            $info = MME_Form_Plugin::get_build_info();
            $meta[] = sprintf(
                '<span style="color:#059669; font-weight: 600;">🕒 Bản cập nhật: %s%s</span>',
                esc_html($info['date']),
                !empty($info['commit']) ? ' (<code>' . esc_html($info['commit']) . '</code>)' : ''
            );
        }
        return $meta;
    }

    public function admin_footer_text(string $text): string
    {
        if (!function_exists('get_current_screen')) {
            return $text;
        }
        $screen = get_current_screen();
        if ($screen && in_array($screen->post_type, array('mme_form', 'mme_form_submission'), true)) {
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
        if (!$screen || $screen->post_type !== 'mme_form' || $screen->base !== 'edit') {
            return;
        }
        $info = MME_Form_Plugin::get_build_info();
        ?>
        <div class="notice notice-info" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 14px; background: #f8fafc; border-left: 4px solid #0284c7; margin: 15px 0 10px;">
            <span style="font-size: 13px; color: #0f172a;">
                <strong>MME Form v<?php echo esc_html($info['version']); ?></strong> &bull; 
                Bản cập nhật lúc: <strong><?php echo esc_html($info['date']); ?></strong>
                <?php if (!empty($info['commit'])) : ?>
                    &bull; Git Commit: <code><?php echo esc_html($info['commit']); ?></code> (<?php echo esc_html($info['branch']); ?>)
                <?php endif; ?>
            </span>
            <span style="font-size: 11px; color: #0369a1; background: #e0f2fe; padding: 2px 8px; border-radius: 12px; font-weight: 600;">Git Sync Active</span>
        </div>
        <?php
    }
}
}

