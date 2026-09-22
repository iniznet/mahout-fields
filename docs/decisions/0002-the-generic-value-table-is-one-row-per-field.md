# ADR-0002 — The generic value table is one row per field per object

Status: accepted

## Context

A single generic value table keeps the query surface small: every scalar value
column carries its own secondary index with `field_id` leading, so a query for
one field is a range scan, never a scan of the row store. The alternative -- a
table per field -- multiplies migrations and pushes the join count into the
query author's hands.

## Decision

`wp_mahout_field_values` is one row per field per object, keyed on
`(object_kind, object_id, field_id)`, with one typed value column per scalar
family: `value_text`, `value_int`, `value_dec`, `value_date`. Every
secondary index leads with `field_id`. An upsert writes every value column
with exactly one filled, so a rewrite can never leave a stale sibling value.

## Consequences

- A field type with no column is refused at registration, not at write time.
- The table is generic by construction; a field that outgrows it binds a
  dedicated table, which is the documented escalation, not a schema change.
