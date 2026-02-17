<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Rest;

use Pulsar\Api\Api;
use Pulsar\Extension\Fhir\Resource\OperationOutcome;

use function array_key_exists;
use function count;
use function is_scalar;
use function is_string;

/**
 * FHIR-conformant REST controller implementing FHIR RESTful API interactions.
 *
 * Handles read, search, create, update, delete, and metadata (CapabilityStatement)
 * operations per the FHIR specification.
 *
 * @see https://www.hl7.org/fhir/http.html
 */
#[Api(since: '1.0.0')]
final readonly class FhirController
{
    public function __construct(
        private FhirRepositoryInterface $repository,
        private CapabilityStatementBuilder $capabilityStatement,
    ) {}

    /**
     * GET /fhir/metadata; Return the server's CapabilityStatement.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function metadata(): array
    {
        return [
            'status' => 200,
            'body' => $this->capabilityStatement->build(),
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * GET /fhir/{type}/{id}: Read a specific resource.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function read(string $resourceType, string $id): array
    {
        $resource = $this->repository->read($resourceType, $id);

        if ($resource === null) {
            return [
                'status' => 404,
                'body' => OperationOutcome::notFound($resourceType, $id)->toArray(),
                'headers' => $this->fhirHeaders(),
            ];
        }

        return [
            'status' => 200,
            'body' => $resource,
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * GET /fhir/{type}?params: Search for resources.
     *
     * @param array<string, string> $parameters Search parameters
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function search(string $resourceType, array $parameters = []): array
    {
        $results = $this->repository->search($resourceType, $parameters);

        $entries = [];
        foreach ($results as $resource) {
            $entry = ['resource' => $resource];
            if (array_key_exists('id', $resource) && is_scalar($resource['id'])) {
                $entry['fullUrl'] = "$resourceType/{$resource['id']}";
            }
            $entries[] = $entry;
        }

        $bundle = [
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => count($results),
            'entry' => $entries,
        ];

        return [
            'status' => 200,
            'body' => $bundle,
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * POST /fhir/{type}: Create a new resource.
     *
     * @param array<string, mixed> $resource The resource to create
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function create(string $resourceType, array $resource): array
    {
        $created = $this->repository->create($resourceType, $resource);

        return [
            'status' => 201,
            'body' => $created,
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * PUT /fhir/{type}/{id}: Update a resource.
     *
     * @param array<string, mixed> $resource The updated resource
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function update(string $resourceType, string $id, array $resource): array
    {
        $updated = $this->repository->update($resourceType, $id, $resource);

        return [
            'status' => 200,
            'body' => $updated,
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * DELETE /fhir/{type}/{id}: Delete a resource.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function delete(string $resourceType, string $id): array
    {
        $deleted = $this->repository->delete($resourceType, $id);

        if (!$deleted) {
            return [
                'status' => 404,
                'body' => OperationOutcome::notFound($resourceType, $id)->toArray(),
                'headers' => $this->fhirHeaders(),
            ];
        }

        return [
            'status' => 204,
            'body' => [],
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * POST /fhir: Process a batch/transaction bundle.
     *
     * @param array<string, mixed> $bundle The Bundle resource
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function batch(array $bundle): array
    {
        $bundleType = is_string($bundle['type'] ?? null) ? $bundle['type'] : '';
        if ($bundleType !== 'batch' && $bundleType !== 'transaction') {
            return [
                'status' => 400,
                'body' => OperationOutcome::error(
                    "Bundle type must be 'batch' or 'transaction', got '$bundleType'",
                    'value',
                )->toArray(),
                'headers' => $this->fhirHeaders(),
            ];
        }

        /** @var list<array<string, mixed>> $entries */
        $entries = $bundle['entry'] ?? [];

        $responseEntries = [];
        foreach ($entries as $entry) {
            /** @var array<string, mixed>|null $request */
            $request = $entry['request'] ?? null;
            if ($request === null) {
                $responseEntries[] = [
                    'response' => [
                        'status' => '400 Bad Request',
                        'outcome' => OperationOutcome::error('Missing request in bundle entry')->toArray(),
                    ],
                ];
                continue;
            }

            $method = is_string($request['method'] ?? null) ? $request['method'] : '';
            $url = is_string($request['url'] ?? null) ? $request['url'] : '';
            $parts = explode('/', $url, 2);
            $type = $parts[0];
            $id = $parts[1] ?? '';

            /** @var array<string, mixed> $entryResource */
            $entryResource = $entry['resource'] ?? [];

            $responseEntry = match ($method) {
                'GET' => $id !== ''
                    ? ['response' => ['status' => '200 OK', 'outcome' => $this->repository->read($type, $id)]]
                    : ['response' => ['status' => '200 OK', 'outcome' => $this->repository->search($type)]],
                'POST' => ['response' => ['status' => '201 Created', 'outcome' => $this->repository->create($type, $entryResource)]],
                'PUT' => ['response' => ['status' => '200 OK', 'outcome' => $this->repository->update($type, $id, $entryResource)]],
                'DELETE' => ['response' => ['status' => $this->repository->delete($type, $id) ? '204 No Content' : '404 Not Found']],
                default => ['response' => ['status' => '400 Bad Request', 'outcome' => OperationOutcome::error("Unsupported method: $method")->toArray()]],
            };

            $responseEntries[] = $responseEntry;
        }

        return [
            'status' => 200,
            'body' => [
                'resourceType' => 'Bundle',
                'type' => $bundleType === 'batch' ? 'batch-response' : 'transaction-response',
                'entry' => $responseEntries,
            ],
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fhirHeaders(): array
    {
        return [
            'Content-Type' => 'application/fhir+json; charset=utf-8',
        ];
    }
}
