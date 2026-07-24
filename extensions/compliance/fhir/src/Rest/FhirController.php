<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Rest;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Fhir\Resource\OperationOutcome;
use Pulsar\Extension\Fhir\Smart\SmartScopeEnforcer;

use function array_key_exists;
use function count;
use function explode;
use function is_array;
use function is_scalar;
use function is_string;
use function json_decode;
use function trim;

/**
 * FHIR-conformant REST controller implementing FHIR RESTful API interactions.
 *
 * Every PHI interaction (read, search, create, update, delete, and each batch
 * entry) is gated by SMART on FHIR scope enforcement BEFORE the repository is
 * touched: the caller must present a validated SMART access token whose granted
 * scopes allow the requested resource type and permission, or the request is
 * refused with an OperationOutcome (401 when no scopes are present, 403 when
 * they are insufficient). The gate is fail-closed — absent scopes deny. Only
 * the CapabilityStatement (`/fhir/metadata`) is public, per the FHIR spec.
 *
 * Granted scopes are read from the `smart_scopes` request attribute, a
 * space-delimited SMART scope string that the deployment's OAuth2/SMART
 * resource-server layer MUST populate from the validated access token.
 *
 * @see https://www.hl7.org/fhir/http.html
 * @see https://build.fhir.org/ig/HL7/smart-app-launch/scopes-and-launch-context.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FhirController
{
    public function __construct(
        private FhirRepositoryInterface $repository,
        private CapabilityStatementBuilder $capabilityStatement,
        private SmartScopeEnforcer $scopeEnforcer,
    ) {}

    /**
     * GET /fhir/metadata; Return the server's CapabilityStatement (public).
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
    public function read(ServerRequestInterface $request): array
    {
        $resourceType = $this->routeParam($request, 'type');
        $id = $this->routeParam($request, 'id');

        $denied = $this->authorize($request, $resourceType, 'read');
        if ($denied !== null) {
            return $denied;
        }

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
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function search(ServerRequestInterface $request): array
    {
        $resourceType = $this->routeParam($request, 'type');

        $denied = $this->authorize($request, $resourceType, 'read');
        if ($denied !== null) {
            return $denied;
        }

        $parameters = [];

        /** @var mixed $value */
        foreach ($request->getQueryParams() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $parameters[$key] = $value;
            }
        }

        $results = $this->repository->search($resourceType, $parameters);

        $entries = [];
        foreach ($results as $resource) {
            $entry = ['resource' => $resource];
            if (array_key_exists('id', $resource) && is_scalar($resource['id'])) {
                $entry['fullUrl'] = "$resourceType/{$resource['id']}";
            }
            $entries[] = $entry;
        }

        return [
            'status' => 200,
            'body' => [
                'resourceType' => 'Bundle',
                'type' => 'searchset',
                'total' => count($results),
                'entry' => $entries,
            ],
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * POST /fhir/{type}: Create a new resource.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function create(ServerRequestInterface $request): array
    {
        $resourceType = $this->routeParam($request, 'type');

        $denied = $this->authorize($request, $resourceType, 'write');
        if ($denied !== null) {
            return $denied;
        }

        $created = $this->repository->create($resourceType, $this->jsonBody($request));

        return [
            'status' => 201,
            'body' => $created,
            'headers' => $this->fhirHeaders(),
        ];
    }

    /**
     * PUT /fhir/{type}/{id}: Update a resource.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function update(ServerRequestInterface $request): array
    {
        $resourceType = $this->routeParam($request, 'type');
        $id = $this->routeParam($request, 'id');

        $denied = $this->authorize($request, $resourceType, 'write');
        if ($denied !== null) {
            return $denied;
        }

        $updated = $this->repository->update($resourceType, $id, $this->jsonBody($request));

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
    public function delete(ServerRequestInterface $request): array
    {
        $resourceType = $this->routeParam($request, 'type');
        $id = $this->routeParam($request, 'id');

        $denied = $this->authorize($request, $resourceType, 'write');
        if ($denied !== null) {
            return $denied;
        }

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
     * POST /fhir: Process a batch/transaction bundle. Each entry is scope-gated
     * independently; a denied entry becomes a 403 response entry and its
     * repository operation is never executed.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function batch(ServerRequestInterface $request): array
    {
        // A batch touches PHI, so it requires an authenticated SMART context up
        // front; per-entry scope is then enforced below.
        if ($this->scopeString($request) === '') {
            return $this->securityOutcome(401, 'login', 'Authentication required for a FHIR batch/transaction');
        }

        $bundle = $this->jsonBody($request);

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
            /** @var array<string, mixed>|null $entryRequest */
            $entryRequest = $entry['request'] ?? null;
            if ($entryRequest === null) {
                $responseEntries[] = [
                    'response' => [
                        'status' => '400 Bad Request',
                        'outcome' => OperationOutcome::error('Missing request in bundle entry')->toArray(),
                    ],
                ];
                continue;
            }

            /** @var mixed $rawMethod */
            $rawMethod = $entryRequest['method'] ?? null;
            /** @var mixed $rawUrl */
            $rawUrl = $entryRequest['url'] ?? null;
            $method = is_string($rawMethod) ? $rawMethod : '';
            $url = is_string($rawUrl) ? $rawUrl : '';
            $parts = explode('/', $url, 2);
            $type = $parts[0];
            $id = $parts[1] ?? '';

            $permission = $method === 'GET' ? 'read' : 'write';
            if (!$this->scopeEnforcer->checkAccess($this->scopeString($request), $type, $permission)) {
                $responseEntries[] = [
                    'response' => [
                        'status' => '403 Forbidden',
                        'outcome' => OperationOutcome::error(
                            "Access denied: no SMART scope grants $permission on $type",
                            'forbidden',
                        )->toArray(),
                    ],
                ];
                continue;
            }

            /** @var array<string, mixed> $entryResource */
            $entryResource = $entry['resource'] ?? [];

            $responseEntries[] = match ($method) {
                'GET' => $id !== ''
                    ? ['response' => ['status' => '200 OK', 'outcome' => $this->repository->read($type, $id)]]
                    : ['response' => ['status' => '200 OK', 'outcome' => $this->repository->search($type)]],
                'POST' => ['response' => ['status' => '201 Created', 'outcome' => $this->repository->create($type, $entryResource)]],
                'PUT' => ['response' => ['status' => '200 OK', 'outcome' => $this->repository->update($type, $id, $entryResource)]],
                'DELETE' => ['response' => ['status' => $this->repository->delete($type, $id) ? '204 No Content' : '404 Not Found']],
                default => ['response' => ['status' => '400 Bad Request', 'outcome' => OperationOutcome::error("Unsupported method: $method")->toArray()]],
            };
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
     * Fail-closed SMART scope gate: returns an OperationOutcome response array
     * when the caller may not perform $permission on $resourceType, or null when
     * access is granted.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}|null
     */
    private function authorize(ServerRequestInterface $request, string $resourceType, string $permission): ?array
    {
        $scopeString = $this->scopeString($request);

        if ($scopeString === '') {
            return $this->securityOutcome(
                401,
                'login',
                'Authentication required: a validated SMART on FHIR access token with scopes is required',
            );
        }

        if (!$this->scopeEnforcer->checkAccess($scopeString, $resourceType, $permission)) {
            return $this->securityOutcome(
                403,
                'forbidden',
                "Access denied: no SMART scope grants $permission on $resourceType",
            );
        }

        return null;
    }

    private function scopeString(ServerRequestInterface $request): string
    {
        /** @var mixed $raw */
        $raw = $request->getAttribute('smart_scopes');

        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function securityOutcome(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'body' => OperationOutcome::error($message, $code)->toArray(),
            'headers' => $this->fhirHeaders(),
        ];
    }

    private function routeParam(ServerRequestInterface $request, string $name): string
    {
        /** @var mixed $value */
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(ServerRequestInterface $request): array
    {
        /** @var mixed $parsed */
        $parsed = $request->getParsedBody();

        if (is_array($parsed)) {
            /** @var array<string, mixed> $parsed */
            return $parsed;
        }

        $raw = (string) $request->getBody();

        if ($raw === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
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
