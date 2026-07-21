<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginAssetprefixesPrefixField extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Campo alvo', 'Campos alvo', $nb, 'assetprefixes');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
    $table              = self::getTable();

    if (!$DB->tableExists($table)) {
      $DB->doQuery("CREATE TABLE `$table` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_assetprefixes_prefixes_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `plugin_assetprefixes_prefixpatterns_id` int unsigned DEFAULT NULL,
        `field_type` enum('native','custom') COLLATE {$default_collation} NOT NULL DEFAULT 'native',
        `field_name` varchar(255) COLLATE {$default_collation} NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `plugin_assetprefixes_prefixes_id` (`plugin_assetprefixes_prefixes_id`),
        KEY `plugin_assetprefixes_prefixpatterns_id` (`plugin_assetprefixes_prefixpatterns_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
      return;
    }

    // Permite restringir um campo alvo a um padrão (subtipo) específico da
    // família, em vez de sempre valer pra família inteira.
    if (!$DB->fieldExists($table, 'plugin_assetprefixes_prefixpatterns_id')) {
      $migration->addField($table, 'plugin_assetprefixes_prefixpatterns_id', 'int unsigned DEFAULT NULL');
      $migration->addKey($table, 'plugin_assetprefixes_prefixpatterns_id');
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

  // Todos os campos alvo configurados para a família (usado na aba/listagem).
  static function getFieldsForPrefix(int $prefix_id): array {
    global $DB;
    return array_values(iterator_to_array($DB->request([
      'FROM'    => self::getTable(),
      'WHERE'   => ['plugin_assetprefixes_prefixes_id' => $prefix_id],
      'ORDERBY' => ['id ASC'],
    ])));
  }

  // Campos aplicáveis no momento da resolução: os da família inteira (padrão
  // NULL) + os restritos especificamente ao padrão que casou com o ativo.
  static function getApplicableFields(int $prefix_id, ?int $pattern_id): array {
    global $DB;
    $where = ['plugin_assetprefixes_prefixes_id' => $prefix_id];
    $where[] = empty($pattern_id)
      ? ['plugin_assetprefixes_prefixpatterns_id' => null]
      : ['OR' => [
          ['plugin_assetprefixes_prefixpatterns_id' => null],
          ['plugin_assetprefixes_prefixpatterns_id' => $pattern_id],
        ]];
    return array_values(iterator_to_array($DB->request([
      'FROM'    => self::getTable(),
      'WHERE'   => $where,
      'ORDERBY' => ['id ASC'],
    ])));
  }

  // Opções agrupadas (nativos/customizados) pro multiselect nativo do GLPI —
  // Dropdown::showFromArray cria <optgroup> automaticamente quando o valor é
  // um array aninhado. "value" codifica a origem: "native:<coluna>" ou
  // "custom:<containers_id>:<coluna>".
  static function getAvailableFieldOptions(string $itemtype): array {
    global $DB;

    $options = [];

    // Campos técnicos que não fazem sentido como alvo de substituição de prefixo.
    $blacklist = [
      'id', 'entities_id', 'is_recursive', 'is_deleted', 'is_dynamic', 'is_template',
      'template_name', 'date_mod', 'date_creation', 'uuid',
    ];

    $table  = getTableForItemType($itemtype);
    $fields = $table ? $DB->listFields($table) : [];

    // Rótulos traduzidos (na língua da sessão) para cada coluna, vindos dos
    // search options nativos do GLPI — o "value" salvo continua sendo a coluna.
    $labels = [];
    foreach (Search::getOptions($itemtype) as $opt) {
      if (is_array($opt) && ($opt['table'] ?? '') === $table && isset($opt['field'])) {
        $labels[$opt['field']] = $opt['name'];
      }
    }

    $native_options = [];
    foreach ($fields as $name => $meta) {
      if (in_array($name, $blacklist, true)) {
        continue;
      }
      if (substr($name, -3) === '_id' || substr($name, -4) === '_ids') {
        continue;
      }
      if (!preg_match('/^(varchar|char|text)/', $meta['Type'] ?? '')) {
        continue;
      }
      $native_options['native:' . $name] = $labels[$name] ?? $name;
    }
    asort($native_options);

    // Integração best-effort com o plugin Fields (glpi-project/fields). Só
    // tipos de texto livre fazem sentido como alvo — dropdown/glpi_item/number/
    // date/yesno têm formato de valor próprio e quebrariam se recebessem o padrão gerado.
    $custom_options = [];
    if ($DB->tableExists('glpi_plugin_fields_containers') && $DB->tableExists('glpi_plugin_fields_fields')) {
      $iter = $DB->request([
        'FROM'  => 'glpi_plugin_fields_containers',
        'WHERE' => ['is_active' => 1],
      ]);
      foreach ($iter as $container) {
        $itemtypes = json_decode((string)$container['itemtypes'], true) ?: [];
        if (!in_array($itemtype, $itemtypes, true)) {
          continue;
        }

        $field_iter = $DB->request([
          'FROM'  => 'glpi_plugin_fields_fields',
          'WHERE' => [
            'plugin_fields_containers_id' => $container['id'],
            'is_active'                   => 1,
            'type'                        => ['text', 'textarea', 'richtext'],
          ],
        ]);
        foreach ($field_iter as $field) {
          $label = ($container['label'] ?: $container['name']) . ' — ' . ($field['label'] ?: $field['name']);
          $custom_options['custom:' . $container['id'] . ':' . $field['name']] = $label;
        }
      }
    }
    asort($custom_options);

    if (!empty($native_options)) {
      $options[__('Campos nativos', 'assetprefixes')] = $native_options;
    }
    if (!empty($custom_options)) {
      $options[__('Campos customizados', 'assetprefixes')] = $custom_options;
    }

    return $options;
  }

  // -------------------------------------------------------------------------
  // Exibição da aba "Campos alvo"
  // -------------------------------------------------------------------------

  static function showForPrefix(PluginAssetprefixesPrefix $prefix): bool {
    $prefix_id = $prefix->getID();
    $itemtype  = $prefix->fields['itemtype'] ?? '';
    $canedit   = Session::haveRight(self::$rightname, UPDATE);
    $form_url  = Plugin::getWebDir('assetprefixes') . '/front/prefixfield.form.php';
    $fields    = self::getFieldsForPrefix($prefix_id);
    $patterns  = PluginAssetprefixesPrefixPattern::getPatternsForPrefix($prefix_id);

    // Rótulo de cada padrão (id => "Subtipo — Padrão"), reaproveitado no
    // dropdown de "Aplicar a" e na coluna "Aplica a" da listagem abaixo.
    $pattern_labels = [];
    foreach ($patterns as $p) {
      $pattern_labels[$p['id']] = PluginAssetprefixesPrefixPattern::getSubtypeDisplayName($itemtype, $p['subtype_id'] ?? null) . ' — ' . $p['pattern'];
    }

    if ($canedit) {
      echo "<form action='$form_url' method='post'>";
      echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
      echo Html::hidden('plugin_assetprefixes_prefixes_id', ['value' => $prefix_id]);
      // Layout horizontal (tudo numa linha), mas com table-layout:fixed + larguras
      // explícitas nas colunas: assim as colunas ficam travadas e o multiselect,
      // ao ganhar muitas tags, cresce só na ALTURA da célula — sem roubar largura
      // e esmaçar os vizinhos (que era o problema com o layout auto). As duas
      // colunas de campo (sem largura fixa) dividem o espaço restante meio a meio.
      echo "<table class='tab_cadre_fixe' style='table-layout:fixed;width:100%'>";
      echo "<tr class='tab_bg_1'><th colspan='5'>" . __('Adicionar campo alvo', 'assetprefixes') . "</th></tr>";
      echo "<tr class='tab_bg_2'>";

      echo "<td style='width:110px;white-space:nowrap'>" . __('Aplicar a', 'assetprefixes');
      echo "<span class='form-help' style='margin-left:3px'
              data-bs-toggle='tooltip'
              data-bs-placement='top'
              data-bs-html='true'
              data-bs-title='" . __('Restrinja a um padrão específico pra usar campos diferentes por subtipo.', 'assetprefixes') . "'>
            ?
        </span>" . "</td>";
      echo "<td>";
      Dropdown::showFromArray('plugin_assetprefixes_prefixpatterns_id', [0 => __('Toda a família (todos os padrões)', 'assetprefixes')] + $pattern_labels, [
        'value'               => 0,
        'display_emptychoice' => false,
        'width'               => '100%',
      ]);
      echo "</td>";

      echo "<td style='width:80px'>" . __('Campos', 'assetprefixes') . "</td>";
      echo "<td>";
      Dropdown::showFromArray('field_name', self::getAvailableFieldOptions($itemtype), [
        'multiple'            => true,
        'display_emptychoice' => false,
        'width'               => '100%',
      ]);
      echo "</td>";

      echo "<td style='width:130px' class='center'>";
      echo "<button type='submit' name='add' class='btn btn-primary'>";
      echo "<i class='ti ti-plus'></i>" . __("Add") . "</button>";
      echo "</td>";
      echo "</tr></table>";
      Html::closeForm();
    }

    echo "<div class='spaced'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<tr class='headerRow'>";
    echo "<th>" . __('Origem', 'assetprefixes') . "</th>";
    echo "<th>" . __('Campo', 'assetprefixes') . "</th>";
    echo "<th>" . __('Aplica a', 'assetprefixes') . "</th>";
    if ($canedit) echo "<th></th>";
    echo "</tr>";

    if (empty($fields)) {
      echo "<tr class='tab_bg_1'><td colspan='" . ($canedit ? 4 : 3) . "' class='center'>";
      echo "<em>" . __('Nenhum campo alvo configurado.', 'assetprefixes') . "</em>";
      echo "</td></tr>";
    } else {
      foreach ($fields as $f) {
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . ($f['field_type'] === 'custom'
          ? __('Campo customizado', 'assetprefixes')
          : __('Campo nativo', 'assetprefixes')) . "</td>";
        echo "<td>" . htmlspecialchars(self::describeFieldName($itemtype, $f)) . "</td>";
        $pattern_id = $f['plugin_assetprefixes_prefixpatterns_id'] ?? null;
        echo "<td>" . htmlspecialchars(empty($pattern_id)
          ? __('Toda a família', 'assetprefixes')
          : ($pattern_labels[$pattern_id] ?? __('Padrão removido', 'assetprefixes'))
        ) . "</td>";

        if ($canedit) {
          echo "<td class='nowrap'>";
          echo "<form style='display:inline' action='$form_url' method='post'>";
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('id', ['value' => $f['id']]);
          echo Html::hidden('plugin_assetprefixes_prefixes_id', ['value' => $prefix_id]);
          echo "<button name='purge' type='submit' class='btn btn-icon btn-ghost-danger' title='" . __('Excluir', 'assetprefixes') . "'"
            . " onclick=\"return confirm('" . __('Excluir este campo alvo?', 'assetprefixes') . "')\">"
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

  // Rótulo amigável para exibição na listagem (campos customizados são
  // gravados como "<glpi_plugin_fields_containers.id>:<coluna>")
  private static function describeFieldName(string $itemtype, array $field): string {
    if ($field['field_type'] !== 'custom') {
      $table = getTableForItemType($itemtype);
      foreach (Search::getOptions($itemtype) as $opt) {
        if (is_array($opt) && ($opt['table'] ?? '') === $table && ($opt['field'] ?? '') === $field['field_name']) {
          return $opt['name'];
        }
      }
      return $field['field_name'];
    }

    global $DB;
    [$containers_id, $column] = array_pad(explode(':', $field['field_name'], 2), 2, null);
    if (!$containers_id || !$column || !$DB->tableExists('glpi_plugin_fields_fields')) {
      return $field['field_name'];
    }

    $iter = $DB->request([
      'FROM'  => 'glpi_plugin_fields_fields',
      'WHERE' => ['plugin_fields_containers_id' => (int)$containers_id, 'name' => $column],
      'LIMIT' => 1,
    ]);
    if (count($iter)) {
      $row = $iter->current();
      return $row['label'] ?: $column;
    }
    return $column;
  }
}
