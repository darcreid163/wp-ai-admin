<?php
/**
 * Pure regression tests for the version-gated WoodMart Header Builder adapter.
 * Run: php tests/woodmart-header-adapter.php
 */
namespace XTS\Modules {
    class Header_Builder {
        public static $instance;
        public $factory;
        public $elements;
        public function __construct($factory, $elements) { $this->factory=$factory; $this->elements=$elements; self::$instance=$this; }
        public static function get_instance() { return self::$instance; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    define('WOODMART_VERSION', '8.6.2');
    define('WOODMART_HB_DEFAULT_ID', 'default_header');

    function absint($v){return abs((int)$v);}
    function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
    function current_user_can($cap){return $cap==='manage_options';}
    function get_template(){return 'woodmart';}
    function get_stylesheet(){return 'woodmart-child';}
    function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
    function wp_generate_uuid4(){static $i=0;return 'test-header-id-'.(++$i);}
    function wp_get_nav_menu_object($id){return (int)$id===20?(object)array('term_id'=>20):false;}
    function wp_get_nav_menu_items($id,$args=array()){return (int)$id===20?array((object)array('ID'=>501)):array();}
    function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['test_options'])?$GLOBALS['test_options'][$key]:$default;}
    function add_option($key,$value,$deprecated='',$autoload=null){if(array_key_exists($key,$GLOBALS['test_options']))return false;$GLOBALS['test_options'][$key]=$value;return true;}
    function delete_option($key){unset($GLOBALS['test_options'][$key]);return true;}
    function wp_cache_delete($key,$group=''){return true;}
    function wp_cache_flush_group($group){return true;}
    function is_wp_error($v){return $v instanceof \WP_Error;}

    class WP_Error {
        private $code; private $message; private $data;
        public function __construct($code,$message='',$data=array()){$this->code=$code;$this->message=$message;$this->data=$data;}
        public function get_error_code(){return $this->code;}
        public function get_error_message(){return $this->message;}
        public function get_error_data(){return $this->data;}
    }
    class TestMainmenuElement {
        public function get_args(){
            return array('params'=>array(
                'menu_id'=>array('id'=>'menu_id','type'=>'select','value'=>'','options'=>array('20'=>'WP AI Admin — основное')),
                'menu_align'=>array('id'=>'menu_align','type'=>'selector','value'=>'left','options'=>array('left'=>'Left','center'=>'Center','right'=>'Right')),
                'menu_style'=>array('id'=>'menu_style','type'=>'selector','value'=>'default','options'=>array('default'=>'Default')),
            ));
        }
    }
    class TestElements { public $elements_classes=array(); }
    class TestHeader {
        private $id; private $name; private $structure; private $settings;
        public function __construct($id,$data){$this->id=$id;$this->name=$data['name'];$this->structure=$data['structure'];$this->settings=$data['settings'];}
        public function get_name(){return $this->name;}
        public function get_structure(){return $this->structure;}
        public function get_settings(){return $this->settings;}
        public function set_structure($v){$this->structure=$v;}
        public function set_settings($v){$this->settings=$v;}
    }
    class TestHeaderFactory {
        public function get_header($id){return new TestHeader($id,$GLOBALS['test_headers'][$id]);}
        public function update_header($id,$name,$structure,$settings){$GLOBALS['test_headers'][$id]=array('name'=>$name,'structure'=>$structure,'settings'=>$settings);return true;}
    }
    class OAI_REST {
        public static function same_option_value($a,$b){return $a===$b;}
    }

    $GLOBALS['test_options']=array('whb_saved_headers'=>array('default_header'=>'Default'));
    $GLOBALS['test_headers']=array('default_header'=>array(
        'name'=>'Default',
        'settings'=>array('sticky'=>false),
        'structure'=>array('content'=>array(
            array('id'=>'general-header','type'=>'row','content'=>array(
                array('id'=>'column8','type'=>'column','content'=>array(array('id'=>'logo001','type'=>'logo','params'=>array()))),
                array('id'=>'column9','type'=>'column','content'=>array()),
                array('id'=>'column10','type'=>'column','content'=>array(array('id'=>'account01','type'=>'account','params'=>array()),array('id'=>'cart0001','type'=>'cart','params'=>array()))),
            )),
        )),
    ));
    $factory=new TestHeaderFactory();
    $elements=new TestElements();
    $elements->elements_classes['Mainmenu']=new TestMainmenuElement();
    new \XTS\Modules\Header_Builder($factory,$elements);

    require dirname(__DIR__).'/includes/class-oai-woodmart-header.php';

    function check_header($condition,$message){
        if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
        echo "PASS: {$message}\n";
    }

    $input=array('action'=>'insert_mainmenu','row_id'=>'general-header','column_id'=>'column9','menu_id'=>20,'align'=>'center');
    $plan=OAI_Woodmart_Header::plan('default_header',$input);
    check_header(!is_wp_error($plan),'valid Mainmenu insertion can be planned');
    check_header($plan['before']['structure']===$GLOBALS['test_headers']['default_header']['structure'],'planning does not mutate the live header fixture');
    $planned_content=$plan['after']['structure']['content'][0]['content'][1]['content']??array();
    check_header(count($planned_content)===1 && ($planned_content[0]['type']??'')==='mainmenu','plan inserts Mainmenu only into the approved empty desktop column');
    $meta=$plan['meta'];
    $written=OAI_Woodmart_Header::write('default_header',$plan['after'],$plan['before'],$meta,false);
    if(is_wp_error($written))fwrite(STDERR,'WRITE ERROR: '.$written->get_error_code().' '.$written->get_error_message().' '.wp_json_encode($written->get_error_data())."\\n");
    check_header(!is_wp_error($written),'approved Mainmenu insertion writes through the native Header Factory');
    list($exists,$current)=OAI_Woodmart_Header::current('default_header');
    check_header($exists && OAI_REST::same_option_value($current,$plan['after']),'saved header matches the planned snapshot');

    $rolled=OAI_Woodmart_Header::write('default_header',$plan['before'],$plan['after'],$meta,true);
    check_header(!is_wp_error($rolled),'header rollback restores the exact prior structure');
    list($exists,$restored)=OAI_Woodmart_Header::current('default_header');
    check_header($exists && OAI_REST::same_option_value($restored,$plan['before']),'rollback verifies the original header snapshot');

    $tampered=$plan['after'];
    $tampered['settings']['sticky']=true;
    $tampered['snapshot_sha256']=hash('sha256',wp_json_encode(array_diff_key($tampered,array('snapshot_sha256'=>true)),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $blocked=OAI_Woodmart_Header::write('default_header',$tampered,$plan['before'],$meta,false);
    check_header(is_wp_error($blocked) && $blocked->get_error_code()==='oai_woodmart_header_settings','unapproved header setting changes are rejected');

    $GLOBALS['test_headers']['default_header']['structure']['content'][0]['content'][1]['content']=array(array('id'=>'existing01','type'=>'search','params'=>array()));
    $occupied=OAI_Woodmart_Header::plan('default_header',$input);
    check_header(is_wp_error($occupied) && $occupied->get_error_code()==='oai_woodmart_header_column','non-empty target column is never overwritten');

    echo "All WoodMart Header Builder adapter regression tests passed.\n";
}
