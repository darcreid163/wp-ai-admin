<?php
/**
 * Stage-3 internal-only database compare-and-swap claim for an Elementor
 * document operation. NOT loaded at runtime, registered in REST or MCP.
 *
 * Claiming changes ONLY the operation row status. It does NOT perform an
 * Elementor save, verify a human approval ceremony, or produce a receipt.
 * Those remain responsibilities of the existing authoritative lifecycle.
 */
if (!defined('ABSPATH')) exit;

final class OAI_Elementor_Operation_Lease {
    public static function claim_ai($operation_id, $action, $post_id) {
        if (!class_exists('OAI_Elementor_Operation_Binding')) {
            return new WP_Error('oai_lease_unavailable', 'Operation binding is unavailable', array('status'=>503));
        }
        $checked = OAI_Elementor_Operation_Binding::inspect_ai_authorized($operation_id, $action, $post_id);
        if (is_wp_error($checked)) return $checked;
        return self::atomic_claim($checked);
    }

    /**
     * WordPress administrator pathway. The nonce and WordPress capabilities
     * must be validated again by the eventual REST callback. This method
     * cannot stand in for the already persisted operation approval.
     */
    public static function claim_human($operation_id, $action, $post_id, $nonce) {
        if (!is_string($nonce) || $nonce === '' ||
            !function_exists('wp_verify_nonce') || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('oai_lease_nonce', 'Human REST nonce required', array('status'=>403));
        }
        if (!current_user_can('manage_options') || !current_user_can('edit_post', absint($post_id))) {
            return new WP_Error('oai_lease_permission', 'Administrator edit permissions required', array('status'=>403));
        }
        if (!class_exists('OAI_Elementor_Operation_Binding')) {
            return new WP_Error('oai_lease_unavailable', 'Operation binding is unavailable', array('status'=>503));
        }
        $checked = OAI_Elementor_Operation_Binding::inspect($operation_id, $action, $post_id);
        if (is_wp_error($checked)) return $checked;
        return self::atomic_claim($checked);
    }

    /** One statement wins; a replay/race is a failure, never a second write. */
    private static function atomic_claim($checked) {
        // Refuse synthetic/forged approved rows: only a server-derived pending
        // operation may transition through the existing real approval route.
        if (!class_exists('OAI_Elementor_Trusted_Planner')) {
            return new WP_Error('oai_lease_planner_missing','Trusted operation planner unavailable',array('status'=>503));
        }
        $persisted=OAI_DB::get(absint($checked['id'] ?? 0));
        $provenance=OAI_Elementor_Trusted_Planner::verify_record($persisted);
        if (is_wp_error($provenance)) return $provenance;
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'prepare') ||
            !method_exists($wpdb, 'query') || !class_exists('OAI_DB')) {
            return new WP_Error('oai_lease_db', 'Database is unavailable', array('status'=>503));
        }
        $action = (string)($checked['action'] ?? '');
        if (!in_array($action, array('apply','rollback'), true) ||
            empty($checked['preflight_only']) || !isset($checked['row_fingerprints']) ||
            !isset($checked['operation_id']) || !isset($checked['id'], $checked['post_id'], $checked['status'])) {
            return new WP_Error('oai_lease_preflight', 'Invalid operation preflight', array('status'=>400));
        }
        $f = $checked['row_fingerprints'];
        if (!is_array($f) || !isset($f['before'],$f['after'],$f['meta'])) {
            return new WP_Error('oai_lease_preflight', 'Missing snapshot fingerprints', array('status'=>400));
        }
        foreach (array('before','after','meta') as $key) {
            if (!is_string($f[$key]) || preg_match('/^[a-f0-9]{64}$/D',$f[$key])!==1) {
                return new WP_Error('oai_lease_preflight', 'Malformed snapshot fingerprint', array('status'=>400));
            }
        }

        $new_state = $action === 'apply' ? 'applying' : 'rolling_back';
        $table = OAI_DB::table();
        // Hashing the stored LONGTEXT in the UPDATE guards against alteration
        // between PHP preflight and the actual database state transition.
        $sql = "UPDATE {$table} SET status=%s, phase=%s
            WHERE id=%d AND status=%s AND type=%s AND target=%s
              AND operation_id=%s
              AND SHA2(before_data,256)=%s AND SHA2(after_data,256)=%s
              AND SHA2(meta_data,256)=%s AND approved_at IS NOT NULL";
        if ($action === 'apply') {
            $sql .= " AND (expires_at IS NULL OR expires_at >= UTC_TIMESTAMP())";
        } else {
            $sql .= " AND applied_at IS NOT NULL";
        }
        $statement = $wpdb->prepare($sql, array(
            $new_state,$new_state,(int)$checked['id'],(string)$checked['status'],
            OAI_Elementor_Operation_Binding::TYPE,(string)$checked['post_id'],
            (string)$checked['operation_id'],$f['before'],$f['after'],$f['meta']
        ));
        $rows = $wpdb->query($statement);
        if ($rows === false) {
            return new WP_Error('oai_lease_db', 'Atomic operation claim failed', array('status'=>500));
        }
        if ((int)$rows !== 1) {
            return new WP_Error('oai_lease_conflict',
                'Operation was changed, expired, or already claimed; no write allowed', array('status'=>409));
        }
        return array(
            'claimed'=>true, 'id'=>(int)$checked['id'],
            'post_id'=>(int)$checked['post_id'],
            'action'=>$action, 'status'=>$new_state,
            'prepared_change'=>$checked['prepared_change'],
            'write_executed'=>false,
        );
    }
}
