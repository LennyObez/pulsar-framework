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
 * Generates validation rule classes from EntityDefinition property metadata.
 *
 * Maps PHP types to appropriate validation rules and produces a rules class
 * per entity with `rules()` returning a field-to-rules map.
 */
#[Api(since: '1.0.0')]
final class ValidationRuleGenerator extends AbstractGenerator
{
    private const string TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        namespace {{namespace}}\Validation;

        use Pulsar\Api\Api;

        /**
         * Validation rules for {{className}} entities.
         */
        #[Api(since: '1.0.0')]
        final readonly class {{className}}Rules
        {
            /**
             * @return array<string, list<string>>
             */
            public static function rules(): array
            {
                return [
        {{rulesBody}}
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

        $rulesBody = $this->buildRulesBody($entity);

        $variables = [
            new TemplateVariable('className', $entity->className),
            new TemplateVariable('namespace', $namespace),
            new TemplateVariable('rulesBody', $rulesBody),
        ];

        return [
            new GeneratedFile(
                targetPath: $baseDir . '/Validation/' . $entity->className . 'Rules.php',
                content: $this->renderer->render(self::TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
        ];
    }

    private function buildRulesBody(EntityDefinition $entity): string
    {
        $lines = [];

        foreach ($entity->properties as $property) {
            if ($property->isPrimaryKey) {
                continue;
            }

            $rules = $this->inferRules($property);

            if ($rules === []) {
                continue;
            }

            $rulesStr = implode("', '", $rules);
            $lines[] = "            '$property->name' => ['$rulesStr'],";
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function inferRules(PropertyDefinition $property): array
    {
        if ($property->validationRules !== []) {
            return $property->validationRules;
        }

        $rules = [];

        if (!$property->nullable) {
            $rules[] = 'required';
        }

        $rules[] = match ($property->phpType) {
            'int' => 'integer',
            'float' => 'numeric',
            'bool' => 'boolean',
            'array' => 'array',
            '\\DateTimeImmutable' => 'date',
            default => 'string',
        };

        if ($property->phpType === 'string' && $property->length !== null) {
            $rules[] = 'max:' . $property->length;
        }

        return $rules;
    }
}
