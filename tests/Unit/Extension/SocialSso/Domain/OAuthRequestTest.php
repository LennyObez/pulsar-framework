<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\OAuthRequest;

#[CoversClass(OAuthRequest::class)]
final class OAuthRequestTest extends TestCase
{
    #[Test]
    public function constructionWithRequiredFields(): void
    {
        $request = new OAuthRequest(
            redirectUri: 'https://app.local/sso/google/callback',
            scopes: ['openid', 'email', 'profile'],
            state: 'abc123state',
        );

        self::assertSame('https://app.local/sso/google/callback', $request->redirectUri);
        self::assertSame(['openid', 'email', 'profile'], $request->scopes);
        self::assertSame('abc123state', $request->state);
        self::assertNull($request->nonce);
        self::assertNull($request->codeChallenge);
        self::assertSame('S256', $request->codeChallengeMethod);
    }

    #[Test]
    public function constructionWithPkceAndNonce(): void
    {
        $request = new OAuthRequest(
            redirectUri: 'https://app.local/callback',
            scopes: ['openid'],
            state: 'state123',
            nonce: 'nonce456',
            codeChallenge: 'challenge789',
            codeChallengeMethod: 'S256',
        );

        self::assertSame('nonce456', $request->nonce);
        self::assertSame('challenge789', $request->codeChallenge);
        self::assertSame('S256', $request->codeChallengeMethod);
    }
}
