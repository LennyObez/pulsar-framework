<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Internal\InMemoryFhirRepository;
use Pulsar\Extension\Fhir\Resource\ResourceType;
use Pulsar\Extension\Fhir\Rest\CapabilityStatementBuilder;
use Pulsar\Extension\Fhir\Rest\FhirController;

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
        $this->controller = new FhirController($this->repository, $capability);
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
        $createResponse = $this->controller->create('Patient', [
            'name' => [['family' => 'Smith']],
            'gender' => 'male',
        ]);

        self::assertSame(201, $createResponse['status']);
        self::assertArrayHasKey('id', $createResponse['body']);

        $id = $createResponse['body']['id'];
        self::assertIsString($id);
        $readResponse = $this->controller->read('Patient', $id);

        self::assertSame(200, $readResponse['status']);
        self::assertSame($id, $readResponse['body']['id']);
        self::assertSame('Patient', $readResponse['body']['resourceType']);
    }

    public function testReadNotFound(): void
    {
        $response = $this->controller->read('Patient', 'nonexistent');

        self::assertSame(404, $response['status']);
        self::assertSame('OperationOutcome', $response['body']['resourceType']);
        $issues = $response['body']['issue'];
        self::assertIsArray($issues);
        $issue0 = $issues[0];
        self::assertIsArray($issue0);
        self::assertSame('not-found', $issue0['code']);
    }

    public function testSearch(): void
    {
        $this->repository->create('Patient', ['id' => 'p1', 'gender' => 'male']);
        $this->repository->create('Patient', ['id' => 'p2', 'gender' => 'female']);
        $this->repository->create('Observation', ['id' => 'o1', 'status' => 'final']);

        $response = $this->controller->search('Patient');

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

        $response = $this->controller->search('Patient', ['_id' => 'p1']);

        self::assertSame(200, $response['status']);
        self::assertSame(1, $response['body']['total']);
    }

    public function testUpdate(): void
    {
        $this->repository->create('Patient', ['id' => 'p1', 'gender' => 'male']);

        $response = $this->controller->update('Patient', 'p1', [
            'gender' => 'male',
            'active' => true,
        ]);

        self::assertSame(200, $response['status']);
        self::assertSame('p1', $response['body']['id']);
        self::assertTrue($response['body']['active']);
    }

    public function testDelete(): void
    {
        $this->repository->create('Patient', ['id' => 'p1']);

        $response = $this->controller->delete('Patient', 'p1');
        self::assertSame(204, $response['status']);

        $readResponse = $this->controller->read('Patient', 'p1');
        self::assertSame(404, $readResponse['status']);
    }

    public function testDeleteNotFound(): void
    {
        $response = $this->controller->delete('Patient', 'nonexistent');

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

        $response = $this->controller->batch($bundle);

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

    public function testBatchInvalidBundleType(): void
    {
        $response = $this->controller->batch(['type' => 'collection']);

        self::assertSame(400, $response['status']);
        self::assertSame('OperationOutcome', $response['body']['resourceType']);
    }

    public function testBatchMissingRequest(): void
    {
        $response = $this->controller->batch([
            'type' => 'batch',
            'entry' => [
                ['resource' => ['id' => '1']],
            ],
        ]);

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
