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
 * Generates form builder configuration from EntityDefinition.
 *
 * Creates a form class with fields matching entity properties.
 * Includes CSRF protection by default.
 */
#[Api(since: '1.0.0')]
final class FormGenerator extends AbstractGenerator
{
    private const string TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        namespace {{namespace}}\Form;

        use Pulsar\Api\Api;

        /**
         * Form definition for {{className}} entities.
         */
        #[Api(since: '1.0.0')]
        final readonly class {{className}}Form
        {
            /**
             * Build the form field configuration.
             *
             * @return array<string, array<string, mixed>>
             */
            public static function fields(): array
            {
                return [
                    '_csrf' => ['type' => 'csrf'],
        {{fieldsBody}}
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

        $fieldsBody = $this->buildFieldsBody($entity);

        $variables = [
            new TemplateVariable('className', $entity->className),
            new TemplateVariable('namespace', $namespace),
            new TemplateVariable('fieldsBody', $fieldsBody),
        ];

        return [
            new GeneratedFile(
                targetPath: $baseDir . '/Form/' . $entity->className . 'Form.php',
                content: $this->renderer->render(self::TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
        ];
    }

    private function buildFieldsBody(EntityDefinition $entity): string
    {
        $lines = [];

        foreach ($entity->properties as $property) {
            if ($property->isPrimaryKey) {
                continue;
            }

            $formType = $this->mapToFormType($property);
            $required = $property->nullable ? 'false' : 'true';
            $lines[] = "            '{$property->name}' => ['type' => '{$formType}', 'required' => {$required}],";
        }

        return implode("\n", $lines);
    }

    private function mapToFormType(PropertyDefinition $property): string
    {
        return match ($property->phpType) {
            'string' => $this->inferStringFormType($property),
            'int', 'float' => 'number',
            'bool' => 'checkbox',
            'array' => 'textarea',
            '\\DateTimeImmutable' => 'datetime',
            default => 'text',
        };
    }

    private function inferStringFormType(PropertyDefinition $property): string
    {
        $column = $property->columnType;

        if ($column === 'text' || $column === 'mediumtext' || $column === 'longtext') {
            return 'textarea';
        }

        return 'text';
    }
}
