<?php
/**
 * Stage-only document pre-save safety gate, not registered as a runtime route.
 *
 * A WordPress edit lock and independent MySQL read offer an *additional*
 * fail-closed signal. They do not serialize arbitrary external writers, and
 * do NOT resolve F3's post-check/pre-save race. See Issue #13.
 */
if (!defined('ABSPATH')) exit;
final class OAI_Elementor_External_Edit_Guard {
    public static function inspect($post_id, $expected_tree) {
        $post_id=absint($post_id);
        $actor=get_current_user_id();
        if (!$post_id || !$actor || !is_array($expected_tree) ||
            !current_user_can('manage_options') ||
            !current_user_can('edit_post',$post_id)) {
            return new WP_Error('oai_external_guard_permission',
                'Administrator ownership and document permissions required',array('status'=>403));
        }
        if (!class_exists('OAI_Elementor_Schema_Save')) {
            return new WP_Error('oai_external_guard_unavailable',
                'Document comparison helper unavailable',array('status'=>503));
        }
        $post=get_post($post_id);
        if (!$post || $post->post_type!=='page' ||
            in_array((string)$post->post_status,array('trash','auto-draft','inherit'),true) ||
            (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id))) {
            return new WP_Error('oai_external_guard_target',
                'Only supported Elementor pages may be written',array('status'=>409));
        }

        // Honor standard WordPress/Elementor human edit-lock signalling.
        // A cooperative edit lock is NOT a database exclusion lock.
        $edit_lock=(string)get_post_meta($post_id,'_edit_lock',true);
        if ($edit_lock!=='') {
            $parts=explode(':',$edit_lock,2);
            $lock_time=isset($parts[0]) && ctype_digit((string)$parts[0]) ?
                (int)$parts[0] : 0;
            $lock_owner=isset($parts[1]) && ctype_digit((string)$parts[1]) ?
                (int)$parts[1] : 0;
            $lock_window=(int)apply_filters('wp_check_post_lock_window',150);
            // If an unrecognized active lock has no valid owner, fail closed.
            if ($lock_time>0 && $lock_time>time()-max(1,$lock_window) &&
                (!$lock_owner || $lock_owner!==$actor)) {
                return new WP_Error('oai_external_editor_active',
                    'Another editor has an active WordPress document lock',array('status'=>409));
            }
        }

        // One resolver for both the guard and final verification. A duplicate
        // _elementor_data row must never be accepted by only one component.
        $persisted=OAI_Elementor_Schema_Save::canonical_meta_row($post_id);
        if (is_wp_error($persisted)) return $persisted;
        if (!OAI_Elementor_Schema_Save::same_tree($persisted['tree'],$expected_tree)) {
            return new WP_Error('oai_external_guard_stale',
                'Persistent Elementor document differs from approved original',
                array('status'=>409));
        }
        $snapshot=OAI_Elementor_Schema_Save::snapshot($post_id);
        if (is_wp_error($snapshot)) return $snapshot;
        if (!OAI_Elementor_Schema_Save::same_tree($snapshot['tree'],$expected_tree)) {
            return new WP_Error('oai_external_guard_stale',
                'Elementor API document differs from approved original',array('status'=>409));
        }
        return array('safe_preflight_only'=>true,
            'post_id'=>$post_id,'actor'=>$actor,
            'document_sha256'=>OAI_Elementor_Schema_Save::tree_hash($expected_tree),
            'meta_id'=>$persisted['meta_id'],
            'writer_exclusion_guaranteed'=>false);
    }
}
