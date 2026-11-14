<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability cho trợ lý AI qua plugin MCP Adapter (WordPress 6.9+ có Abilities API), giống MMe Elementor Kit:
 * kiểm tra và cài bản cập nhật MME Form từ GitHub. Cần quyền update_plugins.
 */
if (!class_exists('MME_Form_Abilities')) {
final class MME_Form_Abilities
{
    public const CATEGORY = 'mme-form';

    private static ?MME_Form_Abilities $instance = null;

    public static function instance(): MME_Form_Abilities
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        add_action('wp_abilities_api_categories_init', array($this, 'register_category'));
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    public function register_category(): void
    {
        wp_register_ability_category(self::CATEGORY, array(
            'label' => 'MME Form',
            'description' => 'Cập nhật và kiểm tra phiên bản MME Form.',
        ));
    }

    public function register_abilities(): void
    {
        $can_update = static function (): bool {
            return current_user_can('update_plugins');
        };

        $abilities = array(
            'mme-form/check-update' => array(
                'label' => 'Kiểm tra cập nhật MME Form',
                'description' => 'So bản MME Form đang cài với bản mới nhất trên GitHub (bỏ qua cache).',
                'execute_callback' => array($this, 'check_update'),
                'annotations' => array('readonly' => true, 'idempotent' => true),
            ),
            'mme-form/update-plugin' => array(
                'label' => 'Cập nhật MME Form',
                'description' => 'Cài bản MME Form mới nhất từ GitHub ngay và giữ plugin ở trạng thái bật. Cần máy chủ ghi file trực tiếp (không hỏi FTP).',
                'execute_callback' => array($this, 'update_plugin'),
                'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
            ),
        );

        foreach ($abilities as $name => $ability) {
            $annotations = $ability['annotations'];
            unset($ability['annotations']);

            wp_register_ability($name, array_merge($ability, array(
                'category' => self::CATEGORY,
                'permission_callback' => $can_update,
                'meta' => array(
                    // MCP Adapter chỉ đưa ra các ability có meta.mcp.public
                    'mcp' => array('public' => true, 'type' => 'tool'),
                    'show_in_rest' => true,
                    'annotations' => $annotations,
                ),
            )));
        }
    }

    public function check_update(): array
    {
        $updater = MME_Form_Updater::instance();
        $info = $updater->remote_info(true);
        return array(
            'installed' => MME_FORM_VERSION,
            'latest' => $info['version'] ?? null,
            'has_update' => $updater->has_update(false),
            'folder' => dirname($updater->basename()),
            'error' => $info['error'] ?? null,
        );
    }

    /** @return array|WP_Error */
    public function update_plugin()
    {
        $result = MME_Form_Updater::instance()->upgrade_now();
        if (!$result['success']) {
            return new WP_Error('mme_form_update_failed', $result['error'] ?? 'Cập nhật thất bại.');
        }
        return $result;
    }
}
}
