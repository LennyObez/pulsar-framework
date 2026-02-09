# File Integrity

Pulsar includes a file integrity verification system that detects unauthorized filesystem changes in deployed applications. It uses SHA-256 content hashes with optional HMAC-BLAKE2b signing for tamper-evident manifests.

## Overview

The integrity system works in three phases:

1. **Build** - Scan configured paths, compute SHA-256 hashes, produce a manifest
2. **Sign** (optional) - HMAC-BLAKE2b sign the manifest using a derived subkey
3. **Verify** - Compare the stored manifest against the current filesystem

This is designed for production environments where filesystem tampering must be detected (e.g., regulated industries, compliance requirements).

## Configuration

Configure file integrity in `config/integrity.php`:

```php
// config/integrity.php
return [
    'mode' => 'verify', // 'disabled', 'verify', 'enforce'

    'include' => [
        'src/**/*.php',
        'config/*.php',
        'public/index.php',
        'composer.lock',
    ],

    'exclude' => [
        'storage/**',
        'tests/**',
        'vendor/**',
    ],

    'manifest_path' => 'storage/integrity/manifest.json',
];
```

### Policy Modes

| Mode       | Behavior                                             |
| ---------- | ---------------------------------------------------- |
| `disabled` | Integrity checks are skipped entirely                |
| `verify`   | Checks run and report results but do not block       |
| `enforce`  | Checks run and failures trigger alerts or block boot |

## Building a Manifest

Build a manifest from the current filesystem state:

```bash
php bin/pulsar integrity:build
```

This scans all files matching the configured `include` patterns (excluding `exclude` patterns), computes SHA-256 hashes, and writes the manifest to the configured `manifest_path`.

### Signing

Sign the manifest with HMAC-BLAKE2b for tamper detection:

```bash
php bin/pulsar integrity:build --sign
```

Signing requires `PULSAR_MASTER_KEY` to be set. The signing key is derived using subKeyId=6 with context `integ_sg`, ensuring domain separation from other key uses (encryption, cache MAC, etc.).

### Custom Output Path

```bash
php bin/pulsar integrity:build --output=deploy/manifest.json
```

## Verifying Integrity

Verify the current filesystem against a stored manifest:

```bash
php bin/pulsar integrity:verify
```

The verifier reports four categories:

| Category | Meaning                                    |
| -------- | ------------------------------------------ |
| Verified | File hash matches the manifest             |
| Modified | File exists but hash differs from manifest |
| Missing  | File is in the manifest but not on disk    |
| Added    | File is on disk but not in the manifest    |

### Strict Mode

Use `--strict` to fail on any added or missing files (not just modified ones):

```bash
php bin/pulsar integrity:verify --strict
```

### JSON Output

```bash
php bin/pulsar integrity:verify --json
```

Returns a JSON object with counts and detailed file lists for each category.

## Repairing the Manifest

If legitimate changes have been made (e.g., a deployment), regenerate the manifest:

```bash
php bin/pulsar integrity:repair --confirm
```

The `--confirm` flag is a required safety gate to prevent accidental manifest replacement.

## Cryptographic Design

### Hash Algorithm

Files are hashed with SHA-256. The manifest stores the algorithm identifier alongside each hash for future extensibility.

### Manifest Signing

The manifest signature uses HMAC-BLAKE2b via `ManifestSigner`:

- **Key derivation**: `MasterKey::deriveSubKey(id: 6, context: 'integ_sg')`
- **Input**: Canonical JSON representation of the manifest (excluding the signature field)
- **Output**: Hex-encoded BLAKE2b HMAC appended to the manifest

Signature verification uses constant-time comparison to prevent timing attacks.

### Key Separation

The integrity signing key (subKeyId=6) is distinct from all other derived keys:

| SubKey ID | Purpose            | Context String |
| --------- | ------------------ | -------------- |
| 1         | Encryption         | `encrypt_`     |
| 2         | Audit HMAC         | `audit___`     |
| 3         | Studio encryption  | `stud_enc`     |
| 4         | Studio archive MAC | `stud_mac`     |
| 5         | Studio chain MAC   | `stud_chn`     |
| 6         | Integrity signing  | `integ_sg`     |
| 7         | Cache HMAC         | `fw_cache`     |

## Workflow

### Initial Setup

```bash
# 1. Build and sign the manifest
php bin/pulsar integrity:build --sign

# 2. Commit the manifest to version control or deploy artifact
git add storage/integrity/manifest.json
```

### Deployment Verification

```bash
# 3. After deployment, verify integrity
php bin/pulsar integrity:verify --strict --json

# 4. If verification fails, investigate before proceeding
```

### CI/CD Integration

Include integrity verification in your deployment pipeline:

```yaml
deploy:
  steps:
   - run: php bin/pulsar integrity:verify --strict
   - run: php bin/pulsar deploy:check --env=production --strict
   - run: php bin/pulsar health:check
```

## Studio Guardian Integration

The Studio Guardian subsystem provides CLI access to integrity operations with JSON output:

```bash
# Build via guardian
php bin/pulsar studio:console:guardian:integrity:build --sign --json

# Verify via guardian
php bin/pulsar studio:console:guardian:integrity:verify --strict --json
```

Guardian commands follow the same logic as the core commands but integrate with Studio's JSON output helpers and event pipeline.
