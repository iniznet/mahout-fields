# ADR-0011 — The page prime belongs to the field reader

Status: accepted

## Context

`FieldReader` exists so that the storage target is invisible at the call site: the
contract promises that moving a field from `Meta` to `Table` is one word plus a
migration. That promise held for correctness and silently failed for cost.

A `Meta` field is read from the object cache core's priming fills, so a page of
them costs one statement however many fields the page shows. A `Table` field went to
the gateway once per field per row: five rows and three fields, fifteen primary-key
reads — measured, in this package's own test, at exactly `rows × fields`. Both
statements are individually cheap and individually indexed; the multiplication is
what the throughput model calls the query that caps the site, and it was reachable
by editing one word in one `config/fields.php`.

So either the target was visible at the call site — in which case the encapsulation
is a lie — or the read path had to carry the page's cost the way the meta path
does.

## Decision

`Contracts\FieldReader::prime(array $objects): void`, called once per result set
before mapping, beside the `update_meta_cache()` and `update_object_term_cache()`
calls the repository already makes. It issues one statement per object kind over the
value table via the set predicate (mahout-db ADR-0008) and files every returned row
in a per-request store, so the field reads that follow are memory lookups.

Four consequences were chosen deliberately:

**Absence is recorded, not rediscovered.** An object filed with no rows reads as
null for every field without a statement. Re-deriving absence per field is how the
fifteen statements came back the moment a page had sparse data, which is the normal
case.

**The ceiling is derived, not guessed.** A page of N objects can own at most N rows
per registered scalar `Table` field, so the `LIMIT` is `N × count`. The count comes
from the registry, so a theme with no `Table` field at all issues no statement —
prime is free on the path that does not use it, and nothing is silently truncated,
because the limit can only ever sit above the true row count.

**Already-filed objects are not fetched twice**, so a repository may prime a second
set that overlaps the first.

**The leaves table is not primed.** A repeater's rows per object are unbounded —
twenty items with four members is eighty rows for one field — so there is no
registry-derived ceiling and a wrong guess would truncate real content rather than
merely cost a query. Repeater reads stay one statement per group per object. That is
a stated limit of this decision, not an oversight: the prime covers the shape whose
maximum is declarable, and leaves the shape whose maximum is the editor's.

## Consequences

Five statements in a page that used to cost fifteen; and the target stops being a
performance decision in disguise. The obligation moves to every repository that
reads fields on more than one row — the same obligation the contract already places
on the meta and term caches, and caught by the same kind of budget test rather than
by review discipline. `wp-content/themes/howdah` primes in its series repository; a
feature that forgets pays per field again, and its Surface ceiling test is where it
hears about it.
