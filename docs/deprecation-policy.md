# Deprecation policy

Pulsar follows semantic versioning for all public API surfaces. This document explains how deprecations are introduced, communicated, and eventually resolved through removal.

## Semver rules

Pulsar treats any symbol marked with `#[Api]` as a public contract. These symbols are covered by strict semver guarantees:

- **Patch releases** (1.0.1, 1.0.2) never deprecate or remove public API. They contain only bug fixes and security patches.
- **Minor releases** (1.1, 1.2) may deprecate public API, but never remove it. Deprecated symbols continue to work exactly as before.
- **Major releases** (2.0, 3.0) may remove symbols that were deprecated in a prior minor release.

The short version: deprecated in a minor, removed in the next major. A symbol deprecated in 1.3 will be removed no earlier than 2.0.

## How deprecations are marked

Pulsar uses two complementary mechanisms to flag deprecated code.

### The #[Deprecated] attribute

The `Pulsar\Api\Deprecated` attribute is the primary deprecation marker. It carries structured metadata that tooling can read:

```php
use Pulsar\Api\Deprecated;

#[Deprecated(since: '1.2', removeIn: '2.0', replacement: 'newMethod()')]
public function oldMethod(): void
{
    // Still works, but triggers E_USER_DEPRECATED on first call
}
```

The attribute supports four fields:

| Field         | Purpose                                              |
| ------------- | ---------------------------------------------------- |
| `since`       | The version that introduced the deprecation          |
| `removeIn`    | The version where the symbol will be removed         |
| `replacement` | The recommended replacement (method, class, or path) |
| `reason`      | Why the deprecation happened                         |

When a deprecated symbol is called at runtime, `Pulsar\Api\DeprecationReporter` triggers an `E_USER_DEPRECATED` notice with a message built from these fields. Each symbol is reported only once per process to avoid log flooding.

### PHPDoc @deprecated tags

For cases where attributes cannot be applied (such as interface constants in older PHP versions or parameters), the `@deprecated` PHPDoc tag serves as a fallback:

```php
/**
 * @deprecated since 1.2, use newMethod() instead. Will be removed in 2.0.
 */
public function oldMethod(): void {}
```

Both PHPStan and Psalm detect `@deprecated` tags and report usages during static analysis.

## Deprecation timeline

Every deprecation follows a minimum lifecycle:

1. **Introduction.** A deprecation is introduced in a minor release (e.g., 1.2.0). The old symbol continues to work. The `#[Deprecated]` attribute and `@deprecated` PHPDoc are added. Release notes document the deprecation with migration instructions.

2. **Grace period.** The deprecated symbol remains functional for at least one full minor release cycle. If deprecated in 1.2, it works through all 1.2.x and 1.3.x releases.

3. **Removal.** The symbol is removed in the next major release (e.g., 2.0.0). The migration guide for that major release covers every removal.

In practice, most deprecations live much longer than one minor cycle because major releases happen every 18 to 24 months.

## Checking for deprecations

### Runtime notices

In development, set your error handler to capture `E_USER_DEPRECATED` notices. Pulsar's `DeprecationReporter` emits structured messages like:

```
MyApp\Service::oldMethod is deprecated since 1.2, will be removed in 2.0. Use newMethod() instead.
```

### The API snapshot

Pulsar maintains a machine-readable public API snapshot at `tools/api/public-api.snapshot.json`. This file records every `#[Api]` and `#[Deprecated]` symbol in the framework. You can use `DeprecationReporter::fromSnapshot()` to extract all current deprecations:

```php
$snapshot = json_decode(file_get_contents('tools/api/public-api.snapshot.json'), true);
$deprecations = DeprecationReporter::fromSnapshot($snapshot);

foreach ($deprecations as $entry) {
    echo "{$entry['symbol']} deprecated since {$entry['since']}, remove in {$entry['removeIn']}\n";
}
```

### Static analysis

Both PHPStan and Psalm flag usages of `@deprecated` symbols. Running `composer phpstan` and `composer psalm` will surface every deprecated call in your application code.

### BC break detection

The `Pulsar\Api\BcBreakDetector` compares two API snapshots to detect removed classes, removed methods, and changed signatures on stable APIs. CI runs this automatically on every pull request to catch accidental removals:

```php
$detector = new BcBreakDetector();
$breaks = $detector->detect($previousSnapshot, $currentSnapshot);
```

Each `BcBreak` includes a type (`ClassRemoved`, `MethodRemoved`), the affected symbol, a human-readable message, and a severity based on the symbol's stability grade.

## Migration guides

Every major release ships with a migration guide that covers all breaking changes. Each entry in the guide includes:

- The old symbol and its replacement
- A before/after code example
- The version where the deprecation was first introduced
- Any behavioral differences between the old and new API

Migration guides live in `docs/` and are linked from the release notes.

## Stability grades

The `#[Api]` attribute includes a `stability` field that affects how deprecation rules are applied:

| Grade          | Meaning                                                                                                               |
| -------------- | --------------------------------------------------------------------------------------------------------------------- |
| `stable`       | Full semver guarantees. Removal requires major version bump.                                                          |
| `experimental` | May change in minor releases. Still flagged with `#[Deprecated]` before removal, but the grace period may be shorter. |

Experimental APIs are clearly marked in the API snapshot and documentation. They graduate to stable once the design is proven, typically within one or two minor releases.

## Summary

The deprecation workflow in one sentence: mark it with `#[Deprecated]` in a minor, keep it working for at least one more minor, remove it in the next major, and document the migration path.
