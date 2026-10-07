# Deterministic build pipeline

Pulsar's build pipeline compiles all production artifacts into immutable, content-addressed files. The same input always produces byte-identical output.

## Quick start

```bash
# Build all production artifacts
php bin/pulsar build

# Build with strict mode (fail on closure routes)
php bin/pulsar build --strict

# Build and sign the manifest
php bin/pulsar build --sign

# Verify existing artifacts without rebuilding
php bin/pulsar build --verify

# Build for persistent runtime (FrankenPHP/RoadRunner)
php bin/pulsar build --runtime=persistent
```

## Artifacts

`pulsar build` generates the following artifacts in `var/cache/`:

| File                      | Description                                          |
| ------------------------- | ---------------------------------------------------- |
| `config.compiled.php`     | Compiled configuration arrays                        |
| `container.compiled.php`  | Compiled DI container with pre-resolved dependencies |
| `routes.compiled.php`     | Compiled route table                                 |
| `extensions.manifest.php` | Extension dependency graph (ordered, hashed)         |
| `events_map.php`          | Compiled event listener registry                     |
| `i18n_catalog_index.php`  | Compiled translation catalog index                   |
| `preload.php`             | OPcache preload file (runtime-specific)              |
| `build-manifest.json`     | SHA-256 hashes of all artifacts + optional signature |
| `build-metadata.json`     | Build timestamp, PHP/Pulsar versions (informational) |

## Reproducibility

All artifacts are deterministic:

- **Stable sorting**: Every array and map is sorted by a deterministic key (service ID, extension name, route path, locale, etc.).
- **Path normalization**: All file paths use forward slashes (`/`) regardless of build platform.
- **No timestamps**: Generated artifacts contain no timestamps. Build metadata is stored separately in `build-metadata.json`, which is not part of the content hash.

This means running `pulsar build` twice with identical input produces byte-identical output.

## Integrity verification

### Hash manifest

Every artifact's SHA-256 hash is recorded in `build-manifest.json`. Use `pulsar build --verify` to check all artifacts against their recorded hashes:

```bash
# Returns exit code 0 if all artifacts are current, 1 if stale
php bin/pulsar build --verify
```

### Runtime verification

Enable runtime artifact verification by setting:

```bash
export PULSAR_VERIFY_ARTIFACTS=1
```

When enabled, the Kernel verifies all artifact hashes before loading them at boot time. If any artifact has been modified or is missing, boot fails immediately with a clear error.

### Manifest signing

Optionally sign the build manifest with the application's signing key:

```bash
php bin/pulsar build --sign
```

When a signed manifest is loaded with verification enabled, the signature is also checked. This detects tampering with both the artifacts and the manifest itself. Signing uses the central Keyring with keyed BLAKE2b (subkey derivation with domain separation).

## Production deployment

### Required workflow

1. **Build**: `php bin/pulsar build --strict --sign`
2. **Verify**: `php bin/pulsar build --verify` (CI-friendly, non-mutating)
3. **Deploy**: Ship the `var/cache/` directory with your application
4. **Boot**: Kernel loads from compiled artifacts only (no scanning)

### Production enforcement

In production mode (`APP_ENV=production`), the Kernel:

- Loads **only** from compiled artifacts
- **Fails fast** if any required artifact is missing ("Run `pulsar build` before deploying")
- Performs **no filesystem scanning**, glob discovery, or class reflection in the hot path
- Optionally **verifies integrity** against the hash manifest

### Atomic writes

Every artifact is written to a `*.tmp` file first, then atomically renamed to its final path. The build manifest is written last, after all artifacts are finalized. If `pulsar build` is interrupted mid-write, no partial state is left behind - the previous artifact set remains intact.

## Development mode

In development (`APP_ENV=local` or similar), the build pipeline behaves differently:

- Full filesystem scanning is allowed
- Artifacts are rebuilt on demand
- `pulsar build --verify` checks for stale artifacts and reports which files changed

## Extension graph manifest

The extension graph manifest (`extensions.manifest.php`) captures:

- **Dependency order**: Extensions sorted by topological dependency resolution
- **Deterministic tie-breaking**: Extensions with no dependency edge are sorted alphabetically
- **Config hashes**: SHA-256 of each extension's config files (detect config drift)
- **Code hashes**: SHA-256 of each extension's source files (detect code changes)
- **Enabled state**: Which extensions are active

Cycle detection and missing dependency errors are raised at build time, not at runtime.

## Content hashing

The build manifest tracks content hashes for change detection:

| Hash Key          | Source                          | Purpose                    |
| ----------------- | ------------------------------- | -------------------------- |
| `extension_code`  | Extension source + config files | Detect extension changes   |
| `event_listeners` | Event listener registrations    | Detect listener changes    |
| `i18n_catalog`    | Translation files               | Detect translation changes |

`pulsar build --verify` compares current source hashes against stored hashes to identify stale artifacts.

## Preload generation

The preload file (`preload.php`) is optimized per runtime:

- **FPM**: Preloads core framework classes since every request pays cold-start cost
- **Persistent** (FrankenPHP, RoadRunner): Focuses on container and routing classes for warm-container reuse

Specify the target runtime:

```bash
php bin/pulsar build --runtime=fpm        # Default
php bin/pulsar build --runtime=persistent
```

## Troubleshooting

### "Run `pulsar build` before deploying"

Production mode requires compiled artifacts. Run `pulsar build` and include `var/cache/` in your deployment.

### "Integrity check failed for artifact X"

An artifact has been modified since the last build. Rebuild with `pulsar build`.

### "Build manifest signature verification failed"

The manifest has been tampered with, or the signing key has changed. Rebuild with `pulsar build --sign`.

### Stale artifacts

Run `pulsar build --verify` to check which artifacts are outdated. The output lists each stale artifact and the reason.

### OOM during build

If the build runs out of memory, increase the PHP memory limit:

```bash
php -d memory_limit=512M bin/pulsar build
```
