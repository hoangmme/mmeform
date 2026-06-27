<?php
/**
 * Plugin Name: MME Form
 * Plugin URI: https://mme.vn
 * Description: Compact form builder with external embeds, source URL tracking, chatbot, Google Sheets webhooks, and Twenty CRM sync.
 * Version: 0.2.16
 * Author: MME
 * Text Domain: mme-form
 * Requires at least: 6.4
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MME_FORM_VERSION', '0.2.16');
define('MME_FORM_FILE', __FILE__);
define('MME_FORM_DIR', plugin_dir_path(__FILE__));
define('MME_FORM_URL', plugin_dir_url(__FILE__));

require_once MME_FORM_DIR . 'includes/class-mme-form-renderer.php';
require_once MME_FORM_DIR . 'includes/class-mme-form-submissions.php';
require_once MME_FORM_DIR . 'includes/class-mme-form-admin.php';
require_once MME_FORM_DIR . 'includes/class-mme-form-plugin.php';

register_activation_hook(__FILE__, array('MME_Form_Plugin', 'activate'));

add_action('plugins_loaded', static function (): void {
    MME_Form_Plugin::instance()->boot();
});
