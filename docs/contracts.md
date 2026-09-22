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
    /** @return list<FieldGroup> */
    public function groups(): array;
}
```

| | |
|---|---|
| Role | owns the declared fields and the resolved storage target of each |
| Implementation in this package | `FieldRegistry` |
| Throws | `FieldNotFound` for an unknown id, `GroupAlreadyRegistered`, `DuplicateFieldId`, `InvalidStorageCombination`, `InvalidFieldId` |
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
    public function items(string $fieldId, ObjectRef $object): array;
}
```

| | |
|---|---|
| Role | resolves the field, reads the declared storage, casts to the field's PHP shape |
| Implementation in this package | `FieldReader` |
| Throws | `FieldNotFound`, `InvalidFieldContext` when the object's context is not the group's, `InvalidRepeaterPayload` for a broken envelope |
| Filters | `mahout/fields/value` and its per-field variant, both scalar-only |

### `Contracts\\FieldWriter`

The write path. Sanitisation happens here, once, before storage — never in the
consumer.

```php
interface FieldWriter
{
    /** @param array<int, string|int|float|bool> $items */
    public function setItems(string $fieldId, ObjectRef $object, array $items): void;
    public function set(string $fieldId, ObjectRef $object, string|int|float|bool|null $value): void;
    public function delete(string $fieldId, ObjectRef $object): void;
}
```

| | |
|---|---|
| Role | sanitises once, stores on the declared target, removes on `null` |
| Implementation in this package | `FieldWriter` |
| Throws | `InvalidFieldValue` for a refused value, `RepeaterTooLarge` above the declared cap, `InvalidFieldWrite` for a shape mismatch |
| Emitted hooks | `mahout/fields/before_save`, `mahout/fields/after_save` carrying the raw caller value |

The save lifecycle's guards — autosave, revision, foreign form, post lock,
capability, nonce — wrap these calls in the save-lifecycle slice; they are not
part of this contract.

## Value objects

`FieldGroup`, `Field` and its ten concrete field classes, `StorageTarget`,
`FieldType`, `ObjectContext`, `ObjectKind`, `ObjectRef`, `RegisteredField`
are public values a consumer constructs and passes. They are `final readonly`
and carry no collaborator; the static `table()` factories on the two schema
declarations are value factories, which the static-access contract permits.
`FieldValuesTable`, `FieldItemsTable`, `RepeaterCodec` and `Hooks` are
documented in the generated hook reference and the architecture docs.

## What a consumer may rely on

- The storage target is invisible at the call site: `FieldReader` and
  `FieldWriter` dispatch on the *resolved* target, so changing a field from
  `Meta` to `Table` is one word plus a migration, with zero call-site
  changes.
- A refusal is loud. Every wrong shape, wrong context and wrong storage
  combination throws a named exception; nothing is coerced silently.
- The two tables are created and swept by mahout-db, through the migrations
  and orphan sources this package attaches to mahout-db's filters.
