<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function assert;
use function chr;
use function hash_hmac;
use function intdiv;
use function ord;
use function pack;
use function rawurlencode;
use function sprintf;
use function str_pad;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use function unpack;

/**
 * TOTP (Time-Based One-Time Password) generator per RFC 6238.
 */
#[Api(since: '1.0.0')]
final readonly class TotpGenerator
{
    private Randomizer $randomizer;

    public function __construct(
        private int $codeDigits = 6,
        private int $period = 30,
        private string $algorithm = 'sha1',
        ?Randomizer $randomizer = null,
    ) {
        assert($period > 0, 'TOTP period must be positive');
        assert($codeDigits >= 6 && $codeDigits <= 10, 'TOTP digits must be between 6 and 10');

        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Get the TOTP period in seconds.
     *
     * @return positive-int
     */
    public function period(): int
    {
        assert($this->period > 0);

        return $this->period;
    }

    /**
     * Get the number of digits in generated codes.
     *
     * @return int<6, 10>
     */
    public function digits(): int
    {
        assert($this->codeDigits >= 6 && $this->codeDigits <= 10);

        return $this->codeDigits;
    }

    /**
     * Generate a cryptographically random secret.
     *
     * @param positive-int $length Length in bytes (default 20 for SHA-1 compatibility)
     *
     * @throws RandomException
     */
    public function generateSecret(int $length = 20): string
    {
        return $this->randomizer->getBytes($length);
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
     * Decode a Base32-encoded string back to binary.
     *
     * @return string|null The decoded binary string, or null if the input contains invalid characters
     */
    public function decodeSecretBase32(string $input): ?string
    {
        return self::base32Decode($input);
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

    /**
     * RFC 4648 Base32 decoding.
     */
    private static function base32Decode(string $input): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper($input);
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $val = strpos($alphabet, $input[$i]);

            if ($val === false) {
                return null;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }
}
