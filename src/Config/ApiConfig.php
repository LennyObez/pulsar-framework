<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Api\Resource\ComplexityLimits;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * Typed configuration DTO for API tooling settings.
 *
 * Maps from the `api` key of `config/api.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ApiConfig
{
    public function __construct(
        public string $defaultFormat,
        public string $paginationType,
        public int $paginationDefaultSize,
        public int $paginationMaxSize,
        public string $versioningStrategy,
        public ComplexityLimits $complexityLimits,
        public bool $entitySerializationBanEnabled,
    ) {}

    /**
     * Build from the raw API config array.
     *
     * @param array{
     *     default_format?: string,
     *     pagination?: array{
     *         type?: string,
     *         default_size?: int,
     *         max_size?: int,
     *     },
     *     versioning_strategy?: string,
     *     complexity_limits?: array<string, mixed>,
     *     entity_serialization_ban?: bool|int|string,
     * } $data Raw array from config/api.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $pagination = $data['pagination'] ?? null;
        if (!is_array($pagination)) {
            $pagination = [];
        }
        $complexityLimitsData = $data['complexity_limits'] ?? null;
        if (!is_array($complexityLimitsData)) {
            $complexityLimitsData = [];
        }

        return new self(
            defaultFormat: Coerce::string($data['default_format'] ?? null, 'json'),
            paginationType: Coerce::string($pagination['type'] ?? null, 'offset'),
            paginationDefaultSize: Coerce::int($pagination['default_size'] ?? null, 25),
            paginationMaxSize: Coerce::int($pagination['max_size'] ?? null, 100),
            versioningStrategy: Coerce::string($data['versioning_strategy'] ?? null, 'url'),
            complexityLimits: ComplexityLimits::fromArray($complexityLimitsData),
            entitySerializationBanEnabled: (bool) ($data['entity_serialization_ban'] ?? true),
        );
    }
}
