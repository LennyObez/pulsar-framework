# Boundary enforcement

Automated enforcement of module boundaries to prevent architectural erosion. Two complementary tools work together:

- **Deptrac**: structural namespace fences (`\Internal\` cross-module, extension isolation, composition root whitelist)
- **Custom script**: semantic API surface enforcement (cross-module imports must target `#[Api]` classes)

Both run as part of `composer qa` and CI. Violations fail the build.

## Boundary rules

| #   | Rule                                              | Tool   | Severity                                             |
| --- | ------------------------------------------------- | ------ | ---------------------------------------------------- |
| 1   | No cross-module controller references             | Both   | Always ERROR                                         |
| 2   | No cross-module view references                   | Both   | Always ERROR                                         |
| 3   | Cross-module imports must target `#[Api]` classes | Script | ERROR (extensions + changed files), WARNING (legacy) |
| 4   | Adapters must not leak into contracts             | Script | Always ERROR                                         |
| 5   | Core must have no forbidden vendor dependencies   | Script | Always ERROR                                         |
| 6   | Cross-module `\Internal\` import                  | Both   | Always ERROR                                         |
| 7   | Extension depends on App code                     | Script | Always ERROR                                         |
| 8   | Cross-extension import of non-`#[Api]`            | Script | Always ERROR                                         |

### Rule 1: no cross-module controller references

A module must not import controllers from another module. Controllers are internal to their module and invoked only through routing.

**Violation:**

```php
namespace Pulsar\Auth;

use Pulsar\Extension\Studio\Server\Controller\ApiController; // FORBIDDEN
```

**Fix:** Use a route or event to trigger behavior in another module. Never reference another module's controllers directly.

### Rule 2: no cross-module view references

A module must not import view classes from another module. Views are internal to their module's presentation layer.

**Violation:**

```php
namespace Pulsar\Auth;

use Pulsar\Extension\Studio\Server\View\ViewRenderer; // FORBIDDEN
```

**Fix:** If you need rendering capabilities, depend on an `#[Api]` rendering interface, not a concrete view implementation from another module.

### Rule 3: cross-module imports must target `#[Api]` classes

When importing a class from another module, the target class must have the `#[Api]` attribute. Classes with `#[Internal]` or no visibility attribute are forbidden for cross-module use.

**Violation:**

```php
namespace Pulsar\Extension\Studio\Command;

use Pulsar\Cache\FrameworkCache; // FORBIDDEN - FrameworkCache is #[Internal]
```

**Fix options:**

1. **Add `#[Api]` to the target class** if it represents stable, public API.
2. **Create an `#[Api]` interface** and depend on the interface instead.
3. **Move the usage within the same module** so it is not a cross-module import.
4. **Baseline (last resort)**: add the target class to `tests/Unit/Integrity/architecture-baseline.json`.

**Exemptions:** Composition roots (`Pulsar\Core\Kernel`, `Pulsar\Console\Application`) are exempt because they wire the entire framework together.

#### Staged enforcement

| Context                  | Severity | Rationale                                   |
| ------------------------ | -------- | ------------------------------------------- |
| Extensions (always)      | ERROR    | Extensions must only use public API surface |
| New/changed files (diff) | ERROR    | New code must respect boundaries from day 1 |
| Legacy core files        | WARNING  | Transitional, ratcheted via baseline        |

#### How `#[Api]` lookup works

1. **Snapshot**: loads `tools/api/public-api.snapshot.json` (fast, deterministic)
2. **Reflection**: fallback for classes not in the snapshot
3. **Unresolvable**: returns null, violation skipped (no false positives)

### Rule 4: adapters must not leak into contracts

Files in `\Contract\` or `\Contracts\` namespaces must not import from `\Adapter\` or `\Provider\` namespaces. Contracts define the public interface; adapters implement it.

**Violation:**

```php
namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider; // FORBIDDEN
```

**Fix:** Contracts should only reference domain types, exceptions, and other contracts. If a contract method needs to return a provider-specific type, extract it into the domain layer.

### Rule 5: core must have no forbidden vendor dependencies

The framework's `composer.json` `require` section (production dependencies) must not include vendor SDK packages. Only `php`, `ext-*` extensions, and `psr/*` interfaces are allowed by default.

A denylist explicitly blocks known vendor SDKs (aws, stripe, sentry, etc.). Packages not on either list are allowed but should be reviewed.

**Violation example:** Adding `"stripe/stripe-php": "^14.0"` to `require` would fail this rule.

**Fix:** Vendor-specific integrations belong in extensions, not core. Use `require-dev` for development tools.

## The `\Internal\` convention

Classes in `\Internal\` namespaces are implementation details visible only within their own module.

### When to create `Internal/` subdirectories

- Concrete implementations of interfaces that should not be used directly
- Infrastructure wiring code (adapters, providers) that consumers should ignore
- Internal data structures not part of the public contract

### Example structure

```
src/Auth/
  AuthManagerInterface.php     <- Public (#[Api])
  Guard/
    SessionGuard.php           <- Public (#[Api])
  Internal/
    TokenHasher.php            <- Internal (only Auth can use)
    SessionStorage.php         <- Internal (only Auth can use)
```

### DI wiring pattern

When an `Internal/` class implements a public interface, wire it in the composition root:

```php
// In Kernel or service provider (composition root, exempt)
$container->bind(AuthManagerInterface::class, Internal\AuthManager::class);
```

Consumer code depends on the interface only, never on the `Internal\` class directly.

## Composition root whitelist

These classes wire the entire system together and are exempt from cross-module import restrictions:

- `Pulsar\Core\Kernel`
- `Pulsar\Console\Application`

## Module tiers

The framework organizes modules into dependency tiers:

| Tier           | Modules                                           |
| -------------- | ------------------------------------------------- |
| Foundation     | Api, Support                                      |
| CoreTier       | Container, Config, Core, ErrorHandling            |
| Infrastructure | Http, Routing, Console, Extensibility             |
| Features       | Auth, Cache, Database, Observability, Queue, etc. |

Deptrac allows all public-to-public cross-tier dependencies. The custom script enforces `#[Api]` targeting at the semantic level.

Internal layers are restricted: they may only depend on their own module's public layer plus Foundation and CoreTier.

## Enforcement tools

### Deptrac (structural fences)

Config: `tools/php/deptrac.yaml`

Run: `composer deptrac`

Deptrac enforces namespace-level dependency rules:

- **Core -> Extensions**: `src/` code cannot import from `extensions/`
- **Extension isolation**: extensions cannot import from other extensions' internal code
- **Contract/internal boundary**: only `Contracts/` and `Domain/` layers are accessible from outside an extension

Layers defined:

| Layer             | Scope                                                                              |
| ----------------- | ---------------------------------------------------------------------------------- |
| CompositionRoot   | `Kernel`, `Application`                                                            |
| Core              | Everything in `Pulsar\` except extensions                                          |
| PaymentsContracts | `Pulsar\Extension\Payments\Contracts\`                                             |
| PaymentsDomain    | `Pulsar\Extension\Payments\Domain\`, `Exception\`, `Idempotency\`, `WebhookClaim*` |
| PaymentsInternal  | Everything else in Payments                                                        |
| ExampleExtension  | `Pulsar\Extension\Example\`                                                        |

### PHPUnit architecture tests

Config: standard PHPUnit in `tests/Unit/Integrity/ArchitectureRulesTest.php`

Run: `vendor/bin/phpunit -c tools/php/phpunit.xml --filter ArchitectureRulesTest`

Uses `token_get_all()` to analyze all PHP files in `src/` and `extensions/*/src/`. Detects:

- `use` statements (including grouped uses)
- Fully-qualified inline references (`\Pulsar\Foo\Bar::class`)

### Support classes

| Class                  | Purpose                                                     |
| ---------------------- | ----------------------------------------------------------- |
| `ModuleMap`            | Maps FQCNs to module identity (`core:Auth`, `ext:Payments`) |
| `ImportAnalyzer`       | Extracts all Pulsar class references from PHP files         |
| `VisibilityClassifier` | Resolves `#[Api]`/`#[Internal]` visibility via reflection   |

Located in `tests/Unit/Integrity/Support/`.

## CLI reference

### Quick commands

```bash
composer boundary:check    # Run both Deptrac + custom script
composer boundary:deptrac  # Deptrac structural analysis only
composer boundary:custom   # Custom #[Api] targeting analysis only
```

### Custom script flags

```bash
php scripts/boundary_check.php                        # Full scan (warnings only)
php scripts/boundary_check.php --diff-base=origin/main  # Errors for changed files
php scripts/boundary_check.php --generate-baseline    # Generate/update baseline
php scripts/boundary_check.php --strict               # All violations are errors
php scripts/boundary_check.php --json                 # JSON output
```

### CI usage

PRs: `--diff-base=origin/$BASE_REF` flags changed files as errors.

Pushes to main: full scan, all violations are warnings (informational).

## The baseline

Two separate baselines exist:

- `tests/Unit/Integrity/architecture-baseline.json`: used by PHPUnit architecture tests
- `tools/php/boundary-baseline.json`: used by the custom boundary check script

Classes in the baseline are known cross-module `#[Internal]` imports that predate the enforcement rules. The tests pass as long as no new violations appear.

### Shrinking the baseline

1. Add `#[Api]` to a baselined class that should be public.
2. Remove it from the baseline JSON.
3. Run `composer test` to verify.

### Adding to the baseline (last resort)

1. Add the target FQCN as a key in the JSON file.
2. Include a description explaining why the exception is necessary.
3. Get code review approval. Baselines should shrink over time, not grow.

### Regenerating the baseline

```bash
php scripts/boundary_check.php --generate-baseline
```

The baseline is a one-way ratchet: entries can only be removed, never added (adding requires updating the baseline file explicitly).

## Runtime enforcement (development only)

In addition to static analysis via Deptrac and the custom boundary check script, Pulsar provides optional runtime boundary enforcement through `BoundaryGuard`.

### BoundaryGuard

`Pulsar\Container\Internal\BoundaryGuard` is a development-only decorator for `ContainerInterface`. When active, it checks each `get()` call to verify that the resolved service's namespace is accessible from the caller's namespace.

**What it detects:**

- Cross-module resolution of `\Internal\` namespace services at runtime

**How it works:**

1. On `get($id)`, the guard walks the debug backtrace to identify the calling class
2. It extracts the module identity of both the caller and the resolved service
3. If they are in different modules and the service is in an `\Internal\` namespace, it reports a violation

**Configuration:**

```php
// In a development-only service provider:
if ($config->isDebug()) {
    $guard = new BoundaryGuard(
        inner: $container,
        logger: $logger,
        throwOnViolation: false, // Log only (default): set true for strict mode
    );
    // Use $guard as the container for downstream consumers
}
```

**Production behavior:** BoundaryGuard is never applied in production. The decorator is only instantiated when `APP_DEBUG=true`. In production, the original container is used directly with zero overhead.

### Baseline ratchet

The `scripts/boundary_ratchet.php` script ensures the boundary baseline only shrinks over time:

```bash
php scripts/boundary_ratchet.php                     # Compare against HEAD~1
php scripts/boundary_ratchet.php --base=origin/main  # Compare against a specific ref
php scripts/boundary_ratchet.php --json               # JSON output
```

The ratchet compares the current `tools/php/boundary-baseline.json` against the previous commit's version:

- **No new violations**: PASS
- **Violations removed**: PASS (baseline shrank)
- **New violations added**: FAIL (baseline grew)

This prevents boundary violations from accumulating. The ratchet can be added to CI as a post-check after `composer boundary:check` to enforce the one-way shrink policy.

## Adding a new extension

When adding a new extension to `extensions/`, update `tools/php/deptrac.yaml`.

**Extension with Contracts split:**

```yaml
- name: MyExtContracts
  collectors:
   - type: classLike
      value: ^Pulsar\\Extension\\MyExt\\Contracts\\

- name: MyExtDomain
  collectors:
   - type: classLike
      value: ^Pulsar\\Extension\\MyExt\\Domain\\
   - type: classLike
      value: ^Pulsar\\Extension\\MyExt\\Exception\\

- name: MyExtInternal
  collectors:
   - type: bool
      must:
       - type: classLike
          value: ^Pulsar\\Extension\\MyExt\\
      must_not:
       - type: layer
          value: MyExtContracts
       - type: layer
          value: MyExtDomain
```

Add ruleset entries:

```yaml
MyExtContracts:
  - Core
  - MyExtDomain
MyExtDomain:
  - Core
MyExtInternal:
  - Core
  - MyExtContracts
  - MyExtDomain
```

**Extension without Contracts split:**

```yaml
- name: MyExtension
  collectors:
   - type: classLike
      value: ^Pulsar\\Extension\\MyExt\\
```

```yaml
MyExtension:
  - Core
```

The `deptrac_config_covers_all_extensions` test fails CI if an extension is not covered.

## Adding a new module

Checklist when adding a new module under `src/`:

1. **Pick a tier** (Foundation, CoreTier, Infrastructure, or Feature)
2. **Add to `tools/php/deptrac.yaml`**:
   - Public layer with bool collector (include `^Pulsar\\{Module}\\`, exclude `\\Internal\\`)
   - Internal layer with classLike collector (`^Pulsar\\{Module}\\Internal\\`)
   - Add both layers to the rulesets section
3. **Create `Internal/` subdirectory** if the module has implementation details
4. **Mark public API** with `#[Api]` attribute
5. **Run** `composer api:snapshot` to update the API snapshot
6. **Run** `composer boundary:check` to verify no violations

## Adding a new rule

1. Add a test method to `ArchitectureRulesTest.php`.
2. Add a fixture file to `tests/Unit/Integrity/Fixture/` that deliberately violates the rule.
3. Add a fixture validation test that proves the detection works.
4. Document the rule in this file.

## ADR reference

Architectural decisions that exempt specific patterns from boundary rules should be documented as ADRs under `docs/adr/`. See [ADR-0002](adr/) and [ADR-0009](adr/) for the foundational boundary enforcement decisions.

## Related docs

- [Architecture](architecture.md): overall system architecture
- [Public API](public-api.md): `#[Api]` attribute usage and snapshot management
- [Integrity](integrity.md): integrity verification and code signing
- [Modular monolith](modular-monolith.md): module design principles
