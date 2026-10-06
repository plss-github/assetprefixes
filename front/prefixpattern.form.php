<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight('config', UPDATE);

$prefix_id = (int)($_POST['plugin_assetprefixes_prefixes_id'] ?? 0);
$back      = PluginAssetprefixesPrefix::getFormURL() . "?id=$prefix_id&forcetab=PluginAssetprefixesPrefix\$1";

// Multiselect de subtipos: 0 = global. O hidden vazio que o GLPI emite pra
// seleção vazia chega como "" e é descartado aqui.
$subtype_ids = array_values(array_unique(array_map(
  'intval',
  array_filter((array)($_POST['subtype_id'] ?? []), fn($v) => $v !== '')
)));

if (isset($_POST['add'])) {
  $pattern = trim($_POST['pattern'] ?? '');
  $counter = (int)($_POST['counter'] ?? 0);

  if ($pattern !== '' && $prefix_id > 0
      && PluginAssetprefixesPrefixPattern::validatePattern($prefix_id, $pattern, $subtype_ids)) {
    $entry = new PluginAssetprefixesPrefixPattern();
    if ($pattern_id = $entry->add([
      'plugin_assetprefixes_prefixes_id' => $prefix_id,
      'pattern'                          => $pattern,
      'counter_current'                  => $counter,
    ])) {
      PluginAssetprefixesPrefixPattern::linkSubtypes($prefix_id, (int)$pattern_id, $subtype_ids);
    }
  }
  Html::redirect($back);
}

if (isset($_POST['update'])) {
  $id      = (int)($_POST['id'] ?? 0);
  $pattern = trim($_POST['pattern'] ?? '');
  $counter = (int)($_POST['counter'] ?? 0);

  if ($id > 0 && $pattern !== ''
      && PluginAssetprefixesPrefixPattern::validatePattern($prefix_id, $pattern, $subtype_ids, $id)) {
    $entry = new PluginAssetprefixesPrefixPattern();
    if ($entry->update([
      'id'              => $id,
      'pattern'         => $pattern,
      'counter_current' => $counter,
    ])) {
      PluginAssetprefixesPrefixPattern::linkSubtypes($prefix_id, $id, $subtype_ids);
    }
  }
  Html::redirect($back);
}

if (isset($_POST['purge'])) {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $entry = new PluginAssetprefixesPrefixPattern();
    $entry->delete(['id' => $id], 1);
  }
  Html::redirect($back);
}

Html::back();
