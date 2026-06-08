<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Config;

use NoDiscard;
use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
final readonly class SocialSsoConfig
{
    /**
     * @param array<string, ProviderConfig> $providers
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public bool $enabled,
        public string $defaultProvider,
        public bool $requirePkce,
        public bool $requireNonce,
        public int $stateTtlSeconds,
        public RoutesConfig $routes,
        public array $providers,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     default_provider?: string,
     *     require_pkce?: bool|int|string,
     *     require_nonce?: bool|int|string,
     *     state_ttl_seconds?: int,
     *     routes?: array<string, mixed>,
     *     providers?: array<string, array<string, mixed>>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $providers = [];
        foreach ($data['providers'] ?? [] as $name => $providerData) {
            $providers[(string) $name] = ProviderConfig::fromArray((string) $name, $providerData);
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            defaultProvider: $data['default_provider'] ?? '',
            requirePkce: (bool) ($data['require_pkce'] ?? true),
            requireNonce: (bool) ($data['require_nonce'] ?? true),
            stateTtlSeconds: $data['state_ttl_seconds'] ?? 300,
            routes: RoutesConfig::fromArray($data['routes'] ?? []),
            providers: $providers,
        );
    }
}
