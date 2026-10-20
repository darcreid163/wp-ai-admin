<?php
/**
 * Real disposable WordPress/MySQL lifecycle acceptance for the UNREGISTERED
 * Elementor document executor. Run only from CI with explicit environment.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST')!=='1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false)) {
    throw new RuntimeException('Refusing executor test outside disposable WordPress');
}
foreach ([
    'class-oai-elementor-schema-save.php',
    'class-oai-elementor-operation-binding.php',
    'class-oai-elementor-trusted-planner.php',
    'class-oai-elementor-operation-lease.php',
    'class-oai-elementor-external-edit-guard.php',
    'class-oai-elementor-document-executor.php'
] as $file) require_once dirname(__DIR__).'/includes/'.$file;
// A default invocation MUST remain disabled, even on a disposable site.
$off=OAI_Elementor_Document_Executor::apply_human(1,1,'ci');
if (!is_wp_error($off) || $off->get_error_code()!=='oai_document_staged_only') {
    throw new RuntimeException('Unreviewed Elementor full-document executor lacks fail-closed gate');
}
define('OAI_ELEMENTOR_DOCUMENT_STAGED_TEST',true);
wp_set_current_user(1);
function doc_expect($condition,$msg) {
    if (!$condition) throw new RuntimeException($msg);
}
function doc_success($result,$msg) {
    if (is_wp_error($result)) throw new RuntimeException($msg.': '.$result->get_error_code());
    return $result;
}
function doc_error($result,$expected,$msg) {
    doc_expect(is_wp_error($result) && $result->get_error_code()===$expected,
        $msg.' (wanted '.$expected.', got '.(is_wp_error($result)?$result->get_error_code():gettype($result)).')');
}
function doc_approve($id,$nonce) {
    $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$id.'/approve');
    $req->set_url_params(['id'=>$id,'action'=>'approve']);
    $req->set_header('X-WP-Nonce',$nonce);
    return OAI_REST::sandbox_action($req);
}
global $wpdb;
$post=doc_success(wp_insert_post([
    'post_type'=>'page','post_title'=>'Disposable full document executor',
    'post_status'=>'draft'
],true),'Create draft');
$id=0;$other=0;
$original=[['id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading',
    'settings'=>['title'=>'Stage 5 Original','header_size'=>'h2'],'elements'=>[]]];
$changed=$original;$changed[0]['settings']['title']='Stage 5 Approved';
$nonce=wp_create_nonce('wp_rest');
try {
    doc_success(OAI_Elementor::save_document($post,$original),'Save original draft');
    $planned=doc_success(OAI_Elementor_Trusted_Planner::prepare_human($post,$changed),'Plan pending');
    $id=(int)$planned['id'];
    doc_expect($planned['status']==='pending' && !empty($planned['requires_human_approval']),
        'Planner created an unauthorized approved operation');
    doc_error(OAI_Elementor_Document_Executor::apply_human($id,$post,$nonce),
        'oai_binding_state','Apply before human approval accepted');
    doc_error(OAI_Elementor_Document_Executor::apply_human($id,$post,''),
        'oai_lease_nonce','Missing nonce accepted');
    doc_success(doc_approve($id,$nonce),'Human approval');

    // A human editing the same page should receive priority over an AI
    // document-wide write, even when an operation was approved earlier.
    $foreign_lock=(string)time().':2';
    update_post_meta($post,'_edit_lock',$foreign_lock);
    $locked=OAI_Elementor_External_Edit_Guard::inspect($post,$original);
    doc_error($locked,'oai_external_editor_active',
        'Active foreign WordPress edit lock was ignored');
    delete_post_meta($post,'_edit_lock');
    update_post_meta($post,'_edit_lock',(string)time().':1');
    $own_lock=doc_success(OAI_Elementor_External_Edit_Guard::inspect($post,$original),
        'Own WordPress edit lock must be permitted');
    doc_expect($own_lock['writer_exclusion_guaranteed']===false,
        'Advisory WordPress lock must not be presented as a strong DB lock');
    delete_post_meta($post,'_edit_lock');

    // Bypass WP postmeta cache to simulate a third-party direct SQL edit.
    // The independent DB read must still catch the divergent content.
    $other_tree=$original;
    $other_tree[0]['settings']['title']='Other editor uncommitted override';
    $wpdb->update($wpdb->postmeta,[
        'meta_value'=>wp_json_encode($other_tree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ],['post_id'=>$post,'meta_key'=>'_elementor_data'],['%s'],['%d','%s']);
    $db_conflict=OAI_Elementor_External_Edit_Guard::inspect($post,$original);
    doc_error($db_conflict,'oai_external_guard_stale',
        'Independent MySQL read failed to detect external data change');
    update_post_meta($post,'_elementor_data',wp_slash(wp_json_encode($original)));
    clean_post_cache($post);
    wp_cache_delete($post,'post_meta');

    $applied=doc_success(OAI_Elementor_Document_Executor::apply_human($id,$post,$nonce),'Execute Apply');
    doc_expect(($applied['status']??'')==='applied' && !empty($applied['verified']),
        'Apply did not commit verified status');
    doc_expect(!empty($applied['proof']['verified']) &&
        ($applied['proof']['phase']??'')==='after' &&
        ($applied['receipt']['event']??'')==='apply',
        'Verified Apply proof/receipt missing');
    doc_expect(OAI_Elementor_Schema_Save::same_tree(
        doc_success(OAI_Elementor_Schema_Save::snapshot($post),'Read Apply')['tree'],$changed
    ),'Apply document differs from proposal');
    doc_error(OAI_Elementor_Document_Executor::apply_human($id,$post,$nonce),
        'oai_binding_state','Replay Apply was accepted');
    $stored=OAI_DB::get($id);
    doc_expect($stored['status']==='applied' && !empty($stored['applied_at']) &&
        !empty($stored['applied_data']), 'Persisted Apply state missing');

    // An approved rollback must not erase edits made by someone else after
    // the verified Apply. Restoring the approved after-image is only a test
    // fixture reset, not a production recovery procedure.
    $post_apply_edit=$changed;
    $post_apply_edit[0]['settings']['title']='Human edit after Apply';
    doc_success(OAI_Elementor::save_document($post,$post_apply_edit),'Create later editor change');
    doc_error(OAI_Elementor_Document_Executor::rollback_human($id,$post,$nonce),
        'oai_binding_stale','Rollback overwrote a later human edit');
    doc_expect(OAI_Elementor_Schema_Save::same_tree(
        doc_success(OAI_Elementor_Schema_Save::snapshot($post),'Check later editor')['tree'],
        $post_apply_edit),'Conflicted rollback touched the document');
    doc_expect(OAI_DB::get($id)['status']==='applied',
        'Refused rollback changed the applied operation state');
    doc_success(OAI_Elementor::save_document($post,$changed),'Reset disposable fixture to approved after-image');
    doc_error(OAI_Elementor_Document_Executor::rollback_human($id,$post,''),
        'oai_lease_nonce','Rollback accepted without WP admin nonce');

    $rollback=doc_success(OAI_Elementor_Document_Executor::rollback_human($id,$post,$nonce),
        'Execute Rollback');
    doc_expect(($rollback['status']??'')==='rolled_back' && !empty($rollback['verified']) &&
        !empty($rollback['proof']['verified']) &&
        ($rollback['proof']['phase']??'')==='rollback' &&
        ($rollback['proof']['hash']??'')!==($applied['proof']['hash']??'') &&
        ($rollback['receipt']['event']??'')==='rollback',
        'Rollback must have its own phase-specific proof, not cached Apply proof');
    doc_expect(OAI_Elementor_Schema_Save::same_tree(
        doc_success(OAI_Elementor_Schema_Save::snapshot($post),'Read Rollback')['tree'],$original
    ),'Rollback failed to restore trusted DB before-image');
    doc_error(OAI_Elementor_Document_Executor::rollback_human($id,$post,$nonce),
        'oai_binding_state','Replay Rollback was accepted');

    // A newer human edit must never be overwritten by an old approved plan.
    $new=$changed;$new[0]['settings']['title']='Concurrent Human Edit';
    $planned2=doc_success(OAI_Elementor_Trusted_Planner::prepare_human($post,$changed),
        'Prepare second pending operation');
    $other=(int)$planned2['id'];
    doc_success(doc_approve($other,$nonce),'Approve second operation');
    doc_success(OAI_Elementor::save_document($post,$new),'Simulate concurrent human editor');
    doc_error(OAI_Elementor_Document_Executor::apply_human($other,$post,$nonce),
        'oai_binding_stale','Stale Apply overwrote human editor');
    doc_expect(OAI_Elementor_Schema_Save::same_tree(
        doc_success(OAI_Elementor_Schema_Save::snapshot($post),'Verify human editor')['tree'],$new
    ),'Stale Apply changed document');
    doc_expect(get_post_status($post)==='draft','Test published its draft');
    echo "PASS: real Elementor trusted pending → nonce approval → CAS Apply → dual Verify/Proof/Receipt → CAS Rollback → Verify/Receipt; no replay or stale overwrite\n";
} finally {
    wp_set_current_user(1);
    if($id)$wpdb->delete(OAI_DB::table(),['id'=>$id],['%d']);
    if($other)$wpdb->delete(OAI_DB::table(),['id'=>$other],['%d']);
    wp_delete_post($post,true);
}
