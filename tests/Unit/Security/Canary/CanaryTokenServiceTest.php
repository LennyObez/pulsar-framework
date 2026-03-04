<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Canary;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Canary\CanaryAlertEvent;
use Pulsar\Security\Canary\CanaryToken;
use Pulsar\Security\Canary\CanaryTokenService;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatEventDispatcherInterface;

#[CoversClass(CanaryTokenService::class)]
#[CoversClass(CanaryToken::class)]
#[CoversClass(CanaryAlertEvent::class)]
final class CanaryTokenServiceTest extends TestCase
{
    #[Test]
    public function generates_unique_tokens(): void
    {
        $service = new CanaryTokenService();

        $token1 = $service->generate('Export A', 'customer_data');
        $token2 = $service->generate('Export B', 'customer_data');

        self::assertNotSame($token1->id, $token2->id);
        self::assertNotSame($token1->marker, $token2->marker);
        self::assertStringStartsWith('CNRY-', $token1->marker);
        self::assertStringStartsWith('CNRY-', $token2->marker);
    }

    #[Test]
    public function token_has_correct_metadata(): void
    {
        $service = new CanaryTokenService();

        $token = $service->generate('Export', 'financial_records', 'admin@example.com');

        self::assertSame('Export', $token->label);
        self::assertSame('financial_records', $token->context);
        self::assertSame('admin@example.com', $token->createdBy);
        self::assertNotEmpty($token->id);
    }

    #[Test]
    public function scan_detects_embedded_token(): void
    {
        $service = new CanaryTokenService();
        $token = $service->generate('Sensitive export', 'db_dump');

        $leakedContent = 'Some data before ' . $token->marker . ' and after';
        $alerts = $service->scan($leakedContent, 'pastebin.com/abc123');

        self::assertCount(1, $alerts);
        self::assertSame($token->id, $alerts[0]->token->id);
        self::assertSame('pastebin.com/abc123', $alerts[0]->detectedLocation);
    }

    #[Test]
    public function scan_returns_empty_when_no_match(): void
    {
        $service = new CanaryTokenService();
        $token = $service->generate('Export', 'context');

        // Use content that definitely does not contain the marker
        self::assertStringNotContainsString($token->marker, 'Clean content');
        $alerts = $service->scan('Clean content with no markers', 'somewhere.com');

        self::assertSame([], $alerts);
    }

    #[Test]
    public function scan_detects_multiple_tokens(): void
    {
        $service = new CanaryTokenService();
        $token1 = $service->generate('Export 1', 'ctx1');
        $token2 = $service->generate('Export 2', 'ctx2');

        $content = $token1->marker . ' mixed with ' . $token2->marker;
        $alerts = $service->scan($content, 'leaked');

        self::assertCount(2, $alerts);
    }

    #[Test]
    public function dispatches_threat_event_on_detection(): void
    {
        $dispatcher = $this->createMock(ThreatEventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn(ThreatEvent $e): bool => $e->category === ThreatCategory::DataLeak));

        $service = new CanaryTokenService(eventDispatcher: $dispatcher);
        $token = $service->generate('Export', 'ctx');

        $service->scan($token->marker, 'dark_web');
    }

    #[Test]
    public function logs_creation_to_audit_trail(): void
    {
        $entry = $this->createStub(AuditEntry::class);
        $logger = $this->createMock(AuditLoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Success,
                'admin',
                'canary.created',
            )
            ->willReturn($entry);

        $service = new CanaryTokenService(auditLogger: $logger);
        $token = $service->generate('Export', 'ctx', 'admin');
        self::assertSame('Export', $token->label);
    }

    #[Test]
    public function logs_detection_to_audit_trail(): void
    {
        $entry = $this->createStub(AuditEntry::class);
        $logger = $this->createMock(AuditLoggerInterface::class);
        $logger->expects(self::exactly(2))
            ->method('log')
            ->willReturn($entry);

        $service = new CanaryTokenService(auditLogger: $logger);
        $token = $service->generate('Export', 'ctx');

        $service->scan($token->marker, 'leaked');
    }

    #[Test]
    public function register_and_get_token(): void
    {
        $service = new CanaryTokenService();
        $token = new CanaryToken(
            id: 'test-id',
            label: 'test',
            marker: 'CNRY-test',
            context: 'ctx',
            createdAt: new DateTimeImmutable(),
        );

        $service->register($token);

        self::assertSame($token, $service->get('test-id'));
        self::assertNull($service->get('nonexistent'));
    }

    #[Test]
    public function all_returns_registered_tokens(): void
    {
        $service = new CanaryTokenService();
        $tokenA = $service->generate('A', 'ctx');
        $tokenB = $service->generate('B', 'ctx');

        $all = $service->all();
        self::assertCount(2, $all);
        self::assertSame($tokenA, $all[$tokenA->id]);
        self::assertSame($tokenB, $all[$tokenB->id]);
    }
}
