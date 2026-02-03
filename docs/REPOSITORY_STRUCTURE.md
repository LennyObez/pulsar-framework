# Repository Structure (Recommended)

## Goals

- Minimize entropy: one obvious place for each concern.
- Keep the core small, stable, and fast.
- Make extensions first-class citizens (including first-party ones).

## Top-level Layout

.
├─ .github/
│ ├─ workflows/
│ ├─ ISSUE_TEMPLATE/
│ └─ PULL_REQUEST_TEMPLATE.md
├─ bootstrap/
│ ├─ bootstrap.php
│ └─ cache/
├─ config/
│ ├─ app.php
│ ├─ security.php
│ └─ observability.php
├─ docs/
│ ├─ REPOSITORY_STRUCTURE.md
│ ├─ PHP_FEATURE_MATRIX.md
│ ├─ ARCHITECTURE.md
│ └─ ADR/
├─ extensions/
│ ├─ README.md
│ └─ <extension-name>/
│ ├─ pulsar.json
│ ├─ composer.json
│ └─ src/
├─ scripts/
│ ├─ qa.sh
│ ├─ bench.sh
│ └─ release.sh
├─ src/
│ ├─ Api/
│ │ ├─ Api.php
│ │ └─ Internal.php
│ ├─ Core/
│ ├─ Http/
│ ├─ Routing/
│ ├─ Container/
│ ├─ Console/
│ ├─ Extensibility/
│ ├─ Security/
│ └─ Observability/
├─ tests/
│ ├─ Unit/
│ ├─ Integration/
│ ├─ E2E/
│ └─ Benchmark/
├─ tools/
│ ├─ qodana.yaml
│ └─ php/
│ ├─ phpstan.neon
│ ├─ psalm.xml
│ ├─ phpunit.xml
│ ├─ phpbench.json
│ └─ performance-budgets.json
├─ .editorconfig
├─ .gitignore
├─ composer.json
└─ README.md

## Notes

- `extensions/` hosts first-party extensions built with the public extension API.
- `tools/` centralizes tooling config to avoid root clutter.
- `bootstrap/` must contain only deterministic startup logic and cache priming.
- `scripts/` are developer-facing entrypoints that wrap composer/npm commands consistently.
