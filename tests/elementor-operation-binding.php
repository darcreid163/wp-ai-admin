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
$bh=hash('sha256',json_encode($before));
$ah=hash('sha256',json_encode($after));
OAI_DB::$row=[
    'type'=>'elementor_document_save','target'=>'17','status'=>'approved',
    'before_data'=>json_encode($before),'after_data'=>json_encode($after),
    'meta_data'=>json_encode(['site_id'=>'installation-ci','post_id'=>17,'before_sha256'=>$bh,'after_sha256'=>$ah]),
];
$binding='OAI_Elementor_Operation_Binding';
$ok=$binding::inspect(12,'apply',17);
ok(!is_wp_error($ok)&&$ok['preflight_only']===true&&$ok['prepared_change']['after']===$after,'approved binding failed');
fail_code($binding::inspect(12,'rollback',17),'oai_binding_state');
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
$ready=$binding::inspect(12,'rollback',17);
ok(!is_wp_error($ready)&&$ready['action']==='rollback','rollback preflight failed');
OAI_Elementor::$tree[17]=$before;
fail_code($binding::inspect(12,'rollback',17),'oai_binding_stale');
echo "PASS: persisted operation binding, status, target, site, integrity hashes, JSON, stale apply and rollback\n";
