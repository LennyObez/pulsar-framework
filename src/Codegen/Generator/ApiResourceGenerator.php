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
use function in_array;
use function rtrim;

/**
 * Generates API resource classes from EntityDefinition.
 *
 * Uses deny-by-default field exposure: only explicitly exposed fields are visible.
 * Generates `#[Expose]`, `#[Filterable]`, `#[Sortable]` attributes based on property metadata.
 * @api
 */
#[Api(since: '1.0.0')]
final class ApiResourceGenerator extends AbstractGenerator
{
    private const string TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        namespace {{namespace}}\Api;

        use Pulsar\Api\Api;

        /**
         * API resource for {{className}} entities.
         *
         * Deny-by-default: only explicitly listed fields are exposed.
         */
        #[Api(since: '1.0.0')]
        final readonly class {{className}}Resource
        {
            /**
             * Fields exposed in API responses.
             *
             * @return array<string, array{expose: bool, filterable: bool, sortable: bool}>
             */
            public static function fields(): array
            {
                return [
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
                targetPath: $baseDir . '/Api/' . $entity->className . 'Resource.php',
                content: $this->renderer->render(self::TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
        ];
    }

    private function buildFieldsBody(EntityDefinition $entity): string
    {
        $lines = [];

        foreach ($entity->properties as $property) {
            $expose = $this->shouldExpose($property) ? 'true' : 'false';
            $filterable = $property->isFilterable ? 'true' : 'false';
            $sortable = $property->isSortable ? 'true' : 'false';

            $lines[] = "            '$property->name' => ['expose' => $expose, 'filterable' => $filterable, 'sortable' => $sortable],";
        }

        return implode("\n", $lines);
    }

    /**
     * Deny-by-default: only non-primary-key, non-audit fields are exposed.
     */
    private function shouldExpose(PropertyDefinition $property): bool
    {
        if ($property->isPrimaryKey) {
            return true;
        }

        // Audit columns are not exposed by default
        return !in_array($property->columnName, ['created_by', 'updated_by', 'deleted_at'], true);
    }
}
