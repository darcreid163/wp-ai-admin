<?php
/**
 * Stage 6 read-only crash/lease recovery assessment.
 * No status transition, rollback, write, resubmission or receipt generation.
 * Must not be registered directly as an MCP/REST endpoint.
 */
if (!defined('ABSPATH')) exit;
final class OAI_Elementor_Document_Recovery {
    public static function inspect_human($id,$post_id) {
        $id=absint($id);$post_id=absint($post_id);
        if (!$id || !$post_id || !current_user_can('manage_options') ||
            !current_user_can('edit_post',$post_id) ||
            !class_exists('OAI_Elementor_Trusted_Planner') ||
            !class_exists('OAI_Elementor_Schema_Save')) {
            return new WP_Error('oai_recovery_permission','Recovery requires a scoped administrator',array('status'=>403));
        }
        $row=OAI_DB::get($id);
        if (!is_array($row) || ($row['type']??'')!==OAI_Elementor_Operation_Binding::TYPE ||
            (string)($row['target']??'')!==(string)$post_id) {
            return new WP_Error('oai_recovery_target','Wrong document or operation',array('status'=>404));
        }
        $meta=json_decode((string)$row['meta_data'],true);
        if (!is_array($meta) || get_current_user_id()!==absint($meta['owner_user_id']??0)) {
            return new WP_Error('oai_recovery_actor','Recovery belongs to another administrator',array('status'=>403));
        }
        $proof=OAI_Elementor_Trusted_Planner::verify_approval_record($row);
        if (is_wp_error($proof)) return $proof;
        $status=(string)$row['status'];
        $terminal=in_array($status,array('applied','rolled_back'),true);
        $receipt=json_decode((string)($row['receipt']??''),true);
        $execution_proof=json_decode((string)($row['proof']??''),true);
        $phase=$status==='rolled_back'?'rollback':'after';
        $expected_event=$status==='rolled_back'?'rollback':'apply';
        $missing_evidence=$terminal && (
            !is_array($receipt) || ($receipt['event']??'')!==$expected_event ||
            ($receipt['status']??'')!==$status ||
            !is_array($execution_proof) ||
            empty($execution_proof['verified']) ||
            ($execution_proof['phase']??'')!==$phase
        );
        if (!$missing_evidence &&
            !in_array($status,array('applying','rolling_back','apply_failed','rollback_failed'),true)) {
            return new WP_Error('oai_recovery_state',
                'Operation has no unresolved execution state or missing terminal evidence',
                array('status'=>409));
        }
        $before=json_decode((string)$row['before_data'],true);
        $after=json_decode((string)$row['after_data'],true);
        $live=OAI_Elementor_Schema_Save::snapshot($post_id);
        if (is_wp_error($live)) return $live;
        $is_before=OAI_Elementor_Schema_Save::same_tree($live['tree'],$before);
        $is_after=OAI_Elementor_Schema_Save::same_tree($live['tree'],$after);
        $action=in_array($status,array('applying','apply_failed','applied'),true)?'apply':'rollback';
        $expected_source=$action==='apply'?'before':'after';
        $result_state=$is_before?'before':($is_after?'after':'other');
        $assessment=$result_state==='other'?'concurrent_or_partial_edit':
            ($action==='apply'?
                ($is_after?'apply_may_have_committed':'apply_not_observed'):
                ($is_before?'rollback_may_have_committed':'rollback_not_observed'));
        return array(
            'id'=>$id, 'post_id'=>$post_id,'status'=>$status,'action'=>$action,
            'document_state'=>$result_state,'assessment'=>$assessment,
            'expected_pre_execution_state'=>$expected_source,
            'live_sha256'=>$live['sha256'],
            'before_sha256'=>OAI_Elementor_Schema_Save::tree_hash($before),
            'after_sha256'=>OAI_Elementor_Schema_Save::tree_hash($after),
            'read_only'=>true,'automatic_retry_allowed'=>false,
            'automatic_rollback_allowed'=>false,
            'terminal_receipt_or_proof_missing'=>$missing_evidence,
            'evidence_incident'=>$missing_evidence?'missing_or_incorrect_terminal_receipt_or_proof':null,
            'human_reconciliation_required'=>true
        );
    }
}
