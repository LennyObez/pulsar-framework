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
use Pulsar\Security\Compliance\Pseudonymization\InMemoryPseudonymLookup;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationService;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function count;
use function hex2bin;
use function random_bytes;
use function strlen;
use function substr;

/**
 * Stub audit logger that captures log calls for testing.
 */
final class PseudonymizationStubAuditLogger implements AuditLoggerInterface
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
            id: 'stub-' . count($this->calls),
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

/**
 * Stub encryptor that wraps/unwraps with a simple prefix.
 */
final class StubEncryptor implements EncryptorInterface
{
    private const string PREFIX = 'ENC:';

    #[Override]
    public function encrypt(string $plaintext): string
    {
        return self::PREFIX . bin2hex($plaintext);
    }

    #[Override]
    public function decrypt(string $encoded): string
    {
        $decoded = hex2bin(substr($encoded, strlen(self::PREFIX)));

        return $decoded !== false ? $decoded : '';
    }

    #[Override]
    public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): EncryptorInterface
    {
        return $this;
    }
}

#[CoversClass(PseudonymizationService::class)]
final class PseudonymizationServiceTest extends TestCase
{
    private MasterKey $masterKey;
    private InMemoryPseudonymLookup $lookup;
    private StubEncryptor $encryptor;
    private PseudonymizationStubAuditLogger $auditLogger;
    private PseudonymizationService $service;

    protected function setUp(): void
    {
        $this->masterKey = MasterKey::fromHex(bin2hex(random_bytes(32)));
        $this->lookup = new InMemoryPseudonymLookup();
        $this->encryptor = new StubEncryptor();
        $this->auditLogger = new PseudonymizationStubAuditLogger();

        $this->service = new PseudonymizationService(
            $this->masterKey,
            $this->lookup,
            $this->encryptor,
            $this->auditLogger,
        );
    }

    #[Test]
    public function pseudonymizeReturnsConsistentResultForSameSubject(): void
    {
        $first = $this->service->pseudonymize('user-1');
        $second = $this->service->pseudonymize('user-1');

        self::assertSame($first, $second);
    }

    #[Test]
    public function pseudonymizeReturnsDifferentResultsForDifferentSubjects(): void
    {
        $pseudo1 = $this->service->pseudonymize('user-a');
        $pseudo2 = $this->service->pseudonymize('user-b');

        self::assertNotSame($pseudo1, $pseudo2);
    }

    #[Test]
    public function pseudonymizeReturns32HexCharacters(): void
    {
        $pseudonym = $this->service->pseudonymize('user-length');

        self::assertSame(32, strlen($pseudonym));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pseudonym);
    }

    #[Test]
    public function pseudonymizeStoresMappingInLookup(): void
    {
        $pseudonym = $this->service->pseudonymize('user-stored');

        $mapping = $this->lookup->findBySubjectId('user-stored');

        self::assertNotNull($mapping);
        self::assertSame($pseudonym, $mapping->pseudonym);
        self::assertSame('user-stored', $mapping->subjectId);
    }

    #[Test]
    public function pseudonymizeEncryptsSalt(): void
    {
        $this->service->pseudonymize('user-salt');

        $mapping = $this->lookup->findBySubjectId('user-salt');

        self::assertNotNull($mapping);
        self::assertStringStartsWith('ENC:', $mapping->encryptedSalt);
    }

    #[Test]
    public function resolveReturnsSubjectId(): void
    {
        $pseudonym = $this->service->pseudonymize('user-resolve');

        $resolved = $this->service->resolve($pseudonym);

        self::assertSame('user-resolve', $resolved);
    }

    #[Test]
    public function resolveReturnsNullForUnknownPseudonym(): void
    {
        self::assertNull($this->service->resolve('nonexistent-pseudonym'));
    }

    #[Test]
    public function resolveEmitsAuditEvent(): void
    {
        $pseudonym = $this->service->pseudonymize('user-audit');

        $this->service->resolve($pseudonym);

        self::assertCount(1, $this->auditLogger->calls);

        $call = $this->auditLogger->calls[0];
        self::assertSame(AuditEvent::DataAccess, $call['event']);
        self::assertSame(AuditOutcome::Success, $call['outcome']);
        self::assertSame('system:compliance.pseudonymization', $call['actor']);
        self::assertSame('pseudonym.resolve', $call['action']);
        self::assertSame($pseudonym, $call['resource']);
    }

    #[Test]
    public function resolveDoesNotEmitAuditEventWhenNotFound(): void
    {
        $this->service->resolve('unknown');

        self::assertCount(0, $this->auditLogger->calls);
    }

    #[Test]
    public function existsReturnsTrueForKnownSubject(): void
    {
        $this->service->pseudonymize('user-exists');

        self::assertTrue($this->service->exists('user-exists'));
    }

    #[Test]
    public function existsReturnsFalseForUnknownSubject(): void
    {
        self::assertFalse($this->service->exists('unknown-user'));
    }
}
