# ADR-0001 — The storage target is declared, never defaulted

Status: accepted

## Context

Every field read with its entity, never filtered, wants `wp_postmeta`. Every
field filtered, sorted, aggregated or counted needs a real column. A default
would let the first decision drift into the wrong store silently.

## Decision

`storage` is a required argument of every field constructor. There is no
default, and the composition root cannot register a field without stating
where it lives. The registry resolves the declared target through
`mahout/fields/storage_target` and then runs the combination rules against
the *result*, so a consumer's filter is what the adapters dispatch on.

## Consequences

- The `wp_postmeta` load-with-the-entity path and the two typed tables are
  one dispatch, not two code paths; a field moves between them with one word
  and a migration, and no call-site change.
- A `Table` field cannot be bound as a block attribute; the field panel and
  the field route own its write path, which is the save-lifecycle slice.
