<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Rest;

use Pulsar\Api\Api;

/**
 * Contract for FHIR resource persistence.
 *
 * Implementations may store resources in a database, in-memory, or proxy
 * to an upstream FHIR server. The repository operates on raw FHIR arrays
 * to stay resource-type agnostic.
 * @api
 */
#[Api(since: '1.0.0')]
interface FhirRepositoryInterface
{
    /**
     * Read a resource by type and ID.
     *
     * @return array<string, mixed>|null The resource as a FHIR-conformant array, or null if not found
     */
    public function read(string $resourceType, string $id): ?array;

    /**
     * Search for resources matching the given parameters.
     *
     * @param array<string, string> $parameters FHIR search parameters (_id, subject, etc.)
     *
     * @return list<array<string, mixed>> Matching resources as FHIR arrays
     */
    public function search(string $resourceType, array $parameters = []): array;

    /**
     * Create a new resource. Returns the resource with assigned id and meta.
     *
     * @param array<string, mixed> $resource The FHIR resource to create
     *
     * @return array<string, mixed> The created resource with server-assigned fields
     */
    public function create(string $resourceType, array $resource): array;

    /**
     * Update an existing resource (or create with the given ID if it does not exist).
     *
     * @param array<string, mixed> $resource The full resource (must include id)
     *
     * @return array<string, mixed> The updated resource
     */
    public function update(string $resourceType, string $id, array $resource): array;

    /**
     * Delete a resource by type and ID.
     *
     * @return bool True if the resource was deleted, false if it did not exist
     */
    public function delete(string $resourceType, string $id): bool;
}
