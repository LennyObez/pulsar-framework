<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            signatureService: Coerce::string($data['signature_service'] ?? null, 'hmac'),
            sealService: Coerce::string($data['seal_service'] ?? null, 'hmac'),
            timestampService: Coerce::string($data['timestamp_service'] ?? null, 'local'),
            deliveryService: Coerce::string($data['delivery_service'] ?? null, 'memory'),
            defaultSignatureFormat: Coerce::string($data['default_signature_format'] ?? null, 'jades'),
            tsaName: Coerce::string($data['tsa_name'] ?? null, 'Pulsar Local TSA'),
        );
    }
}
