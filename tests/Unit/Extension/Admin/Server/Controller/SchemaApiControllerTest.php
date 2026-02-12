<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Result;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Features\Schema\AlterTableHandler;
use Pulsar\Extension\Admin\Features\Schema\CreateTableHandler;
use Pulsar\Extension\Admin\Features\Schema\DropTableHandler;
use Pulsar\Extension\Admin\Features\Schema\PreviewDdlHandler;
use Pulsar\Extension\Admin\Features\Schema\RenameTableHandler;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogEntry;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Extension\Admin\Server\Controller\SchemaApiController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(SchemaApiController::class)]
final class SchemaApiControllerTest extends TestCase
{
    /** @var SchemaChangeLogStoreInterface&Stub */
    private SchemaChangeLogStoreInterface $changeLog;
    private SchemaApiController $controller;

    protected function setUp(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        // For DatabaseIntrospector::tables() on MySQL - return empty tables list
        $connection->method('query')->willReturn(new Result([]));
        // For SchemaManager::executeStatements() - return 0 affected rows
        $connection->method('execute')->willReturn(0);

        $capabilities = new SchemaCapabilities(Driver::MySQL);
        $ddlCompiler = new DdlCompiler(Driver::MySQL, $capabilities);
        $schemaManager = new SchemaManager($connection, $ddlCompiler, $capabilities);
        $introspector = new DatabaseIntrospector($connection);

        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $auditLogger->method('log')->willReturn(new AuditEntry(
            id: 'test-entry',
            event: AuditEvent::SchemaModification,
            outcome: AuditOutcome::Success,
            actor: 'admin',
            action: 'schema.test',
            resource: 'test_tbl',
            timestamp: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            metadata: [],
            previousHmac: '',
            hmac: 'testhash',
        ));

        $this->changeLog = $this->createStub(SchemaChangeLogStoreInterface::class);
        $config = new AdminSchemaConfig(enabled: true);

        $createHandler = new CreateTableHandler(
            $schemaManager,
            $introspector,
            $auditLogger,
            $this->changeLog,
            $config,
        );

        $alterHandler = new AlterTableHandler(
            $schemaManager,
            $capabilities,
            $auditLogger,
            $this->changeLog,
            $config,
        );

        $dropHandler = new DropTableHandler(
            $schemaManager,
            $introspector,
            $auditLogger,
            $this->changeLog,
            $config,
        );

        $renameHandler = new RenameTableHandler(
            $schemaManager,
            $introspector,
            $auditLogger,
            $this->changeLog,
            $config,
        );

        $previewHandler = new PreviewDdlHandler(
            $schemaManager,
            $capabilities,
        );

        $this->controller = new SchemaApiController(
            $createHandler,
            $alterHandler,
            $dropHandler,
            $renameHandler,
            $previewHandler,
            $this->changeLog,
        );
    }

    /**
     * @param array<string, mixed> $bodyData
     */
    private function makeJsonRequest(
        Method $method,
        string $uri = '/admin/api/schema',
        array $bodyData = [],
    ): Request {
        $body = json_encode($bodyData, JSON_THROW_ON_ERROR);

        return new Request(
            method: $method,
            uri: $uri,
            path: $uri,
            queryString: '',
            headers: new HeaderBag([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            body: $body,
        );
    }

    #[Test]
    public function createRequiresReasonOfMinFiveChars(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'reason' => 'hi',
            'name' => 'test_tbl',
            'columns' => [],
        ]);

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('reason of at least 5 characters');

        $this->controller->create($request);
    }

    #[Test]
    public function dropTableRequiresReasonOfMinFiveChars(): void
    {
        $request = $this->makeJsonRequest(Method::DELETE, bodyData: ['reason' => 'xy']);

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('reason of at least 5 characters');

        $this->controller->dropTable($request, 'test_tbl');
    }

