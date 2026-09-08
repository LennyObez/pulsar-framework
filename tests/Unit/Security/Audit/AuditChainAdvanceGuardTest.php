<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Tests\Unit\Security\Audit\Support\ReentrantAuditSink;

use function array_map;
use function array_unique;
use function array_values;
use function random_bytes;

/**
 * The audit chain advance admits one caller at a time, and says so.
 *
 * `AuditLogger::log()` reads the previous entry's HMAC, builds an entry chained
 * to it, hands it to the sink, and only then publishes the new head. Two callers
 * inside that window read the same predecessor and write two entries claiming the
 * same position; the chain forks and every later verification reports the file as
 * tampered with.
 *
 * What guarded that window was a spin on a bare `Fiber::suspend()`, entered only
 * when `Fiber::getCurrent() !== null`. Both halves were wrong:
 *
 *  - Outside a fiber the wait was skipped, so the entrant walked straight into the
 *    window. That is the case that actually happens — an audit sink, or a handler
 *    or listener it reaches, logging an audit event of its own — and it was the
 *    one case the guard did not cover at all.
 *  - Inside a fiber, a suspend with no value is the protocol `FiberScheduler`
 *    reads as "resume me when my connection socket becomes readable". The fiber
 *    was parked on an unrelated event, on a compliance framework's
 *    tamper-evidence mechanism.
 *
 * Both are now refused with a {@see SecurityException}, from any context, before
 * anything is written.
 *
 * @see \Pulsar\Security\Audit\AuditLogger
 * @see \Pulsar\Tests\Unit\Concurrency\FiberSurfaceInventoryTest
 */
#[CoversClass(AuditLogger::class)]
final class AuditChainAdvanceGuardTest extends TestCase
{
    private string $auditKey;

    protected function setUp(): void
    {
        $this->auditKey = random_bytes(32);
    }

    #[Test]
    public function aSinkThatLogsIsRefusedRatherThanAdmitted(): void
    {
        $sink = new ReentrantAuditSink();
        $logger = new AuditLogger($sink, $this->auditKey);
        $sink->logger = $logger;

        try {
            $logger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Success,
                actor: 'alice',
                action: 'user.login',
            );

            self::fail('the nested log was admitted to the chain advance');
        } catch (SecurityException $e) {
            self::assertStringContainsString('Audit chain advance re-entered', $e->getMessage());
            // The refused action, not the one already in flight: that is the
            // call an operator has to go and find.
            self::assertStringContainsString('audit.sink.write', $e->getMessage());
        }
    }

    /**
     * The defect itself, rather than the message: without exclusion, the sink
     * receives two entries naming the same predecessor.
     */
    #[Test]
    public function twoEntriesCannotClaimTheSamePredecessor(): void
    {
        $sink = new ReentrantAuditSink();
        $logger = new AuditLogger($sink, $this->auditKey);
        $sink->logger = $logger;

        try {
            $logger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Success,
                actor: 'alice',
                action: 'user.login',
            );
        } catch (SecurityException) {
            // The refusal is asserted by the test above; here only its effect matters.
        }

        $predecessors = array_map(
            static fn(AuditEntry $entry): string => $entry->previousHmac,
            $sink->received,
        );

        self::assertSame(
            $predecessors,
            array_values(array_unique($predecessors)),
            'two audit entries claim the same predecessor: the chain forks there and every '
            . 'later verification reports the log as tampered with',
        );
    }

    /**
     * The same refusal inside a fiber.
     *
     * The assertion that matters is `isTerminated()`. Against the previous
     * mechanism the fiber suspends inside the chain advance and `start()` returns
     * with it still suspended — waiting, under `FiberScheduler`, for its
     * connection socket to become readable, which is not a thing that has any
     * relation to the chain being free. Nothing in this test, and nothing in the
     * runtime, will resume it.
     */
    #[Test]
    public function aReentrantCallInsideAFiberIsRefusedRatherThanParked(): void
    {
        $sink = new ReentrantAuditSink();
        $logger = new AuditLogger($sink, $this->auditKey);
        $sink->logger = $logger;

        $refusal = null;

        $fiber = new Fiber(static function () use ($logger, &$refusal): void {
            try {
                $logger->log(
                    event: AuditEvent::Authentication,
                    outcome: AuditOutcome::Success,
                    actor: 'alice',
                    action: 'user.login',
                );
            } catch (SecurityException $e) {
                $refusal = $e;
            }
        });

        $fiber->start();

        self::assertTrue(
            $fiber->isTerminated(),
            'the fiber suspended inside the chain advance; nothing resumes a bare suspend here',
        );
        self::assertInstanceOf(SecurityException::class, $refusal);
    }

    /**
     * The flag is released on the way out, so a refusal does not wedge the logger.
     */
    #[Test]
    public function theChainKeepsWorkingAfterARefusal(): void
    {
        $sink = new ReentrantAuditSink();
        $logger = new AuditLogger($sink, $this->auditKey);
        $sink->logger = $logger;

        try {
            $logger->log(
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Success,
                actor: 'alice',
                action: 'user.login',
            );
        } catch (SecurityException) {
            // Expected; the point is what happens next.
        }

        $sink->logger = null;
        $head = $logger->previousHmac();

        $entry = $logger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'chain.continue',
        );

        self::assertSame($head, $entry->previousHmac);
        self::assertSame($entry->hmac, $logger->previousHmac());
    }
}
