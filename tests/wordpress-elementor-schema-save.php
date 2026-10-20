<?php
/**
 * Real Elementor document round-trip in disposable WordPress.
 * This test loads the staged adapter for evaluation only; it is not an MCP endpoint.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(), 'localhost') === false && strpos(home_url(), '.test') === false)) {
    throw new RuntimeException('Not a disposable WordPress installation');
}
if (!defined('ELEMENTOR_VERSION') || !class_exists('Elementor\\Plugin')) {
    throw new RuntimeException('Elementor is required');
}
require_once dirname(__DIR__).'/includes/class-oai-elementor-schema-save.php';
function oai_elementor_expect($condition,$message) {
    if (!$condition) throw new RuntimeException($message);
}
function oai_elementor_result($value,$message) {
    if (is_wp_error($value)) throw new RuntimeException($message.': '.$value->get_error_code());
    return $value;
}
wp_set_current_user(1);
$id = oai_elementor_result(wp_insert_post([
    'post_type'=>'page','post_title'=>'Disposable Elementor schema/save CI fixture',
    'post_status'=>'draft','post_content'=>''
],true),'Create draft');
$id = absint($id);
$base = [['id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading',
    'settings'=>['title'=>'CI original heading','header_size'=>'h2'],'elements'=>[]]];
$after = [['id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading',
    'settings'=>['title'=>'CI edited heading','header_size'=>'h2'],'elements'=>[]]];
try {
    $schema = oai_elementor_result(OAI_Elementor_Schema_Save::schema('heading'),'Widget schema');
    oai_elementor_expect(($schema['widget_type']??'')==='heading' && !empty($schema['controls']),
        'Elementor heading control schema unavailable');
    oai_elementor_result(OAI_Elementor::save_document($id,$base),'Initial document save');
    $initial = oai_elementor_result(OAI_Elementor_Schema_Save::snapshot($id),'Snapshot');
    oai_elementor_expect($initial['tree']===$base,'Initial Elementor document not persisted');
    $change = oai_elementor_result(OAI_Elementor_Schema_Save::validate_replace(
        $initial['tree'],$after,$initial['sha256']),'Prepare replacement');
    $denied = OAI_Elementor_Schema_Save::apply_approved($id,$change,false);
    oai_elementor_expect(is_wp_error($denied) &&
        $denied->get_error_code()==='oai_approval_required','Missing approval accepted');
    oai_elementor_expect(OAI_Elementor_Schema_Save::snapshot($id)['tree']===$base,
        'Denied change mutated document');
    // Competing editor writes must invalidate the snapshot.
    $concurrent=$base;
    $concurrent[0]['settings']['title']='Another editor';
    oai_elementor_result(OAI_Elementor::save_document($id,$concurrent),'Simulate concurrent save');
    $stale=OAI_Elementor_Schema_Save::apply_approved($id,$change,true);
    oai_elementor_expect(is_wp_error($stale) && $stale->get_error_code()==='oai_stale_target',
        'Stale Elementor snapshot was accepted');
    oai_elementor_result(OAI_Elementor::save_document($id,$base),'Restore fixture snapshot');
    oai_elementor_result(OAI_Elementor_Schema_Save::apply_approved($id,$change,true),
        'Save prepared Elementor document');
    $verify=oai_elementor_result(OAI_Elementor_Schema_Save::verify($id,$after),'Verify document');
    oai_elementor_expect(!empty($verify['ok']),'Saved Elementor tree did not match');
    $rollbackDenied=OAI_Elementor_Schema_Save::rollback_approved($id,$change,false);
    oai_elementor_expect(is_wp_error($rollbackDenied),'Rollback bypassed approval');
    oai_elementor_result(OAI_Elementor_Schema_Save::rollback_approved($id,$change,true),
        'Restore original Elementor tree');
    oai_elementor_expect(!empty(OAI_Elementor_Schema_Save::verify($id,$base)['ok']),
        'Rollback did not restore initial Elementor tree');
    oai_elementor_expect(get_post_status($id)==='draft','Disposable draft unexpectedly published');
    echo "PASS: real Elementor schema, document save, stale guard, verify and rollback\n";
} finally {
    wp_delete_post($id,true);
}
