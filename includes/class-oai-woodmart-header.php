<?php
/**
 * WoodMart Header Builder adapter.
 *
 * Supports only the tested WoodMart Header Builder API. Header writes must use
 * Header_Factory::update_header() so native validation and style generation run.
 *
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

class OAI_Woodmart_Header {
 const SUPPORTED_THEME_VERSIONS=array('8.6.2');

 private static function builder(){
  if(!defined('WOODMART_VERSION') || !in_array((string)WOODMART_VERSION,self::SUPPORTED_THEME_VERSIONS,true)){
   return new WP_Error('oai_woodmart_header_version','WoodMart Header Builder writes are not enabled for this unverified theme version',array('status'=>409,'theme_version'=>defined('WOODMART_VERSION')?WOODMART_VERSION:null,'supported'=>self::SUPPORTED_THEME_VERSIONS));
  }
  $class='XTS\\Modules\\Header_Builder';
  if(!class_exists($class))return new WP_Error('oai_woodmart_header_unavailable','WoodMart Header Builder is unavailable',array('status'=>503));
  $builder=$class::get_instance();
  if(!is_object($builder) || !is_object($builder->factory??null) || !method_exists($builder->factory,'get_header') || !method_exists($builder->factory,'update_header')){
   return new WP_Error('oai_woodmart_header_api','WoodMart Header Builder factory API is incomplete',array('status'=>503));
  }
  if(!is_object($builder->elements??null) || empty($builder->elements->elements_classes['Mainmenu'])){
   return new WP_Error('oai_woodmart_header_elements','WoodMart Mainmenu element schema is not loaded',array('status'=>503));
  }
  return $builder;
 }

 public static function status(){
  $builder=self::builder();
  $default=defined('WOODMART_HB_DEFAULT_ID')?(string)WOODMART_HB_DEFAULT_ID:'default_header';
  return array(
   'supported'=>!is_wp_error($builder),
   'available'=>!is_wp_error($builder),
   'theme_version'=>defined('WOODMART_VERSION')?(string)WOODMART_VERSION:'',
   'supported_theme_versions'=>self::SUPPORTED_THEME_VERSIONS,
   'default_header_id'=>$default,
   'native_api'=>'Header_Factory::get_header/update_header + Header::save',
   'write_mode'=>'sandbox_only',
   'error'=>is_wp_error($builder)?array('code'=>$builder->get_error_code(),'message'=>$builder->get_error_message()):null,
  );
 }

 private static function header_id($id){
  $id=sanitize_key((string)$id);
  if($id==='' || !preg_match('/^[a-z0-9_-]{1,64}$/',$id))return new WP_Error('oai_woodmart_header_id','Invalid WoodMart header ID',array('status'=>422));
  $headers=get_option('whb_saved_headers',array());
  $default=defined('WOODMART_HB_DEFAULT_ID')?(string)WOODMART_HB_DEFAULT_ID:'default_header';
  if($id!==$default && (!is_array($headers)||!array_key_exists($id,$headers)))return new WP_Error('oai_woodmart_header_not_found','Header ID is not registered in WoodMart Header Builder',array('status'=>404));
  return $id;
 }

 private static function context($id){
  $context=array(
   'header_id'=>(string)$id,
   'theme_version'=>defined('WOODMART_VERSION')?(string)WOODMART_VERSION:'',
   'builder_api'=>'xts-header-factory-v1',
   'default_header_id'=>defined('WOODMART_HB_DEFAULT_ID')?(string)WOODMART_HB_DEFAULT_ID:'default_header',
   'stylesheet'=>get_stylesheet(),
   'template'=>get_template(),
  );
  return hash('sha256',wp_json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 }

 private static function state($id,$header){
  if(!is_object($header) || !method_exists($header,'get_structure') || !method_exists($header,'get_settings') || !method_exists($header,'get_name')){
   return new WP_Error('oai_woodmart_header_state','WoodMart Header object is incomplete',array('status'=>503));
  }
  $structure=$header->get_structure();
  $settings=$header->get_settings();
  if(!is_array($structure)||!is_array($settings))return new WP_Error('oai_woodmart_header_data','WoodMart header structure/settings are not arrays',array('status'=>409));
  $state=array(
   'id'=>(string)$id,
   'name'=>(string)$header->get_name(),
   'structure'=>$structure,
   'settings'=>$settings,
   'context_sha256'=>self::context($id),
  );
  $json=wp_json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  if(!is_string($json) || strlen($json)>180000)return new WP_Error('oai_woodmart_header_size','Header snapshot is too large or cannot be encoded',array('status'=>413));
  $state['snapshot_sha256']=hash('sha256',$json);
  return $state;
 }

 public static function current($id){
  $builder=self::builder();
  if(is_wp_error($builder))return array(false,null);
  $id=self::header_id($id);
  if(is_wp_error($id))return array(false,null);
  try{$header=$builder->factory->get_header($id);$state=self::state($id,$header);}
  catch(Throwable $e){return array(false,null);}
  if(is_wp_error($state))return array(false,null);
  return array(true,$state);
 }

 public static function get($id){
  if(!current_user_can('manage_options'))return new WP_Error('oai_woodmart_header_permission','manage_options capability required',array('status'=>403));
  $builder=self::builder();
  if(is_wp_error($builder))return $builder;
  $id=self::header_id($id);
  if(is_wp_error($id))return $id;
  try{$header=$builder->factory->get_header($id);return self::state($id,$header);}
  catch(Throwable $e){return new WP_Error('oai_woodmart_header_read','WoodMart header could not be read',array('status'=>500));}
 }

 private static function insert_into_row(&$nodes,$row_id,$column_id,$element,&$found){
  if(!is_array($nodes))return;
  foreach($nodes as &$node){
   if(!is_array($node))continue;
   if((string)($node['id']??'')===(string)$row_id && (string)($node['type']??'')==='row'){
    if(!isset($node['content'])||!is_array($node['content']))return;
    foreach($node['content'] as &$column){
     if(!is_array($column) || (string)($column['id']??'')!==(string)$column_id)continue;
     if((string)($column['type']??'')!=='column'){unset($column);return;}
     if(!empty($column['content'])){unset($column);return;}
     $column['content']=array($element);
     $found=true;
     unset($column);
     unset($node);
     return;
    }
    unset($column);
   }
   if(isset($node['content']) && is_array($node['content']))self::insert_into_row($node['content'],$row_id,$column_id,$element,$found);
   if($found){unset($node);return;}
  }
  unset($node);
 }

 private static function element_exists($nodes,$id){
  foreach((array)$nodes as $node){
   if(!is_array($node))continue;
   if((string)($node['id']??'')===(string)$id)return true;
   if(isset($node['content']) && self::element_exists($node['content'],$id))return true;
  }
  return false;
 }

 private static function mainmenu_element($builder,$menu_id,$align){
  $class=$builder->elements->elements_classes['Mainmenu'];
  $definition=$class->get_args();
  if(empty($definition['params']) || !is_array($definition['params'])){
   return new WP_Error('oai_woodmart_mainmenu_schema','Mainmenu element is not addable or has no parameter schema',array('status'=>409));
  }
  $params=$definition['params'];
  if(!isset($params['menu_id']) || !isset($params['menu_id']['value']))return new WP_Error('oai_woodmart_mainmenu_menu_schema','Mainmenu menu_id parameter is missing',array('status'=>409));
  $menu_options=$params['menu_id']['options']??array();
  if(is_array($menu_options) && $menu_options && !array_key_exists((string)$menu_id,$menu_options) && !in_array((string)$menu_id,$menu_options,true)){
   return new WP_Error('oai_woodmart_mainmenu_menu_option','Selected menu is not in the WoodMart Mainmenu element options',array('status'=>422,'menu_id'=>$menu_id));
  }
  $params['menu_id']['value']=(string)$menu_id;
  if(!isset($params['menu_align']))return new WP_Error('oai_woodmart_mainmenu_align','WoodMart Mainmenu element does not expose a supported alignment control',array('status'=>409));
  if(isset($params['menu_align'])){
   $options=$params['menu_align']['options']??array();
   if(is_array($options) && $options && !array_key_exists($align,$options) && !in_array($align,$options,true)){
    return new WP_Error('oai_woodmart_mainmenu_align','Requested menu alignment is not supported by this WoodMart version',array('status'=>422,'alignment'=>$align));
   }
   $params['menu_align']['value']=$align;
  }
  $element_id=substr(md5(wp_generate_uuid4()),0,20);
  return array('id'=>$element_id,'type'=>'mainmenu','params'=>$params);
 }

 public static function plan($id,$input){
  if(!current_user_can('manage_options'))return new WP_Error('oai_woodmart_header_permission','manage_options capability required',array('status'=>403));
  if(!is_array($input) || (string)($input['action']??'')!=='insert_mainmenu')return new WP_Error('oai_woodmart_header_action','Only insert_mainmenu is supported by this planner',array('status'=>422));
  $builder=self::builder();
  if(is_wp_error($builder))return $builder;
  $id=self::header_id($id);
  if(is_wp_error($id))return $id;
  $row_id=sanitize_key((string)($input['row_id']??''));
  $column_id=sanitize_key((string)($input['column_id']??''));
  $menu_id=absint($input['menu_id']??0);
  $align=sanitize_key((string)($input['align']??'center'));
  if($row_id===''||$column_id===''||!$menu_id)return new WP_Error('oai_woodmart_header_input','row_id, column_id and menu_id are required',array('status'=>422));
  $default=defined('WOODMART_HB_DEFAULT_ID')?(string)WOODMART_HB_DEFAULT_ID:'default_header';
  if($id!==$default||$row_id!=='general-header'||$column_id!=='column9')return new WP_Error('oai_woodmart_header_target','Only the inspected empty desktop general-header/column9 target is currently supported',array('status'=>409));
  if(!in_array($align,array('left','center','right'),true))return new WP_Error('oai_woodmart_header_align','Unsupported menu alignment',array('status'=>422));
  $menu=wp_get_nav_menu_object($menu_id);
  if(!$menu)return new WP_Error('oai_woodmart_header_menu','Selected WordPress menu does not exist',array('status'=>404));
  $items=wp_get_nav_menu_items($menu_id,array('post_status'=>'publish'));
  if(!$items)return new WP_Error('oai_woodmart_header_menu_empty','Selected WordPress menu has no published items',array('status'=>409));
  try{
   $header=$builder->factory->get_header($id);
   $before=self::state($id,$header);
   if(is_wp_error($before))return $before;
   $structure=$before['structure'];
   $element=self::mainmenu_element($builder,$menu_id,$align);
   if(is_wp_error($element))return $element;
   if(self::element_exists($structure,$element['id']))return new WP_Error('oai_woodmart_header_element_id','Generated element ID already exists',array('status'=>409));
   $found=false;
   $nodes=isset($structure['content'])&&is_array($structure['content'])?$structure['content']:$structure;
   self::insert_into_row($nodes,$row_id,$column_id,$element,$found);
   if(!$found)return new WP_Error('oai_woodmart_header_column','Target row/column was not found or is not empty; existing header content will not be overwritten',array('status'=>409,'row_id'=>$row_id,'column_id'=>$column_id));
   if(isset($structure['content']))$structure['content']=$nodes;else$structure=$nodes;
   $header->set_structure($structure);
   $after_structure=$header->get_structure();
   if(!is_array($after_structure))return new WP_Error('oai_woodmart_header_validation','WoodMart rejected the proposed header structure',array('status'=>422));
   $after=array(
    'id'=>$id,
    'name'=>(string)$header->get_name(),
    'structure'=>$after_structure,
    'settings'=>$before['settings'],
    'context_sha256'=>self::context($id),
   );
   $after['snapshot_sha256']=hash('sha256',wp_json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
   return array(
    'before'=>$before,
    'after'=>$after,
    'meta'=>array(
     'woodmart_header_action'=>'insert_mainmenu',
     'woodmart_header_row_id'=>$row_id,
     'woodmart_header_column_id'=>$column_id,
     'woodmart_header_menu_id'=>$menu_id,
     'woodmart_header_align'=>$align,
     'woodmart_header_theme_version'=>(string)WOODMART_VERSION,
     'woodmart_header_context_sha256'=>self::context($id),
    ),
   );
  }catch(Throwable $e){
   return new WP_Error('oai_woodmart_header_plan','WoodMart header plan could not be validated',array('status'=>500));
  }
 }

 private static function column_content($nodes,$row_id,$column_id,&$found=false){
  if(!is_array($nodes))return array();
  if(isset($nodes['content'])&&is_array($nodes['content'])&&!isset($nodes['id']))return self::column_content($nodes['content'],$row_id,$column_id,$found);
  foreach($nodes as $node){
   if(!is_array($node))continue;
   if((string)($node['id']??'')===(string)$row_id && (string)($node['type']??'')==='row'){
    foreach(($node['content']??array()) as $column){
     if(is_array($column) && (string)($column['id']??'')===(string)$column_id && (string)($column['type']??'')==='column'){
      $found=true;
      return is_array($column['content']??null)?$column['content']:array();
     }
    }
   }
   if(isset($node['content']) && is_array($node['content'])){
    $value=self::column_content($node['content'],$row_id,$column_id,$found);
    if($found)return $value;
   }
  }
  return array();
 }

 private static function valid_snapshot_hash($state){
  if(!is_array($state)||!isset($state['snapshot_sha256']))return false;
  $copy=$state;unset($copy['snapshot_sha256']);
  $hash=hash('sha256',wp_json_encode($copy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return hash_equals((string)$state['snapshot_sha256'],$hash);
 }

 private static function element_matches_schema($actual,$expected){
  if(!is_array($actual)||!is_array($expected))return false;
  if((string)($actual['id']??'')!==(string)($expected['id']??'') || (string)($actual['type']??'')!==(string)($expected['type']??''))return false;
  $actual_params=$actual['params']??array();$expected_params=$expected['params']??array();
  if(!is_array($actual_params)||!is_array($expected_params))return false;
  if(array_diff(array_keys($actual_params),array_keys($expected_params)) || array_diff(array_keys($expected_params),array_keys($actual_params)))return false;
  foreach($expected_params as $key=>$definition){
   $actual_param=$actual_params[$key]??null;
   if(!is_array($actual_param)||!array_key_exists('value',$definition)||!array_key_exists('value',$actual_param))return false;
   if(!OAI_REST::same_option_value($actual_param['value'],$definition['value']))return false;
  }
  return true;
 }

 private static function validate_transition($expected,$desired,$meta,$restore){
  $base=$restore?$desired:$expected;
  $applied=$restore?$expected:$desired;
  if(!is_array($base)||!is_array($applied))return new WP_Error('oai_woodmart_header_transition','Invalid header transition snapshots',array('status'=>422));
  if(!self::valid_snapshot_hash($base)||!self::valid_snapshot_hash($applied))return new WP_Error('oai_woodmart_header_snapshot_hash','Header snapshot hash is invalid',array('status'=>409));
  if((string)($base['id']??'')!==(string)($applied['id']??'') || (string)($base['name']??'')!==(string)($applied['name']??''))return new WP_Error('oai_woodmart_header_transition','Header identity/name cannot be changed by this operation',array('status'=>422));
  if(!OAI_REST::same_option_value($base['settings']??null,$applied['settings']??null))return new WP_Error('oai_woodmart_header_settings','Header settings cannot be changed by a main-menu insertion operation',array('status'=>422));
  $row_id=sanitize_key((string)($meta['woodmart_header_row_id']??''));
  $column_id=sanitize_key((string)($meta['woodmart_header_column_id']??''));
  $menu_id=absint($meta['woodmart_header_menu_id']??0);
  $align=sanitize_key((string)($meta['woodmart_header_align']??'center'));
  if($row_id!=='general-header'||$column_id!=='column9'||$menu_id<1||!in_array($align,array('left','center','right'),true))return new WP_Error('oai_woodmart_header_meta','Header operation metadata is incomplete or outside the inspected desktop target',array('status'=>422));
  $found=false;
  $content=self::column_content($applied['structure']??array(),$row_id,$column_id,$found);
  if(!$found || count($content)!==1 || (string)($content[0]['type']??'')!=='mainmenu')return new WP_Error('oai_woodmart_header_element','Applied header must contain exactly one Mainmenu element in the approved empty column',array('status'=>422));
  $element=$content[0];
  $params=$element['params']??array();
  $actual_menu=(string)($params['menu_id']['value']??'');
  if($actual_menu!==(string)$menu_id)return new WP_Error('oai_woodmart_header_menu','Mainmenu element does not reference the approved WordPress menu',array('status'=>422));
  $builder=self::builder();if(is_wp_error($builder))return $builder;
  $approved_element=self::mainmenu_element($builder,$menu_id,$align);if(is_wp_error($approved_element))return $approved_element;
  $approved_element['id']=(string)($element['id']??'');
  if(!self::element_matches_schema($element,$approved_element))return new WP_Error('oai_woodmart_header_element_params','Mainmenu element contains parameters outside the approved theme defaults',array('status'=>422));
  $rebuilt=$base['structure']??array();
  $nodes=isset($rebuilt['content'])&&is_array($rebuilt['content'])?$rebuilt['content']:$rebuilt;
  $inserted=false;
  self::insert_into_row($nodes,$row_id,$column_id,$element,$inserted);
  if(!$inserted)return new WP_Error('oai_woodmart_header_column','Approved target column is no longer empty',array('status'=>409));
  if(isset($rebuilt['content']))$rebuilt['content']=$nodes;else$rebuilt=$nodes;
  if(!OAI_REST::same_option_value($rebuilt,$applied['structure']??null))return new WP_Error('oai_woodmart_header_unapproved_diff','Header operation contains changes beyond the approved Mainmenu insertion',array('status'=>422));
  return true;
 }

 public static function write($id,$desired,$expected,$meta=array(),$restore=false){
  if(!current_user_can('manage_options'))return new WP_Error('oai_woodmart_header_permission','manage_options capability required',array('status'=>403));
  $builder=self::builder();
  if(is_wp_error($builder))return $builder;
  $id=self::header_id($id);
  if(is_wp_error($id))return $id;
  if(!is_array($desired)||!is_array($expected)||!isset($desired['structure'],$desired['settings'],$desired['name']))return new WP_Error('oai_woodmart_header_snapshot','Header snapshots are incomplete',array('status'=>422));
  if(!hash_equals(self::context($id),(string)($desired['context_sha256']??'')) || !hash_equals(self::context($id),(string)($expected['context_sha256']??'')))return new WP_Error('oai_woodmart_header_context','WoodMart theme/header context changed after planning',array('status'=>409));
  $transition=self::validate_transition($expected,$desired,$meta,$restore);
  if(is_wp_error($transition))return $transition;
  $lock_key='oai_wd_header_lock_'.substr(hash('sha256',$id),0,32);
  $lock=array('token'=>wp_generate_uuid4(),'expires'=>time()+120);
  $existing=get_option($lock_key,false);
  if(is_array($existing) && absint($existing['expires']??0)<time())delete_option($lock_key);
  if(!add_option($lock_key,$lock,'','no'))return new WP_Error('oai_woodmart_header_busy','Another header operation is in progress',array('status'=>409));
  try{
   self::refresh($id);
   list($exists,$current)=self::current($id);
   if(!$exists || !OAI_REST::same_option_value($current,$expected))return new WP_Error('oai_woodmart_header_conflict','WoodMart header changed after the sandbox snapshot',array('status'=>409));
   try{$updated=$builder->factory->update_header($id,(string)$desired['name'],$desired['structure'],$desired['settings']);}
   catch(Throwable $e){$updated=new WP_Error('oai_woodmart_header_write_exception','WoodMart Header Builder save threw an exception',array('status'=>500));}
   self::refresh($id);
   list($after_exists,$after)=self::current($id);
   if($after_exists && OAI_REST::same_option_value($after,$desired))return true;
   if($after_exists && OAI_REST::same_option_value($after,$expected))return new WP_Error('oai_woodmart_header_write','WoodMart Header Builder did not persist the approved layout',array('status'=>500,'write_committed'=>false,'native_error'=>is_wp_error($updated)?$updated->get_error_code():null));
   return new WP_Error('oai_woodmart_header_verify','Header save produced an unexpected state; automatic overwrite is blocked for manual inspection',array('status'=>409,'write_committed'=>true,'native_error'=>is_wp_error($updated)?$updated->get_error_code():null));
  }finally{
   $held=get_option($lock_key,false);
   if(is_array($held) && hash_equals((string)($held['token']??''),(string)$lock['token']))delete_option($lock_key);
   self::refresh($id);
  }
 }

 public static function refresh($id){
  wp_cache_delete('whb_'.sanitize_key((string)$id),'options');
  wp_cache_delete('alloptions','options');
  if(function_exists('wp_cache_flush_group'))wp_cache_flush_group('woodmart');
  return true;
 }
}
