<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Typed configuration DTO for Cross-Origin security headers (COOP, COEP, CORP).
 *
 * Maps from the `cross_origin` key within the `headers` section of `config/security.php`.
 */
#[Api(since: '1.0.0')]
final readonly class CrossOriginConfig
{
    public function __construct(
        public string $openerPolicy = 'same-origin',
        public string $embedderPolicy = '',
        public string $resourcePolicy = 'same-origin',
    ) {}

    /**
     * @param array<string, mixed> $data Raw `cross_origin` sub-array from config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawOpenerPolicy = $data['opener_policy'] ?? 'same-origin';
        $rawEmbedderPolicy = $data['embedder_policy'] ?? '';
        $rawResourcePolicy = $data['resource_policy'] ?? 'same-origin';

        return new self(
            openerPolicy: is_string($rawOpenerPolicy) ? $rawOpenerPolicy : 'same-origin',
            embedderPolicy: is_string($rawEmbedderPolicy) ? $rawEmbedderPolicy : '',
            resourcePolicy: is_string($rawResourcePolicy) ? $rawResourcePolicy : 'same-origin',
        );
    }
}
