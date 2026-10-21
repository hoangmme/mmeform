# PROJECT: MME Form | WordPress Lead Generation & Landing Page Form Plugin

- **Strategic Decisions:**
  - Support both Full Layout mode and Form-Only / Fields-Only mode via shortcodes (`[mme_form]`, `[mme_form_fields]`).
  - Added `fields_card_style` ('card' | 'transparent'): Default 'card' mode displays a clean Form Card with custom background (`form_card_bg`, default `#ffffff`), padding, border, and shadow. Solves transparency blending issue when shortcode is embedded in colored/dark page sections.
  - Added `fields_show_heading` ('yes' | 'no') and shortcode attributes `card="yes|no"`, `heading="yes|no"`, `bg="..."`, `field_bg="..."`, `label_color="..."`, `placeholder_color="..."`.
  - Added customizable `field_label_color` (default `#1e293b`), `field_bg_color` (default `#f8fafc`), `field_placeholder_color` (default `#94a3b8`), and `form_heading_color` (default `#0f172a`) wired to CSS variables (`--mme-field-label-color`, `--mme-field-bg`, `--mme-field-placeholder`, `--mme-form-heading-color`) for comprehensive typography and background customization of form cards, inputs, labels, and placeholders.
  - In-admin Interactive Live Preview Modal + tab preview with mode toggle (Full vs Form-Only) and background simulator (White, Gray, Dark) for instant visual feedback.
  - Native Pancake CRM Integration: Direct sync to Pancake CRM (supports both modern `crm.pancake.vn` v2 and legacy `pos.pages.fm` v1 fallback) with custom fields, attribution, and note compilation.
  - 1-Click GitHub In-Place Updater (WPMME Architecture): Ported native WordPress filesystem updater from `/Users/hoji/Documents/code/mmewp/wpmme`. Uses `WP_Filesystem()`, `download_url()`, `unzip_file()`, and `copy_dir()` to download the latest `main.zip` from GitHub and overwrite `MME_FORM_DIR` in-place. Eliminates duplicate plugin folders (`mmeform-main`) and removes need for SSH/terminal `git pull`.
  - Email OTP Verification System (Anti-Spam): Added 6-digit numeric OTP verification on email fields via `wp_mail()` (100% native compatibility with FluentSMTP). Configurable subject template, expiration (minutes), and request cooldown (seconds) backed by transient storage. Blocks submissions if OTP is missing, expired, or incorrect when enabled.
  - Admin UI Redesign & Tabbed Architecture: Split appearance & configuration box into 4 clean tabs (Tab 1: Shortcode Chỉ Form, Tab 2: Form Thường 2 Cột, Tab 3: Phông chữ & Nâng cao, Tab 4: Xác thực OTP Email).
  - Tab 1 (Chỉ Form) UX Clarity: Directly unified Form Heading (show/hide, text, color), Form Card wrapper settings (with auto-hide on transparent mode), Field Inputs styling (Field background, border, title/label color, placeholder color, text color), Submit Button styling, Success Message (`success_message`), and Footer text (`form_footer`) right into Tab 1.
  - Submissions Quick View Modal: Added interactive Quick View button on the Submissions list table (`edit.php?post_type=mme_form_submission`) and row actions. Displays customer contact, all submitted form fields mapped to human-readable labels, source & UTM tracking, integration statuses (Sheets, Pancake, Twenty), and raw JSON debug in a modal without navigating away from the table.
  - Borderless Phone Country Code (intl-tel-input): Completely reset borders, outlines, box-shadows, and background on `.iti__selected-country` and `.iti__country-container` to remove the inner grey border around the flag.
  - Fixed settings persistence: Ensured `field_placeholder_color` and OTP settings are properly sanitized and persisted in `save_form()`.

- **Operational Constraints:**
  - Seamless backward compatibility with existing shortcodes `[mme_form id="..."]` and `[mme_form_fields id="..."]`.
  - No external library dependencies for admin live preview modal, Quick View modal, or tab switching.
  - Backward compatibility with Google Sheets Webhook and Twenty CRM.
  - FluentSMTP / WP Mail compatibility: uses native `wp_mail()`, requiring no separate SMTP credential setup.
  - Resilient Git detection with multi-tier fallback (exec git log -> file read .git/HEAD -> filemtime -> MME_FORM_BUILD_TIME constant).
  - Updater runs with WordPress admin privileges (`manage_options`) and nonce verification (`mme_form_admin_nonce`).

- **Status:**
  - Done: Live preview modal, separate tab previews for full and form-only, copy buttons, white card styling & black field label customization.
  - Done: Fix plugin fatal error on activation by guarding 33+ functions and constants against redeclaration collisions with mmeai-chatbot and themes, and guarding get_current_screen() and is_plugin_active() across all entry points.
  - Done: Added MME_FORM_BOOTSTRAPPED singleton guard and class_exists wrappers on all classes to prevent fatal errors when multiple plugin folders coexist on the server.
  - Done: Added native Pancake CRM integration in MME Form (settings, admin metabox, and submission pipeline with v2/v1 dual fallback).
  - Done: Added dynamic version, date/time, and git commit display across WordPress admin (Plugins list, Admin notices, Edit screen sidebar, Footer text).
  - Done: 1-Click GitHub Updater mechanism (`MME_Form_Updater`, UI buttons, and client-side AJAX handler) modeled after `wpmme`.
  - Done: Email OTP verification for spam protection (endpoint `send-otp`, transient keys, cooldown timer, frontend countdown, submission validation).
  - Done: Reorganized Admin Tab 1 (Chỉ Form) with direct controls for Form Title, Field Background, Field Title/Label color, Placeholder color, Success Message, Footer, and responsive Card wrapper hiding.
  - Done: Borderless flag styling on intl-tel-input phone fields.
  - Done: Submissions Quick View Modal with customer contact, mapped fields, UTM parameters, and integration results.
  - Current Focus: Commit & push version 0.5.6 to origin main.
  - Next: User can update directly via 1-click update button in WordPress Admin and test Quick View on submissions.

- **Flags (Drift/Critical/Entropy):** None.
- **Cost/Impact Alerts:** None.

- **Registry & Recovery:**
  - Active Files:
    - `mme-form.php`
    - `includes/class-mme-form-updater.php`
    - `includes/class-mme-form-admin.php`
    - `includes/class-mme-form-plugin.php`
    - `includes/class-mme-form-renderer.php`
    - `includes/class-mme-form-submissions.php`
    - `includes/mme-chatbot.php`
    - `assets/public.css`
    - `assets/public.js`
    - `assets/admin.css`
    - `assets/admin.js`
    - `CONTINUITY.md`

