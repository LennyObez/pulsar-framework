# File integrity

Pulsar includes a file integrity verification system that detects unauthorized filesystem changes in deployed applications. It uses SHA-256 content hashes with optional keyed BLAKE2b signing for tamper-evident manifests.

## Overview

The integrity system works in three phases:

1. **Build** - Scan configured paths, compute SHA-256 hashes, produce a manifest
2. **Sign** (optional) - keyed BLAKE2b sign the manifest using a derived subkey
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
        'var/**',
        'tests/**',
        'vendor/**',
    ],

    'manifest_path' => 'var/integrity/manifest.json',
];
```

### Policy modes

| Mode       | Behavior                                             |
| ---------- | ---------------------------------------------------- |
| `disabled` | Integrity checks are skipped entirely                |
| `verify`   | Checks run and report results but do not block       |
| `enforce`  | Checks run and failures trigger alerts or block boot |

## Building a manifest

Build a manifest from the current filesystem state:

```bash
php bin/pulsar integrity:build
```

This scans all files matching the configured `include` patterns (excluding `exclude` patterns), computes SHA-256 hashes, and writes the manifest to the configured `manifest_path`.

### Signing

Sign the manifest with keyed BLAKE2b for tamper detection:

```bash
php bin/pulsar integrity:build --sign
```

Signing requires `PULSAR_MASTER_KEY` to be set. The signing key is derived using subKeyId=6 with context `integ_sg`, ensuring domain separation from other key uses (encryption, cache MAC, etc.).

### Custom output path

```bash
php bin/pulsar integrity:build --output=deploy/manifest.json
```

## Verifying integrity

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

### Strict mode

Use `--strict` to fail on any added or missing files (not just modified ones):

```bash
php bin/pulsar integrity:verify --strict
```

### JSON output

```bash
php bin/pulsar integrity:verify --json
```

Returns a JSON object with counts and detailed file lists for each category.

## Repairing the manifest

If legitimate changes have been made (e.g., a deployment), regenerate the manifest:

```bash
php bin/pulsar integrity:repair --confirm
```

The `--confirm` flag is a required safety gate to prevent accidental manifest replacement.

## Cryptographic design

### Threat model boundary

Integrity verification protects against filesystem tampering by actors who do not have access to `PULSAR_MASTER_KEY`. An attacker who modifies files on disk but cannot access the environment variable will be unable to produce a valid manifest signature, and verification will fail.

However, an actor with access to both the filesystem and the `PULSAR_MASTER_KEY` environment variable can re-sign a modified manifest, bypassing integrity checks entirely. This is an inherent limitation of symmetric-key signing. For environments where environment access is a threat, consider storing the master key in an HSM or external secret manager with access logging, and auditing key access independently of the integrity system.

### Hash algorithm

Files are hashed with SHA-256. The manifest stores the algorithm identifier alongside each hash for future extensibility.

SHA-256 is used for content hashing (rather than BLAKE2b) for three reasons: (1) interoperability with standard tooling (`sha256sum`, `openssl dgst`) allows operators and auditors to independently verify file hashes without Pulsar-specific tools; (2) auditor familiarity -- SHA-256 is universally recognized in compliance contexts and does not require explanation in audit reports; (3) NIST approval -- SHA-256 is FIPS 180-4 approved, which matters in regulated environments where algorithm choice is constrained by policy. Keyed BLAKE2b is used for MAC operations (manifest signing, audit chain integrity) where libsodium integration provides a simpler API, better performance, and the keyed construction is a natural fit for symmetric authentication.

### Manifest signing

The manifest signature uses keyed BLAKE2b via `ManifestSigner`:

- **Key derivation**: `MasterKey::deriveSubKey(id: 6, context: 'integ_sg')`
- **Input**: Canonical JSON representation of the manifest (excluding the signature field)
- **Output**: Hex-encoded keyed BLAKE2b appended to the manifest

Signature verification uses constant-time comparison to prevent timing attacks.

### Key separation

The integrity signing key (subKeyId=6) is distinct from all other derived keys:

| SubKey ID | Purpose             | Context String | Source                                  |
| --------- | ------------------- | -------------- | --------------------------------------- |
| 1         | General encryption  | `encrypt_`     | `Encryptor::fromMasterKey()`            |
| 2         | Audit chain HMAC    | `audit___`     | `SecurityWiring`                        |
| 2         | Mail audit HMAC     | `mailaudt`     | `MailAuditor`                           |
| 3         | Session AEAD        | `session_`     | `SessionEncryption`                     |
| 3         | Pseudonymization    | `pseudo__`     | `PseudonymizationService`               |
| 3         | Studio encryption   | `stud_enc`     | Studio extension (via `withDerivedKey`) |
| 3         | Recovery codes      | `rcvrycod`     | `AuthWiring`                            |
| 4         | Studio archive MAC  | `stud_mac`     | `StudioExtension`                       |
| 5         | Studio chain MAC    | `stud_chn`     | `StudioExtension`                       |
| 6         | Integrity signing   | `integ_sg`     | `ManifestSigner`                        |
| 7         | Cache keyed BLAKE2b | `fw_cache`     | `FrameworkCache`                        |
| 8         | Build artifact sign | `bld_sign`     | `ArtifactIntegrityVerifier`             |
| 10        | Queue AEAD          | `que_aead`     | `AeadPayloadEncryptor`                  |
| 20        | Analytics visitor   | `anal_vis`     | `AnalyticsKeyManager`                   |

> **Note:** Sub-key IDs 2 and 3 are each shared across multiple subsystems but use different context strings, producing cryptographically independent derived keys. IDs 9 and 11-19 are currently unallocated.

## Workflow

### Initial setup

```bash
# 1. Build and sign the manifest
php bin/pulsar integrity:build --sign

# 2. Commit the manifest to version control or deploy artifact
git add storage/integrity/manifest.json
```

### Deployment verification

```bash
# 3. After deployment, verify integrity
php bin/pulsar integrity:verify --strict --json

# 4. If verification fails, investigate before proceeding
```

### CI/CD integration

Include integrity verification in your deployment pipeline:

```yaml
deploy:
  steps:
    - run: php bin/pulsar integrity:verify --strict
    - run: php bin/pulsar deploy:check --env=production --strict
    - run: php bin/pulsar health:check
```

## Studio guardian integration

The Studio Guardian subsystem provides CLI access to integrity operations with JSON output:

```bash
# Build via guardian
php bin/pulsar studio:console:guardian:integrity:build --sign --json

# Verify via guardian
php bin/pulsar studio:console:guardian:integrity:verify --strict --json
```

Guardian commands follow the same logic as the core commands but integrate with Studio's JSON output helpers and event pipeline.
