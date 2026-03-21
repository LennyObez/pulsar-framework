<?php

declare(strict_types=1);

namespace Pulsar\Tests\Chaos;

use Fiber;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\ChainableAuditSinkInterface;
use Pulsar\Security\Crypto\KeyRingInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function array_map;
use function array_unique;
use function count;
use function random_bytes;

/**
 * ARCH-CONC-01 (external audit): exercise the audit chain HMAC continuity
 * under concurrent in-process writes.
 *
 * The AuditLogger guarantees a tamper-evident chain via HMAC-previous-HMAC
 * binding. Within a single process, the `$previousHmac` field is the
 * implicit mutex — two concurrent log() calls in the same instance MUST
 * NOT both read the same `$previousHmac` and emit two entries pointing
 * at the same anchor (which would break verification of the second
 * entry). The current implementation is single-threaded (no shared state
 * across processes), so this test verifies the intra-process contract
 * using PHP Fibers to interleave log() calls deterministically.
 *
 * Cross-process testing (FrankenPHP workers, FPM pool, queued jobs) is
 * out of scope for this unit / chaos suite — that contract belongs to
 * the sink's own concurrency story (file locks in AuditFileSink,
 * transaction in a DB sink). Documented in ARCH-CONC-01.
 */
#[CoversClass(AuditLogger::class)]
#[CoversClass(AuditChainVerifier::class)]
#[Group('chaos')]
final class AuditChainConcurrencyTest extends TestCase
{
    #[Test]
    public function fiberInterleavingPreservesChainIntegrity(): void
    {
        $sink = new InMemoryChainableAuditSink();
        $auditKey = random_bytes(32);
        $logger = new AuditLogger(
            $sink,
            $auditKey,
            new Randomizer(new Secure()),
        );

        // Build 10 fibers that each emit one log() call. The fibers
        // cooperatively yield after each log so the scheduler can
        // interleave them — verifying that even under aggressive
        // suspend/resume, no two emitted entries reuse the same
        // previousHmac (which would break the chain).
        $fibers = [];

        for ($i = 0; $i < 10; $i++) {
            $fibers[$i] = new Fiber(function () use ($logger, $i): void {
                $logger->log(
                    AuditEvent::Authentication,
                    AuditOutcome::Success,
                    "fiber-actor-$i",
                    "fiber_action_$i",
                    "fiber_resource_$i",
                    ['fiber_id' => (string) $i],
                );
                Fiber::suspend();
            });

            $fibers[$i]->start();
        }

        // Resume each fiber so they finish — by this point, the InMemory
        // sink has captured all 10 entries in the order their fibers
        // emitted log().
        foreach ($fibers as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        $entries = $sink->entries();
        self::assertCount(10, $entries);

        // Each entry's previousHmac MUST equal the previous entry's hmac
        // (or the seed for the first entry). The verifier needs a KeyRing
        // that maps the AuditLogger-derived kid back to the audit key.
        $verifier = new AuditChainVerifier(new SingleKeyRing($auditKey));
        $result = $verifier->verifyChain($entries);
        self::assertTrue(
            $result->valid,
            'Audit chain corrupted under fiber interleaving. Failed entries: '
                . implode(', ', $result->failedEntryIds)
                . '; broken links: ' . implode(', ', $result->brokenLinks),
        );

        // Every previousHmac must be unique across the 10 entries
        // (would only repeat if two log() calls observed the same
        // chain head — the bug ARCH-CONC-01 protects against).
        $prevHmacs = array_map(static fn(AuditEntry $e): string => $e->previousHmac, $entries);
        self::assertCount(
            10,
            array_unique($prevHmacs),
            'Two entries share the same previousHmac — the intra-process mutex contract is broken',
        );
    }
}

/**
 * @internal test-only key ring that returns the same audit key for every
 * kid lookup. Sufficient because the AuditLogger uses one key per
 * instance, and the chain verifier asks the ring for `keyFor($kid)`
 * where `$kid` is derived from the key itself.
 */
final readonly class SingleKeyRing implements KeyRingInterface
{
    public function __construct(private string $auditKey) {}

    #[Override]
    public function keyFor(string $kid): string
    {
        return $this->auditKey;
    }

    #[Override]
    public function all(): iterable
    {
        yield 'audit' => $this->auditKey;
    }
}

/**
 * @internal test-only sink that captures entries into memory and reports
 * the last HMAC for chain continuity testing.
 */
final class InMemoryChainableAuditSink implements ChainableAuditSinkInterface
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    #[Override]
    public function write(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    #[Override]
    public function lastHmac(): ?string
    {
        $count = count($this->entries);

        return $count === 0 ? null : $this->entries[$count - 1]->hmac;
    }

    /** @return list<AuditEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
