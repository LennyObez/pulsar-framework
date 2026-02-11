<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Generator;

use Override;
use Pulsar\Api\Api;
use Pulsar\Codegen\AbstractGenerator;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\OverwritePolicy;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Template\TemplateVariable;

use function implode;
use function rtrim;

/**
 * Generates test factory from EntityDefinition.
 *
 * Creates a factory class with `definition()` method returning sensible defaults
 * per property type.
 */
#[Api(since: '1.0.0')]
final class TestFactoryGenerator extends AbstractGenerator
{
    private const string TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        namespace {{namespace}}\Factory;

        use Pulsar\Api\Api;

        /**
         * Test factory for {{className}} entities.
         */
        #[Api(since: '1.0.0')]
        final class {{className}}Factory
        {
            /**
             * Default attribute values for {{className}}.
             *
             * @return array<string, mixed>
             */
            public static function definition(): array
            {
                return [
        {{definitionBody}}
                ];
            }
        }
        TPL;

    /** @return list<GeneratedFile> */
    #[Override]
    protected function doGenerate(EntityDefinition $entity, GeneratorConfig $config): array
    {
        $baseDir = rtrim($config->outputBaseDirectory, '/');
        $namespace = $config->namespacePrefix . '\\' . $entity->className;
        $policy = $config->force ? OverwritePolicy::Force : OverwritePolicy::Fail;

        $definitionBody = $this->buildDefinitionBody($entity);

        $variables = [
            new TemplateVariable('className', $entity->className),
            new TemplateVariable('namespace', $namespace),
            new TemplateVariable('definitionBody', $definitionBody),
        ];

        return [
            new GeneratedFile(
                targetPath: $baseDir . '/Factory/' . $entity->className . 'Factory.php',
                content: $this->renderer->render(self::TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
        ];
    }

    private function buildDefinitionBody(EntityDefinition $entity): string
    {
        $lines = [];

        foreach ($entity->properties as $property) {
            if ($property->isPrimaryKey) {
                continue;
            }

            $default = $this->defaultForType($property);
            $lines[] = "            '{$property->name}' => {$default},";
        }

        return implode("\n", $lines);
    }

    private function defaultForType(PropertyDefinition $property): string
    {
        if ($property->nullable) {
            return 'null';
        }

        return match ($property->phpType) {
            'string' => "'{$property->name}_value'",
            'int' => '0',
            'float' => '0.0',
            'bool' => 'false',
            'array' => '[]',
            '\\DateTimeImmutable' => "new \\DateTimeImmutable('2024-01-01')",
            default => "''",
        };
    }
}
