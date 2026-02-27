<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\EventStore;

use Pulsar\Api\Api;

/**
 * Service interface for atomic content snapshots.
 *
 * Captures and restores complete content state across all locales
 * for governance-grade auditability.
 *
 * @psalm-api Public binding contract; implemented by DbContentSnapshotRepository
 *            and consumed by governance + admin controllers.
 */
#[Api(since: '1.0.0')]
interface ContentSnapshotServiceInterface
{
    /**
     * Capture an atomic snapshot of all translations, blocks, and taxonomy terms.
     */
    public function capture(string $contentId, string $reason, string $createdBy): ContentSnapshot;

    /**
     * Restore a content item to the state captured in a snapshot.
     * Creates individual ContentRevision records for each locale.
     */
    public function restore(string $snapshotId, string $restoredBy): void;

    /**
     * Retrieve all snapshots for a content item.
     *
     * @return list<ContentSnapshot>
     */
    public function getSnapshots(string $contentId): array;
}
