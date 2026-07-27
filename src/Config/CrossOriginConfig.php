<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for Cross-Origin security headers (COOP, COEP, CORP).
 *
 * Maps from the `cross_origin` key within the `headers` section of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CrossOriginConfig implements ReportsUnknownKeys
{
    /** Keys read from the `headers.cross_origin` sub-array of config/security.php. */
    private const array KNOWN_KEYS = [
        'opener_policy', 'embedder_policy', 'resource_policy',
    ];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `cross_origin` array
     *     that this DTO does not read — an unread key here leaves a COOP/COEP/CORP
     *     isolation policy at its default rather than the configured value.
     */
    public function __construct(
        public string $openerPolicy = 'same-origin',
        public string $embedderPolicy = '',
        public string $resourcePolicy = 'same-origin',
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array{
     *     opener_policy?: string,
     *     embedder_policy?: string,
     *     resource_policy?: string,
     * } $data Raw `cross_origin` sub-array from config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            openerPolicy: $data['opener_policy'] ?? 'same-origin',
            embedderPolicy: $data['embedder_policy'] ?? '',
            resourcePolicy: $data['resource_policy'] ?? 'same-origin',
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
