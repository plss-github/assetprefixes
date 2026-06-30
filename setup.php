<?php

define('PLUGIN_ASSETPREFIXES_VERSION', '1.0.0');
define('PLUGIN_ASSETPREFIXES_MIN_GLPI', '10.0.0');

if (!defined('PLUGINASSETPREFIXES_DIR')) {
  define('PLUGINASSETPREFIXES_DIR', Plugin::getPhpDir('assetprefixes'));
}

function plugin_init_assetprefixes() {
  global $PLUGIN_HOOKS;

  $PLUGIN_HOOKS['csrf_compliant']['assetprefixes'] = true;

  $PLUGIN_HOOKS['config_page']['assetprefixes'] = 'front/prefixrule.php';
  $PLUGIN_HOOKS['menu_toadd']['assetprefixes']  = ['config' => 'PluginAssetprefixesPrefixRule'];

  Plugin::registerClass('PluginAssetprefixesPrefixRule');

  // Hook de criação de ativo para todos os tipos suportados
  $hooks = [];
  foreach (array_keys(PluginAssetprefixesPrefixRule::getSupportedItemtypes()) as $type) {
    $hooks[$type] = ['PluginAssetprefixesPrefixRule', 'applyPrefix'];
  }
  $PLUGIN_HOOKS['item_add']['assetprefixes'] = $hooks;
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
