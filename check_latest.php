<?php
require_once '../../../wp-load.php';
$posts = get_posts([
    'post_type' => 'mme_form_submission',
    'posts_per_page' => 1,
    'orderby' => 'date',
    'order' => 'DESC'
]);

if ($posts) {
    $post_id = $posts[0]->ID;
    $integrations = get_post_meta($post_id, '_mme_submission_integrations', true);
    
    echo "ID: $post_id\n";
    echo "Integrations:\n";
    print_r($integrations);
} else {
    echo "No submissions found.";
}
