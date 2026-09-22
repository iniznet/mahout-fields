# ADR-0003 — The repeater is versioned JSON in meta, positioned rows in the items table

Status: accepted

## Context

A repeated value in meta has no documented order: repeated meta rows read back
in insertion order, and a consumer that reorders them mutates insertion
metadata. A repeater in a table needs a position the reader can trust.

## Decision

A `Meta` repeater stores one payload under one key, versioned
`{"v":1,"items":[...]}`, encoded and validated by a dedicated codec that also
refuses payloads above a byte cap. A `Table` repeater stores one row per item
at an explicit position in `wp_mahout_field_items`; the read orders by
position, which is the row's own key. A repeater declared `queried` binds the
items table and is refused in meta.

## Consequences

- The codec owns the version. A newer payload written by a consumer's own
  codec is accepted on write and refused on read, loudly, until this package
  learns the version.
- An item type the generic table cannot hold (decimal, date, record) is
  refused for the table target at registration: the escalation is a dedicated
  table, stated in the contract.
