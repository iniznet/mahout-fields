# Contracts

`src/Contracts/` is the package's entire public API and the surface semantic
versioning governs: a change to an interface here is a contract change and is
published as a major. Everything under `src/Internal/` is `@internal` and may
change in a patch release.

## Interfaces

### `Contracts\\FieldRegistry`

The declaration registry. A consumer registers field groups through it and
resolves registered fields through it; every cross-field rule runs once, at
registration, so a future construction site inherits the rules by registering
instead of copying checks.

```php
interface FieldRegistry
{
    public function register(FieldGroup $group): void;
    public function has(string $fieldId): bool;
    public function resolve(string $fieldId): RegisteredField;
    public function field(string $fieldId): Field;
    public function group(string $groupId): FieldGroup;
    /** @return list<FieldGroup> */
    public function groups(): array;
}
```

| | |
|---|---|
| Role | owns the declared fields and the resolved storage target of each |
| Implementation in this package | `FieldRegistry` |
| Throws | `FieldNotFound` for an unknown id, `GroupNotFound` for an unknown group id, `GroupAlreadyRegistered`, `DuplicateFieldId`, `InvalidStorageCombination`, `InvalidFieldId` |
| Emitted hook | `mahout/fields/group_registered` per group, `mahout/fields/registry_loaded` with the registry on boot |

An id answers to exactly one field across every group. A queried repeater may
not bind `Meta`; an option-context field may not bind `Table`.

### `Contracts\\FieldReader`

The read path. A registered field is read through this contract and never
through a raw `get_post_meta()`, which is the rule that makes the storage
target invisible at every call site.

```php
interface FieldReader
{
    public function value(string $fieldId, ObjectRef $object): string|int|float|bool|null;
    /** @return list<string|int|float|bool|null>|list<array<string, string|int|float|bool>> */
    public function items(string $fieldId, ObjectRef $object): array;
    public function hash(string $groupId, ObjectRef $object): string;
}
```

| | |
|---|---|
| Role | resolves the field, reads the declared storage, casts to the field's PHP shape |
| Implementation in this package | `FieldReader` |
| Throws | `FieldNotFound`, `InvalidFieldContext` when the object's context is not the group's, `InvalidRepeaterPayload` for a broken envelope |
| Filters | `mahout/fields/value` and its per-field variant, both scalar-only |

### `Contracts\FieldReader::hash()`

The hash an editor form carries: the group's stored mirror hash, or the empty
row set's hash when no mirror exists yet. Reading it back from the stored
payload — never recomputing it from the table — is what makes an out-of-band
table write a lost update instead of a blessed one. It is the `$expectedHash`
argument of `FieldWriter::writeGroup()`.

### `Contracts\\FieldWriter`

The write path. Sanitisation happens here, once, before storage — never in the
consumer.

```php
interface FieldWriter
{
    /** @param list<string|int|float|bool> $items the raw item values */
    public function setItems(string $fieldId, ObjectRef $object, array $items): void;
    public function set(string $fieldId, ObjectRef $object, string|int|float|bool|null $value): void;
    public function delete(string $fieldId, ObjectRef $object): void;

    /**
     * @param array<string, string|int|float|bool|list<string|int|float|bool>|null> $values field id to raw value, or raw item list for a repeater
     * @return string the mirror hash after the write
     * @throws ConcurrentEditLost when the expected hash does not match
     */
    public function writeGroup(string $groupId, ObjectRef $object, array $values, string $expectedHash): string;
}
```

| | |
|---|---|
| Role | sanitises once, stores on the declared target, removes on `null`; a write that touches a Table-bound field joins the group's revision mirror into the same transaction |
| Implementation in this package | `FieldWriter` |
| Throws | `InvalidFieldValue` for a refused value, `RepeaterTooLarge` above the declared cap, `InvalidFieldWrite` for a shape mismatch, `ConcurrentEditLost` on a lost update |
| Emitted hooks | `mahout/fields/before_save`, `mahout/fields/after_save` carrying the raw caller value |

