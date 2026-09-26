# ADR-0012 — The aggregate counts over a capped read

Status: accepted

## Context

`FieldQuery::count()` was the one statement in the package with no `LIMIT`. Its
documented bound was "the `field_id` index's own range scan" — which is true, and
was the wrong kind of true. A range scan over an index still visits every matching
entry, so the statement's cost is a property of how much data exists, not of how
large a page was asked for. That is the exact shape the throughput model measures at
16.6 ms over fifty thousand rows and names as the query that caps the site at
roughly 120 requests per second — the failure arriving later than any other, and
only after the content has grown.

The statement had no production caller. It was reachable, and nothing but a
reviewer's memory separated it from a public listing.

## Decision

`count()` becomes `countUpTo($fieldId, $operator, $value, int $ceiling)`, and the
count runs over a capped inner read:

```sql
SELECT COUNT(*) FROM (SELECT 1 FROM <values> WHERE … LIMIT ?) AS capped
```

A `LIMIT` cannot bound an aggregate, so the bound moves one level down. The scan
costs at most `ceiling` rows whatever the range holds. The answer is exact below the
ceiling and saturates at it above — a caller who receives the ceiling is told "at
least this many", which is the fact the capped read can honestly return.

The contract now states that no shape in `FieldQuery` has a cost that grows with the
size of its range, and the class docblock no longer carries an exception clause.
The name says the promise at the call site, because a `count()` that returns a
saturated number under that name is a defect waiting for someone to sum it.

## Consequences

An exact total across a large range is no longer obtainable from this contract at
all. That is the intended trade, and it matches the rule the family already applies
to pagination totals: a Surface that must display an exact count maintains a counter
rather than scanning, because the scan is the tax, not the display. If such a
caller ever appears, the next decision is a maintained counter in this package — not
the removal of the ceiling.
