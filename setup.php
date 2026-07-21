<?php

define('PLUGIN_ASSETPREFIXES_VERSION', '1.0.0');
define('PLUGIN_ASSETPREFIXES_MIN_GLPI', '10.0.0');

if (!defined('PLUGINASSETPREFIXES_DIR')) {
  define('PLUGINASSETPREFIXES_DIR', Plugin::getPhpDir('assetprefixes'));
}

function plugin_init_assetprefixes() {
  global $PLUGIN_HOOKS;

  $PLUGIN_HOOKS['csrf_compliant']['assetprefixes'] = true;

  $PLUGIN_HOOKS['config_page']['assetprefixes'] = 'front/prefix.php';
  $PLUGIN_HOOKS['menu_toadd']['assetprefixes']  = ['config' => 'PluginAssetprefixesPrefix'];

  Plugin::registerClass('PluginAssetprefixesPrefix');
  Plugin::registerClass('PluginAssetprefixesPrefixPattern');
  Plugin::registerClass('PluginAssetprefixesPrefixField');
  Plugin::registerClass('PluginAssetprefixesConfig', ['addtabon' => ['Config']]);

  // Resolução de prefixo: pre_item_add injeta valor nos campos nativos,
  // item_add grava nos campos customizados (que só existem após o insert).
  $pre_add_hooks = [];
  $add_hooks     = [];
  foreach (array_keys(PluginAssetprefixesPrefix::getSupportedItemtypes()) as $type) {
    $pre_add_hooks[$type] = ['PluginAssetprefixesResolver', 'onPreItemAdd'];
    $add_hooks[$type]     = ['PluginAssetprefixesResolver', 'onItemAdd'];
  }
  $PLUGIN_HOOKS['pre_item_add']['assetprefixes'] = $pre_add_hooks;
  $PLUGIN_HOOKS['item_add']['assetprefixes']     = $add_hooks;
}

function plugin_version_assetprefixes() {
  return [
    'name'         => 'Asset Prefixes',
    'version'      => PLUGIN_ASSETPREFIXES_VERSION,
    'author'       => 'Ampris',
    'homepage'     => '',
    'license'      => 'GPLv2+',
    'requirements' => [
      'glpi' => ['min' => PLUGIN_ASSETPREFIXES_MIN_GLPI],
    ],
  ];
}

function plugin_assetprefixes_check_prerequisites() {
  return true;
}
