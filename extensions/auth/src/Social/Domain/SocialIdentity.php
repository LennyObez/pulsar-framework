<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Domain;

use Pulsar\Api\Api;

/**
 * Normalized social identity returned by an OAuth provider.
 *
 * Maps the provider-specific user info response into a
 * canonical structure for identity linking and account creation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SocialIdentity
{
    /**
     * @param string $provider       Provider identifier (e.g., "google", "github")
     * @param string $providerUserId Provider-scoped unique user identifier
     * @param ?string $email         User email address (not guaranteed by all providers)
     * @param ?string $name          Display name
     * @param ?string $avatarUrl     Profile image URL
     * @param array<string, mixed> $rawAttributes  Full unprocessed attributes from the provider
     */
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public ?string $email = null,
        public ?string $name = null,
        public ?string $avatarUrl = null,
        public array $rawAttributes = [],
    ) {}
}
