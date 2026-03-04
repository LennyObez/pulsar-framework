<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\FhirVersion;
use Pulsar\Extension\Fhir\Rest\CapabilityStatementBuilder;
use Pulsar\Extension\Fhir\Rest\FhirController;
use Pulsar\Extension\Fhir\Rest\FhirRepositoryInterface;

#[CoversClass(FhirController::class)]
final class FhirControllerTest extends TestCase
{
    private FhirController $controller;
    private FhirRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(FhirRepositoryInterface::class);
        $capabilityStatement = new CapabilityStatementBuilder('TestServer', FhirVersion::R4);
        $this->controller = new FhirController($this->repository, $capabilityStatement);
    }

    #[Test]
    public function metadataReturns200WithCapabilityStatement(): void
    {
        $result = $this->controller->metadata();

        self::assertSame(200, $result['status']);
        self::assertSame('application/fhir+json; charset=utf-8', $result['headers']['Content-Type']);
        self::assertSame('CapabilityStatement', $result['body']['resourceType']);
    }

    #[Test]
    public function readReturns200WhenResourceExists(): void
    {
        $resource = ['resourceType' => 'Patient', 'id' => 'p1'];
        $this->repository->method('read')->willReturn($resource);

        $result = $this->controller->read('Patient', 'p1');

        self::assertSame(200, $result['status']);
        self::assertSame($resource, $result['body']);
    }

    #[Test]
    public function readReturns404WhenResourceMissing(): void
    {
        $this->repository->method('read')->willReturn(null);

        $result = $this->controller->read('Patient', 'nonexistent');

        self::assertSame(404, $result['status']);
        self::assertSame('OperationOutcome', $result['body']['resourceType']);
    }

    #[Test]
    public function searchReturnsBundleWithEntries(): void
    {
        $resources = [
            ['resourceType' => 'Patient', 'id' => 'p1'],
            ['resourceType' => 'Patient', 'id' => 'p2'],
        ];
        $this->repository->method('search')->willReturn($resources);

        $result = $this->controller->search('Patient', ['name' => 'Smith']);

        self::assertSame(200, $result['status']);
        self::assertSame('Bundle', $result['body']['resourceType']);
        self::assertSame('searchset', $result['body']['type']);
        self::assertSame(2, $result['body']['total']);
        self::assertCount(2, $result['body']['entry']);
        self::assertSame('Patient/p1', $result['body']['entry'][0]['fullUrl']);
    }

    #[Test]
    public function searchHandlesEntriesWithoutId(): void
    {
        $resources = [['resourceType' => 'Patient']];
        $this->repository->method('search')->willReturn($resources);

        $result = $this->controller->search('Patient');

        self::assertArrayNotHasKey('fullUrl', $result['body']['entry'][0]);
    }

    #[Test]
    public function createReturns201WithCreatedResource(): void
    {
        $input = ['resourceType' => 'Patient', 'name' => [['family' => 'Smith']]];
        $created = [...$input, 'id' => 'generated-id'];
        $this->repository->method('create')->willReturn($created);

        $result = $this->controller->create('Patient', $input);

        self::assertSame(201, $result['status']);
        self::assertSame('generated-id', $result['body']['id']);
    }

    #[Test]
    public function updateReturns200WithUpdatedResource(): void
    {
        $resource = ['resourceType' => 'Patient', 'id' => 'p1'];
        $this->repository->method('update')->willReturn($resource);

        $result = $this->controller->update('Patient', 'p1', $resource);

        self::assertSame(200, $result['status']);
    }

    #[Test]
    public function deleteReturns204WhenSuccessful(): void
    {
        $this->repository->method('delete')->willReturn(true);

        $result = $this->controller->delete('Patient', 'p1');

        self::assertSame(204, $result['status']);
    }

    #[Test]
    public function deleteReturns404WhenResourceMissing(): void
    {
        $this->repository->method('delete')->willReturn(false);

        $result = $this->controller->delete('Patient', 'nonexistent');

        self::assertSame(404, $result['status']);
    }

    #[Test]
    public function batchRejectsInvalidBundleType(): void
    {
        $result = $this->controller->batch(['type' => 'invalid']);

        self::assertSame(400, $result['status']);
    }

    #[Test]
    public function batchProcessesBatchBundle(): void
    {
        $created = ['resourceType' => 'Patient', 'id' => 'new-1'];
        $this->repository->method('create')->willReturn($created);

        $bundle = [
            'type' => 'batch',
            'entry' => [
                [
                    'request' => ['method' => 'POST', 'url' => 'Patient'],
                    'resource' => ['resourceType' => 'Patient'],
                ],
            ],
        ];

        $result = $this->controller->batch($bundle);

        self::assertSame(200, $result['status']);
        self::assertSame('batch-response', $result['body']['type']);
        self::assertCount(1, $result['body']['entry']);
    }

    #[Test]
    public function batchProcessesTransactionBundle(): void
    {
        $this->repository->method('read')->willReturn(['id' => 'p1']);

        $bundle = [
            'type' => 'transaction',
            'entry' => [
                [
                    'request' => ['method' => 'GET', 'url' => 'Patient/p1'],
                ],
            ],
        ];

        $result = $this->controller->batch($bundle);

        self::assertSame('transaction-response', $result['body']['type']);
    }

    #[Test]
    public function batchHandlesMissingRequest(): void
    {
        $bundle = [
            'type' => 'batch',
            'entry' => [['resource' => ['resourceType' => 'Patient']]],
        ];

        $result = $this->controller->batch($bundle);

        self::assertSame('400 Bad Request', $result['body']['entry'][0]['response']['status']);
    }

    #[Test]
    public function batchHandlesUnsupportedMethod(): void
    {
        $bundle = [
            'type' => 'batch',
            'entry' => [
                ['request' => ['method' => 'PATCH', 'url' => 'Patient/p1']],
            ],
        ];

        $result = $this->controller->batch($bundle);

        self::assertSame('400 Bad Request', $result['body']['entry'][0]['response']['status']);
    }

    #[Test]
    public function batchGetWithoutIdCallsSearch(): void
    {
        $this->repository->method('search')->willReturn([]);

        $bundle = [
            'type' => 'batch',
            'entry' => [
                ['request' => ['method' => 'GET', 'url' => 'Patient']],
            ],
        ];

        $result = $this->controller->batch($bundle);

        self::assertSame('200 OK', $result['body']['entry'][0]['response']['status']);
    }

    #[Test]
    public function batchDeleteReportsCorrectStatus(): void
    {
        $this->repository->method('delete')->willReturn(false);

        $bundle = [
            'type' => 'batch',
            'entry' => [
                ['request' => ['method' => 'DELETE', 'url' => 'Patient/p1']],
            ],
        ];

        $result = $this->controller->batch($bundle);

        self::assertSame('404 Not Found', $result['body']['entry'][0]['response']['status']);
    }

    #[Test]
    public function batchPutCallsUpdate(): void
    {
        $updated = ['resourceType' => 'Patient', 'id' => 'p1'];
        $this->repository->method('update')->willReturn($updated);

        $bundle = [
            'type' => 'batch',
            'entry' => [
                [
                    'request' => ['method' => 'PUT', 'url' => 'Patient/p1'],
                    'resource' => $updated,
                ],
            ],
        ];

        $result = $this->controller->batch($bundle);

        self::assertSame('200 OK', $result['body']['entry'][0]['response']['status']);
    }
}
