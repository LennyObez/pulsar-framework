<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * PSD2 certificate validation configuration.
 * @api
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
     * @param array{
     *     require_qualified?: bool,
     *     check_revocation?: bool,
     *     trusted_issuers?: list<string>,
     *     validator?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            requireQualified: $data['require_qualified'] ?? true,
            checkRevocation: $data['check_revocation'] ?? true,
            trustedIssuers: $data['trusted_issuers'] ?? [],
            validator: $data['validator'] ?? 'default',
        );
    }
}
