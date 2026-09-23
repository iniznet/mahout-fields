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

## Edit the fields

The storage core renders nothing. The editing surface is its own opt-in
provider, registered after the core one, and everything it attaches is derived
from the host's `Contracts\Panels` declaration:

```php
$kernel = Kernel::run($container, [
    new \Iniznet\Mahout\Db\\DbProvider(),
    new \Iniznet\Mahout\Fields\FieldsProvider(),      // storage core
    new \Iniznet\Mahout\Fields\Admin\FieldsUiProvider(), // the panels, after it
]);

$container->set($hostPanels, id: \Iniznet\Mahout\Fields\Contracts\Panels::class);
$container->set($hostRequest, id: \Iniznet\Mahout\Fields\Contracts\RequestInput::class);
```

One panel is one `FieldPanel`: a declared group paired with the post type whose
edit screen renders it. With that binding present and non-empty the UI provider
attaches the whole surface -- one metabox per (post type, group) pair, the
classic `save_post` entry through the handler's guard order, the value route and
its `register_rest_field` reads, and the write-failure notice -- each through a
`Hooks` constant, the metabox behind the `edit_post` capability. Without the
binding, or with an empty collection, it attaches nothing: the opt-in and the
no-panels case are one code path.

The package never reads a superglobal, so a host with panels supplies the
save boundary's `Contracts\RequestInput` adapter; a missing binding fails
loudly in `boot()`. A host that wants another panel renderer binds its own
under `Contracts\FieldEditor` after this provider registers; a host that wants
another control for one field type uses `mahout/fields/editor_controls`.

## The failure modes a newcomer hits

| Symptom | Cause |
|---|---|
| `FieldNotFound` | the field id is not registered, or the group was never registered |
| `InvalidFieldContext` | the `ObjectRef` context is not the field group's — a user field read for a post |
| `InvalidFieldValue` | the value cannot be sanitised into the declared type; it is refused, never coerced |
| `InvalidStorageCombination` | an option-context field on `Table`, or a queried repeater on `Meta` |
| `ServiceNotFound: SqlConnection` | mahout-db is not registered before this provider |
| `ServiceNotFound: RequestInput` | the UI provider is registered and has panels, but the host bound no request adapter |
| No metabox on a screen | no panel declares that post type, or the UI provider is not registered |
| Tables missing | the migrations have not run — switch the theme, or run `wp mahout migrate` |

## Test configuration

The suite runs on a real database through WordPress's own test library. Copy
`tests/wp-tests-config.php.dist` to `tests/wp-tests-config.php` and point it
at a test database, never at the site's.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
