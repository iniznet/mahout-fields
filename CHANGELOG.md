# Changelog

All notable changes to this package are recorded here, in Keep a Changelog
order. The format follows Semantic Versioning; a major entry names each removal.

## [Unreleased]

### Added

- `Field` and its ten concrete declarations: `TextField`, `TextAreaField`,
  `EmailField`, `UrlField`, `ChoiceField`, `IntegerField`, `DecimalField`,
  `BooleanField`, `DateField` and `RepeaterField`, each final and readonly,
  each carrying its `FieldType`, its sanitisation and its cast.
- `StorageTarget` (`Meta` or `Table`), required on every field with no
  default, and `ObjectContext`, `ObjectKind` and `ObjectRef` for the object a
  field value belongs to.
- `FieldGroup`, the registration unit, and `RegisteredField`, the declared
  field with the *resolved* target attached.
- `Contracts` `FieldRegistry`, `FieldReader` and `FieldWriter`, with the
  concrete registry, reader and writer behind them.
- `Internal` `MetaStorage` and `Internal` `TableStorage`, the two adapters:
  meta's load-with-the-entity reads and the typed value and items tables'
  bounded, keyed writes.
- `RepeaterCodec`: the versioned `{"v":1,"items":[...]}` envelope, its byte
  cap, and the loud refusal of any malformed, mis-shaped or oversized payload.
- `FieldValuesTable` and `FieldItemsTable`, the two table declarations, and
  `CreateFieldValueTable` and `CreateFieldItemTable`, their migrations.
- `FieldsProvider`, the composition-root entry point: the contracts it
  registers, the two migrations and two orphan sources it joins to mahout-db,
  and the `mahout/fields/registry_loaded` action.
- `Hooks`, the package's hook inventory, and the `mahout/fields/storage_target`,
  `mahout/fields/sanitised_value` and `mahout/fields/value` filters with their
  per-field variants.
- `Internal` `PostValueOrphans` and `Internal` `PostItemOrphans`, the post-kind
  orphan sources for both tables.
- The public exception set, every class `final` with a private constructor and
  named constructors carrying typed context.
- The architecture-rule proof fixtures for the field-layer-only meta-access
  rule, beside a clean twin that reads through the field layer.
- The layer-0 suite: every round-trip, repeater, migration, contract and
  lifecycle test passing with no object cache, no page cache and no CDN.
