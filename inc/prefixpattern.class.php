<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

// Padrão de numeração (pattern + contador) dentro de uma família de prefixo
// (PluginAssetprefixesPrefix). Um padrão é compartilhado por N subtipos — todos
// avançam o MESMO contador, então dois subtipos com o mesmo prefixo nunca emitem
// números repetidos. O vínculo padrão ↔ subtipo vive em SUBTYPES_TABLE, onde
// `subtype_id = 0` é o "global" da família (fallback para subtipos sem padrão).
class PluginAssetprefixesPrefixPattern extends CommonDBTM {
  static $rightname = 'config';

  const SUBTYPES_TABLE = 'glpi_plugin_assetprefixes_prefixpatterns_subtypes';

  static function getTypeName($nb = 0) {
    return _n('Padrão por subtipo', 'Padrões por subtipo', $nb, 'assetprefixes');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
    $table              = self::getTable();
    $link_table         = self::SUBTYPES_TABLE;

    if (!$DB->tableExists($table)) {
      $DB->doQuery("CREATE TABLE `$table` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_assetprefixes_prefixes_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `pattern` varchar(64) COLLATE {$default_collation} NOT NULL DEFAULT '',
        `counter_current` int unsigned NOT NULL DEFAULT '0',
        `date_creation` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `plugin_assetprefixes_prefixes_id` (`plugin_assetprefixes_prefixes_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    } else {
      // O campo "Ativo" nunca era editável na UI (sempre 1) — removido por não ter efeito prático.
      if ($DB->fieldExists($table, 'is_active')) {
        $migration->dropField($table, 'is_active');
      }

      // Unifica "contador inicial" + "contador atual" num único campo editável —
      // dois números editáveis separadamente podiam ficar incongruentes entre si.
      if ($DB->fieldExists($table, 'counter_start')) {
        foreach ($DB->request(['FROM' => $table]) as $row) {
          if ((int)$row['counter_current'] === 0) {
            $DB->update($table, ['counter_current' => max(0, (int)$row['counter_start'] - 1)], ['id' => $row['id']]);
          }
        }
        $migration->dropField($table, 'counter_start');
      }
    }

    // A chave única (família, subtipo) garante no próprio banco que um subtipo
    // pertence a no máximo um padrão da família — é o que torna a resolução
    // determinística.
    if (!$DB->tableExists($link_table)) {
      $DB->doQuery("CREATE TABLE `$link_table` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_assetprefixes_prefixes_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `plugin_assetprefixes_prefixpatterns_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `subtype_id` int unsigned NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        UNIQUE KEY `prefix_subtype` (`plugin_assetprefixes_prefixes_id`, `subtype_id`),
        KEY `plugin_assetprefixes_prefixpatterns_id` (`plugin_assetprefixes_prefixpatterns_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }

    // Modelo antigo (até 1.2.0): um registro de padrão por subtipo, cada um com
    // o próprio contador. Move o subtipo para a tabela de vínculo e funde os
    // padrões de texto idêntico da mesma família num só (contador compartilhado).
    if ($DB->fieldExists($table, 'subtype_id')) {
      foreach ($DB->request(['FROM' => $table, 'ORDERBY' => ['id ASC']]) as $row) {
        $link = [
          'plugin_assetprefixes_prefixes_id' => (int)$row['plugin_assetprefixes_prefixes_id'],
          'subtype_id'                       => (int)($row['subtype_id'] ?? 0),
        ];
        // Um subtipo repetido na mesma família violaria a chave única; o mais antigo fica.
        if (countElementsInTable($link_table, $link) > 0) {
          continue;
        }
        $DB->insert($link_table, $link + ['plugin_assetprefixes_prefixpatterns_id' => (int)$row['id']]);
      }
      self::mergeDuplicatePatterns();

      $migration->dropKey($table, 'subtype_id');
      $migration->dropField($table, 'subtype_id');
      // Aplica já: hook.php chama installBaseData() de novo depois da migração da
      // família, e a coluna não pode mais existir nesse segundo passe.
      $migration->migrationOneTable($table);
    }
  }

  // Funde padrões com o mesmo texto dentro de uma família. O sobrevivente é o
  // mais antigo e herda o MAIOR contador, para nunca reemitir um número já usado
  // por qualquer um dos padrões fundidos.
  private static function mergeDuplicatePatterns(): void {
    global $DB;

    $table  = self::getTable();
    $groups = [];
    foreach ($DB->request(['FROM' => $table, 'ORDERBY' => ['id ASC']]) as $row) {
      $groups[$row['plugin_assetprefixes_prefixes_id'] . "\0" . $row['pattern']][] = $row;
    }

    foreach ($groups as $rows) {
      if (count($rows) < 2) {
        continue;
      }
      $survivor = array_shift($rows);
      $counter  = (int)$survivor['counter_current'];
      $loser_ids = [];
      foreach ($rows as $row) {
        $counter     = max($counter, (int)$row['counter_current']);
        $loser_ids[] = (int)$row['id'];
      }

      $DB->update($table, ['counter_current' => $counter], ['id' => (int)$survivor['id']]);
      $DB->update(self::SUBTYPES_TABLE, ['plugin_assetprefixes_prefixpatterns_id' => (int)$survivor['id']], ['plugin_assetprefixes_prefixpatterns_id' => $loser_ids]);
      self::repointFields((int)$survivor['plugin_assetprefixes_prefixes_id'], $loser_ids, (int)$survivor['id']);
      $DB->delete($table, ['id' => $loser_ids]);
    }
  }

  // Campos alvo restritos a um padrão fundido passam a apontar pro sobrevivente,
  // sem duplicar um campo que ele já tinha.
  private static function repointFields(int $prefix_id, array $from_ids, int $to_id): void {
    global $DB;

    $field_table = PluginAssetprefixesPrefixField::getTable();
    if (!$DB->tableExists($field_table)) {
      return;
    }
    $seen = [];
    foreach (PluginAssetprefixesPrefixField::getFieldsForPrefix($prefix_id) as $f) {
      if ((int)$f['plugin_assetprefixes_prefixpatterns_id'] === $to_id) {
        $seen[$f['field_name']] = true;
      }
    }
    foreach (PluginAssetprefixesPrefixField::getFieldsForPrefix($prefix_id) as $f) {
      if (!in_array((int)$f['plugin_assetprefixes_prefixpatterns_id'], $from_ids, true)) {
        continue;
      }
      if (isset($seen[$f['field_name']])) {
        $DB->delete($field_table, ['id' => $f['id']]);
        continue;
      }
      $DB->update($field_table, ['plugin_assetprefixes_prefixpatterns_id' => $to_id], ['id' => $f['id']]);
      $seen[$f['field_name']] = true;
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::SUBTYPES_TABLE, true);
    $DB->dropTable(self::getTable());
    return true;
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return Session::haveRight(self::$rightname, READ); }
  static function canUpdate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canPurge(): bool  { return Session::haveRight(self::$rightname, UPDATE); }

  // Campos alvo restritos a este padrão específico não fazem mais sentido sem ele.
  public function cleanDBonPurge() {
    global $DB;
    $DB->delete(PluginAssetprefixesPrefixField::getTable(), [
      'plugin_assetprefixes_prefixpatterns_id' => $this->fields['id'],
    ]);
    $DB->delete(self::SUBTYPES_TABLE, [
      'plugin_assetprefixes_prefixpatterns_id' => $this->fields['id'],
    ]);
  }

  // -------------------------------------------------------------------------
  // Vínculo padrão ↔ subtipos
  // -------------------------------------------------------------------------

  // Substitui o conjunto de subtipos do padrão (0 = global).
  static function linkSubtypes(int $prefix_id, int $pattern_id, array $subtype_ids): void {
    global $DB;

    $DB->delete(self::SUBTYPES_TABLE, ['plugin_assetprefixes_prefixpatterns_id' => $pattern_id]);
    foreach (array_unique(array_map('intval', $subtype_ids)) as $subtype_id) {
      $DB->insert(self::SUBTYPES_TABLE, [
        'plugin_assetprefixes_prefixes_id'       => $prefix_id,
        'plugin_assetprefixes_prefixpatterns_id' => $pattern_id,
        'subtype_id'                             => $subtype_id,
      ]);
    }
  }

  // pattern_id => [subtype_id, ...] de toda a família.
  static function getSubtypeMap(int $prefix_id): array {
    global $DB;
    $map = [];
    foreach ($DB->request([
      'FROM'    => self::SUBTYPES_TABLE,
      'WHERE'   => ['plugin_assetprefixes_prefixes_id' => $prefix_id],
      'ORDERBY' => ['subtype_id ASC'],
    ]) as $row) {
      $map[(int)$row['plugin_assetprefixes_prefixpatterns_id']][] = (int)$row['subtype_id'];
    }
    return $map;
  }

  // Cada padrão da família vem com a chave extra `subtype_ids` (0 = global).
  static function getPatternsForPrefix(int $prefix_id): array {
    global $DB;
    $map      = self::getSubtypeMap($prefix_id);
    $patterns = [];
    foreach ($DB->request([
      'FROM'    => self::getTable(),
      'WHERE'   => ['plugin_assetprefixes_prefixes_id' => $prefix_id],
      'ORDERBY' => ['id ASC'],
    ]) as $row) {
      $row['subtype_ids'] = $map[(int)$row['id']] ?? [];
      $patterns[] = $row;
    }
    return $patterns;
  }

  // Padrão aplicável para o subtipo do ativo: o padrão que contém o subtipo,
  // senão o que contém o global (subtype_id 0).
  static function findApplicablePattern(int $prefix_id, ?int $subtype_id): ?array {
    global $DB;

    $table      = self::getTable();
    $link_table = self::SUBTYPES_TABLE;

    $candidates = empty($subtype_id) ? [0] : [(int)$subtype_id, 0];
    foreach ($candidates as $candidate) {
      $iter = $DB->request([
        'SELECT'     => ["$table.*"],
        'FROM'       => $table,
        'INNER JOIN' => [
          $link_table => [
            'ON' => [$link_table => 'plugin_assetprefixes_prefixpatterns_id', $table => 'id'],
          ],
        ],
        'WHERE'      => [
          "$link_table.plugin_assetprefixes_prefixes_id" => $prefix_id,
          "$link_table.subtype_id"                       => $candidate,
        ],
        'LIMIT'      => 1,
      ]);
      if (count($iter)) {
        return $iter->current();
      }
    }
    return null;
  }

  // -------------------------------------------------------------------------
  // Validação (usada por front/prefixpattern.form.php antes do add()/update())
  // -------------------------------------------------------------------------

  static function validatePattern(int $prefix_id, string $pattern, array $subtype_ids, ?int $exclude_id = null): bool {
    if (strpos($pattern, '0') === false) {
      Session::addMessageAfterRedirect(
        __('O padrão deve conter ao menos um "0" para indicar a máscara de numeração.', 'assetprefixes'),
        false,
        ERROR
      );
      return false;
    }

    if (empty($subtype_ids)) {
      Session::addMessageAfterRedirect(
        __('Selecione ao menos um subtipo para o padrão.', 'assetprefixes'),
        false,
        ERROR
      );
      return false;
    }

    global $DB;

    // Mesmo texto de padrão em dois registros = dois contadores emitindo os
    // mesmos números. Os subtipos devem ser agrupados num único padrão.
    $where = ['plugin_assetprefixes_prefixes_id' => $prefix_id, 'pattern' => $pattern];
    if ($exclude_id !== null) {
      $where[] = ['NOT' => ['id' => $exclude_id]];
    }
    if (count($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1]))) {
      Session::addMessageAfterRedirect(
        sprintf(
          __('O padrão "%s" já existe nesta família — edite-o e adicione os subtipos a ele, para que todos compartilhem o mesmo contador.', 'assetprefixes'),
          $pattern
        ),
        false,
        ERROR
      );
      return false;
    }

    $where = [
      'plugin_assetprefixes_prefixes_id' => $prefix_id,
      'subtype_id'                       => array_map('intval', $subtype_ids),
    ];
    if ($exclude_id !== null) {
      $where[] = ['NOT' => ['plugin_assetprefixes_prefixpatterns_id' => $exclude_id]];
    }
    if (count($DB->request(['FROM' => self::SUBTYPES_TABLE, 'WHERE' => $where, 'LIMIT' => 1]))) {
      Session::addMessageAfterRedirect(
        __('Um dos subtipos selecionados já pertence a outro padrão nesta família.', 'assetprefixes'),
        false,
        ERROR
      );
      return false;
    }

    return true;
  }

  // -------------------------------------------------------------------------
  // Exibição da aba "Padrões por subtipo"
  // -------------------------------------------------------------------------

  static function showForPrefix(PluginAssetprefixesPrefix $prefix): bool {
    $prefix_id     = $prefix->getID();
    $itemtype      = $prefix->fields['itemtype'] ?? '';
    $canedit       = Session::haveRight(self::$rightname, UPDATE);
    $form_url      = Plugin::getWebDir('assetprefixes') . '/front/prefixpattern.form.php';
    $patterns      = self::getPatternsForPrefix($prefix_id);
    $subtype_class = $itemtype ? PluginAssetprefixesPrefix::getSubtypeForItemtype($itemtype) : null;
    $used_subtypes = array_merge([], ...array_column($patterns, 'subtype_ids'));

    if ($canedit) {
      echo "<form action='$form_url' method='post'>";
      echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
      echo Html::hidden('plugin_assetprefixes_prefixes_id', ['value' => $prefix_id]);
      // table-layout:fixed + larguras fixas nos rótulos: trava as colunas para o
      // multiselect de subtipos crescer só na altura, sem esmagar os vizinhos
      // (mesmo tratamento do form de "Campos alvo"). Colunas de campo dividem o resto.
      $subtype_label = $subtype_class ? PluginAssetprefixesPrefix::getSubtypeLabel($itemtype) : __('Subtipo', 'assetprefixes');
      echo "<table class='tab_cadre_fixe' style='table-layout:fixed;width:100%'>";
      echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Adicionar padrão', 'assetprefixes') . "</th></tr>";
      echo "<tr class='tab_bg_2'>";

      echo "<td style='width:90px;white-space:nowrap'>" . $subtype_label;
      echo "<span class='form-help' style='margin-left:3px'
              data-bs-toggle='tooltip'
              data-bs-placement='top'
              data-bs-html='true'
              data-bs-title='" . __('Todos os subtipos selecionados compartilham o mesmo padrão e o mesmo contador.', 'assetprefixes') . "'>
            ?
        </span>" . "</td>";
      echo "<td>";
      PluginAssetprefixesPrefix::showSubtypeMultiselect($itemtype, $used_subtypes);
      echo "</td>";

      echo "<td style='width:90px;white-space:nowrap'>" . __('Padrão', 'assetprefixes') . "</td>";
      echo "<td>";
      echo Html::input('pattern', ['placeholder' => 'NB0000000', 'style' => 'width:100%']);
      echo "</td>";

      echo "</tr><tr class='tab_bg_2'>";
      echo "<td style='width:90px;white-space:nowrap'>" . __('Contador', 'assetprefixes');
      echo "<span class='form-help' style='margin-left:3px'
              data-bs-toggle='tooltip'
              data-bs-placement='top'
              data-bs-html='true'
              data-bs-title='" . __('Último número já usado; o próximo ativo receberá contador + 1.', 'assetprefixes') . "'>
            ?
        </span>" . "</td>";
      echo "<td>";
      echo Html::input('counter', ['value' => 0, 'type' => 'number', 'min' => '0', 'style' => 'width:100%']);
      echo "</td>";
      echo "<td colspan='2' class='text-center'>";
      echo "<button type='submit' name='add' class='btn btn-primary'>";
      echo "<i class='ti ti-plus'></i>" . __("Add") . "</button>";
      echo "</td>";
      echo "</tr></table>";
      Html::closeForm();
    }

    // Um <form> não pode legalmente envolver vários <td> dentro de um <tr> — o
    // parser HTML "expulsa" o form pra fora da tabela (foster parenting) e o
    // submit sai quebrado. Por isso cada linha editável usa um <form> real,
    // declarado FORA da tabela, e os campos se associam a ele via atributo
    // form="..." (suportado nativamente por <input>/<select>/<button>).
    if ($canedit) {
      foreach ($patterns as $p) {
        $form_id = "assetprefixes_pattern_form{$p['id']}";
        echo "<form id='$form_id' action='$form_url' method='post'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('id', ['value' => $p['id']]);
        echo Html::hidden('plugin_assetprefixes_prefixes_id', ['value' => $prefix_id]);
        echo "</form>";
      }
    }

    echo "<div class='spaced'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<tr class='headerRow'>";
    echo "<th>" . __('Subtipos', 'assetprefixes') . "</th>";
    echo "<th>" . __('Padrão', 'assetprefixes') . "</th>";
    echo "<th>" . __('Contador', 'assetprefixes') . "</th>";
    echo "<th>" . __('Próximo valor', 'assetprefixes') . "</th>";
    if ($canedit) echo "<th></th>";
    echo "</tr>";

    if (empty($patterns)) {
      echo "<tr class='tab_bg_1'><td colspan='" . ($canedit ? 5 : 4) . "' class='center'>";
      echo "<em>" . __('Nenhum padrão configurado.', 'assetprefixes') . "</em>";
      echo "</td></tr>";
    } else {
      foreach ($patterns as $p) {
        $next    = PluginAssetprefixesResolver::computeNext((int)$p['counter_current']);
        $preview = PluginAssetprefixesResolver::format($p['pattern'], $next);

        echo "<tr class='tab_bg_1'>";

        if ($canedit) {
          $form_id = "assetprefixes_pattern_form{$p['id']}";
          // Subtipos de OUTROS padrões ficam de fora: cada subtipo pertence a um só padrão.
          $taken = array_diff($used_subtypes, $p['subtype_ids']);

          echo "<td style='min-width:260px'>";
          PluginAssetprefixesPrefix::showSubtypeMultiselect($itemtype, $taken, $p['subtype_ids'], $form_id);
          echo "</td>";
          echo "<td>" . Html::input('pattern', ['value' => $p['pattern'], 'style' => 'width:130px', 'form' => $form_id]) . "</td>";
          echo "<td>" . Html::input('counter', ['value' => (int)$p['counter_current'], 'type' => 'number', 'min' => '0', 'style' => 'width:90px', 'form' => $form_id]) . "</td>";
          echo "<td>" . htmlspecialchars($preview) . "</td>";
          echo "<td class='nowrap'>";
          echo "<button name='update' form='$form_id' type='submit' class='btn btn-icon btn-ghost-secondary' title='" . _sx('button', 'Save') . "'>";
          echo "<i class='ti ti-device-floppy'></i></button>";
          echo "<button name='purge' form='$form_id' type='submit' class='btn btn-icon btn-ghost-danger' title='" . __('Excluir', 'assetprefixes') . "'"
            . " onclick=\"return confirm('" . __('Excluir este padrão?', 'assetprefixes') . "')\">"
            . "<i class='ti ti-trash'></i></button>";
          echo "</td>";
        } else {
          echo "<td>" . htmlspecialchars(self::getSubtypesDisplayName($itemtype, $p['subtype_ids'])) . "</td>";
          echo "<td><strong>" . htmlspecialchars($p['pattern']) . "</strong></td>";
          echo "<td>" . (int)$p['counter_current'] . "</td>";
          echo "<td>" . htmlspecialchars($preview) . "</td>";
        }

        echo "</tr>";
      }
    }

    echo "</table></div>";
    return true;
  }

  static function getSubtypeDisplayName(string $itemtype, ?int $subtype_id): string {
    $subtype_class = $itemtype ? PluginAssetprefixesPrefix::getSubtypeForItemtype($itemtype) : null;
    if (!$subtype_class || empty($subtype_id)) {
      return __('Global (todos os subtipos)', 'assetprefixes');
    }
    $sub = new $subtype_class();
    return $sub->getFromDB($subtype_id) ? $sub->fields['name'] : '#' . $subtype_id;
  }

  static function getSubtypesDisplayName(string $itemtype, array $subtype_ids): string {
    if (empty($subtype_ids)) {
      return '—';
    }
    return implode(', ', array_map(
      fn($id) => self::getSubtypeDisplayName($itemtype, (int)$id),
      $subtype_ids
    ));
  }
}
