<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\InMemoryRefreshTokenRepository;
use Pulsar\Extension\OAuth2\Token\RefreshToken;

final class InMemoryRefreshTokenRepositoryTest extends TestCase
{
    private InMemoryRefreshTokenRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryRefreshTokenRepository();
    }

    private function makeToken(
        string $id = 'rt-1',
        string $tokenValue = 'raw-refresh',
        string $familyId = 'fam-1',
        string $subjectId = 'user-1',
        string $expiresIn = '+30 days',
    ): RefreshToken {
        return new RefreshToken(
            id: $id,
            clientId: 'client-1',
            subjectId: $subjectId,
            sessionId: 'sess-1',
            familyId: $familyId,
            scopes: ['read'],
            expiresAt: new DateTimeImmutable($expiresIn),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        );
    }

    #[Test]
    public function persist_and_consume(): void
    {
        $this->repo->persist($this->makeToken());

        $consumed = $this->repo->consume('raw-refresh');

        self::assertNotNull($consumed);
        self::assertSame('rt-1', $consumed->id);
    }

    #[Test]
    public function consume_returns_null_for_unknown(): void
    {
        self::assertNull($this->repo->consume('nonexistent'));
    }

    #[Test]
    public function consume_returns_null_for_expired_token(): void
    {
        $this->repo->persist($this->makeToken(expiresIn: '-1 day'));

        self::assertNull($this->repo->consume('raw-refresh'));
    }

    #[Test]
    public function consume_returns_null_for_revoked_token(): void
    {
        $this->repo->persist($this->makeToken());
        $this->repo->revoke('rt-1');

        self::assertNull($this->repo->consume('raw-refresh'));
    }

    #[Test]
    public function replay_detection_revokes_entire_family(): void
    {
        $this->repo->persist($this->makeToken('rt-1', 'val-1', 'fam-1'));
        $this->repo->persist($this->makeToken('rt-2', 'val-2', 'fam-1'));

        // First consumption succeeds
        $first = $this->repo->consume('val-1');
        self::assertNotNull($first);
        self::assertFalse($this->repo->wasReplayDetected());

        // Replay of consumed token triggers family revocation
        $replay = $this->repo->consume('val-1');
        self::assertNull($replay);
        self::assertTrue($this->repo->wasReplayDetected());

        // Sibling token in same family is also revoked
        self::assertTrue($this->repo->isRevoked('rt-1'));
        self::assertTrue($this->repo->isRevoked('rt-2'));
        self::assertNull($this->repo->consume('val-2'));
    }

    #[Test]
    public function revoke_family_revokes_all_tokens_in_family(): void
    {
        $this->repo->persist($this->makeToken('rt-1', 'val-1', 'fam-1'));
        $this->repo->persist($this->makeToken('rt-2', 'val-2', 'fam-1'));
        $this->repo->persist($this->makeToken('rt-3', 'val-3', 'fam-2'));

        $this->repo->revokeFamily('fam-1');

        self::assertTrue($this->repo->isRevoked('rt-1'));
        self::assertTrue($this->repo->isRevoked('rt-2'));
        self::assertFalse($this->repo->isRevoked('rt-3'));
    }

    #[Test]
    public function revoke_by_subject_revokes_all_subject_tokens(): void
    {
        $this->repo->persist($this->makeToken('rt-1', 'val-1', 'fam-1', 'user-1'));
        $this->repo->persist($this->makeToken('rt-2', 'val-2', 'fam-2', 'user-1'));
        $this->repo->persist($this->makeToken('rt-3', 'val-3', 'fam-3', 'user-2'));

        $this->repo->revokeBySubject('user-1');

        self::assertTrue($this->repo->isRevoked('rt-1'));
        self::assertTrue($this->repo->isRevoked('rt-2'));
        self::assertFalse($this->repo->isRevoked('rt-3'));
    }

    #[Test]
    public function is_revoked_returns_false_for_active_token(): void
    {
        $this->repo->persist($this->makeToken());

        self::assertFalse($this->repo->isRevoked('rt-1'));
    }

    #[Test]
    public function was_replay_detected_resets_between_consume_calls(): void
    {
        $this->repo->persist($this->makeToken('rt-1', 'val-1', 'fam-1'));
        $this->repo->persist($this->makeToken('rt-2', 'val-2', 'fam-2'));

        // Consume and replay
        $this->repo->consume('val-1');
        $this->repo->consume('val-1');
        self::assertTrue($this->repo->wasReplayDetected());

        // Normal consume resets the flag
        $this->repo->consume('val-2');
        self::assertFalse($this->repo->wasReplayDetected());
    }

    #[Test]
    public function consume_marks_token_as_consumed(): void
    {
        $this->repo->persist($this->makeToken());

        $consumed = $this->repo->consume('raw-refresh');
        self::assertNotNull($consumed);

        // Cannot consume again
        self::assertNull($this->repo->consume('raw-refresh'));
    }
}
