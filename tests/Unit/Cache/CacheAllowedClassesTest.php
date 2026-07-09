<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheAllowedClasses;
use Pulsar\Cache\CacheException;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\View\ViewConfig;
use ReflectionClass;
use ReflectionMethod;

use function dirname;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function serialize;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;

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

    #[Test]
    public function hasDangerousMethodsDetectsInheritedMagicMethod(): void
    {
        // A subclass that inherits __wakeup from its parent is just as
        // exploitable as the parent, because unserialize() invokes the
        // inherited method when reconstructing the subclass. The check must
        // flag it even though the method is declared on the parent.
        $result = $this->invokeHasDangerousMethods(InheritsWakeupFixture::class);

        self::assertTrue($result);
    }

    #[Test]
    public function hasDangerousMethodsDetectsDirectlyDeclaredMagicMethod(): void
    {
        $result = $this->invokeHasDangerousMethods(DeclaresWakeupFixture::class);

        self::assertTrue($result);
    }

    #[Test]
    public function hasDangerousMethodsAllowsClassWithNoMagicMethods(): void
    {
        $result = $this->invokeHasDangerousMethods(SafeFixture::class);

        self::assertFalse($result);
    }

    /**
     * @param class-string $className
     */
    private function invokeHasDangerousMethods(string $className): bool
    {
        /** @var ReflectionClass<object> $ref */
        $ref = new ReflectionClass($className);

        $method = new ReflectionMethod(CacheAllowedClasses::class, 'hasDangerousMethods');

        /** @var bool $result */
        $result = $method->invoke(null, $ref);

        return $result;
    }

    #[Test]
    public function forCacheCoversTheEntireSerializedConfigGraph(): void
    {
        // Regression guard for the namespace-scan gap: a serialized ConfigRepository
        // reaches config value objects in feature namespaces (Api, Database, Mail,
        // Tenancy, View, ...) that scan() does not cover. forCache() must derive
        // those from the data so the file config cache round-trips losslessly
        // instead of raising a __PHP_Incomplete_Class TypeError at boot.
        $repoRoot = dirname(__DIR__, 3);

        $manager = new ConfigManager($repoRoot . DIRECTORY_SEPARATOR . 'config');
        $manager->load();
        $serialized = serialize($manager->repository());

        $allowed = CacheAllowedClasses::forCache(
            $repoRoot . DIRECTORY_SEPARATOR . 'vendor',
            $repoRoot . DIRECTORY_SEPARATOR . 'src',
            $serialized,
        );

        // A feature-namespace config DTO the namespace scan alone would miss.
        self::assertContains(ViewConfig::class, $allowed);

        /** @var mixed $restored */
        $restored = unserialize($serialized, ['allowed_classes' => $allowed]);
        self::assertInstanceOf(ConfigRepository::class, $restored);
        self::assertSame(
            $serialized,
            serialize($restored),
            'The cache allowlist must round-trip the real config graph with no __PHP_Incomplete_Class loss.',
        );
    }

    #[Test]
    public function extractFromSerializedReturnsTheSafeClassesInTheBlob(): void
    {
        $classes = CacheAllowedClasses::extractFromSerialized(serialize(new SafeFixture()));

        self::assertContains(SafeFixture::class, $classes);
    }

    #[Test]
    public function extractFromSerializedRejectsAGadgetClass(): void
    {
        // A serialized class carrying a dangerous magic method must never be
        // silently allow-listed — extractFromSerialized fails closed.
        $this->expectException(CacheException::class);

        (void) CacheAllowedClasses::extractFromSerialized(serialize(new DeclaresWakeupFixture()));
    }
}

/**
 * Fixture: parent declaring a dangerous magic method.
 */
final class DeclaresWakeupFixture
{
    public function __wakeup(): void {}
}

/**
 * Fixture: parent declaring a dangerous magic method, intended for inheritance.
 */
class DangerousParentFixture
{
    public function __wakeup(): void {}
}

/**
 * Fixture: subclass that inherits (does not declare) a dangerous magic method.
 */
final class InheritsWakeupFixture extends DangerousParentFixture {}

/**
 * Fixture: class with no dangerous magic methods.
 */
final class SafeFixture
{
    public function harmless(): void {}
}
