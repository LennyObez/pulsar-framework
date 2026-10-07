<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\SchemaSnapshot;
use Pulsar\Codegen\Schema\SchemaSnapshotStoreInterface;
use Pulsar\Console\Command\Make\FromSchemaCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\Introspection\ColumnInfo;
use Pulsar\Database\Introspection\DatabaseIntrospectorInterface;
use Pulsar\Database\Introspection\TableInfo;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function random_bytes;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(FromSchemaCommand::class)]
final class FromSchemaCommandTest extends TestCase
{
    private string $tempDir;
    private DatabaseIntrospectorInterface&Stub $introspector;
    private SchemaSnapshotStoreInterface&Stub $snapshotStore;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_from_schema_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $this->snapshotStore = $this->createStub(SchemaSnapshotStoreInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function itHasTheCorrectName(): void
    {
        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        self::assertSame('make:from-schema', $command->name);
    }

    #[Test]
    public function itHasADescription(): void
    {
        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function itReturnsSuccessWhenNoTablesFound(): void
    {
        $this->introspector->method('tables')->willReturn([]);

        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            static fn(string $name, string $default = ''): string => match ($name) {
                'path' => 'src',
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn(null);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('warning');

        self::assertSame(ExitCode::Success->value, $command->execute($input, $output));
    }

    #[Test]
    public function itReturnsErrorWhenSpecificTableNotFound(): void
    {
        $this->introspector->method('tables')->willReturn([
            new TableInfo(name: 'users'),
        ]);

        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            static fn(string $name, string $default = ''): string => match ($name) {
                'path' => 'src',
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn('nonexistent');
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Error->value, $command->execute($input, $output));
    }

    #[Test]
    public function itWritesEntityJsonToDisk(): void
    {
        $this->introspector->method('tables')->willReturn([
            new TableInfo(name: 'orders'),
        ]);
        $this->introspector->method('columns')->willReturn([
            new ColumnInfo(name: 'id', type: 'integer', nullable: false, isPrimaryKey: true, default: null),
            new ColumnInfo(name: 'total', type: 'decimal', nullable: false, isPrimaryKey: false, default: null),
            new ColumnInfo(name: 'status', type: 'varchar(50)', nullable: false, isPrimaryKey: false, default: "'pending'"),
        ]);
        $this->introspector->method('primaryKey')->willReturn('id');

        $store = $this->createMock(SchemaSnapshotStoreInterface::class);
        $store->expects(self::once())->method('save');

        $command = new FromSchemaCommand($this->introspector, $store);

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            fn(string $name, string $default = ''): string => match ($name) {
                'path' => $this->tempDir,
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn(null);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        $entityFile = $this->tempDir . DIRECTORY_SEPARATOR . 'Entity' . DIRECTORY_SEPARATOR . 'Orders.json';
        self::assertFileExists($entityFile);

        $content = file_get_contents($entityFile);
        self::assertIsString($content);

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true);
        self::assertIsArray($data);
        self::assertSame('Orders', $data['className']);
        self::assertSame('orders', $data['tableName']);
        self::assertSame('id', $data['primaryKey']);
    }

    #[Test]
    public function itImportsMultipleTablesAndSavesSnapshot(): void
    {
        $this->introspector->method('tables')->willReturn([
            new TableInfo(name: 'users'),
            new TableInfo(name: 'posts'),
        ]);
        $this->introspector->method('columns')->willReturnCallback(
            static fn(string $table): array => match ($table) {
                'users' => [
                    new ColumnInfo(name: 'id', type: 'bigint', nullable: false, isPrimaryKey: true, default: null),
                    new ColumnInfo(name: 'name', type: 'varchar(255)', nullable: false, isPrimaryKey: false, default: null),
                ],
                'posts' => [
                    new ColumnInfo(name: 'id', type: 'bigint', nullable: false, isPrimaryKey: true, default: null),
                    new ColumnInfo(name: 'title', type: 'varchar(255)', nullable: false, isPrimaryKey: false, default: null),
                ],
                default => [],
            },
        );
        $this->introspector->method('primaryKey')->willReturn('id');

        $savedSnapshot = null;
        $store = $this->createMock(SchemaSnapshotStoreInterface::class);
        $store->expects(self::once())->method('save')
            ->willReturnCallback(function (SchemaSnapshot $snapshot) use (&$savedSnapshot): void {
                $savedSnapshot = $snapshot;
            });

        $command = new FromSchemaCommand($this->introspector, $store);

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            fn(string $name, string $default = ''): string => match ($name) {
                'path' => $this->tempDir,
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn(null);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('success');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        $entityDir = $this->tempDir . DIRECTORY_SEPARATOR . 'Entity';
        self::assertFileExists($entityDir . DIRECTORY_SEPARATOR . 'Users.json');
        self::assertFileExists($entityDir . DIRECTORY_SEPARATOR . 'Posts.json');

        self::assertInstanceOf(SchemaSnapshot::class, $savedSnapshot);
        self::assertArrayHasKey('users', $savedSnapshot->entities);
        self::assertArrayHasKey('posts', $savedSnapshot->entities);
    }

    #[Test]
    public function itFiltersToSingleTable(): void
    {
        $this->introspector->method('tables')->willReturn([
            new TableInfo(name: 'users'),
            new TableInfo(name: 'posts'),
        ]);
        $this->introspector->method('columns')->willReturn([
            new ColumnInfo(name: 'id', type: 'bigint', nullable: false, isPrimaryKey: true, default: null),
            new ColumnInfo(name: 'name', type: 'varchar(255)', nullable: false, isPrimaryKey: false, default: null),
        ]);
        $this->introspector->method('primaryKey')->willReturn('id');

        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            fn(string $name, string $default = ''): string => match ($name) {
                'path' => $this->tempDir,
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn('users');
        $input->method('hasOption')->willReturn(false);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        $entityDir = $this->tempDir . DIRECTORY_SEPARATOR . 'Entity';
        self::assertFileExists($entityDir . DIRECTORY_SEPARATOR . 'Users.json');
        self::assertFileDoesNotExist($entityDir . DIRECTORY_SEPARATOR . 'Posts.json');
    }

    #[Test]
    public function itSkipsExistingFilesWithoutForce(): void
    {
        $this->introspector->method('tables')->willReturn([
            new TableInfo(name: 'users'),
        ]);
        $this->introspector->method('columns')->willReturn([
            new ColumnInfo(name: 'id', type: 'integer', nullable: false, isPrimaryKey: true, default: null),
        ]);
        $this->introspector->method('primaryKey')->willReturn('id');

        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        $entityDir = $this->tempDir . DIRECTORY_SEPARATOR . 'Entity';
        mkdir($entityDir, 0o755, true);
        file_put_contents($entityDir . DIRECTORY_SEPARATOR . 'Users.json', '{"original":true}');

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            fn(string $name, string $default = ''): string => match ($name) {
                'path' => $this->tempDir,
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn(null);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('warning');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        // Original file content should be preserved
        $content = file_get_contents($entityDir . DIRECTORY_SEPARATOR . 'Users.json');
        self::assertSame('{"original":true}', $content);
    }

    #[Test]
    public function itOverwritesWithForceFlag(): void
    {
        $this->introspector->method('tables')->willReturn([
            new TableInfo(name: 'users'),
        ]);
        $this->introspector->method('columns')->willReturn([
            new ColumnInfo(name: 'id', type: 'integer', nullable: false, isPrimaryKey: true, default: null),
            new ColumnInfo(name: 'email', type: 'varchar(255)', nullable: false, isPrimaryKey: false, default: null),
        ]);
        $this->introspector->method('primaryKey')->willReturn('id');

        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        $entityDir = $this->tempDir . DIRECTORY_SEPARATOR . 'Entity';
        mkdir($entityDir, 0o755, true);
        file_put_contents($entityDir . DIRECTORY_SEPARATOR . 'Users.json', '{"original":true}');

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            fn(string $name, string $default = ''): string => match ($name) {
                'path' => $this->tempDir,
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn(null);
        $input->method('hasOption')->willReturnCallback(
            static fn(string $name): bool => $name === 'force',
        );

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        $content = file_get_contents($entityDir . DIRECTORY_SEPARATOR . 'Users.json');
        self::assertIsString($content);
        self::assertNotSame('{"original":true}', $content);

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true);
        self::assertSame('Users', $data['className']);
    }

    #[Test]
    public function itSkipsTablesWithNoColumns(): void
    {
        $this->introspector->method('tables')->willReturn([
            new TableInfo(name: 'empty_table'),
        ]);
        $this->introspector->method('columns')->willReturn([]);
        $this->introspector->method('primaryKey')->willReturn(null);

        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            fn(string $name, string $default = ''): string => match ($name) {
                'path' => $this->tempDir,
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn(null);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('warning');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);
    }

    #[Test]
    public function itCreatesEntityDirectoryIfMissing(): void
    {
        $this->introspector->method('tables')->willReturn([
            new TableInfo(name: 'products'),
        ]);
        $this->introspector->method('columns')->willReturn([
            new ColumnInfo(name: 'id', type: 'integer', nullable: false, isPrimaryKey: true, default: null),
            new ColumnInfo(name: 'name', type: 'varchar(255)', nullable: false, isPrimaryKey: false, default: null),
        ]);
        $this->introspector->method('primaryKey')->willReturn('id');

        $command = new FromSchemaCommand($this->introspector, $this->snapshotStore);

        $entityDir = $this->tempDir . DIRECTORY_SEPARATOR . 'Entity';
        self::assertDirectoryDoesNotExist($entityDir);

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            fn(string $name, string $default = ''): string => match ($name) {
                'path' => $this->tempDir,
                'namespace' => 'App\\Entity',
                default => $default,
            },
        );
        $input->method('getNullableStringOption')->willReturn(null);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);
        self::assertDirectoryExists($entityDir);
        self::assertFileExists($entityDir . DIRECTORY_SEPARATOR . 'Products.json');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
