<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * What this deployment backs up, and what it deliberately does not.
 *
 * A PLAN IS A DECLARATION, NOT A MEASUREMENT, and the distinction is the same one
 * ADR-0045 draws everywhere else in this framework. What a plan holds is the
 * sources an operator configured; what {@see BackupManifest} holds is the entries
 * a run actually wrote. The console prints the second after a backup and the
 * first before one, and they are never conflated — a plan that names the audit
 * directory proves nothing about whether the audit trail is in the archive, and
 * only the manifest can answer that.
 *
 * {@see NOT_COVERED} is the other half, and it is stated in code rather than only
 * in prose because an operator has to be able to read the boundary from the thing
 * that enforces it. Naming what a primitive does not do is this project's house
 * style; a backup that leaves an operator to discover its gaps during a recovery
 * is not one.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BackupPlan
{
    /**
     * The entry id under which the audit trail travels in every archive.
     *
     * Here rather than in {@see \Pulsar\Core\Wiring\BackupWiring}, which composes
     * the source that uses it: what an archive calls its audit entries is a
     * property of the archive, and `pulsar backup:run` has to ask
     * {@see BackupManifest::covers()} the same question after the run. A console
     * command reaching into a wiring class for the answer would make the id a
     * detail of the composition root that two modules happen to agree on.
     */
    public const string AUDIT_SOURCE_ID = 'audit';

    /**
     * Estates a Pulsar archive does NOT contain, each with why.
     *
     * These are not gaps to be closed later. Each one is either the operator's by
     * nature or would make the archive less safe to hold:
     *
     * @var array<string, string>
     */
    public const array NOT_COVERED = [
        'The master key' => 'PULSAR_MASTER_KEY seals the archive. Putting it inside would make '
            . 'the seal decorative, and an archive that carries its own key is a plaintext archive '
            . 'with extra steps. Escrow it separately, and understand that an archive without it '
            . 'is unrecoverable — that is the property, not a defect.',
        'Environment and secrets' => 'Everything reached through the environment, including database '
            . 'credentials and third-party keys. Same reasoning, and secrets belong in a secret '
            . 'manager with its own rotation and audit.',
        'Application code and configuration' => 'config/*.php and src/ are versioned artefacts of the '
            . 'deployed commit. Restoring them from an archive would silently roll code back to '
            . 'whatever was deployed when the backup ran; deploy the commit, then restore data.',
        'Database schema' => 'Restored by running the deployment\'s migrations, which are versioned '
            . 'with the code. A DDL snapshot in the archive could disagree with the code beside it.',
        'Offsite replication' => 'The archive is written where the operator points it. Copying it to '
            . 'another failure domain, and proving that copy is readable, is the operator\'s.',
        'Retention and rotation' => 'Nothing here deletes an old archive. Retention is a policy with '
            . 'legal consequences in both directions, and a framework guessing it would be wrong.',
        'Storage-layer encryption and access control' => 'The archive content is sealed; the volume it '
            . 'lands on, its permissions and its snapshots are the operator\'s.',
        'Recovery-time objective' => 'Nothing here promises a restore fits a recovery window. '
            . '`pulsar backup:restore` into a scratch database, timed, is what establishes that — '
            . 'which is what HIPAA §164.308(a)(7) actually asks for.',
    ];

    /**
     * @param list<BackupSourceInterface>  $sources Read, in order, by `backup:run`
     * @param list<RestoreTargetInterface> $targets Consulted, in order, by `backup:restore`
     */
    public function __construct(
        public array $sources,
        public array $targets,
    ) {}

    /**
     * What each configured source says it covers.
     *
     * @return array<string, string> source id => its own description
     */
    #[NoDiscard]
    public function coverage(): array
    {
        $coverage = [];

        foreach ($this->sources as $source) {
            $coverage[$source->id()] = $source->describe();
        }

        return $coverage;
    }

    /**
     * Whether a source with the given id is in the plan.
     *
     * The question `pulsar backup:run` asks about `audit` before it starts, so an
     * operator whose audit directory fell out of the configuration learns it from
     * a warning rather than from a recovery.
     */
    #[NoDiscard]
    public function includes(string $sourceId): bool
    {
        foreach ($this->sources as $source) {
            if ($source->id() === $sourceId) {
                return true;
            }
        }

        return false;
    }
}
