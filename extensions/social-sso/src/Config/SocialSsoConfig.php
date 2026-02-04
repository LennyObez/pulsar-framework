<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
            $providers[(string) $name] = ProviderConfig::fromArray((string) $name, (array) $providerData);
        }

        $routesData = (array) ($data['routes'] ?? []);

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            defaultProvider: (string) ($data['default_provider'] ?? ''),
            requirePkce: (bool) ($data['require_pkce'] ?? true),
            requireNonce: (bool) ($data['require_nonce'] ?? true),
            stateTtlSeconds: (int) ($data['state_ttl_seconds'] ?? 300),
            routes: RoutesConfig::fromArray($routesData),
            providers: $providers,
        );
    }
}
