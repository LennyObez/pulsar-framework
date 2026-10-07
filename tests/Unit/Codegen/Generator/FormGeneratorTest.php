<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Generator\FormGenerator;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Template\TemplateRenderer;

#[CoversClass(FormGenerator::class)]
final class FormGeneratorTest extends TestCase
{
    private FormGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FormGenerator(
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
    public function generateProducesFormClass(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $file = $result->files()[0];

        self::assertStringEndsWith('UserForm.php', $file->targetPath);
        self::assertStringContainsString('class UserForm', $file->content);
        self::assertStringContainsString('function fields(): array', $file->content);
    }

    #[Test]
    public function generateIncludesCsrfField(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringContainsString("'_csrf'", $content);
        self::assertStringContainsString("'csrf'", $content);
    }

    #[Test]
    public function generateMapsPropertyTypesToFormTypes(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        // String field maps to 'text'
        self::assertStringContainsString("'name' => ['type' => 'text'", $content);
        // Bool field maps to 'checkbox'
        self::assertStringContainsString("'active' => ['type' => 'checkbox'", $content);
    }

    #[Test]
    public function generateSkipsPrimaryKeyFields(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringNotContainsString("'id' => ['type'", $content);
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
                    validationRules: ['required'],
                    isFilterable: true,
                    isSortable: true,
                    length: 255,
                    isPrimaryKey: false,
                ),
                new PropertyDefinition(
                    name: 'active',
                    phpType: 'bool',
                    columnName: 'active',
                    columnType: 'boolean',
                    nullable: false,
                    hasDefault: true,
                    defaultValue: true,
                    validationRules: [],
                    isFilterable: true,
                    isSortable: false,
                    length: null,
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
