# mahout-fields

Field declarations, storage targets, both storage adapters and the repeater
codec for the mahout family. A field is declared once, with an explicit
storage target; everything downstream — the read path, the write path, the
table schema, the orphan sources — follows from that declaration.

This package is the **declaration, storage and editing surface** of the field
layer: the schema core, the save lifecycle, the editor controls, the field
route, and the opt-in admin UI that turns a host's declared panels into
screens. It ships no WP-CLI surface; migrations run through mahout-db.

## Install

```bash
composer require iniznet/mahout-fields
```

Requires PHP 8.4+, WordPress 7.1+, and `iniznet/mahout-kernel`,
`iniznet/mahout-db` and `iniznet/mahout-content`. mahout-db must register
before this provider, because the connection and the gateway are resolved
under their contract ids at register() time. The editing surface is a second,
opt-in provider — see [Edit the fields](#edit-the-fields).

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


A repeater reads through `items()` — the declared shape, assembled from the
storage leaves, one scalar per address, no envelope:

```php
$credits = $reader->items('credits', ObjectRef::post($postId));
// [['role' => 'author', 'name' => 'Ursula K. Le Guin'], …]
```

## Query the fields

Only `Table` storage is queryable. `Contracts\FieldQuery` answers in post
ids — bounded, placeholder-bound — and a queried repeater is queried
member-qualified:

```php
use Iniznet\Mahout\Fields\Operator;

$ids = $query->postIds('isbn', Operator::Equals, '978-0-241-26858-2', 50);
$ids = $query->postIds('credits.role', Operator::Equals, 'author', 50);
```
## Edit the fields

The storage core renders nothing. `Admin\FieldsUiProvider`, registered after
`FieldsProvider`, is the editing surface, and every metabox, save entry, REST
read binding and notice it attaches is derived from the host's
`Contracts\Panels` declaration:

```php
$kernel->provider(new FieldsProvider());        // storage, registry, reader, writer
$kernel->provider(new FieldsUiProvider());      // the panels, opt-in

$container->set($hostPanels, id: Contracts\Panels::class);
$container->set($hostRequest, id: Contracts\RequestInput::class);
```

A `FieldPanel` pairs a group with the post type whose edit screen renders it,
and one metabox follows per pair. No panels binding, or an empty one, attaches
nothing: the opt-in and the no-panels case are one code path. The two seams a
host re-binds are `Contracts\ControlRegistry` and `Contracts\FieldEditor`; the
seam it extends is the `mahout/fields/editor_controls` filter.

## Storage targets

| Need | `StorageTarget` |
|---|---|
| Read with the entity, never filtered | `Meta` |
| Filtered, sorted, aggregated or counted | `Table` |

A `Table` field cannot be bound as a block attribute; its write path is the
field panel and the field REST route, both attached by `Admin\FieldsUiProvider`.

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
