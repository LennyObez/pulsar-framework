<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Client;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Contract\ClientRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Exception\OAuth2Exception;

use function password_verify;

/**
 * In-memory client repository for testing and development.
 *
 * Client secrets are verified using password_verify() (bcrypt/argon2).
 */
#[Internal(reason: 'In-memory implementation for testing; not for production use')]
final class InMemoryClientRepository implements ClientRepositoryInterface
{
    /** @var array<string, OAuthClient> Keyed by client ID */
    private array $clients = [];

    public function findById(string $clientId): ?OAuthClient
    {
        $client = $this->clients[$clientId] ?? null;

        if ($client !== null && !$client->active) {
            return null;
        }

        return $client;
    }

    public function validateClient(string $clientId, ?string $clientSecret, string $grantType): bool
    {
        $client = $this->clients[$clientId] ?? null;

        if ($client === null || !$client->active) {
            return false;
        }

        if (!$client->hasGrantType($grantType)) {
            return false;
        }

        // Confidential clients must authenticate with a secret
        if ($client->confidential) {
            if ($clientSecret === null || $client->secretHash === null) {
                return false;
            }

            return password_verify($clientSecret, $client->secretHash);
        }

        // Public clients must not send a secret
        return $clientSecret === null;
    }

    public function register(OAuthClient $client): void
    {
        if (isset($this->clients[$client->id])) {
            throw OAuth2Exception::invalidRequest("Client '$client->id' already exists");
        }

        $this->clients[$client->id] = $client;
    }

    public function revoke(string $clientId): void
    {
        $existing = $this->clients[$clientId] ?? null;

        if ($existing !== null) {
            $this->clients[$clientId] = new OAuthClient(
                id: $existing->id,
                name: $existing->name,
                redirectUris: $existing->redirectUris,
                grantTypes: $existing->grantTypes,
                scopes: $existing->scopes,
                confidential: $existing->confidential,
                active: false,
                secretHash: $existing->secretHash,
                createdAt: $existing->createdAt,
            );
        }
    }
}
