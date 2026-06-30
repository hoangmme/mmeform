# MME Form

WordPress plugin tạo form compact, thu lead và dùng được cả trên website WordPress lẫn website ngoài.

## Tính năng

- Tạo nhiều form bằng Custom Post Type `MME Forms`.
- Field builder: text, email, phone, textarea, dropdown và radio.
- Tự lấy full URL trang hiện tại, referrer, UTM, `gclid`, `fbclid`.
- Giao diện compact; ảnh trái/phải/trên; màu nút, màu nhấn, nền, chữ và font.
- Trust badges, icon, Facebook, Zalo và LinkedIn để form bớt đơn điệu.
- Lưu submission trong WordPress.
- Google Sheets / webhook theo chuẩn `lead_collected` của `mmechatbot`.
- Tạo People trong Twenty CRM qua REST API.
- Mở chatbot `mmechatbot` ngay trong form và truyền full parent URL cho chatbot.
- Shortcode WordPress và script embed cho website khác.
- Auto-resize iframe và gửi event sang GTM/dataLayer, `gtag` và Meta Pixel trên website cha.
- Honeypot, giới hạn tốc độ và chặn submit quá nhanh.

## Cài đặt

1. Copy thư mục `mmeform` vào `wp-content/plugins/mme-form` hoặc zip thư mục rồi upload trong WordPress.
2. Kích hoạt plugin **MME Form**.
3. Plugin tự tạo form mẫu **Form tư vấn MME** ở trạng thái Published.
4. Vào **MME Forms** để sửa fields, giao diện và integrations.

Plugin không cần build Node/npm.

## Dùng trong WordPress

Copy shortcode trong box **Publish & embed**:

```text
[mme_form id="123"]
```

Đặt shortcode vào page, post, Gutenberg Shortcode block hoặc page builder.

## Embed sang website khác

Copy script được sinh trong box **Publish & embed**. Mẫu:

```html
<script
  src="https://wordpress.example.com/wp-content/plugins/mme-form/assets/embed.js"
  data-mme-form="123"
  data-endpoint="https://wordpress.example.com/?mme_form_embed=123">
</script>
```

Loader sẽ:

1. Gắn `window.location.href` của website cha vào iframe.
2. Tự resize iframe theo chiều cao form/chatbot.
3. Forward event tracking về website cha.

## Tracking / Pixel

Plugin phát các event:

```text
mme_form_view
mme_form_submit_success
mme_form_submit_error
```

Event chỉ gửi metadata không chứa PII:

```json
{
  "form_id": "123",
  "page_host": "customer-site.com",
  "page_path": "/landing-page",
  "event_id": "uuid-after-success"
}
```

Nếu website cha có `window.dataLayer`, `gtag()` hoặc `fbq()`, embed loader tự forward event. Full URL vẫn được lưu trong submission/webhook để attribution, nhưng không gửi full query string sang pixel.

## MME Chatbot

Mặc định form bật chatbot với:

```text
Base URL: https://chat.mme.vn
Tenant slug: mme
```

Khi mở chat, plugin tạo URL:

```text
https://chat.mme.vn/embed/mme?parentOrigin=...&parentUrl=<full-current-url>
```

Trong `mmechatbot`, nhớ thêm domain WordPress và domain website embed vào `allowed_origins` của tenant.

## Google Sheets webhook

1. Google Sheet > Extensions > Apps Script.
2. Paste [`examples/google-apps-script.js`](./examples/google-apps-script.js).
3. Đổi `WEBHOOK_SECRET`.
4. Deploy > New deployment > Web app > Execute as Me > Anyone.
5. Bật **Gửi submission tới webhook** trong form.
6. Điền URL:

```text
https://script.google.com/macros/s/DEPLOYMENT_ID/exec?secret=YOUR_SECRET
```

Với Google Apps Script có thể để `Signing secret` trống và dùng `?secret=`. Với n8n/Make/custom API, điền signing secret để nhận:

```text
X-MME-Event: lead_collected
X-MME-Timestamp: unix timestamp
X-MME-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + raw_body)
```

