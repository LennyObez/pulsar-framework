<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Top-level admin panel configuration DTO.
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
     * @param array<string, mixed> $data Raw array from config/admin.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var bool $enabled */
        $enabled = $data['enabled'] ?? false;
        /** @var string $routePrefix */
        $routePrefix = $data['route_prefix'] ?? '/admin';
        /** @var array<string, mixed> $securityData */
        $securityData = $data['security'] ?? [];
        /** @var array<string, mixed> $paginationData */
        $paginationData = $data['pagination'] ?? [];
        /** @var array<string, mixed> $rateLimitData */
        $rateLimitData = $data['rate_limit'] ?? [];
        /** @var array<string, mixed> $storageData */
        $storageData = $data['storage'] ?? [];
        /** @var array<string, mixed> $schemaData */
        $schemaData = $data['schema'] ?? [];

        return new self(
            enabled: $enabled,
            routePrefix: $routePrefix,
            security: AdminSecurityConfig::fromArray($securityData),
            pagination: AdminPaginationConfig::fromArray($paginationData),
            rateLimit: AdminRateLimitConfig::fromArray($rateLimitData),
            storage: AdminStorageConfig::fromArray($storageData),
            schema: AdminSchemaConfig::fromArray($schemaData),
        );
    }
}
