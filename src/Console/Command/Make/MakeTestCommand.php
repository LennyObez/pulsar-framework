<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use ReflectionClass;
use ReflectionMethod;

use function class_exists;
use function count;
use function dirname;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function is_string;
use function mkdir;
use function sprintf;
use function str_replace;

/**
 * Generate a test file with method stubs matching a source class's public methods.
 *
 * Introspects the target class via reflection to produce PHPUnit test stubs
 * with #[Test] attributes, arranged in AAA pattern with assertion placeholders.
 */
final class MakeTestCommand extends Command
{
    use ScaffoldTrait;

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:test';
        $this->description = 'Generate a test file with method stubs matching source class public methods';
        $this->addArgument('class', 'Fully-qualified class name to generate tests for', true);
        $this->addOption('path', 'Output directory for the test file', 'p', 'tests/Unit');
        $this->addOption('force', 'Overwrite existing test file');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $className = $input->getArgument(0);

        if (!is_string($className) || $className === '') {
            $output->errorln('Class name is required.');
            return ExitCode::Invalid->value;
        }

        $basePath = $input->getStringOption('path', 'tests/Unit');
        $force = $input->hasOption('force');

        if (!class_exists($className)) {
            $output->errorln(sprintf('Class "%s" does not exist. Ensure it is autoloaded.', $className));
            return ExitCode::Error->value;
        }

        $reflection = new ReflectionClass($className);

        $methods = $this->extractPublicMethods($reflection);

        if ($methods === []) {
            $output->warning(sprintf('Class "%s" has no public methods to test.', $className));
        }

        $testContent = $this->generateTestContent($reflection, $methods);
        $testPath = $this->resolveTestFilePath($reflection, $basePath);

        if ($testPath === false) {
            $output->errorln('Failed to determine current working directory.');
            return ExitCode::Error->value;
        }

        if (file_exists($testPath) && !$force) {
            $output->errorln(sprintf('Test file already exists: %s', $testPath));
            $output->writeln('Use --force to overwrite.');
            return ExitCode::Error->value;
        }

        $dir = dirname($testPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        file_put_contents($testPath, $testContent);

        $output->success(sprintf('Generated test: %s', $testPath));
        $output->writeln(sprintf('  Methods: %d test stub(s) created.', count($methods)));

        return ExitCode::Success->value;
    }

    /**
     * Extract public non-magic, non-static methods declared on the class itself.
     *
     * @param ReflectionClass<object> $reflection
     * @return list<ReflectionMethod>
     */
    private function extractPublicMethods(ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip inherited, magic, and static methods
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            if ($method->isStatic()) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param list<ReflectionMethod> $methods
     */
    private function generateTestContent(ReflectionClass $reflection, array $methods): string
    {
        $className = $reflection->getShortName();
        $namespace = $reflection->getNamespaceName();
        $testNamespace = 'Pulsar\\Tests\\Unit' . ($namespace !== '' ? '\\' . str_replace('Pulsar\\', '', $namespace) : '');
        $fullClassName = $reflection->getName();

        $testMethods = '';

        foreach ($methods as $method) {
            $testMethodName = 'it_' . $this->toSnakeCase($method->getName());
            $testMethods .= $this->generateTestMethod($testMethodName, $method->getName(), $className);
        }

        if ($testMethods === '') {
            $testMethods = <<<'PHP'

                    #[Test]
                    public function it_can_be_instantiated(): void
                    {
                        // Arrange & Act
                        self::markTestIncomplete('Implement this test: verify meaningful behavior, not just instantiation');
                    }

                PHP;
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$testNamespace};

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use {$fullClassName};

            #[CoversClass({$className}::class)]
            final class {$className}Test extends TestCase
            {
            {$testMethods}}
            PHP;
    }

    private function generateTestMethod(string $testMethodName, string $sourceMethodName, string $className): string
    {
        return <<<PHP

                #[Test]
                public function {$testMethodName}(): void
                {
                    // Arrange
                    // Set up {$className} and dependencies

                    // Act
                    // Call \$subject->{$sourceMethodName}()

                    // Assert
                    self::markTestIncomplete('Implement: verify {$sourceMethodName}() behavior');
                }

            PHP;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function resolveTestFilePath(ReflectionClass $reflection, string $basePath): string|false
    {
        $cwd = getcwd();

        if ($cwd === false) {
            return false;
        }

        $relativeNamespace = str_replace('Pulsar\\', '', $reflection->getNamespaceName());
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeNamespace);
        $testFileName = $reflection->getShortName() . 'Test.php';

        return $cwd . DIRECTORY_SEPARATOR . $basePath . DIRECTORY_SEPARATOR . $relativePath . DIRECTORY_SEPARATOR . $testFileName;
    }
}
