<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheAllowedClasses;

use function dirname;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CacheAllowedClasses::class)]
final class CacheAllowedClassesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cache_test_' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        rmdir($this->tempDir);
    }

    #[Test]
    public function scanReturnsEligibleClasses(): void
    {
        $vendorPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'vendor';
        $srcPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';

        $result = CacheAllowedClasses::scan($vendorPath, $srcPath);

        // Result is a sorted list — verify every entry is from an eligible namespace
        foreach ($result as $class) {
            $inEligible = str_starts_with($class, 'Pulsar\\Config\\')
                || str_starts_with($class, 'Pulsar\\Cache\\')
                || str_starts_with($class, 'Pulsar\\Routing\\')
                || str_starts_with($class, 'Pulsar\\Http\\');
            self::assertTrue($inEligible, "Class {$class} should be in an eligible namespace");
        }

        // scan() must return a list (possibly empty if no eligible classes)
        self::assertSame(array_values($result), $result);
    }

    #[Test]
    public function scanReturnsSortedResults(): void
    {
        $vendorPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'vendor';
        $srcPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';

        $result = CacheAllowedClasses::scan($vendorPath, $srcPath);

        $sorted = $result;
        sort($sorted);
        self::assertSame($sorted, $result);
    }

    #[Test]
    public function saveAndLoadRoundTrip(): void
    {
        /** @var class-string $a */
        $a = trim('Pulsar\\Cache\\SomeClass');
        /** @var class-string $b */
        $b = trim('Pulsar\\Http\\AnotherClass');
        $classes = [$a, $b];

        CacheAllowedClasses::save($this->tempDir, $classes);

        $loaded = CacheAllowedClasses::load($this->tempDir);

        self::assertSame($classes, $loaded);
    }

    #[Test]
    public function loadReturnsNullWhenFileDoesNotExist(): void
    {
        $result = CacheAllowedClasses::load($this->tempDir);

        self::assertNull($result);
    }

    #[Test]
    public function loadReturnsNullForInvalidJson(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'allowed_classes.json', 'not json');

        $result = CacheAllowedClasses::load($this->tempDir);

        self::assertNull($result);
    }

    #[Test]
    public function loadReturnsNullForNonListArray(): void
    {
        $data = json_encode(['key' => 'value'], JSON_THROW_ON_ERROR);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'allowed_classes.json', $data);

        $result = CacheAllowedClasses::load($this->tempDir);

        self::assertNull($result);
    }

    #[Test]
    public function loadReturnsNullForNonArrayJson(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'allowed_classes.json', '"just a string"');

        $result = CacheAllowedClasses::load($this->tempDir);

        self::assertNull($result);
    }

    #[Test]
    public function saveCreatesFormattedJsonFile(): void
    {
        /** @var class-string $a */
        $a = trim('Pulsar\\Cache\\A');
        /** @var class-string $b */
        $b = trim('Pulsar\\Http\\B');
        $classes = [$a, $b];

        CacheAllowedClasses::save($this->tempDir, $classes);

        $content = file_get_contents($this->tempDir . DIRECTORY_SEPARATOR . 'allowed_classes.json');
        self::assertNotFalse($content);

        // Should be pretty-printed
        self::assertStringContainsString("\n", $content);

        // Should not contain forward-slash escaping (unescaped slashes flag)
        self::assertStringNotContainsString('\\/', $content);
    }
}
