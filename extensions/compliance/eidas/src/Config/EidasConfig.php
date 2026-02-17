<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * eIDAS extension configuration.
 */
#[Api(since: '1.0.0')]
final readonly class EidasConfig
{
    public function __construct(
        public string $signatureService = 'hmac',
        public string $sealService = 'hmac',
        public string $timestampService = 'local',
        public string $deliveryService = 'memory',
        public string $defaultSignatureFormat = 'jades',
        public string $tsaName = 'Pulsar Local TSA',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            signatureService: is_string($data['signature_service'] ?? null) ? $data['signature_service'] : 'hmac',
            sealService: is_string($data['seal_service'] ?? null) ? $data['seal_service'] : 'hmac',
            timestampService: is_string($data['timestamp_service'] ?? null) ? $data['timestamp_service'] : 'local',
            deliveryService: is_string($data['delivery_service'] ?? null) ? $data['delivery_service'] : 'memory',
            defaultSignatureFormat: is_string($data['default_signature_format'] ?? null) ? $data['default_signature_format'] : 'jades',
            tsaName: is_string($data['tsa_name'] ?? null) ? $data['tsa_name'] : 'Pulsar Local TSA',
        );
    }
}
