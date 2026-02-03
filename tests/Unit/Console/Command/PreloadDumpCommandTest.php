<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\PreloadDumpCommand;

use function sort;

#[CoversClass(PreloadDumpCommand::class)]
final class PreloadDumpCommandTest extends TestCase
{
    private PreloadDumpCommand $command;

    protected function setUp(): void
    {
        $this->command = new PreloadDumpCommand();
    }

    #[Test]
    public function it_has_correct_name(): void
    {
        self::assertSame('preload:dump', $this->command->name);
    }

    #[Test]
    public function it_has_description(): void
    {
        self::assertNotEmpty($this->command->description);
    }

    #[Test]
    public function output_is_byte_for_byte_deterministic(): void
    {
        $paths = [
            '/app/src/Core/Kernel.php',
            '/app/src/Container/Container.php',
            '/app/src/Http/Request.php',
            '/app/src/Http/Response.php',
        ];

        $first = $this->command->generatePhpContent($paths);
        $second = $this->command->generatePhpContent($paths);

        self::assertSame($first, $second, 'Preload output must be byte-for-byte deterministic');
    }

    #[Test]
    public function output_contains_no_timestamps(): void
    {
        $paths = ['/app/src/Core/Kernel.php'];

        $content = $this->command->generatePhpContent($paths);

        // Must not contain date patterns or PHP version
        self::assertStringNotContainsString(date('Y'), $content, 'Generated PHP must not contain current year');
        self::assertStringNotContainsString(PHP_VERSION, $content, 'Generated PHP must not contain PHP version');
    }

    #[Test]
    public function output_contains_strict_types_declaration(): void
    {
        $content = $this->command->generatePhpContent(['/app/src/Core/Kernel.php']);

        self::assertStringContainsString('declare(strict_types=1);', $content);
    }

    #[Test]
    public function output_guards_with_function_exists_check(): void
    {
        $content = $this->command->generatePhpContent(['/app/src/Core/Kernel.php']);

        self::assertStringContainsString("function_exists('opcache_compile_file')", $content);
    }

    #[Test]
    public function output_uses_absolute_paths(): void
    {
        $paths = [
            '/opt/app/src/Core/Kernel.php',
            '/opt/app/src/Http/Request.php',
        ];

        $content = $this->command->generatePhpContent($paths);

        self::assertStringContainsString("opcache_compile_file('/opt/app/src/Core/Kernel.php');", $content);
        self::assertStringContainsString("opcache_compile_file('/opt/app/src/Http/Request.php');", $content);
    }

    #[Test]
    public function output_normalizes_backslashes(): void
    {
        $paths = ['C:\\app\\src\\Core\\Kernel.php'];

        $content = $this->command->generatePhpContent($paths);

        self::assertStringContainsString("opcache_compile_file('C:/app/src/Core/Kernel.php');", $content);
        self::assertStringNotContainsString('\\', $content);
    }

    #[Test]
    public function output_sorts_paths_deterministically(): void
    {
        // Pass unsorted paths
        $paths = [
            '/app/src/Http/Response.php',
            '/app/src/Core/Kernel.php',
            '/app/src/Container/Container.php',
        ];

        $content = $this->command->generatePhpContent($paths);

        // Paths should appear in sorted order in the output
        $containerPos = strpos($content, 'Container/Container.php');
        $corePos = strpos($content, 'Core/Kernel.php');
        $httpPos = strpos($content, 'Http/Response.php');

        self::assertNotFalse($containerPos);
        self::assertNotFalse($corePos);
        self::assertNotFalse($httpPos);

        // Already sorted as passed (method trusts caller to sort)
        // But verify the output preserves order
        self::assertLessThan($corePos, $containerPos, 'Container should come before Core (alphabetically by path)');
    }

    #[Test]
    public function output_contains_security_warning(): void
    {
        $content = $this->command->generatePhpContent([]);

        self::assertStringContainsString('immutable deploy path', $content);
        self::assertStringContainsString('var/cache/', $content);
    }

    #[Test]
    public function empty_path_list_produces_valid_php(): void
    {
        $content = $this->command->generatePhpContent([]);

        self::assertStringContainsString('<?php', $content);
        // The guard function_exists check is always present, but no compile calls
        self::assertStringNotContainsString("opcache_compile_file('", $content);
    }
}
