# Architecture

The package is a module of the mahout family's modular monolith: one process,
one database, one deployable artefact. Boundaries are enforced by
`Contracts`, `@internal` and the architecture rules, never by a network.

## Layers

```
FieldsProvider (composition root)
      |
      v
FieldRegistry --> RegisteredField --> FieldReader / FieldWriter
      |                                     |
      v                                     v
MetaStorage  |  TableStorage ----------> mahout-db Contracts (SqlConnection, TableGateway)
RepeaterCodec

Admin\FieldsUiProvider (opt-in, registered after the core provider)
      |
      v
Contracts\Panels (the host's declaration) --> FieldMetabox / FieldSaveHandler
                                               / FieldRestRoute / WriteFailureNotice
      |
      v
Contracts\ControlRegistry --> Contracts\FieldEditor --> Contracts\FieldControl
```

Arrows point one way. A storage adapter never imports a reader or a writer;
the registry never touches storage; the codec touches nothing at all. The
core provider is the only class that resolves storage collaborators, and the
UI provider is the only one that touches an admin screen — the core provider
has no dependency on `Admin\`, and no `Admin\` class reaches back into the
container.

## The three halves

- **Declaration.** `Field` and its ten concrete types, `FieldGroup`,
  `StorageTarget`, `FieldType`, `ObjectContext`, `ObjectRef`. Every field
  carries an explicit `StorageTarget`; there is no default.
- **Registration.** `FieldRegistry` applies the storage-target filter, then
  runs the combination rules against the *result*: an option-context field is
  `Meta` always, a queried repeater binds the items table never JSON, a
  repeater whose item type has no generic column is refused on the table
  target, and an id answers to exactly one field.
- **Storage.** `Internal\\MetaStorage` for the load-with-the-entity store,
  `Internal\\TableStorage` for the two typed tables. A repeater stores no
  envelope: every leaf is one scalar at its address — the chain of positions
  and member ids from the root — on either target. The table adapters' every
  statement goes through mahout-db's `TableGateway` with a primary-key
  equality; a repeater's write is one transaction.

## What the package does not do

- The storage core renders no editor control, binds no REST route and registers
  no WP-CLI command. The admin UI is the opt-in `Admin\FieldsUiProvider`,
  registered after `FieldsProvider`, and its seams are the writer's guard order,
  the `mahout/fields/editor_controls` filter, and the `Contracts\Panels`
  declaration the host binds: with no binding, or with an empty one, the UI
  provider attaches nothing at all.
- It never calls a vendor purge API, never flushes a cache, never queries
  `information_schema` on a request path.
- It never reads a registered field through raw meta: the field layer is the
  whole of that rule, and the architecture rules enforce it on consumers by
  fixture.

## Ownership

The two tables' migrations join `mahout/db/migrations` and their orphan
sources join `mahout/db/orphan_sources`, both attached at register() time, so
mahout-db must be registered before this provider in the composition root.
The provider resolves `SqlConnection` and `TableGateway` under their contract
ids — an explicit ordering, not an ambient dependency.

## How to verify

`composer check` runs the whole family's gates over this package: the shared
PHPStan config (max level, the architecture rules included), Psalm taint,
Rector, PHPUnit on a real database, the generated hook reference, the POT and
the artifact set. The architecture rules are proved in the suite by running
them over a violation fixture and a clean twin.
