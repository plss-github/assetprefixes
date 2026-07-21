<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight('config', UPDATE);

$prefix_id = (int)($_POST['plugin_assetprefixes_prefixes_id'] ?? 0);
$back      = PluginAssetprefixesPrefix::getFormURL() . "?id=$prefix_id&forcetab=PluginAssetprefixesPrefix\$1";

if (isset($_POST['add'])) {
  $pattern     = trim($_POST['pattern'] ?? '');
  $counter     = (int)($_POST['counter'] ?? 0);
  $subtype_ids = array_unique(array_map('intval', (array)($_POST['subtype_id'] ?? [])));

  if ($pattern !== '' && $prefix_id > 0 && !empty($subtype_ids)) {
    foreach ($subtype_ids as $subtype_id) {
      $subtype_id = $subtype_id ?: null;
      if (!PluginAssetprefixesPrefixPattern::validatePattern($prefix_id, $pattern, $subtype_id)) {
        continue;
      }
      $entry = new PluginAssetprefixesPrefixPattern();
      $entry->add([
        'plugin_assetprefixes_prefixes_id' => $prefix_id,
        'subtype_id'                       => $subtype_id,
        'pattern'                          => $pattern,
        'counter_current'                  => $counter,
      ]);
    }
  }
  Html::redirect($back);
}

if (isset($_POST['update'])) {
  $id         = (int)($_POST['id'] ?? 0);
  $pattern    = trim($_POST['pattern'] ?? '');
  $subtype_id = (int)($_POST['subtype_id'] ?? 0) ?: null;
  $counter    = (int)($_POST['counter'] ?? 0);

  if ($id > 0 && $pattern !== ''
      && PluginAssetprefixesPrefixPattern::validatePattern($prefix_id, $pattern, $subtype_id, $id)) {
    $entry = new PluginAssetprefixesPrefixPattern();
    $entry->update([
      'id'              => $id,
      'subtype_id'      => $subtype_id,
      'pattern'         => $pattern,
      'counter_current' => $counter,
    ]);
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
