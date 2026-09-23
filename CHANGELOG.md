# Changelog

All notable changes to this package are recorded here, in Keep a Changelog
order. The format follows Semantic Versioning; a major entry names each removal.

## [Unreleased]

### Added

- `Admin\FieldsUiProvider`: the opt-in fields admin UI. It owns the two
  container seams `Contracts\ControlRegistry` and `Contracts\FieldEditor`, and
  derives every metabox, the classic save entry, the value route with its read
  bindings and the write-failure notice from the host's `Contracts\Panels`
  declaration — attaching nothing when no panels are bound and when the
  declaration is empty. `FieldsProvider` renders nothing and depends on no
  `Admin\` class.
- `Contracts\ControlRegistry`, the field-type to control lookup the editor
  renders through: `control()` refuses loudly, `has()` only reports, and a type
  is named by its enum case or by that case's value. `Admin\FieldTypeRegistry`
  implements it and `Admin\FieldEditor` type-hints it.
- `Contracts\Panels`, the host's declared panel collection, and `FieldPanel`,
  the value that pairs a group with the post type whose edit screen renders it,
  refused with `InvalidPanelDeclaration` when it names no screen.
- `Admin\WriteFailureNoticeRenderer`, the notice's presentation, split from
  `Admin\WriteFailureNotice`, its store.
- `Hooks::ADMIN_NOTICES`, the core hook the queued refusal is surfaced on.
- The integrity core: `FieldWriter::writeGroup()`, the store step of the save
  lifecycle — one transaction for the whole group, the lost-update guard read
  inside it before the first write, and the group's revision mirror written
  last.
- `Contracts\FieldWriter::writeGroup()`, `Contracts\FieldReader::hash()` and
  `Contracts\FieldRegistry::group()`, the three contract additions this slice
  makes.
- `MirrorCodec`: the versioned `{"v":1,"schema":1,"hash":...,"rows":[...]}`
  revision-mirror payload, its sha256 reference hash over the canonical row
  set, and the loud refusal of any malformed, mis-versioned or mis-shaped
  payload.
- `Internal\RevisionMirror`: the mirror's registration through
  `register_meta()` with `revisions_enabled`, its guarded write and its
  read-back, keyed `_mahout_mirror_<groupId>` and never exposed in REST.
- `Internal\GroupSnapshot`: the group's canonical row set read from the
  adapters inside the caller's transaction, one row per Table-bound field in
  declaration order.
- `Internal\RevisionRestorer`: the revision-restore rehydrator at priority
  20, rewriting the group's table rows from the restored mirror — an absent
  row removes, a payload field the group no longer declares is refused.
- `MigrateFieldMetaToTable` and `MigrateFieldTableToMeta`: per-field
  migrations between the storage targets, both directions through the
  adapters, keyset-bounded discovery, one transaction per chunk, and a loud
  refusal of any non-post-context field.
- `Capabilities`, the package's typed capability constants.
- `ConcurrentEditLost`, `GroupNotFound` and `InvalidMirrorPayload`, the three
  new exceptions with private constructors, named constructors and typed
  context getters.

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
