<?php
/**
 * End-to-end Lifecycle Control reconnect test inside disposable WordPress.
 * Invoked by tests/wordpress-native-smoke.sh through WP-CLI eval-file.
 */
if (!defined('ABSPATH') || !class_exists('OAI_AI_Lifecycle_Control') || !class_exists('OAI_REST')) {
    throw new RuntimeException('WP AI Admin lifecycle classes are not loaded');
}

$admin = get_user_by('login', 'ci-admin');
if (!$admin || !user_can($admin, 'manage_options')) {
    throw new RuntimeException('Disposable WordPress administrator is unavailable');
}
wp_set_current_user((int) $admin->ID);

$post_id = wp_insert_post(array(
    'post_type' => 'page',
    'post_status' => 'draft',
    'post_title' => 'Lifecycle principal before',
));
if (is_wp_error($post_id) || !$post_id) {
    throw new RuntimeException('Could not create disposable lifecycle fixture page');
}

try {
    $request = new WP_REST_Request('POST', '/okean-ai/v1/sandbox');
    $request->set_param('type', 'post_title');
    $request->set_param('target', (string) $post_id);
    $request->set_param('value', 'Lifecycle principal after');
    $operation = OAI_REST::sandbox_create($request);
    if (is_wp_error($operation) || empty($operation['id'])) {
        throw new RuntimeException('Could not create sandbox operation: ' . (is_wp_error($operation) ? $operation->get_error_code() : 'missing_id'));
    }

    $enabled = OAI_AI_Lifecycle_Control::enable((int) $admin->ID);
    if (is_wp_error($enabled) || empty($enabled['active'])) {
        throw new RuntimeException('Could not enable disposable AI Lifecycle Control');
    }

    $approved = OAI_AI_Lifecycle_Control::perform((int) $operation['id'], 'approve', 'gateway_pairing_first', (int) $admin->ID);
    if (is_wp_error($approved)) {
        throw new RuntimeException('Approve failed: ' . $approved->get_error_code());
    }

    $applied = OAI_AI_Lifecycle_Control::perform((int) $operation['id'], 'apply', 'gateway_pairing_rotated', (int) $admin->ID);
    if (is_wp_error($applied)) {
        throw new RuntimeException('Apply after pairing rotation failed: ' . $applied->get_error_code());
    }
    if (get_post_field('post_title', $post_id) !== 'Lifecycle principal after') {
        throw new RuntimeException('Apply did not persist the approved title');
    }

    $rolled_back = OAI_AI_Lifecycle_Control::perform((int) $operation['id'], 'rollback', 'gateway_pairing_rotated_again', (int) $admin->ID);
    if (is_wp_error($rolled_back)) {
        throw new RuntimeException('Rollback after pairing rotation failed: ' . $rolled_back->get_error_code());
    }
    if (get_post_field('post_title', $post_id) !== 'Lifecycle principal before') {
        throw new RuntimeException('Rollback did not restore the original title');
    }

    echo "PASS: real WordPress sandbox approve/apply/rollback survives MCP pairing identity rotation\n";
} finally {
    OAI_AI_Lifecycle_Control::disable((int) $admin->ID, 'ci_cleanup');
    wp_delete_post($post_id, true);
}
