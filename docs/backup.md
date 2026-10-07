# Backup and restore

Pulsar takes one sealed, tamper-evident archive per run, reads it back, and puts it back. This page states what an archive contains, what it deliberately does not, and which half of a recovery is yours.

Read the second table before the first. A backup primitive is only as honest as its boundary, and the failure this subsystem exists to prevent is an operator discovering the boundary during the recovery.

## What it is

| Piece                        | Type                                              | What it does                                                  |
| ---------------------------- | ------------------------------------------------- | ------------------------------------------------------------- |
| The contract                 | `Pulsar\Resilience\Backup\BackupServiceInterface` | `backUp()`, `verify()`, `restore()`, `keyId()`                |
| The shipped driver           | `SealedArchiveBackupService`                      | One streaming, sealed archive per run                         |
| The seal                     | `ArchiveSeal`                                     | Derives the archive key, and is the only place it is derived  |
| What a run wrote             | `BackupManifest`                                  | Every entry, its length and its digest — a record, not a plan |
| What a deployment covers     | `BackupPlan`                                      | The configured sources and targets, plus `NOT_COVERED`        |
| What reading one back showed | `BackupVerification`                              | Intact, or which of four distinguishable things went wrong    |
| What a restore put back      | `RestoreReport`                                   | Restored entries **and** every entry no target claimed        |

Three commands drive it: `pulsar backup:run`, `pulsar backup:verify`, `pulsar backup:restore`.

## What is in an archive, and what is not

An archive contains exactly what the plan's sources yield. `BackupWiring` composes the shipped set:

| In the archive                 | Source                 | Notes                                                                                                                                                                    |
| ------------------------------ | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Every row of every user table  | `DatabaseBackupSource` | Newline-delimited JSON, one entry per table, read in batches. Rows only — see below                                                                                      |
| The tamper-evident audit trail | `FileTreeBackupSource` | Rooted at `observability.audit.log_path`, the path the audit sink actually writes to. Not optional, and not configured separately, so it cannot drift away from the sink |
| The configured file trees      | `FileTreeBackupSource` | `resilience.backup.trees`, resolved through `WritablePathGuard`, so a tree inside the document root fails boot rather than being archived and served                     |

And what is not, from `BackupPlan::NOT_COVERED`, which states the same list in code so it can be read off the thing that enforces it:

| Not in the archive                    | Why                                                                                                                                                                                                            |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The master key                        | `PULSAR_MASTER_KEY` seals the archive. Putting it inside would make the seal decorative. Escrow it separately, and understand that an archive without it is unrecoverable — that is the property, not a defect |
| Environment and secrets               | Same reasoning; secrets belong in a secret manager with its own rotation and audit                                                                                                                             |
| Application code and configuration    | Versioned artefacts of the deployed commit. Restoring them would roll code back to whatever was deployed when the backup ran                                                                                   |
| Database schema                       | Restored by running migrations, which are versioned with the code. A DDL snapshot could disagree with the code beside it                                                                                       |
| Offsite replication                   | The archive is written where you point it. Copying it to another failure domain, and proving that copy is readable, is yours                                                                                   |
| Retention and rotation                | Nothing here deletes an old archive. Retention has legal consequences in both directions                                                                                                                       |
| Storage encryption and access control | The archive's content is sealed; the volume it lands on, its permissions and its snapshots are yours                                                                                                           |
| The recovery-time objective           | Nothing here promises a restore fits a recovery window. `backup:restore` into a scratch database, timed, is what establishes that                                                                              |

The database source copies **rows, not schema** — no indexes, sequences, grants or triggers. That is why the recovery order below deploys the commit and migrates before it restores anything.

If your deployment has `mysqldump` or `pg_dump`, they produce better dumps than this ever will, and you should keep using them. What they cannot do is run inside the PHP process from a `pulsar` command with no shell, no second binary on the image and no credentials on a command line, which is why the framework ships a primitive that can.

## The seal

Every archive is sealed with `crypto_secretstream_xchacha20poly1305` under a key derived through `KeyProviderInterface` at the `SubKeyId::BackupArchiveSeal` / `bkup_arc` pair — the same seam every other at-rest protection in the framework goes through. See [ADR-0006](adr/0006-libsodium-only-crypto-master-key-derivation.md) for the sub-key registry, and [key rotation](key-rotation.md) for what rotating the master key does to archives already written.

A streaming AEAD rather than one seal over the whole file, and the difference is what makes a backup takeable at all:

- Every chunk is authenticated **before** its plaintext is handed on, so a restore never writes a byte it has not already authenticated.
- The stream is ordered, so an archive cannot be rearranged out of its own authentic pieces.
- The last chunk carries a FINAL tag, so a truncated archive is detectable as truncated rather than merely shorter.

