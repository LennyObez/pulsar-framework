<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use Pulsar\Api\Api;

/**
 * In-memory token store for development and testing.
 *
 * Token mappings are lost when the process ends. Not suitable for production.
 * @api
 */
#[Api(since: '1.0.0')]
final class InMemoryTokenStore implements TokenStoreInterface
{
    /** @var array<string, array{encrypted: string, context: string}> */
    private array $tokens = [];

    public function store(string $token, string $encryptedValue, string $context): void
    {
        $this->tokens[$token] = ['encrypted' => $encryptedValue, 'context' => $context];
    }

    public function retrieve(string $token): ?string
    {
        return $this->tokens[$token]['encrypted'] ?? null;
    }

    public function exists(string $token): bool
    {
        return isset($this->tokens[$token]);
    }

    public function remove(string $token): void
    {
        unset($this->tokens[$token]);
    }
}
