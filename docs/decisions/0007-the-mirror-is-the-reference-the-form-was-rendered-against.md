# ADR-0007 — The mirror is the reference the form was rendered against

Status: accepted

## Context

A group that binds Table storage commits a row set, and a revision restore
rewrites that row set from a stored payload. Two things the storage contract
leaves open: which object is the reference a lost-update comparison reads,
and where that reference lives.

## Decision

The reference is the **mirror**: a versioned payload `{"v":1,"schema":1,"hash":"...","rows":[...]}` stored under `_mahout_mirror_<groupId>`, one row per Table-bound field in group declaration order, encoded by `MirrorCodec` and refused — never substituted — when broken. The guard reads the mirror, not the table: the mirror is what the form was rendered against, so an out-of-band table write is caught rather than blessed, and the hash is read back from the stored payload instead of recomputed from the table.

The key is registered through `register_meta()` with `revisions_enabled => true`, so core copies it into revisions and restores it; the package rehydrates the table rows from the restored payload at priority 20 of the revision-restore action, after core's own meta restore at 10. The payload's row shape is versioned a second time through the `schema` member, so a stored shape can be migrated without re-reading ambiguity.

The mirror binds post context only: revisions are post-only. A user- or term-context group's concurrency control is the save lifecycle's lock guard, and its guard compares against the empty row set's hash.

## Consequences

- The guard compares inside the transaction, before the first write — comparing outside the transaction is a race with a shorter window and an invisible difference.
- An absent mirror reads as the empty hash, which is the upgrade path: rows written before this slice existed carry no mirror, and the first guarded write seeds one.
- A migrated field's group gets its mirror on the next group save; a migration fabricates none.
