<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

use function hash;
use function pack;
use function strlen;

/**
 * A Privacy Pass TokenChallenge (RFC 9577 §2.1.1).
 *
 * Identifies the issuer and Origin a token must be bound to. Pulsar acts as the
 * Origin and verifies tokens statelessly, so it issues a fixed challenge with an
 * empty redemption_context: any token the client redeems against this challenge
 * carries challenge_digest = SHA-256 of {@see self::encode()} and is accepted
 * for the key's lifetime. A non-empty (32-byte) redemption_context binds a token
 * to a single redemption but requires the Origin to remember issued contexts;
 * that per-request, single-use mode is out of scope for the stateless verifier.
 *
 * struct {
 *   uint16_t token_type;
 *   opaque issuer_name<1..2^16-1>;
 *   opaque redemption_context<0..32>;
 *   opaque origin_info<0..2^16-1>;
 * } TokenChallenge;
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TokenChallenge
{
    public function __construct(
        public int $tokenType,
        public string $issuerName,
        public string $originInfo = '',
        public string $redemptionContext = '',
    ) {
        if ($tokenType < 0 || $tokenType > 0xFFFF) {
            throw new InvalidArgumentException('token_type must be a uint16.');
        }
        if ($issuerName === '' || strlen($issuerName) > 0xFFFF) {
            throw new InvalidArgumentException('issuer_name must be 1..65535 bytes.');
        }
        if (strlen($originInfo) > 0xFFFF) {
            throw new InvalidArgumentException('origin_info must be at most 65535 bytes.');
        }
        $contextLen = strlen($redemptionContext);
        if ($contextLen !== 0 && $contextLen !== 32) {
            throw new InvalidArgumentException('redemption_context must be empty or exactly 32 bytes.');
        }
    }

    /**
     * Serialise to the TokenChallenge wire format.
     */
    #[NoDiscard]
    public function encode(): string
    {
        return pack('n', $this->tokenType)
            . pack('n', strlen($this->issuerName)) . $this->issuerName
            . pack('C', strlen($this->redemptionContext)) . $this->redemptionContext
            . pack('n', strlen($this->originInfo)) . $this->originInfo;
    }

    /**
     * challenge_digest = SHA-256(TokenChallenge) (RFC 9577 §2.2.1).
     */
    #[NoDiscard]
    public function digest(): string
    {
        return hash('sha256', $this->encode(), true);
    }
}
