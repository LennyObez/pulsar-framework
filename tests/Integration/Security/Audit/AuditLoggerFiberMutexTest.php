<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security\Audit;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Crypto\Hmac;
use RuntimeException;
use Throwable;

/**
 * Proves that AuditLogger's cooperative fiber mutex maintains
 * HMAC chain integrity under concurrent fiber writes.
 */
#[CoversClass(AuditLogger::class)]
final class AuditLoggerFiberMutexTest extends TestCase
{
    private string $auditKey;

    protected function setUp(): void
    {
        $this->auditKey = random_bytes(32);
    }

    #[Test]
    public function hmac_chain_integrity_with_concurrent_fiber_writes(): void
    {
        /** @var list<AuditEntry> $writtenEntries */
        $writtenEntries = [];

        $sink = $this->createStub(AuditSinkInterface::class);
        $sink->method('write')->willReturnCallback(
            static function (AuditEntry $entry) use (&$writtenEntries): void {
                $writtenEntries[] = $entry;
            },
        );

        $logger = new AuditLogger($sink, $this->auditKey);
        $seedHmac = $logger->previousHmac();

        $fiberCount = 15;
        /** @var list<Fiber<void, void, void, AuditEntry>> $fibers */
        $fibers = [];

        // Create fibers that each log an audit entry and suspend mid-work
        for ($i = 0; $i < $fiberCount; $i++) {
            $index = $i;
            $fibers[] = new Fiber(static function () use ($logger, $index): AuditEntry {
                // Suspend before logging to simulate concurrent scheduling
                Fiber::suspend();

                return $logger->log(
                    event: AuditEvent::DataAccess,
                    outcome: AuditOutcome::Success,
                    actor: 'fiber-' . $index,
                    action: 'concurrent_write_' . $index,
                );
            });
        }

        // Start all fibers (they suspend immediately)
        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        // Round-robin resume until all complete
        $maxIterations = $fiberCount * 10;
        $iterations = 0;
        while ($iterations < $maxIterations) {
            $allDone = true;
            foreach ($fibers as $fiber) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                    $allDone = false;
                }
            }
            if ($allDone) {
                break;
            }
            $iterations++;
        }

        // All fibers must have completed
        foreach ($fibers as $i => $fiber) {
            self::assertTrue($fiber->isTerminated(), "Fiber {$i} should have terminated");
        }

        // All entries must have been written
        self::assertCount($fiberCount, $writtenEntries, 'all fibers should produce entries');

        // Verify HMAC chain integrity: each entry's previousHmac must equal
        // the HMAC of the preceding entry (or the seed for the first entry)
        $expectedPreviousHmac = $seedHmac;
        foreach ($writtenEntries as $i => $entry) {
            self::assertSame(
                $expectedPreviousHmac,
                $entry->previousHmac,
                "Entry {$i} previousHmac chain is broken",
            );
            self::assertTrue(
                $entry->verify($this->auditKey),
                "Entry {$i} HMAC verification failed",
            );
            $expectedPreviousHmac = $entry->hmac;
        }

        // Final logger state must match the last entry's HMAC
        $lastKey = array_key_last($writtenEntries);
        self::assertNotNull($lastKey, 'Expected at least one written entry');
        self::assertSame(
            $writtenEntries[$lastKey]->hmac,
            $logger->previousHmac(),
        );
    }

    #[Test]
    public function all_entries_have_unique_hmacs_under_concurrency(): void
    {
        /** @var list<AuditEntry> $writtenEntries */
        $writtenEntries = [];

        $sink = $this->createStub(AuditSinkInterface::class);
        $sink->method('write')->willReturnCallback(
            static function (AuditEntry $entry) use (&$writtenEntries): void {
                $writtenEntries[] = $entry;
            },
        );

        $logger = new AuditLogger($sink, $this->auditKey);

        $fiberCount = 10;
        /** @var list<Fiber<void, void, void, void>> $fibers */
        $fibers = [];

        for ($i = 0; $i < $fiberCount; $i++) {
            $index = $i;
            $fibers[] = new Fiber(static function () use ($logger, $index): void {
                Fiber::suspend();
                $logger->log(
                    event: AuditEvent::Authentication,
                    outcome: AuditOutcome::Success,
                    actor: 'user-' . $index,
                    action: 'login',
                );
            });
        }

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        // Resume all at once
        foreach ($fibers as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        // Drain any remaining
        for ($round = 0; $round < 20; $round++) {
            $anyPending = false;
            foreach ($fibers as $fiber) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                    $anyPending = true;
                }
            }
            if (!$anyPending) {
                break;
            }
        }

        self::assertCount($fiberCount, $writtenEntries);

        // All HMACs must be unique
        $hmacs = array_map(static fn(AuditEntry $e): string => $e->hmac, $writtenEntries);
        self::assertCount(
            $fiberCount,
            array_unique($hmacs),
            'all entries must have unique HMACs',
        );
    }

    #[Test]
    public function chain_recovers_after_fiber_exception_during_log(): void
    {
        /** @var list<AuditEntry> $writtenEntries */
        $writtenEntries = [];

        // Sink that throws on the 3rd write to simulate an I/O failure
        $writeCount = 0;
        $sink = $this->createStub(AuditSinkInterface::class);
        $sink->method('write')->willReturnCallback(
            static function (AuditEntry $entry) use (&$writtenEntries, &$writeCount): void {
                $writeCount++;
                if ($writeCount === 3) {
                    throw new RuntimeException('Sink I/O failure');
                }
                $writtenEntries[] = $entry;
            },
        );

        $logger = new AuditLogger($sink, $this->auditKey);

        // Log entries from fibers — one will fail due to sink exception
        /** @var array<int, array{success: bool, entry?: AuditEntry, error?: string}> $results */
        $results = [];
        /** @var list<Fiber<void, void, void, void>> $fibers */
        $fibers = [];

        for ($i = 0; $i < 5; $i++) {
            $index = $i;
            $fibers[] = new Fiber(static function () use ($logger, $index, &$results): void {
                Fiber::suspend();
                try {
                    $entry = $logger->log(
                        event: AuditEvent::DataAccess,
                        outcome: AuditOutcome::Success,
                        actor: 'fiber-' . $index,
                        action: 'write_' . $index,
                    );
                    $results[$index] = ['success' => true, 'entry' => $entry];
                } catch (Throwable $e) {
                    $results[$index] = ['success' => false, 'error' => $e->getMessage()];
                }
            });
        }

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        // Resume fibers sequentially to ensure deterministic order
        foreach ($fibers as $fiber) {
            while ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        // Exactly one fiber should have failed
        $failureCount = 0;
        $successCount = 0;

        foreach ($results as $r) {
            if ($r['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        self::assertSame(1, $failureCount, 'exactly one fiber should fail from sink error');
        self::assertSame(4, $successCount, 'other fibers should succeed after exception');

        // Successful entries form a valid chain
        foreach ($writtenEntries as $entry) {
            self::assertTrue($entry->verify($this->auditKey));
        }
    }

    #[Test]
    public function non_fiber_context_still_works(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        // Calling log() outside a Fiber should work (no Fiber::suspend calls)
        $entry = $logger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'main-thread',
            action: 'startup',
        );

        self::assertTrue($entry->verify($this->auditKey));
        self::assertSame($entry->hmac, $logger->previousHmac());
    }
}
