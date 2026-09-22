# ADR-0006 — The two tables ride mahout-db, never their own migration path

Status: accepted

## Context

`wp_mahout_field_values` and `wp_mahout_field_items` need creation, a
ledger and an orphan sweep. A second migration path inside the fields package
would duplicate the runner, the ledger and the after_switch_theme run.

## Decision

The two migrations join mahout-db's list through its own filter at register()
-- which runs for every provider before any boot -- and the post-kind orphan
sources join its orphan sources the same way. The provider resolves the
connection and the gateway from the container under their contract ids, which
is why mahout-db is ordered first in the composition root.

## Consequences

- Rollback, the schema version gate and the sweep are inherited, not copied.
- The provider declares no internal cross-package dependency: `SqlConnection`
  and `TableGateway` are contracts, and the composition root resolves them.
