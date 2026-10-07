<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Internal\InMemoryFhirRepository;

#[CoversClass(InMemoryFhirRepository::class)]
final class InMemoryFhirRepositoryTest extends TestCase
{
    private InMemoryFhirRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryFhirRepository();
    }

    public function testCreateAssignsId(): void
    {
        $resource = $this->repository->create('Patient', ['gender' => 'male']);

        self::assertArrayHasKey('id', $resource);
        self::assertNotEmpty($resource['id']);
        self::assertSame('Patient', $resource['resourceType']);
        self::assertArrayHasKey('meta', $resource);
        $meta = $resource['meta'];
        self::assertIsArray($meta);
        self::assertArrayHasKey('versionId', $meta);
    }

    public function testCreateWithExplicitId(): void
    {
        $resource = $this->repository->create('Patient', ['id' => 'pt-001', 'gender' => 'female']);

        self::assertSame('pt-001', $resource['id']);
    }

    public function testReadExistingResource(): void
    {
        $this->repository->create('Patient', ['id' => 'pt-001', 'active' => true]);

        $result = $this->repository->read('Patient', 'pt-001');

        self::assertNotNull($result);
        self::assertSame('pt-001', $result['id']);
        self::assertTrue($result['active']);
    }

    public function testReadNonexistentReturnsNull(): void
    {
        self::assertNull($this->repository->read('Patient', 'missing'));
    }

    public function testSearchByType(): void
    {
        $this->repository->create('Patient', ['id' => 'p1']);
        $this->repository->create('Patient', ['id' => 'p2']);
        $this->repository->create('Observation', ['id' => 'o1']);

        $results = $this->repository->search('Patient');

        self::assertCount(2, $results);
    }

    public function testSearchById(): void
    {
        $this->repository->create('Patient', ['id' => 'p1', 'name' => 'A']);
        $this->repository->create('Patient', ['id' => 'p2', 'name' => 'B']);

        $results = $this->repository->search('Patient', ['_id' => 'p1']);

        self::assertCount(1, $results);
        self::assertSame('p1', $results[0]['id']);
    }

    public function testSearchNoResults(): void
    {
        $this->repository->create('Patient', ['id' => 'p1']);

        $results = $this->repository->search('Observation');

        self::assertSame([], $results);
    }

    public function testUpdate(): void
    {
        $this->repository->create('Patient', ['id' => 'p1', 'active' => false]);

        $updated = $this->repository->update('Patient', 'p1', ['active' => true, 'gender' => 'male']);

        self::assertSame('p1', $updated['id']);
        self::assertTrue($updated['active']);
        self::assertSame('male', $updated['gender']);
    }

    public function testDeleteExisting(): void
    {
        $this->repository->create('Patient', ['id' => 'p1']);

        self::assertTrue($this->repository->delete('Patient', 'p1'));
        self::assertNull($this->repository->read('Patient', 'p1'));
    }

    public function testDeleteNonexistent(): void
    {
        self::assertFalse($this->repository->delete('Patient', 'missing'));
    }

    public function testVersionIncrementsOnUpdate(): void
    {
        $created = $this->repository->create('Patient', ['id' => 'p1']);
        $createdMeta = $created['meta'];
        self::assertIsArray($createdMeta);
        $v1 = $createdMeta['versionId'];

        $updated = $this->repository->update('Patient', 'p1', ['active' => true]);
        $updatedMeta = $updated['meta'];
        self::assertIsArray($updatedMeta);
        $v2 = $updatedMeta['versionId'];

        self::assertNotSame($v1, $v2);
    }
}
