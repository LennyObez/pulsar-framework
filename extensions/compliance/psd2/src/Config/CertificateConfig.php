<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_string;

/**
 * PSD2 certificate validation configuration.
 */
#[Api(since: '1.0.0')]
final readonly class CertificateConfig
{
    /**
     * @param list<string> $trustedIssuers List of trusted CA distinguished names
     */
    public function __construct(
        public bool $requireQualified = true,
        public bool $checkRevocation = true,
        public array $trustedIssuers = [],
        public string $validator = 'default',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $trustedIssuers */
        $trustedIssuers = is_array($data['trusted_issuers'] ?? null) ? $data['trusted_issuers'] : [];

        return new self(
            requireQualified: is_bool($data['require_qualified'] ?? null) ? $data['require_qualified'] : true,
            checkRevocation: is_bool($data['check_revocation'] ?? null) ? $data['check_revocation'] : true,
            trustedIssuers: $trustedIssuers,
            validator: is_string($data['validator'] ?? null) ? $data['validator'] : 'default',
        );
    }
}
