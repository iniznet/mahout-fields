# ADR-0008 — A storage migration runs through the adapters, per field, post-context only

Status: accepted

## Context

A field's `StorageTarget` can change — that is the point of making it a declaration and not a hard-coded path. The values already stored must move, and the move must not become a second write path that bypasses the adapters' canonicalisation, or a second discovery mechanism that bypasses the bounded-statement rule.

## Decision

`MigrateFieldMetaToTable` and `MigrateFieldTableToMeta` are one-instance-per-field migrations: the ledger's UNIQUE name carries the field id, so the same field never moves twice. Both directions read and write through `MetaStorage` and `TableStorage` — never around them — so a moved value lands exactly as a write would have stored it, and the read-back in the test is the production path.

Discovery is bounded on both sides: a keyset walk on `meta_id` with a `meta_key` equality and a `LIMIT`, the cursor strictly advancing, for the meta side; an equality on `field_id` against the declared tables' primary keys for the table side. Each chunk is one transaction: a failed row rolls the chunk back, nothing is partially applied, nothing is retried, and the original exception propagates to the runner's `MigrationFailed`.

Only post-context fields migrate. A user- or term-context field is refused with `InvalidFieldContext::migrationUnsupported`, because collection and erasure for those contexts are the privacy slice's work, and a move that outpaces that slice would orphan values the erase path can no longer see.

The move is value-exact, not byte-exact: `value_dec`'s `3.500000` becomes `3.5` on the way back to meta, which is the field's cast, not a loss. The migration fabricates no mirror; the field's group gets one on its next group save.

## Consequences

- Reversal is the other class's `up()`, not a mirrored copy of the code.
- A failed migration leaves the source untouched for the fields it never reached and the chunk it rolled back — a re-run resumes from the cursor.
- The migration is a host decision: the classes are public, the runner schedules them through the db package's migration list.
