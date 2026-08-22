<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\ExtensionAutoloader;

use function class_exists;

/**
 * Direct tests for the extension PSR-4 autoloader.
 *
 * The fixtures under Fixture/Autoload* live in the `PulsarAutoloadFixture`
 * namespace, which composer.json maps nowhere — so a successful class load
 * proves {@see ExtensionAutoloader} resolved it on its own, independently of
 * Composer's autoloader (the point of ADR-0004 de-privileging). Each test
 * targets a distinct class, since a class once declared cannot be unloaded.
 */
#[CoversClass(ExtensionAutoloader::class)]
final class ExtensionAutoloaderTest extends TestCase
{
    private const string FIXTURE_DIR = __DIR__ . '/Fixture/Autoload';
    private const string DEEP_FIXTURE_DIR = __DIR__ . '/Fixture/AutoloadDeep';

    #[Test]
    public function resolves_a_class_under_a_registered_prefix(): void
    {
        $class = 'PulsarAutoloadFixture\\Widget';

        $loader = new ExtensionAutoloader();
        $loader->addPsr4(['PulsarAutoloadFixture\\' => self::FIXTURE_DIR]);

        self::assertFalse(class_exists($class, false), 'precondition: class not yet loaded');
        self::assertTrue($loader->loadClass($class), 'autoloader should report a hit');
        self::assertTrue(class_exists($class, false), 'class should now be declared');
    }

    #[Test]
    public function maps_sub_namespaces_to_nested_directories(): void
    {
        $loader = new ExtensionAutoloader();
        $loader->addPsr4(['PulsarAutoloadFixture\\' => self::FIXTURE_DIR]);

        self::assertTrue($loader->loadClass('PulsarAutoloadFixture\\Sub\\Leaf'));
        self::assertTrue(class_exists('PulsarAutoloadFixture\\Sub\\Leaf', false));
    }

    #[Test]
    public function longest_prefix_wins_for_nested_namespace_mapped_elsewhere(): void
    {
        // The parent prefix maps to FIXTURE_DIR; the deeper prefix maps to a
        // SEPARATE directory. Loading the deep class must use the longer prefix
        // (DEEP_FIXTURE_DIR/Leaf.php) — the parent prefix would resolve to
        // FIXTURE_DIR/Deep/Leaf.php, which does not exist.
        $loader = new ExtensionAutoloader();
        $loader->addPsr4([
            'PulsarAutoloadFixture\\' => self::FIXTURE_DIR,
            'PulsarAutoloadFixture\\Deep\\' => self::DEEP_FIXTURE_DIR,
        ]);

        self::assertTrue($loader->loadClass('PulsarAutoloadFixture\\Deep\\Leaf'));
        self::assertTrue(class_exists('PulsarAutoloadFixture\\Deep\\Leaf', false));
    }

    #[Test]
    public function returns_false_for_unregistered_namespace(): void
    {
        $loader = new ExtensionAutoloader();
        $loader->addPsr4(['PulsarAutoloadFixture\\' => self::FIXTURE_DIR]);

        self::assertFalse($loader->loadClass('Some\\Other\\Vendor\\Thing'));
    }

    #[Test]
    public function returns_false_when_matched_prefix_has_no_file(): void
    {
        $loader = new ExtensionAutoloader();
        $loader->addPsr4(['PulsarAutoloadFixture\\' => self::FIXTURE_DIR]);

        self::assertFalse($loader->loadClass('PulsarAutoloadFixture\\NoSuchClass'));
    }
}
