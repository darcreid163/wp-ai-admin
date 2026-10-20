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
        if ($mode==='rollback') lease_result(OAI_Elementor::save_document($post,$after),'Simulated previously applied state');
        $op=lease_result(OAI_DB::insert([
            'operation_id'=>'OAI-LEASE-CI-'.wp_generate_uuid4(),
            'status'=>$mode==='rollback'?'applied':'approved',
            'phase'=>$mode==='rollback'?'applied':'approved',
            'type'=>'elementor_document_save',
            'target'=>(string)$post,
            'before_data'=>wp_json_encode($change['before']),
            'after_data'=>wp_json_encode($change['after']),
            'meta_data'=>wp_json_encode([
                'site_id'=>OAI_Platform::site_id(),'post_id'=>$post,'owner_user_id'=>1,
                'before_sha256'=>$change['before_sha256'],'after_sha256'=>$change['after_sha256']
            ]),
            'diff_data'=>'{}','risk'=>'medium',
            'created_at'=>current_time('mysql',true),
            'approved_at'=>current_time('mysql',true),
            'applied_at'=>$mode==='rollback'?current_time('mysql',true):null,
            'expires_at'=>gmdate('Y-m-d H:i:s',time()+600)
        ]),'Create test-only approved row');
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
