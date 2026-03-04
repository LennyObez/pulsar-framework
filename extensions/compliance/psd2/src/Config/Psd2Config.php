<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;

/**
 * PSD2 extension configuration.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $scaData */
        $scaData = is_array($data['sca'] ?? null) ? $data['sca'] : [];

        /** @var array<string, mixed> $riskData */
        $riskData = is_array($data['risk'] ?? null) ? $data['risk'] : [];

        /** @var array<string, mixed> $certData */
        $certData = is_array($data['certificate'] ?? null) ? $data['certificate'] : [];

        return new self(
            sca: ScaConfig::fromArray($scaData),
            risk: RiskConfig::fromArray($riskData),
            certificate: CertificateConfig::fromArray($certData),
        );
    }
}