### `Contracts\FieldWriter::writeGroup()`

The store step of the save lifecycle, and the unit the guards call:

```php
$newHash = $writer->writeGroup(
    'series_credits',
    ObjectRef::post($postId),
    ['series_order' => 3, 'series_items' => ['paperback', 'hardcover']],
    $expectedHash,
);
```

The whole group is one transaction: the guard reads the group's mirror hash
after the transaction opens and before the first write, `ConcurrentEditLost`
rolls everything back when the form's hash is stale, and the mirror is
written last — so a forced mirror failure leaves the tables unchanged, and
nothing is partially applied, substituted or retried. A submitted id the
group does not declare is a mass-assignment attempt and is refused with
`FieldNotFound`; nothing is written.

The save lifecycle's guards — autosave, revision, foreign form, post lock,
capability, nonce — wrap this call in the save-lifecycle slice; the guard it
reads is part of this contract.

## Value objects

`FieldGroup`, `Field` and its ten concrete field classes, `FieldPanel`,
`StorageTarget`, `FieldType`, `ObjectContext`, `ObjectKind`, `ObjectRef`,
`RegisteredField`
are public values a consumer constructs and passes. They are `final readonly`
and carry no collaborator; the static `table()` factories on the two schema
declarations are value factories, which the static-access contract permits.
`FieldValuesTable`, `FieldItemsTable`, `RepeaterCodec` and `Hooks` are
documented in the generated hook reference and the architecture docs.

`FieldPanel` is one declared panel: a `FieldGroup` paired with the post type
whose edit screen renders it. The pairing is a value because the group carries
no screen — the same group may serve several post types, and core registers one
metabox per pair. A panel naming no post type is refused with
`InvalidPanelDeclaration`.

## The admin-UI contracts

The storage core renders nothing. `Admin\FieldsUiProvider` — the opt-in half of
the package — turns a host's declaration into screens, and it reaches its own
collaborators through two contracts:

### `Contracts\Panels`

```php
interface Panels extends \IteratorAggregate
{
    public function isEmpty(): bool;
    /** @return list<FieldPanel> */
    public function forPostType(string $postType): array;
}
```

| | |
|---|---|
| Role | the host's declared field panels, from which every metabox, save entry, read binding and notice is derived |
| Implementation in this package | none: the host's collection implements it |
| Read by | `Admin\FieldsUiProvider`, and nothing else |

Iteration is part of the contract because the read bindings are registered at
`rest_api_init`, when no screen names a post type, so the enumeration must come
from the declaration. No binding and an empty collection attach nothing; that is
the whole opt-in.

### `Contracts\ControlRegistry`

```php
interface ControlRegistry
{
    public function control(FieldType|string $type): FieldControl;
    public function has(FieldType|string $type): bool;
}
```

| | |
|---|---|
| Role | the field-type to control lookup the editor renders through |
| Implementation in this package | `Admin\FieldTypeRegistry` |
| Throws | `InvalidFieldDefinition` from `control()` when no control serves the type; `has()` never throws |
| Emitted hook | `mahout/fields/editor_controls`, applied by the implementation at construction |

A type is named by its enum case or by that case's value, because the filtered
map is keyed by the string and a declaration carries the enum. The editor
type-hints the contract, so a host that binds another registry renders through
its own controls.

`Contracts\FieldEditor` and `Contracts\FieldControl` are the other two admin
surfaces a host may re-bind or extend: the panel renderer behind the metabox,
and the one input a field type renders into.

## What a consumer may rely on

- The storage target is invisible at the call site: `FieldReader` and
  `FieldWriter` dispatch on the *resolved* target, so changing a field from
  `Meta` to `Table` is one word plus a migration, with zero call-site
  changes.
- A refusal is loud. Every wrong shape, wrong context and wrong storage
  combination throws a named exception; nothing is coerced silently.
- The two tables are created and swept by mahout-db, through the migrations
  and orphan sources this package attaches to mahout-db's filters.
