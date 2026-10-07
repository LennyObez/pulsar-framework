<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Fhir\Resource\FhirVersion;
use Pulsar\Extension\Fhir\Rest\CapabilityStatementBuilder;
use Pulsar\Extension\Fhir\Rest\FhirController;
use Pulsar\Extension\Fhir\Rest\FhirRepositoryInterface;
use Pulsar\Extension\Fhir\Smart\SmartScopeEnforcer;

#[CoversClass(FhirController::class)]
final class FhirControllerTest extends TestCase
{
    /** Grants read+write on Patient. */
    private const string PATIENT_FULL = 'user/Patient.*';

    private FhirController $controller;
    private FhirRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(FhirRepositoryInterface::class);
        $capabilityStatement = new CapabilityStatementBuilder('TestServer', FhirVersion::R4);
        $this->controller = new FhirController($this->repository, $capabilityStatement, new SmartScopeEnforcer());
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
    public function readReturns200WhenResourceExistsAndScopeGrantsIt(): void
    {
        $resource = ['resourceType' => 'Patient', 'id' => 'p1'];
        $this->repository->method('read')->willReturn($resource);

        $result = $this->controller->read($this->request(self::PATIENT_FULL, ['type' => 'Patient', 'id' => 'p1']));

        self::assertSame(200, $result['status']);
        self::assertSame($resource, $result['body']);
    }

    #[Test]
    public function readReturns404WhenResourceMissing(): void
    {
        $this->repository->method('read')->willReturn(null);

        $result = $this->controller->read($this->request(self::PATIENT_FULL, ['type' => 'Patient', 'id' => 'nope']));

        self::assertSame(404, $result['status']);
        self::assertSame('OperationOutcome', $result['body']['resourceType']);
    }

    #[Test]
    public function readWithoutAnyScopeReturns401AndNeverTouchesTheRepository(): void
    {
        $repository = $this->createMock(FhirRepositoryInterface::class);
        $repository->expects(self::never())->method('read');
        $controller = new FhirController($repository, new CapabilityStatementBuilder('T', FhirVersion::R4), new SmartScopeEnforcer());

        $result = $controller->read($this->request('', ['type' => 'Patient', 'id' => 'p1']));

        self::assertSame(401, $result['status']);
        self::assertSame('OperationOutcome', $result['body']['resourceType']);
    }

    #[Test]
    public function readWithAScopeForADifferentResourceReturns403(): void
    {
        $repository = $this->createMock(FhirRepositoryInterface::class);
        $repository->expects(self::never())->method('read');
        $controller = new FhirController($repository, new CapabilityStatementBuilder('T', FhirVersion::R4), new SmartScopeEnforcer());

        // Scope grants Observation, but the request reads a Patient.
        $result = $controller->read($this->request('user/Observation.read', ['type' => 'Patient', 'id' => 'p1']));

        self::assertSame(403, $result['status']);
    }

    #[Test]
    public function searchReturnsBundleWithEntries(): void
    {
        $resources = [
            ['resourceType' => 'Patient', 'id' => 'p1'],
            ['resourceType' => 'Patient', 'id' => 'p2'],
        ];
        $this->repository->method('search')->willReturn($resources);

        $result = $this->controller->search(
            $this->request(self::PATIENT_FULL, ['type' => 'Patient'], ['name' => 'Smith']),
        );

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
        $this->repository->method('search')->willReturn([['resourceType' => 'Patient']]);

        $result = $this->controller->search($this->request(self::PATIENT_FULL, ['type' => 'Patient']));

        self::assertArrayNotHasKey('fullUrl', $result['body']['entry'][0]);
    }

    #[Test]
    public function createReturns201WithCreatedResource(): void
    {
        $input = ['resourceType' => 'Patient', 'name' => [['family' => 'Smith']]];
        $created = [...$input, 'id' => 'generated-id'];
        $this->repository->method('create')->willReturn($created);

        $result = $this->controller->create($this->request(self::PATIENT_FULL, ['type' => 'Patient'], [], $input));

        self::assertSame(201, $result['status']);
        self::assertSame('generated-id', $result['body']['id']);
    }

    #[Test]
    public function createWithAReadOnlyScopeReturns403(): void
    {
        $repository = $this->createMock(FhirRepositoryInterface::class);
        $repository->expects(self::never())->method('create');
        $controller = new FhirController($repository, new CapabilityStatementBuilder('T', FhirVersion::R4), new SmartScopeEnforcer());

        // Read scope must not authorize a write.
        $result = $controller->create($this->request('user/Patient.read', ['type' => 'Patient'], [], ['resourceType' => 'Patient']));

        self::assertSame(403, $result['status']);
    }

