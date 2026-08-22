<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Internal\InMemoryFhirRepository;
use Pulsar\Extension\Fhir\Resource\ResourceType;
use Pulsar\Extension\Fhir\Rest\CapabilityStatementBuilder;
use Pulsar\Extension\Fhir\Rest\FhirController;
use Pulsar\Extension\Fhir\Smart\SmartScopeEnforcer;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(FhirController::class)]
final class FhirControllerTest extends TestCase
{
    private FhirController $controller;
    private InMemoryFhirRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryFhirRepository();
        $capability = new CapabilityStatementBuilder();
        $capability->addResource(ResourceType::Patient, ['read', 'search-type', 'create', 'update', 'delete']);
        $this->controller = new FhirController($this->repository, $capability, new SmartScopeEnforcer());
    }

    /**
     * Build a request carrying the route attributes the controller reads
     * (`type`, `id`), the SMART scope string, an optional query, and body.
     * The default scope (`system/*.*`) grants every operation, so happy-path
     * tests read as intended; negative tests pass a narrower/empty scope.
     *
     * @param array<string, string> $attributes
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    private function request(
        array $attributes = [],
        array $query = [],
        ?array $body = null,
        string $scopes = 'system/*.*',
    ): ServerRequest {
        return new ServerRequest(
            queryParams: $query,
            parsedBody: $body,
            attributes: ['smart_scopes' => $scopes] + $attributes,
        );
    }

    public function testMetadataReturnsCapabilityStatement(): void
    {
        $response = $this->controller->metadata();

        self::assertSame(200, $response['status']);
        self::assertSame('CapabilityStatement', $response['body']['resourceType']);
        self::assertSame('application/fhir+json; charset=utf-8', $response['headers']['Content-Type']);
    }

    public function testCreateAndRead(): void
    {
        $createResponse = $this->controller->create($this->request(
            ['type' => 'Patient'],
            body: [
                'name' => [['family' => 'Smith']],
                'gender' => 'male',
            ],
        ));

        self::assertSame(201, $createResponse['status']);
        self::assertArrayHasKey('id', $createResponse['body']);

        $id = $createResponse['body']['id'];
        self::assertIsString($id);
        $readResponse = $this->controller->read($this->request(['type' => 'Patient', 'id' => $id]));

        self::assertSame(200, $readResponse['status']);
        self::assertSame($id, $readResponse['body']['id']);
        self::assertSame('Patient', $readResponse['body']['resourceType']);
    }

    public function testReadNotFound(): void
    {
        $response = $this->controller->read($this->request(['type' => 'Patient', 'id' => 'nonexistent']));

        self::assertSame(404, $response['status']);
        self::assertSame('OperationOutcome', $response['body']['resourceType']);
        $issues = $response['body']['issue'];
        self::assertIsArray($issues);
        $issue0 = $issues[0];
        self::assertIsArray($issue0);
        self::assertSame('not-found', $issue0['code']);
    }

    public function testReadWithoutAuthenticationReturns401(): void
    {
        $response = $this->controller->read($this->request(['type' => 'Patient', 'id' => 'p1'], scopes: ''));

        self::assertSame(401, $response['status']);
        self::assertSame('OperationOutcome', $response['body']['resourceType']);
    }

    public function testCreateWithInsufficientScopeReturns403(): void
    {
        // A read-only scope must not authorize a write.
        $response = $this->controller->create($this->request(
            ['type' => 'Patient'],
            body: ['gender' => 'male'],
            scopes: 'user/Patient.read',
        ));

        self::assertSame(403, $response['status']);
        self::assertSame('OperationOutcome', $response['body']['resourceType']);
        // Fail-closed: nothing was persisted.
        self::assertCount(0, $this->repository->search('Patient'));
    }

    public function testSearch(): void
    {
        $this->repository->create('Patient', ['id' => 'p1', 'gender' => 'male']);
        $this->repository->create('Patient', ['id' => 'p2', 'gender' => 'female']);
        $this->repository->create('Observation', ['id' => 'o1', 'status' => 'final']);

        $response = $this->controller->search($this->request(['type' => 'Patient']));

        self::assertSame(200, $response['status']);
        self::assertSame('Bundle', $response['body']['resourceType']);
        self::assertSame('searchset', $response['body']['type']);
        self::assertSame(2, $response['body']['total']);
        $entries = $response['body']['entry'];
        self::assertIsArray($entries);
        self::assertCount(2, $entries);
    }

    public function testSearchWithParameters(): void
    {
        $this->repository->create('Patient', ['id' => 'p1', 'gender' => 'male']);
        $this->repository->create('Patient', ['id' => 'p2', 'gender' => 'female']);

        $response = $this->controller->search($this->request(['type' => 'Patient'], query: ['_id' => 'p1']));

        self::assertSame(200, $response['status']);
        self::assertSame(1, $response['body']['total']);
    }

    public function testUpdate(): void
    {
        $this->repository->create('Patient', ['id' => 'p1', 'gender' => 'male']);

        $response = $this->controller->update($this->request(
            ['type' => 'Patient', 'id' => 'p1'],
            body: [
                'gender' => 'male',
                'active' => true,
            ],
        ));

        self::assertSame(200, $response['status']);
        self::assertSame('p1', $response['body']['id']);
        self::assertTrue($response['body']['active']);
    }

    public function testDelete(): void
    {
        $this->repository->create('Patient', ['id' => 'p1']);

        $response = $this->controller->delete($this->request(['type' => 'Patient', 'id' => 'p1']));
        self::assertSame(204, $response['status']);

        $readResponse = $this->controller->read($this->request(['type' => 'Patient', 'id' => 'p1']));
        self::assertSame(404, $readResponse['status']);
    }

    public function testDeleteNotFound(): void
    {
        $response = $this->controller->delete($this->request(['type' => 'Patient', 'id' => 'nonexistent']));

        self::assertSame(404, $response['status']);
        self::assertSame('OperationOutcome', $response['body']['resourceType']);
    }

    public function testBatchOperation(): void
    {
        $bundle = [
            'type' => 'batch',
            'entry' => [
                [
                    'resource' => ['name' => [['family' => 'BatchPatient']]],
                    'request' => ['method' => 'POST', 'url' => 'Patient'],
                ],
                [
                    'request' => ['method' => 'GET', 'url' => 'Patient'],
                ],
            ],
        ];

        $response = $this->controller->batch($this->request(body: $bundle));

        self::assertSame(200, $response['status']);
        self::assertSame('batch-response', $response['body']['type']);
        $entries = $response['body']['entry'];
        self::assertIsArray($entries);
        self::assertCount(2, $entries);
        $entry0 = $entries[0];
        self::assertIsArray($entry0);
        $entryResponse = $entry0['response'];
        self::assertIsArray($entryResponse);
        self::assertSame('201 Created', $entryResponse['status']);
    }

    public function testBatchRequiresAuthentication(): void
    {
        $response = $this->controller->batch($this->request(body: ['type' => 'batch', 'entry' => []], scopes: ''));

        self::assertSame(401, $response['status']);
        self::assertSame('OperationOutcome', $response['body']['resourceType']);
    }

    public function testBatchInvalidBundleType(): void
    {
        $response = $this->controller->batch($this->request(body: ['type' => 'collection']));

        self::assertSame(400, $response['status']);
        self::assertSame('OperationOutcome', $response['body']['resourceType']);
    }

    public function testBatchMissingRequest(): void
    {
        $response = $this->controller->batch($this->request(body: [
            'type' => 'batch',
            'entry' => [
                ['resource' => ['id' => '1']],
            ],
        ]));

        self::assertSame(200, $response['status']);
        $entries = $response['body']['entry'];
        self::assertIsArray($entries);
        $entry0 = $entries[0];
        self::assertIsArray($entry0);
        $entryResponse = $entry0['response'];
        self::assertIsArray($entryResponse);
        self::assertSame('400 Bad Request', $entryResponse['status']);
    }
}
