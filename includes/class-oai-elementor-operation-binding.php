<?php
/**
 * Stage 2: read-only preflight for an Elementor document operation persisted in
 * the existing Native operation store. This file is NOT wired into the plugin
 * bootstrap, REST, MCP or an executor. It never grants an approval or writes.
 *
 * A successful preflight is NOT authorization to call save_document().
 * Real approval must still be performed by OAI_REST::sandbox_action(), with
 * its existing user, nonce, scoped client, status and audit checks.
 */
if (!defined('ABSPATH')) exit;

final class OAI_Elementor_Operation_Binding {
    public const TYPE = 'elementor_document_save';

    public static function inspect($operation_id, $action, $post_id) {
        $id = absint($operation_id);
        $post = absint($post_id);
        if (!$id || !$post || !in_array($action, array('apply','rollback'), true)) {
            return new WP_Error('oai_binding_arguments', 'Invalid operation, action or post ID', array('status'=>400));
        }
        if (!class_exists('OAI_DB') || !class_exists('OAI_Elementor_Schema_Save')) {
            return new WP_Error('oai_binding_unavailable', 'Required Native components unavailable', array('status'=>503));
        }
        $row = OAI_DB::get($id);
        if (!is_array($row)) {
            return new WP_Error('oai_binding_not_found', 'Operation not found', array('status'=>404));
        }
        if (($row['type'] ?? '') !== self::TYPE || (string)($row['target'] ?? '') !== (string)$post) {
            return new WP_Error('oai_binding_target', 'Operation type or target mismatch', array('status'=>409));
        }
        $expected_states = $action === 'apply' ? array('approved') : array('applied','proved');
        if (!in_array((string)($row['status'] ?? ''), $expected_states, true)) {
            return new WP_Error('oai_binding_state', 'Operation not in a permitted preflight state', array('status'=>409));
        }
        if ($action === 'apply' && !empty($row['expires_at']) &&
            strtotime((string)$row['expires_at']) < time()) {
            return new WP_Error('oai_binding_expired', 'Prepared operation has expired', array('status'=>409));
        }
        if ($action === 'rollback' && empty($row['applied_at'])) {
            return new WP_Error('oai_binding_unapplied', 'Operation has no recorded application', array('status'=>409));
        }
        $meta = self::json_object($row['meta_data'] ?? '');
        $before = self::json_list($row['before_data'] ?? '');
        $after = self::json_list($row['after_data'] ?? '');
        if (!is_array($meta) || !is_array($before) || !is_array($after)) {
            return new WP_Error('oai_binding_snapshot', 'Persisted operation snapshots are malformed', array('status'=>422));
        }
        // A document operation is always bound to the installation and post
        // that created it. A missing installation identity is fail-closed.
        $stored_site = (string)($meta['site_id'] ?? '');
        $current_site = (string)get_option('oai_site_id', '');
        if ($stored_site === '' || $current_site === '' || !hash_equals($stored_site, $current_site)
            || absint($meta['post_id'] ?? 0) !== $post) {
            return new WP_Error('oai_binding_site', 'Operation does not belong to this installation/post', array('status'=>403));
        }
        // A persisted operation identifies its owner, but this alone is not an
        // authorization grant. Never accept actor identity from method arguments.
        // The live WP session must be that owner and must have both administrator
        // privileges and edit rights on the exact target document.
        $owner = absint($meta['owner_user_id'] ?? 0);
        $actor = get_current_user_id();
        if (!$owner || !$actor || $actor !== $owner ||
            !current_user_can('manage_options') || !current_user_can('edit_post', $post)) {
            return new WP_Error('oai_binding_actor', 'Operation owner or edit capability mismatch', array('status'=>403));
        }
        $target = get_post($post);
        if (!$target || in_array((string)$target->post_status, array('trash', 'auto-draft'), true)) {
            return new WP_Error('oai_binding_post', 'Target document is unavailable', array('status'=>404));
        }
        // Approval must be a persisted lifecycle transition, not a caller flag.
        // A check here cannot replace OAI_REST::sandbox_action authorization.
        if (empty($row['operation_id']) || empty($row['approved_at'])) {
            return new WP_Error('oai_binding_approval', 'Operation has no persisted approval record', array('status'=>403));
        }
        $sha_before = (string)($meta['before_sha256'] ?? '');
        $sha_after = (string)($meta['after_sha256'] ?? '');
        if (!self::is_digest($sha_before) || !self::is_digest($sha_after)) {
            return new WP_Error('oai_binding_digest', 'Operation hashes are missing or invalid', array('status'=>422));
        }
        $prepared = OAI_Elementor_Schema_Save::validate_replace($before, $after, $sha_before);
        if (is_wp_error($prepared)) return $prepared;
        if (!hash_equals($prepared['after_sha256'], $sha_after)) {
            return new WP_Error('oai_binding_digest', 'Operation after-image hash mismatch', array('status'=>409));
        }
        $live = OAI_Elementor_Schema_Save::snapshot($post);
        if (is_wp_error($live)) return $live;
        $expected_tree = $action === 'apply' ? $before : $after;
        $expected_sha = $action === 'apply' ? $sha_before : $sha_after;
        if (!OAI_Elementor_Schema_Save::same_tree($live['tree'], $expected_tree) ||
            !hash_equals($live['sha256'], $expected_sha)) {
            return new WP_Error('oai_binding_stale', 'Live document differs from operation snapshot', array('status'=>409));
        }
        return array(
            'id'=>$id, 'post_id'=>$post, 'action'=>$action,
            'status'=>$row['status'], 'site_id'=>$stored_site,
            'before_sha256'=>$sha_before, 'after_sha256'=>$sha_after,
            'prepared_change'=>$prepared,
            // Exact database bytes at the time of preflight, required by the
            // subsequent atomic claim to detect intervening row edits.
            'operation_id'=>(string)$row['operation_id'],
            'row_fingerprints'=>array(
                'before'=>hash('sha256', (string)$row['before_data']),
                'after'=>hash('sha256', (string)$row['after_data']),
                'meta'=>hash('sha256', (string)$row['meta_data']),
            ),
            'preflight_only'=>true,
        );
    }

    /**
     * AI-controlled lifecycle preflight: require a context issued by the existing
     * OAI_AI_Lifecycle_Control::perform() after validating owner + bound MCP client.
     * This is read-only and must be invoked by the actual lifecycle executor,
     * never from a standalone user-controlled route. WordPress admin/human
     * approval uses a separate scoped flow and is deliberately not mimicked.
     */
    public static function inspect_ai_authorized($operation_id, $action, $post_id) {
        if (!class_exists('OAI_AI_Lifecycle_Control') ||
            !OAI_AI_Lifecycle_Control::request_authorized(absint($operation_id), $action)) {
            return new WP_Error('oai_binding_authorization', 'Authorized lifecycle context required', array('status'=>403));
        }
        return self::inspect($operation_id, $action, $post_id);
    }

    private static function json_object($raw) {
        if (!is_string($raw)) return null;
        $v = json_decode($raw, true, 128);
        return json_last_error() === JSON_ERROR_NONE && is_array($v) && !array_is_list($v) ? $v : null;
    }

    private static function json_list($raw) {
        if (!is_string($raw)) return null;
        $v = json_decode($raw, true, 128);
        return json_last_error() === JSON_ERROR_NONE && is_array($v) && array_is_list($v) ? $v : null;
    }

    private static function is_digest($value) {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
