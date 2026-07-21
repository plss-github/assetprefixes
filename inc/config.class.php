<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginAssetprefixesConfig extends CommonGLPI {

  static function getTypeName($nb = 0) {
    return __('Asset Prefixes', 'assetprefixes');
  }

  static function getIcon() {
    return 'ti ti-hash';
  }

  public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item instanceof Config) {
      // 4º arg (ícone) usado pelo GLPI 11; GLPI 10 ignora args extras.
      return self::createTabEntry(self::getTypeName(), 0, null, self::getIcon());
    }
    return '';
  }

  public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item instanceof Config) {
      self::showForm();
    }
    return true;
  }

  // -------------------------------------------------------------------------
  // Leitura / escrita de configuração
  // -------------------------------------------------------------------------

  static function getDefaults(): array {
    return [
      'check_uniqueness' => 0,
    ];
  }

  static function getConfig(): array {
    return Config::getConfigurationValues('plugin_assetprefixes', array_keys(self::getDefaults()));
  }

  static function setDefaults(): void {
    $existing = Config::getConfigurationValues('plugin_assetprefixes', array_keys(self::getDefaults()));
    $toSet    = [];
    foreach (self::getDefaults() as $key => $default) {
      if (!array_key_exists($key, $existing) || $existing[$key] === '') {
        $toSet[$key] = $default;
      }
    }
    if (!empty($toSet)) {
      Config::setConfigurationValues('plugin_assetprefixes', $toSet);
    }
  }

  static function removeConfig(): void {
    $config = new Config();
    $config->deleteByCriteria(['context' => 'plugin_assetprefixes']);
  }

  // Flag opcional (spec §10): validar unicidade do valor gerado antes de gravar.
  static function checkUniquenessEnabled(): bool {
    return (bool)(self::getConfig()['check_uniqueness'] ?? false);
  }

  // -------------------------------------------------------------------------
  // Formulário
  // -------------------------------------------------------------------------

  static function showForm(): void {
    $config   = self::getConfig();
    $form_url = Plugin::getWebDir('assetprefixes') . '/front/config.form.php';

    echo "<form action='$form_url' method='post'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

    echo "<table class='tab_cadre_fixe'>";
    echo "<tr class='tab_bg_1'><th colspan='2'>" . self::getTypeName() . "</th></tr>";
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Validar unicidade do valor gerado antes de gravar', 'assetprefixes') . "</td>";
    echo "<td>";
    Dropdown::showYesNo('check_uniqueness', (int)($config['check_uniqueness'] ?? 0));
    echo "&nbsp;<small class='text-muted'>" . __('Avança o contador se o valor já existir em um campo nativo alvo (não verifica campos customizados).', 'assetprefixes') . "</small>";
    echo "</td>";
    echo "</tr>";
    echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
    echo "<button type='submit' name='update' class='btn btn-primary' title='" . _sx('button', 'Save') . "'>";
    echo "<i class='ti ti-device-floppy'></i><span class='d-none d-xxl-block'>" . _sx('button', 'Save') . "</span></button>";
    echo "</td></tr>";
    echo "</table>";
    Html::closeForm();
  }
}
