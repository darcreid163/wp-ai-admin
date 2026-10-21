<?php
/**
 * Exploratory MySQL FOR UPDATE experiment for external Elementor writers.
 * NOT production code. A green run is not proof of general editor safety.
 * Run as two WP-CLI processes only against a disposable .test/localhost WP.
 */
if (getenv('OAI_DISPOSABLE_WP_TEST')!=='1' ||
    (strpos(home_url(),'localhost')===false && strpos(home_url(),'.test')===false))
    throw new RuntimeException('Refusing F3 transaction experiment on a live site');
require_once dirname(__DIR__).'/includes/class-oai-elementor-schema-save.php';
wp_set_current_user(1);
global $wpdb;
$scenario=(string)getenv('OAI_F3_TRANSACTION_SCENARIO');
$phase=(string)getenv('OAI_F3_TRANSACTION_MODE');
$dir=(string)getenv('OAI_F3_TRANSACTION_SYNC');
if (!in_array($scenario,['editor_first','ai_first'],true) || !is_dir($dir) ||
    !in_array($phase,['seed','ai','editor','verify'],true))
    throw new RuntimeException('Missing F3 scenario/sync/phase');
$GLOBALS['oai_f3tx_sync_dir']=$dir;
$option='oai_f3_txn_'. $scenario;
function f3tx_require($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function f3tx_ok($result,$message) {
    if(is_wp_error($result))throw new RuntimeException($message.': '.$result->get_error_code());
    return $result;
}
function f3tx_signal($state) {
    $dir=(string)$GLOBALS['oai_f3tx_sync_dir'];
    f3tx_require(file_put_contents($dir.'/'.$state,'1')===1,'Could not publish signal '.$state);
}
function f3tx_wait($state) {
    $dir=(string)$GLOBALS['oai_f3tx_sync_dir'];
    for($i=0;$i<150;$i++) {
        if(file_exists($dir.'/'.$state))return;
        usleep(100000);
    }
    throw new RuntimeException('Timeout waiting for '.$state);
}
function f3tx_doc($title) {
    return [[
        'id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading',
        'settings'=>['title'=>$title],'elements'=>[]
    ]];
}
function f3tx_lock_and_read($post_id) {
    global $wpdb;
    $table=$wpdb->postmeta;
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT meta_id, meta_value FROM {$table} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE",
        $post_id,'_elementor_data'),ARRAY_A);
    f3tx_require(is_array($rows)&&count($rows)===1,'Expected exactly one persisted Elementor meta row');
    $value=json_decode((string)$rows[0]['meta_value'],true);
    f3tx_require(is_array($value),'Invalid persisted document after row lock');
    return $value;
}
$before=f3tx_doc('F3 original');
$ai=f3tx_doc('F3 AI after checked');
$human=f3tx_doc('F3 independent human');
if($phase==='seed') {
    f3tx_require(!get_option($option,false),'Old F3 transaction fixture exists');
    $post=f3tx_ok(wp_insert_post(['post_type'=>'page','post_status'=>'draft',
        'post_title'=>'Disposable F3 transaction experiment '.$scenario],true),'Create test page');
    try {
        f3tx_ok(OAI_Elementor::save_document($post,$before),'Create original Elementor data');
        f3tx_require(add_option($option,$post,'','no'),'Cannot record temporary test page');
        echo "F3_TX_SEEDED ".$scenario."\n";
    }catch(Throwable $e){wp_delete_post($post,true);throw $e;}
} elseif($phase==='editor') {
    $post=(int)get_option($option,0);
    f3tx_require($post>0,'Missing temporary post');
    f3tx_wait($scenario==='editor_first'?'ai_checked':'ai_locked');
    if ($scenario==='ai_first') {
        // The browser may have loaded the old page while the AI writer owns
        // the transaction row lock. Its later save still carries stale data.
        $read=f3tx_ok(OAI_Elementor_Schema_Save::snapshot($post),
            'Human reads prior committed document while AI holds row lock');
        f3tx_require(OAI_Elementor_Schema_Save::same_tree($read['tree'],$before),
            'Human was not editing a stale original image');
    }
    f3tx_signal('editor_attempting');
    f3tx_ok(OAI_Elementor::save_document($post,$human),'External Elementor document save');
    f3tx_signal('editor_done');
    echo "F3_TX_EDITOR_SAVED ".$scenario."\n";
} elseif($phase==='ai') {
    $post=(int)get_option($option,0);
    f3tx_require($post>0,'Missing temporary post');
    $pre=f3tx_ok(OAI_Elementor_Schema_Save::snapshot($post),'AI reads initial document');
    f3tx_require(OAI_Elementor_Schema_Save::same_tree($pre['tree'],$before),
        'Unexpected preflight baseline');
    if($scenario==='editor_first') {
        f3tx_signal('ai_checked');
        f3tx_wait('editor_done');
    }
    f3tx_require($wpdb->query('START TRANSACTION')!==false,'Cannot start MySQL transaction');
    try {
        $locked=f3tx_lock_and_read($post);
        if($scenario==='editor_first') {
            // External save committed before our locking check: abort.
            f3tx_require(!OAI_Elementor_Schema_Save::same_tree($locked,$before),
                'External save not detected under row lock');
            $wpdb->query('ROLLBACK');
            f3tx_signal('ai_refused');
            echo "F3_TX_AI_REFUSED_STALE ".$scenario."\n";
        } else {
            f3tx_require(OAI_Elementor_Schema_Save::same_tree($locked,$before),
                'AI lock acquired on already changed document');
            f3tx_signal('ai_locked');
            f3tx_wait('editor_attempting');
            usleep(350000);
            f3tx_require(!file_exists($dir.'/editor_done'),
                'External writer completed while AI held the same DB row lock');
            f3tx_ok(OAI_Elementor::save_document($post,$ai),
                'Native Elementor save inside exploratory MySQL row transaction');
            $stored=f3tx_lock_and_read($post);
            f3tx_require(OAI_Elementor_Schema_Save::same_tree($stored,$ai),
                'AI save did not persist the approved target before commit');
            f3tx_require($wpdb->query('COMMIT')!==false,'AI transaction commit failed');
            f3tx_signal('ai_committed');
            echo "F3_TX_AI_COMMITTED_FIRST ".$scenario."\n";
        }
    } catch(Throwable $e) {
        $wpdb->query('ROLLBACK');
        file_put_contents($dir.'/error',(string)$e->getMessage());
        throw $e;
    }
} elseif($phase==='verify') {
    $post=(int)get_option($option,0);
    f3tx_require($post>0,'Missing temporary post');
    try {
        if($scenario==='editor_first') {
            f3tx_require(file_exists($dir.'/ai_refused'),'AI failed to abort after prior human edit');
        } else {
            f3tx_require(file_exists($dir.'/ai_committed'),'AI transaction did not commit');
        }
        f3tx_require(file_exists($dir.'/editor_done'),'External editor was not able to finish');
        clean_post_cache($post);wp_cache_delete($post,'post_meta');
        $live=f3tx_ok(OAI_Elementor_Schema_Save::snapshot($post),'Verify final document');
        f3tx_require(OAI_Elementor_Schema_Save::same_tree($live['tree'],$human),
            'External editor last write not retained');
        if ($scenario==='ai_first') {
            f3tx_require(!OAI_Elementor_Schema_Save::same_tree($live['tree'],$ai),
                'Expected stale later human writer to overwrite AI to demonstrate reverse lost update');
            echo "F3_TX_REVERSE_LOST_UPDATE_REPRODUCED\n";
        }
        f3tx_require(get_post_status($post)==='draft','Experiment published a page');
        echo "F3_TX_EXPERIMENT_PASS ".$scenario."\n";
    } finally {
        wp_delete_post($post,true);
        delete_option($option);
    }
}
