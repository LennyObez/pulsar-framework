<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Audit\ChainableAuditSinkInterface;
use Pulsar\Security\Crypto\Hmac;

#[CoversClass(AuditLogger::class)]
final class AuditLoggerTest extends TestCase
{
    private string $auditKey;

    protected function setUp(): void
    {
        $this->auditKey = random_bytes(32);
    }

    #[Test]
    public function initialPreviousHmacIsSeedHmac(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        $expected = Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey);

        self::assertSame($expected, $logger->previousHmac());
    }

    #[Test]
    public function logWritesEntryToSink(): void
    {
        $sink = $this->createMock(AuditSinkInterface::class);
        $sink->expects($this->once())
            ->method('write')
            ->with($this->isInstanceOf(AuditEntry::class));

        $logger = new AuditLogger($sink, $this->auditKey);

        $entry = $logger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user@example.com',
            action: 'login',
        );

        self::assertInstanceOf(AuditEntry::class, $entry);
    }

    #[Test]
    public function logAdvancesChainState(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        $seedHmac = $logger->previousHmac();

        $entry1 = $logger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user1',
            action: 'login',
        );

        // previousHmac should now be entry1's hmac
        self::assertSame($entry1->hmac, $logger->previousHmac());
        self::assertNotSame($seedHmac, $logger->previousHmac());

        $entry2 = $logger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'user1',
            action: 'read',
            resource: '/api/data',
        );

        // entry2's previousHmac is entry1's hmac
        self::assertSame($entry1->hmac, $entry2->previousHmac);
        self::assertSame($entry2->hmac, $logger->previousHmac());
    }

    #[Test]
    public function loggedEntriesVerifyCorrectly(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        $entry = $logger->log(
            event: AuditEvent::Authorization,
            outcome: AuditOutcome::Denied,
            actor: 'attacker',
            action: 'access_admin',
            resource: '/admin',
            metadata: ['ip' => '10.0.0.1'],
        );

        self::assertTrue($entry->verify($this->auditKey));
    }

    #[Test]
    public function logPopulatesAllFields(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        $entry = $logger->log(
            event: AuditEvent::ConfigurationChange,
            outcome: AuditOutcome::Success,
            actor: 'admin',
            action: 'update_config',
            resource: 'security.csrf.enabled',
            metadata: ['old' => 'true', 'new' => 'false'],
        );

        self::assertNotEmpty($entry->id);
        self::assertSame(AuditEvent::ConfigurationChange, $entry->event);
        self::assertSame(AuditOutcome::Success, $entry->outcome);
        self::assertSame('admin', $entry->actor);
        self::assertSame('update_config', $entry->action);
        self::assertSame('security.csrf.enabled', $entry->resource);
        self::assertSame(['old' => 'true', 'new' => 'false'], $entry->metadata);
    }

    #[Test]
    public function seedsFromChainableSinkLastHmac(): void
    {
        $resumedHmac = 'abc123resumed';
        $sink = $this->createStub(ChainableAuditSinkInterface::class);
        $sink->method('lastHmac')->willReturn($resumedHmac);

        $logger = new AuditLogger($sink, $this->auditKey);

        self::assertSame($resumedHmac, $logger->previousHmac());
    }

    #[Test]
    public function fallsBackToSeedWhenChainableSinkReturnsNull(): void
    {
        $sink = $this->createStub(ChainableAuditSinkInterface::class);
        $sink->method('lastHmac')->willReturn(null);

        $logger = new AuditLogger($sink, $this->auditKey);

        $expected = Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey);
        self::assertSame($expected, $logger->previousHmac());
    }

    #[Test]
    public function fallsBackToSeedWhenSinkIsNotChainable(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        $expected = Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey);
        self::assertSame($expected, $logger->previousHmac());
    }

    #[Test]
    public function multipleEntriesFormValidChain(): void
    {
        $entries = [];
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        $seedHmac = $logger->previousHmac();

        for ($i = 0; $i < 5; $i++) {
            $entries[] = $logger->log(
                event: AuditEvent::DataAccess,
                outcome: AuditOutcome::Success,
                actor: 'user',
                action: 'read_' . $i,
            );
        }

        // Verify chain integrity
        self::assertSame($seedHmac, $entries[0]->previousHmac);

        for ($i = 1; $i < 5; $i++) {
            self::assertSame($entries[$i - 1]->hmac, $entries[$i]->previousHmac);
        }

        // All entries verify
        foreach ($entries as $entry) {
            self::assertTrue($entry->verify($this->auditKey));
        }
    }

    #[Test]
    public function logAutoEnrichesFromRequestContext(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $contextHolder = new RequestContextHolder();
        $corrId = bin2hex(random_bytes(16));
        $causeId = bin2hex(random_bytes(16));
        $context = new RequestContext(
            correlationId: CorrelationId::fromString($corrId),
            causationId: CausationId::fromString($causeId),
            actor: 'context-user',
        );
        $contextHolder->set($context);

        $logger = new AuditLogger($sink, $this->auditKey, contextHolder: $contextHolder);

        // Log with null actor — should auto-fill from context
        $entry = $logger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: null,
            action: 'read',
        );

        self::assertSame('context-user', $entry->actor);
        self::assertSame($corrId, $entry->metadata['correlation_id']);
        self::assertSame($causeId, $entry->metadata['causation_id']);
    }

    #[Test]
    public function logPreservesExplicitActorOverContextActor(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $contextHolder = new RequestContextHolder();
        $context = new RequestContext(
            correlationId: CorrelationId::fromString(bin2hex(random_bytes(16))),
            causationId: CausationId::fromString(bin2hex(random_bytes(16))),
            actor: 'context-user',
        );
        $contextHolder->set($context);

        $logger = new AuditLogger($sink, $this->auditKey, contextHolder: $contextHolder);

        // Log with explicit actor — should NOT be overridden by context
        $entry = $logger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'explicit-user',
            action: 'login',
        );

        self::assertSame('explicit-user', $entry->actor);
    }

    #[Test]
    public function logUsesSystemActorWhenNoContextAndNoActor(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $logger = new AuditLogger($sink, $this->auditKey);

        $entry = $logger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: null,
            action: 'startup',
        );

        self::assertSame('system', $entry->actor);
    }

    #[Test]
    public function logDoesNotOverrideExistingCorrelationIdInMetadata(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $contextHolder = new RequestContextHolder();
        $context = new RequestContext(
            correlationId: CorrelationId::fromString(bin2hex(random_bytes(16))),
            causationId: CausationId::fromString(bin2hex(random_bytes(16))),
        );
        $contextHolder->set($context);

        $logger = new AuditLogger($sink, $this->auditKey, contextHolder: $contextHolder);

        // Pass metadata with existing correlation_id
        $entry = $logger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'user',
            action: 'read',
            metadata: ['correlation_id' => 'explicit-corr'],
        );

        // Explicit metadata should be preserved
        self::assertSame('explicit-corr', $entry->metadata['correlation_id']);
    }

    #[Test]
    public function logWithEmptyContextHolderDoesNotEnrich(): void
    {
        $sink = $this->createStub(AuditSinkInterface::class);
        $contextHolder = new RequestContextHolder();
        // No context set

        $logger = new AuditLogger($sink, $this->auditKey, contextHolder: $contextHolder);

        $entry = $logger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: null,
            action: 'read',
        );

        self::assertSame('system', $entry->actor);
        self::assertArrayNotHasKey('correlation_id', $entry->metadata);
    }
}
