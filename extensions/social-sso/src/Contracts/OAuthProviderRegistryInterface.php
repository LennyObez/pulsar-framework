<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\SocialSso\Exception\SsoException;

/**
 * Registry for OAuth provider implementations.
 *
 * Providers are registered by name and retrieved for use during the SSO flow.
 */
#[Api(since: '1.0.0')]
interface OAuthProviderRegistryInterface
{
    /**
     * Register an OAuth provider.
     */
    public function register(OAuthProviderInterface $provider): void;

    /**
     * Retrieve a registered provider by name.
     *
     * @throws SsoException if the provider is not registered
     */
    public function get(string $name): OAuthProviderInterface;
}
