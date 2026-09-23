# ADR-0009 — The admin UI is its own opt-in provider, and panels are the host's declaration

Status: accepted

## Context

`FieldsProvider` declares the registry, the reader and the writer. The editing
surface — metaboxes, the `save_post` entry, the field REST route, the
write-failure notice — is a different set of concerns: it is admin, it is
capability-gated, and it depends on declarations only the host can make. With
the UI attached by the host's own provider, every host repeats the same
composition, the package's core provider quietly becomes the owner of code it
documents as not rendering anything, and a host that declares no field panel
still carries the wiring in its composition root.

The other option is a flag. A `show_ui` switch, or a config key, is a degraded
mode: it is behaviour chosen at runtime rather than composition chosen at the
root, which is exactly what law 3 and law 6 refuse.

## Decision

`Admin\FieldsUiProvider` is a second `ServiceProvider`, registered after
`FieldsProvider`, and it is the only class in the package that calls
`add_meta_box()`, attaches a classic save entry, registers a REST route or
prints an admin notice. `FieldsProvider` renders nothing and has no dependency
on `Admin\`; no `Admin\` class reaches back into the container for a storage
collaborator it was not given.

Its `register()` binds two container seams — `Contracts\ControlRegistry` (the
field-type to control lookup) and `Contracts\FieldEditor` (the panel renderer) —
and its `boot()` reads one host binding, `Contracts\Panels`. Every metabox, save
entry, read binding and notice is derived from that collection and from nothing
else: no binding, or an empty collection, attaches nothing. The opt-in and the
no-panels case are the same code path, so there is no configuration to diverge
and nothing attached to a screen nobody declared.

`Contracts\Panels` mirrors the host's collection shape — an
`IteratorAggregate<int, FieldPanel>` with `isEmpty()` and `forPostType()` —
rather than inventing a package list, because the host already owns the
declaration site. The *pair* is the package's: `FieldPanel` binds a `FieldGroup`
to the post type whose edit screen renders it, since the (post type, group)
pairing is the fact a metabox registration is built from, and one group may
serve several screens.

A host with panels also binds `Contracts\RequestInput`. The package never reads
a superglobal, so the foreign-form guard must be decided by the host's request
adapter; an unbound adapter fails loudly in `boot()` through the container.

## Consequences

- A host registers the UI by adding one provider line and one binding, not by
  re-implementing the wiring.
- The metabox, save, route and notice are attached through package `Hooks`
  constants with the handler's guard order and the `edit_post` capability gate
  unchanged — the provider moves the wiring, not the policy.
- The UI is absent from a headless or CLI installation by composition: the
  provider is simply not registered.
- `Hooks::ADMIN_NOTICES` joins the inventory, so the notice's attachment point
  is greppable from the composition root like every other.
- A host that renders its own panels binds its own `Contracts\FieldEditor` after
  this provider registers; a host that replaces one control uses
  `mahout/fields/editor_controls`, because the control map is one filtered value
  and not a second path to a control.
