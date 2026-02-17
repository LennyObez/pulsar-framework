<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Adapter;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\WebAuthn\Attestation\AttestationResult;
use Pulsar\Extension\Auth\WebAuthn\Attestation\AttestationTrustLevel;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Exception\WebAuthnException;

use function chr;
use function in_array;
use function ord;
use function strlen;

/**
 * Attestation statement verifier supporting 'none' and 'packed' formats.
 *
 * Validates attestation statements from WebAuthn registration ceremonies
 * according to the configured format policy.
 */
#[Internal(reason: 'WebAuthn adapter implementation')]
final readonly class AttestationVerifier implements AttestationVerifierInterface
{
    /** @var list<string> */
    private array $formats;

    /**
     * @param list<string> $allowedFormats
     */
    public function __construct(array $allowedFormats = ['none', 'packed'])
    {
        $this->formats = $allowedFormats;
    }

    public function verify(
        string $format,
        string $attestationObject,
        string $clientDataJson,
    ): AttestationResult {
        if (!$this->isFormatAllowed($format)) {
            throw WebAuthnException::disallowedFormat($format);
        }

        /** @var array<string|int, mixed> $decoded */
        $decoded = CborDecoder::decode($attestationObject);
        /** @var string $authData */
        $authData = $decoded['authData'] ?? '';
        $aaguid = $this->extractAaguid($authData);

        return match ($format) {
            'none' => $this->verifyNone($decoded, $aaguid),
            'packed' => $this->verifyPacked($decoded, $clientDataJson, $aaguid),
            default => throw WebAuthnException::invalidAttestation("Unsupported format: $format"),
        };
    }

    public function isFormatAllowed(string $format): bool
    {
        return in_array($format, $this->formats, true);
    }

    public function allowedFormats(): array
    {
        return $this->formats;
    }

    /**
     * Verify 'none' attestation: no attestation statement, unconditionally trusted.
     *
     * @param array<string|int, mixed> $decoded
     */
    private function verifyNone(array $decoded, string $aaguid): AttestationResult
    {
        /** @var array<string|int, mixed> $attStmt */
        $attStmt = $decoded['attStmt'] ?? [];

        if ($attStmt !== []) {
            throw WebAuthnException::invalidAttestation('None attestation must have empty attStmt');
        }

        return new AttestationResult(
            verified: true,
            format: 'none',
            trustLevel: AttestationTrustLevel::None,
            aaguid: $aaguid,
        );
    }

    /**
     * Verify 'packed' attestation: supports self-attestation and basic attestation.
     *
     * @param array<string|int, mixed> $decoded
     */
    private function verifyPacked(array $decoded, string $clientDataJson, string $aaguid): AttestationResult
    {
        /** @var array<string|int, mixed> $attStmt */
        $attStmt = $decoded['attStmt'] ?? [];
        /** @var string $authData */
        $authData = $decoded['authData'] ?? '';

        /** @var int|null $algValue */
        $algValue = $attStmt['alg'] ?? null;
        /** @var string|null $sig */
        $sig = $attStmt['sig'] ?? null;

        if ($algValue === null || $sig === null) {
            throw WebAuthnException::invalidAttestation('Packed attestation requires alg and sig');
        }

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authData . $clientDataHash;

        /** @var list<string>|null $x5c */
        $x5c = $attStmt['x5c'] ?? null;

        if ($x5c !== null && $x5c !== []) {
            return $this->verifyPackedFull($x5c, $sig, $signedData, $algValue, $aaguid);
        }

        return $this->verifyPackedSelf($authData, $sig, $signedData, $algValue, $aaguid);
    }

    /**
     * Full (basic) packed attestation with x5c certificate chain.
     *
     * @param list<string> $x5c
     */
    private function verifyPackedFull(
        array $x5c,
        string $sig,
        string $signedData,
        int $alg,
        string $aaguid,
    ): AttestationResult {
        $certDer = $x5c[0];
        $certPem = $this->derToPem($certDer);

        $publicKey = openssl_pkey_get_public($certPem);

        if ($publicKey === false) {
            throw WebAuthnException::invalidAttestation('Cannot extract public key from attestation certificate');
        }

        $opensslAlg = $this->coseAlgToOpenSsl($alg);
        $valid = openssl_verify($signedData, $sig, $publicKey, $opensslAlg);

        if ($valid !== 1) {
            throw WebAuthnException::invalidAttestation('Packed attestation signature verification failed');
        }

        return new AttestationResult(
            verified: true,
            format: 'packed',
            trustLevel: AttestationTrustLevel::Basic,
            aaguid: $aaguid,
        );
    }

    /**
     * Self-attestation: signature verified with the credential public key from authData.
     */
    private function verifyPackedSelf(
        string $authData,
        string $sig,
        string $signedData,
        int $alg,
        string $aaguid,
    ): AttestationResult {
        $publicKeyPem = $this->extractPublicKeyFromAuthData($authData);

        if ($publicKeyPem === null) {
            throw WebAuthnException::invalidAttestation('Cannot extract credential public key from authenticator data');
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            throw WebAuthnException::invalidAttestation('Invalid credential public key for self-attestation');
        }

        $opensslAlg = $this->coseAlgToOpenSsl($alg);
        $valid = openssl_verify($signedData, $sig, $publicKey, $opensslAlg);

        if ($valid !== 1) {
            throw WebAuthnException::invalidAttestation('Packed self-attestation signature verification failed');
        }

        return new AttestationResult(
            verified: true,
            format: 'packed',
            trustLevel: AttestationTrustLevel::Self,
            aaguid: $aaguid,
        );
    }

    /**
     * Extract the credential public key from authenticator data.
     *
     * AuthData layout: rpIdHash(32) + flags(1) + counter(4) + aaguid(16) + credIdLen(2) + credId(L) + coseKey(...)
     */
    private function extractPublicKeyFromAuthData(string $authData): ?string
    {
        // Minimum: 37 bytes (rpIdHash + flags + counter) + attestedCredentialData
        if (strlen($authData) < 55) {
            return null;
        }

        $flags = ord($authData[32]);
        $hasAttestedData = ($flags & 0x40) !== 0;

        if (!$hasAttestedData) {
            return null;
        }

        // Skip rpIdHash(32) + flags(1) + counter(4) + aaguid(16) = offset 53
        /** @var array{len: int} $credIdLenData */
        $credIdLenData = unpack('nlen', $authData, 53);
        $credIdLen = $credIdLenData['len'];

        $coseKeyOffset = 55 + $credIdLen;

        if ($coseKeyOffset >= strlen($authData)) {
            return null;
        }

        $coseKeyBytes = substr($authData, $coseKeyOffset);

        return $this->coseKeyToPem($coseKeyBytes);
    }

    /**
     * Convert a COSE_Key to PEM format.
     *
     * Supports EC2 (kty=2, ES256/P-256) and RSA (kty=3).
     */
    private function coseKeyToPem(string $coseKeyBytes): ?string
    {
        /** @var array<int, mixed> $coseKey */
        $coseKey = CborDecoder::decode($coseKeyBytes);

        /** @var int $kty */
        $kty = $coseKey[1] ?? 0; // kty

        return match ($kty) {
            2 => $this->ec2KeyToPem($coseKey),
            3 => $this->rsaKeyToPem($coseKey),
            default => null,
        };
    }

    /**
     * Convert EC2 COSE key (P-256) to PEM.
     *
     * @param array<int, mixed> $coseKey
     */
    private function ec2KeyToPem(array $coseKey): ?string
    {
        /** @var string|null $x */
        $x = $coseKey[-2] ?? null;
        /** @var string|null $y */
        $y = $coseKey[-3] ?? null;

        if ($x === null || $y === null) {
            return null;
        }

        // Uncompressed EC point: 0x04 || x || y
        $ecPoint = "\x04" . $x . $y;

        // DER-encode as SubjectPublicKeyInfo for P-256
        $oid = "\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $bitString = "\x03" . chr((strlen($ecPoint) + 1) & 0xFF) . "\x00" . $ecPoint;
        $sequence = "\x30" . $this->derLength(strlen($oid) + strlen($bitString)) . $oid . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($sequence), 64) . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Convert RSA COSE key to PEM.
     *
     * @param array<int, mixed> $coseKey
     */
    private function rsaKeyToPem(array $coseKey): ?string
    {
        /** @var string|null $n */
        $n = $coseKey[-1] ?? null; // modulus
        /** @var string|null $e */
        $e = $coseKey[-2] ?? null; // exponent

        if ($n === null || $e === null) {
            return null;
        }

        $nInt = $this->derInteger($n);
        $eInt = $this->derInteger($e);
        $rsaPublicKey = "\x30" . $this->derLength(strlen($nInt) + strlen($eInt)) . $nInt . $eInt;

        $oid = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $bitString = "\x03" . $this->derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $sequence = "\x30" . $this->derLength(strlen($oid) + strlen($bitString)) . $oid . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($sequence), 64) . "-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $bytes): string
    {
        // Ensure unsigned: add leading zero if high bit set
        if (ord($bytes[0]) >= 0x80) {
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

    /**
     * Convert DER-encoded certificate to PEM.
     */
    private function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64)
            . "-----END CERTIFICATE-----\n";
    }

    /**
     * Extract AAGUID from authenticator data (bytes 37-52).
     */
    private function extractAaguid(string $authData): string
    {
        if (strlen($authData) < 53) {
            return str_repeat('0', 32);
        }

        $flags = ord($authData[32]);
        $hasAttestedData = ($flags & 0x40) !== 0;

        if (!$hasAttestedData) {
            return str_repeat('0', 32);
        }

        $aaguidBytes = substr($authData, 37, 16);

        return bin2hex($aaguidBytes);
    }

    /**
     * Map COSE algorithm identifier to OpenSSL algorithm constant.
     */
    private function coseAlgToOpenSsl(int $alg): int
    {
        return match ($alg) {
            -7, -257, -37 => OPENSSL_ALGO_SHA256,   // ES256, RS256, PS256
            -35, -258, -38 => OPENSSL_ALGO_SHA384,   // ES384, RS384, PS384
            -36, -259, -39 => OPENSSL_ALGO_SHA512,   // ES512, RS512, PS512
            default => throw WebAuthnException::invalidAttestation("Unsupported COSE algorithm: $alg"),
        };
    }

    /**
     * Convert a COSE_Key from raw bytes to PEM format (public entry point for ceremonies).
     */
    public static function coseKeyBytesToPem(string $coseKeyBytes): ?string
    {
        $verifier = new self();
        return $verifier->coseKeyToPem($coseKeyBytes);
    }

    /**
     * Extract the AAGUID from authenticator data (public entry point for ceremonies).
     */
    public static function aaguidFromAuthData(string $authData): string
    {
        $verifier = new self();
        return $verifier->extractAaguid($authData);
    }
}
