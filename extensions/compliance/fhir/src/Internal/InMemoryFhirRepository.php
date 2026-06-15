<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Fhir\Rest\FhirRepositoryInterface;

use function array_key_exists;
use function bin2hex;
use function is_string;
use function random_bytes;
use function str_starts_with;

/**
 * In-memory FHIR resource repository for testing and development.
 *
 * Resources are stored as associative arrays keyed by "{type}/{id}".
 * Search uses simple string matching on resource field values.
 */
#[Internal(reason: 'In-memory implementation for testing; use FhirRepositoryInterface')]
final class InMemoryFhirRepository implements FhirRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $resources = [];

    private int $versionCounter = 0;

    #[Override]
    public function read(string $resourceType, string $id): ?array
    {
        return $this->resources["$resourceType/$id"] ?? null;
    }

    #[Override]
    public function search(string $resourceType, array $parameters = []): array
    {
        $results = [];

        foreach ($this->resources as $key => $resource) {
            if (!str_starts_with($key, "$resourceType/")) {
                continue;
            }

            if (FhirSearchMatcher::matches($resource, $parameters)) {
                $results[] = $resource;
            }
        }

        return $results;
    }

    #[Override]
    public function create(string $resourceType, array $resource): array
    {
        /** @var mixed $rawId */
        $rawId = $resource['id'] ?? null;
        $id = is_string($rawId) ? $rawId : bin2hex(random_bytes(8));
        $resource['id'] = $id;
        $resource['resourceType'] = $resourceType;
        $resource['meta'] = $this->buildMeta();

        $this->resources["$resourceType/$id"] = $resource;

        return $resource;
    }

    #[Override]
    public function update(string $resourceType, string $id, array $resource): array
    {
        $resource['id'] = $id;
        $resource['resourceType'] = $resourceType;
        $resource['meta'] = $this->buildMeta();

        $this->resources["$resourceType/$id"] = $resource;

        return $resource;
    }

    #[Override]
    public function delete(string $resourceType, string $id): bool
    {
        $key = "$resourceType/$id";

        if (!array_key_exists($key, $this->resources)) {
            return false;
        }

        unset($this->resources[$key]);

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function buildMeta(): array
    {
        ++$this->versionCounter;

        return [
            'versionId' => (string) $this->versionCounter,
            'lastUpdated' => new DateTimeImmutable()->format('Y-m-d\TH:i:s.vP'),
        ];
    }
}
