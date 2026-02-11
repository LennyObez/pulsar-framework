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

use function lcfirst;
use function rtrim;

/**
 * Generates RBAC policy from EntityDefinition.
 *
 * DENY-BY-DEFAULT: all operations (view, create, update, delete) return false.
 * Developers must explicitly grant access per operation.
 */
#[Api(since: '1.0.0')]
final class PolicyGenerator extends AbstractGenerator
{
    private const string TEMPLATE = <<<'TPL'
        <?php

        declare(strict_types=1);

        namespace {{namespace}}\Policy;

        use Pulsar\Api\Api;

        /**
         * Authorization policy for {{className}} entities.
         *
         * DENY-BY-DEFAULT: All operations return false until explicitly granted.
         */
        #[Api(since: '1.0.0')]
        final readonly class {{className}}Policy
        {
            /**
             * Whether the user can view any {{entityVar}} entities.
             */
            public function viewAny(): bool
            {
                return false;
            }

            /**
             * Whether the user can view a specific {{entityVar}}.
             */
            public function view(): bool
            {
                return false;
            }

            /**
             * Whether the user can create a new {{entityVar}}.
             */
            public function create(): bool
            {
                return false;
            }

            /**
             * Whether the user can update a specific {{entityVar}}.
             */
            public function update(): bool
            {
                return false;
            }

            /**
             * Whether the user can delete a specific {{entityVar}}.
             */
            public function delete(): bool
            {
                return false;
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
            new TemplateVariable('entityVar', lcfirst($entity->className)),
        ];

        return [
            new GeneratedFile(
                targetPath: $baseDir . '/Policy/' . $entity->className . 'Policy.php',
                content: $this->renderer->render(self::TEMPLATE, $variables),
                overwritePolicy: $policy,
            ),
        ];
    }
}
