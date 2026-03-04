<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Peppol Access Point configuration.
 *
 * @param string $senderScheme Peppol participant scheme (e.g., "0088" for GLN, "9925" for VAT BE)
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            accessPointUrl: is_string($data['access_point_url'] ?? null) ? $data['access_point_url'] : '',
            senderId: is_string($data['sender_id'] ?? null) ? $data['sender_id'] : '',
            senderScheme: is_string($data['sender_scheme'] ?? null) ? $data['sender_scheme'] : '0088',
            receiverLookupEndpoint: is_string($data['receiver_lookup_endpoint'] ?? null) ? $data['receiver_lookup_endpoint'] : null,
            signingKeyPath: is_string($data['signing_key_path'] ?? null) ? $data['signing_key_path'] : null,
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
