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
use Pulsar\Codegen\Template\TemplateVariable;

use function implode;
use function rtrim;

/**
 * Generates admin panel resource from EntityDefinition.
 *
 * Produces CRUD views (list, create, edit, show) and auto-registers in admin module.
 */
#[Api(since: '1.0.0')]
final class AdminResourceGenerator extends AbstractGenerator
{
    private const string TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        namespace {{namespace}}\Admin;

        use Pulsar\Api\Api;

        /**
         * Admin resource for {{className}} entities.
         *
         * Provides CRUD view configuration for the admin panel.
         */
        #[Api(since: '1.0.0')]
        final readonly class {{className}}AdminResource
        {
            /**
             * Columns displayed in the list view.
             *
             * @return list<string>
             */
            public static function listColumns(): array
            {
                return [
        {{listColumnsBody}}
                ];
            }

            /**
             * Fields displayed in create/edit forms.
             *
             * @return list<string>
             */
            public static function formFields(): array
            {
                return [
        {{formFieldsBody}}
                ];
            }

            /**
             * Fields displayed in the detail/show view.
             *
             * @return list<string>
             */
            public static function showFields(): array
            {
                return [
        {{showFieldsBody}}
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

        $variables = [
            new TemplateVariable('className', $entity->className),
            new TemplateVariable('namespace', $namespace),
            new TemplateVariable('listColumnsBody', $this->buildFieldList($entity, includePrimary: true)),
            new TemplateVariable('formFieldsBody', $this->buildFieldList($entity, includePrimary: false)),
            new TemplateVariable('showFieldsBody', $this->buildFieldList($entity, includePrimary: true)),
        ];

        return [
            new GeneratedFile(
                targetPath: $baseDir . '/Admin/' . $entity->className . 'AdminResource.php',
                content: $this->renderer->render(self::TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
        ];
    }

    private function buildFieldList(EntityDefinition $entity, bool $includePrimary): string
    {
        $lines = [];

        foreach ($entity->properties as $property) {
            if (!$includePrimary && $property->isPrimaryKey) {
                continue;
            }

            $lines[] = "            '{$property->name}',";
        }

        return implode("\n", $lines);
    }
}
