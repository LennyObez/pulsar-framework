<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Generator\ValidationRuleGenerator;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Template\TemplateRenderer;

#[CoversClass(ValidationRuleGenerator::class)]
final class ValidationRuleGeneratorTest extends TestCase
{
    private ValidationRuleGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ValidationRuleGenerator(
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
    public function generateProducesRulesClass(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $file = $result->files()[0];

        self::assertStringEndsWith('UserRules.php', $file->targetPath);
        self::assertStringContainsString('class UserRules', $file->content);
        self::assertStringContainsString('function rules(): array', $file->content);
    }

    #[Test]
    public function generateIncludesPropertyRules(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringContainsString("'name'", $content);
        self::assertStringContainsString("'required'", $content);
        self::assertStringContainsString("'max:255'", $content);
    }

    #[Test]
    public function generateSkipsPrimaryKeyFields(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        // Primary key 'id' should not be in the rules
        self::assertStringNotContainsString("'id' =>", $content);
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
                    validationRules: ['required', 'max:255'],
                    isFilterable: true,
                    isSortable: true,
                    length: 255,
                    isPrimaryKey: false,
                ),
                new PropertyDefinition(
                    name: 'age',
                    phpType: 'int',
                    columnName: 'age',
                    columnType: 'integer',
                    nullable: true,
                    hasDefault: false,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: false,
                    isSortable: true,
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
