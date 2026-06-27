<?php
require_once '../../../wp-load.php';
$post_id = 34762;
$fields = get_post_meta($post_id, '_mme_form_fields', true);
if (is_string($fields)) $fields = json_decode($fields, true);
foreach ($fields as $field) {
    if ($field['name'] === 'careAbout') {
        echo "Options for careAbout:\n";
        var_dump($field['options']);
    }
}
