# ADR-0005 — Shared analyzer config, the lockfile, and the translation scope

Status: accepted

## Context

The family's convention, fixed in the db package's ADR-0005: the analyzer
rules live in `mahout-devtools`; a repository that copies them has diverged.
`mahout-devtools` is not published, so a development checkout resolves it
through an uncommitted `composer.dev.json` with a `path` repository.

## Decision

- `phpstan.neon` includes the shared configuration and declares no rule of
  its own; `psalm.xml`, `rector.php` and `.php-cs-fixer.dist.php` are the
  shared ones. `composer stan` and `composer arch` run the same config, so
  the architecture rules are part of the analysis, not a second pass.
- The committed `composer.lock` is resolved through the uncommitted
  `composer.dev.json`; the `path` repository that produced it is never
  committed.
- Exception messages are not passed through a translation function. They are
  developer-facing diagnostics. The generated POT is therefore header-only,
  and `composer i18n:check` passes on it.

## Consequences

- A fork pull request runs the same `composer check`, because devtools
  resolves over VCS with no secret.
- The first user-visible string this package produces must come with a
  decision record saying why the exception-message rule does not apply to it.
