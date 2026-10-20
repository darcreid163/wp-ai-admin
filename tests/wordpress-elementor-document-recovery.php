<?php
/** Read-only crash assessment of interrupted Apply and Rollback in disposable WP. */
if (getenv('OAI_DISPOSABLE_WP_TEST')!=='1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false))
    throw new RuntimeException('Refusing crash test on non-disposable WordPress');
foreach([
    'class-oai-elementor-schema-save.php',
    'class-oai-elementor-operation-binding.php',
    'class-oai-elementor-trusted-planner.php',
    'class-oai-elementor-operation-lease.php',
    'class-oai-elementor-document-executor.php',
    'class-oai-elementor-document-recovery.php'
] as $file) require_once dirname(__DIR__).'/includes/'.$file;
function recovery_ok($value,$msg){
    if(is_wp_error($value)) throw new RuntimeException($msg.': '.$value->get_error_code());
    return $value;
}
function recovery_assert($yes,$msg){if(!$yes)throw new RuntimeException($msg);}
wp_set_current_user(1);
global $wpdb;
$post=recovery_ok(wp_insert_post(['post_type'=>'page','post_status'=>'draft',
    'post_title'=>'Disposable interrupted Elementor operation'],true),'Create test page');
$id=0;
$before=[['id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading',
    'settings'=>['title'=>'Recovery original'],'elements'=>[]]];
$after=$before;$after[0]['settings']['title']='Recovery applied';
$other=$after;$other[0]['settings']['title']='Concurrent editor';
$nonce=wp_create_nonce('wp_rest');
try {
    recovery_ok(OAI_Elementor::save_document($post,$before),'Save initial document');
    $planned=recovery_ok(OAI_Elementor_Trusted_Planner::prepare_human($post,$after),'Plan operation');
    $id=(int)$planned['id'];
    $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$id.'/approve');
    $req->set_url_params(['id'=>$id,'action'=>'approve']);
    $req->set_header('X-WP-Nonce',$nonce);
    recovery_ok(OAI_REST::sandbox_action($req),'Existing WP human approval');

    recovery_ok(OAI_Elementor_Operation_Lease::claim_human($id,'apply',$post,$nonce),
        'Claim interrupted apply');
    $check=recovery_ok(OAI_Elementor_Document_Recovery::inspect_human($id,$post),
        'Inspect unapplied document');
    recovery_assert($check['assessment']==='apply_not_observed' &&
        $check['automatic_retry_allowed']===false &&
        $check['read_only']===true,'Premature recovery recommended write');
    wp_set_current_user(0);
    $denied=OAI_Elementor_Document_Recovery::inspect_human($id,$post);
    recovery_assert(is_wp_error($denied),'Anonymous recovery was not rejected');
    wp_set_current_user(1);
    recovery_ok(OAI_Elementor::save_document($post,$after),'Simulate committed Apply before status');
    $check=recovery_ok(OAI_Elementor_Document_Recovery::inspect_human($id,$post),
        'Inspect committed yet unresolved Apply');
    recovery_assert($check['assessment']==='apply_may_have_committed',
        'Committed Apply not recognized');
    // A crash in the tiny window before applied_data/applied_at persistence
    // must NOT allow an operator to invent a successful confirmed Apply.
    $premature=OAI_Elementor_Document_Executor::reconcile_applied_human(
        $id,$post,$nonce);
    recovery_assert(is_wp_error($premature) &&
        $premature->get_error_code()==='oai_reconcile_state',
        'Reconciliation accepted an unrecorded/uncertain write attempt');
    recovery_ok(OAI_Elementor::save_document($post,$other),'Simulate concurrent edit');
    $check=recovery_ok(OAI_Elementor_Document_Recovery::inspect_human($id,$post),
        'Inspect foreign editor change');
    recovery_assert($check['assessment']==='concurrent_or_partial_edit' &&
        $check['automatic_rollback_allowed']===false,
        'Concurrent edit must prevent automatic recovery');

    // Simulate a fully applied state solely in this disposable fixture,
    // then interrupt a rollback claim before its write.
    recovery_ok(OAI_Elementor::save_document($post,$after),'Restore desired after state');
    OAI_DB::update($id,['status'=>'applied','phase'=>'applied',
        'applied_data'=>wp_json_encode($after),'applied_at'=>current_time('mysql',true)]);
    recovery_ok(OAI_Elementor_Operation_Lease::claim_human($id,'rollback',$post,$nonce),
        'Claim interrupted rollback');
    $check=recovery_ok(OAI_Elementor_Document_Recovery::inspect_human($id,$post),
        'Inspect before rollback write');
    recovery_assert($check['assessment']==='rollback_not_observed','Wrong rollback pre-write classification');
    recovery_ok(OAI_Elementor::save_document($post,$before),'Simulate rollback without receipt');
    $check=recovery_ok(OAI_Elementor_Document_Recovery::inspect_human($id,$post),
        'Inspect rolled back document');
    recovery_assert($check['assessment']==='rollback_may_have_committed',
        'Committed rollback not recognized');
    recovery_assert(OAI_DB::get($id)['status']==='rolling_back',
        'Read-only assessment unexpectedly changed operation status');
    echo "PASS: crash inspector classifies before/after/concurrent states for interrupted Apply/Rollback, prevents automatic retry and refuses anonymous actors\n";
} finally {
    wp_set_current_user(1);
    if($id)$wpdb->delete(OAI_DB::table(),['id'=>$id],['%d']);
    wp_delete_post($post,true);
}
