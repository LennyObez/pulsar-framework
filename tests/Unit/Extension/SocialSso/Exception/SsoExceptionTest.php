<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use RuntimeException;

#[CoversClass(SsoException::class)]
final class SsoExceptionTest extends TestCase
{
    #[Test]
    public function invalidStateHasCorrectErrorType(): void
    {
        $e = SsoException::invalidState();

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame('invalid_state', $e->errorType);
        self::assertStringContainsString('state', $e->getMessage());
    }

    #[Test]
    public function invalidNonceHasCorrectErrorType(): void
    {
        $e = SsoException::invalidNonce();

        self::assertSame('invalid_nonce', $e->errorType);
    }

    #[Test]
    public function invalidIdTokenIncludesReason(): void
    {
        $e = SsoException::invalidIdToken('expired');

        self::assertSame('invalid_id_token', $e->errorType);
        self::assertStringContainsString('expired', $e->getMessage());
    }

    #[Test]
    public function unexpectedIdTokenHasCorrectType(): void
    {
        self::assertSame('unexpected_id_token', SsoException::unexpectedIdToken()->errorType);
    }

    #[Test]
    public function tokenExchangeFailedHasCorrectType(): void
    {
        self::assertSame('token_exchange_failed', SsoException::tokenExchangeFailed()->errorType);
    }

    #[Test]
    public function providerNotFoundHasCorrectType(): void
    {
        self::assertSame('provider_not_found', SsoException::providerNotFound()->errorType);
    }

    #[Test]
    public function providerErrorHasCorrectType(): void
    {
        self::assertSame('provider_error', SsoException::providerError()->errorType);
    }

    #[Test]
    public function missingAuthorizationCodeHasCorrectType(): void
    {
        self::assertSame('missing_authorization_code', SsoException::missingAuthorizationCode()->errorType);
    }

    #[Test]
    public function extensionDisabledHasCorrectType(): void
    {
        self::assertSame('extension_disabled', SsoException::extensionDisabled()->errorType);
    }

    #[Test]
    public function pkceRequiredHasCorrectType(): void
    {
        self::assertSame('pkce_required', SsoException::pkceRequired()->errorType);
    }

    #[Test]
    public function jwksFetchFailedHasCorrectType(): void
    {
        self::assertSame('jwks_fetch_failed', SsoException::jwksFetchFailed()->errorType);
    }

    #[Test]
    public function signatureVerificationFailedHasCorrectType(): void
    {
        self::assertSame('signature_verification_failed', SsoException::signatureVerificationFailed()->errorType);
    }

    #[Test]
    public function unsupportedAlgorithmIncludesAlgName(): void
    {
        $e = SsoException::unsupportedAlgorithm('HS384');

        self::assertSame('unsupported_algorithm', $e->errorType);
        self::assertStringContainsString('HS384', $e->getMessage());
    }
}
