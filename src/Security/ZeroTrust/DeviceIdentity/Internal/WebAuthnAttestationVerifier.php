<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity\Internal;

use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;
use Throwable;

use function base64_encode;
use function chr;
use function chunk_split;
use function hash;
use function hash_equals;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function openssl_pkey_get_public;
use function openssl_verify;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_ALGO_SHA512;

/**
 * Verifies WebAuthn attestation objects for device registration, with real
 * cryptographic verification of the attestation signature.
 *
 * For `packed` and `fido-u2f` the attestation statement signature is verified
 * over authenticatorData ‖ SHA-256(clientDataJSON): with an x5c chain, against
 * the leaf attestation certificate's public key (COSE alg → OpenSSL); for
 * `packed` self-attestation, against the credential public key parsed out of
 * the authenticator data. `fido-u2f` rebuilds the U2F signature base
 * (0x00 ‖ rpIdHash ‖ clientDataHash ‖ credentialId ‖ 0x04‖x‖y). A statement that
 * does not verify is rejected — an attacker can no longer submit arbitrary bytes
 * and be trusted. `none` carries no statement and stays low-confidence.
 *
 * Trust is limited to "the authenticator holds this private key" (Basic/Self):
 * the leaf certificate chain is not yet validated to a FIDO Metadata Service
 * (MDS) root, so provenance ("genuine vendor-X hardware") is not asserted.
 * Callers needing certified hardware must add an AAGUID allowlist.
 */
