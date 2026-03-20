<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Ceremony;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\Auth\WebAuthn\Attestation\AttestationResult;
use Pulsar\Extension\Auth\WebAuthn\Attestation\AttestationTrustLevel;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;
use Throwable;

use function base64_encode;
use function json_encode;
use function rtrim;
use function str_replace;

/**
 * VECTORS-WA-01: W3C WebAuthn conformance + attack-surface vectors
 * (ADR-0032).
 *
 * GA-gate suite per ADR-0032. The full W3C / FIDO Alliance vector
 * corpus is a multi-PR import (registration ceremony × 7 attestation
 * formats + authentication ceremony × counter monotonicity + resident
 * credential). This file covers the input-validation surface that
 * DOES NOT need real attestation fixtures:
 *
 *   - Malformed credential JSON refused.
 *   - Missing required fields refused.
 *   - clientDataJSON `type` mismatch refused (webauthn.get vs
 *     webauthn.create confusion).
 *   - clientDataJSON challenge mismatch refused.
 *   - clientDataJSON origin mismatch refused.
 *
 * The CBOR conformance suite (CborDecoderRfc8949ConformanceTest, 31
 * vectors from RFC 8949 §A) covers the byte-level decoder. The
 * attestation format coverage (none / packed / fido-u2f / android-key
 * / android-safetynet / apple / tpm) requires the full W3C corpus and
 * is tracked under the VECTORS-WA-01 follow-up PR.
 *
 * Foundation references:
 *   - W3C WebAuthn Level 2/3 spec §7.1 (Registration), §7.2 (Auth).
 *   - <https://github.com/web-auth/webauthn-test-vectors>
 *   - WebAuthn attack catalogue (origin confusion, RP-ID hash mismatch,
 *     challenge replay, alg whitelist bypass).
 */
#[CoversClass(RegistrationCeremony::class)]
final class W3cConformanceVectorTest extends TestCase
{
    private RegistrationCeremony $ceremony;

    protected function setUp(): void
    {
        $config = new WebAuthnConfig(
            rpId: 'example.test',
            rpName: 'Example RP',
            origin: 'https://example.test',
        );

        $this->ceremony = new RegistrationCeremony(
            $config,
            new NoopAttestationVerifier(),
            new InMemoryCredentialRepository(),
            new NullAuditLogger(),
        );
    }

    /**
     * Malformed input refused — non-JSON, empty, control bytes.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function malformedCredentialJson(): iterable
    {
        yield 'empty string'         => [''];
        yield 'plain text'           => ['not-json'];
        yield 'truncated JSON'       => ['{"id":'];
        yield 'unclosed quote'       => ['{"id":"abc'];
        yield 'control bytes only'   => ["\x00\x01\x02"];
        yield 'object missing response field' => ['{"id":"abc","type":"public-key"}'];
    }

    #[Test]
    #[DataProvider('malformedCredentialJson')]
    public function malformedCredentialJsonIsRefused(string $credentialJson): void
    {
        $this->expectException(Throwable::class);
        $this->ceremony->verify($credentialJson, 'expected-challenge');
    }

    /**
     * Type-confusion attack: clientDataJSON.type is "webauthn.get"
     * (authentication ceremony) but submitted to registration.verify().
     */
    #[Test]
    public function typeConfusionRegistrationVsAuthenticationIsRefused(): void
    {
        $clientData = self::b64u(json_encode([
            'type' => 'webauthn.get', // wrong: should be webauthn.create
            'challenge' => 'aaa',
            'origin' => 'https://example.test',
        ]));

        $credential = json_encode([
            'id' => 'cred-1',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $clientData,
                'attestationObject' => 'AA',
            ],
        ]);

        $this->expectException(WebAuthnException::class);
        $this->ceremony->verify($credential, 'aaa');
    }

    /**
     * Challenge mismatch: clientDataJSON contains a different challenge
     * than the server expected. Replay defence.
     */
    #[Test]
    public function challengeMismatchIsRefused(): void
    {
        $clientData = self::b64u(json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'attacker-challenge',
            'origin' => 'https://example.test',
        ]));

        $credential = json_encode([
            'id' => 'cred-1',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $clientData,
                'attestationObject' => 'AA',
            ],
        ]);

        $this->expectException(WebAuthnException::class);
        $this->ceremony->verify($credential, 'server-challenge');
    }

    /**
     * Origin mismatch: clientDataJSON.origin is not the configured RP
     * origin. Cross-origin / RP-confusion defence.
     */
    #[Test]
    public function originMismatchIsRefused(): void
    {
        $clientData = self::b64u(json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'aaa',
            'origin' => 'https://evil.test', // wrong origin
        ]));

        $credential = json_encode([
            'id' => 'cred-1',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $clientData,
                'attestationObject' => 'AA',
            ],
        ]);

        $this->expectException(WebAuthnException::class);
        $this->ceremony->verify($credential, 'aaa');
    }

    /**
     * Origin mismatch via case variation. The RFC origin comparison
     * is case-sensitive per RFC 6454 §4.
     */
    #[Test]
    public function originCaseVariationIsRefused(): void
    {
        $clientData = self::b64u(json_encode([
            'type' => 'webauthn.create',
            'challenge' => 'aaa',
            'origin' => 'https://EXAMPLE.test', // case-altered
        ]));

        $credential = json_encode([
            'id' => 'cred-1',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $clientData,
                'attestationObject' => 'AA',
            ],
        ]);

        $this->expectException(WebAuthnException::class);
        $this->ceremony->verify($credential, 'aaa');
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($bytes)), '=');
    }
}

/**
 * @internal No-op AttestationVerifier — the ceremony test reaches the
 * verifyClientData() gate first, so attestation is not exercised.
 */
final readonly class NoopAttestationVerifier implements AttestationVerifierInterface
{
    #[Override]
    public function verify(string $format, string $attestationObject, string $clientDataJson): AttestationResult
    {
        return new AttestationResult(
            verified: true,
            format: $format,
            trustLevel: AttestationTrustLevel::Basic,
            aaguid: '',
        );
    }

    #[Override]
    public function isFormatAllowed(string $format): bool
    {
        return true;
    }

    #[Override]
    public function allowedFormats(): array
    {
        return ['none', 'packed', 'fido-u2f', 'android-key', 'android-safetynet', 'apple', 'tpm'];
    }
}