## Twenty CRM

Trong form > **Integrations > Twenty CRM**:

1. Bật **Tạo People trong Twenty CRM**.
2. Điền base URL, ví dụ `https://nxhcrm.mme.vn`.
3. Điền API key của đúng workspace.

Plugin gọi:

```text
POST {TWENTY_BASE_URL}/rest/people
Authorization: Bearer {API_KEY}
```

Field nên đặt tên chuẩn để tự map:

| Dữ liệu | Field names hỗ trợ |
| --- | --- |
| Họ tên | `full_name`, `name`, `fullname`, `ho_ten`, `hoten` |
| Điện thoại | `phone`, `telephone`, `mobile`, `so_dien_thoai`, `sdt` hoặc type `tel` |
| Email | `email`, `email_address` hoặc type `email` |
| Nhu cầu | `need`, `message`, `note`, `nhu_cau`, `content` |

Lỗi integration không làm mất submission: dữ liệu vẫn lưu trong WordPress và kết quả webhook/Twenty nằm trong trang chi tiết submission.

## Payload webhook

```json
{
  "event_id": "uuid",
  "event": "lead_collected",
  "tenant": "wordpress.example.com",
  "channel": "mme_form",
  "form": { "id": 123, "title": "Form tư vấn MME" },
  "contact": {
    "name": "Nguyễn Văn A",
    "phone": "0901234567",
    "email": "a@example.com"
  },
  "lead": {
    "need": "Tư vấn chatbot",
    "booking_requested": false,
    "note": ""
  },
  "fields": {},
  "source": {
    "provider": "mme_form",
    "url": "https://customer-site.com/landing?utm_source=facebook",
    "origin": "customer-site.com",
    "referrer": "https://facebook.com/",
    "form_id": 123
  },
  "attribution": {
    "utm_source": "facebook"
  },
  "timestamp": "2026-06-27T00:00:00+00:00"
}
```

## Bảo mật và dữ liệu cá nhân

- Webhook secret và Twenty API key chỉ lưu trong post meta, không render ra frontend.
- Submission chứa PII; cần phân quyền WordPress admin và đặt thời hạn lưu dữ liệu phù hợp.
- Nên dùng HTTPS cho WordPress, website embed, webhook, chatbot và Twenty.
- Rate limit mặc định: 15 requests / 10 phút / IP.
- Không xóa dữ liệu khi deactivate plugin.

## Hướng dẫn cho Developer (Khởi tạo Form nhanh bằng JSON)

Trong wp-admin, phần tạo Form có tính năng **"Import JSON (Nâng cao)"**. Tính năng này cho phép bạn dán trực tiếp một mảng JSON để khởi tạo nhanh cấu trúc các trường (fields) thay vì phải bấm tạo từng cái bằng tay.

Đây là một tính năng cực kỳ hữu ích khi bạn làm việc với **AI (ChatGPT, Claude, Gemini...)**. Bạn có thể yêu cầu AI tự động sinh ra mã JSON dựa trên yêu cầu của khách hàng hoặc tài liệu API, sau đó chỉ việc copy và dán vào.

### Cấu trúc JSON chuẩn của Form Fields:

Mảng JSON cần tuân thủ cấu trúc sau (mỗi object là 1 field):

```json
[
  {
    "type": "text",
    "name": "full_name",
    "label": "Họ và tên",
    "placeholder": "Nguyễn Văn A",
    "required": true,
    "width": "100"
  },
  {
    "type": "tel",
    "name": "phone",
    "label": "Số điện thoại",
    "placeholder": "09xx xxx xxx",
    "required": true,
    "width": "50"
  },
  {
    "type": "select",
    "name": "need",
    "label": "Nhu cầu tư vấn",
    "placeholder": "Bạn đang quan tâm điều gì?",
    "options": "Tư vấn thiết kế\nThi công nội thất\nBáo giá trọn gói",
    "required": false,
    "width": "50"
  },
  {
    "type": "textarea",
    "name": "note",
    "label": "Ghi chú thêm",
    "placeholder": "Để lại lời nhắn...",
    "required": false,
    "width": "100"
  }
]
```