    #[Test]
    public function createTableWithValidReasonSucceeds(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'reason' => 'Adding users table for the application',
            'name' => 'my_users',
            'columns' => [
                ['name' => 'user_id', 'type' => 'integer', 'primary_key' => true, 'auto_increment' => true],
                ['name' => 'user_name', 'type' => 'string', 'length' => 100],
            ],
        ]);

        $response = $this->controller->create($request);

        // Should succeed with 201 Created
        self::assertSame(201, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertTrue($body['success']);
        self::assertIsString($body['message']);
        self::assertStringContainsString('my_users', $body['message']);
    }

    #[Test]
    public function changelogReturnsJsonArray(): void
    {
        $entry = new SchemaChangeLogEntry(
            id: 'log-1',
            operation: 'CREATE',
            table: 'my_users',
            actor: 'admin',
            reason: 'Initial schema',
            timestamp: 1700000000,
            statements: ['CREATE TABLE my_users (user_id INTEGER)'],
            evidenceHash: 'abc123',
            correlationId: null,
            success: true,
        );

        $this->changeLog->method('recent')->willReturn([$entry]);

        $request = new Request(
            method: Method::GET,
            uri: '/admin/api/schema/changelog',
            path: '/admin/api/schema/changelog',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
        );

        $response = $this->controller->changelog($request);

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('entries', $body);
        /** @var list<array<string, mixed>> $entries */
        $entries = $body['entries'];
        self::assertCount(1, $entries);
        self::assertSame('log-1', $entries[0]['id']);
        self::assertSame('CREATE', $entries[0]['operation']);
        self::assertSame('my_users', $entries[0]['table']);
        self::assertTrue($entries[0]['success']);
    }

    #[Test]
    public function exportBundleReturnsPlainText(): void
    {
        $this->changeLog->method('exportSqlBundle')
            ->willReturn("-- Schema Bundle\nCREATE TABLE my_users (user_id INTEGER);");

        $request = new Request(
            method: Method::GET,
            uri: '/admin/api/schema/changelog/export',
            path: '/admin/api/schema/changelog/export',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $response = $this->controller->exportBundle($request);

        self::assertSame(200, $response->status->value);
        self::assertStringContainsString('CREATE TABLE my_users', $response->body);
    }

    #[Test]
    public function previewCreateReturnsStatements(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'name' => 'test_tbl',
            'columns' => [
                ['name' => 'row_id', 'type' => 'integer', 'primary_key' => true],
            ],
        ]);

        $response = $this->controller->previewCreate($request);

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('statements', $body);
        /** @var list<string> $statements */
        $statements = $body['statements'];
        self::assertNotEmpty($statements);
        self::assertStringContainsString('CREATE TABLE', $statements[0]);
    }

    #[Test]
    public function previewDropTableReturnsStatements(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/admin/api/schema/preview/test_tbl/drop',
            path: '/admin/api/schema/preview/test_tbl/drop',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
        );

        $response = $this->controller->previewDropTable($request, 'test_tbl');

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('statements', $body);
        /** @var list<string> $statements */
        $statements = $body['statements'];
        self::assertNotEmpty($statements);
        self::assertStringContainsString('DROP TABLE', $statements[0]);
    }

    #[Test]
    public function previewRenameTableReturnsStatements(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'new_name' => 'renamed_tbl',
        ]);

        $response = $this->controller->previewRenameTable($request, 'old_tbl');

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('statements', $body);
        /** @var list<string> $statements */
        $statements = $body['statements'];
        self::assertNotEmpty($statements);
    }

    #[Test]
    public function renameTableRequiresReasonOfMinFiveChars(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'reason' => 'xyz',
            'new_name' => 'renamed_tbl',
        ]);

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('reason of at least 5 characters');

        $this->controller->renameTable($request, 'old_tbl');
    }

    #[Test]
    public function addColumnWithValidReasonSucceeds(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'reason' => 'Adding email column for notifications',
            'name' => 'email',
            'type' => 'string',
            'length' => 255,
        ]);

        $response = $this->controller->addColumn($request, 'test_tbl');

        self::assertSame(201, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function dropColumnRequiresReason(): void
    {
        $request = $this->makeJsonRequest(Method::DELETE, bodyData: ['reason' => 'no']);

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('reason of at least 5 characters');

        $this->controller->dropColumn($request, 'test_tbl', 'old_col');
    }

    #[Test]
    public function addIndexWithValidReasonSucceeds(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'reason' => 'Adding index for faster lookups',
            'name' => 'idx_email',
            'columns' => ['email'],
        ]);

        $response = $this->controller->addIndex($request, 'test_tbl');

        self::assertSame(201, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function dropIndexRequiresReason(): void
    {
        $request = $this->makeJsonRequest(Method::DELETE, bodyData: ['reason' => 'ab']);

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('reason of at least 5 characters');

        $this->controller->dropIndex($request, 'test_tbl', 'idx_old');
    }

    #[Test]
    public function previewAddColumnReturnsStatements(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'name' => 'email',
            'type' => 'string',
            'length' => 255,
        ]);

        $response = $this->controller->previewAddColumn($request, 'test_tbl');

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('statements', $body);
        /** @var list<string> $statements */
        $statements = $body['statements'];
        self::assertNotEmpty($statements);
        self::assertStringContainsString('ALTER TABLE', $statements[0]);
    }

    #[Test]
    public function previewDropColumnReturnsStatements(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/admin/api/schema/preview/test_tbl/column/drop',
            path: '/admin/api/schema/preview/test_tbl/column/drop',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
        );

        $response = $this->controller->previewDropColumn($request, 'test_tbl');

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('statements', $body);
        /** @var list<string> $statements */
        $statements = $body['statements'];
        self::assertNotEmpty($statements);
    }

    #[Test]
    public function previewAddIndexReturnsStatements(): void
    {
        $request = $this->makeJsonRequest(Method::POST, bodyData: [
            'name' => 'idx_email',
            'columns' => ['email'],
        ]);

        $response = $this->controller->previewAddIndex($request, 'test_tbl');

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('statements', $body);
        /** @var list<string> $statements */
        $statements = $body['statements'];
        self::assertNotEmpty($statements);
        self::assertStringContainsString('CREATE INDEX', $statements[0]);
    }

    #[Test]
    public function previewDropIndexReturnsStatements(): void
    {
        $request = new Request(
            method: Method::POST,
            uri: '/admin/api/schema/preview/test_tbl/index/drop',
            path: '/admin/api/schema/preview/test_tbl/index/drop',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
        );

        $response = $this->controller->previewDropIndex($request, 'test_tbl');

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertArrayHasKey('statements', $body);
        /** @var list<string> $statements */
        $statements = $body['statements'];
        self::assertNotEmpty($statements);
    }
}
