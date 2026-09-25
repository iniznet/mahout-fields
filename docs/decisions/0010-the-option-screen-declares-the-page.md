# ADR-0010 — The option screen declares the page, not a field bag

Status: accepted

## Context

The option screen paired one option-context field group with the settings page
that rendered it, and the page was a bare `<h1>` and a form. A host whose
settings page is documentation -- guides, the reading of each option -- had no
way to declare it: a page is not always filled with fields, and a declaration
that cannot say so forces the host to register its own settings page by hand,
which is the second write path the admin UI exists to prevent. Pages also read
differently -- a nav bar across the top suits a form, a navigation column suits
a document -- and some pages belong on their own menu, not under Settings.

The other option is a host-side page builder: the host registers the menu, the
callback and the save entry itself. That is the second write path, with no
save lifecycle on it, and it is what ADR-0009 refuses.

## Decision

`OptionScreen` declares the page's whole structure: an intro line, tabs of
sections, each section either one group's fields -- inside the save lifecycle
-- or a markup file the declaring feature ships, whose existence is checked at
declaration because it is part of the codebase. The placement and the layout
are the declaration's facts too: a parent menu's submenu, or a top-level menu
with its own icon and no parent; a sidebar navigation column, the default, or
core's nav-tab bar. A screen declares its content one way -- its own group,
normalised into one tab, or tabs, never both -- and every group on the page,
wherever it is declared, is option-context; a screen with neither is refused,
because a page that renders nothing is not a screen.

Every tab's sections render on one page load, the inactive panel hidden, and
the package ships the tab-switching script -- no inline script, no framework,
and the links remain the no-JavaScript path. Each fields panel is its own
form: a tab with no fields renders no form, a submission posts exactly the
panel it came from, and the save writes the submitted tab's groups through the
same guard order the metaboxes use. The active tab is the request adapter's
`param()`, the one boundary; the adapter owns the merge of query and body.

## Consequences

A documentation-only screen registers no save entry and no notice: nothing can
be submitted to it, so nothing can fail. The page shell's look is the shipped
stylesheet's, and a host that took styling over through `Contracts\FieldUiPolicy`
owns it entirely -- the layout itself stays the markup's, which is the package's.
The declaration grows four public values (`OptionScreen` gained `description`,
`tabs`, `layout`, `topLevel`, `menuIcon`; `OptionTab`, `OptionSection` and
`OptionScreenLayout` are new) and one contract method, `RequestInput::param()`,
adopted by every consumer in the same change.
