<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;

final class OAuth2ExceptionTest extends TestCase
{
    #[Test]
    public function invalid_request_has_correct_code(): void
    {
        $e = OAuth2Exception::invalidRequest('missing param');

        self::assertSame('invalid_request', $e->errorCode());
        self::assertSame(400, $e->httpStatusCode());
        self::assertStringContainsString('missing param', $e->getMessage());
    }

    #[Test]
    public function invalid_client_returns_401(): void
    {
        $e = OAuth2Exception::invalidClient();

        self::assertSame('invalid_client', $e->errorCode());
        self::assertSame(401, $e->httpStatusCode());
    }

    #[Test]
    public function unsupported_grant_type(): void
    {
        $e = OAuth2Exception::unsupportedGrantType('implicit');

        self::assertSame('unsupported_grant_type', $e->errorCode());
        self::assertStringContainsString('implicit', $e->getMessage());
    }

    #[Test]
    public function access_denied_returns_403(): void
    {
        $e = OAuth2Exception::accessDenied('user denied');

        self::assertSame('access_denied', $e->errorCode());
        self::assertSame(403, $e->httpStatusCode());
    }

    #[Test]
    public function server_error_returns_500(): void
    {
        $e = OAuth2Exception::serverError();

        self::assertSame('server_error', $e->errorCode());
        self::assertSame(500, $e->httpStatusCode());
    }

    #[Test]
    public function to_error_response_format(): void
    {
        $e = OAuth2Exception::invalidScope('unknown scope');

        $response = $e->toErrorResponse();

        self::assertSame('invalid_scope', $response['error']);
        self::assertSame('unknown scope', $response['error_description']);
        self::assertArrayNotHasKey('error_uri', $response);
    }

    #[Test]
    public function invalid_grant_factory(): void
    {
        $e = OAuth2Exception::invalidGrant('expired');

        self::assertSame('invalid_grant', $e->errorCode());
        self::assertSame(400, $e->httpStatusCode());
    }

    #[Test]
    public function unauthorized_client_factory(): void
    {
        $e = OAuth2Exception::unauthorizedClient();

        self::assertSame('unauthorized_client', $e->errorCode());
    }

    #[Test]
    public function error_uri_is_null_by_default(): void
    {
        $e = OAuth2Exception::invalidRequest('test');

        self::assertNull($e->errorUri());
    }
}
