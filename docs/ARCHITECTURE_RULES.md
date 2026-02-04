# Architecture Rules

Pulsar enforces architectural boundaries through two complementary tools:

- **Deptrac** — structural namespace fences (extension isolation, core/extension wall)
- **PHPUnit architecture tests** — attribute-aware boundary checks (`#[Api]`/`#[Internal]`)

Both run as part of `composer qa` and CI. Violations fail the build.

## The Rules

### Rule 1: No Cross-Module Controller References

A module must not import controllers from another module. Controllers are internal to their module and invoked only through routing.

**Violation:**

```php
namespace Pulsar\Auth;

use Pulsar\Studio\Server\Controller\ApiController; // FORBIDDEN
```

**Fix:** Use a route or event to trigger behavior in another module. Never reference another module's controllers directly.

### Rule 2: No Cross-Module View References

A module must not import view classes from another module. Views are internal to their module's presentation layer.

**Violation:**

```php
namespace Pulsar\Auth;

use Pulsar\Studio\Server\View\ViewRenderer; // FORBIDDEN
```

**Fix:** If you need rendering capabilities, depend on an `#[Api]` rendering interface, not a concrete view implementation from another module.

### Rule 3: Cross-Module Imports Must Target `#[Api]` Classes

When importing a class from another module, the target class must have the `#[Api]` attribute. Classes with `#[Internal]` or no visibility attribute are forbidden for cross-module use.

**Violation:**

```php
namespace Pulsar\Studio\Command;

use Pulsar\Cache\FrameworkCache; // FORBIDDEN — FrameworkCache is #[Internal]
```

**Fix options:**

1. **Add `#[Api]` to the target class** if it represents stable, public API.
2. **Create an `#[Api]` interface** and depend on the interface instead.
3. **Move the usage within the same module** so it's not a cross-module import.
4. **Baseline (last resort)**: Add the target class to `tests/Unit/Integrity/architecture-baseline.json`.

**Exemptions:** Composition roots (`Pulsar\Core\Kernel`, `Pulsar\Console\Application`) are exempt because they wire the entire framework together.

### Rule 4: Adapters Must Not Leak Into Contracts

Files in `\Contract\` or `\Contracts\` namespaces must not import from `\Adapter\` or `\Provider\` namespaces. Contracts define the public interface; adapters implement it.

**Violation:**

```php
namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider; // FORBIDDEN
```

**Fix:** Contracts should only reference domain types, exceptions, and other contracts. If a contract method needs to return a provider-specific type, extract it into the domain layer.

### Rule 5: Core Must Have No Forbidden Vendor Dependencies

The framework's `composer.json` `require` section (production dependencies) must not include vendor SDK packages. Only `php`, `ext-*` extensions, and `psr/*` interfaces are allowed by default.

A denylist explicitly blocks known vendor SDKs (aws, stripe, sentry, etc.). Packages not on either list are allowed but should be reviewed.

**Violation example:** Adding `"stripe/stripe-php": "^14.0"` to `require` would fail this rule.

**Fix:** Vendor-specific integrations belong in extensions, not core. Use `require-dev` for development tools.

## Enforcement Tools

### Deptrac (Structural Fences)

Config: `tools/php/deptrac.yaml`

Run: `composer deptrac`

Deptrac enforces namespace-level dependency rules:

- **Core ↛ Extensions**: `src/` code cannot import from `extensions/`
- **Extension isolation**: Extensions cannot import from other extensions' internal code
- **Contract/internal boundary**: Only `Contracts/` and `Domain/` layers are accessible from outside an extension

Layers defined:

| Layer             | Scope                                                                              |
| ----------------- | ---------------------------------------------------------------------------------- |
| CompositionRoot   | `Kernel`, `Application`                                                            |
| Core              | Everything in `Pulsar\` except extensions                                          |
| PaymentsContracts | `Pulsar\Extension\Payments\Contracts\`                                             |
| PaymentsDomain    | `Pulsar\Extension\Payments\Domain\`, `Exception\`, `Idempotency\`, `WebhookClaim*` |
| PaymentsInternal  | Everything else in Payments                                                        |
| ExampleExtension  | `Pulsar\Extension\Example\`                                                        |

### PHPUnit Architecture Tests

Config: standard PHPUnit in `tests/Unit/Integrity/ArchitectureRulesTest.php`

Run: `vendor/bin/phpunit -c tools/php/phpunit.xml --filter ArchitectureRulesTest`

Uses `token_get_all()` to analyze all PHP files in `src/` and `extensions/*/src/`. Detects:

- `use` statements (including grouped uses)
- Fully-qualified inline references (`\Pulsar\Foo\Bar::class`)

### The Baseline

File: `tests/Unit/Integrity/architecture-baseline.json`

Classes in the baseline are known cross-module `#[Internal]` imports that predate the enforcement rules. The test passes as long as no NEW violations appear.

To shrink the baseline:

1. Add `#[Api]` to a baselined class that should be public.
2. Remove it from the baseline JSON.
3. Run `composer test` to verify.

To add to the baseline (last resort):

1. Add the target FQCN as a key in the JSON file.
2. Include a description explaining why the exception is necessary.
3. Get code review approval — baselines should shrink over time, not grow.

## Adding a New Extension

When adding a new extension to `extensions/`, you must update `tools/php/deptrac.yaml`:

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

The `deptrac_config_covers_all_extensions` test will fail CI if an extension is not covered.

## Adding a New Rule

1. Add a test method to `ArchitectureRulesTest.php`.
2. Add a fixture file to `tests/Unit/Integrity/Fixture/` that deliberately violates the rule.
3. Add a fixture validation test that proves the detection works.
4. Document the rule in this file.

## Support Classes

| Class                  | Purpose                                                     |
| ---------------------- | ----------------------------------------------------------- |
| `ModuleMap`            | Maps FQCNs to module identity (`core:Auth`, `ext:Payments`) |
| `ImportAnalyzer`       | Extracts all Pulsar class references from PHP files         |
| `VisibilityClassifier` | Resolves `#[Api]`/`#[Internal]` visibility via reflection   |

Located in `tests/Unit/Integrity/Support/`.
