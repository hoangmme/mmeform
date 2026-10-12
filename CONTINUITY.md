# PROJECT: MME Form | WordPress Lead Generation & Landing Page Form Plugin

- **Strategic Decisions:**
  - Support both Full Layout mode and Form-Only / Fields-Only mode via shortcodes (`[mme_form]`, `[mme_form_fields]`).
  - Added `fields_card_style` ('card' | 'transparent'): Default 'card' mode displays a clean Form Card with custom background (`form_card_bg`, default `#ffffff`), padding, border, and shadow. Solves transparency blending issue when shortcode is embedded in colored/dark page sections.
  - Added `fields_show_heading` ('yes' | 'no') and shortcode attributes `card="yes|no"`, `heading="yes|no"`, `bg="..."`, `label_color="..."`.
  - Added customizable `field_label_color` (default `#1e293b`) and `form_heading_color` (default `#0f172a`) wired to CSS variables (`--mme-field-label-color`, `--mme-form-heading-color`) so field titles can easily be set to bold black or custom colors.
  - In-admin Interactive Live Preview Modal + tab preview with mode toggle (Full vs Form-Only) and background simulator (White, Gray, Dark) for instant visual feedback.

- **Operational Constraints:**
  - Seamless backward compatibility with existing shortcodes `[mme_form id="..."]` and `[mme_form_fields id="..."]`.
  - No external library dependencies for admin live preview modal.

- **Status:**
  - Done: Live preview modal, separate tab previews for full and form-only, copy buttons, white card styling & black field label customization.
  - Current Focus: Ready for user verification.
  - Next: Any additional customization requests.

- **Flags (Drift/Critical/Entropy):** None.
- **Cost/Impact Alerts:** None.

- **Registry & Recovery:**
  - Active Files:
    - `includes/class-mme-form-plugin.php`
    - `includes/class-mme-form-renderer.php`
    - `includes/class-mme-form-admin.php`
    - `assets/public.css`
    - `assets/admin.css`
    - `assets/admin.js`
