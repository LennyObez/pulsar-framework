<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Peppol Access Point configuration.
 *
 * @param string $senderScheme Peppol participant scheme (e.g., "0088" for GLN, "9925" for VAT BE)
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PeppolConfig
{
    public function __construct(
        public bool $enabled,
        public string $accessPointUrl,
        public string $senderId,
        public string $senderScheme,
        public ?string $receiverLookupEndpoint = null,
        public ?string $signingKeyPath = null,
    ) {}

    /**
     * Build from a raw configuration array.
     *
     * @param array{
     *     enabled?: bool|int|string,
     *     access_point_url?: string,
     *     sender_id?: string,
     *     sender_scheme?: string,
     *     receiver_lookup_endpoint?: string|null,
     *     signing_key_path?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            accessPointUrl: $data['access_point_url'] ?? '',
            senderId: $data['sender_id'] ?? '',
            senderScheme: $data['sender_scheme'] ?? '0088',
            receiverLookupEndpoint: $data['receiver_lookup_endpoint'] ?? null,
            signingKeyPath: $data['signing_key_path'] ?? null,
        );
    }

    /**
     * Create a disabled configuration.
     */
    #[NoDiscard]
    public static function disabled(): self
    {
        return new self(
            enabled: false,
            accessPointUrl: '',
            senderId: '',
            senderScheme: '0088',
        );
    }
}
