<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight('config', UPDATE);

$rule_id = (int)($_POST['plugin_assetprefixes_prefixrules_id'] ?? 0);
$back    = PluginAssetprefixesPrefixRule::getFormURL() . "?id=$rule_id&forcetab=PluginAssetprefixesPrefixRule$1";

// ---- Adicionar entrada -----------------------------------------------------
if (isset($_POST['add'])) {
  $prefix = trim($_POST['prefix'] ?? '');
  if ($prefix !== '' && $rule_id > 0) {
    $entry = new PluginAssetprefixesPrefixEntry();
    $entry->add([
      'plugin_assetprefixes_prefixrules_id' => $rule_id,
      'prefix'           => $prefix,
      'filter_items_id'  => (int)($_POST['filter_items_id'] ?? 0),
      'per_type_index'   => (int)($_POST['per_type_index'] ?? 0),
      'current_count'    => 0,
    ]);
  }
  Html::redirect($back);
}

// ---- Excluir entrada -------------------------------------------------------
if (isset($_POST['purge'])) {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $entry = new PluginAssetprefixesPrefixEntry();
    $entry->delete(['id' => $id], 1);
  }
  Html::redirect($back);
}

Html::back();
