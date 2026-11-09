<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MME_Form_Submissions')) {
final class MME_Form_Submissions
{
    private static ?MME_Form_Submissions $instance = null;

    public static function instance(): MME_Form_Submissions
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private const MAX_DISPATCH_ATTEMPTS = 3;

    /** @var int[] Submission chờ gửi tích hợp sau khi đã trả phản hồi cho khách. */
    private array $pending_dispatch = array();

    public function boot(): void
    {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('mme_form_dispatch_integrations', array($this, 'dispatch_integrations'));
        add_action('shutdown', array($this, 'flush_pending_dispatch'), 0);
    }

    public function register_routes(): void
    {
        register_rest_route('mme-form/v1', '/submit/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'submit'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array('sanitize_callback' => 'absint'),
            ),
        ));
        register_rest_route('mme-form/v1', '/mmeauth-lead/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'mmeauth_lead'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array('sanitize_callback' => 'absint'),
            ),
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function submit(WP_REST_Request $request)
    {
        $form_id = absint($request['id']);
        $form = get_post($form_id);
        if (!$form || $form->post_type !== 'mme_form' || $form->post_status !== 'publish') {
            return new WP_Error('mme_form_not_found', 'Form không tồn tại.', array('status' => 404));
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            return new WP_Error('mme_form_invalid_json', 'Dữ liệu không hợp lệ.', array('status' => 400));
        }

        if (!empty($input['website'])) {
            return new WP_REST_Response(array('success' => true), 200);
        }

        $started_at = isset($input['started_at']) && is_numeric($input['started_at'])
            ? absint($input['started_at'])
            : 0;
        $elapsed = (int) floor(microtime(true) * 1000) - $started_at;
        if (!$started_at || $elapsed < 1200) {
            return new WP_Error('mme_form_too_fast', 'Vui lòng thử lại sau một chút.', array('status' => 429));
        }

        if (!$this->allow_request()) {
            return new WP_Error('mme_form_rate_limit', 'Bạn đã gửi quá nhiều lần. Vui lòng thử lại sau.', array('status' => 429));
        }

        $fields = get_post_meta($form_id, '_mme_form_fields', true);
        $fields = is_array($fields) ? $fields : MME_Form_Plugin::default_fields();
        $raw_values = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : array();
        $values = array();
        $errors = array();

        foreach ($fields as $field) {
            $name = sanitize_text_field($field['name'] ?? '');
            $field_name = trim(preg_replace('/\s+/', '_', $name));
            if (!$field_name) {
                continue;
            }
            $type = sanitize_key($field['type'] ?? 'text');
            $raw_value = '';
            if (isset($raw_values[$field_name]) && is_scalar($raw_values[$field_name])) {
                $raw_value = (string) $raw_values[$field_name];
            } elseif (isset($raw_values[sanitize_key($field_name)]) && is_scalar($raw_values[sanitize_key($field_name)])) {
                $raw_value = (string) $raw_values[sanitize_key($field_name)];
            }
            $raw_value = $this->limit_string($raw_value, $type === 'textarea' ? 5000 : 500);
            if ($type === 'email') {
                $value = sanitize_email($raw_value);
            } elseif ($type === 'textarea') {
                $value = sanitize_textarea_field($raw_value);
            } else {
                $value = sanitize_text_field($raw_value);
            }
            if ($type === 'email' && $raw_value !== '' && !is_email($raw_value)) {
                $errors[$name] = sprintf('%s không đúng định dạng email.', sanitize_text_field($field['label'] ?? $name));
            }
            if (in_array($type, array('select', 'radio'), true)) {
                $options = array_map('sanitize_text_field', (array) ($field['options'] ?? array()));
                if ($value !== '' && !in_array($value, $options, true)) {
                    $errors[$name] = sprintf('%s có giá trị không hợp lệ.', sanitize_text_field($field['label'] ?? $name));
                }
            }
            if (!empty($field['required']) && $value === '') {
                $errors[$name] = sprintf('%s là trường bắt buộc.', sanitize_text_field($field['label'] ?? $name));
            }
            $values[$name] = $value;
        }

        if ($errors) {
            $error_msg = implode(' ', array_values($errors));
            return new WP_Error('mme_form_validation_failed', $error_msg, array('status' => 400, 'errors' => $errors));
        }

        $settings = MME_Form_Plugin::form_settings($form_id);
        $identity = null;
        if (MME_Form_MMeAuth::is_enabled($settings)) {
            $token = isset($input['mmeauth_token']) && is_string($input['mmeauth_token']) ? trim($input['mmeauth_token']) : '';
            if ($token === '') {
                return new WP_Error('mme_form_auth_required', 'Vui lòng xác minh số điện thoại qua Zalo trước khi gửi.', array('status' => 400, 'auth_required' => true));
            }
            $identity = MME_Form_MMeAuth::verify($token, $settings);
            if (is_wp_error($identity)) {
                return new WP_Error($identity->get_error_code(), $identity->get_error_message(), array('status' => 400, 'auth_required' => true));
            }
            if (MME_Form_MMeAuth::is_used($identity)) {
                return new WP_Error('mme_form_auth_reused', 'Phiên xác minh này đã được dùng, vui lòng xác minh lại.', array('status' => 400, 'auth_required' => true));
            }
            // SĐT đã xác minh luôn thắng giá trị khách tự gõ
            foreach ($fields as $f) {
                if (($f['type'] ?? '') === 'tel') {
                    $values[sanitize_text_field($f['name'] ?? '')] = $identity['phone'];
                }
            }
        }

        $duplicate_key = 'mme_form_dup_' . md5($form_id . '|' . wp_json_encode($values));
        $duplicate_event = get_transient($duplicate_key);
        if ($duplicate_event) {
            return new WP_REST_Response(array(
                'success' => true,
                'event_id' => $duplicate_event,
                'message' => sanitize_text_field($settings['success_message']),
            ), 200);
        }

        $current_url = isset($input['current_url']) && is_scalar($input['current_url'])
            ? esc_url_raw($this->limit_string((string) $input['current_url'], 4096))
            : '';
        $referrer_url = isset($input['referrer_url']) && is_scalar($input['referrer_url'])
            ? esc_url_raw($this->limit_string((string) $input['referrer_url'], 4096))
            : '';
        $attribution = $this->sanitize_attribution($input['attribution'] ?? array());
        $contact = $this->extract_contact($fields, $values);
        if ($identity) {
            $contact['phone'] = $identity['phone'];
            if ($contact['name'] === '' && $identity['name'] !== '') {
                $contact['name'] = $identity['name'];
            }
            $contact['verified'] = array(
                'provider' => 'mmeauth',
                'phone' => $identity['phone'],
                'subject' => $identity['sub'],
            );
        }
        $need = $this->first_value($values, array('need', 'message', 'note', 'nhu_cau', 'content'));
        $event_id = wp_generate_uuid4();
        $timestamp = gmdate('c');

        $payload = array(
            'event_id' => $event_id,
            'event' => 'lead_collected',
            'tenant' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'channel' => 'mme_form',
            'conversation_id' => null,
            'form' => array(
                'id' => $form_id,
                'title' => get_the_title($form_id),
            ),
            'contact' => $contact,
            'lead' => array(
                'need' => $need,
                'booking_requested' => $this->looks_like_booking($values),
                'note' => '',
            ),
            'fields' => $values,
            'source' => array(
                'provider' => 'mme_form',
                'url' => $current_url,
                'origin' => $current_url ? (string) wp_parse_url($current_url, PHP_URL_HOST) : '',
                'referrer' => $referrer_url,
                'form_id' => $form_id,
            ),
            'attribution' => $attribution,
            'timestamp' => $timestamp,
            'started_at' => $input['started_at'] ?? '',
        );

        return $this->store_submission($form_id, $settings, $payload, $identity, $duplicate_key);
    }

    /**
     * Lead "Nhận tư vấn" khách gửi từ MMeAuth Mini App, được gateway chuyển tới (LEAD_WEBHOOK_URL).
     * Không có secret dùng chung: id_token (purpose = lead) do gateway ký cho đúng site key là bằng chứng.
     */
    public function mmeauth_lead(WP_REST_Request $request)
    {
        $form_id = absint($request['id']);
        $form = get_post($form_id);
        if (!$form || $form->post_type !== 'mme_form' || $form->post_status !== 'publish') {
            return new WP_Error('mme_form_not_found', 'Form không tồn tại.', array('status' => 404));
        }
        $input = $request->get_json_params();
        $token = is_array($input) && isset($input['mmeauth_token']) && is_string($input['mmeauth_token']) ? trim($input['mmeauth_token']) : '';
        $settings = MME_Form_Plugin::form_settings($form_id);
        if (trim((string) $settings['mmeauth_site_key']) === '') {
            return new WP_Error('mme_auth_not_configured', 'Website chưa cấu hình Site key MMeAuth.', array('status' => 400));
        }
        $identity = MME_Form_MMeAuth::verify($token, $settings);
        if (is_wp_error($identity)) {
            return new WP_Error($identity->get_error_code(), $identity->get_error_message(), array('status' => 401));
        }
        if ($identity['purpose'] !== 'lead') {
            return new WP_Error('mme_auth_bad_purpose', 'Token không dùng để gửi lead.', array('status' => 401));
        }
        if (MME_Form_MMeAuth::is_used($identity)) {
            return new WP_Error('mme_form_auth_reused', 'Lead này đã được nhận.', array('status' => 409));
        }

        $solution = sanitize_text_field($this->limit_string((string) ($input['solution'] ?? ''), 200));
        $note = sanitize_textarea_field($this->limit_string((string) ($input['note'] ?? ''), 2000));
        $values = array_filter(array(
            'name' => $identity['name'],
            'phone' => $identity['phone'],
            'need' => $solution ?: 'Tư vấn chung',
            'note' => $note,
        ), 'strlen');
        $payload = array(
            'event_id' => wp_generate_uuid4(),
            'event' => 'lead_collected',
            'tenant' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'channel' => 'zalo_miniapp',
            'conversation_id' => null,
            'form' => array(
                'id' => $form_id,
                'title' => get_the_title($form_id),
            ),
            'contact' => array(
                'name' => $identity['name'],
                'phone' => $identity['phone'],
                'email' => '',
                'verified' => array('provider' => 'mmeauth', 'phone' => $identity['phone'], 'subject' => $identity['sub']),
            ),
            'lead' => array(
                'need' => $values['need'],
                'booking_requested' => false,
                'note' => $note,
            ),
            'fields' => $values,
            'source' => array(
                'provider' => 'mmeauth',
                'url' => '',
                'origin' => 'zalo_miniapp',
                'referrer' => '',
                'form_id' => $form_id,
            ),
            'attribution' => array(),
            'timestamp' => gmdate('c'),
            'started_at' => '',
        );

        return $this->store_submission($form_id, $settings, $payload, $identity, 'mme_form_dup_' . md5($form_id . '|' . wp_json_encode($values)));
    }

    /** Lưu submission, trả phản hồi ngay; webhook/Twenty/Pancake chạy sau (shutdown + WP-Cron dự phòng/thử lại). */
    private function store_submission(int $form_id, array $settings, array $payload, ?array $identity, string $duplicate_key)
    {
        $contact = $payload['contact'];
        $event_id = $payload['event_id'];
        $submission_id = wp_insert_post(array(
            'post_type' => 'mme_form_submission',
            'post_status' => 'publish',
            'post_title' => sprintf('%s — %s', $contact['name'] ?: ($contact['phone'] ?: 'Lead'), wp_date('Y-m-d H:i:s')),
        ));

        if (is_wp_error($submission_id)) {
            return new WP_Error('mme_form_storage_failed', 'Không thể lưu submission.', array('status' => 500));
        }

        update_post_meta($submission_id, '_mme_submission_payload', $payload);
        update_post_meta($submission_id, '_mme_submission_form_id', $form_id);

        if ($identity) {
            MME_Form_MMeAuth::mark_used($identity);
        }
        set_transient($duplicate_key, $event_id, 2 * MINUTE_IN_SECONDS);

        update_post_meta($submission_id, '_mme_submission_dispatch', array('status' => 'pending', 'attempts' => 0));
        $runs_after_response = function_exists('fastcgi_finish_request');
        wp_schedule_single_event($runs_after_response ? time() + MINUTE_IN_SECONDS : time(), 'mme_form_dispatch_integrations', array($submission_id));
        $this->pending_dispatch[] = (int) $submission_id;

        return new WP_REST_Response(array(
            'success' => true,
            'event_id' => $event_id,
            'message' => sanitize_text_field($settings['success_message']),
        ), 201);
    }

    /** Chạy sau khi phản hồi đã được gửi đi (PHP-FPM); không có FPM thì để WP-Cron xử lý. */
    public function flush_pending_dispatch(): void
    {
        if (!$this->pending_dispatch || !function_exists('fastcgi_finish_request')) {
            return;
        }
        fastcgi_finish_request();
        ignore_user_abort(true);
        foreach ($this->pending_dispatch as $submission_id) {
            $this->dispatch_integrations($submission_id);
        }
        $this->pending_dispatch = array();
    }

    /**
     * Gửi submission tới webhook / Twenty / Pancake. Tích hợp nào đã thành công thì bỏ qua, lỗi thì hẹn thử lại.
     * @param bool $force Admin bấm "Gửi lại": chạy lại cả khi đã hết lượt thử.
     */
    public function dispatch_integrations($submission_id, bool $force = false): array
    {
        $submission_id = absint($submission_id);
        $payload = get_post_meta($submission_id, '_mme_submission_payload', true);
        if (!is_array($payload)) {
            return array();
        }
        $state = get_post_meta($submission_id, '_mme_submission_dispatch', true);
        $state = wp_parse_args(is_array($state) ? $state : array(), array('status' => 'pending', 'attempts' => 0));
        $results = get_post_meta($submission_id, '_mme_submission_integrations', true);
        $results = is_array($results) ? $results : array();
        if (!$force && in_array($state['status'], array('done', 'failed'), true)) {
            return $results;
        }

        // add_option là thao tác nguyên tử: chặn shutdown và WP-Cron cùng gửi 1 submission
        $lock = 'mme_form_dispatch_lock_' . $submission_id;
        if (!add_option($lock, time(), '', 'no')) {
            if (time() - (int) get_option($lock) < 2 * MINUTE_IN_SECONDS) {
                return $results;
            }
            update_option($lock, time(), false);
        }

        $form_id = absint($payload['form']['id'] ?? 0);
        $settings = MME_Form_Plugin::form_settings($form_id);
        $senders = array(
            'webhook' => function () use ($settings, $payload) { return $this->send_webhook($settings, $payload); },
            'twenty' => function () use ($form_id, $settings, $payload) { return $this->send_twenty($form_id, $settings, $payload); },
            'pancake' => function () use ($settings, $payload) { return $this->send_pancake($settings, $payload); },
        );
        $has_failure = false;
        foreach ($senders as $key => $send) {
            if (!empty($results[$key]['success'])) {
                continue;
            }
            $results[$key] = $send();
            if (empty($results[$key]['success']) && empty($results[$key]['skipped'])) {
                $has_failure = true;
            }
        }

        $attempts = (int) $state['attempts'] + 1;
        $status = 'done';
        if ($has_failure) {
            $status = $attempts >= self::MAX_DISPATCH_ATTEMPTS ? 'failed' : 'retrying';
        }
        update_post_meta($submission_id, '_mme_submission_integrations', $results);
        update_post_meta($submission_id, '_mme_submission_dispatch', array('status' => $status, 'attempts' => $attempts, 'last_run' => time()));

        wp_clear_scheduled_hook('mme_form_dispatch_integrations', array($submission_id));
        if ($status === 'retrying') {
            wp_schedule_single_event(time() + $attempts * 5 * MINUTE_IN_SECONDS, 'mme_form_dispatch_integrations', array($submission_id));
        }
        delete_option($lock);

        return $results;
    }

    private function allow_request(): bool
    {
        $ip = $this->client_ip();
        $key = 'mme_form_rate_' . md5(wp_salt('nonce') . '|' . $ip);
        $count = absint(get_transient($key));
        if ($count >= 15) {
            return false;
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    /** Chỉ tin header CF-Connecting-IP khi request thật sự đi qua Cloudflare, tránh bị giả header để vượt rate limit. */
    private function client_ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return 'unknown';
        }
        $cf_ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($cf_ip !== '' && filter_var($cf_ip, FILTER_VALIDATE_IP) && $this->is_cloudflare_ip($remote)) {
            return $cf_ip;
        }
        return $remote;
    }

    private function is_cloudflare_ip(string $ip): bool
    {
        // https://www.cloudflare.com/ips/
        $ranges = array(
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
            '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
            '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
            '2a06:98c0::/29', '2c0f:f248::/32',
        );
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            list($subnet, $bits) = explode('/', $range);
            $subnet = inet_pton($subnet);
            if ($subnet === false || strlen($subnet) !== strlen($packed)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($subnet, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if ((($packed[$bytes] & $mask) === ($subnet[$bytes] & $mask))) {
                return true;
            }
        }
        return false;
    }

    private function sanitize_attribution($raw): array
    {
        if (!is_array($raw)) {
            return array();
        }
        $allowed = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid');
        $output = array();
        foreach ($allowed as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $output[$key] = sanitize_text_field((string) $raw[$key]);
            }
        }
        return $output;
    }

    private function limit_string(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length)
            : substr($value, 0, $length);
    }

    private function extract_contact(array $fields, array $values): array
    {
        $name = $this->first_value($values, array('full_name', 'name', 'fullname', 'ho_ten', 'hoten'));
        $phone = $this->first_value($values, array('phone', 'telephone', 'mobile', 'so_dien_thoai', 'sdt'));
        $email = $this->first_value($values, array('email', 'email_address'));

        foreach ($fields as $field) {
            $field_name = sanitize_text_field($field['name'] ?? '');
            $field_name = trim(preg_replace('/\s+/', '_', $field_name));
            $field_type = sanitize_key($field['type'] ?? '');
            if (!$email && $field_type === 'email') {
                $email = $values[$field_name] ?? '';
            }
            if (!$phone && $field_type === 'tel') {
                $phone = $values[$field_name] ?? '';
            }
        }

        return array('name' => $name, 'phone' => $phone, 'email' => $email);
    }

    private function first_value(array $values, array $keys): string
    {
        foreach ($keys as $key) {
            if (!empty($values[$key])) {
                return (string) $values[$key];
            }
        }
        return '';
    }

    private function looks_like_booking(array $values): bool
    {
        $haystack = strtolower(implode(' ', array_values($values)));
        foreach (array('đặt lịch', 'dat lich', 'booking', 'meeting', 'hẹn', 'hen lich') as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function send_webhook(array $settings, array $payload): array
    {
        if (($settings['webhook_enabled'] ?? 'no') !== 'yes' || empty($settings['webhook_url'])) {
            return array('success' => false, 'skipped' => true);
        }

        $url = trim((string) $settings['webhook_url']);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $url = esc_url_raw($url);
        $secret = trim((string) ($settings['webhook_secret'] ?? ''));
        $query = array();
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        if (!empty($query['secret'])) {
            $payload['secret'] = sanitize_text_field((string) $query['secret']);
        } elseif ($secret && strpos((string) wp_parse_url($url, PHP_URL_HOST), 'script.google.com') !== false) {
            $payload['secret'] = $secret;
        }

        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $headers = array(
            'Content-Type' => 'application/json',
            'X-MME-Event' => 'lead_collected',
            'X-MME-Timestamp' => $timestamp,
        );
        if ($secret) {
            $headers['X-MME-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        // Force IPv4 if cURL is used, preventing Ubuntu/WordOps IPv6 timeout to script.google.com
        $force_ipv4 = static function ($handle): void {
            if (defined('CURL_IPRESOLVE_V4')) {
                curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
        };
        add_action('http_api_curl', $force_ipv4, 10, 1);

        $is_gas = strpos((string) wp_parse_url($url, PHP_URL_HOST), 'script.google.com') !== false;

        // Google Apps Script runs doPost() then answers 302 -> script.googleusercontent.com.
        // WP Requests re-POSTs on 302 (only 303 is converted to GET), which Google rejects with 405.
        // So for GAS: don't auto-follow, then fetch the redirect target with GET to read the JSON result.
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => $body,
            'timeout' => 20,
            'redirection' => $is_gas ? 0 : 5,
        ));

        if (!is_wp_error($response) && $is_gas) {
            $first_status = (int) wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_array($location)) {
                $location = end($location);
            }
            if ($first_status >= 300 && $first_status < 400 && $location) {
                $follow = wp_remote_get((string) $location, array('timeout' => 20, 'redirection' => 3));
                if (!is_wp_error($follow)) {
                    $response = $follow;
                } else {
                    // doPost() already ran; we just couldn't read its JSON output.
                    remove_action('http_api_curl', $force_ipv4, 10);
                    return array('success' => true, 'status_code' => $first_status, 'body' => 'Redirect read failed: ' . $follow->get_error_message());
                }
            }
        }

        remove_action('http_api_curl', $force_ipv4, 10);

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        $is_ok = $status >= 200 && $status < 300;

        $result = array(
            'success' => $is_ok,
            'status_code' => $status,
        );

        // Apps Script always returns 200; honour {"success": false, "error": "..."} from the script itself.
        $json = json_decode((string) $resp_body, true);
        if ($is_ok && is_array($json) && array_key_exists('success', $json) && !$json['success']) {
            $result['success'] = false;
            $result['error'] = 'Apps Script: ' . (is_scalar($json['error'] ?? null) ? (string) $json['error'] : 'success=false');
        }
        if (!empty($resp_body)) {
            $result['body'] = mb_substr(trim($resp_body), 0, 250);
        }
        // Record which endpoint was called (no query string, so ?secret=... is never stored).
        $result['endpoint'] = (string) wp_parse_url($url, PHP_URL_HOST) . (string) wp_parse_url($url, PHP_URL_PATH);

        return $result;
    }

    private function get_twenty_person_fields(array $settings): array
    {
        $base_url = untrailingslashit(esc_url_raw($settings['twenty_base_url']));
        $api_key = sanitize_text_field($settings['twenty_api_key']);
        $cache_key = 'mme_twenty_person_fields_' . md5($base_url . $api_key);
        
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        
        $url = $base_url . (substr(strtolower($base_url), -5) === '/rest' ? '/metadata/objects' : '/rest/metadata/objects');
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 5,
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return array();
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['data']) || !is_array($data['data'])) {
            return array();
        }
        
        $fields = array();
        foreach ($data['data'] as $obj) {
            if (($obj['nameSingular'] ?? '') === 'person' && !empty($obj['fields'])) {
                foreach ($obj['fields'] as $field) {
                    if (!empty($field['name'])) {
                        $fields[] = $field['name'];
                    }
                }
                break;
            }
        }
        
        if ($fields) {
            set_transient($cache_key, $fields, HOUR_IN_SECONDS);
        }
        
        return $fields;
    }

    public function send_twenty(int $post_id, array $settings, array $payload): array
    {
        if (($settings['twenty_enabled'] ?? 'no') !== 'yes') {
            return array('success' => false, 'skipped' => true);
        }
        if (empty($settings['twenty_base_url']) || empty($settings['twenty_api_key'])) {
            return array('success' => false, 'skipped' => true, 'reason' => 'missing_credentials');
        }

        $contact = (array) $payload['contact'];
        $lead = (array) $payload['lead'];
        $source_url = (string) ($payload['source']['url'] ?? '');
        $intro = array_filter(array($lead['need'] ?? '', $source_url ? 'Source URL: ' . $source_url : ''));
        
        $raw_name = $contact['name'] ?: ($contact['phone'] ?: ($contact['email'] ?: 'Website lead'));
        $name_parts = explode(' ', trim($raw_name), 2);
        
        $person = array(
            'name' => array(
                'firstName' => $name_parts[0],
                'lastName' => $name_parts[1] ?? '',
            ),
        );
        
        if (!empty($contact['email'])) {
            $person['emails'] = array('primaryEmail' => $contact['email']);
        }
        
        if (!empty($contact['phone'])) {
            $phone = preg_replace('/[^0-9+]/', '', $contact['phone']);
            if (strpos($phone, '0') === 0 && strlen($phone) === 10) {
                $phone = '+84' . substr($phone, 1);
            }
            $person['phones'] = array('primaryPhoneNumber' => $phone);
        }
        
        $allowed_fields = $this->get_twenty_person_fields($settings);
        
        if (!empty($lead['need'])) {
            if (in_array('need', $allowed_fields, true)) {
                $person['need'] = $lead['need'];
            } elseif (in_array('needCustom', $allowed_fields, true)) {
                $person['needCustom'] = $lead['need'];
            }
        }
        
        $twenty_metadata = get_post_meta($post_id, '_mme_twenty_person_metadata', true);
        if (!is_array($twenty_metadata)) {
            $twenty_metadata = array();
        }
        
        $standard_keys = array('full_name', 'name', 'fullname', 'ho_ten', 'hoten', 'phone', 'telephone', 'mobile', 'so_dien_thoai', 'sdt', 'email', 'email_address', 'need');
        foreach ((array) $payload['fields'] as $k => $v) {
            if (in_array(strtolower($k), $standard_keys, true)) {
                continue;
            }
            
            $mapped_key = null;
            if (in_array($k, $allowed_fields, true)) {
                $mapped_key = $k;
            } elseif (in_array($k . 'Custom', $allowed_fields, true)) {
                $mapped_key = $k . 'Custom';
            }
            
            if ($mapped_key) {
                $twenty_type = $twenty_metadata[$mapped_key]['type'] ?? '';
                if ($twenty_type === 'SELECT' || $twenty_type === 'MULTI_SELECT') {
                    $slug = remove_accents($v);
                    $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $slug);
                    $slug = trim($slug, '_');
                    $slug = strtoupper($slug);
                    
                    $matched = false;
                    if (!empty($twenty_metadata[$mapped_key]['options'])) {
                        foreach ($twenty_metadata[$mapped_key]['options'] as $opt) {
                            if ($opt['value'] === $slug || strcasecmp($opt['label'], $v) === 0 || strcasecmp($opt['value'], $v) === 0) {
                                $v = $opt['value'];
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if (!$matched) {
                        $v = $slug;
                    }
                    
                    if ($twenty_type === 'MULTI_SELECT') {
                        $v = array($v);
                    }
                } elseif ($twenty_type === 'NUMBER' || $twenty_type === 'CURRENCY') {
                    $v = (float)$v;
                } elseif ($twenty_type === 'BOOLEAN') {
                    $v = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                } elseif ($twenty_type === 'DATE' || $twenty_type === 'DATE_TIME') {
                    if (is_numeric($v)) {
                        $v = date('c', (int)($v / 1000));
                    } elseif (strtotime($v)) {
                        $v = date('c', strtotime($v));
                    }
                }
                
                $person[$mapped_key] = $v;
            }
        }

        $hidden_fields = array(
            'current_url' => $payload['source']['url'] ?? '',
            'referrer_url' => $payload['source']['referrer'] ?? '',
            'started_at' => $payload['started_at'] ?? '',
        );
        foreach ($hidden_fields as $k => $v) {
            if ($v !== '') {
                $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $k))));
                $mapped_key = null;
                if (in_array($k, $allowed_fields, true)) {
                    $mapped_key = $k;
                } elseif (in_array($camel, $allowed_fields, true)) {
                    $mapped_key = $camel;
                } elseif (in_array($k . 'Custom', $allowed_fields, true)) {
                    $mapped_key = $k . 'Custom';
                } elseif (in_array($camel . 'Custom', $allowed_fields, true)) {
                    $mapped_key = $camel . 'Custom';
                }
                
                if ($mapped_key) {
                    if ($k === 'started_at') {
                        $person[$mapped_key] = date('c', is_numeric($v) ? (int) ($v / 1000) : time());
                    } elseif ($k === 'current_url' || $k === 'referrer_url') {
                        $person[$mapped_key] = array(
                            'primaryLinkUrl' => $v,
                            'primaryLinkLabel' => $k === 'current_url' ? 'Current URL' : 'Referrer URL'
                        );
                    } else {
                        $person[$mapped_key] = $v;
                    }
                }
            }
        }

        $base_url = untrailingslashit(esc_url_raw($settings['twenty_base_url']));
        if (substr(strtolower($base_url), -5) === '/rest') {
            $url = $base_url . '/people';
        } else {
            $url = $base_url . '/rest/people';
        }
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . sanitize_text_field($settings['twenty_api_key']),
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($person, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 10,
        ));
        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            error_log('MME Form - Twenty CRM Error: ' . $err);
            return array('success' => false, 'error' => $err);
        }
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($status >= 300) {
            error_log('MME Form - Twenty CRM Error HTTP ' . $status . ': ' . $body);
        }
        return array('success' => $status >= 200 && $status < 300, 'status_code' => $status, 'response' => json_decode($body, true));
    }

    public function send_pancake(array $settings, array $payload): array
    {
        if (($settings['pancake_enabled'] ?? 'no') !== 'yes') {
            return array('success' => false, 'skipped' => true);
        }
        if (empty($settings['pancake_workspace_id']) || empty($settings['pancake_api_key'])) {
            return array('success' => false, 'skipped' => true, 'reason' => 'missing_credentials');
        }

        $workspace_id = rawurlencode(trim((string) $settings['pancake_workspace_id']));
        $api_key = rawurlencode(trim((string) $settings['pancake_api_key']));
        $table = in_array($settings['pancake_table'] ?? '', array('contact', 'lead'), true) ? $settings['pancake_table'] : 'contact';

        $contact = (array) ($payload['contact'] ?? array());
        $fields = (array) ($payload['fields'] ?? array());
        $raw_name = !empty($contact['name']) ? $contact['name'] : (!empty($payload['lead']['name']) ? $payload['lead']['name'] : '');
        $phone = !empty($contact['phone']) ? $contact['phone'] : '';
        $email = !empty($contact['email']) ? $contact['email'] : '';
        $source_url = (string) ($payload['source']['url'] ?? '');
        $form_title = (string) ($payload['form']['title'] ?? 'Form liên hệ');

        $notes = array();
        $notes[] = 'Loại Form: ' . $form_title;
        if ($source_url) {
            $notes[] = 'Nguồn: ' . $source_url;
        }
        if (!empty($payload['lead']['need'])) {
            $notes[] = 'Nhu cầu: ' . $payload['lead']['need'];
        }
        foreach ($fields as $k => $v) {
            if (in_array(strtolower($k), array('full_name', 'name', 'fullname', 'ho_ten', 'phone', 'telephone', 'mobile', 'sdt', 'email'), true)) {
                continue;
            }
            if (is_scalar($v) && trim((string) $v) !== '') {
                $notes[] = $k . ': ' . trim((string) $v);
            }
        }
        if (!empty($payload['attribution']) && is_array($payload['attribution'])) {
            $utms = array();
            foreach ($payload['attribution'] as $uk => $uv) {
                if ($uv) {
                    $utms[] = $uk . '=' . $uv;
                }
            }
            if (!empty($utms)) {
                $notes[] = 'UTM: ' . implode(', ', $utms);
            }
        }
        $note_str = implode("\n", $notes);

        // 1. Try Pancake CRM v2 (crm.pancake.vn)
        $v2_url = sprintf('https://crm.pancake.vn/api/workspaces/%s/%s/records?api_key=%s', $workspace_id, $table, $api_key);
        $v2_payload = array(
            'name' => $raw_name ?: ($phone ?: 'Khách Website'),
            'phone_number' => $phone,
            'email' => $email,
            'note' => $note_str,
        );

        $response = wp_remote_post($v2_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($v2_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 10,
        ));

        if (!is_wp_error($response)) {
            $status = wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) {
                return array('success' => true, 'status_code' => $status, 'api' => 'crm_v2');
            }
        }

        // 2. Fallback to Pancake POS v1 (pos.pages.fm)
        $v1_table = ucfirst($table);
        $v1_url = sprintf('https://pos.pages.fm/api/v1/shops/%s/crm/%s/records?api_key=%s', $workspace_id, $v1_table, $api_key);
        $v1_payload = array(
            'Name' => $raw_name ?: ($phone ?: 'Khách Website'),
            'Phone' => $phone,
            'Email' => $email,
            'link' => $source_url,
            'Note' => $note_str,
        );

        $v1_response = wp_remote_post($v1_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($v1_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 10,
        ));

        if (is_wp_error($v1_response)) {
            $err = $v1_response->get_error_message();
            error_log('MME Form - Pancake CRM Error: ' . $err);
            return array('success' => false, 'error' => $err);
        }

        $v1_status = wp_remote_retrieve_response_code($v1_response);
        $v1_body = wp_remote_retrieve_body($v1_response);

        if ($v1_status >= 200 && $v1_status < 300) {
            return array('success' => true, 'status_code' => $v1_status, 'api' => 'pos_v1');
        }

        $v2_err = is_wp_error($response) ? $response->get_error_message() : ('HTTP ' . wp_remote_retrieve_response_code($response) . ': ' . wp_remote_retrieve_body($response));
        error_log('MME Form - Pancake CRM Failed. v2: ' . $v2_err . ' | v1: HTTP ' . $v1_status . ': ' . $v1_body);
        return array(
            'success' => false,
            'status_code' => $v1_status,
            'error' => 'Pancake CRM v2 (' . $v2_err . ') | POS v1 (' . $v1_body . ')',
        );
    }
}
}

