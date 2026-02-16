<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_string;

#[Api(since: '1.0.0')]
final readonly class SocialSsoConfig
{
    /** @param array<string, ProviderConfig> $providers */
    public function __construct(
        public bool $enabled,
        public string $defaultProvider,
        public bool $requirePkce,
        public bool $requireNonce,
        public int $stateTtlSeconds,
        public RoutesConfig $routes,
        public array $providers,
    ) {}

    /** @param array<string, mixed> $data */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $providersRaw = (array) ($data['providers'] ?? []);
        $providers = [];
        foreach ($providersRaw as $name => $providerData) {
            /** @var array<string, mixed> $providerArray */
            $providerArray = (array) $providerData;
            $providers[(string) $name] = ProviderConfig::fromArray((string) $name, $providerArray);
        }

        /** @var array<string, mixed> $routesData */
        $routesData = (array) ($data['routes'] ?? []);

        /** @var string $defaultProvider */
        $defaultProvider = isset($data['default_provider']) && is_string($data['default_provider']) ? $data['default_provider'] : '';
        /** @var int $stateTtl */
        $stateTtl = isset($data['state_ttl_seconds']) && is_int($data['state_ttl_seconds']) ? $data['state_ttl_seconds'] : 300;

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            defaultProvider: $defaultProvider,
            requirePkce: (bool) ($data['require_pkce'] ?? true),
            requireNonce: (bool) ($data['require_nonce'] ?? true),
            stateTtlSeconds: $stateTtl,
            routes: RoutesConfig::fromArray($routesData),
            providers: $providers,
        );
    }
}
