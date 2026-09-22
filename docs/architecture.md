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
```

Arrows point one way. A storage adapter never imports a reader or a writer;
the registry never touches storage; the codec touches nothing at all. The
provider is the only class that resolves a collaborator from the container.

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
  `Internal\\TableStorage` for the two typed tables, `RepeaterCodec` for the
  versioned envelope. The table adapters' every statement goes through
  mahout-db's `TableGateway` with a primary-key equality; the item write is
  one transaction.

## What the package does not do

- It renders no editor control, binds no REST route and registers no WP-CLI
  command. The save lifecycle's guard order and the editor registry are the
  next two slices; their seams are the writer's guard order and the
  `mahout/fields/editor_controls` filter.
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
