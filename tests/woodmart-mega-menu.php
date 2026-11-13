<?php
/**
 * Regression tests for WoodMart mega-menu HTML Block metadata.
 * Run: php tests/woodmart-mega-menu.php
 */
define('ABSPATH', __DIR__);
define('WOODMART_VERSION', '8.6.2');

function absint($v){return abs((int)$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function current_user_can($cap){return in_array($cap,array('manage_options','edit_theme_options'),true);}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_post($id){
    if((int)$id===501)return (object)array('ID'=>501,'post_type'=>'nav_menu_item','post_status'=>'publish','post_title'=>'Mega menu parent');
    if((int)$id===900)return (object)array('ID'=>900,'post_type'=>'cms_block','post_status'=>'publish');
    if((int)$id===901)return (object)array('ID'=>901,'post_type'=>'cms_block','post_status'=>'draft');
    return null;
}
function wp_get_object_terms($id,$taxonomy,$args=array()){return (int)$id===501?array(20):array();}
function metadata_exists($type,$id,$key){return array_key_exists($key,$GLOBALS['test_meta'][(int)$id]??array());}
function get_post_meta($id,$key,$single=false){return $GLOBALS['test_meta'][(int)$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['test_meta'][(int)$id][$key]=$value;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['test_meta'][(int)$id][$key]);return true;}
function clean_post_cache($id){return true;}

class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code,$message='',$data=array()){$this->code=$code;$this->message=$message;$this->data=$data;}
    public function get_error_code(){return $this->code;}
}
class OAI_Verify {
    public static function same($a,$b){return $a===$b;}
}

$GLOBALS['test_meta']=array();
require dirname(__DIR__).'/includes/class-oai-p0.php';

function check_mega($condition,$message){
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
    echo "PASS: {$message}\n";
}

$before=OAI_P0::woodmart_mega_menu_snapshot(501);
check_mega(!is_wp_error($before) && empty($before['design_exists']) && empty($before['block_exists']),'reads an ordinary menu item without inventing mega-menu metadata');

$after=$before;$after['design_exists']=true;$after['design']='full-width';$after['block_exists']=true;$after['block_id']=900;
$write=OAI_P0::write('woodmart_mega_menu','501',$after,array('woodmart_version'=>'8.6.2'));
check_mega(!is_wp_error($write),'assigns a published HTML Block using the WoodMart metadata whitelist');
$current=OAI_P0::woodmart_mega_menu_snapshot(501);
check_mega(OAI_Verify::same($current,$after),'mega-menu metadata verifies after write');

$rollback=OAI_P0::write('woodmart_mega_menu','501',$before,array('woodmart_version'=>'8.6.2'));
check_mega(!is_wp_error($rollback),'rollback restores absent mega-menu metadata');
$restored=OAI_P0::woodmart_mega_menu_snapshot(501);
check_mega(OAI_Verify::same($restored,$before),'rollback verifies the original menu item metadata');

$draft=$after;$draft['block_id']=901;
$blocked=OAI_P0::write('woodmart_mega_menu','501',$draft,array('woodmart_version'=>'8.6.2'));
check_mega(is_wp_error($blocked) && $blocked->get_error_code()==='oai_woodmart_mega_block','draft HTML Blocks cannot be attached to public mega menus');

$invalid=$after;$invalid['design']='unknown';
$blocked_design=OAI_P0::write('woodmart_mega_menu','501',$invalid,array('woodmart_version'=>'8.6.2'));
check_mega(is_wp_error($blocked_design) && $blocked_design->get_error_code()==='oai_woodmart_mega_design','unknown WoodMart dropdown designs are rejected');

echo "All WoodMart mega-menu metadata regression tests passed.\n";
