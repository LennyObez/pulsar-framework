<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivateTokenHeaderParser;

#[CoversClass(PrivateTokenHeaderParser::class)]
final class PrivateTokenHeaderParserTest extends TestCase
{
    #[Test]
    public function parsesAQuotedToken(): void
    {
        self::assertSame('abc-_123==', PrivateTokenHeaderParser::parse('PrivateToken token="abc-_123=="'));
    }

    #[Test]
    public function parsesABareToken(): void
    {
        self::assertSame('abc123', PrivateTokenHeaderParser::parse('PrivateToken token=abc123'));
    }

    #[Test]
    public function isCaseInsensitiveOnTheScheme(): void
    {
        self::assertSame('xyz', PrivateTokenHeaderParser::parse('privatetoken token="xyz"'));
    }

    #[Test]
    public function rejectsAnotherScheme(): void
    {
        self::assertNull(PrivateTokenHeaderParser::parse('Bearer token="xyz"'));
        self::assertNull(PrivateTokenHeaderParser::parse('Basic dXNlcjpwYXNz'));
    }

    #[Test]
    public function returnsNullWhenTokenParamAbsent(): void
    {
        self::assertNull(PrivateTokenHeaderParser::parse('PrivateToken realm="x"'));
    }

    #[Test]
    public function readsFromTheRequestAuthorizationHeader(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Authorization' => 'PrivateToken token="from-request"'],
        );

        self::assertSame('from-request', PrivateTokenHeaderParser::fromRequest($request));
    }

    #[Test]
    public function returnsNullWhenNoAuthorizationHeader(): void
    {
        self::assertNull(PrivateTokenHeaderParser::fromRequest(new ServerRequest(method: 'GET', uri: '/')));
    }
}
