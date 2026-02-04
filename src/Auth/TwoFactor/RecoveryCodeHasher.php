<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use function hash_equals;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use SensitiveParameter;
use SodiumException;

use function str_replace;
use function strtoupper;

/**
 * Hashes recovery codes using HMAC-BLAKE2b with a derived key.
 *
 * Canonicalizes input (uppercase, strip dashes/spaces) before hashing
 * for consistent matching regardless of user input formatting.
 */
#[Internal]
final readonly class RecoveryCodeHasher
{
    public function __construct(
        #[SensitiveParameter]
        private string $key,
    ) {}

    /**
     * Hash a single recovery code.
     *
     * @throws SodiumException
     */
    public function hash(#[SensitiveParameter] string $code): string
    {
        return Hmac::computeHex(self::canonicalize($code), $this->key);
    }

    /**
     * Hash a list of recovery codes.
     *
     * @param list<string> $codes
     * @return list<string>
     *
     * @throws SodiumException
     */
    public function hashAll(array $codes): array
    {
        $hashes = [];

        foreach ($codes as $code) {
            $hashes[] = $this->hash($code);
        }

        return $hashes;
    }

    /**
     * Verify a code against a list of hashed codes.
     *
     * @param list<string> $hashedCodes
     * @return int Index of matching hash, or -1 if no match
     *
     * @throws SodiumException
     */
    public function verify(#[SensitiveParameter] string $code, array $hashedCodes): int
    {
        $hash = $this->hash($code);

        foreach ($hashedCodes as $index => $hashedCode) {
            if (hash_equals($hashedCode, $hash)) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Canonicalize a recovery code: uppercase, strip dashes and spaces.
     */
    public static function canonicalize(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', $code));
    }
}
