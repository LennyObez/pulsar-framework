<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Token;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Contract\ScopeRepositoryInterface;

/**
 * In-memory scope repository for testing and development.
 */
#[Internal(reason: 'In-memory implementation for testing; not for production use')]
final class InMemoryScopeRepository implements ScopeRepositoryInterface
{
    /** @var array<string, Scope> Keyed by scope ID */
    private array $scopes = [];

    /**
     * Register a scope definition.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function add(Scope $scope): void
    {
        $this->scopes[$scope->id] = $scope;
    }

    public function findById(string $scopeId): ?Scope
    {
        return $this->scopes[$scopeId] ?? null;
    }

    public function resolveScopes(array $scopeIds, string $grantType, string $clientId): array
    {
        $resolved = [];

        foreach ($scopeIds as $scopeId) {
            $scope = $this->scopes[$scopeId] ?? null;
            if ($scope !== null) {
                $resolved[] = $scope;
            }
        }

        return $resolved;
    }
}
