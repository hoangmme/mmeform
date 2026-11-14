=== MME Form ===
Contributors: mme
Tags: forms, crm, chatbot, webhook, google sheets, twenty
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 0.6.5
License: GPLv2 or later

Compact WordPress form builder with external embeds, URL attribution, MME Chatbot, Google Sheets webhooks, Twenty CRM and Pancake CRM sync, and Zalo phone verification (MMeAuth).

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Activate MME Form.
3. Edit the automatically created form under "Form liên hệ".
4. Use the generated shortcode or external embed script.

== Updates ==

Updates come from github.com/hoangmme/mmeform (branch main) through the normal WordPress update screen. To release: raise "Version" and MME_FORM_VERSION in mme-form.php, add a changelog entry below, push to main.

== Changelog ==

= 0.6.5 =
* Cập nhật theo luồng chuẩn của WordPress (giống MMe Elementor Kit): trang Plugins hiện "Có bản cập nhật", bật được tự động cập nhật, popup "Xem chi tiết" có changelog. Bản mới nhất là header Version trên nhánh main.
* Nút "Kiểm tra cập nhật" dùng Plugin_Upgrader của WordPress: thay cả thư mục plugin (file đã xoá khỏi repo cũng mất), giữ đúng tên thư mục đang cài, plugin vẫn bật sau khi cập nhật.
* Ability MCP mme-form/check-update và mme-form/update-plugin cho trợ lý AI (qua plugin MCP Adapter).
* Header Version chỉ còn số phiên bản (ngày build nằm ở MME_FORM_BUILD_TIME) để WordPress so sánh phiên bản đúng.

= 0.6.4 =
* SEO: tiêu đề form mặc định là h2, đổi được qua filter mme_form_heading_tag.

= 0.6.3 =
* Admin: tiêu đề submission dễ đọc; hiện kênh Zalo Mini App.

= 0.6.2 =
* Giữ nguồn lead từ Mini App của MMeAuth.

= 0.6.1 =
* Nhận lead "Nhận tư vấn" từ Mini App qua cổng MMeAuth.

= 0.6.0 =
* Xác minh SĐT qua Zalo (MMeAuth) thay OTP email; tích hợp chạy sau phản hồi, tự thử lại; trang Tích hợp chung.

= 0.5.8 =
* Webhook: chấp nhận redirect 302 của Google Apps Script, ép IPv4, hiện lỗi chi tiết.

= 0.5.7 =
* Đổi tên form và menu mặc định thành "Form liên hệ".
