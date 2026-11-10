<?php
require dirname(__DIR__).'/../tests/php/bootstrap.php';
cpic_test_boot_db();
$GLOBALS['cpic_test_roles'] = array(
  'administrator' => array('manage_options'=>true),
  'editor' => array(),
  'author' => array(),
);

function audit_ok($msg){ echo "✓ $msg\n"; }
function audit_fail($msg){ echo "✗ $msg\n"; global $failures; $failures[]=$msg; }
$failures=[];

echo "\n== Full Plugin Audit ==\n";

// 1. Load entry file like WordPress does
define('CPIC_ENTRY_FILE', dirname(__DIR__,2).'/custom-post-image-creator.php');
require CPIC_ENTRY_FILE;
$plugin = cpic();
if($plugin instanceof \CPIC\Core\Plugin) audit_ok("Plugin singleton loads");
else audit_fail("Plugin singleton failed");

// 2. All services resolve
$expected_services = array('migrator','scheduler','capabilities','settings','templates','template_schema','render','render_plan','renderers','render_signature','artifact_manager','brands','accounts','captions','publishers','delivery_manager','queue','job_runner','health','campaign_manager','calendar','analytics','ai','media','admin','editor','rest','events','logger','fonts','dynamic_content','dates','legacy');
foreach($expected_services as $id){
  $svc = $plugin->service($id);
  if(null===$svc) audit_fail("Service $id does not resolve");
  else audit_ok("Service $id resolves as ".(is_object($svc)?get_class($svc):gettype($svc)));
}

// 3. Activation
$hook = null;
$hooks = isset(CPIC_Test_State::$hooks['__activate']) ? (array)CPIC_Test_State::$hooks['__activate'] : array();
foreach($hooks as $entries) foreach((array)$entries as $entry){
  if(isset($entry[0]) && is_array($entry[0]) && isset($entry[0][1]) && 'on_activate'=== $entry[0][1]) $hook=$entry[0];
}
if(null===$hook) audit_fail("No activation hook");
else {
  try { call_user_func($hook); audit_ok("Activation handler completes"); }
  catch(Throwable $e){ audit_fail("Activation threw: ".$e->getMessage()); }
}

// 4. Check tables
global $wpdb;
$tables = array_values(\CPIC\Database\Schema::tables());
$existing=[];
foreach((array)$wpdb->get_results("SELECT name FROM sqlite_master WHERE type='table'") as $row){
  $existing[] = is_array($row)?($row['name']??''):$row->name;
}
foreach($tables as $t){
  if(in_array($t,$existing,true)) audit_ok("Table $t exists");
  else audit_fail("Table $t missing");
}

// 5. Check starter templates
$templates = $plugin->service('template_manager')->all();
audit_ok("Templates count: ".count($templates));
if(count($templates)>=12) audit_ok("12 starter templates present");
else audit_fail("Expected 12 starters, got ".count($templates));

// 6. Check brands
$brand = $plugin->service('brands')->ensure_default();
if($brand) audit_ok("Default brand id $brand");
else audit_fail("Default brand missing");

// 7. Check fonts
$fonts = $plugin->service('fonts');
$font_status = $fonts->status();
if($font_status['ok']) audit_ok("Fonts ok: ".implode(',',$font_status['families']));
else audit_fail("Fonts missing: ".$font_status['missing']);

// 8. Create post and render plan
$post_id=999;
CPIC_Test_State::$posts[$post_id]=cpic_test_make_post(array('ID'=>$post_id,'post_title'=>'বাংলা শিরোনাম পরীক্ষা','post_content'=>'এটি একটি পরীক্ষা কন্টেন্ট।','post_excerpt'=>'সংক্ষিপ্ত'));
$template = $plugin->service('template_manager')->for_post($post_id);
if($template) audit_ok("Template for post resolves: ".$template['name']);
else audit_fail("No template for post");

