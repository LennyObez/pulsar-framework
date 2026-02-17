<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Token\InMemoryPersonalAccessTokenStore;
use Pulsar\Auth\Token\PersonalAccessToken;
use Pulsar\Auth\Token\PersonalAccessTokenManager;
use Pulsar\Auth\Token\PersonalAccessTokenResult;

#[CoversClass(PersonalAccessTokenManager::class)]
#[CoversClass(PersonalAccessToken::class)]
#[CoversClass(PersonalAccessTokenResult::class)]
#[CoversClass(InMemoryPersonalAccessTokenStore::class)]
final class PersonalAccessTokenManagerTest extends TestCase
{
    private PersonalAccessTokenManager $manager;
    private InMemoryPersonalAccessTokenStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryPersonalAccessTokenStore();
        $this->manager = new PersonalAccessTokenManager($this->store);
    }

    #[Test]
    public function createTokenReturnsResultWithPlaintext(): void
    {
        $result = $this->manager->createToken('user-1', 'CI Key');

        self::assertNotEmpty($result->plaintext);
        self::assertStringStartsWith('ptkn_', $result->plaintext);
        self::assertSame('CI Key', $result->token->name);
        self::assertSame('user-1', $result->token->userId);
    }

    #[Test]
    public function validateTokenReturnsTokenForValidPlaintext(): void
    {
        $result = $this->manager->createToken('user-1', 'Test');

        $validated = $this->manager->validateToken($result->plaintext);

        self::assertNotNull($validated);
        self::assertSame($result->token->id, $validated->id);
    }

    #[Test]
    public function validateTokenReturnsNullForInvalidToken(): void
    {
        self::assertNull($this->manager->validateToken('ptkn_invalid_garbage'));
    }

    #[Test]
    public function validateTokenReturnsNullForExpiredToken(): void
    {
        $result = $this->manager->createToken(
            'user-1',
            'Expired',
            expiresAt: new DateTimeImmutable('-1 hour'),
        );

        self::assertNull($this->manager->validateToken($result->plaintext));
    }

    #[Test]
    public function revokeTokenDeletesIt(): void
    {
        $result = $this->manager->createToken('user-1', 'Revocable');

        self::assertTrue($this->manager->revokeToken($result->token->id));
        self::assertNull($this->manager->validateToken($result->plaintext));
    }

    #[Test]
    public function revokeTokenReturnsFalseForNonexistent(): void
    {
        self::assertFalse($this->manager->revokeToken('nonexistent-id'));
    }

    #[Test]
    public function tokensForUserListsAllUserTokens(): void
    {
        (void) $this->manager->createToken('user-1', 'Token A');
        (void) $this->manager->createToken('user-1', 'Token B');
        (void) $this->manager->createToken('user-2', 'Token C');

        $tokens = $this->manager->tokensForUser('user-1');

        self::assertCount(2, $tokens);
        self::assertSame('Token A', $tokens[0]->name);
        self::assertSame('Token B', $tokens[1]->name);
    }

    #[Test]
    public function createTokenWithScopesRestrictsAccess(): void
    {
        $result = $this->manager->createToken(
            'user-1',
            'Scoped',
            scopes: ['read', 'write'],
        );

        self::assertTrue($result->token->hasScope('read'));
        self::assertTrue($result->token->hasScope('write'));
        self::assertFalse($result->token->hasScope('admin'));
    }

    #[Test]
    public function tokenWithoutScopesHasFullAccess(): void
    {
        $result = $this->manager->createToken('user-1', 'Full');

        self::assertTrue($result->token->hasScope('anything'));
        self::assertTrue($result->token->hasAllScopes(['read', 'write', 'admin']));
    }

    #[Test]
    public function hasAllScopesReturnsFalseWhenMissing(): void
    {
        $result = $this->manager->createToken(
            'user-1',
            'Limited',
            scopes: ['read'],
        );

        self::assertFalse($result->token->hasAllScopes(['read', 'write']));
    }

    #[Test]
    public function tokenIsNotExpiredWhenNoExpiry(): void
    {
        $token = new PersonalAccessToken(
            id: 'test',
            userId: 'u1',
            name: 'Forever',
            tokenHash: 'hash',
            prefix: 'ptkn_test1',
            scopes: [],
            createdAt: new DateTimeImmutable(),
        );

        self::assertFalse($token->isExpired());
    }

    #[Test]
    public function tokenIsExpiredWhenPastExpiry(): void
    {
        $token = new PersonalAccessToken(
            id: 'test',
            userId: 'u1',
            name: 'Old',
            tokenHash: 'hash',
            prefix: 'ptkn_test1',
            scopes: [],
            createdAt: new DateTimeImmutable('-2 hours'),
            expiresAt: new DateTimeImmutable('-1 hour'),
        );

        self::assertTrue($token->isExpired());
    }

    #[Test]
    public function validateTokenUpdatesLastUsedTimestamp(): void
    {
        $result = $this->manager->createToken('user-1', 'Touch Test');

        self::assertNull($result->token->lastUsedAt);

        $validated = $this->manager->validateToken($result->plaintext);
        self::assertNotNull($validated);

        $tokens = $this->manager->tokensForUser('user-1');
        self::assertNotNull($tokens[0]->lastUsedAt);
    }

    #[Test]
    public function resultDebugInfoRedactsPlaintext(): void
    {
        $result = $this->manager->createToken('user-1', 'Debug');

        $debug = $result->__debugInfo();

        self::assertSame('[REDACTED]', $debug['plaintext']);
        self::assertSame('Debug', $debug['name']);
    }
}
