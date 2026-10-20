<?php
/**
 * Failure-window negative tests on disposable WordPress only.
 * Simulates a third-party metadata writer AFTER Elementor's save but BEFORE
 * OAI_Verify. Does not use production-only fault hooks.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST')!=='1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false)) {
    throw new RuntimeException('Failure-window test requires disposable WordPress');
}
foreach ([
    'class-oai-elementor-schema-save.php',
    'class-oai-elementor-operation-binding.php',
    'class-oai-elementor-trusted-planner.php',
    'class-oai-elementor-operation-lease.php',
    'class-oai-elementor-document-executor.php',
    'class-oai-elementor-document-recovery.php'
] as $file) require_once dirname(__DIR__).'/includes/'.$file;
function window_require($condition,$message) {
    if (!$condition) throw new RuntimeException($message);
}
function window_ok($result,$message) {
    if (is_wp_error($result)) throw new RuntimeException($message.': '.$result->get_error_code());
    return $result;
}
wp_set_current_user(1);
global $wpdb;
$post=window_ok(wp_insert_post(['post_type'=>'page','post_status'=>'draft',
    'post_title'=>'Disposable incomplete document write'],true),'Create page');
$blog=window_ok(wp_insert_post(['post_type'=>'post','post_status'=>'draft',
    'post_title'=>'Not an Elementor page operation'],true),'Create blog post');
$id=0;
$before=[['id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading',
    'settings'=>['title'=>'Original'],'elements'=>[]]];
$after=$before;$after[0]['settings']['title']='Approved target';
$foreign=$after;$foreign[0]['settings']['title']='Third-party concurrent write';
$nonce=wp_create_nonce('wp_rest');
$corruptor=null;
try {
    window_ok(OAI_Elementor::save_document($post,$before),'Save baseline');
    $denied=OAI_Elementor_Trusted_Planner::prepare_human($blog,$after);
    window_require(is_wp_error($denied)&&$denied->get_error_code()==='oai_planner_post',
        'Non-page document accepted into trusted planner');
    $planned=window_ok(OAI_Elementor_Trusted_Planner::prepare_human($post,$after),'Prepare pending');
    $id=(int)$planned['id'];
    $request=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$id.'/approve');
    $request->set_url_params(['id'=>$id,'action'=>'approve']);
    $request->set_header('X-WP-Nonce',$nonce);
    window_ok(OAI_REST::sandbox_action($request),'Human approve');

    // OAI_Elementor::save_document fires this cache-clear action AFTER its
    // persistence step. Corrupt the database only at this precise boundary,
    // simulating an external writer that invalidates the Verify readback.
    $corruptor=function() use ($post,$foreign,$wpdb) {
        static $fired=false;
        if ($fired) return;
        $fired=true;
        $wpdb->update($wpdb->postmeta,[
            'meta_value'=>wp_json_encode($foreign,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ],['post_id'=>$post,'meta_key'=>'_elementor_data'],['%s'],['%d','%s']);
        clean_post_cache($post);
        wp_cache_delete($post,'post_meta');
    };
    add_action('elementor/core/files/clear_cache',$corruptor,999,0);
    $failure=OAI_Elementor_Document_Executor::apply_human($id,$post,$nonce);
    remove_action('elementor/core/files/clear_cache',$corruptor,999);
    window_require(is_wp_error($failure), 'Corrupted after-write verification unexpectedly passed');
    $row=OAI_DB::get($id);
    window_require($row['status']==='applying' && !empty($row['applied_data']) &&
        !empty($row['applied_at']), 'Possible completed write was mislabeled apply_failed or marker erased');
    $incident=json_decode((string)$row['receipt'],true);
    window_require(is_array($incident) && $incident['event']==='document_write_uncertain' &&
        empty($incident['verified']),'Unverified change falsely received a success receipt');

    $assessment=window_ok(OAI_Elementor_Document_Recovery::inspect_human($id,$post),
        'Inspect interrupted write');
    window_require($assessment['assessment']==='concurrent_or_partial_edit' &&
        $assessment['automatic_retry_allowed']===false,
        'Incomplete write recovery may overwrite another editor');

    // Model the second crash window: DB marks "applied" but process dies
    // before storing receipt/proof. Inspector must surface the incident.
    window_ok(OAI_Elementor::save_document($post,$after),'Reset disposable applied fixture');
    OAI_DB::update($id,['status'=>'applied','phase'=>'applied','receipt'=>null,'proof'=>null]);
    $gap=window_ok(OAI_Elementor_Document_Recovery::inspect_human($id,$post),
        'Detect absent Apply receipt');
    window_require($gap['terminal_receipt_or_proof_missing']===true &&
        $gap['evidence_incident']==='missing_or_incorrect_terminal_receipt_or_proof' &&
        $gap['automatic_retry_allowed']===false,'Applied status without receipt was treated as success');

    window_ok(OAI_Elementor::save_document($post,$before),'Restore disposable rollback fixture');
    OAI_DB::update($id,['status'=>'rolled_back','phase'=>'rolled_back','receipt'=>null,
        'proof'=>null,'rolled_back_at'=>current_time('mysql',true)]);
    $gap=window_ok(OAI_Elementor_Document_Recovery::inspect_human($id,$post),
        'Detect absent Rollback receipt');
    window_require($gap['terminal_receipt_or_proof_missing']===true &&
        $gap['action']==='rollback' &&
        $gap['automatic_rollback_allowed']===false,
        'Rolled_back status without proof was treated as success');
    window_require(get_post_status($post)==='draft','Failure test published the temporary page');
    echo "PASS: after-write verification fault preserves ambiguous state; missing Apply/Rollback evidence detected; no automatic replay; non-page refused\n";
} finally {
    if ($corruptor) remove_action('elementor/core/files/clear_cache',$corruptor,999);
    wp_set_current_user(1);
    if($id)$wpdb->delete(OAI_DB::table(),['id'=>$id],['%d']);
    wp_delete_post($post,true);
    wp_delete_post($blog,true);
}
