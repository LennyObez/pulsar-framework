<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Privacy;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;

/**
 * Retention policy for a specific signal type.
 *
 * Defines how long signal data (claims) from a given source are retained,
 * whether they should be pseudonymized before storage, and the legal basis
 * for retention (e.g., GDPR Article 6).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SignalRetentionPolicy
{
    /**
     * @param ClaimSource $source The signal source this policy applies to
     * @param int $retentionSeconds Maximum retention duration in seconds (0 = no retention)
     * @param bool $pseudonymize Whether to pseudonymize claim data before storage
     * @param string $legalBasis Legal basis for retaining this signal data
     */
    public function __construct(
        public ClaimSource $source,
        public int $retentionSeconds,
        public bool $pseudonymize = false,
        public string $legalBasis = '',
    ) {
        if ($this->retentionSeconds < 0) {
            throw new InvalidArgumentException('Signal retention must not be negative');
        }
    }

    /**
     * Build from raw configuration array.
     *
     * @param array{
     *     source?: string,
     *     retention_seconds?: int,
     *     pseudonymize?: bool|int|string,
     *     legal_basis?: string,
     * } $data Must contain 'source' key
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            source: ClaimSource::from($data['source'] ?? ''),
            retentionSeconds: $data['retention_seconds'] ?? 0,
            pseudonymize: (bool) ($data['pseudonymize'] ?? false),
            legalBasis: $data['legal_basis'] ?? '',
        );
    }
}