    #[Test]
    public function updateReturns200WithUpdatedResource(): void
    {
        $resource = ['resourceType' => 'Patient', 'id' => 'p1'];
        $this->repository->method('update')->willReturn($resource);

        $result = $this->controller->update(
            $this->request(self::PATIENT_FULL, ['type' => 'Patient', 'id' => 'p1'], [], $resource),
        );

        self::assertSame(200, $result['status']);
    }

    #[Test]
    public function deleteReturns204WhenSuccessful(): void
    {
        $this->repository->method('delete')->willReturn(true);

        $result = $this->controller->delete($this->request(self::PATIENT_FULL, ['type' => 'Patient', 'id' => 'p1']));

        self::assertSame(204, $result['status']);
    }

    #[Test]
    public function deleteReturns404WhenResourceMissing(): void
    {
        $this->repository->method('delete')->willReturn(false);

        $result = $this->controller->delete($this->request(self::PATIENT_FULL, ['type' => 'Patient', 'id' => 'nope']));

        self::assertSame(404, $result['status']);
    }

    #[Test]
    public function batchWithoutAnyScopeReturns401(): void
    {
        $result = $this->controller->batch($this->request('', [], [], ['type' => 'batch', 'entry' => []]));

        self::assertSame(401, $result['status']);
    }

    #[Test]
    public function batchRejectsInvalidBundleType(): void
    {
        $result = $this->controller->batch($this->request(self::PATIENT_FULL, [], [], ['type' => 'invalid']));

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
                ['request' => ['method' => 'POST', 'url' => 'Patient'], 'resource' => ['resourceType' => 'Patient']],
            ],
        ];

        $result = $this->controller->batch($this->request(self::PATIENT_FULL, [], [], $bundle));

        self::assertSame(200, $result['status']);
        self::assertSame('batch-response', $result['body']['type']);
        self::assertCount(1, $result['body']['entry']);
        self::assertSame('201 Created', $result['body']['entry'][0]['response']['status']);
    }

    #[Test]
    public function batchEntryTouchingAnUnscopedResourceIsForbidden(): void
    {
        $repository = $this->createMock(FhirRepositoryInterface::class);
        $repository->expects(self::never())->method('read');
        $controller = new FhirController($repository, new CapabilityStatementBuilder('T', FhirVersion::R4), new SmartScopeEnforcer());

        // Scope covers Patient only; the entry reads an Observation.
        $bundle = [
            'type' => 'transaction',
            'entry' => [['request' => ['method' => 'GET', 'url' => 'Observation/o1']]],
        ];

        $result = $controller->batch($this->request(self::PATIENT_FULL, [], [], $bundle));

        self::assertSame('403 Forbidden', $result['body']['entry'][0]['response']['status']);
    }

    #[Test]
    public function batchHandlesMissingRequestEntry(): void
    {
        $bundle = ['type' => 'batch', 'entry' => [['resource' => ['resourceType' => 'Patient']]]];

        $result = $this->controller->batch($this->request(self::PATIENT_FULL, [], [], $bundle));

        self::assertSame('400 Bad Request', $result['body']['entry'][0]['response']['status']);
    }

    #[Test]
    public function batchGetWithoutIdCallsSearch(): void
    {
        $this->repository->method('search')->willReturn([]);

        $bundle = ['type' => 'batch', 'entry' => [['request' => ['method' => 'GET', 'url' => 'Patient']]]];

        $result = $this->controller->batch($this->request(self::PATIENT_FULL, [], [], $bundle));

        self::assertSame('200 OK', $result['body']['entry'][0]['response']['status']);
    }

    #[Test]
    public function batchPutCallsUpdate(): void
    {
        $updated = ['resourceType' => 'Patient', 'id' => 'p1'];
        $this->repository->method('update')->willReturn($updated);

        $bundle = [
            'type' => 'batch',
            'entry' => [['request' => ['method' => 'PUT', 'url' => 'Patient/p1'], 'resource' => $updated]],
        ];

        $result = $this->controller->batch($this->request(self::PATIENT_FULL, [], [], $bundle));

        self::assertSame('200 OK', $result['body']['entry'][0]['response']['status']);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    private function request(string $scopes, array $attributes = [], array $query = [], ?array $body = null): ServerRequestInterface
    {
        $attributes['smart_scopes'] = $scopes;

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => $attributes[$name] ?? $default,
        );
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }
}
