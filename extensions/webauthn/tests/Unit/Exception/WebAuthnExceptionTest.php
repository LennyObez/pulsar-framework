<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;

final class WebAuthnExceptionTest extends TestCase
{
    #[Test]
    public function invalid_challenge_has_correct_error_code(): void
    {
        $e = WebAuthnException::invalidChallenge();

        self::assertSame('invalid_challenge', $e->errorCode());
        self::assertStringContainsString('challenge', $e->getMessage());
    }

    #[Test]
    public function invalid_attestation_includes_detail(): void
    {
        $e = WebAuthnException::invalidAttestation('Bad format');

        self::assertSame('invalid_attestation', $e->errorCode());
        self::assertStringContainsString('Bad format', $e->getMessage());
    }

    #[Test]
    public function invalid_assertion_includes_detail(): void
    {
        $e = WebAuthnException::invalidAssertion('Signature failed');

        self::assertSame('invalid_assertion', $e->errorCode());
        self::assertStringContainsString('Signature failed', $e->getMessage());
    }

    #[Test]
    public function disallowed_format(): void
    {
        $e = WebAuthnException::disallowedFormat('tpm');

        self::assertSame('disallowed_format', $e->errorCode());
        self::assertStringContainsString('tpm', $e->getMessage());
    }

    #[Test]
    public function clone_detected(): void
    {
        $e = WebAuthnException::cloneDetected('cred-xyz');

        self::assertSame('clone_detected', $e->errorCode());
        self::assertStringContainsString('clone detected', $e->getMessage());
    }

    #[Test]
    public function credential_not_found(): void
    {
        $e = WebAuthnException::credentialNotFound('cred-abc');

        self::assertSame('credential_not_found', $e->errorCode());
    }

    #[Test]
    public function user_not_found(): void
    {
        $e = WebAuthnException::userNotFound();

        self::assertSame('user_not_found', $e->errorCode());
        self::assertStringContainsString('No credentials', $e->getMessage());
    }
}
