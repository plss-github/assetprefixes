<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

// Padrão de numeração (pattern + contador) por subtipo dentro de uma família
// de prefixo (PluginAssetprefixesPrefix). `subtype_id = NULL` é o padrão
// global da família, usado quando o ativo não corresponde a nenhum subtipo
// específico configurado.
class PluginAssetprefixesPrefixPattern extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Padrão por subtipo', 'Padrões por subtipo', $nb, 'assetprefixes');
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
        `subtype_id` int unsigned DEFAULT NULL,
        `pattern` varchar(64) COLLATE {$default_collation} NOT NULL DEFAULT '',
        `counter_current` int unsigned NOT NULL DEFAULT '0',
        `date_creation` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `plugin_assetprefixes_prefixes_id` (`plugin_assetprefixes_prefixes_id`),
        KEY `subtype_id` (`subtype_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
      return;
    }

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

  static function uninstall() {
    global $DB;
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
  }

  static function getPatternsForPrefix(int $prefix_id): array {
    global $DB;
    return array_values(iterator_to_array($DB->request([
      'FROM'    => self::getTable(),
      'WHERE'   => ['plugin_assetprefixes_prefixes_id' => $prefix_id],
      'ORDERBY' => ['id ASC'],
    ])));
  }

  // Padrão aplicável para o subtipo do ativo: match exato, senão fallback global (subtype_id NULL)
  static function findApplicablePattern(int $prefix_id, ?int $subtype_id): ?array {
    global $DB;

    $base_where = ['plugin_assetprefixes_prefixes_id' => $prefix_id];

    if (!empty($subtype_id)) {
      $iter = $DB->request([
        'FROM'  => self::getTable(),
        'WHERE' => array_merge($base_where, ['subtype_id' => $subtype_id]),
        'LIMIT' => 1,
      ]);
      if (count($iter)) {
        return $iter->current();
      }
    }

    $iter = $DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => array_merge($base_where, ['subtype_id' => null]),
      'LIMIT' => 1,
    ]);
    return count($iter) ? $iter->current() : null;
  }

  // -------------------------------------------------------------------------
  // Validação (usada por front/prefixpattern.form.php antes do add()/update())
  // -------------------------------------------------------------------------

  static function validatePattern(int $prefix_id, string $pattern, ?int $subtype_id, ?int $exclude_id = null): bool {
    if (strpos($pattern, '0') === false) {
      Session::addMessageAfterRedirect(
        __('O padrão deve conter ao menos um "0" para indicar a máscara de numeração.', 'assetprefixes'),
        false,
        ERROR
      );
      return false;
    }

    global $DB;
    $where = ['plugin_assetprefixes_prefixes_id' => $prefix_id];
    $where[] = empty($subtype_id) ? ['subtype_id' => null] : ['subtype_id' => $subtype_id];
    if ($exclude_id !== null) {
      $where[] = ['NOT' => ['id' => $exclude_id]];
    }
    $iter = $DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1]);
    if (count($iter)) {
      Session::addMessageAfterRedirect(
        __('Já existe um padrão configurado para este subtipo nesta família.', 'assetprefixes'),
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
    $used_subtypes = array_column($patterns, 'subtype_id');

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

      echo "<td style='width:90px;white-space:nowrap'>" . $subtype_label . "</td>";
      echo "<td>";
      PluginAssetprefixesPrefix::showSubtypeMultiselect($itemtype, $used_subtypes);
      echo "</td>";

      echo "<td style='width:90px;white-space:nowrap'>" . __('Padrão', 'assetprefixes');
      echo "<span class='form-help' style='margin-left:3px'
              data-bs-toggle='tooltip'
              data-bs-placement='top'
              data-bs-html='true'
              data-bs-title='" . __('Mesmo padrão e contador serão aplicados a cada subtipo selecionado.', 'assetprefixes') . "'>
            ?
        </span>" . "</td>";
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
        // O subtipo não é editável na linha (mostrado como texto) — carregamos o
        // valor atual num hidden para que o update não o zere pra global.
        echo Html::hidden('subtype_id', ['value' => (int)($p['subtype_id'] ?? 0)]);
        echo "</form>";
      }
    }

    echo "<div class='spaced'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<tr class='headerRow'>";
    echo "<th>" . __('Subtipo', 'assetprefixes') . "</th>";
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

          echo "<td>";
          $subtype_name = self::getSubtypeDisplayName($itemtype, $p['subtype_id'] ?? null);
          echo htmlspecialchars($subtype_name);
          // PluginAssetprefixesPrefix::showSubtypeDropdown($itemtype, (int)($p['subtype_id'] ?? 0), $form_id);
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
          $subtype_name = self::getSubtypeDisplayName($itemtype, $p['subtype_id'] ?? null);
          echo "<td>" . htmlspecialchars($subtype_name) . "</td>";
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
}
