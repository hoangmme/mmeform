# MME Editable Template Standard

Tai lieu nay la chuan viet WordPress theme/template de ho tro MME Chatbot sua noi dung an toan qua JSON.

## Kien Truc

```text
Theme PHP render giao dien
Plugin MME Chatbot cung cap helper + validate + page context
chat.mme.vn sua JSON qua GitHub + trigger plugin webhook
```

Theme khong giao quyen sua PHP cho AI trong tac vu content hang ngay. Theme chi chuyen cac vung muon AI sua sang doc tu JSON.

## Cau Truc Bat Buoc

Trong theme tao:

```text
mme-content/
  manifest.json
  content.json
```

Hoac chia theo page:

```text
mme-content/
  manifest.json
  pages/
    home.json
    contact.json
    about.json
```

## Quy Tac Dat Key

Dung pattern:

```text
page.section.field
```

Vi du tot:

```text
home.hero.title
home.hero.description
home.product_range.title
contact.team.headline
contact.team.intro
about.cta.button_url
```

Khong dung key mo ho:

```text
title1
text_2
section_a
abc_text
```

## Helper Trong Template

Plugin cung cap cac helper:

```php
mme_content($path, $fallback = '', $file = '')
mme_text($path, $fallback = '', $file = '')
mme_url($path, $fallback = '', $file = '')
mme_image($path, $fallback = '', $file = '')
mme_html($path, $fallback = '', $file = '')
```

Vi du:

```php
<h1><?php echo mme_text('contact.hero.title', 'Contact APT Rubber'); ?></h1>

<p><?php echo mme_text('contact.hero.description', 'Request quotation and samples.'); ?></p>

<a href="<?php echo mme_url('contact.cta.url', '/contact-us/'); ?>">
  <?php echo mme_text('contact.cta.label', 'Contact us'); ?>
</a>

<img
  src="<?php echo mme_image('contact.hero.image_url', get_template_directory_uri() . '/assets/img/hero.jpg'); ?>"
  alt="<?php echo esc_attr(mme_content('contact.hero.title', 'Hero image')); ?>"
>
```

Neu can doc tu file rieng:

```php
<?php echo mme_text('contact.team.headline', 'Speak With Our Export Team', 'mme-content/pages/contact.json'); ?>
```

## manifest.json

Manifest la ban do de AI biet page nao duoc sua, file JSON nao can sua, section/field nao hop le.

Vi du:

```json
{
  "version": 1,
  "site": "Example Site",
  "default_editable_file": "mme-content/content.json",
  "pages": {
    "contact": {
      "label": "Contact page",
      "url": "/contact-us/",
      "templates": ["template-contact.php"],
      "editable_file": "mme-content/pages/contact.json",
      "sections": {
        "hero": {
          "label": "Hero",
          "fields": {
            "title": "Hero title",
            "description": "Hero description",
            "image_url": "Hero image URL"
          }
        },
        "cta": {
          "label": "Call to action",
          "fields": {
            "label": "Button label",
            "url": "Button URL"
          }
        }
      }
    }
  }
}
```

## contact.json

Neu dung file rieng theo page:

```json
{
  "contact": {
    "hero": {
      "title": "Contact APT Rubber",
      "description": "Request SVR pricing, samples, and technical documents.",
      "image_url": "/wp-content/uploads/contact-hero.jpg"
    },
    "cta": {
      "label": "Request quotation",
      "url": "/contact-us/"
    }
  }
}
```

## Quy Tac Voi ACF

Mac dinh:

```text
ACF fields = khach tu sua trong WordPress Admin
Static template text/link/image/video = MME JSON
```

Neu template da co ACF field:

```php
$headline = get_field('team_headline');
```

thi khong can dua field do vao JSON, tru khi ban muon AI cung co quyen cap nhat ACF qua sync script.

Neu khong co ACF field va text dang hardcode:

```php
<h2>Speak With Our Export Team</h2>
```

hay chuyen thanh:

```php
<h2><?php echo mme_text('contact.team.headline', 'Speak With Our Export Team'); ?></h2>
```

## Template Header Khuyen Dung

Moi template AI editable nen co comment ro:

```php
<?php
/**
 * Template Name: Contact Page
 * MME Editable Page: contact
 * MME Content File: mme-content/pages/contact.json
 */
```

## Plugin Lam Gi

Plugin MME Chatbot:

- Cung cap helper `mme_text`, `mme_url`, `mme_image`, `mme_html`.
- Validate `mme-content/manifest.json`.
- Hien thi danh sach page ho tro AI edit trong Settings > MME Chatbot.
- Tao REST webhook de sync `mme-content/*.json` tu GitHub ve theme production.
- Chay `mme-content/sync-to-wp.php` sau khi sync neu theme co script nay.
- Gui current page context sang `chat.mme.vn`, gom:
  - URL hien tai
  - post_id
  - post_type
  - template_file
  - theme
  - ACF fields detected
  - manifest_exists

## Chat.mme.vn Lam Gi

`chat.mme.vn`:

- Dung DeepSeek de hieu yeu cau admin.
- Doc `manifest.json`.
- Sua dung file JSON trong `mme-content/`.
- Push commit len GitHub.
- Trigger Deploy Webhook URL cua plugin website neu duoc cau hinh.

## Content Sync Webhook

Trong WordPress Admin cua tung website:

```text
Settings > MME Chatbot > Content Sync Webhook
```

Cau hinh:

```text
GitHub Repo: owner/repo cua theme
GitHub Branch: main
GitHub Token: token co Contents: Read neu repo private
Sync Webhook Secret: chuoi bi mat dai
```

Sau khi Save, plugin hien `Deploy Webhook URL`. Copy URL nay sang admin `chat.mme.vn` trong truong deploy webhook cua chatbot/tenant.

Flow production:

```text
chat.mme.vn push JSON len GitHub
chat.mme.vn POST Deploy Webhook URL
Plugin tai lai cac file mme-content/*.json va mme-content/pages/*.json
Plugin ghi file vao theme production
Plugin include mme-content/sync-to-wp.php neu co
```

Plugin chi sync file `.json` trong `mme-content/`. Plugin khong tai PHP tu GitHub trong webhook de giam rui ro.

Neu website khong cap nhat sau khi webhook bao OK, kiem tra:

```text
1. Template co dang doc text/link/anh bang mme_text/mme_url/mme_image khong.
2. JSON tren VPS da thay doi chua.
3. Neu field la ACF, sync-to-wp.php da map field do chua.
4. Cache website/CDN co can clear khong.
```

Neu webhook bao loi write permission, cap quyen ghi cho:

```text
wp-content/themes/{theme-name}/mme-content
```

## Checklist Khi Viet Theme Moi

1. Tao `mme-content/manifest.json`.
2. Tao `mme-content/content.json` hoac `mme-content/pages/{page}.json`.
3. Dat key theo chuan `page.section.field`.
4. Template dung `mme_text`, `mme_url`, `mme_image`, `mme_html`.
5. Chi dua field can AI sua vao JSON.
6. ACF field nao khach tu sua duoc thi giu trong ACF.
7. Vao Settings > MME Chatbot de kiem tra manifest/page status.
8. Trong chat.mme.vn, set `Allowed editable paths` la:

```text
mme-content/
```

## Nguyen Tac An Toan

- AI khong sua PHP cho content thuong ngay.
- AI chi sua JSON nam trong `mme-content/`.
- Moi JSON field phai co fallback trong PHP.
- Moi page editable phai co manifest.
- Moi thay doi nen di qua GitHub commit de rollback duoc.
