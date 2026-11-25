<?php
/**
 * Deliberate F3 lost-update reproducer on a disposable WordPress installation.
 *
 * This is a NEGATIVE safety test. Passing means the gap is REPRODUCED, not that
 * a concurrent external editor is protected. No production execution.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST') !== '1' ||
    (strpos(home_url(), 'localhost') === false && strpos(home_url(), '.test') === false)) {
    throw new RuntimeException('F3 interleaving fixture refuses non-disposable WordPress');
}
foreach ([
    'class-oai-elementor-schema-save.php',
    'class-oai-elementor-external-edit-guard.php'
] as $file) require_once dirname(__DIR__).'/includes/'.$file;
wp_set_current_user(1);
global $wpdb;
$phase=(string)getenv('OAI_F3_PHASE');
$marker='oai_disposable_f3_state';
$post_option='oai_disposable_f3_post';
function f3_assert($condition,$reason) {
    if (!$condition) throw new RuntimeException($reason);
}
function f3_ok($v,$reason) {
    if (is_wp_error($v)) throw new RuntimeException($reason.': '.$v->get_error_code());
    return $v;
}
function f3_read_state() {
    global $wpdb;
    return (string)$wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
        'oai_disposable_f3_state'
    ));
}
function f3_set_state($state) {
    global $wpdb;
    $rows=$wpdb->update($wpdb->options,['option_value'=>$state],
        ['option_name'=>'oai_disposable_f3_state'],['%s'],['%s']);
    f3_assert($rows===1,'State transfer failed: '.$state);
}
function f3_wait($expected) {
    for($i=0;$i<100;$i++){
        $state=f3_read_state();
        if($state===$expected) return;
        if($state==='error') break;
        usleep(100000);
    }
    throw new RuntimeException('Timed out waiting for '.$expected.'; observed '.f3_read_state());
}
$before=[['id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading',
    'settings'=>['title'=>'Before external edit'],'elements'=>[]]];
$ai=$before;$ai[0]['settings']['title']='AI target after preflight';
$editor=$before;$editor[0]['settings']['title']='Independent editor saved first';
if ($phase==='seed') {
    f3_assert(!get_option($post_option,false),'Previous fixture not cleaned');
    $id=f3_ok(wp_insert_post(['post_type'=>'page','post_status'=>'draft',
        'post_title'=>'Disposable F3 interleaving'],true),'Create fixture page');
    try {
        f3_ok(OAI_Elementor::save_document($id,$before),'Save starting document');
        add_option($post_option,$id,'','no');
        add_option($marker,'seed','','no');
        echo "F3_SEEDED\n";
    } catch(Throwable $e) {
        wp_delete_post($id,true);
        throw $e;
    }
} elseif ($phase==='ai') {
    $id=(int)get_option($post_option,0);
    f3_assert($id>0,'Missing test page');
    $check=f3_ok(OAI_Elementor_External_Edit_Guard::inspect($id,$before),
        'AI preflight should initially pass');
    f3_assert($check['writer_exclusion_guaranteed']===false,
        'Do not misrepresent a WordPress advisory signal as a DB mutex');
    f3_set_state('ai_checked');
    f3_wait('writer_saved');
    // Model the exact time-of-check/time-of-use gap: a client that passed
    // preflight calls the existing Elementor save after another process wrote.
    // This is intentionally not the approved production executor.
    f3_ok(OAI_Elementor::save_document($id,$ai),
        'Simulated AI save after another process has changed the page');
    f3_set_state('ai_saved');
    echo "F3_UNSAFE_RACE_REPRODUCED\n";
} elseif ($phase==='editor') {
    f3_wait('ai_checked');
    $id=(int)get_option($post_option,0);
    f3_assert($id>0,'Missing test page');
    f3_ok(OAI_Elementor::save_document($id,$editor),
        'Independent editor saved after AI preflight');
    f3_set_state('writer_saved');
    echo "F3_EDITOR_SAVED\n";
} elseif ($phase==='verify') {
    $id=(int)get_option($post_option,0);
    f3_assert($id>0,'Missing test page');
    try {
        f3_assert(f3_read_state()==='ai_saved','Workers did not complete ordered interleaving');
        $live=f3_ok(OAI_Elementor_Schema_Save::snapshot($id),'Read last editor');
        f3_assert(OAI_Elementor_Schema_Save::same_tree($live['tree'],$ai),
            'F3 was not reproduced; reassess the interleaving test');
        f3_assert(!OAI_Elementor_Schema_Save::same_tree($live['tree'],$editor),
            'Independent editor content was not overwritten');
        echo "F3_CONFIRMED_UNSAFE_GAP_REMAINS\n";
    } finally {
        wp_delete_post($id,true);
        delete_option($post_option);
        delete_option($marker);
    }
} else throw new RuntimeException('Unknown F3 phase');
