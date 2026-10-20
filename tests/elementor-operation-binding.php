<?php
/** Run: php tests/elementor-operation-binding.php (no WordPress required). */
define('ABSPATH', __DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=array()) {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_option($key,$default=''){return $key==='oai_site_id'?'installation-ci':$default;}
$GLOBALS['oai_actor']=7;
$GLOBALS['oai_can_edit']=true;
$GLOBALS['oai_can_admin']=true;
$GLOBALS['oai_post_state']='draft';
function get_current_user_id(){return $GLOBALS['oai_actor'];}
function current_user_can($cap,...$args){
    if($cap==='edit_post')return $GLOBALS['oai_can_edit'] && ($args[0]??0)===17;
    return $cap==='manage_options' && $GLOBALS['oai_can_admin'];
}
function get_post($id){return $id===17?(object)['ID'=>17,'post_status'=>$GLOBALS['oai_post_state']]:null;}
class OAI_Elementor {
    public static $tree=array(17=>[['id'=>'aabbccdd','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Before'],'elements'=>[]]]);
    public static function get_data($id){return self::$tree[$id] ?? new WP_Error('oai_no_post');}
    public static function widget_schema($type){return ['widget_type'=>$type,'controls'=>[]];}
    public static function decode_create_nodes($nodes){
        if (!is_array($nodes)||!array_is_list($nodes)) return new WP_Error('invalid_nodes');
        foreach($nodes as $node) if(!is_array($node)||array_is_list($node))return new WP_Error('invalid_node');
        return $nodes;
    }
}
class OAI_AI_Lifecycle_Control {
    public static $context = null;
    public static function request_authorized($id, $action) {
        return is_array(self::$context) && self::$context['id']===$id &&
            self::$context['action']===$action && self::$context['issued'] === true;
    }
}
class OAI_DB {
    public static $row;
    public static function get($id){return $id===12?self::$row:null;}
}
require dirname(__DIR__).'/includes/class-oai-elementor-schema-save.php';
require dirname(__DIR__).'/includes/class-oai-elementor-operation-binding.php';
function ok($v,$why){if(!$v)throw new RuntimeException($why);}
function fail_code($v,$code){ok(is_wp_error($v)&&$v->get_error_code()===$code,'Expected '.$code.' got '.(is_wp_error($v)?$v->get_error_code():gettype($v)));}
$before=OAI_Elementor::$tree[17];
$after=$before;$after[0]['settings']['title']='After';
$bh=OAI_Elementor_Schema_Save::tree_hash($before);
$ah=OAI_Elementor_Schema_Save::tree_hash($after);
OAI_DB::$row=[
    'type'=>'elementor_document_save','target'=>'17','status'=>'approved',
    'operation_id'=>'OAI-TEST-12','approved_at'=>'2026-10-20 10:00:00',
    'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),'applied_at'=>null,
    'before_data'=>json_encode($before),'after_data'=>json_encode($after),
    'meta_data'=>json_encode(['site_id'=>'installation-ci','post_id'=>17,'owner_user_id'=>7,'before_sha256'=>$bh,'after_sha256'=>$ah]),
];
$binding='OAI_Elementor_Operation_Binding';
$ok=$binding::inspect(12,'apply',17);
ok(!is_wp_error($ok)&&$ok['preflight_only']===true&&$ok['prepared_change']['after']===$after,'approved binding failed');
fail_code($binding::inspect_ai_authorized(12,'apply',17),'oai_binding_authorization');
OAI_AI_Lifecycle_Control::$context=['id'=>12,'action'=>'apply','issued'=>true];
$authorized=$binding::inspect_ai_authorized(12,'apply',17);
ok(!is_wp_error($authorized) && $authorized['preflight_only']===true,'scoped context preflight failed');
fail_code($binding::inspect_ai_authorized(12,'rollback',17),'oai_binding_authorization');
fail_code($binding::inspect_ai_authorized(13,'apply',17),'oai_binding_authorization');
OAI_AI_Lifecycle_Control::$context=null;
fail_code($binding::inspect(12,'rollback',17),'oai_binding_state');
$GLOBALS['oai_actor']=8;
fail_code($binding::inspect(12,'apply',17),'oai_binding_actor');
$GLOBALS['oai_actor']=7;
$GLOBALS['oai_can_edit']=false;
fail_code($binding::inspect(12,'apply',17),'oai_binding_actor');
$GLOBALS['oai_can_edit']=true;
$GLOBALS['oai_can_admin']=false;
fail_code($binding::inspect(12,'apply',17),'oai_binding_actor');
$GLOBALS['oai_can_admin']=true;
$GLOBALS['oai_post_state']='trash';
fail_code($binding::inspect(12,'apply',17),'oai_binding_post');
$GLOBALS['oai_post_state']='draft';
OAI_DB::$row['approved_at']=null;
fail_code($binding::inspect(12,'apply',17),'oai_binding_approval');
OAI_DB::$row['approved_at']='2026-10-20 10:00:00';
OAI_DB::$row['expires_at']=gmdate('Y-m-d H:i:s',time()-300);
fail_code($binding::inspect(12,'apply',17),'oai_binding_expired');
OAI_DB::$row['expires_at']=gmdate('Y-m-d H:i:s',time()+3600);
fail_code($binding::inspect(12,'apply',18),'oai_binding_target');
fail_code($binding::inspect(13,'apply',17),'oai_binding_not_found');
fail_code($binding::inspect(12,'approve',17),'oai_binding_arguments');
OAI_DB::$row['status']='pending';
fail_code($binding::inspect(12,'apply',17),'oai_binding_state');
OAI_DB::$row['status']='approved';
OAI_DB::$row['type']='option_update';
fail_code($binding::inspect(12,'apply',17),'oai_binding_target');
OAI_DB::$row['type']='elementor_document_save';
$meta=json_decode(OAI_DB::$row['meta_data'],true);$meta['site_id']='other-tenant';
OAI_DB::$row['meta_data']=json_encode($meta);
fail_code($binding::inspect(12,'apply',17),'oai_binding_site');
$meta['site_id']='installation-ci';$meta['post_id']=18;
OAI_DB::$row['meta_data']=json_encode($meta);
fail_code($binding::inspect(12,'apply',17),'oai_binding_site');
$meta['post_id']=17;$meta['after_sha256']=str_repeat('f',64);
OAI_DB::$row['meta_data']=json_encode($meta);
fail_code($binding::inspect(12,'apply',17),'oai_binding_digest');
$meta['after_sha256']=$ah;
OAI_DB::$row['meta_data']=json_encode($meta);
OAI_DB::$row['before_data']='not json';
fail_code($binding::inspect(12,'apply',17),'oai_binding_snapshot');
OAI_DB::$row['before_data']=json_encode($before);
OAI_Elementor::$tree[17][0]['settings']['title']='Someone else edited';
fail_code($binding::inspect(12,'apply',17),'oai_binding_stale');
OAI_Elementor::$tree[17]=$after;OAI_DB::$row['status']='applied';
fail_code($binding::inspect(12,'rollback',17),'oai_binding_unapplied');
OAI_DB::$row['applied_at']='2026-10-20 10:05:00';
$ready=$binding::inspect(12,'rollback',17);
ok(!is_wp_error($ready)&&$ready['action']==='rollback','rollback preflight failed');
OAI_Elementor::$tree[17]=$before;
fail_code($binding::inspect(12,'rollback',17),'oai_binding_stale');
echo "PASS: persisted operation binding, status, target, site, integrity hashes, JSON, stale apply and rollback\n";
