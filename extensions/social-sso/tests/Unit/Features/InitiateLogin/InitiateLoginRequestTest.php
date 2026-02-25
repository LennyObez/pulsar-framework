<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Features\InitiateLogin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Features\InitiateLogin\InitiateLoginRequest;

final class InitiateLoginRequestTest extends TestCase
{
    #[Test]
    public function constructsWithProviderOnly(): void
    {
        $req = new InitiateLoginRequest('google');

        self::assertSame('google', $req->providerName);
        self::assertNull($req->redirectUri);
    }

    #[Test]
    public function constructsWithRedirectUri(): void
    {
        $req = new InitiateLoginRequest('github', 'https://app/cb');

        self::assertSame('https://app/cb', $req->redirectUri);
    }
}
