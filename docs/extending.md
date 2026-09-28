# Extending

## Hooks

The generated hook references, `docs/reference/actions.md` and
`docs/reference/filters.md`, are the inventory. The
names are `public const` on `Hooks`; a raw hook-name string is banned and the
architecture rules fire on one in a fixture.

| Hook | Kind | Fires |
|---|---|---|
| `mahout/fields/storage_target` | filter | per field at registration; the result, not the declaration, is what the adapters dispatch on |
| `mahout/fields/sanitised_value` | filter | once per write, between sanitisation and storage |
| `mahout/fields/value` | filter | per read, after the cast |
| `mahout/fields/before_save` | action | around a write, carrying the raw caller value |
| `mahout/fields/after_save` | action | after the write |
| `mahout/fields/before_delete`, `mahout/fields/after_delete` | actions | around a delete |
| `mahout/fields/group_registered` | action | per registered group |
| `mahout/fields/registry_loaded` | action | on boot, with the registry |
| `mahout/fields/editor_controls` | filter | the editor slice's seam; not yet consumed |

Every hook is emitted from the provider, the registry, the reader or the
writer — never from a component, never at file scope.

## Changing a field's storage target from outside

The storage-target filter is the one deliberately dangerous seam. It decides
where a field lives, and the combination rules run against what it returns, so
a meta-to-table migration is possible from outside the library:

```php
\\add_filter(\\Iniznet\\Mahout\\Fields\\Hooks::STORAGE_TARGET, function ($storage, $field) {
    if ('season' === $field->id) {
        return StorageTarget::Table;   // after the migration has run
    }
    return $storage;
}, 10, 2);
```

A wrong shape is refused loudly, never coerced: the check is the trust
boundary itself.

## Adding a field type

Extend `Field` with the three members the abstract declares — `type()`,
`sanitise()`, `cast()` — and narrow the return types to what the type really
produces. A value that cannot be sanitised throws `InvalidFieldValue`
through a named constructor; it is never coerced. Register the field inside a
`FieldGroup` like any other.

## What a consumer may not do

- Read a registered field through `get_post_meta()`. The field layer is the
  whole of the encapsulation rule, and the shared architecture rule fires on a
  violation fixture and not on its clean twin in every build.
- Store sensitive values in either target. Constants or environment only.
- Open a transaction around a field save. The gateway owns the boundary, and
  it never nests.
