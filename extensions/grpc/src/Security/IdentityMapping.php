<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Security;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;

use function array_keys;
use function array_values;
use function count;

/**
 * Compiled, immutable mapping from certificate SANs to service identities.
 *
 * Built from configuration at boot time. Once constructed, the mapping is
 * frozen — no entries can be added or removed at runtime.
 */
#[Api(since: '1.0.0')]
final readonly class IdentityMapping
{
    /** @var array<string, ServiceIdentity> */
    private array $compiled;

    /**
     * @param array<string, IdentityMappingEntry> $entries SAN → config entry mapping
     */
    public function __construct(array $entries)
    {
        $compiled = [];

        foreach ($entries as $san => $entry) {
            $compiled[$san] = new ServiceIdentity(
                name: $entry->name,
                trustLevel: $entry->trustLevel,
                allowedMethods: $entry->allowedMethods,
            );
        }

        $this->compiled = $compiled;
    }

    /**
     * Look up a service identity by its certificate SAN.
     */
    public function lookup(string $san): ?ServiceIdentity
    {
        return $this->compiled[$san] ?? null;
    }

    /**
     * Whether the mapping contains an entry for the given SAN.
     */
    public function has(string $san): bool
    {
        return isset($this->compiled[$san]);
    }

    /**
     * Get all SANs in the compiled mapping.
     *
     * @return list<string>
     */
    public function sans(): array
    {
        return array_keys($this->compiled);
    }

    /**
     * Get all service identities in the compiled mapping.
     *
     * @return list<ServiceIdentity>
     */
    public function identities(): array
    {
        return array_values($this->compiled);
    }

    /**
     * Whether the mapping is empty (no entries configured).
     */
    public function isEmpty(): bool
    {
        return $this->compiled === [];
    }

    /**
     * Number of entries in the compiled mapping.
     */
    public function count(): int
    {
        return count($this->compiled);
    }
}
