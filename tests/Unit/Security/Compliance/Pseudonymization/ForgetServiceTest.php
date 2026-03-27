<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Compliance\Exception\ComplianceException;
use Pulsar\Security\Compliance\Pseudonymization\ForgetService;
use Pulsar\Security\Compliance\Pseudonymization\InMemoryPseudonymLookup;

use function count;
use function hash;

/**
 * Stub audit logger for ForgetService tests.
 */
final class ForgetStubAuditLogger implements AuditLoggerInterface
{
    /** @var list<array{event: AuditEvent, outcome: AuditOutcome, actor: string, action: string, resource: string, metadata: array<string, mixed>}> */
    public array $calls = [];

    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $resolved = match (true) {
            $actor instanceof AuditActor => $actor->id,
            $actor === null || $actor === '' => 'system',
            default => $actor,
        };
        $this->calls[] = [
            'event' => $event,
            'outcome' => $outcome,
            'actor' => $resolved,
            'action' => $action,
            'resource' => $resource,
            'metadata' => $metadata,
        ];

        return new AuditEntry(
            id: 'audit-' . count($this->calls),
            event: $event,
            outcome: $outcome,
            actor: $resolved,
            action: $action,
            resource: $resource,
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            metadata: $metadata,
            previousHmac: 'stub-hmac',
            hmac: 'stub-hmac-result',
        );
    }
}

#[CoversClass(ForgetService::class)]
final class ForgetServiceTest extends TestCase
{
    private InMemoryPseudonymLookup $lookup;
    private ForgetStubAuditLogger $auditLogger;
    private ForgetService $service;

    protected function setUp(): void
    {
        $this->lookup = new InMemoryPseudonymLookup();
        $this->auditLogger = new ForgetStubAuditLogger();
        $this->service = new ForgetService($this->lookup, $this->auditLogger);
    }

    #[Test]
    public function forgetDeletesMapping(): void
    {
        $this->lookup->store('user-forget', 'pseudo-forget', 'enc-salt');

        $result = $this->service->forget('user-forget');

        self::assertSame(hash('sha256', 'pseudo-forget'), $result->confirmationHash);
        self::assertNull($this->lookup->findBySubjectId('user-forget'));
        self::assertNull($this->lookup->findByPseudonym('pseudo-forget'));
    }

    #[Test]
    public function forgetThrowsWhenSubjectNotFound(): void
    {
        $this->expectException(ComplianceException::class);
        $this->expectExceptionMessage('Pseudonym not found for the given identifier.');

        $this->service->forget('nonexistent');
    }

    #[Test]
    public function forgetEmitsAuditEventWithConfirmationHash(): void
    {
        $this->lookup->store('user-audit', 'pseudo-audit', 'enc-salt');

        $this->service->forget('user-audit');

        self::assertCount(1, $this->auditLogger->calls);

        $expectedHash = hash('sha256', 'pseudo-audit');
        $call = $this->auditLogger->calls[0];
        self::assertSame(AuditEvent::DataModification, $call['event']);
        self::assertSame(AuditOutcome::Success, $call['outcome']);
        self::assertSame('system:compliance.forget', $call['actor']);
        self::assertSame('pseudonym.forget', $call['action']);
        self::assertSame($expectedHash, $call['resource']);
        self::assertSame(['confirmation_hash' => $expectedHash], $call['metadata']);
    }

    #[Test]
    public function forgetAuditDoesNotContainRawPseudonymOrSubjectId(): void
    {
        $this->lookup->store('user-leak', 'pseudo-leak', 'enc-salt');

        $this->service->forget('user-leak');

        $call = $this->auditLogger->calls[0];
        self::assertNotSame('user-leak', $call['resource']);
        self::assertArrayNotHasKey('pseudonym', $call['metadata']);
        self::assertArrayNotHasKey('subject_id', $call['metadata']);
    }

    #[Test]
    public function forgetReturnsAuditEntryId(): void
    {
        $this->lookup->store('user-entry-id', 'pseudo-entry-id', 'enc-salt');

        $result = $this->service->forget('user-entry-id');

        self::assertSame('audit-1', $result->auditEntryId);
    }

    #[Test]
    public function forgetReturnsForgottenAtTimestamp(): void
    {
        $this->lookup->store('user-ts', 'pseudo-ts', 'enc-salt');

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = $this->service->forget('user-ts');
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertGreaterThanOrEqual($before->getTimestamp(), $result->forgottenAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $result->forgottenAt->getTimestamp());
    }

    #[Test]
    public function forgetDoesNotAffectOtherMappings(): void
    {
        $this->lookup->store('user-keep', 'pseudo-keep', 'enc-salt-keep');
        $this->lookup->store('user-delete', 'pseudo-delete', 'enc-salt-delete');

        $this->service->forget('user-delete');

        self::assertNotNull($this->lookup->findBySubjectId('user-keep'));
        self::assertNull($this->lookup->findBySubjectId('user-delete'));
    }

    #[Test]
    public function forgetResultDoesNotContainSubjectIdOrPseudonym(): void
    {
        $this->lookup->store('user-no-leak', 'pseudo-no-leak', 'enc-salt');

        $result = $this->service->forget('user-no-leak');
        $array = $result->toArray();

        self::assertArrayNotHasKey('subject_id', $array);
        self::assertArrayNotHasKey('pseudonym', $array);
        self::assertArrayHasKey('confirmation_hash', $array);
    }
}