### Các thuộc tính (Properties):

- `type`: Loại trường. Các giá trị hỗ trợ: `text`, `email`, `tel`, `textarea`, `select`, `radio`.
- `name`: Tên biến dữ liệu (để gửi qua webhook hoặc CRM). Ví dụ: `phone`, `email`, `need`. **Lưu ý: Không dùng ký tự đặc biệt, nên dùng tiếng Anh viết thường không dấu gạch dưới.**
- `label`: Tên hiển thị cho người dùng (Label).
- `placeholder`: Dòng chữ mờ gợi ý trong ô input.
- `options`: **Chỉ dùng cho `select` và `radio`**. Các lựa chọn cách nhau bằng dấu xuống dòng (`\n`).
- `required`: Bắt buộc nhập (`true` hoặc `false`).
- `width`: Chiều rộng của ô. Hỗ trợ 2 giá trị: `"100"` (Full-width) hoặc `"50"` (Chia đôi dòng).

### Prompt mẫu để nhờ AI viết JSON:

Bạn có thể copy đoạn hướng dẫn sau gửi cho AI:

> "Hãy tạo cho tôi mảng JSON cấu trúc form dựa theo document của MME Form: Cần các trường: Họ tên, Số điện thoại, Email, Chức vụ (select: Giám đốc, Nhân viên, Khác), Ghi chú. Thiết lập width 50 cho điện thoại và email."

---

## 🚀 Dành cho Theme Developer / AI Code Landing Page (Tự động tạo form qua PHP)

Nếu bạn code trực tiếp trong file template PHP (ví dụ `page-landing.php`) và không muốn phải vào wp-admin bấm tạo form hay lấy ID thủ công, plugin cung cấp hàm PHP thần thánh: `mme_form_auto()`.

### Hàm `mme_form_auto($slug, $title, $fields, $settings, $fields_only = true)`

Hàm này sẽ tự động tìm trong database xem đã có form nào mang slug đó chưa:
- **Nếu đã có:** Tự động lấy ID và render ra HTML shortcode.
- **Nếu chưa có:** Tự động tạo 1 bài viết `mme_form` mới trong database với `$fields` và `$settings` được cung cấp, sau đó render ra HTML.
- **Lưu ý quan trọng (`$fields_only = true`):** Mặc định hàm sẽ sử dụng shortcode `[mme_form_fields id="..."]` (Chỉ Form nhập liệu, ẩn cột thông tin bên trái). Điều này giúp form hoàn toàn ăn khớp khi chèn vào bên trong các Card/Container giao diện do AI hoặc Developer tự thiết kế mà không bị lỗi giao diện kép.

### Cách dùng trong PHP template:

```php
<?php
// Định nghĩa mảng JSON các trường muốn tạo
$json_fields = '[
  {"type":"text", "name":"fullname", "label":"Họ và tên", "placeholder":"Nguyễn Văn A", "required":true, "width":"100"},
  {"type":"tel", "name":"phone", "label":"Số điện thoại", "placeholder":"09xx xxx xxx", "required":true, "width":"50"},
  {"type":"email", "name":"email", "label":"Email", "placeholder":"email@domain.com", "required":false, "width":"50"}
]';

// Gọi hàm (Tự động tạo form slug "form-landing-2026" nếu chưa tồn tại và in ra)
if (function_exists('mme_form_auto')) {
    echo mme_form_auto('form-landing-2026', 'Form Landing Page 2026', $json_fields);
}
?>
```

👉 **Prompt bảo AI code PHP Landing Page:**
> "Tôi đang code file PHP template cho WordPress. Hãy dùng hàm PHP `mme_form_auto($slug, $title, $json_fields)` của plugin MME Form để hiển thị form đăng ký. Hãy tự viết mảng `$json_fields` phù hợp với ngữ cảnh trang web này và gọi hàm `echo mme_form_auto(...)` vào đúng vị trí section form."

