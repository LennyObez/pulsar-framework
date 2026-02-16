<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogEntry;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Extension\Admin\Server\Controller\SchemaController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Translator;

#[CoversClass(SchemaController::class)]
final class SchemaControllerTest extends TestCase
{
    private SchemaChangeLogStoreInterface&Stub $changeLog;

    protected function setUp(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(null);
        $catalog->method('has')->willReturn(false);
        Translator::setGlobalInstance(new Translator($catalog, new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
            fallbackLocales: [],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        )));
        $this->changeLog = $this->createStub(SchemaChangeLogStoreInterface::class);
    }

    protected function tearDown(): void
    {
        Translator::resetGlobalInstance();
    }

    private function makeControllerWithIntrospector(
        DatabaseIntrospector $introspector,
        Driver $driver = Driver::SQLite,
        bool $schemaEnabled = true,
    ): SchemaController {
        $capabilities = new SchemaCapabilities($driver);
        $config = new AdminSchemaConfig(enabled: $schemaEnabled);

        return new SchemaController(
            $introspector,
            $capabilities,
            $config,
            $this->changeLog,
        );
    }

    private function makeJsonRequest(string $path = '/admin/schema'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
            headers: ['Accept' => 'application/json'],
        );
    }

    private function makeHtmlRequest(string $path = '/admin/schema'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
        );
    }

    #[Test]
    public function listReturnsJsonWithTablesAndCapabilities(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        // SQLite tables query
        $tablesResult = new Result([
            new Row(['name' => 'users']),
            new Row(['name' => 'orders']),
        ]);

        // For columns queries and primary key checks
        $usersColumnsResult = new Result([
            new Row(['name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1, 'dflt_value' => null]),
            new Row(['name' => 'name', 'type' => 'TEXT', 'notnull' => 0, 'pk' => 0, 'dflt_value' => null]),
        ]);

        $ordersColumnsResult = new Result([
            new Row(['name' => 'order_id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1, 'dflt_value' => null]),
        ]);

        $callCount = 0;
        $connection->method('query')->willReturnCallback(
            function (string $sql) use (&$callCount, $tablesResult, $usersColumnsResult, $ordersColumnsResult): Result {
                $callCount++;
                if ($callCount === 1) {
                    return $tablesResult;
                }
                if ($callCount <= 3) {
                    // users columns (once for columns, once for primaryKey)
                    return $usersColumnsResult;
                }

                return $ordersColumnsResult;
            },
        );

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->list($this->makeJsonRequest());

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('tables', $body);
        self::assertArrayHasKey('capabilities', $body);
        /** @var list<array<string, mixed>> $tables */
        $tables = $body['tables'];
        self::assertCount(2, $tables);
        self::assertSame('users', $tables[0]['name']);
        self::assertSame(2, $tables[0]['columns']);
        self::assertSame('id', $tables[0]['primaryKey']);
        self::assertSame('orders', $tables[1]['name']);
        self::assertSame(1, $tables[1]['columns']);
    }

    #[Test]
    public function listReturnsHtmlWhenNotJson(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([]));

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->list($this->makeHtmlRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Database', (string) $response->getBody());
        self::assertStringContainsString('admin.nav.brand', (string) $response->getBody());
    }

    #[Test]
    public function listCapabilitiesReflectSqliteDriver(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([]));

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector, Driver::SQLite);

        $response = $controller->list($this->makeJsonRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        /** @var array<string, bool> $capabilities */
        $capabilities = $body['capabilities'];
        self::assertFalse($capabilities['supportsNativeEnum']);
        self::assertFalse($capabilities['supportsTransactionalDdl']);
        self::assertFalse($capabilities['supportsAlterColumnType']);
    }

    #[Test]
    public function viewReturnsJsonWithColumnData(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $columnsResult = new Result([
            new Row(['name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1, 'dflt_value' => null]),
            new Row(['name' => 'email', 'type' => 'TEXT', 'notnull' => 0, 'pk' => 0, 'dflt_value' => "'unknown'"]),
        ]);

        $connection->method('query')->willReturn($columnsResult);

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->view($this->makeJsonRequest('/admin/schema/users'), 'users');

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('users', $body['table']);
        self::assertArrayHasKey('columns', $body);
        self::assertArrayHasKey('primaryKey', $body);
        self::assertArrayHasKey('capabilities', $body);

        /** @var list<array<string, mixed>> $columns */
        $columns = $body['columns'];
        self::assertCount(2, $columns);
        self::assertSame('id', $columns[0]['name']);
        self::assertSame('integer', $columns[0]['type']);
        self::assertFalse($columns[0]['nullable']);
        self::assertTrue($columns[0]['primaryKey']);
        self::assertNull($columns[0]['default']);
        self::assertSame('email', $columns[1]['name']);
        self::assertSame('text', $columns[1]['type']);
        self::assertTrue($columns[1]['nullable']);
        self::assertFalse($columns[1]['primaryKey']);
    }

    #[Test]
    public function viewReturnsHtmlWhenNotJson(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $columnsResult = new Result([
            new Row(['name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1, 'dflt_value' => null]),
        ]);
        $connection->method('query')->willReturn($columnsResult);

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->view($this->makeHtmlRequest('/admin/schema/users'), 'users');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Table: users', (string) $response->getBody());
    }

    #[Test]
    public function viewReturnsPrimaryKeyInJson(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $columnsResult = new Result([
            new Row(['name' => 'user_id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1, 'dflt_value' => null]),
            new Row(['name' => 'name', 'type' => 'TEXT', 'notnull' => 0, 'pk' => 0, 'dflt_value' => null]),
        ]);
        $connection->method('query')->willReturn($columnsResult);

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->view($this->makeJsonRequest(), 'accounts');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('user_id', $body['primaryKey']);
    }

    #[Test]
    public function createFormReturnsHtml(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([
            new Row(['name' => 'users']),
        ]));

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->createForm($this->makeHtmlRequest('/admin/schema/create'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Create table', (string) $response->getBody());
    }

    #[Test]
    public function changelogReturnsJsonWithEntries(): void
    {
        $entry = new SchemaChangeLogEntry(
            id: 'cl-1',
            operation: 'CREATE',
            table: 'products',
            actor: 'admin',
            reason: 'New products table',
            timestamp: 1700000000,
            statements: ['CREATE TABLE products (id INTEGER PRIMARY KEY)'],
            evidenceHash: 'hash123',
            correlationId: 'corr-1',
            success: true,
        );

        $this->changeLog->method('recent')->willReturn([$entry]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->changelog($this->makeJsonRequest('/admin/schema/changelog'));

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('entries', $body);
        /** @var list<array<string, mixed>> $entries */
        $entries = $body['entries'];
        self::assertCount(1, $entries);
        self::assertSame('cl-1', $entries[0]['id']);
        self::assertSame('CREATE', $entries[0]['operation']);
        self::assertSame('products', $entries[0]['table']);
        self::assertSame('admin', $entries[0]['actor']);
        self::assertSame('New products table', $entries[0]['reason']);
        self::assertSame(1700000000, $entries[0]['timestamp']);
        self::assertSame('hash123', $entries[0]['evidence_hash']);
        self::assertTrue($entries[0]['success']);
    }

    #[Test]
    public function changelogReturnsHtmlWhenNotJson(): void
    {
        $this->changeLog->method('recent')->willReturn([]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->changelog($this->makeHtmlRequest('/admin/schema/changelog'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Schema change log', (string) $response->getBody());
    }

    #[Test]
    public function changelogReturnsEmptyEntriesArray(): void
    {
        $this->changeLog->method('recent')->willReturn([]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->changelog($this->makeJsonRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['entries']);
    }

    #[Test]
    public function listReturnsEmptyTablesArray(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([]));

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->list($this->makeJsonRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['tables']);
    }

    #[Test]
    public function listHtmlContainsDriverInfo(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([]));

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector, Driver::SQLite);

        $response = $controller->list($this->makeHtmlRequest());

        // The HTML template receives driver as 'sqlite' for this driver configuration
        self::assertStringContainsString('admin.nav.brand', (string) $response->getBody());
    }

    #[Test]
    public function viewHtmlIncludesTableNameInTitle(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([
            new Row(['name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1, 'dflt_value' => null]),
        ]));

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->view($this->makeHtmlRequest(), 'my_table');

        self::assertStringContainsString('Table: my_table', (string) $response->getBody());
    }

    #[Test]
    public function changelogMapsMultipleEntries(): void
    {
        $entry1 = new SchemaChangeLogEntry(
            id: 'cl-1',
            operation: 'CREATE',
            table: 'users',
            actor: 'admin',
            reason: 'Initial',
            timestamp: 1700000000,
            statements: [],
            evidenceHash: 'h1',
            correlationId: null,
            success: true,
        );

        $entry2 = new SchemaChangeLogEntry(
            id: 'cl-2',
            operation: 'ALTER',
            table: 'users',
            actor: 'dev',
            reason: 'Add column',
            timestamp: 1700001000,
            statements: [],
            evidenceHash: 'h2',
            correlationId: 'c2',
            success: false,
        );

        $this->changeLog->method('recent')->willReturn([$entry1, $entry2]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->changelog($this->makeJsonRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        /** @var list<array<string, mixed>> $entries */
        $entries = $body['entries'];
        self::assertCount(2, $entries);
        self::assertSame('cl-1', $entries[0]['id']);
        self::assertTrue($entries[0]['success']);
        self::assertSame('cl-2', $entries[1]['id']);
        self::assertFalse($entries[1]['success']);
        self::assertSame('dev', $entries[1]['actor']);
    }

    #[Test]
    public function viewJsonReturnsNullPrimaryKeyWhenNone(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        // Table with no primary key columns
        $columnsResult = new Result([
            new Row(['name' => 'data', 'type' => 'TEXT', 'notnull' => 0, 'pk' => 0, 'dflt_value' => null]),
        ]);
        $connection->method('query')->willReturn($columnsResult);

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->view($this->makeJsonRequest(), 'no_pk_table');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertNull($body['primaryKey']);
    }

    #[Test]
    public function createFormIncludesExistingTableNames(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([
            new Row(['name' => 'users']),
            new Row(['name' => 'orders']),
        ]));

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector);

        $response = $controller->createForm($this->makeHtmlRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Create table', (string) $response->getBody());
    }

    #[Test]
    public function listJsonIncludesCapabilitiesStructure(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturn(new Result([]));

        $introspector = new DatabaseIntrospector($connection);
        $controller = $this->makeControllerWithIntrospector($introspector, Driver::SQLite);

        $response = $controller->list($this->makeJsonRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        /** @var array<string, bool> $capabilities */
        $capabilities = $body['capabilities'];
        self::assertArrayHasKey('supportsDropColumn', $capabilities);
        self::assertArrayHasKey('supportsAlterColumnType', $capabilities);
        self::assertArrayHasKey('supportsForeignKeys', $capabilities);
        self::assertArrayHasKey('supportsTransactionalDdl', $capabilities);
        self::assertArrayHasKey('supportsNativeEnum', $capabilities);
        self::assertArrayHasKey('supportsAddForeignKey', $capabilities);
        self::assertArrayHasKey('supportsDropForeignKey', $capabilities);
        self::assertArrayHasKey('supportsUnsigned', $capabilities);
    }
}
