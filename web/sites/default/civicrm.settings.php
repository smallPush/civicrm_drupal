<?php
/**
 * CiviCRM Configuration File
 * Configured to use environment variables for Dokploy deployments.
 */
define('CIVICRM_UF', 'Drupal8');
if (!defined('CIVICRM_UF_BASEURL')) {
  $civicrm_base_url = getenv('CIVICRM_UF_BASEURL');
  if (!$civicrm_base_url && !empty($_SERVER['HTTP_HOST'])) {
    $forwarded_proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $forwarded_proto ? strtok($forwarded_proto, ',') : ($https ? 'https' : 'http');
    $civicrm_base_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/';
  }
  define('CIVICRM_UF_BASEURL', $civicrm_base_url ?: 'http://localhost/');
}
$civicrm_root = '/var/www/html/vendor/civicrm/civicrm-core/';

$civicrm_db_user = getenv('CIVICRM_DB_USER') ?: getenv('DB_USER');
$civicrm_db_password = getenv('CIVICRM_DB_PASSWORD') ?: getenv('DB_PASSWORD');
$civicrm_db_host = getenv('CIVICRM_DB_HOST') ?: getenv('DB_HOST');
$civicrm_db_name = getenv('CIVICRM_DB_NAME') ?: getenv('DB_NAME');

define('CIVICRM_DSN', "mysql://{$civicrm_db_user}:{$civicrm_db_password}@{$civicrm_db_host}/{$civicrm_db_name}?new_link=true");
define('CIVICRM_UF_DSN', "mysql://{$civicrm_db_user}:{$civicrm_db_password}@{$civicrm_db_host}/{$civicrm_db_name}?new_link=true");

define('CIVICRM_SITE_KEY', getenv('CIVICRM_SITE_KEY') ?: 'CHANGE_ME_TO_SOMETHING_SECURE');

$civicrm_setting['core']['civicrm_env'] = 'Production';

global $civicrm_paths;
$civicrm_paths['civicrm.root']['url'] = '/libraries/civicrm/';

if (!defined('CIVICRM_TEMPLATE_COMPILEDIR')) {
  define('CIVICRM_TEMPLATE_COMPILEDIR', '/var/www/html/web/sites/default/files/civicrm/templates_c/');
}

$include_path = '.'           . PATH_SEPARATOR .
                $civicrm_root . PATH_SEPARATOR .
                $civicrm_root . DIRECTORY_SEPARATOR . 'packages' . PATH_SEPARATOR .
                get_include_path();
if (set_include_path($include_path) === false) {
   echo "Could not set the include path<p>";
   exit();
}

require_once 'CRM/Core/ClassLoader.php';
CRM_Core_ClassLoader::singleton()->register();
