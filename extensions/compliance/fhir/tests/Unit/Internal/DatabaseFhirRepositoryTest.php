<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Internal;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Fhir\Internal\DatabaseFhirRepository;

#[CoversClass(DatabaseFhirRepository::class)]
final class DatabaseFhirRepositoryTest extends TestCase
{
    private PdoConnection $connection;

    private DatabaseFhirRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
            options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE fhir_resources (
                resource_type VARCHAR(64) NOT NULL,
                resource_id VARCHAR(64) NOT NULL,
                version_id INTEGER NOT NULL DEFAULT 1,
                last_updated VARCHAR(40) NOT NULL,
                content TEXT NOT NULL,
                is_deleted INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (resource_type, resource_id)
            )
            SQL);

        $this->repository = new DatabaseFhirRepository($this->connection);
    }

    #[Test]
    public function createAssignsIdResourceTypeAndMeta(): void
    {
        $created = $this->repository->create('Patient', ['name' => [['family' => 'Doe']]]);

        self::assertArrayHasKey('id', $created);
        self::assertNotSame('', $created['id']);
        self::assertSame('Patient', $created['resourceType']);
        self::assertSame('1', $created['meta']['versionId']);
        self::assertArrayHasKey('lastUpdated', $created['meta']);
    }

    #[Test]
    public function createHonoursProvidedId(): void
    {
        $created = $this->repository->create('Patient', ['id' => 'pat-1', 'active' => true]);

        self::assertSame('pat-1', $created['id']);
        self::assertSame($created, $this->repository->read('Patient', 'pat-1'));
    }

    #[Test]
    public function readReturnsNullForMissingResource(): void
    {
        self::assertNull($this->repository->read('Patient', 'does-not-exist'));
    }

    #[Test]
    public function readReturnsPersistedResourceAcrossInstances(): void
    {
        $this->repository->create('Observation', ['id' => 'obs-1', 'status' => 'final']);

        // A fresh repository over the same connection must see the row.
        $fresh = new DatabaseFhirRepository($this->connection);
        $read = $fresh->read('Observation', 'obs-1');

        self::assertNotNull($read);
        self::assertSame('final', $read['status']);
    }

    #[Test]
    public function updateBumpsVersionAndPersistsChanges(): void
    {
        $this->repository->create('Patient', ['id' => 'pat-2', 'active' => false]);
        $updated = $this->repository->update('Patient', 'pat-2', ['active' => true]);

        self::assertSame('2', $updated['meta']['versionId']);
        self::assertTrue($updated['active']);

        $read = $this->repository->read('Patient', 'pat-2');
        self::assertNotNull($read);
        self::assertTrue($read['active']);
        self::assertSame('2', $read['meta']['versionId']);
    }

    #[Test]
    public function searchReturnsAllResourcesOfType(): void
    {
        $this->repository->create('Patient', ['id' => 'a']);
        $this->repository->create('Patient', ['id' => 'b']);
        $this->repository->create('Observation', ['id' => 'c']);

        $patients = $this->repository->search('Patient');

        self::assertCount(2, $patients);
    }

    #[Test]
    public function searchFiltersByIdControlParameter(): void
    {
        $this->repository->create('Patient', ['id' => 'a']);
        $this->repository->create('Patient', ['id' => 'b']);

        $results = $this->repository->search('Patient', ['_id' => 'b']);

        self::assertCount(1, $results);
        self::assertSame('b', $results[0]['id']);
    }

    #[Test]
    public function searchFiltersByDottedFieldPath(): void
    {
        $this->repository->create('Observation', [
            'id' => 'o1',
            'subject' => ['reference' => 'Patient/a'],
        ]);
        $this->repository->create('Observation', [
            'id' => 'o2',
            'subject' => ['reference' => 'Patient/b'],
        ]);

        $results = $this->repository->search('Observation', ['subject.reference' => 'Patient/a']);

        self::assertCount(1, $results);
        self::assertSame('o1', $results[0]['id']);
    }

    #[Test]
    public function deleteLogicallyRemovesResource(): void
    {
        $this->repository->create('Patient', ['id' => 'pat-3']);

        self::assertTrue($this->repository->delete('Patient', 'pat-3'));
        self::assertNull($this->repository->read('Patient', 'pat-3'));
        self::assertCount(0, $this->repository->search('Patient'));
    }

    #[Test]
    public function deleteReturnsFalseForMissingResource(): void
    {
        self::assertFalse($this->repository->delete('Patient', 'ghost'));
    }

    #[Test]
    public function deleteIsIdempotent(): void
    {
        $this->repository->create('Patient', ['id' => 'pat-4']);

        self::assertTrue($this->repository->delete('Patient', 'pat-4'));
        self::assertFalse($this->repository->delete('Patient', 'pat-4'));
    }

    #[Test]
    public function recreatingDeletedResourceResurrectsAndBumpsVersion(): void
    {
        $this->repository->create('Patient', ['id' => 'pat-5', 'active' => true]);
        $this->repository->delete('Patient', 'pat-5');

        $recreated = $this->repository->create('Patient', ['id' => 'pat-5', 'active' => false]);

        self::assertSame('2', $recreated['meta']['versionId']);
        $read = $this->repository->read('Patient', 'pat-5');
        self::assertNotNull($read);
        self::assertFalse($read['active']);
    }
}
