# Boundary Enforcement

Automated enforcement of module boundaries to prevent architectural erosion. Two complementary tools work together:

- **Deptrac** — structural namespace fences (`\Internal\` cross-module, extension isolation, composition root whitelist)
- **Custom script** — semantic API surface enforcement (cross-module imports must target `#[Api]` classes)

## Boundary Rules

| #   | Rule                                   | Tool   | Severity                                             |
| --- | -------------------------------------- | ------ | ---------------------------------------------------- |
| 1   | Cross-module `\Internal\` import       | Both   | Always ERROR                                         |
| 2   | Cross-module import of non-`#[Api]`    | Script | ERROR (extensions + changed files), WARNING (legacy) |
| 3   | Extension depends on App code          | Script | Always ERROR                                         |
| 4   | Cross-extension import of non-`#[Api]` | Script | Always ERROR                                         |

## The `\Internal\` Convention

Classes in `\Internal\` namespaces are implementation details visible only within their own module.

### When to create `Internal/` subdirectories

- **Concrete implementations** of interfaces that should not be used directly
- **Infrastructure wiring** code (adapters, providers) that consumers should ignore
- **Internal data structures** not part of the public contract

### Example structure

```
src/Auth/
  AuthManagerInterface.php     ← Public (#[Api])
  Guard/
    SessionGuard.php           ← Public (#[Api])
  Internal/
    TokenHasher.php            ← Internal (only Auth can use)
    SessionStorage.php         ← Internal (only Auth can use)
```

### DI wiring pattern

When an `Internal/` class implements a public interface, wire it in the composition root:

```php
// In Kernel or service provider (composition root — exempt)
$container->bind(AuthManagerInterface::class, Internal\AuthManager::class);
```

Consumer code depends on the interface only — never on the `Internal\` class directly.

## The `#[Api]` Targeting Rule

Cross-module imports must target classes marked with `#[Api]`. This is stricter than "avoid `#[Internal]`" — it also catches unmarked classes that are internal by default.

### Staged enforcement

| Context                  | Severity | Rationale                                   |
| ------------------------ | -------- | ------------------------------------------- |
| Extensions (always)      | ERROR    | Extensions must only use public API surface |
| New/changed files (diff) | ERROR    | New code must respect boundaries from day 1 |
| Legacy core files        | WARNING  | Transitional — ratchet via baseline         |

### How `#[Api]` lookup works

1. **Snapshot** — loads `tools/api/public-api.snapshot.json` (fast, deterministic)
2. **Reflection** — fallback for classes not in the snapshot
3. **Unresolvable** — returns null, violation skipped (no false positives)

## Composition Root Whitelist

These classes wire the entire system together and are exempt from cross-module import restrictions:

- `Pulsar\Core\Kernel`
- `Pulsar\Console\Application`

## CLI Reference

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

## Module Tiers

The framework organizes modules into dependency tiers:

| Tier           | Modules                                           |
| -------------- | ------------------------------------------------- |
| Foundation     | Api, Support                                      |
| CoreTier       | Container, Config, Core, ErrorHandling            |
| Infrastructure | Http, Routing, Console, Extensibility             |
| Features       | Auth, Cache, Database, Observability, Queue, etc. |

Deptrac allows all public-to-public cross-tier dependencies. The custom script enforces `#[Api]` targeting at the semantic level.

Internal layers are restricted: they may only depend on their own module's public layer plus Foundation and CoreTier.

## Adding New Modules

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

## Ratcheting the Baseline

The baseline file (`tools/php/boundary-baseline.json`) tracks known legacy violations. To reduce it:

1. Pick a violation to fix
2. Either add `#[Api]` to the target class or refactor the import
3. Remove the entry from the baseline
4. Run `composer boundary:check` — the violation is now enforced
5. Commit both the code fix and the baseline update

To regenerate the baseline from scratch:

```bash
php scripts/boundary_check.php --generate-baseline
```

The baseline is a one-way ratchet: entries can only be removed, never added (adding requires updating the baseline file explicitly).

## ADR Reference

Architectural decisions that exempt specific patterns from boundary rules should be documented as ADRs under `docs/adr/`.
