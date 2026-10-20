<?php
/**
 * Stage 5: unregistered human-authorized Elementor document executor.
 * NEVER expose this class directly as an MCP/REST route. Existing Native
 * approval and site/actor guards must remain the sole authority.
 */
if (!defined('ABSPATH')) exit;
final class OAI_Elementor_Document_Executor {
    public static function apply_human($id,$post_id,$nonce) {
        return self::execute('apply',$id,$post_id,$nonce);
    }
    public static function rollback_human($id,$post_id,$nonce) {
        return self::execute('rollback',$id,$post_id,$nonce);
    }

    private static function execute($action,$id,$post_id,$nonce) {
        if (!class_exists('OAI_Elementor_Operation_Lease') ||
            !class_exists('OAI_Elementor_Trusted_Planner') ||
            !class_exists('OAI_Verify') || !class_exists('OAI_Receipts') ||
            !class_exists('OAI_Proof')) {
            return new WP_Error('oai_document_executor_unavailable','Internal executor dependencies unavailable',array('status'=>503));
        }
        $id=absint($id);$post_id=absint($post_id);
        // Claim performs actual nonce, actor, post, approved-audit, planner-
        // signature, snapshot and DB-atomic transition validation.
        $claim=OAI_Elementor_Operation_Lease::claim_human($id,$action,$post_id,$nonce);
        if (is_wp_error($claim)) return $claim;
        $expected_status=$action==='apply'?'applying':'rolling_back';
        $row=OAI_DB::get($id);
        if (!is_array($row) || (string)$row['status']!==$expected_status ||
            (string)$row['target']!==(string)$post_id) {
            return new WP_Error('oai_document_claim_lost','Claimed operation changed unexpectedly',array('status'=>409));
        }
        $origin=OAI_Elementor_Trusted_Planner::verify_approval_record($row);
        if (is_wp_error($origin)) return $origin;
        $before=json_decode((string)$row['before_data'],true);
        $after=json_decode((string)$row['after_data'],true);
        if (!is_array($before)||!is_array($after)) {
            return new WP_Error('oai_document_snapshot_invalid','Stored document snapshots unavailable',array('status'=>409));
        }
        // Rollback must compare against *actually applied* and verified DB
        // state. Caller-supplied before/after snapshots are never accepted.
        if ($action==='rollback') {
            $applied=json_decode((string)($row['applied_data']??''),true);
            if (!is_array($applied) ||
                !OAI_Elementor_Schema_Save::same_tree($applied,$after)) {
                return new WP_Error('oai_document_applied_missing','Verified applied state is missing',array('status'=>409));
            }
        }
        $expected_live=$action==='apply'?$before:$after;
        $target_tree=$action==='apply'?$after:$before;
        $live=OAI_Elementor_Schema_Save::snapshot($post_id);
        if (is_wp_error($live)) return $live;
        if (!OAI_Elementor_Schema_Save::same_tree($live['tree'],$expected_live)) {
            // Never overwrite a concurrent editor change, even after CAS.
            self::fail($id,$action,'stale_document');
            return new WP_Error('oai_document_conflict','Document changed after atomic claim',array('status'=>409));
        }

        // A second pre-write guard honors an active human WordPress edit lock
        // and compares Elementor API to independent MySQL persistence. This is
        // NOT an atomic lock against arbitrary other editors (Issue #13).
        if (!class_exists('OAI_Elementor_External_Edit_Guard')) {
            self::fail($id,$action,'external_guard_unavailable');
            return new WP_Error('oai_external_guard_unavailable',
                'Document edit-safety guard is unavailable',array('status'=>503));
        }
        $guard=OAI_Elementor_External_Edit_Guard::inspect($post_id,$expected_live);
        if (is_wp_error($guard)) {
            self::fail($id,$action,'external_edit_preflight_refused');
            return $guard;
        }
        // From this point Elementor may already commit the document, even if
        // a later PHP exception, timeout or verification error occurs.
        // NEVER report "apply_failed" as if no write happened in this window.
        $write_attempted=false;
        try {
            $write_attempted=true;
            $saved=OAI_Elementor::save_document($post_id,$target_tree);
            if (is_wp_error($saved)) {
                self::uncertain($id,$action,'save_result_uncertain');
                return new WP_Error('oai_document_write_uncertain',
                    'Elementor reported a save error; document state requires inspection',array('status'=>500));
            }
            // Record the intended persisted image immediately after the API
            // returned success, before any independent verification/proof.
            // A crash here remains "applying/rolling_back" and is diagnosed,
            // not retried automatically.
            if ($action==='apply' && !self::transition($id,'applying',array(
                'applied_data'=>wp_json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),
                'applied_at'=>current_time('mysql',true)))) {
                self::uncertain($id,$action,'applied_marker_missing');
                return new WP_Error('oai_document_write_uncertain',
                    'Document may be saved but its application marker is missing',array('status'=>500));
            }
            $verification=OAI_Verify::operation($id,$target_tree,
                $action==='apply'?'after':'rollback',true);
            if (is_wp_error($verification) || empty($verification['verified'])) {
                self::uncertain($id,$action,'verify_failed');
                return new WP_Error('oai_document_verify',
                    'Document write needs manual reconciliation after verification failure',array('status'=>409));
            }
            $second=OAI_Verify::operation($id,$target_tree,
                $action==='apply'?'after':'rollback',true);
            if (is_wp_error($second) || empty($second['verified'])) {
                self::uncertain($id,$action,'verify_unstable');
                return new WP_Error('oai_document_verify',
                    'Document second verification failed; manual reconciliation required',array('status'=>409));
            }
            $final=$action==='apply'?'applied':'rolled_back';
            $fields=$action==='apply'
                ? array('status'=>'applied','phase'=>'applied',
                    'applied_data'=>wp_json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),
                    'applied_at'=>current_time('mysql',true))
                : array('status'=>'rolled_back','phase'=>'rolled_back',
                    'rolled_back_at'=>current_time('mysql',true));
            if (!self::transition($id,$expected_status,$fields)) {
                self::uncertain($id,$action,'finalization_failed');
                return new WP_Error('oai_document_finalize',
                    'Saved document was verified but final state persistence requires review',array('status'=>500));
            }
            $proof=OAI_Proof::make($id);
            if (!is_array($proof)||empty($proof['verified'])) {
                // A successful write can have occurred. Keep the applied
                // status; report that manual proof reconciliation is needed.
                return new WP_Error('oai_document_proof','Document saved but proof is incomplete; reconciliation required',array('status'=>500));
            }
            $receipt=array('event'=>$action,'status'=>$final,'at'=>gmdate('c'),
                'verification'=>$second,'document_sha256'=>OAI_Elementor_Schema_Save::tree_hash($target_tree));
            OAI_Receipts::store($id,$receipt,$proof);
            $stored=OAI_Receipts::build($id);
            if (!is_array($stored)||empty($stored['verified']) ||
                ($stored['status']??'')!==$final ||
                ($stored['receipt']['document_sha256']??'')!==$receipt['document_sha256']) {
                return new WP_Error('oai_document_receipt','Document saved but receipt persistence requires review',array('status'=>500));
            }
            if (class_exists('OAI_Audit')) OAI_Audit::log($action==='apply'?'applied':'rolled_back',
                OAI_DB::get($id),array('verified'=>true,'document_sha256'=>$receipt['document_sha256']));
            return array('id'=>$id,'post_id'=>$post_id,'status'=>$final,
                'verified'=>true,'receipt'=>$stored['receipt'],'proof'=>$stored['proof']);
        } catch (Throwable $e) {
            if ($write_attempted) self::uncertain($id,$action,'exception_after_write_attempt');
            else self::fail($id,$action,'exception_before_write');
            return new WP_Error('oai_document_exception',
                'Document execution was interrupted; inspect actual state before any repair',array('status'=>500));
        }
    }

    /**
     * Explicit administrator reconciliation of an already attempted Apply.
     * It NEVER performs an Elementor write or automatic rollback.
     * This method is not registered as a REST/MCP endpoint.
     * An approved before/after image and applied marker must already exist.
     */
    public static function reconcile_applied_human($id,$post_id,$nonce) {
        $id=absint($id);$post_id=absint($post_id);
        if (!$id || !$post_id || !is_string($nonce) || $nonce==='' ||
            !wp_verify_nonce($nonce,'wp_rest') ||
            !current_user_can('manage_options') ||
            !current_user_can('edit_post',$post_id)) {
            return new WP_Error('oai_reconcile_permission',
                'Administrator nonce and document permissions are required',array('status'=>403));
        }
        if (!class_exists('OAI_Elementor_Trusted_Planner') ||
            !class_exists('OAI_Elementor_Schema_Save') ||
            !class_exists('OAI_Verify') || !class_exists('OAI_Receipts') ||
            !class_exists('OAI_Proof')) {
            return new WP_Error('oai_reconcile_unavailable',
                'Reconciliation dependencies are unavailable',array('status'=>503));
        }
        $row=OAI_DB::get($id);
        if (!is_array($row) ||
            ($row['type']??'')!=='elementor_document_save' ||
            (string)($row['target']??'')!==(string)$post_id ||
            ($row['status']??'')!=='applying' ||
            empty($row['applied_at']) || empty($row['applied_data'])) {
            return new WP_Error('oai_reconcile_state',
                'Operation has no recorded write or requires a different recovery procedure',
                array('status'=>409));
        }
        $meta=json_decode((string)$row['meta_data'],true);
        if (!is_array($meta) ||
            absint($meta['owner_user_id']??0)!==get_current_user_id()) {
            return new WP_Error('oai_reconcile_owner','Operation owner mismatch',array('status'=>403));
        }
        $origin=OAI_Elementor_Trusted_Planner::verify_approval_record($row);
        if (is_wp_error($origin)) return $origin;
        $expected=json_decode((string)$row['after_data'],true);
        $applied=json_decode((string)$row['applied_data'],true);
        if (!is_array($expected)||!is_array($applied) ||
            !OAI_Elementor_Schema_Save::same_tree($applied,$expected)) {
            return new WP_Error('oai_reconcile_snapshot',
                'Persisted applied image differs from trusted proposal',array('status'=>409));
        }
        // Independent WordPress API + persistent MySQL verification, twice.
        // No automatic reapply if another editor modified the page.
        for ($pass=0;$pass<2;$pass++) {
            $verify=OAI_Verify::operation($id,$expected,'after',true);
            if (is_wp_error($verify) || empty($verify['verified'])) {
                return new WP_Error('oai_reconcile_conflict',
                    'Current document does not match approved applied image',
                    array('status'=>409));
            }
        }
        if (!self::transition($id,'applying',array('status'=>'applied','phase'=>'applied'))) {
            return new WP_Error('oai_reconcile_race',
                'Operation changed during reconciliation; no write attempted',array('status'=>409));
        }
        $proof=OAI_Proof::make($id);
        if (!is_array($proof) || empty($proof['verified'])) {
            return new WP_Error('oai_reconcile_proof',
                'Applied state confirmed but proof needs operator inspection',
                array('status'=>500));
        }
        $receipt=array(
            'event'=>'apply','status'=>'applied',
            'reconciled_from'=>'incomplete_execution',
            'at'=>gmdate('c'),
            'document_sha256'=>OAI_Elementor_Schema_Save::tree_hash($expected)
        );
        OAI_Receipts::store($id,$receipt,$proof);
        $stored=OAI_Receipts::build($id);
        if (!is_array($stored) || empty($stored['verified']) ||
            ($stored['receipt']['reconciled_from']??'')!=='incomplete_execution') {
            return new WP_Error('oai_reconcile_receipt',
                'Applied state persisted but proof or receipt requires inspection',
                array('status'=>500));
        }
        if (class_exists('OAI_Audit')) OAI_Audit::log('applied_reconciled',
            OAI_DB::get($id),array('human_reconciliation'=>true,
                'document_sha256'=>$receipt['document_sha256']));
        return array('id'=>$id,'post_id'=>$post_id,'status'=>'applied',
            'reconciled'=>true,'write_executed'=>false,
            'verified'=>true,'receipt'=>$stored['receipt'],'proof'=>$stored['proof']);
    }

    /**
     * Fail-safe incident record for anything that can have written the page.
     * Keep the CAS state unchanged and retain applied_data/applied_at when
     * already captured; do not fabricate rollback safety or a success proof.
     */
    private static function uncertain($id,$action,$reason) {
        $state=$action==='apply'?'applying':'rolling_back';
        $row=OAI_DB::get($id);
        if (!is_array($row) || ($row['status']??'')!==$state) return;
        OAI_Receipts::store($id,array(
            'event'=>'document_write_uncertain','status'=>$state,
            'reason'=>$reason,'recovery'=>'manual_review',
            'verified'=>false,'at'=>gmdate('c')
        ),null);
        if (class_exists('OAI_Audit')) OAI_Audit::log('document_write_uncertain',
            $row,array('action'=>$action,'reason'=>$reason));
    }

    private static function transition($id,$from,$fields) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb,'prepare') ||
            !method_exists($wpdb,'query')) return false;
        $values=array();$set=array();
        foreach ($fields as $key=>$value) {
            if (!in_array($key,array('status','phase','applied_data','applied_at','rolled_back_at'),true))
                return false;
            $set[]=$key.'=%s';$values[]=$value;
        }
        $values[]=absint($id);$values[]=$from;
        $sql='UPDATE '.OAI_DB::table().' SET '.implode(',',$set).' WHERE id=%d AND status=%s';
        return (int)$wpdb->query($wpdb->prepare($sql,$values))===1;
    }

    private static function fail($id,$action,$reason) {
        // Never retry an uncertain write automatically, and never overwrite
        // an operation another process already finalized.
        $from=$action==='apply'?'applying':'rolling_back';
        $failed=$action==='apply'?'apply_failed':'rollback_failed';
        if (self::transition($id,$from,array('status'=>$failed,'phase'=>$failed))) {
            OAI_Receipts::store($id,array('event'=>$failed,'status'=>$failed,
                'reason'=>$reason,'recovery'=>'manual_review','at'=>gmdate('c')),null);
            if (class_exists('OAI_Audit')) OAI_Audit::log($failed,OAI_DB::get($id),
                array('reason'=>$reason,'recovery'=>'manual_review'));
        }
    }
}
