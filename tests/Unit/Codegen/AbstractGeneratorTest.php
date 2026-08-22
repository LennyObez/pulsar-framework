<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\AbstractGenerator;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\GeneratedFileSet;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\OverwritePolicy;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Template\TemplateRenderer;

use function dirname;
use function str_replace;
use function strtolower;

#[CoversClass(AbstractGenerator::class)]
final class AbstractGeneratorTest extends TestCase
{
    #[Test]
    public function generateReturnsFileSetFromSubclass(): void
    {
        $generator = $this->createTestGenerator([
            new GeneratedFile('/project/src/Models/User.php', '<?php class User {}'),
        ]);

        $entity = $this->createEntity('User');
        $config = new GeneratorConfig('/project/src');

        $result = $generator->generate($entity, $config);

        self::assertInstanceOf(GeneratedFileSet::class, $result);
        self::assertCount(1, $result->files());
        self::assertSame('/project/src/Models/User.php', $result->files()[0]->targetPath);
    }

    #[Test]
    public function generateValidatesAllPaths(): void
    {
        $generator = $this->createTestGenerator([
            new GeneratedFile('/project/vendor/evil.php', '<?php // evil'),
        ]);

        $entity = $this->createEntity('Evil');
        $config = new GeneratorConfig('/project/src');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('outside allowed directories');

        $generator->generate($entity, $config);
    }

    #[Test]
    public function generateThrowsWhenFailPolicyAndConflict(): void
    {
        $existingFile = $this->normalizedPath(__FILE__);

        // Go up to project root so the file is within tests/ allowlist
        $projectRoot = $this->normalizedPath(dirname(__DIR__, 3));
        $generator = $this->createPermissiveGenerator($projectRoot, [
            new GeneratedFile($existingFile, 'new content', OverwritePolicy::Fail),
        ]);

        $entity = $this->createEntity('Test');
        $config = new GeneratorConfig($projectRoot);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('overwrite policy is Fail');

        $generator->generate($entity, $config);
    }

    #[Test]
    public function generateSkipsOverwriteCheckWhenForceEnabled(): void
    {
        $existingFile = $this->normalizedPath(__FILE__);

        $projectRoot = $this->normalizedPath(dirname(__DIR__, 3));
        $generator = $this->createPermissiveGenerator($projectRoot, [
            new GeneratedFile($existingFile, 'new content', OverwritePolicy::Fail),
        ]);

        $entity = $this->createEntity('Test');
        $config = new GeneratorConfig($projectRoot, force: true);

        $result = $generator->generate($entity, $config);

        self::assertCount(1, $result->files());
    }

    #[Test]
    public function generateAllowsSkipPolicyOnConflict(): void
    {
        $existingFile = $this->normalizedPath(__FILE__);

        $projectRoot = $this->normalizedPath(dirname(__DIR__, 3));
        $generator = $this->createPermissiveGenerator($projectRoot, [
            new GeneratedFile($existingFile, 'new content', OverwritePolicy::Skip),
        ]);

        $entity = $this->createEntity('Test');
        $config = new GeneratorConfig($projectRoot);

        $result = $generator->generate($entity, $config);

        self::assertCount(1, $result->files());
    }

    /**
     * @param list<GeneratedFile> $files
     */
    private function createTestGenerator(array $files): AbstractGenerator
    {
        $renderer = new TemplateRenderer();
        $validator = new PathValidator('/project');

        return $this->buildGenerator($renderer, $validator, $files);
    }

    /**
     * @param list<GeneratedFile> $files
     */
    private function createPermissiveGenerator(string $root, array $files): AbstractGenerator
    {
        $renderer = new TemplateRenderer();
        $validator = new PathValidator($root, ['tests/']);

        return $this->buildGenerator($renderer, $validator, $files);
    }

    /**
     * @param list<GeneratedFile> $files
     */
    private function buildGenerator(TemplateRenderer $renderer, PathValidator $validator, array $files): AbstractGenerator
    {
        return new class ($renderer, $validator, $files) extends AbstractGenerator {
            /**
             * @param list<GeneratedFile> $filesToGenerate
             */
            public function __construct(
                TemplateRenderer $renderer,
                PathValidator $pathValidator,
                /** @var list<GeneratedFile> */
                private readonly array $filesToGenerate,
            ) {
                parent::__construct($renderer, $pathValidator);
            }

            /** @return list<GeneratedFile> */
            #[Override]
            protected function doGenerate(EntityDefinition $entity, GeneratorConfig $config): array
            {
                return $this->filesToGenerate;
            }
        };
    }

    private function createEntity(string $name): EntityDefinition
    {
        return new EntityDefinition(
            className: $name,
            namespace: 'App\\Models',
            tableName: strtolower($name) . 's',
            properties: [],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );
    }

    private function normalizedPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
