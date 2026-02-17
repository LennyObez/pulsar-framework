<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Features;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Domain\IdTokenClaims;
use Pulsar\Extension\Auth\Social\Domain\LinkAction;
use Pulsar\Extension\Auth\Social\Domain\OAuthTokenSet;
use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;
use Pulsar\Extension\Auth\Social\Features\ExchangeCode\ExchangeCodeResult;
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityRequest;
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityResult;

use function time;

#[CoversClass(ExchangeCodeResult::class)]
#[CoversClass(MapIdentityRequest::class)]
#[CoversClass(MapIdentityResult::class)]
#[CoversClass(LinkAction::class)]
final class FeatureDtosTest extends TestCase
{
    // --- ExchangeCodeResult ---

    #[Test]
    public function exchangeCodeResultConstruction(): void
    {
        $tokenSet = new OAuthTokenSet(
            accessToken: 'ya29.access-token-from-google',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: '1//refresh-token',
            idToken: 'eyJhbGciOiJSUzI1NiJ9.payload.signature',
        );

        $result = new ExchangeCodeResult($tokenSet);

        self::assertSame($tokenSet, $result->tokenSet);
        self::assertNull($result->verifiedClaims);
    }

    #[Test]
    public function exchangeCodeResultWithVerifiedClaims(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'ya29.access-token');
        $claims = new IdTokenClaims(
            sub: '112233445566',
            iss: 'https://accounts.google.com',
            aud: 'client-123.apps.googleusercontent.com',
            exp: time() + 3600,
            iat: time(),
            nonce: 'nonce-abc',
        );

        $result = new ExchangeCodeResult($tokenSet, $claims);

        self::assertSame($claims, $result->verifiedClaims);
        self::assertSame('112233445566', $result->verifiedClaims->sub);
    }

    // --- MapIdentityRequest ---

    #[Test]
    public function mapIdentityRequestConstruction(): void
    {
        $tokenSet = new OAuthTokenSet(
            accessToken: 'gho_github-access-token',
            tokenType: 'bearer',
        );

        $request = new MapIdentityRequest($tokenSet, 'github');

        self::assertSame($tokenSet, $request->tokenSet);
        self::assertSame('github', $request->providerName);
    }

    // --- MapIdentityResult ---

    #[Test]
    public function mapIdentityResultConstruction(): void
    {
        $identity = new SocialIdentity(
            provider: 'google',
            providerUserId: '112233445566',
            email: 'user@gmail.com',
            name: 'Test User',
            avatarUrl: 'https://lh3.googleusercontent.com/photo.jpg',
            rawAttributes: ['locale' => 'en'],
        );

        $result = new MapIdentityResult($identity);

        self::assertSame($identity, $result->socialIdentity);
        self::assertSame('google', $result->socialIdentity->provider);
        self::assertSame('user@gmail.com', $result->socialIdentity->email);
    }

    // --- LinkAction enum ---

    #[Test]
    public function linkActionValues(): void
    {
        self::assertSame('linked', LinkAction::Linked->value);
        self::assertSame('created', LinkAction::Created->value);
        self::assertSame('unlinked', LinkAction::Unlinked->value);
        self::assertSame('rejected', LinkAction::Rejected->value);
    }

    #[Test]
    public function linkActionAllCases(): void
    {
        self::assertCount(4, LinkAction::cases());
    }
}
