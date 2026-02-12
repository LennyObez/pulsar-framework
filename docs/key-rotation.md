# Key rotation runbook

## Overview

Pulsar derives all cryptographic subkeys from a single `PULSAR_MASTER_KEY` using `sodium_crypto_kdf_derive_from_key` with domain-separated contexts. Rotation replaces the active key while preserving the previous key for a grace period to allow fallback decryption and audit verification during the transition.

## Prerequisites

- The application must be running with a valid `PULSAR_MASTER_KEY` already set.
- You have access to the `.env` file or environment variable configuration for all deployment targets.
- You can restart application workers after the rotation.

## Rotation procedure

### Step 1: generate a new key

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

### Step 1b: drain queues

Before deploying the new key, drain all queue workers to ensure no in-flight jobs remain encrypted with the old key alone:

```bash
# Stop queue workers and let current jobs finish
php bin/pulsar queue:drain --timeout=60
```

Queue payloads are AEAD-encrypted with the `que_aead` subkey (ID 10). Jobs enqueued with the old key can still be decrypted during the grace period while `PULSAR_MASTER_KEY_PREVIOUS` is set, but draining first avoids edge cases with long-running jobs that span the restart window.

### Step 2: deploy and restart workers

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

### Step 3: clear caches

Clear framework caches that were signed with the old key:

```bash
php bin/pulsar optimize:clear
```

Then rebuild caches with the new key:

```bash
php bin/pulsar optimize
```

### Step 4: verify audit chain integrity

Confirm the audit chain remains verifiable with the new key ring:

```bash
php bin/pulsar studio:console:evidence:verify --mode=tamper-evident --json
```

The verifier uses the current key for new entries and falls back to the previous key for entries signed before rotation. The `kid` (key identifier) field in each chain link indicates which key was used.

### Step 5: verify application health

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

### Step 6: remove the previous key

After a grace period (recommended: 24-72 hours, depending on your session lifetime and cache TTL), remove the previous key:

```bash
# Remove PULSAR_MASTER_KEY_PREVIOUS from .env
# Or unset the environment variable in your deployment configuration
```

Deploy and restart workers again. From this point, data encrypted with the old key that has not been re-encrypted is no longer decryptable.

**Side effects of removing the previous key:**

- **Analytics sessions break.** Visitor IDs derived from the `anal_vis` subkey (ID 20) change when the key rotates. Visitor sessions that span the rotation boundary will appear as new visitors in analytics. Plan key rotation during low-traffic windows to minimize reporting artifacts.
- **Pseudonymized data re-linking.** The `pseudo__` subkey (ID 3) produces different pseudonyms after rotation. If you rely on pseudonymized identifiers for cross-system correlation (e.g., GDPR subject access requests), you must re-pseudonymize affected records during the grace period while both keys are active, or maintain a mapping of old-to-new pseudonyms.

### Step 7: final cache clear

Clear and rebuild caches one more time to ensure no stale references to the previous key remain:

```bash
php bin/pulsar optimize:clear
php bin/pulsar optimize
```

## Key identifier (kid) tracking

Each audit chain link and Studio evidence entry includes a `kid` field that identifies which key was used for signing. This allows the verifier to select the correct key from the key ring during verification.

- Entries created before rotation: `kid` references the old key.
- Entries created after rotation: `kid` references the new key.
- Both are verifiable as long as `PULSAR_MASTER_KEY_PREVIOUS` is set.

## Derived subkey contexts

The master key derives purpose-specific subkeys via KDF. Rotation replaces all derived keys simultaneously:

| Purpose               | Subkey ID | Context    |
| --------------------- | --------- | ---------- |
| Encryption            | 1         | `encrypt_` |
| Audit chain HMAC      | 2         | `audit___` |
| Mail audit HMAC       | 2         | `mailaudt` |
| Session AEAD          | 3         | `session_` |
| Pseudonymization      | 3         | `pseudo__` |
| Studio encryption     | 3         | `stud_enc` |
| Recovery codes        | 3         | `rcvrycod` |
| Studio archive MAC    | 4         | `stud_mac` |
| Studio chain link MAC | 5         | `stud_chn` |
| Integrity manifest    | 6         | `integ_sg` |
| Cache keyed BLAKE2b   | 7         | `fw_cache` |
| Build artifact sign   | 8         | `bld_sign` |
| Queue AEAD            | 10        | `que_aead` |
| Analytics visitor     | 20        | `anal_vis` |

## Per-subsystem key rotation

By default, all subkeys are derived from a single `PULSAR_MASTER_KEY` via KDF. This means rotating the master key rotates every subsystem simultaneously. For high-security deployments, Pulsar supports per-subsystem key overrides that allow independent rotation of individual subsystem keys.

### How it works

When a per-subsystem key override is set via an environment variable, `CompositeKeyProvider` returns that key directly instead of deriving it from the master key. Other subsystems continue to use KDF derivation from the master key.

This reduces blast radius: compromising or rotating one subsystem key does not affect any other subsystem.

### Configuration

Set one or more override environment variables alongside `PULSAR_MASTER_KEY`:

| Subsystem  | Environment variable    | Context    |
| ---------- | ----------------------- | ---------- |
| Encryption | `PULSAR_ENCRYPTION_KEY` | `encrypt_` |
| Audit HMAC | `PULSAR_AUDIT_KEY`      | `audit___` |

Each override must be a hex-encoded raw key of the correct length for its subsystem (typically 32 bytes = 64 hex characters).

```bash
# Example: override only the encryption key
export PULSAR_MASTER_KEY="..."
export PULSAR_ENCRYPTION_KEY="<64 hex chars>"
```

When no override environment variables are set, the system behaves identically to a plain `MasterKey` with no overrides.

### Rotating a single subsystem key

1. Generate a new key for the specific subsystem:

   ```bash
   php -r "echo sodium_bin2hex(random_bytes(32)) . PHP_EOL;"
   ```

2. Update the environment variable (e.g., `PULSAR_ENCRYPTION_KEY`) to the new value.

3. Restart application workers.

4. Clear and rebuild caches:

   ```bash
   php bin/pulsar optimize:clear
   php bin/pulsar optimize
   ```

5. Verify application health:

   ```bash
   php bin/pulsar diagnostics
   ```

Other subsystems (audit, Studio, etc.) are unaffected because they still derive from the unchanged `PULSAR_MASTER_KEY`.

### When to use per-subsystem overrides

- **Compliance**: Regulations require different key lifecycle policies per data domain.
- **Blast radius reduction**: A single subsystem compromise requires rotating only that subsystem key.
- **Phased migration**: Gradually migrate subsystems to independently managed keys.

For most deployments, the standard master key rotation (described above) is sufficient. Per-subsystem overrides add operational complexity and should only be used when there is a clear security or compliance requirement.

## Emergency rotation

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
