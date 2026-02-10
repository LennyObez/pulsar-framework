<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;
use RuntimeException;

#[CoversClass(WebAuthnException::class)]
final class WebAuthnExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = WebAuthnException::invalidChallenge();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function invalidChallengeProducesCorrectErrorCode(): void
    {
        $exception = WebAuthnException::invalidChallenge();

        self::assertSame('invalid_challenge', $exception->errorCode());
        self::assertSame('The challenge response is invalid or expired', $exception->getMessage());
    }

    #[Test]
    public function invalidAttestationProducesCorrectErrorCode(): void
    {
        $exception = WebAuthnException::invalidAttestation('Certificate chain invalid');

        self::assertSame('invalid_attestation', $exception->errorCode());
        self::assertSame('Attestation verification failed: Certificate chain invalid', $exception->getMessage());
    }

    #[Test]
    public function invalidAssertionProducesCorrectErrorCode(): void
    {
        $exception = WebAuthnException::invalidAssertion('Signature verification failed');

        self::assertSame('invalid_assertion', $exception->errorCode());
        self::assertSame('Assertion verification failed: Signature verification failed', $exception->getMessage());
    }

    #[Test]
    public function disallowedFormatProducesCorrectErrorCode(): void
    {
        $exception = WebAuthnException::disallowedFormat('fido-u2f');

        self::assertSame('disallowed_format', $exception->errorCode());
        self::assertSame("Attestation format 'fido-u2f' is not allowed by policy", $exception->getMessage());
    }

    #[Test]
    public function cloneDetectedProducesCorrectErrorCode(): void
    {
        $exception = WebAuthnException::cloneDetected('cred-123');

        self::assertSame('clone_detected', $exception->errorCode());
        self::assertSame('Authenticator clone detected for credential: signature counter did not increase', $exception->getMessage());
    }

    #[Test]
    public function credentialNotFoundProducesCorrectErrorCode(): void
    {
        $exception = WebAuthnException::credentialNotFound('cred-456');

        self::assertSame('credential_not_found', $exception->errorCode());
        self::assertSame('The credential is not registered', $exception->getMessage());
    }

    #[Test]
    public function userNotFoundProducesCorrectErrorCode(): void
    {
        $exception = WebAuthnException::userNotFound();

        self::assertSame('user_not_found', $exception->errorCode());
        self::assertSame('No credentials found for the user', $exception->getMessage());
    }

    #[Test]
    public function allFactoryMethodsReturnCorrectType(): void
    {
        self::assertInstanceOf(WebAuthnException::class, WebAuthnException::invalidChallenge());
        self::assertInstanceOf(WebAuthnException::class, WebAuthnException::invalidAttestation('detail'));
        self::assertInstanceOf(WebAuthnException::class, WebAuthnException::invalidAssertion('detail'));
        self::assertInstanceOf(WebAuthnException::class, WebAuthnException::disallowedFormat('fmt'));
        self::assertInstanceOf(WebAuthnException::class, WebAuthnException::cloneDetected('id'));
        self::assertInstanceOf(WebAuthnException::class, WebAuthnException::credentialNotFound('id'));
        self::assertInstanceOf(WebAuthnException::class, WebAuthnException::userNotFound());
    }
}
