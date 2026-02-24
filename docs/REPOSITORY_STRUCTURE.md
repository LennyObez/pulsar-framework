# Repository structure

## Goals

- Minimize entropy: one obvious place for each concern.
- Keep the core small, stable, and fast.
- Make extensions first-class citizens (including first-party ones).

## Top-level layout

```
.
├─ .github/
│  ├─ workflows/
│  ├─ ISSUE_TEMPLATE/
│  └─ PULL_REQUEST_TEMPLATE.md
├─ bin/
│  └─ pulsar                    # CLI entry point
├─ bootstrap/
│  ├─ bootstrap.php
│  └─ cache/
├─ config/                      # Configuration stubs (29 files)
│  ├─ app.php
│  ├─ cache.php
│  ├─ database.php
│  ├─ security.php
│  ├─ observability.php
│  └─ ...
├─ database/
│  └─ migrations/
├─ docs/
│  ├─ adr/                      # Architecture decision records
│  ├─ contributing/
│  ├─ deployment/
│  ├─ security/
│  ├─ ARCHITECTURE.md
│  ├─ INSTALL.md
│  └─ ...
├─ examples/
├─ extensions/                  # First-party extensions (14)
│  ├─ admin/
│  ├─ cms/
│  ├─ form/
│  ├─ mcp-server/
│  ├─ oauth2/
│  ├─ observability-export/
│  ├─ opentelemetry/
│  ├─ orm/
│  ├─ payments/
│  ├─ psr7-bridge/
│  ├─ social-sso/
│  ├─ studio/
│  ├─ webauthn/
│  └─ example/
├─ lang/                        # i18n translation catalogs
├─ resources/                   # View templates and assets
├─ scripts/
│  ├─ qa                        # Quality gate script
│  ├─ ci/
│  ├─ boundary_check.php
│  ├─ check-adr.sh
│  └─ wiring_check.php
├─ src/                         # Framework core (39 modules)
│  ├─ Api/                      # #[Api] and #[Internal] attributes
│  ├─ Attribute/
│  ├─ Audit/
│  ├─ Auth/
│  ├─ Build/
│  ├─ Cache/
│  ├─ Config/
│  ├─ Console/
│  ├─ Container/
│  ├─ Context/
│  ├─ Core/
│  ├─ DataProtection/
│  ├─ Database/
│  ├─ Deploy/
│  ├─ ErrorHandling/
│  ├─ Event/
│  ├─ Extensibility/
│  ├─ Extension/
│  ├─ FeatureFlag/
│  ├─ Http/
│  ├─ I18n/
│  ├─ Idempotency/
│  ├─ Integrity/
│  ├─ Introspection/
│  ├─ Mail/
│  ├─ Notification/
│  ├─ Observability/
│  ├─ Queue/
│  ├─ Resilience/
│  ├─ Routing/
│  ├─ Runtime/
│  ├─ Scheduler/
│  ├─ Security/
│  ├─ Storage/
│  ├─ Supervisor/
│  ├─ Support/
│  ├─ Tenancy/
│  ├─ View/
│  └─ Webhook/
├─ tests/
│  ├─ Unit/
│  ├─ Integration/
│  ├─ E2E/
│  └─ Benchmark/
├─ tools/
│  ├─ api/                      # Public API snapshot tooling
│  ├─ bench/                    # Benchmark configuration
│  └─ php/
│     ├─ phpstan.neon
│     ├─ psalm.xml
│     ├─ phpunit.xml
│     ├─ phpbench.json
│     └─ performance-budgets.json
├─ .editorconfig
├─ .gitignore
├─ composer.json
├─ qodana.yaml
└─ README.md
```

## Notes

- `src/` contains the framework core with 39 modules. Each module is a self-contained namespace.
- `extensions/` hosts 14 first-party extensions built with the public extension API.
- `tools/` centralizes tooling config to avoid root clutter.
- `bootstrap/` must contain only deterministic startup logic and cache priming.
- `scripts/` are developer-facing entrypoints that wrap composer/npm commands consistently.
- `config/` contains 29 configuration stubs. Each returns an associative array mapped to a readonly DTO.
- `docs/adr/` contains architecture decision records following the ADR discipline.
