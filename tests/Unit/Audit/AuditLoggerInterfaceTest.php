<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\AuditSinkInterface;

#[CoversClass(AuditLogger::class)]
final class AuditLoggerInterfaceTest extends TestCase
{
    private string $auditKey;

    protected function setUp(): void
    {
        $this->auditKey = random_bytes(32);
    }

    #[Test]
    public function auditLoggerImplementsInterface(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        self::assertInstanceOf(AuditLoggerInterface::class, $logger);
    }

    #[Test]
    public function enrichesMetadataWithCorrelationAndCausationIds(): void
    {
        $holder = new RequestContextHolder();
        $correlationId = CorrelationId::fromString(str_repeat('aa', 16));
        $causationId = CausationId::fromString(str_repeat('bb', 16));

        $holder->set(new RequestContext(
            correlationId: $correlationId,
            causationId: $causationId,
            actor: 'context-user',
        ));

        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey, contextHolder: $holder);

        $entry = $logger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'explicit-user',
            action: 'read',
        );

        self::assertSame($correlationId->value, $entry->metadata['correlation_id']);
        self::assertSame($causationId->value, $entry->metadata['causation_id']);
        self::assertSame('explicit-user', $entry->actor);
    }

    #[Test]
    public function autoFillsActorFromContextWhenNull(): void
    {
        $holder = new RequestContextHolder();
        $holder->set(new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
            actor: 'context-user',
        ));

        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey, contextHolder: $holder);

        $entry = $logger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: null,
            action: 'login',
        );

        self::assertSame('context-user', $entry->actor);
    }

    #[Test]
    public function fallsBackToSystemWhenNoActorAvailable(): void
    {
        $holder = new RequestContextHolder();
        $holder->set(new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        ));

        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey, contextHolder: $holder);

        $entry = $logger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: null,
            action: 'background_task',
        );

        self::assertSame('system', $entry->actor);
    }

    #[Test]
    public function worksWithoutContextHolder(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        $entry = $logger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'login',
        );

        self::assertArrayNotHasKey('correlation_id', $entry->metadata);
        self::assertArrayNotHasKey('causation_id', $entry->metadata);
    }

    #[Test]
    public function doesNotOverrideExplicitMetadata(): void
    {
        $holder = new RequestContextHolder();
        $holder->set(new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        ));

        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey, contextHolder: $holder);

        $entry = $logger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'read',
            metadata: ['correlation_id' => 'explicit-value'],
        );

        self::assertSame('explicit-value', $entry->metadata['correlation_id']);
    }
}