#[Internal]
final readonly class WebAuthnAttestationVerifier
{
    /** Confidence for attestation with no hardware proof (none). */
    private const float CONFIDENCE_NONE = 0.3;

    /** Confidence for a cryptographically-verified hardware attestation. */
    private const float CONFIDENCE_HIGH = 0.9;

    private const array SUPPORTED_FORMATS = ['none', 'packed', 'fido-u2f'];

    /**
     * Verify a WebAuthn attestation object.
     *
     * @param array<string, mixed> $attestation Decoded attestation: fmt, authData, clientDataJSON, attStmt
     * @param string $expectedChallenge The challenge issued during registration
     * @param string $expectedOrigin The expected relying-party origin
     */
    public function verify(array $attestation, string $expectedChallenge, string $expectedOrigin): DeviceProofResult
    {
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

        $authData = $attestation['authData'];
        $clientDataJSON = $attestation['clientDataJSON'];

        $challengeResult = $this->verifyChallenge($clientDataJSON, $expectedChallenge, $expectedOrigin);

        if (!$challengeResult->verified) {
            return $challengeResult;
        }

        // authData = rpIdHash(32) ‖ flags(1) ‖ signCount(4) ‖ [attestedCredentialData].
        if (strlen($authData) < 37) {
            return DeviceProofResult::failed('Authenticator data too short');
        }

        if ((ord($authData[32]) & 0x01) === 0) {
            return DeviceProofResult::failed('User Present flag not set');
        }

        $clientDataHash = hash('sha256', $clientDataJSON, true);

        if ($format === 'none') {
            return DeviceProofResult::verified('', self::CONFIDENCE_NONE);
        }

        if (!isset($attestation['attStmt']) || !is_array($attestation['attStmt'])) {
            return DeviceProofResult::failed('Missing attestation statement for format: ' . $format);
        }

        try {
            return $format === 'packed'
                ? $this->verifyPacked($attestation['attStmt'], $authData, $clientDataHash)
                : $this->verifyFidoU2f($attestation['attStmt'], $authData, $clientDataHash);
        } catch (Throwable $e) {
            return DeviceProofResult::failed('Attestation verification error: ' . $e->getMessage());
        }
    }

    private function verifyChallenge(string $clientDataJSON, string $expectedChallenge, string $expectedOrigin): DeviceProofResult
    {
        /** @var mixed $clientData */
        $clientData = json_decode($clientDataJSON, true);

        if (!is_array($clientData)) {
            return DeviceProofResult::failed('Invalid client data JSON');
        }

        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            return DeviceProofResult::failed('Invalid client data type: expected webauthn.create');
        }

        /** @var mixed $receivedChallenge */
        $receivedChallenge = $clientData['challenge'] ?? '';

        if (!is_string($receivedChallenge) || !hash_equals($expectedChallenge, $receivedChallenge)) {
            return DeviceProofResult::failed('Challenge mismatch');
        }

        /** @var mixed $receivedOrigin */
        $receivedOrigin = $clientData['origin'] ?? '';

        if (!is_string($receivedOrigin) || $receivedOrigin !== $expectedOrigin) {
            return DeviceProofResult::failed('Origin mismatch');
        }

        return DeviceProofResult::verified('');
    }

    /**
     * Verify a packed attestation signature over authData ‖ clientDataHash,
     * against the x5c leaf certificate (basic) or the credential key (self).
     *
     * @param array<array-key, mixed> $attStmt
     */
    private function verifyPacked(array $attStmt, string $authData, string $clientDataHash): DeviceProofResult
    {
        /** @var mixed $alg */
        $alg = $attStmt['alg'] ?? null;
        /** @var mixed $sig */
        $sig = $attStmt['sig'] ?? null;

        if (!is_int($alg) || !is_string($sig) || $sig === '') {
            return DeviceProofResult::failed('Packed attestation requires an integer alg and a signature');
        }

        $signedData = $authData . $clientDataHash;

        /** @var mixed $x5c */
        $x5c = $attStmt['x5c'] ?? null;

        if (is_array($x5c) && $x5c !== []) {
            $leaf = $x5c[0];
            if (!is_string($leaf) || $leaf === '') {
                return DeviceProofResult::failed('Packed attestation has an invalid certificate');
            }
            $publicKeyPem = $this->derToPem($leaf);
        } else {
            $publicKeyPem = $this->credentialPublicKeyPem($authData);
            if ($publicKeyPem === null) {
                return DeviceProofResult::failed('Cannot extract the credential public key for self-attestation');
            }
        }

        return $this->verifySignature($signedData, $sig, $publicKeyPem, $this->coseAlgToOpenSsl($alg));
    }

    /**
     * Verify a FIDO U2F attestation: rebuild the U2F signature base and verify
     * it (ES256) against the x5c leaf attestation certificate.
     *
     * @param array<array-key, mixed> $attStmt
     */
    private function verifyFidoU2f(array $attStmt, string $authData, string $clientDataHash): DeviceProofResult
    {
        /** @var mixed $sig */
        $sig = $attStmt['sig'] ?? null;
        /** @var mixed $x5c */
        $x5c = $attStmt['x5c'] ?? null;

        if (!is_string($sig) || $sig === '') {
            return DeviceProofResult::failed('FIDO U2F attestation missing signature');
        }

        if (!is_array($x5c) || $x5c === [] || !is_string($x5c[0]) || $x5c[0] === '') {
            return DeviceProofResult::failed('FIDO U2F attestation missing certificate');
        }

        $credential = $this->attestedCredential($authData);
        if ($credential === null) {
            return DeviceProofResult::failed('Cannot extract the U2F credential from authenticator data');
        }

        [$credentialId, $u2fPublicKey] = $credential;
        $rpIdHash = substr($authData, 0, 32);

        // U2F signature base: 0x00 ‖ rpIdHash ‖ clientDataHash ‖ credentialId ‖ 0x04‖x‖y
        $signedData = "\x00" . $rpIdHash . $clientDataHash . $credentialId . $u2fPublicKey;

        return $this->verifySignature($signedData, $sig, $this->derToPem($x5c[0]), OPENSSL_ALGO_SHA256);
    }

    private function verifySignature(string $signedData, string $signature, string $publicKeyPem, int $opensslAlg): DeviceProofResult
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            return DeviceProofResult::failed('Attestation public key is unreadable');
        }

        if (openssl_verify($signedData, $signature, $publicKey, $opensslAlg) !== 1) {
            return DeviceProofResult::failed('Attestation signature does not verify');
        }

        return DeviceProofResult::verified('', self::CONFIDENCE_HIGH);
    }

    /**
     * Parse attestedCredentialData for its credential id and the U2F-format
     * public key (0x04 ‖ x ‖ y), or null when the key is not EC2/P-256.
     *
     * @return array{0: string, 1: string}|null
     */
    private function attestedCredential(string $authData): ?array
    {
        if ((ord($authData[32]) & 0x40) === 0 || strlen($authData) < 55) {
            return null; // AT (attested credential data) flag not set
        }

        /** @var array{len: int} $credIdLen */
        $credIdLen = unpack('nlen', $authData, 53);
        $length = $credIdLen['len'];

        $coseKeyOffset = 55 + $length;
        if ($coseKeyOffset > strlen($authData)) {
            return null;
        }

        $credentialId = substr($authData, 55, $length);
        $coseKey = CborDecoder::decode(substr($authData, $coseKeyOffset));

        if (!is_array($coseKey)) {
            return null;
        }

        /** @var mixed $x */
        $x = $coseKey[-2] ?? null;
        /** @var mixed $y */
        $y = $coseKey[-3] ?? null;

        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }

        return [$credentialId, "\x04" . $x . $y];
    }

    private function credentialPublicKeyPem(string $authData): ?string
    {
        if ((ord($authData[32]) & 0x40) === 0 || strlen($authData) < 55) {
            return null;
        }

        /** @var array{len: int} $credIdLen */
        $credIdLen = unpack('nlen', $authData, 53);
        $coseKeyOffset = 55 + $credIdLen['len'];

        if ($coseKeyOffset > strlen($authData)) {
            return null;
        }

        $coseKey = CborDecoder::decode(substr($authData, $coseKeyOffset));

        if (!is_array($coseKey)) {
            return null;
        }

        /** @var mixed $kty */
        $kty = $coseKey[1] ?? 0;

        return match ($kty) {
            2 => $this->ec2KeyToPem($coseKey),
            3 => $this->rsaKeyToPem($coseKey),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $coseKey
     */
    private function ec2KeyToPem(array $coseKey): ?string
    {
        /** @var mixed $x */
        $x = $coseKey[-2] ?? null;
        /** @var mixed $y */
        $y = $coseKey[-3] ?? null;

        if (!is_string($x) || !is_string($y)) {
            return null;
        }

        $ecPoint = "\x04" . $x . $y;
        $oid = "\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $bitString = "\x03" . chr((strlen($ecPoint) + 1) & 0xFF) . "\x00" . $ecPoint;
        $sequence = "\x30" . $this->derLength(strlen($oid) + strlen($bitString)) . $oid . $bitString;

        return $this->pemPublicKey($sequence);
    }

    /**
     * @param array<array-key, mixed> $coseKey
     */
    private function rsaKeyToPem(array $coseKey): ?string
    {
        /** @var mixed $n */
        $n = $coseKey[-1] ?? null;
        /** @var mixed $e */
        $e = $coseKey[-2] ?? null;

        if (!is_string($n) || !is_string($e)) {
            return null;
        }

        $rsaKey = "\x30" . $this->derLength(strlen($this->derInteger($n)) + strlen($this->derInteger($e)))
            . $this->derInteger($n) . $this->derInteger($e);
        $oid = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $bitString = "\x03" . $this->derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $sequence = "\x30" . $this->derLength(strlen($oid) + strlen($bitString)) . $oid . $bitString;

        return $this->pemPublicKey($sequence);
    }

    private function derInteger(string $bytes): string
    {
        if ($bytes !== '' && ord($bytes[0]) >= 0x80) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length & 0xFF);
        }

        if ($length < 0x100) {
            return "\x81" . chr($length & 0xFF);
        }

        return "\x82" . pack('n', $length);
    }

    private function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64) . "-----END CERTIFICATE-----\n";
    }

    private function pemPublicKey(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----\n";
    }

    private function coseAlgToOpenSsl(int $alg): int
    {
        return match ($alg) {
            -7, -257, -37 => OPENSSL_ALGO_SHA256,   // ES256, RS256, PS256
            -35, -258, -38 => OPENSSL_ALGO_SHA384,   // ES384, RS384, PS384
            -36, -259, -39 => OPENSSL_ALGO_SHA512,   // ES512, RS512, PS512
            default => OPENSSL_ALGO_SHA256,
        };
    }
}
