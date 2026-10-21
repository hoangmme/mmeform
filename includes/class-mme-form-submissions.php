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

        register_rest_route('mme-form/v1', '/send-otp', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'send_otp'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function send_otp(WP_REST_Request $request)
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            return new WP_Error('mme_form_invalid_json', 'Dữ liệu không hợp lệ.', array('status' => 400));
        }

        $form_id = absint($input['form_id'] ?? 0);
        $form = get_post($form_id);
        if (!$form || $form->post_type !== 'mme_form' || $form->post_status !== 'publish') {
            return new WP_Error('mme_form_not_found', 'Form không tồn tại.', array('status' => 404));
        }

        $settings = wp_parse_args((array) get_post_meta($form_id, '_mme_form_settings', true), MME_Form_Plugin::default_settings());
        if (($settings['otp_enabled'] ?? 'no') !== 'yes') {
            return new WP_Error('mme_form_otp_disabled', 'Tính năng xác thực OTP chưa được bật cho form này.', array('status' => 400));
        }

        $email = sanitize_email($input['email'] ?? '');
        if (!$email || !is_email($email)) {
            return new WP_Error('mme_form_invalid_email', 'Vui lòng nhập địa chỉ email hợp lệ.', array('status' => 400));
        }

        // Cooldown check (default 60 seconds)
        $cooldown_secs = !empty($settings['otp_cooldown_seconds']) ? absint($settings['otp_cooldown_seconds']) : 60;
        $cd_key = 'mme_otp_cd_' . md5($form_id . '_' . strtolower($email));
        $last_sent = get_transient($cd_key);
        if ($last_sent) {
            $remaining = $cooldown_secs - (time() - $last_sent);
            if ($remaining > 0) {
                return new WP_Error('mme_form_otp_cooldown', sprintf('Vui lòng đợi %d giây trước khi yêu cầu gửi lại mã.', $remaining), array('status' => 429, 'cooldown' => $remaining));
            }
        }

        // Generate 6-digit numeric OTP
        $otp = (string) wp_rand(100000, 999999);
        $expiry_mins = !empty($settings['otp_expiry_minutes']) ? absint($settings['otp_expiry_minutes']) : 5;
        $otp_key = 'mme_otp_' . md5($form_id . '_' . strtolower($email));

        set_transient($otp_key, array('code' => $otp, 'created_at' => time()), $expiry_mins * MINUTE_IN_SECONDS);
        set_transient($cd_key, time(), $cooldown_secs);

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $form_title = get_the_title($form_id);

        $subject_template = !empty($settings['otp_email_subject']) ? $settings['otp_email_subject'] : '[{site_name}] Mã xác thực đăng ký: {otp}';
        $subject = str_replace(
            array('{site_name}', '{form_title}', '{otp}'),
            array($site_name, $form_title, $otp),
            $subject_template
        );

        $message = sprintf(
            "Xin chào,\n\nMã xác thực OTP của bạn cho form \"%s\" là: %s\n\nMã này có hiệu lực trong vòng %d phút. Vui lòng không chia sẻ mã này cho bất kỳ ai.\n\nTrân trọng,\n%s",
            $form_title,
            $otp,
            $expiry_mins,
            $site_name
        );

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($email, $subject, $message, $headers);

        return new WP_REST_Response(array(
            'success'  => true,
            'message'  => sprintf('Đã gửi mã OTP 6 chữ số đến %s. Vui lòng kiểm tra hộp thư (kể cả mục Spam).', $email),
            'cooldown' => $cooldown_secs,
        ), 200);
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

        $settings = wp_parse_args((array) get_post_meta($form_id, '_mme_form_settings', true), MME_Form_Plugin::default_settings());
        if (($settings['otp_enabled'] ?? 'no') === 'yes') {
            $user_email = '';
            $has_email_field = false;
            foreach ($fields as $f) {
                if (($f['type'] ?? '') === 'email') {
                    $has_email_field = true;
                    $n = trim(preg_replace('/\s+/', '_', $f['name'] ?? ''));
                    if (!empty($values[$n]) && is_email($values[$n])) {
                        $user_email = $values[$n];
                        break;
                    }
                }
            }

            if ($has_email_field && empty($user_email)) {
                return new WP_Error('mme_form_email_required_for_otp', 'Vui lòng nhập địa chỉ email hợp lệ để nhận mã xác thực OTP.', array('status' => 400));
            }

            if ($user_email) {
                $submitted_otp = isset($input['otp_code']) ? trim((string) $input['otp_code']) : '';
                if ($submitted_otp === '') {
                    return new WP_Error('mme_form_otp_required', 'Vui lòng nhập mã OTP đã được gửi đến email của bạn.', array('status' => 400));
                }

                $otp_key = 'mme_otp_' . md5($form_id . '_' . strtolower($user_email));
                $stored_otp = get_transient($otp_key);

                if (!$stored_otp || empty($stored_otp['code'])) {
                    return new WP_Error('mme_form_otp_expired', 'Mã OTP đã hết hạn hoặc chưa được tạo. Vui lòng bấm gửi lại mã OTP.', array('status' => 400));
                }

                if ((string) $stored_otp['code'] !== $submitted_otp) {
                    return new WP_Error('mme_form_otp_invalid', 'Mã OTP không chính xác. Vui lòng kiểm tra lại.', array('status' => 400));
                }

                delete_transient($otp_key);
            }
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
            'started_at' => $input['started_at'] ?? '',
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
            'twenty' => $this->send_twenty($form_id, $settings, $payload),
            'pancake' => $this->send_pancake($settings, $payload),
        );
        update_post_meta($submission_id, '_mme_submission_integrations', $integration_results);

        $message = sanitize_text_field($settings['success_message']);
        
        if (current_user_can('manage_options')) {
            if (isset($integration_results['twenty']) && empty($integration_results['twenty']['success']) && empty($integration_results['twenty']['skipped'])) {
                $err = $integration_results['twenty']['error'] ?? json_encode($integration_results['twenty']['response'] ?? 'Unknown error', JSON_UNESCAPED_UNICODE);
                $message .= '<br><br><b style="color:#d63638">⚠️ Lỗi đồng bộ Twenty CRM (Chỉ Admin mới thấy):</b><br> ' . esc_html($err);
            }
            if (isset($integration_results['pancake']) && empty($integration_results['pancake']['success']) && empty($integration_results['pancake']['skipped'])) {
                $err = $integration_results['pancake']['error'] ?? json_encode($integration_results['pancake']['response'] ?? 'Unknown error', JSON_UNESCAPED_UNICODE);
                $message .= '<br><br><b style="color:#d63638">⚠️ Lỗi đồng bộ Pancake CRM (Chỉ Admin mới thấy):</b><br> ' . esc_html($err);
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'event_id' => $event_id,
            'message' => $message,
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

        $url = esc_url_raw($settings['webhook_url']);
        $secret = (string) ($settings['webhook_secret'] ?? '');
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

        $response = wp_remote_post($url, array('headers' => $headers, 'body' => $body, 'timeout' => 10, 'redirection' => 3));
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        $status = wp_remote_retrieve_response_code($response);
        return array('success' => $status >= 200 && $status < 300, 'status_code' => $status);
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

