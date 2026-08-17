# Asset Prefixes — Sequential Asset Numbering Plugin for GLPI

The **Asset Prefixes** plugin automatically assigns sequential, numbered prefixes to
assets at creation time. You define a numbering pattern (e.g. `NB0000000`) and a counter,
and every new asset of the matching type/subtype gets the next value written into one or
more of its fields — native fields or, when the [Fields](https://github.com/pluginsGLPI/fields)
plugin is installed, custom fields.

Example: pattern `NB0000000` with counter `103` → the next Notebook created gets `NB0000104`.

---

## Features

- **Prefix families** — one family per asset type (Computer, Monitor, Network device,
  Peripheral, Phone, Printer) scoped to an entity (with sub-entity inheritance)
- **Patterns per subtype** — inside a family, define a numbering pattern + counter per
  asset subtype (e.g. one pattern for *Notebook*, another for *Desktop*), plus an optional
  **global** pattern used as fallback when the asset's subtype has no specific pattern
- **Configurable pattern mask** — the contiguous run of `0`s defines the zero-padding
  width; text before/after the mask is preserved (`NB0000000` → `NB` + 7-digit number)
- **Target fields** — choose which field(s) receive the generated value, from a single
  grouped dropdown mixing **native** text fields and **custom** fields (Fields plugin)
- **Field scoping** — a target field can apply to the whole family, or be restricted to a
  specific subtype pattern (different fields per subtype)
- **Translated field labels** — native fields are listed using GLPI's own translated
  labels (e.g. *Inventory number* instead of `otherserial`)
- **Concurrency-safe counter** — numbers are issued inside a transaction with
  `SELECT ... FOR UPDATE`, preventing duplicates on simultaneous creation
- **Optional uniqueness check** — when enabled, the counter advances past any value that
  already exists in a native target field (native fields only)
- **Multi-entity** — families respect entity assignment and recursion

---

## Requirements

| Dependency | Version |
|---|---|
| GLPI | 10.0.x **and** 11.0.x |
| PHP | ≥ 8.1 |
| [Fields](https://github.com/pluginsGLPI/fields) plugin | Optional — only needed to target custom fields |

---

## Installation

1. Download the plugin archive and extract it to `<glpi>/marketplace/assetprefixes/`
2. In GLPI go to **Setup → Plugins**, find *Asset Prefixes* and click **Install**, then **Enable**

---

## Configuration

Configuration lives under **Setup → Dropdowns → Asset Prefixes** (menu entry *Asset
Prefixes*). Global options are under **Setup → General → Asset Prefixes**.

### 1 — Create a prefix family

A family groups the numbering rules for a single asset type within an entity.

| Field | Description |
|---|---|
| Name | Descriptive label |
| Active | Enable/disable the whole family |
| Entity / Sub-entities | Scope; *Sub-entities* = yes makes it apply to child entities too |
| Asset type | Computer, Monitor, Network device, Peripheral, Phone or Printer (locked after creation) |

### 2 — Patterns per subtype

Open the family and go to the **Patterns by subtype** tab.

- Select one or more subtypes (or **Global — all subtypes**) and define a **pattern** and
  a starting **counter**; one row is created per selected subtype
- **Counter** is the last number already issued — the next asset receives *counter + 1*
- Each row is editable inline (pattern / counter) and shows a live **Next value** preview
- Resolution order for a new asset: exact subtype match first, then the global pattern

### 3 — Target fields

Go to the **Target fields** tab.

- **Apply to** — the whole family (all patterns), or a specific subtype pattern
- **Fields** — a grouped multi-select listing native text fields and (if the Fields plugin
  is installed) compatible custom fields (`text` / `textarea` / `richtext` only)
- The generated value is written to every applicable field on creation

### 4 — Global options

Under **Setup → General → Asset Prefixes**:

| Setting | Description |
|---|---|
| Validate uniqueness | If enabled, advances the counter when the generated value already exists in a native target field (does not check custom fields) |
| Diagnostic logging | If enabled, writes one line per created asset to **Administration → Logs** (service `plugins`) showing the family, pattern and fields written. For troubleshooting only — failures are always logged regardless of this setting |

---

## How it works

```
Asset created (e.g. a Computer)
    │
    └─ pre_item_add hook
           │
           ├─ Find active family for (asset type + entity, respecting recursion)
           │       │ none → stop
           │       ▼
           ├─ Find applicable pattern (asset subtype → exact, else global)
           │       │ none → stop
           │       ▼
           ├─ Issue next number  (SELECT ... FOR UPDATE → counter + 1 → format with mask)
           │       │ (optional uniqueness: skip values already used in native fields)
           │       ▼
           ├─ Write value into NATIVE target fields ($item->input)
           │
           └─ Write value into $item->input[<custom column>] as well, so the Fields
              plugin persists it itself if its own pre_item_add hook runs after ours
    │
    └─ item_add hook (+ register_shutdown_function)
           │
           └─ Write value into CUSTOM target fields (Fields plugin), after all other
              plugins' item_add hooks, so the value is not overwritten
```

### Custom fields (Fields plugin)

Custom fields are provided exclusively by the third-party
[Fields](https://github.com/pluginsGLPI/fields) plugin — GLPI core has no equivalent for
the legacy asset types this plugin targets. The integration is best-effort and guarded:
if the Fields plugin is absent, custom-field options simply don't appear and asset
creation is never blocked. Only free-text field types (`text`, `textarea`, `richtext`) are
offered, since dropdown/number/date fields have their own value formats.

> **Note on read-only custom fields:** a read-only field is rendered with the `readonly`
> attribute, so the creation form still submits it — empty. The Fields plugin picks that
> empty value up in `PluginFieldsContainer::preItem()` → `populateData()` (which reads
> `$item->input[<column>]` and does *not* filter read-only fields) and writes it in its own
> `item_add` hook. Two independent mechanisms make sure our value wins regardless of the
> order in which GLPI loads the two plugins:
>
> 1. `pre_item_add` also injects the value into `$item->input[<column>]`. If the Fields
>    hook runs after ours, Fields persists our value natively, with proper history.
> 2. `item_add` schedules a deferred write via `register_shutdown_function()`, which runs
>    after all synchronous processing. This covers the case where the Fields hook ran
>    first. It writes through the Fields-generated class when available, and falls back to
>    a direct `UPDATE`/`INSERT` on the block table otherwise (same value, no history entry).

### Troubleshooting

Any failure of the custom-field write is reported in three places: a session warning, the
`files/_log/assetprefixes.log` file, and **Administration → Logs** (service `plugins`),
with the concrete reason (block removed, generated class missing, incompatible field type,
write rejected…). The last channel is the one to use on hosted environments with no shell
access. Enabling *Diagnostic logging* additionally traces the successful path, so you can
tell "no family matched" apart from "the write failed".

---

## Notes & design decisions

- **Burned numbers / gaps:** if an asset insert fails after a number was issued, that
  number is skipped. Gaps are accepted by design (simpler and safe).
- **Counter is the source of truth:** a single editable *Counter* field per pattern
  (last-issued number) avoids the inconsistency of separate start/current values.

---

## Authors

- Ampris

## License

GPL v2+
