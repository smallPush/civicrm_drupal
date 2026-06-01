<?php

$databases['default']['default'] = [
  'database' => getenv('DB_NAME') ?: 'drupal_civicrm',
  'username' => getenv('DB_USER') ?: 'drupal',
  'password' => getenv('DB_PASSWORD') ?: 'drupalpassword',
  'host' => getenv('DB_HOST') ?: 'db',
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: 'CHANGE_ME_TO_SOMETHING_RANDOM_AND_SECURE';

$settings['update_free_access'] = FALSE;

$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = 'sites/default/files/private';
$settings['file_temp_path'] = '/tmp';

$settings['config_sync_directory'] = 'sites/default/files/config_sync';

