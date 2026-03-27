<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Top-level admin panel configuration DTO.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdminConfig
{
    public function __construct(
        public bool $enabled,
        public string $routePrefix,
        public AdminSecurityConfig $security,
        public AdminPaginationConfig $pagination,
        public AdminRateLimitConfig $rateLimit,
        public AdminStorageConfig $storage,
        public AdminSchemaConfig $schema,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     route_prefix?: string,
     *     security?: array<string, mixed>,
     *     pagination?: array<string, mixed>,
     *     rate_limit?: array<string, mixed>,
     *     storage?: array<string, mixed>,
     *     schema?: array<string, mixed>,
     * } $data Raw array from config/admin.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? false,
            routePrefix: $data['route_prefix'] ?? '/admin',
            security: AdminSecurityConfig::fromArray($data['security'] ?? []),
            pagination: AdminPaginationConfig::fromArray($data['pagination'] ?? []),
            rateLimit: AdminRateLimitConfig::fromArray($data['rate_limit'] ?? []),
            storage: AdminStorageConfig::fromArray($data['storage'] ?? []),
            schema: AdminSchemaConfig::fromArray($data['schema'] ?? []),
        );
    }
}
