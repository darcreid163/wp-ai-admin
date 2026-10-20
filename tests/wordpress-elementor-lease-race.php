<?php
/**
 * Disposable real WordPress/MySQL race fixture. Phases run in separate PHP
 * processes. No production site is permitted and no document is modified by
 * the claim phase.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(), 'localhost') === false && strpos(home_url(), '.test') === false)) {
    throw new RuntimeException('Race fixture refuses non-disposable WordPress');
}
require_once dirname(__DIR__).'/includes/class-oai-elementor-schema-save.php';
require_once dirname(__DIR__).'/includes/class-oai-elementor-operation-binding.php';
require_once dirname(__DIR__).'/includes/class-oai-elementor-operation-lease.php';
require_once dirname(__DIR__).'/includes/class-oai-elementor-trusted-planner.php';
wp_set_current_user(1);
$phase = (string)getenv('OAI_LEASE_PHASE');
function lease_expect($ok,$why) { if(!$ok) throw new RuntimeException($why); }
function lease_result($value,$why) {
    if(is_wp_error($value))throw new RuntimeException($why.': '.$value->get_error_code());
    return $value;
}
$mode = getenv('OAI_LEASE_MODE') === 'rollback' ? 'rollback' : 'apply';
$marker = 'oai_disposable_lease_race_'.$mode;
global $wpdb;
if ($phase==='seed') {
    lease_expect(!get_option($marker,false),'Fixture already exists');
    $post = lease_result(wp_insert_post([
        'post_type'=>'page','post_title'=>'Disposable CAS race test','post_status'=>'draft'
    ],true),'New test draft');
    $op=0;
    try {
        $before=[['id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading',
            'settings'=>['title'=>'Original'],'elements'=>[]]];
        $after=$before;
        $after[0]['settings']['title']='Prepared but not applied';
        lease_result(OAI_Elementor::save_document($post,$before),'Save baseline');
        $snapshot=lease_result(OAI_Elementor_Schema_Save::snapshot($post),'Snapshot');
        $change=lease_result(OAI_Elementor_Schema_Save::validate_replace($snapshot['tree'],$after,$snapshot['sha256']),'Prepare');

        $planned=lease_result(OAI_Elementor_Trusted_Planner::prepare_human($post,$after),'Trusted pending sandbox');
        $op=(int)$planned['id'];
        lease_expect($planned['status']==='pending' && $planned['requires_human_approval']===true,
            'Planner bypassed pending approval');
        $before_approval=OAI_DB::get($op);
        lease_expect(empty($before_approval['approved_at']), 'Planner set approved_at without human consent');
        // Use the existing WP-admin nonce-gated approval action, not a
        // handcrafted row with an approved status.
        $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$op.'/approve');
        $req->set_url_params(['id'=>$op,'action'=>'approve']);
        $req->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        lease_result(OAI_REST::sandbox_action($req),'Real administrator approval');
        $approved=OAI_DB::get($op);
        lease_expect($approved['status']==='approved' && !empty($approved['approved_at']),
            'Real approval did not persist');
        if ($mode==='rollback') {
            // No document Apply is executed here: the fixture explicitly
            // simulates a prior trusted Apply so it can test rollback CAS.
            lease_result(OAI_Elementor::save_document($post,$after),'Simulate previous apply');
            OAI_DB::update($op,[
                'status'=>'applied','phase'=>'applied',
                'applied_at'=>current_time('mysql',true)
            ]);
        }
        update_option($marker,['post_id'=>$post,'operation_id'=>$op],false);
        echo "SEEDED\n";
    } catch(Throwable $e) {
        if($op)$wpdb->delete(OAI_DB::table(),['id'=>$op],['%d']);
        wp_delete_post($post,true);
        throw $e;
    }
} elseif ($phase==='claim') {
    $data=get_option($marker,[]);
    lease_expect(is_array($data) && !empty($data['operation_id']), 'Fixture missing');
    $nonce=wp_create_nonce('wp_rest');
    $res=OAI_Elementor_Operation_Lease::claim_human(
        (int)$data['operation_id'],$mode,(int)$data['post_id'],$nonce
    );
    if (is_wp_error($res)) {
        lease_expect(in_array($res->get_error_code(),
            ['oai_lease_conflict','oai_binding_state','oai_binding_stale'],true),
            'Unexpected failure '.$res->get_error_code());
        echo "REFUSED\n";
    } else {
        lease_expect(!empty($res['claimed']) && $res['status']===($mode==='rollback'?'rolling_back':'applying') &&
            $res['write_executed']===false, 'Claim did not return expected state');
        echo "CLAIMED\n";
    }
} elseif ($phase==='verify') {
    $data=get_option($marker,[]);
    lease_expect(is_array($data) && !empty($data['operation_id']), 'Fixture missing');
    $id=(int)$data['operation_id'];$post=(int)$data['post_id'];
    try {
        $row=OAI_DB::get($id);
        lease_expect(is_array($row) && $row['status']===($mode==='rollback'?'rolling_back':'applying'),
            'Atomic claim did not persist exactly one applying state');
        $current=lease_result(OAI_Elementor_Schema_Save::snapshot($post),'Read unaffected document');
        $before=json_decode((string)$row['before_data'],true);
        $after=json_decode((string)$row['after_data'],true);
        lease_expect(OAI_Elementor_Schema_Save::same_tree($current['tree'],$mode==='rollback'?$after:$before),
            'Claim unexpectedly modified Elementor document');
        echo "VERIFIED\n";
    } finally {
        $wpdb->delete(OAI_DB::table(),['id'=>$id],['%d']);
        wp_delete_post($post,true);
        delete_option($marker);
    }
} else throw new RuntimeException('Unknown fixture phase');
