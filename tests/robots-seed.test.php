<?php
declare(strict_types=1);
define('ABSPATH', __DIR__.'/');
define('SMARTCLOUD_STATIC_PUBLISHER_BOOTSTRAPPED', true);
function sanitize_text_field($value): string { return trim((string)$value); }
function sanitize_key($value): string { return (string)$value; }
function sanitize_file_name($value): string { return preg_replace('/[^A-Za-z0-9._-]/','',(string)$value); }
function absint($value): int { return abs((int)$value); }
function esc_url_raw($value, $protocols = null): string { return (string)$value; }
function get_site_url(): string { return $GLOBALS['origin'] ?? 'https://dev.example.test'; }
function home_url($path = ''): string { return get_site_url().$path; }
function wp_normalize_path($value): string { return str_replace('\\','/',(string)$value); }
require dirname(__DIR__).'/smartcloud-static-publisher.php';
$plugin=(new ReflectionClass(\SmartCloud\WPSuite\StaticPublisher\Plugin::class))->newInstanceWithoutConstructor();
if (($argv[1]??'')==='--emit-seeds') {
 $GLOBALS['origin']=$argv[2];echo json_encode($plugin->sanitizeConfig(['seedPaths'=>[]])['seedPaths'],JSON_THROW_ON_ERROR);return;
}
foreach (['https://dev.example.test','https://site.example.test'] as $origin) {
 $GLOBALS['origin']=$origin;
 foreach ([[],['/preferences/','/marketplace/'],['/robots.txt','/preferences/','/robots.txt']] as $seeds) {
  $input=['seedPaths'=>$seeds,'targetOrigin'=>'https://published.example.test'];
  $config=$plugin->sanitizeConfig($input);
  if(count(array_keys($config['seedPaths'],'/robots.txt',true))!==1)throw new RuntimeException('Full publishing must seed robots exactly once');
  foreach($seeds as$seed)if(!in_array($seed,$config['seedPaths'],true))throw new RuntimeException('Existing seed lost');
  if($config['sourceOrigin']!==$origin||$config['targetOrigin']!==$input['targetOrigin'])throw new RuntimeException('Robots seeding changed the publishing origins');
  if($plugin->sanitizeConfig($config)!==$config)throw new RuntimeException('Robots seeding is not idempotent');
 }
}
echo "Publisher robots seed checks PASS: existing/empty lists, both origin forms, no policy override\n";
