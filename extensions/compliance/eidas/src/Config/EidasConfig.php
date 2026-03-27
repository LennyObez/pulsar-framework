<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * eIDAS extension configuration.
 *
 * EIDAS-DEFAULT (external audit): defaults below are dev/test only — `hmac`
 * for signature/seal, `local` for timestamp, `memory` for delivery.
 * These are NOT eIDAS-conformant providers. Production deployments must
 * wire qualified providers (QSeal/QES via a Qualified Trust Service
 * Provider, QTSA for timestamps, persistent delivery) and the
 * {@see \Pulsar\Deploy\Check\EidasProductionReadinessCheck} deploy gate
 * refuses these defaults in staging/production.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EidasConfig
{
    /** @var list<string> dev/test sentinel values refused in production by deploy check. */
    public const array DEV_TEST_SIGNATURE_SERVICES = ['hmac'];
    public const array DEV_TEST_SEAL_SERVICES = ['hmac'];
    public const array DEV_TEST_TIMESTAMP_SERVICES = ['local'];
    public const array DEV_TEST_DELIVERY_SERVICES = ['memory'];

    public function __construct(
        public string $signatureService = 'hmac',
        public string $sealService = 'hmac',
        public string $timestampService = 'local',
        public string $deliveryService = 'memory',
        public string $defaultSignatureFormat = 'jades',
        public string $tsaName = 'Pulsar Local TSA',
    ) {}

    /**
     * @param array{
     *     signature_service?: string,
     *     seal_service?: string,
     *     timestamp_service?: string,
     *     delivery_service?: string,
     *     default_signature_format?: string,
     *     tsa_name?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            signatureService: $data['signature_service'] ?? 'hmac',
            sealService: $data['seal_service'] ?? 'hmac',
            timestampService: $data['timestamp_service'] ?? 'local',
            deliveryService: $data['delivery_service'] ?? 'memory',
            defaultSignatureFormat: $data['default_signature_format'] ?? 'jades',
            tsaName: $data['tsa_name'] ?? 'Pulsar Local TSA',
        );
    }
}
