<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(NullAuditLogger::class)]
final class NullAuditLoggerTest extends TestCase
{
    private NullAuditLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullAuditLogger();
    }

    #[Test]
    public function implementsAuditLoggerInterface(): void
    {
        self::assertInstanceOf(AuditLoggerInterface::class, $this->logger);
    }

    #[Test]
    public function logReturnsAuditEntry(): void
    {
        $entry = $this->logger->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            'admin',
            'test.action',
        );

        self::assertInstanceOf(AuditEntry::class, $entry);
    }

    #[Test]
    public function logPreservesEventAndOutcome(): void
    {
        $entry = $this->logger->log(
            AuditEvent::DataAccess,
            AuditOutcome::Denied,
            'user-42',
            'read',
            'users/123',
        );

        self::assertSame(AuditEvent::DataAccess, $entry->event);
        self::assertSame(AuditOutcome::Denied, $entry->outcome);
    }

    #[Test]
    public function logPreservesActorAndAction(): void
    {
        $entry = $this->logger->log(
            AuditEvent::Authentication,
            AuditOutcome::Success,
            'jane@example.com',
            'login',
        );

        self::assertSame('jane@example.com', $entry->actor);
        self::assertSame('login', $entry->action);
    }

    #[Test]
    public function logPreservesResource(): void
    {
        $entry = $this->logger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            'admin',
            'update',
            'posts/456',
        );

        self::assertSame('posts/456', $entry->resource);
    }

    #[Test]
    public function logPreservesMetadata(): void
    {
        $metadata = ['ip' => '127.0.0.1', 'user_agent' => 'TestClient/1.0'];

        $entry = $this->logger->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Failure,
            'attacker',
            'brute_force',
            'auth',
            $metadata,
        );

        self::assertSame($metadata, $entry->metadata);
    }

    #[Test]
    public function logUsesSystemActorWhenNull(): void
    {
        $entry = $this->logger->log(
            AuditEvent::SystemEvent,
            AuditOutcome::Success,
            null,
            'scheduled.task',
        );

        self::assertSame('system', $entry->actor);
    }

    #[Test]
    public function logReturnsNullId(): void
    {
        $entry = $this->logger->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            'admin',
            'test',
        );

        self::assertSame('null', $entry->id);
    }

    #[Test]
    public function logReturnsEmptyHmacChain(): void
    {
        $entry = $this->logger->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            'admin',
            'test',
        );

        self::assertSame('', $entry->previousHmac);
        self::assertSame('', $entry->hmac);
    }

    #[Test]
    public function logSetsTimestamp(): void
    {
        $before = new DateTimeImmutable();
        $entry = $this->logger->log(
            AuditEvent::SecurityEvent,
            AuditOutcome::Success,
            'admin',
            'test',
        );
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $entry->timestamp);
        self::assertLessThanOrEqual($after, $entry->timestamp);
    }

    /** @return iterable<string, array{AuditEvent, AuditOutcome}> */
    public static function eventOutcomeProvider(): iterable
    {
        foreach (AuditEvent::cases() as $event) {
            foreach (AuditOutcome::cases() as $outcome) {
                yield "{$event->value}/{$outcome->value}" => [$event, $outcome];
            }
        }
    }

    #[Test]
    #[DataProvider('eventOutcomeProvider')]
    public function logHandlesAllEventOutcomeCombinations(AuditEvent $event, AuditOutcome $outcome): void
    {
        $entry = $this->logger->log($event, $outcome, 'test-actor', 'test-action');

        self::assertSame($event, $entry->event);
        self::assertSame($outcome, $entry->outcome);
    }

    #[Test]
    public function multipleCallsReturnIndependentEntries(): void
    {
        $entry1 = $this->logger->log(
            AuditEvent::Authentication,
            AuditOutcome::Success,
            'user-1',
            'login',
        );
        $entry2 = $this->logger->log(
            AuditEvent::DataAccess,
            AuditOutcome::Denied,
            'user-2',
            'read',
        );

        self::assertSame('user-1', $entry1->actor);
        self::assertSame('user-2', $entry2->actor);
        self::assertSame(AuditEvent::Authentication, $entry1->event);
        self::assertSame(AuditEvent::DataAccess, $entry2->event);
    }
}
