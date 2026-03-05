<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Adapter\OAuth2AuthorizationServer;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;
use ReflectionMethod;

/**
 * F385.14 + F385.19 regression suite for redirect_uri validation.
 *
 * Exercises `OAuth2AuthorizationServer::assertSafeRedirectUri()` —
 * the defense-in-depth check that runs before the registered-URI
 * exact-match. Even if a misguided admin registers an unsafe URI
 * (fragment present, `javascript:` scheme, etc.), a request whose
 * `redirect_uri` matches that registration must still be rejected.
 *
 * The helper is private; this suite reaches it via Reflection so the
 * regression coverage tests the actual production logic without
 * standing up the entire authorization-server boot pipeline.
 */
#[CoversClass(OAuth2AuthorizationServer::class)]
final class RedirectUriValidationTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function safeUriProvider(): array
    {
        return [
            ['https://app.example.com/callback'],
            ['https://app.example.com/callback?prefilled=true'],
            ['https://app.example.com:8443/callback'],
            ['http://localhost:3000/callback'], // localhost dev
            ['com.example.app:/oauth/callback'], // mobile custom scheme
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function unsafeUriProvider(): array
    {
        return [
            // RFC 6749 §3.1.2: fragment forbidden
            ['https://app.example.com/callback#frag', 'fragment'],
            ['https://app.example.com/callback?ok=1#frag', 'fragment'],
            ['https://app.example.com/#x', 'fragment'],
            // Dangerous schemes
            ['javascript:alert(1)', 'scheme is not allowed'],
            ['JavaScript:alert(1)', 'scheme is not allowed'],
            ['JAVASCRIPT:alert(1)', 'scheme is not allowed'],
            ['data:text/html,<script>alert(1)</script>', 'scheme is not allowed'],
            ['vbscript:msgbox(1)', 'scheme is not allowed'],
            ['file:///etc/passwd', 'scheme is not allowed'],
            ['about:blank', 'scheme is not allowed'],
            // Missing scheme
            ['/oauth/callback', 'must include a scheme'],
            ['app.example.com/callback', 'must include a scheme'],
        ];
    }

    #[Test]
    #[DataProvider('safeUriProvider')]
    public function safeUriPassesValidation(string $uri): void
    {
        $this->expectNotToPerformAssertions();

        $this->invoke($uri);
    }

    #[Test]
    #[DataProvider('unsafeUriProvider')]
    public function unsafeUriIsRejected(string $uri, string $expectedReason): void
    {
        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage($expectedReason);

        $this->invoke($uri);
    }

    private function invoke(string $uri): void
    {
        $method = new ReflectionMethod(OAuth2AuthorizationServer::class, 'assertSafeRedirectUri');
        $method->invoke(null, $uri);
    }
}
