<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Adapter;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\WebAuthn\Contract\ChallengeStoreInterface;

use function array_key_exists;

/**
 * Process-local record of spent challenges.
 *
 * Complete and correct for a single worker process, and that is the whole of its
 * claim. It is the same posture as {@see InMemoryCredentialRepository} and
 * {@see InMemoryAuthenticatorRepository}: the extension ships a working default
 * so nothing is unbound, and a deployment that outgrows one process replaces the
 * binding.
 *
 * A deployment running several PHP workers MUST bind a shared implementation to
 * {@see ChallengeStoreInterface} instead. Each worker holds its own copy of this
 * array, so a replay routed to a different worker than the original ceremony
 * finds an empty store and is admitted. That is not a theoretical arrangement;
 * it is the default shape of PHP-FPM, RoadRunner and FrankenPHP alike. Redis
 * `SET key value NX PX ttl`, a unique index on a `challenge` column, or
 * memcached `add` each give the atomic claim {@see ChallengeStoreInterface}
 * requires.
 *
 * Atomicity holds here because PHP executes a request's opcodes on one thread
 * with no preemption: nothing can interleave between the `array_key_exists()`
 * test and the write below.
 */
#[Internal(reason: 'Default in-memory adapter for development and single-process deployments')]
final class InMemoryChallengeStore implements ChallengeStoreInterface
{
    /**
     * Spent challenges mapped to the Unix second past which they may be dropped.
     *
     * @var array<string, int>
     */
    private array $spent = [];

    /**
     * @param ClockInterface|null $clock Used only to prune expired records; the
     *        system clock is used when none is given. Sharing the ceremony's
     *        clock keeps a frozen test clock from pruning records the ceremony
     *        still considers live.
     */
    public function __construct(private readonly ?ClockInterface $clock = null) {}

    public function consume(string $challenge, DateTimeImmutable $expiresAt): bool
    {
        $this->prune();

        if (array_key_exists($challenge, $this->spent)) {
            return false;
        }

        $this->spent[$challenge] = $expiresAt->getTimestamp();

        return true;
    }

    /**
     * Drop records for challenges that freshness already refuses.
     *
     * Bounded growth is the point: without this, a process that runs ceremonies
     * for weeks accumulates one entry per challenge it ever issued. Dropping an
     * expired record cannot reopen a replay window, because a challenge past its
     * TTL never reaches {@see consume()} — the ceremony refuses it on freshness
     * first.
     */
    private function prune(): void
    {
        $now = ($this->clock?->now() ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();

        foreach ($this->spent as $challenge => $expiresAt) {
            if ($expiresAt < $now) {
                unset($this->spent[$challenge]);
            }
        }
    }
}
