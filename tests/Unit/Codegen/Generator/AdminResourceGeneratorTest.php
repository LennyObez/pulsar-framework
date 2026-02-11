<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Generator\AdminResourceGenerator;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Template\TemplateRenderer;

#[CoversClass(AdminResourceGenerator::class)]
final class AdminResourceGeneratorTest extends TestCase
{
    private AdminResourceGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new AdminResourceGenerator(
            new TemplateRenderer(),
            new PathValidator('/project'),
        );
    }

    #[Test]
    public function generateProducesOneFile(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);

        self::assertCount(1, $result->files());
    }

    #[Test]
    public function generateProducesAdminResourceClass(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $file = $result->files()[0];

        self::assertStringEndsWith('UserAdminResource.php', $file->targetPath);
        self::assertStringContainsString('class UserAdminResource', $file->content);
    }

    #[Test]
    public function generateIncludesListColumnsShowAndFormFields(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringContainsString('listColumns()', $content);
        self::assertStringContainsString('formFields()', $content);
        self::assertStringContainsString('showFields()', $content);
    }

    #[Test]
    public function listColumnsIncludesPrimaryKey(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        // listColumns includes 'id'
        self::assertStringContainsString("'id'", $content);
    }

    #[Test]
    public function generateIsDeterministic(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result1 = $this->generator->generate($entity, $config);
        $result2 = $this->generator->generate($entity, $config);

        self::assertSame($result1->files()[0]->content, $result2->files()[0]->content);
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
