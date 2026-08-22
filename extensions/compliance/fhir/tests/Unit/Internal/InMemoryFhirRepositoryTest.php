<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Internal\InMemoryFhirRepository;

#[CoversClass(InMemoryFhirRepository::class)]
final class InMemoryFhirRepositoryTest extends TestCase
{
    private InMemoryFhirRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryFhirRepository();
    }

    #[Test]
    public function readReturnsNullForMissingResource(): void
    {
        self::assertNull($this->repo->read('Patient', 'nonexistent'));
    }

    #[Test]
    public function createAndReadResource(): void
    {
        $resource = $this->repo->create('Patient', [
            'id' => 'p1',
            'name' => [['family' => 'Smith']],
        ]);

        self::assertSame('p1', $resource['id']);
        self::assertSame('Patient', $resource['resourceType']);
        self::assertArrayHasKey('meta', $resource);

        $found = $this->repo->read('Patient', 'p1');
        self::assertNotNull($found);
        self::assertSame('p1', $found['id']);
    }

    #[Test]
    public function createGeneratesIdWhenNotProvided(): void
    {
        $resource = $this->repo->create('Observation', ['status' => 'final']);

        self::assertNotEmpty($resource['id']);
        self::assertSame('Observation', $resource['resourceType']);
    }

    #[Test]
    public function updateReplacesResource(): void
    {
        $this->repo->create('Patient', ['id' => 'p1', 'gender' => 'male']);

        $updated = $this->repo->update('Patient', 'p1', ['gender' => 'female']);

        self::assertSame('female', $updated['gender']);
        self::assertSame('p1', $updated['id']);

        $read = $this->repo->read('Patient', 'p1');
        self::assertSame('female', $read['gender']);
    }

    #[Test]
    public function updateIncrementsVersion(): void
    {
        $created = $this->repo->create('Patient', ['id' => 'p1']);
        $updated = $this->repo->update('Patient', 'p1', []);

        self::assertNotSame($created['meta']['versionId'], $updated['meta']['versionId']);
    }

    #[Test]
    public function deleteExistingResourceReturnsTrue(): void
    {
        $this->repo->create('Patient', ['id' => 'p1']);

        self::assertTrue($this->repo->delete('Patient', 'p1'));
        self::assertNull($this->repo->read('Patient', 'p1'));
    }

    #[Test]
    public function deleteMissingResourceReturnsFalse(): void
    {
        self::assertFalse($this->repo->delete('Patient', 'nonexistent'));
    }

    #[Test]
    public function searchByResourceType(): void
    {
        $this->repo->create('Patient', ['id' => 'p1']);
        $this->repo->create('Patient', ['id' => 'p2']);
        $this->repo->create('Observation', ['id' => 'o1']);

        $patients = $this->repo->search('Patient');

        self::assertCount(2, $patients);
    }

    #[Test]
    public function searchByIdParameter(): void
    {
        $this->repo->create('Patient', ['id' => 'p1']);
        $this->repo->create('Patient', ['id' => 'p2']);

        $results = $this->repo->search('Patient', ['_id' => 'p1']);

        self::assertCount(1, $results);
        self::assertSame('p1', $results[0]['id']);
    }

    #[Test]
    public function searchByDottedPath(): void
    {
        $this->repo->create('Patient', [
            'id' => 'p1',
            'name' => ['family' => 'Smith'],
        ]);
        $this->repo->create('Patient', [
            'id' => 'p2',
            'name' => ['family' => 'Jones'],
        ]);

        $results = $this->repo->search('Patient', ['name.family' => 'Smith']);

        self::assertCount(1, $results);
        self::assertSame('p1', $results[0]['id']);
    }

    #[Test]
    public function searchReturnsEmptyWhenNoMatches(): void
    {
        $this->repo->create('Patient', ['id' => 'p1']);

        $results = $this->repo->search('Observation');

        self::assertSame([], $results);
    }

    #[Test]
    public function searchByMissingFieldReturnsEmpty(): void
    {
        $this->repo->create('Patient', ['id' => 'p1']);

        $results = $this->repo->search('Patient', ['nonexistent_field' => 'value']);

        self::assertSame([], $results);
    }
}