$plan = $plugin->service('render')->plan($post_id, array());
if(is_wp_error($plan)) audit_fail("Render plan failed: ".$plan->get_error_message());
else {
  audit_ok("Render plan builds: {$plan['width']}x{$plan['height']} layers=".count($plan['layers']));
  if(!empty($plan['layers'])){
    foreach($plan['layers'] as $l){
      if(!isset($l['id'])||!isset($l['type'])) audit_fail("Layer missing id/type");
    }
  }
  // Check complex script detection
  $has_bengali = $plugin->service('render')->plan_has_complex_script($plan);
  audit_ok("Bengali detection: ".($has_bengali?'yes':'no'));
}

// 9. Check delivery creation
$accounts = $plugin->service('accounts');
$acc = $accounts->create(array('provider'=>'telegram','label'=>'Test channel','account_id'=>'-100123','credentials'=>array('bot_token'=>'123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11'),'config'=>array(),'status'=>'connected'));
if(is_wp_error($acc)) audit_fail("Account creation failed: ".$acc->get_error_message());
else audit_ok("Account created id {$acc['id']}");

$delivery = $plugin->service('delivery_manager')->publish_post($post_id, array('destination_ids'=>array((int)$acc['id']),'format'=>'square'));
if(is_wp_error($delivery)){
  $code=$delivery->get_error_code();
  if(in_array($code,array('cpic_browser_only','cpic_complex_script','cpic_no_background_renderer'))) audit_ok("Publish correctly blocked for Bengali background: $code");
  else audit_fail("Publish failed unexpectedly: $code - ".$delivery->get_error_message());
} else {
  audit_ok("Publish run created: run {$delivery['run_id']} deliveries ".count($delivery['deliveries']));
}

// 10. Check REST routes — fire rest_api_init first (RestProvider registers lazily on that hook).
do_action( 'rest_api_init' );
$rest = $plugin->service('rest');
$routes = CPIC_Test_State::$rest_routes ?? [];
audit_ok("REST routes registered: ".count($routes));
$expected_routes = array('/cpic/v1/templates','/cpic/v1/artifacts','/cpic/v1/render','/cpic/v1/jobs','/cpic/v1/publishing','/cpic/v1/accounts','/cpic/v1/campaigns','/cpic/v1/calendar','/cpic/v1/analytics','/cpic/v1/diagnostics');
foreach($expected_routes as $r){
  $found=false;
  foreach($routes as $route=>$handlers){
    if(strpos($route,$r)===0||strpos($route,trim($r,'/'))!==false) {$found=true;break;}
  }
  // Also check via RestApi directly
  if(!$found){
    // try alternative: check if any route contains the last segment
    $seg = basename($r);
    foreach($routes as $route=>$h) if(strpos($route,$seg)!==false) {$found=true;break;}
  }
  if($found) audit_ok("REST route $r present");
  else audit_fail("REST route $r missing");
}

// 11. Check admin screens render without fatal
$admin = $plugin->service('admin');
$screens = array('render_dashboard','render_templates','render_brands','render_accounts','render_campaigns','render_calendar','render_queue','render_analytics','render_settings','render_diagnostics','render_studio');
foreach($screens as $m){
  ob_start();
  try { $admin->$m(); $html=ob_get_clean(); 
    if(strlen($html)<200) audit_fail("Screen $m suspiciously short");
    else if(strpos($html,'Warning:')!==false||strpos($html,'Fatal')!==false) audit_fail("Screen $m leaked PHP warning");
    else audit_ok("Screen $m renders ".strlen($html)." bytes");
  } catch(Throwable $e){ ob_end_clean(); audit_fail("Screen $m threw: ".$e->getMessage()); }
}

// 12. Check plugin activation errors not set when clean
$errors = get_option('cpic_activation_errors');
if(false===$errors) audit_ok("No activation errors lingering");
else audit_fail("Activation errors still set: ".json_encode($errors));

// 13. Check load_failures doesn't recurse
try {
  $f = $plugin->load_failures();
  audit_ok("load_failures returns ".count($f)." items without recursion");
} catch(Throwable $e){ audit_fail("load_failures threw: ".$e->getMessage()); }

echo "\n== Audit Summary ==\n";
if(empty($failures)) echo "ALL CHECKS PASSED\n";
else { echo "FAILURES: ".count($failures)."\n"; foreach($failures as $f) echo " - $f\n"; }
exit(empty($failures)?0:1);
