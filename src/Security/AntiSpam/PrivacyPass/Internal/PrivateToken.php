<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass\Internal;

use InvalidArgumentException;
use Pulsar\Api\Internal;

use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * A redeemed Privacy Pass token (RFC 9577 §2.2.1) for token type 0x0002.
 *
 * struct {
 *   uint16_t token_type;
 *   uint8_t nonce[32];
 *   uint8_t challenge_digest[32];
 *   uint8_t token_key_id[Nid];   // Nid = 32 for type 0x0002
 *   uint8_t authenticator[Nk];   // Nk  = 256 for type 0x0002
 * } Token;
 */
#[Internal]
final readonly class PrivateToken
{
    public const int TYPE_BLIND_RSA = 0x0002;
    public const int NONCE_LENGTH = 32;
    public const int DIGEST_LENGTH = 32;
    public const int KEY_ID_LENGTH = 32;       // Nid
    public const int AUTHENTICATOR_LENGTH = 256; // Nk (RSA-2048)

    private const int TOTAL_LENGTH = 2 + self::NONCE_LENGTH + self::DIGEST_LENGTH
        + self::KEY_ID_LENGTH + self::AUTHENTICATOR_LENGTH;

    public function __construct(
        public int $tokenType,
        public string $nonce,
        public string $challengeDigest,
        public string $tokenKeyId,
        public string $authenticator,
    ) {}

    /**
     * Parse a raw type-0x0002 token, validating its fixed length.
     *
     * @throws InvalidArgumentException on length/type mismatch
     */
    public static function parse(string $raw): self
    {
        if (strlen($raw) !== self::TOTAL_LENGTH) {
            throw new InvalidArgumentException('Token has an unexpected length for type 0x0002.');
        }

        /** @var array{1: int} $typeUnpack */
        $typeUnpack = unpack('n', substr($raw, 0, 2));
        $tokenType = $typeUnpack[1];

        if ($tokenType !== self::TYPE_BLIND_RSA) {
            throw new InvalidArgumentException('Unsupported token_type; only 0x0002 is supported.');
        }

        $offset = 2;
        $nonce = substr($raw, $offset, self::NONCE_LENGTH);
        $offset += self::NONCE_LENGTH;
        $challengeDigest = substr($raw, $offset, self::DIGEST_LENGTH);
        $offset += self::DIGEST_LENGTH;
        $tokenKeyId = substr($raw, $offset, self::KEY_ID_LENGTH);
        $offset += self::KEY_ID_LENGTH;
        $authenticator = substr($raw, $offset, self::AUTHENTICATOR_LENGTH);

        return new self($tokenType, $nonce, $challengeDigest, $tokenKeyId, $authenticator);
    }

    /**
     * token_authenticator_input = token_type ‖ nonce ‖ challenge_digest ‖ token_key_id
     * (RFC 9578 §6.4) — the message the RSASSA-PSS authenticator signs.
     */
    public function authenticatorInput(): string
    {
        return pack('n', $this->tokenType) . $this->nonce . $this->challengeDigest . $this->tokenKeyId;
    }
}
