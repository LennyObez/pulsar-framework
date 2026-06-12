<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Schema\SchemaDiff;
use Pulsar\Codegen\Schema\SchemaSnapshot;
use Pulsar\Codegen\Schema\SchemaSnapshotStoreInterface;
use Pulsar\Codegen\Template\TemplateRenderer;
use Pulsar\Console\Command\Make\MigrationDiffCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function str_contains;

#[CoversClass(MigrationDiffCommand::class)]
final class MigrationDiffCommandTest extends TestCase
{
    #[Test]
    public function itHasTheCorrectName(): void
    {
        $command = $this->createCommand(
            new SchemaSnapshot(entities: [], version: '1'),
        );

        self::assertSame('make:migration-diff', $command->name);
    }

    #[Test]
    public function itHasADescription(): void
    {
        $command = $this->createCommand(
            new SchemaSnapshot(entities: [], version: '1'),
        );

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function itReturnsSuccessWhenNoChangesDetected(): void
    {
        $entity = $this->createEntity();
        $snapshot = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );

        // Store returns the same snapshot => no diff
        $store = $this->createStub(SchemaSnapshotStoreInterface::class);
        $store->method('load')->willReturn($snapshot);

        $command = $this->createCommand($snapshot, $store);

        $input = $this->createDefaultInput();
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('info');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);
    }

    #[Test]
    public function itGeneratesMigrationWhenChangesDetected(): void
    {
        $entity = $this->createEntity();
        $currentSnapshot = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );

        // No previous snapshot — everything is new
        $store = $this->createMock(SchemaSnapshotStoreInterface::class);
        $store->method('load')->willReturn(null);
        $store->expects(self::once())->method('save');

        $command = $this->createCommand($currentSnapshot, $store);

        $input = $this->createDefaultInput();
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('success');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);
    }

    #[Test]
    public function itSavesSnapshotAfterGeneration(): void
    {
        $entity = $this->createEntity();
        $currentSnapshot = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );

        $store = $this->createMock(SchemaSnapshotStoreInterface::class);
        $store->method('load')->willReturn(null);
        $store->expects(self::once())->method('save')->with($currentSnapshot);

        $command = $this->createCommand($currentSnapshot, $store);

        $input = $this->createDefaultInput();
        $output = $this->createStub(OutputInterface::class);

        $command->execute($input, $output);
    }

    #[Test]
    public function itReportsOperationCount(): void
    {
        $entity = $this->createEntity();
        $currentSnapshot = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );

        $store = $this->createStub(SchemaSnapshotStoreInterface::class);
        $store->method('load')->willReturn(null);

        $command = $this->createCommand($currentSnapshot, $store);

        $input = $this->createDefaultInput();

        $writtenLines = [];
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(
            static function (string $line) use (&$writtenLines): void {
                $writtenLines[] = $line;
            },
        );

        $command->execute($input, $output);

        // Should report operation count
        $hasCount = false;
        foreach ($writtenLines as $line) {
            if (str_contains($line, 'operation')) {
                $hasCount = true;
                break;
            }
        }
        self::assertTrue($hasCount);
    }

    #[Test]
    public function itUsesCustomMigrationName(): void
    {
        $entity = $this->createEntity();
        $currentSnapshot = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );

        $store = $this->createStub(SchemaSnapshotStoreInterface::class);
        $store->method('load')->willReturn(null);

        $command = $this->createCommand($currentSnapshot, $store);

        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            static fn(string $name, string $default = ''): string => match ($name) {
                'path' => '/project',
                'name' => 'create_users_table',
                default => $default,
            },
        );
        $input->method('hasOption')->willReturn(false);

        $writtenLines = [];
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(
            static function (string $line) use (&$writtenLines): void {
                $writtenLines[] = $line;
            },
        );

        $command->execute($input, $output);

        // Should contain the custom name in generated path
        $hasCustomName = false;
        foreach ($writtenLines as $line) {
            if (str_contains($line, 'create_users_table')) {
                $hasCustomName = true;
                break;
            }
        }
        self::assertTrue($hasCustomName);
    }

    #[Test]
    public function itDoesNotSaveSnapshotWhenNoChanges(): void
    {
        $entity = $this->createEntity();
        $snapshot = new SchemaSnapshot(
            entities: ['users' => $entity],
            version: '1',
        );

        $store = $this->createMock(SchemaSnapshotStoreInterface::class);
        $store->method('load')->willReturn($snapshot);
        $store->expects(self::never())->method('save');

        $command = $this->createCommand($snapshot, $store);

        $input = $this->createDefaultInput();
        $output = $this->createStub(OutputInterface::class);

        $command->execute($input, $output);
    }

    private function createCommand(
        SchemaSnapshot $currentSnapshot,
        ?SchemaSnapshotStoreInterface $store = null,
    ): MigrationDiffCommand {
        $store ??= $this->createStub(SchemaSnapshotStoreInterface::class);

        return new MigrationDiffCommand(
            snapshotStore: $store,
            schemaDiff: new SchemaDiff(),
            currentSnapshot: $currentSnapshot,
            renderer: new TemplateRenderer(),
            pathValidator: new PathValidator('/project', ['database/']),
        );
    }

    private function createDefaultInput(): InputInterface
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('getStringOption')->willReturnCallback(
            static fn(string $name, string $default = ''): string => $name === 'path' ? '/project' : $default,
        );
        $input->method('hasOption')->willReturn(false);

        return $input;
    }

    private function createEntity(): EntityDefinition
    {
        return new EntityDefinition(
            className: 'User',
            namespace: 'App\\Entity',
            tableName: 'users',
            properties: [
                new PropertyDefinition(
                    name: 'id',
                    phpType: 'int',
                    columnName: 'id',
                    columnType: 'bigint',
                    nullable: false,
                    hasDefault: false,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: true,
                    isSortable: true,
                    length: null,
                    isPrimaryKey: true,
                ),
                new PropertyDefinition(
                    name: 'name',
                    phpType: 'string',
                    columnName: 'name',
                    columnType: 'varchar(255)',
                    nullable: false,
                    hasDefault: false,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: true,
                    isSortable: true,
                    length: 255,
                    isPrimaryKey: false,
                ),
            ],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );
    }
}
