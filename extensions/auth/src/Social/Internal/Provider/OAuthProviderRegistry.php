<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Internal\Provider;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\Auth\Social\Exception\SsoException;

/**
 * In-memory OAuth provider registry.
 *
 * Stores registered provider implementations keyed by their name and
 * retrieves them during the SSO flow. Throws on lookup of unregistered providers.
 */
#[Internal]
final class OAuthProviderRegistry implements OAuthProviderRegistryInterface
{
    /** @var array<string, OAuthProviderInterface> */
    private array $providers = [];

    #[Override]
    public function register(OAuthProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    #[Override]
    public function get(string $name): OAuthProviderInterface
    {
        return $this->providers[$name] ?? throw SsoException::providerNotFound();
    }
}
