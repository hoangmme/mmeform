<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Đăng nhập / đăng ký WordPress bằng MMeAuth (xác minh SĐT qua Zalo, không mật khẩu).
 *
 * Luồng: nút [data-mme-login] → MMeAuth.prompt({ purpose: 'login' }) → POST /wp-json/mme-form/v1/login { token }
 * → kiểm chữ ký bằng MME_Form_MMeAuth::verify() → tìm user theo Zalo id / SĐT đã xác minh, chưa có thì tạo
 * → wp_set_auth_cookie().
 *
 * Chỉ khớp tài khoản qua meta do module này ghi (mme_zalo_sub, mme_phone), KHÔNG khớp billing_phone
 * vì số đó khách tự gõ, chưa xác minh. Tài khoản có quyền biên tập trở lên không đăng nhập bằng Zalo
 * (filter mme_form_login_allow_user để mở).
 *
 * Plugin khác dùng: mme_form_login_button(), MME_Form_Login::normalize_phone(), MME_Form_Login::user_phone(),
 * action mme_form_login (user_id, identity, is_new), filter mme_form_login_redirect.
 */
if (!class_exists('MME_Form_Login')) {
final class MME_Form_Login
{
    public const OPTION = 'mme_form_login';
    public const META_PHONE = 'mme_phone';
    public const META_SUB = 'mme_zalo_sub';

    private static ?MME_Form_Login $instance = null;

    public static function instance(): MME_Form_Login
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function defaults(): array
    {
        return array(
            'enabled' => 'no',
            'allow_register' => 'yes',
            'role' => '',
            'woocommerce_form' => 'yes',
            'wp_login_form' => 'no',
            'redirect' => '',
        );
    }

    public static function settings(): array
    {
        $saved = get_option(self::OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }

    /** Bật đăng nhập và site đã có site key MMeAuth. */
    public static function is_enabled(): bool
    {
        $global = MME_Form_Plugin::global_settings();
        return self::settings()['enabled'] === 'yes' && trim((string) $global['mmeauth_site_key']) !== '';
    }

    /** 84944117399 / +84 944 117 399 / 0944.117.399 → 0944117399. */
    public static function normalize_phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strpos($digits, '84') === 0 && strlen($digits) === 11) {
            $digits = '0' . substr($digits, 2);
        }
        return (string) $digits;
    }

    /** SĐT đã xác minh qua Zalo của user ('' nếu chưa có). */
    public static function user_phone(int $user_id): string
    {
        return (string) get_user_meta($user_id, self::META_PHONE, true);
    }

    public function boot(): void
    {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_shortcode('mme_login', array($this, 'shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('login_enqueue_scripts', array($this, 'register_assets'));

        if (!self::is_enabled()) {
            return;
        }
        $settings = self::settings();
        if ($settings['woocommerce_form'] === 'yes') {
            add_action('woocommerce_login_form_end', array($this, 'print_form_button'));
        }
        if ($settings['wp_login_form'] === 'yes') {
            add_action('login_form', array($this, 'print_form_button'));
        }
        add_action('show_user_profile', array($this, 'profile_field'));
        add_action('edit_user_profile', array($this, 'profile_field'));
        add_action('edit_user_profile_update', array($this, 'save_profile_field'));
    }

    public function register_assets(): void
    {
        $global = MME_Form_Plugin::global_settings();
        wp_register_script('mmeauth-sdk', MME_Form_MMeAuth::sdk_url($global), array(), null, true);
        wp_register_script('mme-form-login', MME_FORM_URL . 'assets/login.js', array('mmeauth-sdk'), MME_FORM_VERSION, true);
        wp_localize_script('mme-form-login', 'MMEFormLogin', array(
            'site' => (string) $global['mmeauth_site_key'],
            'endpoint' => esc_url_raw(rest_url('mme-form/v1/login')),
            'i18n' => array(
                'error' => 'Đăng nhập chưa thành công, vui lòng thử lại.',
                'working' => 'Đang đăng nhập…',
            ),
        ));
        wp_register_style('mme-form-login', MME_FORM_URL . 'assets/login.css', array(), MME_FORM_VERSION);
    }

    /**
     * HTML nút "Đăng nhập bằng Zalo". Trả về '' nếu chưa bật hoặc đã đăng nhập (trừ khi $args['logged_in'] = 'show').
     *
     * @param array{label?:string,redirect?:string,purpose?:string,class?:string,logged_in?:string} $args
     */
    public static function button(array $args = array()): string
    {
        if (!self::is_enabled()) {
            return '';
        }
        $args = wp_parse_args($args, array(
            'label' => 'Đăng nhập bằng Zalo',
            'redirect' => '',
            'purpose' => 'login',
            'class' => '',
            'logged_in' => 'greeting',
        ));
        if (is_user_logged_in()) {
            if ($args['logged_in'] !== 'greeting') {
                return '';
            }
            $user = wp_get_current_user();
            return '<p class="mme-login-greeting">Xin chào <strong>' . esc_html($user->display_name) . '</strong> · <a href="' . esc_url(wp_logout_url(home_url('/'))) . '">Đăng xuất</a></p>';
        }

        wp_enqueue_script('mme-form-login');
        wp_enqueue_style('mme-form-login');

        $purpose = in_array($args['purpose'], array('login', 'register'), true) ? $args['purpose'] : 'login';
        $redirect = $args['redirect'] !== '' ? $args['redirect'] : self::current_url();

        return sprintf(
            '<button type="button" class="mme-login-btn %1$s" data-mme-login data-purpose="%2$s" data-redirect="%3$s"><span class="mme-login-mark" aria-hidden="true">Zalo</span><span class="mme-login-label">%4$s</span></button><p class="mme-login-error" role="alert" hidden></p>',
            esc_attr($args['class']),
            esc_attr($purpose),
            esc_url($redirect),
            esc_html($args['label'])
        );
    }

    public function shortcode($atts): string
    {
        return self::button(shortcode_atts(array(
            'label' => 'Đăng nhập bằng Zalo',
            'redirect' => '',
            'purpose' => 'login',
            'class' => '',
            'logged_in' => 'greeting',
        ), (array) $atts, 'mme_login'));
    }

    public function print_form_button(): void
    {
        $html = self::button(array('logged_in' => 'hide'));
        if ($html !== '') {
            echo '<div class="mme-login-divider"><span>hoặc</span></div>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    public function register_routes(): void
    {
        register_rest_route('mme-form/v1', '/login', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_login'),
            'permission_callback' => '__return_true',
            'args' => array(
                'token' => array('type' => 'string', 'required' => true),
                'redirect' => array('type' => 'string', 'required' => false),
            ),
        ));
    }

    public function rest_login(WP_REST_Request $request)
    {
        if (!self::is_enabled()) {
            return new WP_Error('mme_login_disabled', 'Website chưa bật đăng nhập bằng Zalo.', array('status' => 403));
        }
        if (!$this->same_origin($request)) {
            return new WP_Error('mme_login_origin', 'Yêu cầu không hợp lệ.', array('status' => 403));
        }
        if (!$this->rate_ok()) {
            return new WP_Error('mme_login_rate', 'Bạn thao tác quá nhanh, vui lòng thử lại sau ít phút.', array('status' => 429));
        }

        $identity = MME_Form_MMeAuth::verify((string) $request->get_param('token'), MME_Form_Plugin::global_settings());
        if (is_wp_error($identity)) {
            $identity->add_data(array('status' => 401));
            return $identity;
        }
        if (!in_array($identity['purpose'], array('login', 'register'), true)) {
            return new WP_Error('mme_login_purpose', 'Phiên xác minh không dùng để đăng nhập.', array('status' => 401));
        }
        if (MME_Form_MMeAuth::is_used($identity)) {
            return new WP_Error('mme_login_reused', 'Phiên xác minh đã được dùng, vui lòng xác minh lại.', array('status' => 401));
        }

        $identity['phone'] = self::normalize_phone($identity['phone']);
        if ($identity['phone'] === '') {
            return new WP_Error('mme_login_phone', 'Không lấy được số điện thoại từ Zalo.', array('status' => 401));
        }

        $user_id = self::find_user($identity);
        $is_new = false;
        if (!$user_id) {
            if (self::settings()['allow_register'] !== 'yes') {
                return new WP_Error('mme_login_no_account', 'Số điện thoại này chưa có tài khoản trên website.', array('status' => 403));
            }
            $user_id = self::create_user($identity);
            if (is_wp_error($user_id)) {
                $user_id->add_data(array('status' => 500));
                return $user_id;
            }
            $is_new = true;
        }

        $user = get_userdata($user_id);
        $allowed = $user && !user_can($user, 'edit_posts');
        /** Mở cho tài khoản có quyền biên tập (mặc định chặn để Zalo không thành cửa vào wp-admin). */
        if (!apply_filters('mme_form_login_allow_user', $allowed, $user, $identity)) {
            return new WP_Error('mme_login_forbidden', 'Tài khoản này không đăng nhập bằng Zalo được.', array('status' => 403));
        }

        update_user_meta($user_id, self::META_PHONE, $identity['phone']);
        if ($identity['sub'] !== '') {
            update_user_meta($user_id, self::META_SUB, $identity['sub']);
        }
        update_user_meta($user_id, 'mme_last_login', time());
        MME_Form_MMeAuth::mark_used($identity);

        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());
        do_action('wp_login', $user->user_login, $user);
        do_action('mme_form_login', $user_id, $identity, $is_new);

        $redirect = wp_validate_redirect((string) $request->get_param('redirect'), '');
        if ($redirect === '') {
            $redirect = self::settings()['redirect'] !== '' ? self::settings()['redirect'] : home_url('/');
        }
        $redirect = (string) apply_filters('mme_form_login_redirect', $redirect, $user_id, $is_new);

        return rest_ensure_response(array(
            'ok' => true,
            'is_new' => $is_new,
            'name' => $user->display_name,
            'redirect' => esc_url_raw($redirect),
        ));
    }

    public static function find_user(array $identity): int
    {
        if ($identity['sub'] !== '') {
            $ids = get_users(array('meta_key' => self::META_SUB, 'meta_value' => $identity['sub'], 'fields' => 'ID', 'number' => 1));
            if ($ids) {
                return (int) $ids[0];
            }
        }
        $ids = get_users(array('meta_key' => self::META_PHONE, 'meta_value' => self::normalize_phone($identity['phone']), 'fields' => 'ID', 'number' => 1));
        return $ids ? (int) $ids[0] : 0;
    }

    /** @return int|WP_Error */
    private static function create_user(array $identity)
    {
        $login = 'zalo_' . $identity['phone'];
        $suffix = 1;
        while (username_exists($login)) {
            $login = 'zalo_' . $identity['phone'] . '_' . (++$suffix);
        }
        $role = self::settings()['role'];
        if ($role === '' || !get_role($role)) {
            $role = get_role('customer') ? 'customer' : get_option('default_role', 'subscriber');
        }
        if (in_array($role, array('administrator', 'editor', 'author', 'shop_manager'), true)) {
            $role = 'subscriber';
        }
        $name = $identity['name'] !== '' ? $identity['name'] : $identity['phone'];
        $user_id = wp_insert_user(array(
            'user_login' => $login,
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => $name,
            'nickname' => $name,
            'first_name' => $identity['name'],
            'role' => $role,
        ));
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        if ($identity['name'] !== '') {
            update_user_meta($user_id, 'billing_first_name', $identity['name']);
        }
        update_user_meta($user_id, 'billing_phone', $identity['phone']);
        update_user_meta($user_id, 'mme_registered_via', 'mmeauth');
        return (int) $user_id;
    }

    public function profile_field(WP_User $user): void
    {
        $phone = self::user_phone($user->ID);
        $can_edit = current_user_can('edit_users');
        ?>
        <h2>Đăng nhập bằng Zalo</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="mme_phone">SĐT đã xác minh</label></th>
                <td>
                    <?php if ($can_edit && (int) $user->ID !== get_current_user_id()) : ?>
                        <input type="text" id="mme_phone" name="mme_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Khách đăng nhập bằng Zalo với số này sẽ vào tài khoản này. Để trống để gỡ liên kết.</p>
                    <?php else : ?>
                        <code><?php echo esc_html($phone !== '' ? $phone : '—'); ?></code>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
        wp_nonce_field('mme_phone_' . $user->ID, '_mme_phone_nonce');
    }

    public function save_profile_field(int $user_id): void
    {
        if (!current_user_can('edit_users') || !isset($_POST['_mme_phone_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_mme_phone_nonce'])), 'mme_phone_' . $user_id)) {
            return;
        }
        $phone = self::normalize_phone(sanitize_text_field(wp_unslash($_POST['mme_phone'] ?? '')));
        if ($phone === '') {
            delete_user_meta($user_id, self::META_PHONE);
            delete_user_meta($user_id, self::META_SUB);
            return;
        }
        $other = get_users(array('meta_key' => self::META_PHONE, 'meta_value' => $phone, 'fields' => 'ID', 'number' => 1, 'exclude' => array($user_id)));
        if (!$other) {
            update_user_meta($user_id, self::META_PHONE, $phone);
            delete_user_meta($user_id, self::META_SUB);
        }
    }

    private function same_origin(WP_REST_Request $request): bool
    {
        $origin = (string) $request->get_header('origin');
        if ($origin === '') {
            $origin = (string) $request->get_header('referer');
        }
        $host = wp_parse_url($origin, PHP_URL_HOST);
        return $host && strtolower($host) === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    private function rate_ok(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $key = 'mme_login_rate_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= 10) {
            return false;
        }
        set_transient($key, $count + 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function current_url(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        return home_url($uri);
    }
}
}

if (!function_exists('mme_form_login_button')) {
    /** Nút "Đăng nhập bằng Zalo" cho theme / plugin khác. */
    function mme_form_login_button(array $args = array()): string
    {
        return class_exists('MME_Form_Login') ? MME_Form_Login::button($args) : '';
    }
}
