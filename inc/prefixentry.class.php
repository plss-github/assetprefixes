<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginAssetprefixesPrefixEntry extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Entrada de prefixo', 'Entradas de prefixo', $nb, 'assetprefixes');
  }

  static function installBaseData(Migration $migration) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_assetprefixes_prefixrules_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `prefix` varchar(100) COLLATE {$default_collation} NOT NULL DEFAULT '',
        `filter_items_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `per_type_index` int NOT NULL DEFAULT '0',
        `current_count` int NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `plugin_assetprefixes_prefixrules_id` (`plugin_assetprefixes_prefixrules_id`),
        KEY `filter_items_id` (`filter_items_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return Session::haveRight(self::$rightname, READ); }
  static function canPurge(): bool  { return Session::haveRight(self::$rightname, UPDATE); }

  static function getEntriesForRule(int $rule_id): array {
    global $DB;
    return array_values(iterator_to_array($DB->request([
      'FROM'    => self::getTable(),
      'WHERE'   => ['plugin_assetprefixes_prefixrules_id' => $rule_id],
      'ORDERBY' => ['id ASC'],
    ])));
  }

  // Incrementa current_count e retorna o número a ser usado
  static function consumeIndex(int $entry_id, int $per_type_index, int $current_count): int {
    global $DB;
    $number = $per_type_index + $current_count;
    $DB->update(self::getTable(), ['current_count' => $current_count + 1], ['id' => $entry_id]);
    return $number;
  }

  static function showForRule(PluginAssetprefixesPrefixRule $rule): bool {
    global $DB;

    $rule_id  = $rule->getID();
    $itemtype = $rule->fields['itemtype'] ?? '';
    $canedit  = Session::haveRight(self::$rightname, UPDATE);
    $form_url = Plugin::getWebDir('assetprefixes') . '/front/prefixentry.form.php';
    $entries  = self::getEntriesForRule($rule_id);

    $subtype_class = PluginAssetprefixesPrefixRule::getSubtypeForItemtype($itemtype);

    // ---- Formulário de adicionar entrada -----------------------------------
    if ($canedit) {
      echo "<form action='$form_url' method='post'>";
      echo Html::hidden('_glpi_csrf_token',                       ['value' => Session::getNewCSRFToken()]);
      echo Html::hidden('plugin_assetprefixes_prefixrules_id',    ['value' => $rule_id]);
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='tab_bg_1'><th colspan='5'>" . __('Adicionar prefixo', 'assetprefixes') . "</th></tr>";
      echo "<tr class='tab_bg_2'>";

      echo "<td>" . __('Prefixo', 'assetprefixes') . "</td>";
      echo "<td>" . Html::input('prefix', ['style' => 'width:120px', 'placeholder' => 'PC-']) . "</td>";

      echo "<td>" . ($subtype_class
        ? PluginAssetprefixesPrefixRule::getSubtypeLabel($itemtype)
        : __('Filtro', 'assetprefixes')) . "</td>";
      echo "<td>";
      if ($subtype_class) {
        Dropdown::show($subtype_class, [
          'name'                => 'filter_items_id',
          'value'               => 0,
          'display_emptychoice' => true,
          'emptylabel'          => __('Qualquer tipo', 'assetprefixes'),
        ]);
      } else {
        echo "<em class='text-muted'>" . __('Sem filtro disponível', 'assetprefixes') . "</em>";
        echo Html::hidden('filter_items_id', ['value' => 0]);
      }
      echo "</td>";

      echo "<td style='white-space:nowrap'>";
      echo "<label class='me-2'>" . __('Índice inicial', 'assetprefixes') . "</label>";
      echo Html::input('per_type_index', [
        'value'       => 0,
        'type'        => 'number',
        'min'         => '0',
        'style'       => 'width:80px',
        'title'       => __('0 = sem numeração', 'assetprefixes'),
      ]);
      echo "&nbsp;<small class='text-muted'>" . __('(0 = sem número)', 'assetprefixes') . "</small>";
      echo "</td>";

      echo "<td>";
      echo "<button type='submit' name='add' class='btn btn-primary btn-sm'>";
      echo "<i class='ti ti-plus'></i></button>";
      echo "</td>";
      echo "</tr></table>";
      Html::closeForm();
    }

    // ---- Lista de entradas -------------------------------------------------
    echo "<div class='spaced'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<tr class='headerRow'>";
    echo "<th>" . __('Prefixo', 'assetprefixes') . "</th>";
    echo "<th>" . __('Filtro por tipo', 'assetprefixes') . "</th>";
    echo "<th>" . __('Índice inicial', 'assetprefixes') . "</th>";
    echo "<th>" . __('Contador atual', 'assetprefixes') . "</th>";
    if ($canedit) echo "<th></th>";
    echo "</tr>";

    if (empty($entries)) {
      echo "<tr class='tab_bg_1'><td colspan='" . ($canedit ? 5 : 4) . "' class='center'>";
      echo "<em>" . __('Nenhum prefixo configurado.', 'assetprefixes') . "</em>";
      echo "</td></tr>";
    } else {
      foreach ($entries as $e) {
        $filter_name = '—';
        if ($subtype_class && $e['filter_items_id'] > 0) {
          $sub = new $subtype_class();
          $filter_name = $sub->getFromDB($e['filter_items_id']) ? $sub->fields['name'] : '#' . $e['filter_items_id'];
        } elseif ($e['filter_items_id'] == 0) {
          $filter_name = '<em>' . __('Qualquer tipo', 'assetprefixes') . '</em>';
        }

        $index_display = $e['per_type_index'] > 0
          ? $e['per_type_index'] . ' <small class="text-muted">(próximo: ' . ($e['per_type_index'] + $e['current_count']) . ')</small>'
          : '<em>' . __('Sem numeração', 'assetprefixes') . '</em>';

        echo "<tr class='tab_bg_1'>";
        echo "<td><strong>" . htmlspecialchars($e['prefix']) . "</strong></td>";
        echo "<td>" . $filter_name . "</td>";
        echo "<td>" . $index_display . "</td>";
        echo "<td>" . (int)$e['current_count'] . "</td>";

        if ($canedit) {
          echo "<td class='nowrap'>";
          echo "<form style='display:inline' action='$form_url' method='post'>";
          echo Html::hidden('_glpi_csrf_token',                    ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('id',                                   ['value' => $e['id']]);
          echo Html::hidden('plugin_assetprefixes_prefixrules_id', ['value' => $rule_id]);
          echo "<button name='purge' type='submit' class='btn btn-icon btn-ghost-danger' title='" . __('Excluir', 'assetprefixes') . "'"
            . " onclick=\"return confirm('" . __('Excluir este prefixo?', 'assetprefixes') . "')\">"
            . "<i class='ti ti-trash'></i></button>";
          Html::closeForm();
          echo "</td>";
        }

        echo "</tr>";
      }
    }

    echo "</table></div>";
    return true;
  }
}
