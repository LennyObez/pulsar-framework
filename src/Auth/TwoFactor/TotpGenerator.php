<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function hash_hmac;
use function intdiv;
use function ord;
use function pack;

use Random\RandomException;

use function random_bytes;
use function rawurlencode;
use function sprintf;
use function str_pad;
use function strlen;
use function substr;
use function unpack;

/**
 * TOTP (Time-Based One-Time Password) generator per RFC 6238.
 */
final readonly class TotpGenerator
{
    public function __construct(
        private int $codeDigits = 6,
        private int $period = 30,
        private string $algorithm = 'sha1',
    ) {}

    /**
     * Generate a cryptographically random secret.
     *
     * @param positive-int $length Length in bytes (default 20 for SHA-1 compatibility)
     *
     * @throws RandomException
     */
    public function generateSecret(int $length = 20): string
    {
        return random_bytes($length);
    }

    /**
     * Encode a binary secret as Base32 for QR code provisioning.
     */
    public function encodeSecretBase32(string $secret): string
    {
        return self::base32Encode($secret);
    }

    /**
     * Compute a TOTP code for the given secret and time.
     *
     * @param string $secret Raw binary secret
     * @param int|null $timestamp Unix timestamp (defaults to current time)
     */
    public function computeCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);

        return $this->generateHotp($secret, $counter);
    }

    /**
     * Generate a provisioning URI for QR code scanning.
     */
    public function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $base32Secret = $this->encodeSecretBase32($secret);

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $base32Secret,
            rawurlencode($issuer),
            strtoupper($this->algorithm),
            $this->codeDigits,
            $this->period,
        );
    }

    /**
     * HOTP generation per RFC 4226.
     */
    private function generateHotp(string $secret, int $counter): string
    {
        // Pack counter as 8-byte big-endian
        $counterBytes = pack('J', $counter);

        $hash = hash_hmac($this->algorithm, $counterBytes, $secret, true);

        // Dynamic truncation
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        /** @var array<int, int> $unpacked */
        $unpacked = unpack('N', substr($hash, $offset, 4));
        $code = ($unpacked[1] & 0x7FFFFFFF) % (10 ** $this->codeDigits);

        return str_pad((string) $code, $this->codeDigits, '0', STR_PAD_LEFT);
    }

    /**
     * RFC 4648 Base32 encoding (no padding).
     */
    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $result .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $result;
    }
}
