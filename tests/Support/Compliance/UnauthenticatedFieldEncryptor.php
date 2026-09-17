<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use Override;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function intdiv;
use function str_repeat;
use function strlen;

/**
 * An encryptor that conceals and defends nothing else.
 *
 * WHY A DEPLOYMENT WOULD HAVE ONE. `EncryptorInterface` is `#[Api]` and the
 * composition root binds it by contract, so an application is free to bind its
 * own — a legacy cipher kept for a migration, an HSM shim, a wrapper someone
 * wrote to add a key id. Every one of those is opaque on the page and none of
 * them is necessarily authenticated. This is the shape of the mistake, built so
 * a test can assess a deployment that makes it.
 *
 * WHAT IT DOES: XOR against a repeating key, then base64. That is enough to pass
 * the two subjects most people would think of — the stored form does not carry
 * the value, and the value comes back byte for byte — and it fails the two that
 * matter and are easy to leave out:
 *
 *  - Nothing is authenticated. `decrypt()` returns bytes for ANY input, so
 *    whoever can write the row picks what the application reads back about a
 *    data subject.
 *  - Nothing is randomised. Two equal values seal to identical bytes, so holding
 *    the storage is enough to learn which data subjects share a value, without
 *    opening a single record.
 */
final readonly class UnauthenticatedFieldEncryptor implements EncryptorInterface
{
    /** Fixed, so that equal plaintexts produce equal ciphertexts. That is the defect. */
    private const string KEY = 'compliance-fixture-unauthenticated-key';

    #[Override]
    public function encrypt(
        #[SensitiveParameter]
        string $plaintext,
    ): string {
        return base64_encode(self::xorKey($plaintext));
    }

    #[Override]
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);

        // Not a refusal on integrity grounds: base64 that does not decode is not a
        // ciphertext at all. Anything that DOES decode is handed back, which is the
        // whole point of this class.
        return $raw === false ? '' : self::xorKey($raw);
    }

    #[Override]
    public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self
    {
        return $this;
    }

    /**
     * PHP's `^` on two strings is a byte-wise XOR truncated to the shorter
     * operand, so the key is repeated past the value's length and the result is
     * exactly as long as the value.
     */
    private static function xorKey(string $value): string
    {
        $length = strlen($value);

        if ($length === 0) {
            return '';
        }

        return $value ^ str_repeat(self::KEY, intdiv($length, strlen(self::KEY)) + 1);
    }
}
