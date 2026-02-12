<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Features\InitiateLogin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\PkceChallenge;
use Pulsar\Extension\SocialSso\Features\InitiateLogin\InitiateLoginResult;

final class InitiateLoginResultTest extends TestCase
{
    #[Test]
    public function constructsWithoutPkce(): void
    {
        $result = new InitiateLoginResult('https://auth-url', 'state-val');

        self::assertSame('https://auth-url', $result->authorizationUrl);
        self::assertSame('state-val', $result->state);
        self::assertNull($result->pkceChallenge);
    }

    #[Test]
    public function constructsWithPkce(): void
    {
        $pkce = new PkceChallenge('v', 'c', 'S256');
        $result = new InitiateLoginResult('https://auth', 'st', $pkce);

        self::assertSame($pkce, $result->pkceChallenge);
    }
}
