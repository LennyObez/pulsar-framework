<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\WebAuthnAttestationVerifier;

use function json_encode;
use function random_bytes;
use function str_repeat;

#[CoversClass(WebAuthnAttestationVerifier::class)]
final class WebAuthnAttestationVerifierTest extends TestCase
{
    private WebAuthnAttestationVerifier $verifier;

    protected function setUp(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['webauthn' => $key]);
        $this->verifier = new WebAuthnAttestationVerifier($keyRing);
    }

    #[Test]
    public function verifySucceedsWithNoneAttestation(): void
    {
        $attestation = $this->buildAttestation('none');

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertTrue($result->verified);
        self::assertSame(0.3, $result->confidence);
    }

    #[Test]
    public function verifySucceedsWithPackedAttestation(): void
    {
        $attestation = $this->buildAttestation('packed', [
            'sig' => 'signature-data',
            'alg' => -7,
        ]);

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertTrue($result->verified);
        self::assertSame(0.9, $result->confidence);
    }

    #[Test]
    public function verifySucceedsWithFidoU2fAttestation(): void
    {
        $attestation = $this->buildAttestation('fido-u2f', [
            'sig' => 'signature-data',
            'x5c' => ['cert-data'],
        ]);

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertTrue($result->verified);
        self::assertSame(0.9, $result->confidence);
    }

    #[Test]
    public function verifyFailsWithUnsupportedFormat(): void
    {
        $attestation = $this->buildAttestation('tpm');

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('Unsupported attestation format', $result->reason);
    }

    #[Test]
    public function verifyFailsWithMissingFormat(): void
    {
        $attestation = [
            'authData' => str_repeat('x', 37),
            'clientDataJSON' => '{}',
        ];

        $result = $this->verifier->verify($attestation, 'challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('Missing or invalid attestation format', $result->reason);
    }

    #[Test]
    public function verifyFailsWithMissingAuthData(): void
    {
        $result = $this->verifier->verify(
            ['fmt' => 'none', 'clientDataJSON' => '{}'],
            'challenge',
            'https://example.com',
        );

        self::assertFalse($result->verified);
        self::assertStringContainsString('Missing authenticator data', $result->reason);
    }

    #[Test]
    public function verifyFailsWithMissingClientDataJSON(): void
    {
        $result = $this->verifier->verify(
            ['fmt' => 'none', 'authData' => str_repeat('x', 37)],
            'challenge',
            'https://example.com',
        );

        self::assertFalse($result->verified);
        self::assertStringContainsString('Missing client data JSON', $result->reason);
    }

    #[Test]
    public function verifyFailsWithChallengeMismatch(): void
    {
        $attestation = $this->buildAttestation('none', [], 'wrong-challenge');

        $result = $this->verifier->verify($attestation, 'expected-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('Challenge mismatch', $result->reason);
    }

    #[Test]
    public function verifyFailsWithOriginMismatch(): void
    {
        $attestation = $this->buildAttestation('none', [], 'test-challenge', 'https://evil.com');

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('Origin mismatch', $result->reason);
    }

    #[Test]
    public function verifyFailsWithInvalidClientDataType(): void
    {
        $clientData = json_encode([
            'type' => 'webauthn.get', // Should be webauthn.create
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $attestation = [
            'fmt' => 'none',
            'authData' => str_repeat('x', 37),
            'clientDataJSON' => $clientData,
        ];

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('Invalid client data type', $result->reason);
    }

    #[Test]
    public function verifyFailsWithTooShortAuthData(): void
    {
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'test-challenge',
            'origin' => 'https://example.com',
        ], JSON_THROW_ON_ERROR);

        $attestation = [
            'fmt' => 'none',
            'authData' => 'short',
            'clientDataJSON' => $clientData,
        ];

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('Authenticator data too short', $result->reason);
    }

    #[Test]
    public function verifyFailsPackedWithMissingSignature(): void
    {
        $attestation = $this->buildAttestation('packed', ['alg' => -7]);

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('missing signature', $result->reason);
    }

    #[Test]
    public function verifyFailsPackedWithMissingAlgorithm(): void
    {
        $attestation = $this->buildAttestation('packed', ['sig' => 'data']);

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('missing algorithm', $result->reason);
    }

    #[Test]
    public function verifyFailsPackedWithEmptyCertChain(): void
    {
        $attestation = $this->buildAttestation('packed', [
            'sig' => 'data',
            'alg' => -7,
            'x5c' => [],
        ]);

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('Empty certificate chain', $result->reason);
    }

    #[Test]
    public function verifyFailsFidoU2fWithMissingSignature(): void
    {
        $attestation = $this->buildAttestation('fido-u2f', ['x5c' => ['cert']]);

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('missing signature', $result->reason);
    }

    #[Test]
    public function verifyFailsFidoU2fWithMissingCertificate(): void
    {
        $attestation = $this->buildAttestation('fido-u2f', ['sig' => 'data']);

        $result = $this->verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('missing certificate', $result->reason);
    }

    #[Test]
    public function verifyFailsWhenKeyUnavailable(): void
    {
        $keyRing = new EnvKeyRing([]);
        $verifier = new WebAuthnAttestationVerifier($keyRing);

        $attestation = $this->buildAttestation('none');

        $result = $verifier->verify($attestation, 'test-challenge', 'https://example.com');

        self::assertFalse($result->verified);
        self::assertStringContainsString('key unavailable', $result->reason);
    }

    /**
     * Build a valid attestation array for testing.
     *
     * @param array<string, mixed> $attStmt
     * @return array{fmt: string, authData: string, clientDataJSON: string, attStmt?: array<string, mixed>}
     */
    private function buildAttestation(
        string $format,
        array $attStmt = [],
        string $challenge = 'test-challenge',
        string $origin = 'https://example.com',
    ): array {
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => $origin,
        ], JSON_THROW_ON_ERROR);

        $attestation = [
            'fmt' => $format,
            'authData' => str_repeat('x', 37),
            'clientDataJSON' => $clientData,
        ];

        if ($attStmt !== []) {
            $attestation['attStmt'] = $attStmt;
        }

        return $attestation;
    }
}
