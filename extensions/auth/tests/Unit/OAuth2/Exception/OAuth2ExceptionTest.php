<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Exception\OAuth2Exception;
use RuntimeException;

#[CoversClass(OAuth2Exception::class)]
final class OAuth2ExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = OAuth2Exception::invalidRequest('Missing parameter');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function invalidRequestProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::invalidRequest('The request is missing a required parameter');

        self::assertSame('invalid_request', $exception->errorCode());
        self::assertSame('The request is missing a required parameter', $exception->getMessage());
        self::assertSame(400, $exception->httpStatusCode());
    }

    #[Test]
    public function invalidClientProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::invalidClient();

        self::assertSame('invalid_client', $exception->errorCode());
        self::assertSame('Client authentication failed', $exception->getMessage());
        self::assertSame(401, $exception->httpStatusCode());
    }

    #[Test]
    public function invalidClientAcceptsCustomDetail(): void
    {
        $exception = OAuth2Exception::invalidClient('Unknown client');

        self::assertSame('invalid_client', $exception->errorCode());
        self::assertSame('Unknown client', $exception->getMessage());
    }

    #[Test]
    public function invalidGrantProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::invalidGrant();

        self::assertSame('invalid_grant', $exception->errorCode());
        self::assertSame('The provided grant is invalid', $exception->getMessage());
        self::assertSame(400, $exception->httpStatusCode());
    }

    #[Test]
    public function unauthorizedClientProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::unauthorizedClient();

        self::assertSame('unauthorized_client', $exception->errorCode());
        self::assertSame('The client is not authorized for this grant type', $exception->getMessage());
        self::assertSame(400, $exception->httpStatusCode());
    }

    #[Test]
    public function unsupportedGrantTypeProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::unsupportedGrantType('implicit');

        self::assertSame('unsupported_grant_type', $exception->errorCode());
        self::assertSame("Grant type 'implicit' is not supported", $exception->getMessage());
        self::assertSame(400, $exception->httpStatusCode());
    }

    #[Test]
    public function invalidScopeProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::invalidScope();

        self::assertSame('invalid_scope', $exception->errorCode());
        self::assertSame('The requested scope is invalid', $exception->getMessage());
        self::assertSame(400, $exception->httpStatusCode());
    }

    #[Test]
    public function accessDeniedProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::accessDenied();

        self::assertSame('access_denied', $exception->errorCode());
        self::assertSame('Access denied', $exception->getMessage());
        self::assertSame(403, $exception->httpStatusCode());
    }

    #[Test]
    public function serverErrorProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::serverError();

        self::assertSame('server_error', $exception->errorCode());
        self::assertSame('An unexpected error occurred', $exception->getMessage());
        self::assertSame(500, $exception->httpStatusCode());
    }

    #[Test]
    public function registrationDisabledProducesCorrectErrorCode(): void
    {
        $exception = OAuth2Exception::registrationDisabled();

        self::assertSame('invalid_request', $exception->errorCode());
        self::assertSame('Dynamic client registration is disabled', $exception->getMessage());
        self::assertSame(403, $exception->httpStatusCode());
    }

    #[Test]
    public function toErrorResponseMatchesRfc6749Format(): void
    {
        $exception = OAuth2Exception::invalidRequest('Missing code_challenge');

        $response = $exception->toErrorResponse();

        self::assertArrayHasKey('error', $response);
        self::assertArrayHasKey('error_description', $response);
        self::assertSame('invalid_request', $response['error']);
        self::assertSame('Missing code_challenge', $response['error_description']);
    }

    #[Test]
    public function toErrorResponseOmitsErrorUriWhenNull(): void
    {
        $exception = OAuth2Exception::invalidRequest('Test');

        $response = $exception->toErrorResponse();

        self::assertArrayNotHasKey('error_uri', $response);
    }

    #[Test]
    public function errorUriDefaultsToNull(): void
    {
        $exception = OAuth2Exception::invalidRequest('Test');

        self::assertNull($exception->errorUri());
    }

    #[Test]
    public function httpStatusCodesAreCorrect(): void
    {
        self::assertSame(400, OAuth2Exception::invalidRequest('x')->httpStatusCode());
        self::assertSame(401, OAuth2Exception::invalidClient()->httpStatusCode());
        self::assertSame(400, OAuth2Exception::invalidGrant()->httpStatusCode());
        self::assertSame(400, OAuth2Exception::unauthorizedClient()->httpStatusCode());
        self::assertSame(400, OAuth2Exception::unsupportedGrantType('x')->httpStatusCode());
        self::assertSame(400, OAuth2Exception::invalidScope()->httpStatusCode());
        self::assertSame(403, OAuth2Exception::accessDenied()->httpStatusCode());
        self::assertSame(500, OAuth2Exception::serverError()->httpStatusCode());
        self::assertSame(403, OAuth2Exception::registrationDisabled()->httpStatusCode());
    }
}
