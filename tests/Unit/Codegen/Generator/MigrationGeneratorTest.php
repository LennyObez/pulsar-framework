<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Generator\MigrationGenerator;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\DiffResult;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\SchemaOperation;
use Pulsar\Codegen\Schema\SchemaOperationType;
use Pulsar\Codegen\Template\TemplateRenderer;

#[CoversClass(MigrationGenerator::class)]
final class MigrationGeneratorTest extends TestCase
{
    #[Test]
    public function generateReturnsEmptyWhenNoDiffResult(): void
    {
        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);

        self::assertCount(0, $result->files());
    }

    #[Test]
    public function generateReturnsEmptyWhenNoChanges(): void
    {
        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            new DiffResult([]),
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);

        self::assertCount(0, $result->files());
    }

    #[Test]
    public function generateProducesMigrationFileForCreateTable(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);

        self::assertCount(1, $result->files());

        $file = $result->files()[0];
        self::assertStringContainsString('database/migrations/', $file->targetPath);
        self::assertStringContainsString('20240115120000', $file->targetPath);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS users', $file->content);
    }

    #[Test]
    public function generateProducesUpAndDownMethods(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringContainsString('function up(', $content);
        self::assertStringContainsString('function down(', $content);
        self::assertStringContainsString('DROP TABLE IF EXISTS users', $content);
    }

    #[Test]
    public function generateHandlesAddColumnOperation(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(
                SchemaOperationType::AddColumn,
                'users',
                'email',
                ['columnType' => 'varchar(255)'],
            ),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringContainsString('ADD COLUMN email', $content);
    }

    #[Test]
    public function generateHandlesAddIndexOperation(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(
                SchemaOperationType::AddIndex,
                'users',
                'email',
                ['indexName' => 'users_email_idx'],
            ),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringContainsString('CREATE INDEX users_email_idx ON users (email)', $content);
    }

    #[Test]
    public function generateHandlesForeignKeyOperation(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(
                SchemaOperationType::AddForeignKey,
                'orders',
                'user_id',
                ['relatedEntity' => 'User', 'localKey' => 'id'],
            ),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringContainsString('FOREIGN KEY (user_id)', $content);
        self::assertStringContainsString('REFERENCES', $content);
    }

    #[Test]
    public function generateIsDeterministic(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
        ]);

        $gen1 = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $gen2 = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result1 = $gen1->generate($entity, $config);
        $result2 = $gen2->generate($entity, $config);

        self::assertSame($result1->files()[0]->content, $result2->files()[0]->content);
    }

    #[Test]
    public function generateUsesMigrationInterfacePattern(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        // Must use anonymous class implementing MigrationInterface
        self::assertStringContainsString('implements MigrationInterface', $content);
        self::assertStringContainsString('use Pulsar\\Database\\ConnectionInterface;', $content);
        self::assertStringContainsString('use Pulsar\\Database\\Migration\\MigrationInterface;', $content);
    }

    #[Test]
    public function generateUsesConnectionExecute(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        // Must use $connection->execute() not $this->schema->
        self::assertStringContainsString('$connection->execute(', $content);
        self::assertStringNotContainsString('$this->schema', $content);
    }

    #[Test]
    public function generateContainsNoTodos(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);

        foreach ($result->files() as $file) {
            self::assertStringNotContainsString('TODO', $file->content);
        }
    }

    #[Test]
    public function generateHandlesDropColumnOperation(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(
                SchemaOperationType::DropColumn,
                'users',
                'legacy_field',
            ),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        // up() should drop the column
        self::assertStringContainsString('DROP COLUMN legacy_field', $content);
        // down() should add the column back
        self::assertStringContainsString('ADD COLUMN legacy_field', $content);
    }

    #[Test]
    public function generateHandlesModifyColumnOperation(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(
                SchemaOperationType::ModifyColumn,
                'users',
                'name',
                [
                    'oldType' => 'varchar(100)',
                    'newType' => 'varchar(255)',
                    'oldNullable' => false,
                    'newNullable' => true,
                ],
            ),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        // up() should use newType
        self::assertStringContainsString('ALTER TABLE users ALTER COLUMN name TYPE VARCHAR(255)', $content);

        // down() should use oldType
        self::assertStringContainsString('ALTER TABLE users ALTER COLUMN name TYPE VARCHAR(100)', $content);
    }

    #[Test]
    public function generateUsesCustomMigrationName(): void
    {
        $diff = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
        ]);

        $generator = new MigrationGenerator(
            new TemplateRenderer(),
            new PathValidator('/project', ['database/']),
            $diff,
            '20240115120000',
            'create_users_table',
        );

        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project');

        $result = $generator->generate($entity, $config);
        $file = $result->files()[0];

        self::assertStringContainsString('create_users_table', $file->targetPath);
    }

    private function createEntity(): EntityDefinition
    {
        return new EntityDefinition(
            className: 'User',
            namespace: 'App\\Entity',
            tableName: 'users',
            properties: [],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: true,
            hasSoftDeletes: false,
            isAuditAware: false,
        );
    }
}
