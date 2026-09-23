<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use Pulsar\Api\Api;

/**
 * Taking a backup, reading it back, and putting it back.
 *
 * The primitive the framework did not have. `docs/compliance.md` recorded its
 * absence as the residual gap behind NIST CSF RC.RP, SOC 2 A1.3 and HIPAA
 * §164.308(a)(7), and recorded it as unclosable by configuration: no deployment
 * can assert that recovery does not apply to it, so the control could not be
 * scoped out and could not be satisfied.
 *
 * THREE OPERATIONS, AND THE MIDDLE ONE IS NOT DECORATION. A backup that has never
 * been read back is a belief about a file. {@see verify()} is therefore a
 * first-class operation with its own console command, it decrypts and re-frames
 * the whole archive rather than stat-ing it, and it is what the compliance probe
 * exercises — because the alternative is a deployment that reports a recovery
 * capability on the strength of a file existing, which is the shape of defect
 * ADR-0041 was written about.
 *
 * WHAT AN IMPLEMENTATION MUST GUARANTEE, because these are the properties the
 * controls above are actually about and none of them is implied by the signatures:
 *
 *  1. The archive is SEALED. Its content is unreadable, and undetectably
 *     unmodifiable, without the key the deployment derives. An implementation that
 *     writes a plain tarball satisfies the interface and satisfies nothing else:
 *     an archive is a copy of every estate the deployment protects, in one file
 *     whose purpose is to leave the host.
 *  2. Modification is REFUSED, not repaired and not reported as a warning.
 *     {@see restore()} throws; it never hands a target a byte it has not already
 *     authenticated, and it never reports a modified archive as restored. A
 *     restore happens under pressure and is the moment a tampered archive is
 *     least likely to be questioned.
 *
 *     What it is NOT, stated because the difference decides an operator's
 *     procedure: restore() is a STREAM, so an archive that fails at entry seven
 *     has already handed entries one to six to their targets before it refuses.
 *     Proving the whole archive before writing is a separate read of the file --
 *     {@see verify()} -- and `pulsar backup:restore --into=live` performs it
 *     before it writes anything, which is where that cost belongs. A caller
 *     driving this interface itself, into live targets, must do the same.
 *  3. Streaming. Neither the archive nor any entry in it is held whole in memory
 *     — see {@see SealedArchiveBackupService} for the bound it actually holds to.
 *  4. Fail closed. Where no key can be derived, an implementation must not be
 *     bound at all rather than write an unsealed archive. That is what
 *     {@see \Pulsar\Core\Wiring\BackupWiring} does, matching how
 *     {@see \Pulsar\Core\Wiring\SecurityWiring} treats every other at-rest
 *     protection.
 *
 * WHAT IS DELIBERATELY NOT HERE: scheduling, retention, rotation, replication to
 * another site, and encryption of the storage the archive lands on. Those are the
 * operator's, they are named as the operator's in `docs/backup.md`, and adding a
 * method for one of them would let a deployment believe the framework was doing
 * it. See {@see BackupPlan} for what a deployment backs
 * up, and {@see BackupPlan::NOT_COVERED} for the boundary in machine-readable form.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface BackupServiceInterface
{
    /**
     * Seal every entry the given sources yield into one archive at $archivePath.
     *
     * @param non-empty-string                 $archivePath
     * @param iterable<BackupSourceInterface>  $sources Read in order; each source's id
     *        becomes the first path segment of every entry it contributes, so entries
     *        cannot collide across sources and the manifest can answer "is the audit
     *        trail in this archive"
     *
     * An implementation MUST NOT write over a destination that already holds a
     * file. It refuses, leaving what is there untouched: the caller chose the path,
     * and a primitive that silently replaced one archive with another would destroy
     * the copy a recovery was going to use in order to make the copy that replaces
     * it. {@see SealedArchiveBackupService} refuses through the open mode, so the
     * check is not a test-then-open a second run can slip between.
     *
     * @throws BackupException when the destination already holds a file, cannot be
     *         written, or a source cannot be read
     */
    public function backUp(string $archivePath, iterable $sources): BackupManifest;

    /**
     * Read the archive back and check it. Writes nothing.
     *
     * Returns a verdict rather than throwing, because "this archive is broken" is
     * the ANSWER to this question and an operator running a verification sweep
     * needs a result per archive, not an abort on the first bad one.
     *
     * @param non-empty-string $archivePath
     */
    public function verify(string $archivePath): BackupVerification;

    /**
     * Put the archive back through the given targets.
     *
     * @param non-empty-string             $archivePath
     * @param list<RestoreTargetInterface> $targets Consulted in order; the first that
     *        accepts an entry receives it. An entry no target accepts is reported in
     *        {@see RestoreReport::$skipped} rather than silently dropped
     *
     * @throws BackupException when the archive is not readable, not authentic, sealed
     *         under another key, truncated, or a target fails to write an entry
     */
    public function restore(string $archivePath, array $targets): RestoreReport;

    /**
     * The identifier of the archive key this deployment derives.
     *
     * Published so `pulsar backup:verify` can say "sealed under a key you do not
     * hold" as a named condition, and so an operator can tell before a recovery
     * whether the host they are on can open the copy they are holding.
     *
     * @return non-empty-string
     */
    public function keyId(): string;
}
