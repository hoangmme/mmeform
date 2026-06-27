<?php

if (!defined('ABSPATH')) {
    exit;
}

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

    public function boot(): void
    {
        add_action('rest_api_init', array($this, 'register_routes'));
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
    }

    public function submit(WP_REST_Request $request): WP_REST_Response|WP_Error
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
            $name = sanitize_key($field['name'] ?? '');
            if (!$name) {
                continue;
            }
            $type = sanitize_key($field['type'] ?? 'text');
            $raw_value = is_scalar($raw_values[$name] ?? null) ? (string) $raw_values[$name] : '';
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
            return new WP_Error('mme_form_validation', 'Vui lòng kiểm tra các trường bắt buộc.', array('status' => 422, 'fields' => $errors));
        }

        $current_url = isset($input['current_url']) && is_scalar($input['current_url'])
            ? esc_url_raw($this->limit_string((string) $input['current_url'], 4096))
            : '';
        $referrer_url = isset($input['referrer_url']) && is_scalar($input['referrer_url'])
            ? esc_url_raw($this->limit_string((string) $input['referrer_url'], 4096))
            : '';
        $attribution = $this->sanitize_attribution($input['attribution'] ?? array());
        $contact = $this->extract_contact($fields, $values);
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
        );

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

        $settings = wp_parse_args((array) get_post_meta($form_id, '_mme_form_settings', true), MME_Form_Plugin::default_settings());
        $integration_results = array(
            'webhook' => $this->send_webhook($settings, $payload),
            'twenty' => $this->send_twenty($settings, $payload),
        );
        update_post_meta($submission_id, '_mme_submission_integrations', $integration_results);

        return new WP_REST_Response(array(
            'success' => true,
            'event_id' => $event_id,
            'message' => sanitize_text_field($settings['success_message']),
        ), 201);
    }

    private function allow_request(): bool
    {
        $candidate_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ip = filter_var($candidate_ip, FILTER_VALIDATE_IP) ? (string) $candidate_ip : 'unknown';
        $key = 'mme_form_rate_' . md5(wp_salt('nonce') . '|' . $ip);
        $count = absint(get_transient($key));
        if ($count >= 15) {
            return false;
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private function sanitize_attribution(mixed $raw): array
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
            $field_name = sanitize_key($field['name'] ?? '');
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
            if (str_contains($haystack, $needle)) {
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

        $url = esc_url_raw($settings['webhook_url']);
        $secret = (string) ($settings['webhook_secret'] ?? '');
        $query = array();
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        if (!empty($query['secret'])) {
            $payload['secret'] = sanitize_text_field((string) $query['secret']);
        } elseif ($secret && str_contains((string) wp_parse_url($url, PHP_URL_HOST), 'script.google.com')) {
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

        $response = wp_remote_post($url, array('headers' => $headers, 'body' => $body, 'timeout' => 10, 'redirection' => 3));
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        $status = wp_remote_retrieve_response_code($response);
        return array('success' => $status >= 200 && $status < 300, 'status_code' => $status);
    }

    private function send_twenty(array $settings, array $payload): array
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
            if (str_starts_with($phone, '0') && strlen($phone) === 10) {
                $phone = '+84' . substr($phone, 1);
            }
            $person['phones'] = array('primaryPhoneNumber' => $phone);
        }

        $base_url = untrailingslashit(esc_url_raw($settings['twenty_base_url']));
        if (str_ends_with(strtolower($base_url), '/rest')) {
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
            return array('success' => false, 'error' => $response->get_error_message());
        }
        $status = wp_remote_retrieve_response_code($response);
        return array('success' => $status >= 200 && $status < 300, 'status_code' => $status);
    }
}
