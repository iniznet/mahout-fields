# Getting started

## Install

The package is consumed over VCS; there is no Packagist lane.

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/iniznet/mahout-fields.git" }
    ],
    "require": {
        "iniznet/mahout-fields": "^1.0"
    }
}
```

```bash
composer require iniznet/mahout-fields:^1.0
```

The package requires PHP 8.4 and WordPress 7.1 or later, and depends on
`iniznet/mahout-kernel`, `iniznet/mahout-db` and `iniznet/mahout-content`.
In a development checkout of this repository the sibling packages are resolved
through the uncommitted `composer.dev.json` (a `path` repository plus
`@dev`):

```bash
COMPOSER=composer.dev.json composer install
```

The committed `composer.lock` is resolved through the uncommitted dev
manifest; the path repository that produced it is never committed.

## Register the provider

mahout-db must register before this provider, because the connection and the
gateway are resolved from the container under their contract ids at register()
time:

```php
$kernel = Kernel::run(new Container(), [
    new \Iniznet\Mahout\Db\\DbProvider(),
    new \Iniznet\Mahout\Fields\\FieldsProvider(),
    // your modules, in dependency order
]);
```

The provider attaches the two tables' migrations and the post-kind orphan
sources to mahout-db's filters in register(), which runs for every provider
before any boot, so the ordering cannot lose them.

## Declare fields

Declare data in a module, in a group:

```php
use Iniznet\\Mahout\\Fields\\{FieldGroup, IntegerField, TextField};
use Iniznet\\Mahout\\Fields\\Contracts\\FieldRegistry;
use Iniznet\\Mahout\\Fields\\StorageTarget;

final class SeriesModule implements \\Iniznet\\Mahout\\Kernel\\Contracts\\Module
{
    public function register(\\Iniznet\\Mahout\\Kernel\\Container $container): void
    {
    }

    public function boot(\\Iniznet\\Mahout\\Kernel\\Container $container): void
    {
        $registry = $container->get(FieldRegistry::class);

        $registry->register(new FieldGroup('series', ObjectContext::Post, [
            new TextField('isbn', StorageTarget::Meta),
            new IntegerField('season_count', StorageTarget::Table),
        ]));
    }
}
```

Every field declares its storage target; there is no default. A duplicate id
across groups is a loud refusal at registration.

## Read and write

```php
$isbn = $reader->value('isbn', ObjectRef::post($postId));
$writer->set('season_count', ObjectRef::post($postId), 4);
```

A `Meta` repeater stores the versioned payload; `items()` reads it back in
declared order. A `Table` repeater stores one row per item at an explicit
position.

## The failure modes a newcomer hits

| Symptom | Cause |
|---|---|
| `FieldNotFound` | the field id is not registered, or the group was never registered |
| `InvalidFieldContext` | the `ObjectRef` context is not the field group's — a user field read for a post |
| `InvalidFieldValue` | the value cannot be sanitised into the declared type; it is refused, never coerced |
| `InvalidStorageCombination` | an option-context field on `Table`, or a queried repeater on `Meta` |
| `ServiceNotFound: SqlConnection` | mahout-db is not registered before this provider |
| Tables missing | the migrations have not run — switch the theme, or run `wp mahout migrate` |

## Test configuration

The suite runs on a real database through WordPress's own test library. Copy
`tests/wp-tests-config.php.dist` to `tests/wp-tests-config.php` and point it
at a test database, never at the site's.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
