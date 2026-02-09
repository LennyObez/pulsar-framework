# Key Rotation Runbook

This document describes the procedure for rotating the `PULSAR_MASTER_KEY` used for cache integrity, encryption, audit chain signing, and Studio evidence chain verification.

## Overview

Pulsar derives all cryptographic subkeys from a single `PULSAR_MASTER_KEY` using `sodium_crypto_kdf_derive_from_key` with domain-separated contexts. Rotation replaces the active key while preserving the previous key for a grace period to allow fallback decryption and audit verification during the transition.

## Prerequisites

- The application must be running with a valid `PULSAR_MASTER_KEY` already set.
- You have access to the `.env` file or environment variable configuration for all deployment targets.
- You can restart application workers after the rotation.

## Rotation Procedure

### Step 1: Generate a New Key

Use the CLI command to generate and write the rotated keys:

```bash
php bin/pulsar key:rotate --write
```

This atomically updates the `.env` file:

- Sets `PULSAR_MASTER_KEY` to the newly generated key.
- Sets `PULSAR_MASTER_KEY_PREVIOUS` to the former `PULSAR_MASTER_KEY` value.

Alternatively, perform the rotation manually:

```bash
# Generate a new key (display only)
php bin/pulsar key:rotate

# Then manually update your environment:
# 1. Copy current PULSAR_MASTER_KEY value to PULSAR_MASTER_KEY_PREVIOUS
# 2. Set PULSAR_MASTER_KEY to the new value
```

### Step 2: Deploy and Restart Workers

Deploy the updated `.env` (or environment variables) to all application instances and restart workers:

```bash
# If using the persistent runtime
# Restart the process supervisor (systemd, supervisord, Docker)
systemctl restart pulsar

# If using PHP-FPM
systemctl restart php-fpm
```

During restart, the application loads both keys. The current key is used for all new operations. The previous key is used as a fallback for:

- Decrypting data encrypted with the old key.
- Verifying audit chain entries signed with the old key.
- Verifying Studio evidence chain MACs created with the old key.

### Step 3: Clear Caches

Clear framework caches that were signed with the old key:

```bash
php bin/pulsar optimize:clear
```

Then rebuild caches with the new key:

```bash
php bin/pulsar optimize
```

### Step 4: Verify Audit Chain Integrity

Confirm the audit chain remains verifiable with the new key ring:

```bash
php bin/pulsar studio:console:evidence:verify --mode=tamper-evident --json
```

The verifier uses the current key for new entries and falls back to the previous key for entries signed before rotation. The `kid` (key identifier) field in each chain link indicates which key was used.

### Step 5: Verify Application Health

Run diagnostics to confirm the application is healthy:

```bash
php bin/pulsar diagnostics
php bin/pulsar studio:doctor
```

Check that:

- All extensions load correctly.
- Encrypted sessions and tokens work with the new key.
- Cache integrity verification passes.
- Audit entries created after rotation use the new key.

### Step 6: Remove the Previous Key

After a grace period (recommended: 24-72 hours, depending on your session lifetime and cache TTL), remove the previous key:

```bash
# Remove PULSAR_MASTER_KEY_PREVIOUS from .env
# Or unset the environment variable in your deployment configuration
```

Deploy and restart workers again. From this point, data encrypted with the old key that has not been re-encrypted is no longer decryptable.

### Step 7: Final Cache Clear

Clear and rebuild caches one more time to ensure no stale references to the previous key remain:

```bash
php bin/pulsar optimize:clear
php bin/pulsar optimize
```

## Key Identifier (kid) Tracking

Each audit chain link and Studio evidence entry includes a `kid` field that identifies which key was used for signing. This allows the verifier to select the correct key from the key ring during verification.

- Entries created before rotation: `kid` references the old key.
- Entries created after rotation: `kid` references the new key.
- Both are verifiable as long as `PULSAR_MASTER_KEY_PREVIOUS` is set.

## Derived Subkey Contexts

The master key derives purpose-specific subkeys via KDF. Rotation replaces all derived keys simultaneously:

| Purpose               | Subkey ID | Context              |
| --------------------- | --------- | -------------------- |
| Encryption            | 1         | `encrypt_`           |
| Audit HMAC            | 2         | `pulsar__audit_hmac` |
| Studio encryption     | 3         | `studio_enc__`       |
| Studio archive MAC    | 4         | `studio_mac__`       |
| Studio chain link MAC | 5         | `studio_chain_mac__` |
| Integrity manifest    | 6         | `integ_sg`           |

## Emergency Rotation

If a key compromise is suspected:

1. Generate and deploy a new key immediately (`key:rotate --write`).
2. Restart all workers.
3. Clear all caches (`optimize:clear`).
4. Remove `PULSAR_MASTER_KEY_PREVIOUS` immediately (do not wait for a grace period).
5. Rebuild caches (`optimize`).
6. Re-encrypt any sensitive data that may have been exposed.
7. Rebuild the integrity manifest (`integrity:build --sign`).
8. Audit all access logs for the compromised period.

## Automation

For automated rotation in CI/CD pipelines:

```bash
# Rotate key, write to .env, and clear caches
php bin/pulsar key:rotate --write --clear-cache
php bin/pulsar optimize:clear
php bin/pulsar optimize

# Verify integrity
php bin/pulsar studio:console:evidence:verify --mode=tamper-evident --json
php bin/pulsar diagnostics
```

Ensure your deployment pipeline distributes the updated `.env` to all instances before restarting workers.
