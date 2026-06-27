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

        if (empty($args['embed'])) {
            wp_enqueue_style('mme-form-public', MME_FORM_URL . 'assets/public.css', array(), MME_FORM_VERSION);
            wp_enqueue_script('mme-form-public', MME_FORM_URL . 'assets/public.js', array(), MME_FORM_VERSION, true);
        }

        $fields = get_post_meta($form_id, '_mme_form_fields', true);
        $fields = is_array($fields) && $fields ? $fields : MME_Form_Plugin::default_fields();
        $settings = wp_parse_args(
            (array) get_post_meta($form_id, '_mme_form_settings', true),
            MME_Form_Plugin::default_settings()
        );

        $font_map = array(
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
            '--mme-button:%s;--mme-accent:%s;--mme-bg:%s;--mme-text:%s;--mme-font:%s;',
            esc_attr($settings['button_color']),
            esc_attr($settings['accent_color']),
            esc_attr($settings['background_color']),
            esc_attr($settings['text_color']),
            esc_attr($font)
        );

        ob_start();
        ?>
        <section
            id="<?php echo esc_attr($instance_id); ?>"
            class="mme-form-shell mme-image-<?php echo esc_attr($image_position); ?> <?php echo !empty($settings['image_url']) ? 'has-image' : 'no-image'; ?><?php echo !empty($args['embed']) ? ' is-embed' : ''; ?>"
            style="<?php echo esc_attr($style); ?>"
            data-form-id="<?php echo esc_attr((string) $form_id); ?>"
        >
            <?php if (!empty($settings['image_url']) || !empty($settings['image_url_mobile'])) : ?>
                <div class="mme-form-visual" aria-hidden="true">
                    <picture>
                        <?php if (!empty($settings['image_url_mobile'])) : ?>
                            <source media="(max-width: 680px)" srcset="<?php echo esc_url($settings['image_url_mobile']); ?>">
                        <?php endif; ?>
                        <img src="<?php echo esc_url($settings['image_url'] ?: $settings['image_url_mobile']); ?>" alt="" loading="lazy">
                    </picture>
                </div>
            <?php endif; ?>

            <div class="mme-form-card">
                <header class="mme-form-header">
                    <?php if (!empty($settings['kicker'])) : ?>
                        <span class="mme-form-kicker"><?php echo self::icon('spark'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html($settings['kicker']); ?></span>
                    <?php endif; ?>
                    <h2><?php echo esc_html($settings['heading']); ?></h2>
                    <?php if (!empty($settings['description'])) : ?>
                        <p><?php echo esc_html($settings['description']); ?></p>
                    <?php endif; ?>
                </header>

                <?php if (!empty($settings['trust_items']) && is_array($settings['trust_items'])) : ?>
                    <div class="mme-form-trust" aria-label="Điểm nổi bật">
                        <?php foreach (array_slice($settings['trust_items'], 0, 4) as $index => $item) : ?>
                            <span><?php echo self::icon(array('bolt', 'shield', 'chat', 'check')[$index] ?? 'check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html($item); ?></span>
                        <?php endforeach; ?>
                    </div>
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

                    <button class="mme-form-submit" type="submit">
                        <span><?php echo esc_html($settings['button_text']); ?></span>
                        <?php echo self::icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>

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

                    <?php echo self::render_social_links($settings); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_field(array $field): string
    {
        $type = sanitize_key($field['type'] ?? 'text');
        $allowed = array('text', 'email', 'tel', 'textarea', 'select', 'radio');
        if (!in_array($type, $allowed, true)) {
            $type = 'text';
        }

        $name = sanitize_key($field['name'] ?? 'field');
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
                <textarea id="<?php echo esc_attr($id); ?>" name="fields[<?php echo esc_attr($name); ?>]" placeholder="<?php echo esc_attr($placeholder); ?>" rows="3" <?php echo $required ? 'required' : ''; ?>></textarea>
            <?php elseif ($type === 'select') : ?>
                <select id="<?php echo esc_attr($id); ?>" name="fields[<?php echo esc_attr($name); ?>]" <?php echo $required ? 'required' : ''; ?>>
                    <option value=""><?php echo esc_html($placeholder ?: 'Chọn một phương án'); ?></option>
                    <?php foreach ($options as $option) : ?>
                        <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                    <?php endforeach; ?>
                </select>
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
                <input id="<?php echo esc_attr($id); ?>" type="<?php echo esc_attr($type); ?>" name="fields[<?php echo esc_attr($name); ?>]" placeholder="<?php echo esc_attr($placeholder); ?>" <?php echo $required ? 'required' : ''; ?>>
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
        );

        $html = '';
        foreach ($links as $key => $config) {
            if (empty($settings[$key])) {
                continue;
            }
            $html .= sprintf(
                '<a class="mme-social" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s">%s</a>',
                esc_url($settings[$key]),
                esc_attr($config['label']),
                self::icon($config['icon'])
            );
        }

        return $html ? '<nav class="mme-form-socials" aria-label="Mạng xã hội">' . $html . '</nav>' : '';
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
            'zalo' => '<path d="M4 5h16v14H4z"/><path d="m7 9 4 6M11 9l-4 6M13 15V9h4M13 12h3"/>',
            'linkedin' => '<path d="M6 9v10M6 5v.01M10 19V9h4v2c1-2 5-2 5 2v6"/>',
        );
        $path = $paths[$name] ?? $paths['check'];

        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    }
}
