# ADR-0004 — The registry owns the cross-field rules at registration

Status: accepted

## Context

Field ids that answer to more than one field, a queried repeater bound to
meta, an option-context field bound to the table -- each is a rule two
construction sites can forget. A rule that lives at the call site is a rule
that depends on the caller's memory.

## Decision

Every cross-field rule runs once, in `FieldRegistry::register()`, against the
*resolved* storage target, never the declaration alone. The reader and the
writer accept nothing they did not resolve through the registry, so a future
construction site inherits the rules by registering instead of copying checks.
Id uniqueness is global across groups, not per group.

## Consequences

- A wrong shape reaching the storage-target filter is a loud refusal, never a
  coercion; the check is the trust boundary itself.
- The reader and writer throw on a context mismatch, which is what keeps a
  user-scoped field from being read for a post.
