<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight('config', UPDATE);

$prefix_id = (int)($_POST['plugin_assetprefixes_prefixes_id'] ?? 0);
// $2 = aba "Campos alvo" (esta); $1 seria "Padrões por subtipo".
$back      = PluginAssetprefixesPrefix::getFormURL() . "?id=$prefix_id&forcetab=PluginAssetprefixesPrefix\$2";

if (isset($_POST['add'])) {
  // Cada opção do dropdown unificado vem codificada como "native:<coluna>"
  // ou "custom:<containers_id>:<coluna>" — separa o tipo do valor real gravado.
  $selections = array_unique(array_filter(array_map('trim', (array)($_POST['field_name'] ?? []))));
  $pattern_id = (int)($_POST['plugin_assetprefixes_prefixpatterns_id'] ?? 0) ?: null;

  if ($prefix_id > 0 && !empty($selections)) {
    $existing = [];
    foreach (PluginAssetprefixesPrefixField::getFieldsForPrefix($prefix_id) as $f) {
      $existing[($f['plugin_assetprefixes_prefixpatterns_id'] ?? 0) . ':' . $f['field_name']] = true;
    }

    foreach ($selections as $selection) {
      [$field_type, $field_name] = array_pad(explode(':', $selection, 2), 2, null);
      if (!in_array($field_type, ['native', 'custom'], true) || $field_name === null || $field_name === '') {
        continue;
      }
      if (isset($existing[($pattern_id ?? 0) . ':' . $field_name])) {
        continue;
      }
      $field = new PluginAssetprefixesPrefixField();
      $field->add([
        'plugin_assetprefixes_prefixes_id'       => $prefix_id,
        'plugin_assetprefixes_prefixpatterns_id' => $pattern_id,
        'field_type'                             => $field_type,
        'field_name'                             => $field_name,
      ]);
    }
  }
  Html::redirect($back);
}

if (isset($_POST['purge'])) {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $field = new PluginAssetprefixesPrefixField();
    $field->delete(['id' => $id], 1);
  }
  Html::redirect($back);
}

Html::back();
