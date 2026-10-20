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

        try {
            $saved=OAI_Elementor::save_document($post_id,$target_tree);
            if (is_wp_error($saved)) {
                self::fail($id,$action,'save_failed');
                return $saved;
            }
            $verification=OAI_Verify::operation($id,$target_tree,
                $action==='apply'?'after':'rollback',true);
            if (is_wp_error($verification) || empty($verification['verified'])) {
                self::fail($id,$action,'verify_failed');
                return new WP_Error('oai_document_verify','Saved Elementor document failed independent verification',array('status'=>409));
            }
            $second=OAI_Verify::operation($id,$target_tree,
                $action==='apply'?'after':'rollback',true);
            if (is_wp_error($second) || empty($second['verified'])) {
                self::fail($id,$action,'verify_unstable');
                return new WP_Error('oai_document_verify','Second document verification failed',array('status'=>409));
            }
            $final=$action==='apply'?'applied':'rolled_back';
            $fields=$action==='apply'
                ? array('status'=>'applied','phase'=>'applied',
                    'applied_data'=>wp_json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),
                    'applied_at'=>current_time('mysql',true))
                : array('status'=>'rolled_back','phase'=>'rolled_back',
                    'rolled_back_at'=>current_time('mysql',true));
            if (!self::transition($id,$expected_status,$fields)) {
                return new WP_Error('oai_document_finalize','Could not atomically record verified operation outcome',array('status'=>500));
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
            self::fail($id,$action,'exception');
            return new WP_Error('oai_document_exception','Document execution failed; review operation state',array('status'=>500));
        }
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
