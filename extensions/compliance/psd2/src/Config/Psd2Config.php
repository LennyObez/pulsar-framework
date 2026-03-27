<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * PSD2 extension configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Psd2Config
{
    public function __construct(
        public ScaConfig $sca,
        public RiskConfig $risk,
        public CertificateConfig $certificate,
    ) {}

    /**
     * @param array{
     *     sca?: array<string, mixed>,
     *     risk?: array<string, mixed>,
     *     certificate?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            sca: ScaConfig::fromArray($data['sca'] ?? []),
            risk: RiskConfig::fromArray($data['risk'] ?? []),
            certificate: CertificateConfig::fromArray($data['certificate'] ?? []),
        );
    }
}