The archive header — format, cipher, chunk size, archive key id, creation instant — is written **in the clear** and **authenticated as the AEAD's additional data**. In the clear because "this was sealed under a key you do not hold" and "this file is corrupted" must be tellable apart at 3am, and encrypting the answer leaves only "decryption failed" for both. Authenticated because the recorded creation instant is the field an assessor reads to decide whether an archive predates an incident, and rewriting it under a valid seal must not be possible. Nothing else is in the clear: no entry names, no sizes, no source ids, because those describe the estate.

A second, independent mechanism sits inside the seal: a BLAKE2b digest and byte count per entry. It is not redundant. The AEAD catches what happens to the file **after** it is written; the per-entry digest catches what happened **before** — a source that yielded a short read, a target that consumed fewer bytes than it was handed — and those leave the seal perfectly intact.

### It fails closed

With no `PULSAR_MASTER_KEY` there is no archive key, and `BackupWiring` binds **nothing**: no service, no plan, no commands. It does not fall back to an unsealed archive and there is no configuration key that makes it, which is the same posture `SecurityWiring` takes for the encryptor, the token vault and the audit chain. A deployment without a key must report a recovery gap, not a recovery capability with a footnote.

### Memory

Neither the archive nor any entry is held whole. The bound is one 64 KiB plaintext chunk on either side, plus one unit of whatever the source yields — a batch of 500 database rows, one read from a file. The one thing that grows with the estate is the in-memory manifest: a name and two integers per table and per file. That is stated rather than engineered away, because an entry list that cannot be held is one that cannot be printed for you either, and the list is what shows the audit trail is in there.

## Configuration

`config/resilience.php`:

```php
'backup' => [
    'enabled' => true,
    'destination' => 'storage/backups',
    'trees' => ['files' => 'storage/app'],
],
```

| Key           | Default                      | Meaning                                                                                                                                                                                                                                                                                        |
| ------------- | ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `enabled`     | `true`                       | Binds the service and the plan. **Takes no backup.** Also settable with `BACKUP_ENABLED`                                                                                                                                                                                                       |
| `destination` | `storage/backups`            | Where archives are written, relative to the project root unless absolute. Resolved through `WritablePathGuard`. Also `BACKUP_DESTINATION`                                                                                                                                                      |
| `trees`       | `['files' => 'storage/app']` | `entry id => path`. The id becomes the first path segment of every entry the tree contributes, and `audit` is reserved — a tree under that id would make `--require-audit` report a trail the archive does not carry, so the boot refuses it. `'trees' => []` is a real answer and is honoured |

`enabled` defaults to **on**, and it is the only switch in that file that does. Circuit breakers and health checks default off because a deployment that has not chosen thresholds is better off without them; recovery is not like that. No deployment can assert that recovery does not apply to it, so shipping the primitive off would leave every default installation in the state this page was written to end.

The audit trail is **not** configured here. Its directory comes from `observability.audit.log_path` — the path the sink actually writes to — so moving the audit log moves its backup with it. A second key naming the same file is a way for the two to drift, and the drift would be silent and discovered by an assessor asking where the evidence went.

## Taking one

```
pulsar backup:run [--to=PATH] [--require-audit]
```

It prints the **manifest**, not a tick. "Backup complete" tells you nothing you can check; an entry list tells you what is in the file you are holding, and whether the audit trail is in it:

```
  archive   storage/backups/2026-09-02T031500Z.pulsarbk
  sealed    4823104 bytes under archive key 91f3c0a27b4e5d18
  content   4791220 bytes across 37 entries

    database/orders.ndjson                                   4210993 bytes
    audit/audit.jsonl                                         121004 bytes
    files/uploads/logo.png                                      4881 bytes
    ...
```

It also prints what it **skipped** — a file a source could not read is named, never dropped. Pass `--require-audit` in a regulated deployment: the command then exits non-zero when the archive carries no audit trail, so a deployment whose audit logging is off learns it from a failed backup rather than from a recovery.

A failed backup **removes its own archive**. A half-written file with a valid header, sitting where the operator expects the backup, is the most dangerous artefact this subsystem could leave behind.

A backup **never writes over a destination that already holds a file**. The archive is created with an exclusive open, so it is the filesystem that refuses and two runs racing for one path cannot both find it free. This matters because the generated archive name resolves to the second: two runs started inside the same second — a cron entry and a manual run, a retried invocation — ask for the same path, and the second is refused rather than silently replacing the first. Repeat the run, or pass `--to` with another path. The one file whose loss cannot be recovered from is the archive, because the thing that would have recovered it is what overwrote it.

## Verifying one

