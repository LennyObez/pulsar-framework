<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Exception\SsoException;

final class SsoExceptionTest extends TestCase
{
    #[Test]
    public function invalidStateHasCorrectType(): void
    {
        $e = SsoException::invalidState();

        self::assertSame('invalid_state', $e->errorType);
        self::assertStringContainsString('invalid or expired', $e->getMessage());
    }

    #[Test]
    public function invalidNonceHasCorrectType(): void
    {
        $e = SsoException::invalidNonce();

        self::assertSame('invalid_nonce', $e->errorType);
    }

    #[Test]
    public function invalidIdTokenIncludesReason(): void
    {
        $e = SsoException::invalidIdToken('token expired');

        self::assertSame('invalid_id_token', $e->errorType);
        self::assertStringContainsString('token expired', $e->getMessage());
    }

    #[Test]
    public function unexpectedIdTokenHasCorrectType(): void
    {
        $e = SsoException::unexpectedIdToken();

        self::assertSame('unexpected_id_token', $e->errorType);
    }

    #[Test]
    public function tokenExchangeFailedHasCorrectType(): void
    {
        $e = SsoException::tokenExchangeFailed();

        self::assertSame('token_exchange_failed', $e->errorType);
    }

    #[Test]
    public function providerNotFoundHasCorrectType(): void
    {
        $e = SsoException::providerNotFound();

        self::assertSame('provider_not_found', $e->errorType);
    }

    #[Test]
    public function extensionDisabledHasCorrectType(): void
    {
        $e = SsoException::extensionDisabled();

        self::assertSame('extension_disabled', $e->errorType);
    }

    #[Test]
    public function pkceRequiredHasCorrectType(): void
    {
        $e = SsoException::pkceRequired();

        self::assertSame('pkce_required', $e->errorType);
    }

    #[Test]
    public function unsupportedAlgorithmIncludesAlgName(): void
    {
        $e = SsoException::unsupportedAlgorithm('HS384');

        self::assertSame('unsupported_algorithm', $e->errorType);
        self::assertStringContainsString('HS384', $e->getMessage());
    }

    #[Test]
    public function jwksFetchFailedHasCorrectType(): void
    {
        $e = SsoException::jwksFetchFailed();

        self::assertSame('jwks_fetch_failed', $e->errorType);
    }

    #[Test]
    public function signatureVerificationFailedHasCorrectType(): void
    {
        $e = SsoException::signatureVerificationFailed();

        self::assertSame('signature_verification_failed', $e->errorType);
    }

    #[Test]
    public function providerErrorHasCorrectType(): void
    {
        $e = SsoException::providerError();

        self::assertSame('provider_error', $e->errorType);
    }

    #[Test]
    public function missingAuthorizationCodeHasCorrectType(): void
    {
        $e = SsoException::missingAuthorizationCode();

        self::assertSame('missing_authorization_code', $e->errorType);
    }
}
