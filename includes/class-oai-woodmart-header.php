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
    foreach(($node['content']??array()) as $index=>&$column){
     if(!is_array($column) || (string)($column['id']??'')!==(string)$column_id)continue;
     if((string)($column['type']??'')!=='column')return;
     if(!empty($column['content']))return;
     $column['content']=array($element);
     $found=true;
     return;
    }
   }
   if(isset($node['content']) && is_array($node['content']))self::insert_into_row($node['content'],$row_id,$column_id,$element,$found);
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
  if(empty($definition['addable']) || empty($definition['params']) || !is_array($definition['params'])){
   return new WP_Error('oai_woodmart_mainmenu_schema','Mainmenu element is not addable or has no parameter schema',array('status'=>409));
  }
  $params=$definition['params'];
  if(!isset($params['menu_id']) || !isset($params['menu_id']['value']))return new WP_Error('oai_woodmart_mainmenu_menu_schema','Mainmenu menu_id parameter is missing',array('status'=>409));
  $menu_options=$params['menu_id']['options']??array();
  if(is_array($menu_options) && $menu_options && !array_key_exists((string)$menu_id,$menu_options) && !in_array((string)$menu_id,$menu_options,true)){
   return new WP_Error('oai_woodmart_mainmenu_menu_option','Selected menu is not in the WoodMart Mainmenu element options',array('status'=>422,'menu_id'=>$menu_id));
  }
  $params['menu_id']['value']=(string)$menu_id;
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
     'woodmart_header_theme_version'=>(string)WOODMART_VERSION,
     'woodmart_header_context_sha256'=>self::context($id),
    ),
   );
  }catch(Throwable $e){
   return new WP_Error('oai_woodmart_header_plan','WoodMart header plan could not be validated',array('status'=>500));
  }
 }

 public static function write($id,$desired,$expected){
  if(!current_user_can('manage_options'))return new WP_Error('oai_woodmart_header_permission','manage_options capability required',array('status'=>403));
  $builder=self::builder();
  if(is_wp_error($builder))return $builder;
  $id=self::header_id($id);
  if(is_wp_error($id))return $id;
  if(!is_array($desired)||!is_array($expected)||!isset($desired['structure'],$desired['settings'],$desired['name']))return new WP_Error('oai_woodmart_header_snapshot','Header snapshots are incomplete',array('status'=>422));
  if(!hash_equals(self::context($id),(string)($desired['context_sha256']??'')) || !hash_equals(self::context($id),(string)($expected['context_sha256']??'')))return new WP_Error('oai_woodmart_header_context','WoodMart theme/header context changed after planning',array('status'=>409));
  $lock_key='oai_wd_header_lock_'.substr(hash('sha256',$id),0,32);
  $lock=array('token'=>wp_generate_uuid4(),'expires'=>time()+120);
  $existing=get_option($lock_key,false);
  if(is_array($existing) && absint($existing['expires']??0)<time())delete_option($lock_key);
  if(!add_option($lock_key,$lock,'','no'))return new WP_Error('oai_woodmart_header_busy','Another header operation is in progress',array('status'=>409));
  global $wpdb;
  try{
   wp_cache_delete('whb_'.$id,'options');
   list($exists,$current)=self::current($id);
   if(!$exists || !OAI_REST::same_option_value($current,$expected))return new WP_Error('oai_woodmart_header_conflict','WoodMart header changed after the sandbox snapshot',array('status'=>409));
   $updated=$builder->factory->update_header($id,(string)$desired['name'],$desired['structure'],$desired['settings']);
   if(is_wp_error($updated))return $updated;
   self::refresh($id);
   list($after_exists,$after)=self::current($id);
   if($after_exists && OAI_REST::same_option_value($after,$desired))return true;
   // Restore only if the current state still belongs to this operation.
   if($after_exists && OAI_REST::same_option_value($after,$desired)){
    $builder->factory->update_header($id,(string)$expected['name'],$expected['structure'],$expected['settings']);
    self::refresh($id);
    return new WP_Error('oai_woodmart_header_verify','Header save did not remain stable; previous header restored',array('status'=>500,'write_committed'=>false));
   }
   return new WP_Error('oai_woodmart_header_verify','Header save could not be verified; current state was preserved for manual inspection',array('status'=>500,'write_committed'=>true));
  }catch(Throwable $e){
   return new WP_Error('oai_woodmart_header_write','WoodMart Header Builder save failed',array('status'=>500,'write_committed'=>true));
  }finally{
   $held=get_option($lock_key,false);
   if(is_array($held) && hash_equals((string)($held['token']??''),(string)$lock['token']))delete_option($lock_key);
   wp_cache_delete('whb_'.$id,'options');
  }
 }

 public static function refresh($id){
  wp_cache_delete('whb_'.sanitize_key((string)$id),'options');
  wp_cache_delete('alloptions','options');
  if(function_exists('wp_cache_flush_group'))wp_cache_flush_group('woodmart');
  return true;
 }
}
