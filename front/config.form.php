<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
  Config::setConfigurationValues('plugin_assetprefixes', [
    'check_uniqueness' => (int)($_POST['check_uniqueness'] ?? 0),
    'debug_log'        => (int)($_POST['debug_log'] ?? 0),
  ]);
  Session::addMessageAfterRedirect(__('Configurações salvas.', 'assetprefixes'));
}

Html::back();
