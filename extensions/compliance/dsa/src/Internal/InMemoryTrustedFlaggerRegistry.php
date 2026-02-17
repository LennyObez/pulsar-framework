<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Internal;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Dsa\TrustedFlagger\TrustedFlaggerRegistry;

use function array_key_exists;
use function array_values;
use function count;

/**
 * In-memory implementation of TrustedFlaggerRegistry for development and testing.
 *
 * Production deployments should replace this with a persistent
 * implementation backed by a database.
 */
#[Internal(reason: 'Default in-memory implementation; override with persistent storage')]
final class InMemoryTrustedFlaggerRegistry extends TrustedFlaggerRegistry
{
    /** @var array<string, array{id: string, name: string, designating_authority: string, expertise_domain: string}> */
    private array $flaggers = [];

    public function register(
        string $id,
        string $name,
        string $designatingAuthority,
        string $expertiseDomain,
    ): void {
        $this->flaggers[$id] = [
            'id' => $id,
            'name' => $name,
            'designating_authority' => $designatingAuthority,
            'expertise_domain' => $expertiseDomain,
        ];
    }

    public function revoke(string $id): void
    {
        unset($this->flaggers[$id]);
    }

    #[NoDiscard]
    public function isTrusted(string $id): bool
    {
        return array_key_exists($id, $this->flaggers);
    }

    /**
     * @return list<array{id: string, name: string, designating_authority: string, expertise_domain: string}>
     */
    #[NoDiscard]
    public function all(): array
    {
        return array_values($this->flaggers);
    }

    #[NoDiscard]
    public function count(): int
    {
        return count($this->flaggers);
    }
}
