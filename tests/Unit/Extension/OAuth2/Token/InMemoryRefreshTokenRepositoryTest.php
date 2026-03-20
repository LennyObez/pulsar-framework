<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryRefreshTokenRepository;
use Pulsar\Extension\Auth\OAuth2\Token\RefreshToken;

/**
 * Tests for the real InMemoryRefreshTokenRepository implementation.
 *
 * Validates persist + consume flow, family revocation, replay detection,
 * and wasReplayDetected() tracking.
 */
#[CoversClass(InMemoryRefreshTokenRepository::class)]
final class InMemoryRefreshTokenRepositoryTest extends TestCase
{
    private InMemoryRefreshTokenRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryRefreshTokenRepository();
    }

    #[Test]
    public function persistAndConsumeFlow(): void
    {
        $token = new RefreshToken(
            id: 'rt-repo-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'raw-token-value',
        );

        $this->repository->persist($token);

        $consumed = $this->repository->consume('raw-token-value');

        self::assertNotNull($consumed);
        self::assertSame('rt-repo-001', $consumed->id);
    }

    #[Test]
    public function consumeReturnsNullForUnknownToken(): void
    {
        $consumed = $this->repository->consume('nonexistent-token');

        self::assertNull($consumed);
    }

    #[Test]
    public function consumeReturnsNullForAlreadyConsumedToken(): void
    {
        $token = new RefreshToken(
            id: 'rt-repo-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'consume-twice',
        );

        $this->repository->persist($token);

        // First consume succeeds
        $first = $this->repository->consume('consume-twice');
        self::assertNotNull($first);

        // Second consume triggers replay detection — returns null
        $second = $this->repository->consume('consume-twice');
        self::assertNull($second);
    }

    #[Test]
    public function familyRevocation(): void
    {
        $token1 = new RefreshToken(
            id: 'rt-fam-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-revoke',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'family-token-1',
        );

        $token2 = new RefreshToken(
            id: 'rt-fam-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-revoke',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'family-token-2',
        );

        $this->repository->persist($token1);
        $this->repository->persist($token2);

        $this->repository->revokeFamily('family-revoke');

        self::assertTrue($this->repository->isRevoked('rt-fam-001'));
        self::assertTrue($this->repository->isRevoked('rt-fam-002'));
    }

    #[Test]
    public function revokeSpecificToken(): void
    {
        $token = new RefreshToken(
            id: 'rt-rev-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-001',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'revoke-me',
        );

        $this->repository->persist($token);

        self::assertFalse($this->repository->isRevoked('rt-rev-001'));

        $this->repository->revoke('rt-rev-001');

        self::assertTrue($this->repository->isRevoked('rt-rev-001'));
    }

    #[Test]
    public function replayDetectionRevokesFamilyOnReuse(): void
    {
        $token1 = new RefreshToken(
            id: 'rt-replay-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-replay',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'replay-token',
        );

        $token2 = new RefreshToken(
            id: 'rt-replay-002',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-replay',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'current-token',
        );

        $this->repository->persist($token1);
        $this->repository->persist($token2);

        // First consume: succeeds
        $result = $this->repository->consume('replay-token');
        self::assertNotNull($result);
        self::assertFalse($this->repository->wasReplayDetected());

        // Replay: reusing consumed token triggers family revocation
        $replay = $this->repository->consume('replay-token');
        self::assertNull($replay);
        self::assertTrue($this->repository->wasReplayDetected());

        // All tokens in the family are now revoked
        self::assertTrue($this->repository->isRevoked('rt-replay-001'));
        self::assertTrue($this->repository->isRevoked('rt-replay-002'));
    }

    #[Test]
    public function wasReplayDetectedResetsBetweenCalls(): void
    {
        $token = new RefreshToken(
            id: 'rt-reset-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-reset',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'reset-token',
        );

        $this->repository->persist($token);

        // Consume
        $this->repository->consume('reset-token');
        self::assertFalse($this->repository->wasReplayDetected());

        // Replay triggers detection
        $this->repository->consume('reset-token');
        self::assertTrue($this->repository->wasReplayDetected());

        // Next consume of unknown token resets the flag
        $this->repository->consume('unknown-token');
        self::assertFalse($this->repository->wasReplayDetected());
    }

    #[Test]
    public function revokeBySubject(): void
    {
        $this->repository->persist(new RefreshToken(
            id: 'rt-sub-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-a',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'sub-token-1',
        ));

        $this->repository->persist(new RefreshToken(
            id: 'rt-sub-002',
            clientId: 'client-2',
            subjectId: 'user-42',
            sessionId: 'session-xyz',
            familyId: 'family-b',
            scopes: ['api:read'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'sub-token-2',
        ));

        $this->repository->revokeBySubject('user-42');

        self::assertTrue($this->repository->isRevoked('rt-sub-001'));
        self::assertTrue($this->repository->isRevoked('rt-sub-002'));
    }

    #[Test]
    public function expiredTokenCannotBeConsumed(): void
    {
        $token = new RefreshToken(
            id: 'rt-exp-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-exp',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('-1 second'),
            issuedAt: new DateTimeImmutable('-31 days'),
            tokenValue: 'expired-rt',
        );

        $this->repository->persist($token);

        self::assertNull($this->repository->consume('expired-rt'));
    }

    #[Test]
    public function revokedTokenCannotBeConsumed(): void
    {
        $token = new RefreshToken(
            id: 'rt-rvc-001',
            clientId: 'client-1',
            subjectId: 'user-42',
            sessionId: 'session-abc',
            familyId: 'family-rvc',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: 'revoked-rt',
        );

        $this->repository->persist($token);
        $this->repository->revoke('rt-rvc-001');

        self::assertNull($this->repository->consume('revoked-rt'));
    }
}
