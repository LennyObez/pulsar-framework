<?php

declare(strict_types=1);

namespace Pulsar\Codegen;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Template\TemplateRenderer;

/**
 * Base class for code generators providing common infrastructure:
 * template rendering, path validation, and conflict detection.
 *
 * Subclasses implement `doGenerate()` to produce the list of generated files.
 * @api
 */
#[Api(since: '1.0.0')]
abstract class AbstractGenerator implements GeneratorInterface
{
    public function __construct(
        protected readonly TemplateRenderer $renderer,
        protected readonly PathValidator $pathValidator,
    ) {}

    final public function generate(EntityDefinition $entity, GeneratorConfig $config): GeneratedFileSet
    {
        $files = $this->doGenerate($entity, $config);

        // Validate all output paths
        foreach ($files as $file) {
            $this->pathValidator->validate($file->targetPath);
        }

        $fileSet = new GeneratedFileSet($files);

        // If not forcing, check for conflicts and apply overwrite policy
        if (!$config->force && $fileSet->hasConflicts()) {
            $this->enforceOverwritePolicies($fileSet);
        }

        return $fileSet;
    }

    /**
     * Produce the list of generated files. Subclasses implement this.
     *
     * @return list<GeneratedFile>
     */
    abstract protected function doGenerate(EntityDefinition $entity, GeneratorConfig $config): array;

    /**
     * Enforce overwrite policies on conflicting files.
     *
     * @throws InvalidArgumentException If any file has OverwritePolicy::Fail and exists on disk
     */
    private function enforceOverwritePolicies(GeneratedFileSet $fileSet): void
    {
        foreach ($fileSet->conflicts() as $conflict) {
            if ($conflict->overwritePolicy === OverwritePolicy::Fail) {
                throw new InvalidArgumentException(
                    "File already exists and overwrite policy is Fail: $conflict->targetPath",
                );
            }
        }
    }
}
