<?php
/**
 * Real-WordPress / Elementor / MySQL test of read-only operation preflight.
 * The test is never loaded by WordPress or exposed as MCP/REST.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(), 'localhost') === false && strpos(home_url(), '.test') === false)) {
    throw new RuntimeException('Not a disposable WordPress test installation');
}
require_once dirname(__DIR__).'/includes/class-oai-elementor-schema-save.php';
require_once dirname(__DIR__).'/includes/class-oai-elementor-operation-binding.php';
function binding_real_expect($v,$reason) { if (!$v) throw new RuntimeException($reason); }
function binding_real_ok($v,$reason) {
    if (is_wp_error($v)) throw new RuntimeException($reason.': '.$v->get_error_code());
    return $v;
}
wp_set_current_user(1);
$site = OAI_Platform::site_id();
binding_real_expect(is_string($site) && $site !== '', 'Installation identity missing');
$post = binding_real_ok(wp_insert_post([
    'post_type'=>'page',
    'post_status'=>'draft',
    'post_title'=>'Disposable operation-binding regression',
    'post_content'=>'',
],true),'Create draft');
$before = [[
    'id'=>'aabbccdd', 'elType'=>'widget','widgetType'=>'heading',
    'settings'=>['title'=>'Snapshot A','header_size'=>'h2'], 'elements'=>[],
]];
$after = $before;
$after[0]['settings']['title']='Snapshot B';
$operation = 0;
global $wpdb;
try {
    binding_real_ok(OAI_Elementor::save_document($post,$before),'Persist starting Elementor document');
    $current = binding_real_ok(OAI_Elementor_Schema_Save::snapshot($post),'Read baseline');
    $change = binding_real_ok(OAI_Elementor_Schema_Save::validate_replace($current['tree'],$after,$current['sha256']),'Prepare new document');
    $meta = [
        'site_id'=>$site,'post_id'=>$post,'owner_user_id'=>1,
        'before_sha256'=>$change['before_sha256'],
        'after_sha256'=>$change['after_sha256'],
    ];
    $operation = binding_real_ok(OAI_DB::insert([
        'operation_id'=>'OAI-CI-BIND-'.wp_generate_uuid4(),
        'status'=>'approved','type'=>'elementor_document_save','target'=>(string)$post,
        'before_data'=>wp_json_encode($change['before']),
        'after_data'=>wp_json_encode($change['after']),
        'meta_data'=>wp_json_encode($meta),
        'diff_data'=>'{}','risk'=>'medium',
        'created_at'=>current_time('mysql',true),
        'approved_at'=>current_time('mysql',true),
        'expires_at'=>gmdate('Y-m-d H:i:s',time()+300),
    ]),'Insert disposable persisted operation');
    $preflight = binding_real_ok(OAI_Elementor_Operation_Binding::inspect($operation,'apply',$post),'Preflight apply');
    binding_real_expect(!empty($preflight['preflight_only']) &&
        $preflight['prepared_change']['before']===$change['before'], 'Wrong persisted before-image');
    $denied=OAI_Elementor_Operation_Binding::inspect_ai_authorized($operation,'apply',$post);
    binding_real_expect(is_wp_error($denied) && $denied->get_error_code()==='oai_binding_authorization',
        'AI mutation preflight accepted without scoped lifecycle context');
    wp_set_current_user(0);
    $denied=OAI_Elementor_Operation_Binding::inspect($operation,'apply',$post);
    binding_real_expect(is_wp_error($denied)&&$denied->get_error_code()==='oai_binding_actor',
        'Anonymous actor passed document gate');
    wp_set_current_user(1);
    binding_real_ok(OAI_Elementor::save_document($post,$after),'Simulate concurrent editor');
    $stale=OAI_Elementor_Operation_Binding::inspect($operation,'apply',$post);
    binding_real_expect(is_wp_error($stale) && $stale->get_error_code()==='oai_binding_stale',
        'Old approved snapshot accepted after editor change');

    // This is an isolated DB state fixture, not the authorized apply executor.
    OAI_DB::update($operation,[
        'status'=>'applied','applied_at'=>current_time('mysql',true)
    ]);
    $rollback = binding_real_ok(OAI_Elementor_Operation_Binding::inspect($operation,'rollback',$post),
        'Preflight rollback');
    binding_real_expect($rollback['prepared_change']['before']===$change['before'], 'DB before-image unavailable');
    $concurrent = $after;
    $concurrent[0]['settings']['title']='A newer human edit';
    binding_real_ok(OAI_Elementor::save_document($post,$concurrent),'Simulate post-apply edit');
    $conflict=OAI_Elementor_Operation_Binding::inspect($operation,'rollback',$post);
    binding_real_expect(is_wp_error($conflict)&&$conflict->get_error_code()==='oai_binding_stale',
        'Rollback would overwrite a newer editor');
    binding_real_expect(get_post_status($post)==='draft','Document was published');
    echo "PASS: real WordPress/MySQL Elementor operation binding, actor scope, stale apply/rollback, AI authorization gate\n";
} finally {
    wp_set_current_user(1);
    if($operation) $wpdb->delete(OAI_DB::table(),['id'=>$operation],['%d']);
    wp_delete_post($post,true);
}
