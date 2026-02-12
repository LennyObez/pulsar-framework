<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\OAuthRequest;

final class OAuthRequestTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $request = new OAuthRequest(
            redirectUri: 'https://app.local/callback',
            scopes: ['openid', 'email'],
            state: 'state-abc',
        );

        self::assertSame('https://app.local/callback', $request->redirectUri);
        self::assertSame(['openid', 'email'], $request->scopes);
        self::assertSame('state-abc', $request->state);
        self::assertNull($request->nonce);
        self::assertNull($request->codeChallenge);
        self::assertSame('S256', $request->codeChallengeMethod);
    }

    #[Test]
    public function constructsWithPkceAndNonce(): void
    {
        $request = new OAuthRequest(
            redirectUri: 'https://app.local/cb',
            scopes: ['openid'],
            state: 'st',
            nonce: 'nonce-val',
            codeChallenge: 'challenge-val',
            codeChallengeMethod: 'S256',
        );

        self::assertSame('nonce-val', $request->nonce);
        self::assertSame('challenge-val', $request->codeChallenge);
    }
}
