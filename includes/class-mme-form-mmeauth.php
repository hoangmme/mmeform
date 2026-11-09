<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Xác thực người gửi form bằng MMeAuth (xác minh SĐT qua Zalo Mini App).
 * Trình duyệt chỉ chuyển id_token do gateway cấp; máy chủ tự kiểm tra chữ ký RS256 bằng khoá công khai
 * của gateway (/.well-known/mmeauth-keys.json), kiểm tra iss/aud/exp/phone_verified và chặn dùng lại.
 */
if (!class_exists('MME_Form_MMeAuth')) {
final class MME_Form_MMeAuth
{
    public const DEFAULT_URL = 'https://auth.mme.vn';
    private const LEEWAY = 60;

    public static function gateway_url(array $settings): string
    {
        $url = trim((string) ($settings['mmeauth_url'] ?? ''));
        return untrailingslashit($url !== '' ? $url : self::DEFAULT_URL);
    }

    /** Form bật MMeAuth và site đã có site key thì mới bắt xác thực. */
    public static function is_enabled(array $settings): bool
    {
        return ($settings['mmeauth_enabled'] ?? 'no') === 'yes' && trim((string) ($settings['mmeauth_site_key'] ?? '')) !== '';
    }

    /**
     * @return array|WP_Error Claims đã xác minh: sub, phone, name, jti, exp.
     */
    public static function verify(string $jwt, array $settings)
    {
        $gateway = self::gateway_url($settings);
        $site_key = trim((string) ($settings['mmeauth_site_key'] ?? ''));

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return new WP_Error('mme_auth_malformed', 'Phiên xác minh không hợp lệ, vui lòng xác minh lại.');
        }
        $header = json_decode(self::b64url_decode($parts[0]), true);
        $claims = json_decode(self::b64url_decode($parts[1]), true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
            return new WP_Error('mme_auth_malformed', 'Phiên xác minh không hợp lệ, vui lòng xác minh lại.');
        }

        $key = self::key_for_kid($gateway, (string) ($header['kid'] ?? ''));
        if (is_wp_error($key)) {
            return $key;
        }
        if (openssl_verify($parts[0] . '.' . $parts[1], self::b64url_decode($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            return new WP_Error('mme_auth_bad_signature', 'Không xác minh được chữ ký MMeAuth.');
        }

        if (($claims['iss'] ?? '') !== $gateway) {
            return new WP_Error('mme_auth_bad_issuer', 'Phiên xác minh không đến từ MMeAuth.');
        }
        if (($claims['aud'] ?? '') !== $site_key) {
            return new WP_Error('mme_auth_bad_audience', 'Phiên xác minh không dành cho website này.');
        }
        if ((int) ($claims['exp'] ?? 0) + self::LEEWAY < time()) {
            return new WP_Error('mme_auth_expired', 'Phiên xác minh đã hết hạn, vui lòng xác minh lại.');
        }
        if (empty($claims['phone']) || ($claims['phone_verified'] ?? false) !== true) {
            return new WP_Error('mme_auth_unverified', 'Số điện thoại chưa được xác minh.');
        }

        return array(
            'sub' => (string) ($claims['sub'] ?? ''),
            'phone' => preg_replace('/[^0-9+]/', '', (string) $claims['phone']),
            'name' => sanitize_text_field((string) ($claims['name'] ?? '')),
            'jti' => (string) ($claims['jti'] ?? ''),
            'exp' => (int) ($claims['exp'] ?? 0),
        );
    }

    /** Chặn dùng lại 1 token cho nhiều lần gửi (chỉ đánh dấu sau khi lưu submission thành công). */
    public static function is_used(array $identity): bool
    {
        return $identity['jti'] !== '' && (bool) get_transient('mme_auth_jti_' . md5($identity['jti']));
    }

    public static function mark_used(array $identity): void
    {
        if ($identity['jti'] === '') {
            return;
        }
        $ttl = max(MINUTE_IN_SECONDS, $identity['exp'] + self::LEEWAY - time());
        set_transient('mme_auth_jti_' . md5($identity['jti']), 1, $ttl);
    }

    /** @return string|WP_Error PEM khoá công khai, cache 1 giờ; gặp kid lạ (gateway đổi khoá) thì tải lại. */
    private static function key_for_kid(string $gateway, string $kid)
    {
        $cache_key = 'mme_auth_keys_' . md5($gateway);
        $keys = get_transient($cache_key);
        if (!is_array($keys) || !isset($keys[$kid])) {
            $response = wp_remote_get($gateway . '/.well-known/mmeauth-keys.json', array('timeout' => 8));
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                return new WP_Error('mme_auth_keys_unavailable', 'Không kết nối được MMeAuth để kiểm tra, vui lòng thử lại.');
            }
            $keys = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($keys)) {
                return new WP_Error('mme_auth_keys_unavailable', 'Không kết nối được MMeAuth để kiểm tra, vui lòng thử lại.');
            }
            set_transient($cache_key, $keys, HOUR_IN_SECONDS);
        }
        if (!isset($keys[$kid])) {
            return new WP_Error('mme_auth_unknown_key', 'Phiên xác minh không hợp lệ, vui lòng xác minh lại.');
        }
        return (string) $keys[$kid];
    }

    private static function b64url_decode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($value, true);
    }
}
}
