<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit\Support;

use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\AuditSinkInterface;

/**
 * An audit sink that logs an audit event of its own while writing one.
 *
 * Not a contrived shape: a sink that reports its own failures, a PSR-3 handler
 * behind one, or an event listener on the write are all this, and every one of
 * them re-enters {@see AuditLogger::log()} on the same call stack, holding the
 * chain advance open while it does. {@see $reentered} stops the nesting after one
 * level, so a test using this sink demonstrates the shape instead of recursing
 * away from it.
 */
final class ReentrantAuditSink implements AuditSinkInterface
{
    /**
     * Every entry handed to {@see write()}, in order.
     *
     * Handed to, not necessarily persisted: an entry whose `write()` throws is
     * one this sink still saw, and that is the question the guard tests ask.
     *
     * @var list<AuditEntry>
     */
    public array $received = [];

    /**
     * The logger to re-enter, or null to behave as an ordinary sink.
     *
     * Set after construction because the logger needs the sink first.
     */
    public ?AuditLogger $logger = null;

    private bool $reentered = false;

    public function write(AuditEntry $entry): void
    {
        $this->received[] = $entry;

        if ($this->logger === null || $this->reentered) {
            return;
        }

        $this->reentered = true;

        $this->logger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'audit-sink',
            action: 'audit.sink.write',
        );
    }
}
