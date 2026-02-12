<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function bin2hex;
use function ord;
use function sprintf;
use function str_replace;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Generates recovery codes in XXXX-XXXX-XXXX-XXXX format (64-bit entropy).
 *
 * Each code is 16 hex characters (8 bytes of randomness) with optional
 * checksum group for typo detection.
 */
#[Api(since: '1.0.0')]
final readonly class RecoveryCodeGenerator
{
    private Randomizer $randomizer;

    public function __construct(
        ?Randomizer $randomizer = null,
        private int $bytesPerCode = 8,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Generate a set of recovery codes.
     *
     * @param int $count Number of codes to generate
     * @return list<string> Recovery codes in XXXX-XXXX-XXXX-XXXX format
     *
     * @throws RandomException
     */
    public function generate(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $bytes = $this->randomizer->getBytes($this->bytesPerCode);
            $hex = strtoupper(bin2hex($bytes));

            $codes[] = match ($this->bytesPerCode) {
                4 => sprintf('%s-%s', substr($hex, 0, 4), substr($hex, 4, 4)),
                default => sprintf(
                    '%s-%s-%s-%s',
                    substr($hex, 0, 4),
                    substr($hex, 4, 4),
                    substr($hex, 8, 4),
                    substr($hex, 12, 4),
                ),
            };
        }

        return $codes;
    }

    /**
     * Compute a 2-hex-char checksum for a recovery code.
     *
     * XORs all bytes of the canonical (no dashes) hex representation to produce
     * a single byte used for typo detection.
     */
    public static function checksum(string $code): string
    {
        $canonical = self::canonicalize($code);
        $bytes = hex2bin($canonical);

        if ($bytes === false) {
            return '00';
        }

        $xor = 0;
        for ($i = 0; $i < strlen($bytes); $i++) {
            $xor ^= ord($bytes[$i]);
        }

        return strtoupper(sprintf('%02X', $xor));
    }

    /**
     * Canonicalize a recovery code: uppercase, strip dashes and spaces.
     */
    public static function canonicalize(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', $code));
    }
}
