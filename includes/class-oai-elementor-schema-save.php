<?php
/**
 * Elementor schema/save integration boundary.
 *
 * This is an original implementation using the existing OAI_Elementor API.
 * Uses the existing native Elementor APIs without external code.
 * Stage 1 is deliberately NOT registered as an MCP or REST write route.
 */
if (!defined('ABSPATH')) exit;

final class OAI_Elementor_Schema_Save {
    /**
     * Stable Elementor document digest: associative JSON object key order is
     * irrelevant, while array order (page sections/widgets) and value types
     * are significant. The original tree is never rewritten.
     */
    private static function canonical($value) {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) {
            return array_map(array(__CLASS__, 'canonical'), $value);
        }
        $out = array();
        foreach ($value as $key=>$child) $out[$key] = self::canonical($child);
        ksort($out, SORT_STRING);
        return $out;
    }

    public static function tree_hash($tree) {
        return hash('sha256', wp_json_encode(self::canonical($tree), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function same_tree($left, $right) {
        return is_array($left) && is_array($right) &&
            hash_equals(self::tree_hash($left), self::tree_hash($right));
    }

    /**
     * Authoritative single-row resolver for persisted Elementor page content.
     * Guard, Verify and crash readback must not pick different duplicate rows.
     * This method reads MySQL directly, not the WordPress object/meta cache.
     * A duplicate, missing, malformed or non-list document fails closed.
     *
     * This is a read-only resolver; it DOES NOT serialize concurrent writers.
     */
    public static function canonical_meta_row($post_id) {
        global $wpdb;
        $post_id=absint($post_id);
        if (!$post_id || !isset($wpdb) || !isset($wpdb->postmeta) ||
            !method_exists($wpdb,'prepare') || !method_exists($wpdb,'get_results')) {
            return new WP_Error('oai_elementor_meta_unavailable',
                'Persisted Elementor document cannot be checked',array('status'=>503));
        }
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC LIMIT 2",
            $post_id,'_elementor_data'
        ),ARRAY_A);
        if (!is_array($rows) || count($rows)!==1 ||
            !isset($rows[0]['meta_id']) ||
            !is_string($rows[0]['meta_value']??null)) {
            return new WP_Error('oai_elementor_meta_ambiguous',
                'Elementor document metadata is missing or duplicated',
                array('status'=>409));
        }
        $tree=json_decode($rows[0]['meta_value'],true);
        if (!is_array($tree) || !array_is_list($tree) || json_last_error()!==JSON_ERROR_NONE) {
            return new WP_Error('oai_elementor_meta_invalid',
                'Persisted Elementor document is malformed',array('status'=>409));
        }
        return array('post_id'=>$post_id,'meta_id'=>absint($rows[0]['meta_id']),
            'tree'=>$tree,'sha256'=>self::tree_hash($tree));
    }

    public static function schema($widget_type) {
        if (!class_exists('OAI_Elementor')) {
            return new WP_Error('oai_elementor_missing', 'Elementor adapter is unavailable', array('status'=>503));
        }
        return OAI_Elementor::widget_schema($widget_type);
    }

    /** Read-only snapshot for a future approved replace; avoids lossy serialization. */
    public static function snapshot($post_id) {
        $id = absint($post_id);
        if (!$id) return new WP_Error('oai_invalid_post', 'A post ID is required', array('status'=>400));
        $tree = OAI_Elementor::get_data($id);
        if (is_wp_error($tree)) return $tree;
        return array('post_id'=>$id, 'tree'=>$tree,
            'sha256'=>self::tree_hash($tree));
    }

    public static function validate_replace($before, $after, $expected_sha256) {
        if (!is_array($before) || !is_array($after) || !array_is_list($after)) {
            return new WP_Error('oai_invalid_tree', 'A list of Elementor nodes is required', array('status'=>422));
        }
        $hash = self::tree_hash($before);
        if (!is_string($expected_sha256) ||
            !preg_match('/^[a-f0-9]{64}$/D', $expected_sha256) ||
            !hash_equals($hash, $expected_sha256)) {
            return new WP_Error('oai_snapshot_mismatch', 'Snapshot hash mismatch', array('status'=>409));
        }
        $decoded = OAI_Elementor::decode_create_nodes($after);
        if (is_wp_error($decoded)) return $decoded;
        if (self::same_tree($decoded, $before)) {
            return new WP_Error('oai_noop', 'No content change', array('status'=>409));
        }
        return array('before'=>$before, 'after'=>$decoded, 'before_sha256'=>$hash,
            'after_sha256'=>self::tree_hash($decoded));
    }

    /**
     * Apply only after a separate authority has validated a human-approved operation.
     * The caller MUST supply approved=true from the existing Approval Engine.
     * This method does not grant approvals or expose any write endpoints.
     */
    public static function apply_approved($post_id, $change, $approved=false) {
        if ($approved !== true) {
            return new WP_Error('oai_approval_required', 'Human approval is required', array('status'=>403));
        }
        if (!is_array($change) || !isset($change['before'], $change['after'], $change['before_sha256'], $change['after_sha256'])
            || !is_array($change['before']) || !is_array($change['after'])
            || !is_string($change['before_sha256']) || !is_string($change['after_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $change['before_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $change['after_sha256'])) {
            return new WP_Error('oai_invalid_change', 'Missing or malformed prepared change', array('status'=>400));
        }
        $rehash_before = self::tree_hash($change['before']);
        $rehash_after = self::tree_hash($change['after']);
        if (!hash_equals($rehash_before, $change['before_sha256']) ||
            !hash_equals($rehash_after, $change['after_sha256'])) {
            return new WP_Error('oai_change_integrity', 'Prepared change hash mismatch', array('status'=>409));
        }
        $snapshot = self::snapshot($post_id);
        if (is_wp_error($snapshot)) return $snapshot;
        if (!hash_equals($snapshot['sha256'], (string)$change['before_sha256']) ||
            !self::same_tree($snapshot['tree'], $change['before'])) {
            return new WP_Error('oai_stale_target', 'Elementor document changed before apply', array('status'=>409));
        }
        $saved = OAI_Elementor::save_document(absint($post_id), $change['after']);
        if (is_wp_error($saved)) return $saved;
        $verified = self::verify($post_id, $change['after']);
        if (is_wp_error($verified)) return $verified;
        if (empty($verified['ok'])) {
            return new WP_Error('oai_save_verify', 'Saved document differs from approved snapshot', array('status'=>409));
        }
        return $saved;
    }

    /** Full comparison, not only the existence of valid Elementor JSON. */
    public static function verify($post_id, $expected_tree) {
        $snapshot = self::snapshot($post_id);
        if (is_wp_error($snapshot)) return $snapshot;
        $match = self::same_tree($snapshot['tree'], $expected_tree);
        return array('ok'=>$match, 'post_id'=>$snapshot['post_id'],
            'actual_sha256'=>$snapshot['sha256'],
            'expected_sha256'=>is_array($expected_tree) ? self::tree_hash($expected_tree) : null);
    }

    /** Rollback requires a separately approved rollback and no intervening edits. */
    public static function rollback_approved($post_id, $change, $approved=false) {
        if ($approved !== true) {
            return new WP_Error('oai_approval_required', 'Rollback approval required', array('status'=>403));
        }
        if (!is_array($change) || !isset($change['before'], $change['after'], $change['before_sha256'], $change['after_sha256'])
            || !is_array($change['before']) || !is_array($change['after'])
            || !is_string($change['before_sha256']) || !is_string($change['after_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $change['before_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $change['after_sha256'])) {
            return new WP_Error('oai_invalid_change', 'Missing or malformed rollback snapshots', array('status'=>400));
        }
        if (!hash_equals(self::tree_hash($change['before']), $change['before_sha256']) ||
            !hash_equals(self::tree_hash($change['after']), $change['after_sha256'])) {
            return new WP_Error('oai_change_integrity', 'Rollback change hashes do not match', array('status'=>409));
        }
        // These hashes alone do NOT prove origin: the real executor must load
        // immutable before/after snapshots by approved operation ID from OAI_DB.
        $snapshot = self::snapshot($post_id);
        if (is_wp_error($snapshot)) return $snapshot;
        if (!self::same_tree($snapshot['tree'], $change['after']) ||
            !hash_equals($snapshot['sha256'], $change['after_sha256'])) {
            return new WP_Error('oai_rollback_conflict', 'Target changed since apply', array('status'=>409));
        }
        $restored = OAI_Elementor::save_document(absint($post_id), $change['before']);
        if (is_wp_error($restored)) return $restored;
        $verified = self::verify($post_id, $change['before']);
        if (is_wp_error($verified)) return $verified;
        if (empty($verified['ok'])) {
            return new WP_Error('oai_rollback_verify', 'Restored document differs from original snapshot', array('status'=>409));
        }
        return $restored;
    }
}
