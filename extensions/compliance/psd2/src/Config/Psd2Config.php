<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $sub = static function (string $k) use ($data): array {
            /** @var mixed $value */
            $value = $data[$k] ?? null;

            /** @var array<string, mixed> */
            return is_array($value) ? $value : [];
        };

        return new self(
            sca: ScaConfig::fromArray($sub('sca')),
            risk: RiskConfig::fromArray($sub('risk')),
            certificate: CertificateConfig::fromArray($sub('certificate')),
        );
    }
}
