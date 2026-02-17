<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\BasePathResolveTrait;

#[CoversClass(BasePathResolveTrait::class)]
final class BasePathResolveTraitTest extends TestCase
{
    #[Test]
    #[DataProvider('pathProvider')]
    public function toRelativePathConvertsCorrectly(string $basePath, string $absolutePath, string $expected): void
    {
        $obj = new class ($basePath) {
            use BasePathResolveTrait;

            public function __construct(private readonly string $basePath) {}

            public function resolve(string $path): string
            {
                return $this->toRelativePath($path);
            }
        };

        self::assertSame($expected, $obj->resolve($absolutePath));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function pathProvider(): iterable
    {
        yield 'basic relative path' => [
            '/var/www/project',
            '/var/www/project/src/Kernel.php',
            'src/Kernel.php',
        ];

        yield 'nested directory' => [
            '/var/www/project',
            '/var/www/project/src/Security/Crypto/Encryptor.php',
            'src/Security/Crypto/Encryptor.php',
        ];

        yield 'windows-style backslashes' => [
            'C:\\Users\\dev\\project',
            'C:\\Users\\dev\\project\\src\\Main.php',
            'src/Main.php',
        ];

        yield 'mixed separators' => [
            'C:/Users/dev/project',
            'C:\\Users\\dev\\project\\tests\\UnitTest.php',
            'tests/UnitTest.php',
        ];

        yield 'path outside base returns normalized absolute' => [
            '/var/www/project',
            '/var/www/other/file.php',
            '/var/www/other/file.php',
        ];

        yield 'base path with trailing slash' => [
            '/var/www/project',
            '/var/www/project/composer.json',
            'composer.json',
        ];

        yield 'single file in root' => [
            '/app',
            '/app/index.php',
            'index.php',
        ];
    }

    #[Test]
    public function identicalBaseAndPathProducesFilename(): void
    {
        $obj = new class ('/project') {
            use BasePathResolveTrait;

            public function __construct(private readonly string $basePath) {}

            public function resolve(string $path): string
            {
                return $this->toRelativePath($path);
            }
        };

        // Path exactly at base + filename should strip the base
        self::assertSame('file.php', $obj->resolve('/project/file.php'));
    }
}
