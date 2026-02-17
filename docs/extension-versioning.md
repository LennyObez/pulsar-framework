# Extension versioning

Pulsar extensions follow semantic versioning. This document explains how core and community extensions are versioned, how compatibility is declared, and how the framework enforces version constraints at boot time.

## Core extensions

Core extensions ship with the framework and are maintained by the Pulsar team. These include:

- `pulsar/admin`, `pulsar/cms`, `pulsar/studio`, `pulsar/orm`, `pulsar/analytics`
- `pulsar/forum`, `pulsar/graphql`, `pulsar/oauth2`, `pulsar/webauthn`
- `pulsar/grpc`, `pulsar/mcp-server`, `pulsar/observability`, `pulsar/payments`
- Other extensions under the `pulsar/` vendor namespace

Core extensions are versioned in lockstep with the framework. When Pulsar releases version 1.0.0, all core extensions also release 1.0.0. When Pulsar releases 1.1.0, core extensions release 1.1.0.

This lockstep approach has a practical benefit: you never need to figure out which version of `pulsar/admin` works with which version of the framework. The answer is always "the same version."

Core extensions set their `min_version` in `pulsar.json` to match the framework release they ship with. For example, the admin extension's manifest during the RC phase:

```json
{
  "name": "pulsar/admin",
  "version": "1.0.0-rc.11",
  "pulsar": {
    "min_version": "1.0.0-rc.11"
  }
}
```

## Community extensions

Community extensions use independent versioning. A community extension might be at version 3.2.0 while the framework is at 1.1.0. This is expected and normal.

Community extensions declare their framework compatibility through the `pulsar` section in their `pulsar.json` manifest. The framework validates this at boot time and refuses to load incompatible extensions.

## Declaring framework compatibility

Every extension must declare which framework versions it supports using the `pulsar` section of its `pulsar.json` manifest. The `ExtensionManifest` class (at `src/Extensibility/ExtensionManifest.php`) parses these constraints and the `PulsarVersionConfig` validates them against the running framework version.

### The min_version field

The `min_version` field specifies the oldest framework version the extension supports:

```json
{
  "name": "acme/reports",
  "version": "2.0.0",
  "pulsar": {
    "min_version": "1.0.0"
  }
}
```

This extension works with Pulsar 1.0.0 and any later version. It will not load on Pulsar 0.9.x or earlier.

### The max_version field

The optional `max_version` field sets an upper bound:

```json
{
  "name": "acme/reports",
  "version": "2.0.0",
  "pulsar": {
    "min_version": "1.0.0",
    "max_version": "2.0.0"
  }
}
```

This extension works with Pulsar 1.0.0 through 1.x.x, but will not load on Pulsar 2.0.0 or later. Use `max_version` when you know your extension relies on APIs that may change in the next major release.

If you omit `max_version`, the extension is assumed to work with all future versions. This is fine for simple extensions that use only stable, well-established APIs.

### Version format

Both fields must follow semver format: `MAJOR.MINOR.PATCH` with an optional prerelease suffix (e.g., `1.0.0-beta.1`). The manifest parser validates the format and rejects invalid versions with a `ManifestException::invalidVersion()` error.

## Extension-to-extension dependencies

Extensions can depend on other extensions using the `requires` field:

```json
{
  "name": "acme/analytics-dashboard",
  "version": "1.0.0",
  "requires": {
    "pulsar/analytics": ">=1.0.0",
    "acme/charts": "^2.0.0"
  }
}
```

The `ExtensionLoader` resolves these dependencies and sorts extensions into a valid boot order. If a required extension is missing or its version does not satisfy the constraint, the loader throws a `DependencyException`. Circular dependencies are detected and reported as errors.

## What happens at boot time

When the kernel boots, the `ExtensionLoader` runs through this sequence:

1. **Discovery.** Scan all configured extension paths for `pulsar.json` manifests.
2. **Validation.** Parse each manifest via `ExtensionManifest::fromFile()`. Check that required fields are present, version format is valid, and the `extension_class` exists.
3. **Compatibility check.** Call `PulsarVersionConfig::isSatisfiedByCurrent()` to verify the running framework version falls within the declared range. Extensions that fail this check transition to the `Failed` lifecycle state and are skipped.
4. **Dependency resolution.** Build a dependency graph from `requires` fields. Topologically sort extensions so that dependencies boot before dependents.
5. **Lifecycle execution.** Run the four-phase boot pipeline (Register, PreBoot, Boot, PostBoot) in the resolved order.

If an extension fails at any point, it transitions to `ExtensionLifecycle::Failed` and the error is logged. Other extensions continue loading normally unless they depend on the failed extension.

## Compatibility guidelines for extension authors

When deciding on your `min_version` and `max_version`:

- Set `min_version` to the oldest framework version where the APIs you use exist. If you use a feature introduced in 1.1, set `min_version` to `1.1.0`.
- Only set `max_version` if you are certain your extension will break on the next major. If you are unsure, leave it out and test against major release candidates when they become available.
- When the framework releases a new major version, test your extension and publish an updated release with the new `min_version`.
- Use the `#[Api]` and `#[Deprecated]` attributes in the API snapshot (`tools/api/public-api.snapshot.json`) to check whether the APIs you depend on are stable or experimental. Stable APIs will not change within a major version. Experimental APIs may change in minor releases.

## Semver rules for extensions

Extensions follow the same semver contract as the framework:

- **Patch** (1.0.1): bug fixes only, no API changes.
- **Minor** (1.1.0): new features, new API, deprecations. Never removes existing API.
- **Major** (2.0.0): may remove deprecated API. Accompanied by a migration guide.

The `BcBreakDetector` can compare two versions of your extension's API snapshot to catch accidental breaking changes before you publish.
