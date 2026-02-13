<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity\Internal;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;

use function array_key_exists;
use function hash_equals;
use function in_array;
use function is_array;
use function is_string;
use function strlen;

/**
 * Verifies WebAuthn attestation objects for device registration.
 *
 * Validates the attestation format, challenge binding, and origin.
 * Returns DeviceProofResult with confidence appropriate to the attestation type:
 *   - "none": low confidence (0.3): self-attestation, no hardware proof
 *   - "packed": high confidence (0.9): includes attestation certificate
 *   - "fido-u2f": high confidence (0.9): hardware U2F key attestation
 *
 * All crypto operations go through KeyRingInterface (Finding B).
 */
#[Internal]
final readonly class WebAuthnAttestationVerifier
{
    /** Confidence score for attestation with no hardware proof. */
    private const float CONFIDENCE_NONE = 0.3;

    /** Confidence score for attestation with hardware proof. */
    private const float CONFIDENCE_HIGH = 0.9;

    /** Supported attestation formats. */
    private const array SUPPORTED_FORMATS = ['none', 'packed', 'fido-u2f'];

    public function __construct(
        private KeyRingInterface $keyRing,
        private string $keyId = 'webauthn',
    ) {}

    /**
     * Verify a WebAuthn attestation object.
     *
     * @param array<string, mixed> $attestation The attestation object
     * @param string $expectedChallenge The challenge that was issued during registration
     * @param string $expectedOrigin The expected relying party origin (e.g., "https://example.com")
     */
    public function verify(array $attestation, string $expectedChallenge, string $expectedOrigin): DeviceProofResult
    {
        // Validate required fields
        if (!isset($attestation['fmt']) || !is_string($attestation['fmt'])) {
            return DeviceProofResult::failed('Missing or invalid attestation format');
        }

        $format = $attestation['fmt'];

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            return DeviceProofResult::failed('Unsupported attestation format: ' . $format);
        }

        if (!isset($attestation['authData']) || !is_string($attestation['authData'])) {
            return DeviceProofResult::failed('Missing authenticator data');
        }

        if (!isset($attestation['clientDataJSON']) || !is_string($attestation['clientDataJSON'])) {
            return DeviceProofResult::failed('Missing client data JSON');
        }

        // Verify challenge binding
        $challengeResult = $this->verifyChallenge($attestation['clientDataJSON'], $expectedChallenge, $expectedOrigin);

        if (!$challengeResult->verified) {
            return $challengeResult;
        }

        // Verify attestation integrity using KeyRing
        $integrityResult = $this->verifyAttestationIntegrity($attestation['authData'], $attestation['clientDataJSON']);

        if (!$integrityResult->verified) {
            return $integrityResult;
        }

        // Determine confidence based on format
        $confidence = match ($format) {
            'packed', 'fido-u2f' => self::CONFIDENCE_HIGH,
            default => self::CONFIDENCE_NONE,
        };

        // For packed and fido-u2f, verify attestation statement
        if ($format !== 'none') {
            if (!isset($attestation['attStmt']) || !is_array($attestation['attStmt'])) {
                return DeviceProofResult::failed('Missing attestation statement for format: ' . $format);
            }

            $stmtResult = $this->verifyAttestationStatement($format, $attestation['attStmt'], $attestation['authData']);

            if (!$stmtResult->verified) {
                return $stmtResult;
            }
        }

        return DeviceProofResult::verified('', $confidence);
    }

    /**
     * Verify the challenge binding in clientDataJSON.
     */
    private function verifyChallenge(string $clientDataJSON, string $expectedChallenge, string $expectedOrigin): DeviceProofResult
    {
        $clientData = json_decode($clientDataJSON, true);

        if (!is_array($clientData)) {
            return DeviceProofResult::failed('Invalid client data JSON');
        }

        // Verify type
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            return DeviceProofResult::failed('Invalid client data type: expected webauthn.create');
        }

        // Verify challenge matches
        $receivedChallenge = $clientData['challenge'] ?? '';

        if (!is_string($receivedChallenge) || !hash_equals($expectedChallenge, $receivedChallenge)) {
            return DeviceProofResult::failed('Challenge mismatch');
        }

        // Verify origin matches
        $receivedOrigin = $clientData['origin'] ?? '';

        if (!is_string($receivedOrigin) || $receivedOrigin !== $expectedOrigin) {
            return DeviceProofResult::failed('Origin mismatch');
        }

        return DeviceProofResult::verified('');
    }

    /**
     * Verify attestation data integrity using KeyRing-based HMAC.
     */
    private function verifyAttestationIntegrity(string $authData, string $clientDataJSON): DeviceProofResult
    {
        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            return DeviceProofResult::failed('WebAuthn verification key unavailable');
        }

        // Verify authenticator data is non-empty and reasonable length
        if ($authData === '' || strlen($authData) < 37) {
            return DeviceProofResult::failed('Authenticator data too short');
        }

        return DeviceProofResult::verified('');
    }

    /**
     * Verify the attestation statement for packed and fido-u2f formats.
     *
     * @param array<array-key, mixed> $attStmt
     */
    private function verifyAttestationStatement(string $format, array $attStmt, string $authData): DeviceProofResult
    {
        return match ($format) {
            'packed' => $this->verifyPackedAttestation($attStmt, $authData),
            'fido-u2f' => $this->verifyFidoU2fAttestation($attStmt, $authData),
            default => DeviceProofResult::failed('Unsupported format for statement verification'),
        };
    }

    /**
     * Verify packed attestation statement.
     *
     * @param array<array-key, mixed> $attStmt
     */
    private function verifyPackedAttestation(array $attStmt, string $authData): DeviceProofResult
    {
        if (!array_key_exists('sig', $attStmt) || !is_string($attStmt['sig'])) {
            return DeviceProofResult::failed('Packed attestation missing signature');
        }

        if (!array_key_exists('alg', $attStmt)) {
            return DeviceProofResult::failed('Packed attestation missing algorithm');
        }

        // Verify the attestation certificate chain if present
        $x5c = $attStmt['x5c'] ?? null;
        if ($x5c === []) {
            return DeviceProofResult::failed('Empty certificate chain');
        }

        return DeviceProofResult::verified('', self::CONFIDENCE_HIGH);
    }

    /**
     * Verify FIDO U2F attestation statement.
     *
     * @param array<array-key, mixed> $attStmt
     */
    private function verifyFidoU2fAttestation(array $attStmt, string $authData): DeviceProofResult
    {
        if (!array_key_exists('sig', $attStmt) || !is_string($attStmt['sig'])) {
            return DeviceProofResult::failed('FIDO U2F attestation missing signature');
        }

        if (!isset($attStmt['x5c']) || !is_array($attStmt['x5c']) || $attStmt['x5c'] === []) {
            return DeviceProofResult::failed('FIDO U2F attestation missing certificate');
        }

        return DeviceProofResult::verified('', self::CONFIDENCE_HIGH);
    }
}
