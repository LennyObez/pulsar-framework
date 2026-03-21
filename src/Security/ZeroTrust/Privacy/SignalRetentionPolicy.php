<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Privacy;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;

use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data Must contain 'source' key
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $sourceRaw = is_string($data['source'] ?? null) ? $data['source'] : '';
        $source = ClaimSource::from($sourceRaw);

        return new self(
            source: $source,
            retentionSeconds: is_int($data['retention_seconds'] ?? null) ? $data['retention_seconds'] : 0,
            pseudonymize: (bool) ($data['pseudonymize'] ?? false),
            legalBasis: is_string($data['legal_basis'] ?? null) ? $data['legal_basis'] : '',
        );
    }
}
