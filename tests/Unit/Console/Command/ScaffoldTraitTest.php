<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\OutputInterface;

#[CoversNothing]
final class ScaffoldTraitTest extends TestCase
{
    use ScaffoldTrait;

    #[Test]
    public function to_pascal_case_converts_kebab_case(): void
    {
        self::assertSame('UserProfile', $this->toPascalCase('user-profile'));
    }

    #[Test]
    public function to_pascal_case_converts_snake_case(): void
    {
        self::assertSame('UserProfile', $this->toPascalCase('user_profile'));
    }

    #[Test]
    public function to_pascal_case_preserves_pascal_case(): void
    {
        self::assertSame('UserProfile', $this->toPascalCase('UserProfile'));
    }

    #[Test]
    public function to_pascal_case_handles_single_word(): void
    {
        self::assertSame('User', $this->toPascalCase('user'));
    }

    #[Test]
    public function to_kebab_case_converts_pascal_case(): void
    {
        self::assertSame('user-profile', $this->toKebabCase('UserProfile'));
    }

    #[Test]
    public function to_kebab_case_converts_snake_case(): void
    {
        self::assertSame('user-profile', $this->toKebabCase('user_profile'));
    }

    #[Test]
    public function to_kebab_case_handles_single_word(): void
    {
        self::assertSame('user', $this->toKebabCase('user'));
    }

    #[Test]
    public function to_camel_case_converts_kebab_case(): void
    {
        self::assertSame('userProfile', $this->toCamelCase('user-profile'));
    }

    #[Test]
    public function to_camel_case_converts_pascal_case(): void
    {
        self::assertSame('userProfile', $this->toCamelCase('UserProfile'));
    }

    #[Test]
    public function to_snake_case_converts_pascal_case(): void
    {
        self::assertSame('user_profile', $this->toSnakeCase('UserProfile'));
    }

    #[Test]
    public function resolve_base_path_returns_absolute_path(): void
    {
        $result = $this->resolveBasePath('app/Modules', 'app/Modules');

        self::assertIsString($result);
        self::assertStringEndsWith('app' . DIRECTORY_SEPARATOR . 'Modules', $result);
    }

    #[Test]
    public function resolve_base_path_uses_default_when_empty(): void
    {
        $result = $this->resolveBasePath('', 'app/Modules');

        self::assertIsString($result);
        self::assertStringEndsWith('app' . DIRECTORY_SEPARATOR . 'Modules', $result);
    }

    #[Test]
    public function confirm_action_returns_true_on_yes(): void
    {
        $stdin = fopen('php://memory', 'r+');
        self::assertNotFalse($stdin);

        fwrite($stdin, "y\n");
        rewind($stdin);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('write');

        self::assertTrue($this->confirmAction($stdin, $output, 'Delete?'));

        fclose($stdin);
    }

    #[Test]
    public function confirm_action_returns_false_on_no(): void
    {
        $stdin = fopen('php://memory', 'r+');
        self::assertNotFalse($stdin);

        fwrite($stdin, "n\n");
        rewind($stdin);

        $output = $this->createStub(OutputInterface::class);

        self::assertFalse($this->confirmAction($stdin, $output, 'Delete?'));

        fclose($stdin);
    }

    #[Test]
    public function confirm_action_returns_false_on_empty_input(): void
    {
        $stdin = fopen('php://memory', 'r+');
        self::assertNotFalse($stdin);

        fwrite($stdin, "\n");
        rewind($stdin);

        $output = $this->createStub(OutputInterface::class);

        self::assertFalse($this->confirmAction($stdin, $output, 'Delete?'));

        fclose($stdin);
    }

    #[Test]
    public function remove_directory_recursive_removes_nested_structure(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_scaffold_trait_' . bin2hex(random_bytes(8));
        mkdir($tempDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'deep', 0o755, true);
        file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'file.txt', 'test');
        file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR . 'nested.txt', 'test');

        self::assertDirectoryExists($tempDir);

        $this->removeDirectoryRecursive($tempDir);

        self::assertDirectoryDoesNotExist($tempDir);
    }

    #[Test]
    public function remove_directory_recursive_handles_nonexistent_dir(): void
    {
        $this->removeDirectoryRecursive('/nonexistent/path/that/does/not/exist');

        // Should not throw — just a no-op. Assert the dir still doesn't exist.
        self::assertDirectoryDoesNotExist('/nonexistent/path/that/does/not/exist');
    }

    #[Test]
    public function list_files_recursive_returns_all_files(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_list_files_' . bin2hex(random_bytes(8));
        mkdir($tempDir . DIRECTORY_SEPARATOR . 'sub', 0o755, true);
        file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'a.txt', 'a');
        file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.txt', 'b');

        $files = $this->listFilesRecursive($tempDir);

        self::assertCount(2, $files);
        self::assertContains($tempDir . DIRECTORY_SEPARATOR . 'a.txt', $files);
        self::assertContains($tempDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.txt', $files);

        $this->removeDirectoryRecursive($tempDir);
    }

    #[Test]
    public function list_files_recursive_returns_empty_for_nonexistent(): void
    {
        self::assertSame([], $this->listFilesRecursive(sys_get_temp_dir() . '/pulsar_nonexistent_' . bin2hex(random_bytes(16))));
    }

    #[Test]
    public function parse_comma_separated_option_returns_trimmed_list(): void
    {
        self::assertSame(['push', 'pull_request', 'issues'], $this->parseCommaSeparatedOption('push, pull_request, issues'));
    }

    #[Test]
    public function parse_comma_separated_option_filters_empty_values(): void
    {
        self::assertSame(['a', 'b'], $this->parseCommaSeparatedOption('a,,b,'));
    }

    #[Test]
    public function parse_comma_separated_option_returns_empty_for_empty_string(): void
    {
        self::assertSame([], $this->parseCommaSeparatedOption(''));
    }

    #[Test]
    public function parse_comma_separated_option_returns_empty_for_non_string(): void
    {
        self::assertSame([], $this->parseCommaSeparatedOption(null));
    }

    #[Test]
    public function resolve_test_base_path_creates_subdirectories(): void
    {
        $testBase = $this->resolveTestBasePath('TestModule', ['Controller']);

        self::assertIsString($testBase);
        self::assertStringEndsWith('tests' . DIRECTORY_SEPARATOR . 'Unit' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'TestModule', $testBase);
        self::assertDirectoryExists($testBase . DIRECTORY_SEPARATOR . 'Controller');

        $this->removeDirectoryRecursive($testBase);
    }
}
