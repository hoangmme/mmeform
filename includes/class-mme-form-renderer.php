<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MME_Form_Renderer
{
    public static function render(int $form_id, array $args = array()): string
    {
        $form = get_post($form_id);
        if (!$form || $form->post_type !== 'mme_form' || $form->post_status !== 'publish') {
            return current_user_can('edit_posts')
                ? '<p class="mme-form-error">' . esc_html__('MME Form not found or not published.', 'mme-form') . '</p>'
                : '';
        }

        $fields = get_post_meta($form_id, '_mme_form_fields', true);
        $fields = is_array($fields) && $fields ? $fields : MME_Form_Plugin::default_fields();
        
        $has_tel = false;
        foreach ($fields as $field) {
            if (isset($field['type']) && $field['type'] === 'tel') {
                $has_tel = true;
                break;
            }
        }

        if (empty($args['embed'])) {
            wp_enqueue_style('mme-form-public', MME_FORM_URL . 'assets/public.css', array(), MME_FORM_VERSION);
            if ($has_tel) {
                wp_enqueue_style('intl-tel-input', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/23.0.4/css/intlTelInput.css', array(), '23.0.4');
                wp_enqueue_script('intl-tel-input', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/23.0.4/js/intlTelInput.min.js', array(), '23.0.4', true);
            }
            wp_enqueue_script('mme-form-public', MME_FORM_URL . 'assets/public.js', $has_tel ? array('intl-tel-input') : array(), MME_FORM_VERSION, true);
        }
        $settings = wp_parse_args(
            (array) get_post_meta($form_id, '_mme_form_settings', true),
            MME_Form_Plugin::default_settings()
        );

        $font_map = array(
            'inherit' => 'inherit',
            'system' => 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'inter' => 'Inter, ui-sans-serif, system-ui, sans-serif',
            'arial' => 'Arial, Helvetica, sans-serif',
            'georgia' => 'Georgia, "Times New Roman", serif',
        );
        $font = $font_map[$settings['font_family']] ?? $font_map['system'];
        $instance_id = wp_unique_id('mme-form-');
        $parent_url = !empty($args['parent_url']) ? esc_url_raw($args['parent_url']) : '';
        $image_position = in_array($settings['image_position'], array('left', 'right', 'top'), true)
            ? $settings['image_position']
            : 'left';

        $style = sprintf(
            '--mme-button:%s;--mme-secondary:%s;--mme-accent:%s;--mme-bg:%s;--mme-text:%s;--mme-font:%s;',
            esc_attr($settings['button_color']),
            esc_attr($settings['secondary_color']),
            esc_attr($settings['accent_color']),
            esc_attr($settings['background_color']),
            esc_attr($settings['text_color']),
            esc_attr($font)
        );

        $wrapper_style = $style;
        if (!empty($args['fields_only'])) {
            $wrapper_style .= ' padding: 0 !important; background: transparent !important;';
        }

        ob_start();
        ?>
        <section
            id="<?php echo esc_attr($instance_id); ?>"
            class="mme-form-wrapper <?php echo !empty($args['embed']) ? 'is-embed' : ''; ?> <?php echo !empty($args['fields_only']) ? 'is-fields-only' : ''; ?> <?php echo !empty($settings['component_mode']) ? 'is-component' : ''; ?>"
            style="<?php echo esc_attr($wrapper_style); ?>"
            data-form-id="<?php echo esc_attr((string) $form_id); ?>"
        >
            <?php if (empty($args['fields_only'])) : ?>
            <div class="mme-form-bg-blob mme-bg-blob-primary"></div>
            <div class="mme-form-bg-blob mme-bg-blob-secondary"></div>

            <div class="mme-layout-grid mme-layout-<?php echo esc_attr($image_position); ?>">
                
                <!-- Left Column -->
                <div class="mme-area-left">
                    <!-- Khu vực 1: Heading -->
                    <div class="mme-area-heading">
                        <?php if (!empty($settings['kicker'])) : ?>
                            <div class="mme-form-badge">
                                <span class="mme-badge-dot"></span>
                                <?php echo esc_html($settings['kicker']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($settings['heading'])) : ?>
                            <h1 class="mme-form-main-heading">
                                <?php echo wp_kses_post(str_replace(array('[', ']'), array('<span class="mme-highlight">', '</span>'), $settings['heading'])); ?>
                                <?php if (!empty($settings['subheading'])) : ?>
                                    <br><span class="mme-gradient-text"><?php echo wp_kses_post($settings['subheading']); ?></span>
                                <?php endif; ?>
                            </h1>
                        <?php endif; ?>
                        
                        <?php if (!empty($settings['description'])) : ?>
                            <p class="mme-form-main-desc"><?php echo wp_kses_post($settings['description']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Khu vực 3: Benefit List -->
                    <div class="mme-area-trust">
                        <?php if (!empty($settings['trust_items']) && is_array($settings['trust_items'])) : ?>
                            <ul class="mme-form-trust-list">
                                <?php foreach (array_slice($settings['trust_items'], 0, 4) as $item) : ?>
                                    <li>
                                        <span class="mme-trust-icon"><?php echo self::icon('check-circle'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                        <span><?php echo esc_html($item); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Khu vực 4: Contact Cards -->
                    <div class="mme-area-contact">
                        <div class="mme-form-contact-boxes">
                            <?php if (!empty($settings['hotline'])) : ?>
                                <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $settings['hotline'])); ?>" class="mme-contact-box group">
                                    <div class="mme-contact-icon mme-phone-icon">
                                        <?php echo self::icon('phone-call'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>
                                    <div class="mme-contact-text">
                                        <span class="mme-contact-label"><?php echo esc_html($settings['hotline_label']); ?></span>
                                        <span class="mme-contact-value"><?php echo esc_html($settings['hotline']); ?></span>
                                    </div>
                                </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($settings['support_email'])) : ?>
                                <a href="mailto:<?php echo esc_attr($settings['support_email']); ?>" class="mme-contact-box group">
                                    <div class="mme-contact-icon mme-email-icon">
                                        <?php echo self::icon('mail'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>
                                    <div class="mme-contact-text">
                                        <span class="mme-contact-label"><?php echo esc_html($settings['email_label']); ?></span>
                                        <span class="mme-contact-value"><?php echo esc_html($settings['support_email']); ?></span>
                                    </div>
                                </a>
                            <?php endif; ?>
                            
                            <?php echo self::render_social_links($settings); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Khu vực 2: Form Card -->
                <div class="mme-area-form">
                    <div class="mme-form-card">
                        <?php if (!empty($settings['form_heading']) && empty($args['fields_only'])) : ?>
                            <header class="mme-form-header">
                                <h2><?php echo esc_html($settings['form_heading']); ?></h2>
                            </header>
                        <?php endif; ?>

                        <form
                            class="mme-form"
                            data-endpoint="<?php echo esc_url(rest_url('mme-form/v1/submit/' . $form_id)); ?>"
                            novalidate
                        >
                            <input type="hidden" name="form_id" value="<?php echo esc_attr((string) $form_id); ?>">
                            <input type="hidden" name="current_url" value="<?php echo esc_attr($parent_url); ?>">
                            <input type="hidden" name="referrer_url" value="">
                            <input type="hidden" name="started_at" value="">
                            <input type="text" class="mme-form-honeypot" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">

                            <div class="mme-form-grid">
                                <?php foreach ($fields as $field) : ?>
                                    <?php echo self::render_field($field); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="mme-form-submit-wrap">
                                <button class="mme-form-submit" type="submit">
                                    <span><?php echo esc_html($settings['button_text']); ?></span>
                                    <?php echo self::icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </button>
                            </div>
                            
                            <?php if (!empty($settings['form_footer'])) : ?>
                                <div class="mme-form-secure-text">
                                    <?php echo self::icon('shield-check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span><?php echo esc_html($settings['form_footer']); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="mme-form-status" role="status" aria-live="polite" data-success="<?php echo esc_attr($settings['success_message']); ?>"></div>
                        </form>

                        <div class="mme-form-footer">
                            <?php if ($settings['chatbot_enabled'] === 'yes' && $settings['chatbot_base_url'] && $settings['chatbot_tenant']) : ?>
                                <button
                                    type="button"
                                    class="mme-form-chat-toggle"
                                    data-chat-base="<?php echo esc_url($settings['chatbot_base_url']); ?>"
                                    data-chat-tenant="<?php echo esc_attr($settings['chatbot_tenant']); ?>"
                                >
                                    <?php echo self::icon('chat'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php echo esc_html($settings['chatbot_button_text']); ?>
                                </button>
                                <div class="mme-form-chat-panel" hidden></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php if (empty($args['fields_only'])) : ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($args['embed']) && $has_tel) : ?>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/23.0.4/css/intlTelInput.css">
                <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/23.0.4/js/intlTelInput.min.js"></script>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_field(array $field): string
    {
        $type = sanitize_key($field['type'] ?? 'text');
        $allowed = array('text', 'email', 'tel', 'date', 'time', 'textarea', 'select', 'radio');
        if (!in_array($type, $allowed, true)) {
            $type = 'text';
        }

        $name = sanitize_text_field($field['name'] ?? '');
        $name = trim(preg_replace('/\s+/', '_', $name));
        if (!$name) $name = 'field';
        $label = sanitize_text_field($field['label'] ?? $name);
        $placeholder = sanitize_text_field($field['placeholder'] ?? '');
        $required = !empty($field['required']);
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
        $width_class = ($field['width'] ?? '100') === '50' ? 'mme-field-width-50' : 'mme-field-width-100';
        $id = wp_unique_id('mme-field-');

        ob_start();
        ?>
        <div class="mme-form-field mme-field-<?php echo esc_attr($type); ?> <?php echo esc_attr($width_class); ?>">
            <label for="<?php echo esc_attr($id); ?>">
                <?php echo esc_html($label); ?><?php echo $required ? '<span aria-hidden="true">*</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </label>

            <?php if ($type === 'textarea') : ?>
                <div class="mme-input-wrapper">
                    <textarea id="<?php echo esc_attr($id); ?>" name="fields[<?php echo esc_attr($name); ?>]" placeholder="<?php echo esc_attr($placeholder); ?>" rows="3" <?php echo $required ? 'required' : ''; ?>></textarea>
                </div>
            <?php elseif ($type === 'select') : ?>
                <div class="mme-input-wrapper mme-has-icon">
                    <div class="mme-input-icon-left">
                        <?php echo self::icon('list'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <select id="<?php echo esc_attr($id); ?>" name="fields[<?php echo esc_attr($name); ?>]" <?php echo $required ? 'required' : ''; ?>>
                        <option value=""><?php echo esc_html($placeholder ?: 'Chọn một phương án'); ?></option>
                        <?php foreach ($options as $option) : ?>
                            <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mme-input-icon-right pointer-events-none">
                        <?php echo self::icon('chevron-down'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                </div>
            <?php elseif ($type === 'radio') : ?>
                <div class="mme-radio-group" id="<?php echo esc_attr($id); ?>">
                    <?php foreach ($options as $index => $option) : ?>
                        <label class="mme-radio-option">
                            <input type="radio" name="fields[<?php echo esc_attr($name); ?>]" value="<?php echo esc_attr($option); ?>" <?php echo $required && $index === 0 ? 'required' : ''; ?>>
                            <span><?php echo esc_html($option); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>


            <?php else : ?>
                <div class="mme-input-wrapper <?php echo $type !== 'tel' ? 'mme-has-icon' : ''; ?>">
                    <?php if ($type !== 'tel') : ?>
                        <div class="mme-input-icon-left">
                            <?php 
                            $icon_name = 'user';
                            if ($type === 'email') $icon_name = 'mail';
                            echo self::icon($icon_name); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                            ?>
                        </div>
                    <?php endif; ?>
                    <input id="<?php echo esc_attr($id); ?>" type="<?php echo esc_attr($type); ?>" name="fields[<?php echo esc_attr($name); ?>]" placeholder="<?php echo esc_attr($placeholder); ?>" <?php echo $required ? 'required' : ''; ?>>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_social_links(array $settings): string
    {
        $links = array(
            'facebook_url' => array('label' => 'Facebook', 'icon' => 'facebook'),
            'zalo_url' => array('label' => 'Zalo', 'icon' => 'zalo'),
            'linkedin_url' => array('label' => 'LinkedIn', 'icon' => 'linkedin'),
            'tiktok_url' => array('label' => 'TikTok', 'icon' => 'tiktok'),
            'youtube_url' => array('label' => 'YouTube', 'icon' => 'youtube'),
        );

        $configured = array();
        foreach ($links as $key => $config) {
            if (!empty($settings[$key])) {
                $config['url'] = $settings[$key];
                $configured[] = $config;
            }
        }

        if (empty($configured)) {
            return '';
        }

        $html = '<div class="mme-social-card">';
        $html .= '<div class="mme-social-text">';
        $html .= '<span class="mme-social-label">' . esc_html($settings['social_label'] ?? 'Theo dõi') . '</span>';
        $html .= '<span class="mme-social-title">Mạng xã hội</span>';
        $html .= '</div>';
        $html .= '<div class="mme-social-icons">';
        
        foreach ($configured as $config) {
            $html .= sprintf(
                '<a class="mme-social-icon group mme-social-icon-%s" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s">',
                esc_attr($config['icon']),
                esc_url($config['url']),
                esc_attr($config['label'])
            );
            $html .= self::icon($config['icon']);
            $html .= '</a>';
        }
        $html .= '</div></div>';

        return $html;
    }

    private static function icon(string $name): string
    {
        $paths = array(
            'spark' => '<path d="M12 2l1.5 5.5L19 9l-5.5 1.5L12 16l-1.5-5.5L5 9l5.5-1.5L12 2Z"/><path d="M19 15l.7 2.3L22 18l-2.3.7L19 21l-.7-2.3L16 18l2.3-.7L19 15Z"/>',
            'bolt' => '<path d="m13 2-8 12h7l-1 8 8-12h-7l1-8Z"/>',
            'shield' => '<path d="M12 3 5 6v5c0 4.6 2.9 8 7 10 4.1-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
            'chat' => '<path d="M21 12a8 8 0 0 1-8 8H6l-4 2 1.5-4A9 9 0 1 1 21 12Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'facebook' => '<path d="M14 8h3V4h-3c-3 0-5 2-5 5v3H6v4h3v6h4v-6h3l1-4h-4V9c0-.7.3-1 1-1Z"/>',
            'zalo' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 8.5-8.5 8.5 8.5 0 0 1 8.5 8.5z"/>',
            'linkedin' => '<path d="M6 9v10M6 5v.01M10 19V9h4v2c1-2 5-2 5 2v6"/>',
            'tiktok' => '<path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93v7.2c0 1.63-.52 3.23-1.47 4.54-1.22 1.67-3.08 2.79-5.11 3.04-1.95.24-4.01-.1-5.65-1.15-2.2-1.41-3.64-3.85-3.84-6.49-.24-3.07 1.56-6.07 4.41-7.23 2.12-.86 4.62-.77 6.64.29v4.18c-.89-.5-1.94-.7-2.94-.53-1.07.18-2.04.79-2.61 1.69-.58.91-.71 2.08-.34 3.09.43 1.19 1.49 2.08 2.76 2.3 1.25.21 2.59-.09 3.51-.9.99-.86 1.56-2.17 1.55-3.52V.02z"/>',
            'youtube' => '<path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.5 12 3.5 12 3.5s-7.505 0-9.377.55a3.016 3.016 0 0 0-2.122 2.136C0 8.07 0 12 0 12s0 3.93.501 5.814a3.016 3.016 0 0 0 2.122 2.136c1.872.55 9.377.55 9.377.55s7.505 0 9.377-.55a3.016 3.016 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>',
            'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'phone-call' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/><path d="M14.05 2a9 9 0 0 1 8 7.94"/><path d="M14.05 6A5 5 0 0 1 18 10"/>',
            'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
            'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2-1 4-2 7-2 2.5 0 4.5 1 6.5 2a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
            'user' => '<path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
            'phone-input' => '<path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
            'list' => '<path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>',
            'chevron-down' => '<path d="M19 9l-7 7-7-7"/>'
        );
        $path = $paths[$name] ?? $paths['check'];

        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    }
}
