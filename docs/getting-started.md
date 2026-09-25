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

Absence is `null`: an empty field reads null, and a default is the
consumer's decision. The storage target is invisible at the call site — the
same call reads either target, so moving a field is one word plus a
migration.

## Read a repeater

A repeater reads through `items()` — the declared shape, assembled from the
storage leaves: a scalar item reads as a list, a composite item as
member-keyed records, a nested repeater as a list inside its record.

```php
$credits = $reader->items('credits', ObjectRef::post($postId));
// [['role' => 'author', 'name' => 'Ursula K. Le Guin'], …]
```

## Query your fields

Only `Table` storage is queryable — that is what the storage target decides.
The query builder is bound under `Contracts\FieldQuery` and answers in post
ids, never rows:

```php
use Iniznet\Mahout\Fields\{Operator, OrderDirection};

$ids = $query->postIds('isbn', Operator::Equals, '978-0-241-26858-2', 50);
$n   = $query->count('rating', Operator::GreaterThan, 4.0);
$ids = $query->orderedIds('rating', OrderDirection::Descending, 20);
```

A queried repeater — `queried: true` in its declaration, which forces the
leaves table — is queried **member-qualified**:

```php
$ids = $query->postIds('credits.role', Operator::Equals, 'author', 50);
```

The composition pattern: the query answers "which objects", the repository
turns the ids into entities through its own hardened query — never
`meta_query`, and never a query against a `Meta` field.

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

## Option screens: a page, not a field bag

An option screen declares a whole settings page. The one-line shape pairs one
option-context group with the page that renders it:

```php
new \Iniznet\Mahout\Fields\OptionScreen(
    pageSlug: 'howdah-display',
    pageTitle: 'Display',
    menuTitle: 'Display',
    group: $displayGroup,
    capability: 'manage_options',
);
```

A page is not always filled with fields. For tabs, an intro line, or plain
documentation, declare the page's structure instead -- `OptionTab`s of
`OptionSection`s, each section either one group's fields or a markup file the
declaring feature ships:

```php
new \Iniznet\Mahout\Fields\OptionScreen(
    pageSlug: 'howdah-display',
    pageTitle: 'Display',
    menuTitle: 'Display',
    group: null,
    capability: 'manage_options',
    description: 'How the site takes its display options.',
    tabs: [
        new \Iniznet\Mahout\Fields\OptionTab('Options', [
            \Iniznet\Mahout\Fields\OptionSection::fields('Footer note', $displayGroup),
        ]),
        new \Iniznet\Mahout\Fields\OptionTab('Guide', [
            \Iniznet\Mahout\Fields\OptionSection::content(
                'How display options work',
                __DIR__.'/../app/Admin/markup/display-guide.php',
            ),
        ]),
    ],
);
```

The rules are the declaration's, refused at construction: a screen declares
its content one way -- its own group or tabs, never both, and neither is not
a screen; a tab has a label (it is the tab's key in the URL) and at least one
section; a content section's markup file is part of the codebase and must
exist; every group on the page, declared or inside a tab, is option-context.

The page's placement and its reading layout are the declaration's own facts
too. A screen sits on the parent menu it names -- `menuParent` defaults to the
Settings menu -- or registers its own top-level menu, which names its icon and
no parent:

```php
new OptionScreen(
    // ...
    menuParent: '',          // a top-level screen declares no parent
    topLevel: true,
    menuIcon: 'dashicons-layout',
    layout: OptionScreenLayout::Tabs,     // a nav-tab bar; Sidebar is the default
);
```

The layout is structural: a navigation column beside the content -- the
default, for pages that read as a document -- or core's nav-tab bar across
the top. The shipped stylesheet styles the page shell and both layouts; a
host that took styling over owns the look instead.

A screen whose tabs carry no field group at all renders no form -- no nonce,
no save button, no save entry: a documentation or guide page is a screen like
any other, and nothing can be submitted to it. A tabbed screen saves exactly
the active tab's groups, group by group, in the order its sections render,
through the same guard order the metaboxes use; the form carries the active
tab back as a hidden field, and the request adapter's `param()` is the one
boundary it is read through.

The controls arrive styled. `Admin\FieldStyles` enqueues the package's own
`resources/fields.css` — scoped to its `mahout-fields-*` class names — on
exactly the screens that render field UI: a declared panel's post type edit
screen, a declared option screen's page. A host takes styling over through
`Contracts\FieldUiPolicy`: `styled() === false` removes the handle and every
default class, and the policy's per-field `FieldUi` value replaces a field's
control (a name that is not a control is refused at the render site), strips
one field's default styling while keeping the built-in control, or both. A
package install no core API can name a URL for refuses loudly at the enqueue
site; a host in that position binds its own `Admin\FieldStyles` with an
explicit URL.

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
