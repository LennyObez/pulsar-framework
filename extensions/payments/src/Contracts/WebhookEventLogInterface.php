<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Exception\WebhookException;
use Pulsar\Extension\Payments\Webhook\WebhookClaim;

/**
 * Claim-based webhook replay prevention contract.
 *
 * @see \Pulsar\Contracts\WebhookEventLogInterface Planned core-level contract (Plan 3).
 */
#[Api]
interface WebhookEventLogInterface
{
    /**
     * Atomically claim a webhook event ID.
     *
     * @return WebhookClaim Replay if already processed, Claimed if new
     *
     * @throws WebhookException On concurrent claim for in-flight event
     */
    public function claim(string $eventId, DateTimeImmutable $now, int $ttlSeconds): WebhookClaim;

    /**
     * Commit after successful handler dispatch.
     */
    public function commit(string $eventId): void;

    /**
     * Release on handler failure (allows retry).
     */
    public function release(string $eventId): void;

    /**
     * Remove expired records.
     *
     * @return int Number of records pruned
     */
    public function prune(DateTimeImmutable $before): int;
}
