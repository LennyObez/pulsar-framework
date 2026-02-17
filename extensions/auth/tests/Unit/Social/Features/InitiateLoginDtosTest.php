<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Features;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Domain\PkceChallenge;
use Pulsar\Extension\Auth\Social\Features\InitiateLogin\InitiateLoginRequest;
use Pulsar\Extension\Auth\Social\Features\InitiateLogin\InitiateLoginResult;

#[CoversClass(InitiateLoginRequest::class)]
#[CoversClass(InitiateLoginResult::class)]
final class InitiateLoginDtosTest extends TestCase
{
    // --- InitiateLoginRequest ---

    #[Test]
    public function requestWithProviderNameOnly(): void
    {
        $request = new InitiateLoginRequest(providerName: 'google');

        self::assertSame('google', $request->providerName);
        self::assertNull($request->redirectUri);
    }

    #[Test]
    public function requestWithRedirectUri(): void
    {
        $request = new InitiateLoginRequest(
            providerName: 'github',
            redirectUri: 'https://app.test/callback',
        );

        self::assertSame('github', $request->providerName);
        self::assertSame('https://app.test/callback', $request->redirectUri);
    }

    // --- InitiateLoginResult ---

    #[Test]
    public function resultWithoutPkce(): void
    {
        $result = new InitiateLoginResult(
            authorizationUrl: 'https://accounts.google.com/o/oauth2/v2/auth?client_id=abc',
            state: 'random-state-token',
        );

        self::assertSame('https://accounts.google.com/o/oauth2/v2/auth?client_id=abc', $result->authorizationUrl);
        self::assertSame('random-state-token', $result->state);
        self::assertNull($result->pkceChallenge);
    }

    #[Test]
    public function resultWithPkce(): void
    {
        $pkce = new PkceChallenge(
            verifier: 'verifier-string',
            challenge: 'challenge-hash',
            method: 'S256',
        );

        $result = new InitiateLoginResult(
            authorizationUrl: 'https://github.com/login/oauth/authorize',
            state: 'state-token',
            pkceChallenge: $pkce,
        );

        self::assertNotNull($result->pkceChallenge);
        self::assertSame('verifier-string', $result->pkceChallenge->verifier);
        self::assertSame('S256', $result->pkceChallenge->method);
    }
}
