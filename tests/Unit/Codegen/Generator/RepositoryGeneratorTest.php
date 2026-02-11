<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Generator\RepositoryGenerator;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Template\TemplateRenderer;

#[CoversClass(RepositoryGenerator::class)]
final class RepositoryGeneratorTest extends TestCase
{
    private RepositoryGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new RepositoryGenerator(
            new TemplateRenderer(),
            new PathValidator('/project'),
        );
    }

    #[Test]
    public function generateProducesTwoFiles(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);

        self::assertCount(2, $result->files());
    }

    #[Test]
    public function generateProducesInterfaceFile(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $files = $result->files();

        $interface = $files[0];
        self::assertStringEndsWith('UserRepositoryInterface.php', $interface->targetPath);
        self::assertStringContainsString('interface UserRepositoryInterface', $interface->content);
        self::assertStringContainsString('function find(int $id)', $interface->content);
        self::assertStringContainsString('function findAll(): array', $interface->content);
        self::assertStringContainsString('function create(array $data)', $interface->content);
        self::assertStringContainsString('function update(int $id, array $data)', $interface->content);
        self::assertStringContainsString('function delete(int $id)', $interface->content);
    }

    #[Test]
    public function generateProducesImplementationFile(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $files = $result->files();

        $impl = $files[1];
        self::assertStringEndsWith('UserRepository.php', $impl->targetPath);
        self::assertStringContainsString('class UserRepository implements UserRepositoryInterface', $impl->content);
        self::assertStringContainsString('ConnectionInterface', $impl->content);
        self::assertStringContainsString('SELECT * FROM users', $impl->content);
    }

    #[Test]
    public function generateUsesCorrectPrimaryKeyType(): void
    {
        $entity = new EntityDefinition(
            className: 'Order',
            namespace: 'App\\Entity',
            tableName: 'orders',
            properties: [
                new PropertyDefinition(
                    name: 'uuid',
                    phpType: 'string',
                    columnName: 'uuid',
                    columnType: 'uuid',
                    nullable: false,
                    hasDefault: false,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: true,
                    isSortable: true,
                    length: null,
                    isPrimaryKey: true,
                ),
            ],
            relationships: [],
            primaryKey: 'uuid',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );

        $config = new GeneratorConfig('/project/src');
        $result = $this->generator->generate($entity, $config);

        $interface = $result->files()[0];
        self::assertStringContainsString('function find(string $id)', $interface->content);
        self::assertStringContainsString('function delete(string $id)', $interface->content);
    }

    #[Test]
    public function generateProducesCustomFindersForFilterableColumns(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);

        $interface = $result->files()[0];
        self::assertStringContainsString('findByName', $interface->content);

        $impl = $result->files()[1];
        self::assertStringContainsString('findByName', $impl->content);
        self::assertStringContainsString('WHERE name = :value', $impl->content);
    }

    #[Test]
    public function generateIsDeterministic(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result1 = $this->generator->generate($entity, $config);
        $result2 = $this->generator->generate($entity, $config);

        self::assertSame($result1->files()[0]->content, $result2->files()[0]->content);
        self::assertSame($result1->files()[1]->content, $result2->files()[1]->content);
    }

    #[Test]
    public function generateUsesParameterizedQueries(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $impl = $result->files()[1];

        self::assertStringContainsString(':id', $impl->content);
        self::assertStringContainsString(':__pk_id', $impl->content);
        self::assertStringNotContainsString('$id"', $impl->content);
    }

    #[Test]
    public function implementationContainsNoTodos(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);

        foreach ($result->files() as $file) {
            self::assertStringNotContainsString('TODO', $file->content);
        }
    }

    #[Test]
    public function generateRespectsCustomNamespacePrefix(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src', namespacePrefix: 'Domain\\Modules');

        $result = $this->generator->generate($entity, $config);
        $interface = $result->files()[0];

        self::assertStringContainsString('namespace Domain\\Modules\\Contracts;', $interface->content);
    }

    #[Test]
    public function interfaceContainsStrictTypes(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);

        foreach ($result->files() as $file) {
            self::assertStringContainsString('declare(strict_types=1);', $file->content);
        }
    }

    #[Test]
    public function generateDoesNotCreateFindersForNonFilterableColumns(): void
    {
        $entity = new EntityDefinition(
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
                    hasDefault: true,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: false,
                    isSortable: true,
                    length: null,
                    isPrimaryKey: true,
                ),
                new PropertyDefinition(
                    name: 'password',
                    phpType: 'string',
                    columnName: 'password',
                    columnType: 'varchar(255)',
                    nullable: false,
                    hasDefault: false,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: false,
                    isSortable: false,
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

        $config = new GeneratorConfig('/project/src');
        $result = $this->generator->generate($entity, $config);
        $interface = $result->files()[0];

        self::assertStringNotContainsString('findByPassword', $interface->content);
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
                    validationRules: ['required', 'max:255'],
                    isFilterable: true,
                    isSortable: true,
                    length: 255,
                    isPrimaryKey: false,
                ),
            ],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: true,
            hasSoftDeletes: false,
            isAuditAware: false,
        );
    }
}
