<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            requireQualified: Coerce::strictBool($data['require_qualified'] ?? null, true),
            checkRevocation: Coerce::strictBool($data['check_revocation'] ?? null, true),
            trustedIssuers: Coerce::listOfString($data['trusted_issuers'] ?? null),
            validator: Coerce::string($data['validator'] ?? null, 'default'),
        );
    }
}
