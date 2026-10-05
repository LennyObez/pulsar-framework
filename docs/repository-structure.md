# Repository structure

## Goals

- Minimize entropy: one obvious place for each concern.
- Keep the core small, stable, and fast.
- Make extensions first-class citizens (including first-party ones).

## What this page states, and what it deliberately does not

This page names the **top-level directories** and what each is for. That set changes
rarely, and `RepositoryStructureTest` fails in both directions if it changes without this
page changing with it: a directory here that the repository does not have, or a tracked
directory the repository has that is not here.

It states **no inventory and no counts** — not the modules under `src/`, not the
extensions under `extensions/`, not the number of files in `config/`. An earlier version
did, and by the time anyone read it the figures were wrong by 24 modules, 14 extensions
and 14 config files, because a list transcribed by hand decays the moment the next
directory is added and nothing fails when it does. The commands under each entry below
produce the current answer, and they cannot be out of date.

## Top-level layout

```
.
├─ .github/          # Workflows, issue templates, PR template
├─ benchmarks/       # Standalone component benchmarks (see performance.md)
├─ bin/              # CLI entry point (bin/pulsar)
├─ config/           # Configuration stubs, one per subsystem
├─ database/         # Migrations
├─ docs/             # This documentation set
├─ examples/         # Runnable example applications
├─ extensions/       # First-party extensions, each with a pulsar.json manifest
├─ lang/             # i18n translation catalogs
├─ public/           # Web-server document root (index.php, router.php)
├─ resources/        # View templates, CSS, self-hosted fonts
├─ scripts/          # Developer-facing entrypoints and CI helpers
├─ src/              # Framework core, one namespace per module
├─ tests/            # Unit/, Integration/, E2E/, Benchmark/
├─ tools/            # Tooling configuration, kept out of the root
└─ vendor-bin/       # Isolated tool trees (bamarni/composer-bin-plugin)
```

Everything permitted at the repository **root** as a file is enumerated, with the reason
for each, in `tests/Unit/Integrity/RootCleanlinessTest.php`. That list is the authority;
adding a file to the root means adding it there first, deliberately.

A **bootstrap** directory is not in this list, and is deliberately not written above as a
path: it holds one generated, gitignored cache directory and nothing else, so git tracks
nothing inside it and a fresh clone does not have it at all. The bootstrap PHP that earlier
revisions of this page placed there is `tools/php/bootstrap.php`.

## Per-directory notes

### `src/`

The framework core. Each top-level directory is one module and one namespace root
(`src/Routing/` is `Pulsar\Routing\`). A `Internal\` namespace inside a module is
module-private and must never be imported across a module boundary — see
[boundary-enforcement.md](boundary-enforcement.md) and
[ADR-0002](adr/0002-modular-monolith-vertical-slices-ports-adapters.md).

```bash
git ls-files src | cut -d/ -f2 | sort -u    # the current module list
```

### `extensions/`

First-party extensions, built on the same public extension API third parties use — there
is no privileged built-in **API** — the trust each bundled extension ships with is a
separate matter, granted in `config/extensions.php`
([ADR-0070](adr/0070-the-extension-api-is-shared-the-trust-that-ships-with-it-is-not.md)).
An extension is a directory containing a `pulsar.json` manifest, which is why the count of
manifests and the count of directories differ: `extensions/compliance/` is a grouping
directory whose children are the extensions.

```bash
git ls-files 'extensions/**/pulsar.json'    # every extension, by its manifest
```

Which tier each bundled extension is granted is recorded in `config/extensions.php`, and
kept complete by `ExtensionSandboxDriftTest`.

### `config/`

Configuration stubs. Each returns an associative array that is mapped to a readonly DTO
([ADR-0011](adr/0011-typed-readonly-configuration-dtos.md)); an unknown key is a boot
failure rather than a silent no-op ([ADR-0036](adr/0036-unknown-config-key-detection.md)).

```bash
git ls-files config    # the current stub list
```

### `docs/`

`docs/adr/` holds the architecture decision records, indexed by a **generated**
[README](adr/README.md) — `composer adr:index -- --check` fails when that index has
drifted. `docs/contributing/`, `docs/deployment/`, `docs/security/` and
`docs/architecture/` group the pages that belong to one audience; everything else sits at
the top level.

There is also an audit scratch area under docs/, named in `.gitignore` and excluded from
every clone. It holds working notes, not published findings, and no page here should send
a reader into it — published audit artefacts belong under `docs/security/`, which is
tracked.

### `tools/`

Tooling configuration, centralized to avoid root clutter: `tools/php/` (phpstan.neon,
psalm.xml, phpunit.xml, phpbench.json, the budget files), `tools/api/` (the public API
snapshot), `tools/bench/`, `tools/ci/`.

### `scripts/`

Developer-facing entrypoints that wrap composer/npm consistently (`scripts/qa`), plus the
checks CI runs directly (`scripts/boundary_check.php`, `scripts/wiring_check.php`,
`scripts/check-adr.sh`).

### `vendor-bin/`

Isolated dependency trees for analysers whose own requirements would otherwise constrain
the framework's. Each tool's `composer.json` and `composer.lock` are tracked, so the
analyser version is pinned and reproducible; the packages they install are not.
