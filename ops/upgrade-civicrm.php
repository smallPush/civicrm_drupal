<?php
/** Run with Drush php:script after Drupal updatedb and a verified backup. */
if (!defined('CIVICRM_UPGRADE_ACTIVE')) {
  define('CIVICRM_UPGRADE_ACTIVE', 1);
}
\Drupal::service('civicrm')->initialize();
$databaseVersion = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Domain', 1, 'version');
$codeVersion = CRM_Utils_System::version();
if (version_compare($databaseVersion, $codeVersion, '<')) {
  // Follow cv upgrade:db's console queue flow. The legacy Headless runner claims
  // items under LOCK TABLES, which conflicts with lazy entity metadata queries.
  $upgrade = new CRM_Upgrade_Form();
  $blockers = $upgrade->getUpgradeBlockers($databaseVersion, $codeVersion);
  if ($blockers) {
    throw new RuntimeException(implode(PHP_EOL, array_map('strip_tags', $blockers)));
  }
  CRM_Core_DAO::dropTriggers();
  $messageFile = CRM_Utils_File::tempnam('civicrm-post-upgrade');
  $queue = CRM_Upgrade_Form::buildQueue($databaseVersion, $codeVersion, $messageFile);
  $context = new CRM_Queue_TaskContext();
  $context->queue = $queue;
  $context->log = Log::singleton('display');
  while ($queue->numberOfItems()) {
    $item = $queue->stealItem();
    if (!$item->data->run($context)) {
      throw new RuntimeException('CiviCRM upgrade task failed: ' . $item->data->title);
    }
    $queue->deleteItem($item);
  }
  CRM_Upgrade_Form::doFinish();
  unlink($messageFile);
  echo 'CiviCRM upgraded to ' . $codeVersion . PHP_EOL;
}
else {
  echo 'CiviCRM database already current: ' . $databaseVersion . PHP_EOL;
}
