# mahout-fields

Field declarations, storage targets, both storage adapters and the repeater
codec for the mahout family. A field is declared once, with an explicit
storage target; everything downstream — the read path, the write path, the
table schema, the orphan sources — follows from that declaration.

This slice is the **declaration and storage core**. The save lifecycle, the
editor registry and the WP-CLI surface arrive in the next two slices.

## Install

```bash
composer require iniznet/mahout-fields
```

Requires PHP 8.4+, WordPress 7.1+, and `iniznet/mahout-kernel`,
`iniznet/mahout-db` and `iniznet/mahout-content`. mahout-db must register
before this provider, because the connection and the gateway are resolved
under their contract ids at register() time.

## Declare

```php
use Iniznet\Mahout\Fields\{FieldGroup, IntegerField, TextField, ObjectContext, StorageTarget};

$registry->register(new FieldGroup('series', ObjectContext::Post, [
    new TextField('isbn', StorageTarget::Meta),
    new IntegerField('season_count', StorageTarget::Table),
]));
```

A field id answers to exactly one field across every group. A wrong shape
reaching the `mahout/fields/storage_target` filter is refused, never coerced.

## Read and write

```php
use Iniznet\Mahout\Fields\Contracts\FieldReader;
use Iniznet\Mahout\Fields\Contracts\FieldWriter;
use Iniznet\Mahout\Fields\ObjectRef;

// The storage target is invisible at the call site. Changing a field from
// Meta to Table is one word plus a migration, with zero call-site changes.
$isbn = $reader->value('isbn', ObjectRef::post($postId));
$writer->set('season', ObjectRef::post($postId), 3);
```

A component never reads a raw `get_post_meta()` for a registered field: the
reader and the writer are the whole of the encapsulation rule.

## Storage targets

| Need | `StorageTarget` |
|---|---|
| Read with the entity, never filtered | `Meta` |
| Filtered, sorted, aggregated or counted | `Table` |

A `Table` field cannot be bound as a block attribute; its write path is the
field panel and the field REST route, which is the save-lifecycle slice.

## The tables

The two tables ride mahout-db: their migrations join
`mahout/db/migrations` and their orphan sources join
`mahout/db/orphan_sources`. Both declare `ENGINE=InnoDB`; every statement
through the gateway carries a primary-key equality or a `LIMIT`.

- `{prefix}mahout_field_values` — one row per field per object, one typed
  value column per field type, every value column indexed with `field_id`
  leading.
- `{prefix}mahout_field_items` — one row per repeater item at an explicit
  position, the position the read orders by.

## Development

```bash
composer install          # production manifest; the lock is committed
COMPOSER=composer.dev.json composer install   # development tooling
composer check            # every gate, before every commit
```

The tests run against a real database through WordPress's own test library;
see `tests/wp-tests-config.php.dist`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