```
pulsar backup:verify [ARCHIVE] [--all]
```

Verification decrypts every chunk, re-frames every entry and recomputes every entry digest. It is not a `stat`. With no argument it takes the newest archive in the destination; `--all` sweeps every one, and returns a verdict per archive rather than aborting on the first bad one.

Four refusals are distinguishable, because the remedy differs and only one of them means the data is gone:

| Refusal                    | What happened                                       | What to do                                                          |
| -------------------------- | --------------------------------------------------- | ------------------------------------------------------------------- |
| not a Pulsar archive       | The file is something else, or a newer format       | Check what you are holding                                          |
| sealed under another key   | Written under a different master key                | Restore where that key is held, or set `PULSAR_MASTER_KEY_PREVIOUS` |
| failed authentication      | Bytes changed since it was written                  | **Discard the copy.** It must not be restored                       |
| ends without its final tag | A truncated copy — interrupted upload, partial copy | Fetch the whole file again                                          |

**Intact is not recoverable.** Verification proves the archive is complete and readable with the key you hold. It does not prove the database will accept the rows back, that the schema still matches, or that the restore fits your recovery window. Only a restore into a real target proves those.

## Putting one back

```
pulsar backup:restore ARCHIVE [--replace] [--overwrite]
```

The recovery order, and it is not optional:

1. **Deploy the commit** the archive was taken against. Code and configuration are not in the archive.
2. **Run migrations.** The schema is not in the archive.
3. **Restore the rows and files.**

A **live** restore reads the archive through once before it writes anything, and refuses the whole run if it does not read back. `restore()` authenticates every chunk before handing on its plaintext, so no unauthenticated byte ever reaches a target — but it is a stream, and an archive that stops short at entry seven has already put entries one to six into the deployment by the time it refuses. Proving the file whole first is a second read, and a recovery is the one operation where that is worth paying for. A drill writes nothing, so it does not pay it.

Both targets fail closed. A row whose binary column carries a `pulsar_b64` marker that does not decode refuses the entry and names the column, rather than restoring it as an empty value: the seal and the entry digest both pass for a producer that encoded wrongly, so this is the one corruption no other check in the subsystem can see, and a blank where a signature or a document had been would go unnoticed in the one operation nobody re-reads. `DatabaseRestoreTarget` refuses a table that already holds rows unless `--replace` is given, because an append into a populated table produces duplicate keys at best and a silently doubled ledger at worst. `FileTreeRestoreTarget` refuses to overwrite an existing file unless `--overwrite` is given, because someone restoring one lost file into a live tree must not silently roll back every other file beside it. `--replace` uses `DELETE`, not `TRUNCATE`, so the restore stays inside one transaction that a failure halfway can roll back.

Rows are inserted table by table in archive order, which is alphabetical, and that will violate a foreign key whose parent sorts after its child. Restore with constraint checks deferred — a session setting this framework deliberately does not reach in and change on your behalf.

The report names every entry **no target claimed**. A restore run against the wrong target set succeeds at every step it performs and leaves the deployment without its rows; a report that only counted successes would read identically to a complete recovery.

## What compliance reads

`BackupRoundTripObserver` runs on every `composer compliance:check`, against the live service and the deployment's **own** backup destination. It seals a 64-byte synthetic payload into a real archive, verifies it, confirms the bytes at rest carry none of the payload in the clear, flips one byte and requires the service to refuse the result, restores the untouched archive and compares byte for byte, and removes both files it wrote. A removal that fails is reported, not passed over.

`RecoveryCapabilityProbe` carries NIST CSF RC.RP, SOC 2 A1.3, HIPAA §164.308(a)(7) and DORA on that measurement. `backup_primitive_resolved` — which class answers the contract — is printed as supporting evidence and decides nothing, because the honest answer to "which class is bound" is the same on a broken deployment as on a working one ([ADR-0041](adr/0041-the-token-vault-takes-a-connection.md)).

The probe writes under `compliance-round-trip.probe`, never under the destination's chronological archive naming, so it cannot be mistaken for a real archive even in the window it exists.

What a pass still does **not** establish: that an archive was taken recently, that a copy exists in another failure domain, that anyone has restored your real data, or that a restore fits HIPAA's 72 hours. The round trip proves the mechanism works on this host with this key. A timed `backup:restore` into a scratch database, recorded, is what answers the rest — and it is what the standard is actually asking about.

## Related

- [Compliance](compliance.md) — the control map, and what the report will and will not claim
- [Audit logging](audit-logging.md) — the trail every archive carries
- [Key rotation runbook](key-rotation.md) — what rotating `PULSAR_MASTER_KEY` does to archives already written
- [Self-healing and resilience](self-healing.md) — the rest of `config/resilience.php`
