<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Routing\RouterInterface;

#[CoversClass(ExtensionRegistry::class)]
final class ExtensionRegistryTest extends TestCase
{
    private ExtensionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ExtensionRegistry();
    }

    #[Test]
    public function addRegistersExtension(): void
    {
        $extension = $this->createExtension('test/ext');
        $manifest = $this->createManifest('test/ext');

        $this->registry->add($extension, $manifest);

        self::assertTrue($this->registry->has('test/ext'));
        self::assertSame($extension, $this->registry->get('test/ext'));
    }

    #[Test]
    public function addThrowsOnDuplicateExtension(): void
    {
        $extension = $this->createExtension('test/ext');
        $manifest = $this->createManifest('test/ext');

        $this->registry->add($extension, $manifest);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageIsOrContains('already registered');

        $this->registry->add($extension, $manifest);
    }

    #[Test]
    public function getThrowsOnNotFound(): void
    {
        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $_ = $this->registry->get('nonexistent');
    }

    #[Test]
    public function getManifestReturnsManifest(): void
    {
        $extension = $this->createExtension('test/ext');
        $manifest = $this->createManifest('test/ext');

        $this->registry->add($extension, $manifest);

        self::assertSame($manifest, $this->registry->getManifest('test/ext'));
    }

    #[Test]
    public function getStateReturnsState(): void
    {
        $extension = $this->createExtension('test/ext');
        $manifest = $this->createManifest('test/ext');

        $this->registry->add($extension, $manifest, ExtensionLifecycle::Validated);

        self::assertSame(ExtensionLifecycle::Validated, $this->registry->getState('test/ext'));
    }

    #[Test]
    public function setStateUpdatesState(): void
    {
        $extension = $this->createExtension('test/ext');
        $manifest = $this->createManifest('test/ext');

        $this->registry->add($extension, $manifest, ExtensionLifecycle::Validated);
        $this->registry->setState('test/ext', ExtensionLifecycle::Registered);

        self::assertSame(ExtensionLifecycle::Registered, $this->registry->getState('test/ext'));
    }

    #[Test]
    public function allReturnsAllExtensions(): void
    {
        $ext1 = $this->createExtension('test/ext1');
        $ext2 = $this->createExtension('test/ext2');

        $this->registry->add($ext1, $this->createManifest('test/ext1'));
        $this->registry->add($ext2, $this->createManifest('test/ext2'));

        $all = $this->registry->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('test/ext1', $all);
        self::assertArrayHasKey('test/ext2', $all);
    }

    #[Test]
    public function inStateReturnsExtensionsInState(): void
    {
        $ext1 = $this->createExtension('test/ext1');
        $ext2 = $this->createExtension('test/ext2');

        $this->registry->add($ext1, $this->createManifest('test/ext1'), ExtensionLifecycle::Validated);
        $this->registry->add($ext2, $this->createManifest('test/ext2'), ExtensionLifecycle::Registered);

        $validated = $this->registry->inState(ExtensionLifecycle::Validated);

        self::assertCount(1, $validated);
        self::assertArrayHasKey('test/ext1', $validated);
    }

    #[Test]
    public function countReturnsCorrectCount(): void
    {
        self::assertSame(0, $this->registry->count());

        $this->registry->add($this->createExtension('test/ext1'), $this->createManifest('test/ext1'));
        self::assertSame(1, $this->registry->count());

        $this->registry->add($this->createExtension('test/ext2'), $this->createManifest('test/ext2'));
        self::assertSame(2, $this->registry->count());
    }

    #[Test]
    public function namesReturnsExtensionNames(): void
    {
        $this->registry->add($this->createExtension('test/ext1'), $this->createManifest('test/ext1'));
        $this->registry->add($this->createExtension('test/ext2'), $this->createManifest('test/ext2'));

        self::assertSame(['test/ext1', 'test/ext2'], $this->registry->names());
    }

    #[Test]
    public function allBootedReturnsTrueWhenAllBooted(): void
    {
        $this->registry->add($this->createExtension('test/ext1'), $this->createManifest('test/ext1'), ExtensionLifecycle::Booted);
        $this->registry->add($this->createExtension('test/ext2'), $this->createManifest('test/ext2'), ExtensionLifecycle::Booted);

        self::assertTrue($this->registry->allBooted());
    }

    #[Test]
    public function allBootedReturnsFalseWhenNotAllBooted(): void
    {
        $this->registry->add($this->createExtension('test/ext1'), $this->createManifest('test/ext1'), ExtensionLifecycle::Booted);
        $this->registry->add($this->createExtension('test/ext2'), $this->createManifest('test/ext2'), ExtensionLifecycle::Registered);

        self::assertFalse($this->registry->allBooted());
    }

    #[Test]
    public function failedReturnsFailedExtensions(): void
    {
        $this->registry->add($this->createExtension('test/ext1'), $this->createManifest('test/ext1'), ExtensionLifecycle::Booted);
        $this->registry->add($this->createExtension('test/ext2'), $this->createManifest('test/ext2'), ExtensionLifecycle::Failed);

        $failed = $this->registry->failed();

        self::assertCount(1, $failed);
        self::assertArrayHasKey('test/ext2', $failed);
    }

    #[Test]
    public function hasFailedReturnsTrueWhenHasFailed(): void
    {
        $this->registry->add($this->createExtension('test/ext'), $this->createManifest('test/ext'), ExtensionLifecycle::Failed);

        self::assertTrue($this->registry->hasFailed());
    }

    private function createExtension(string $name): ExtensionInterface
    {
        return new class ($name) implements ExtensionInterface {
            public function __construct(private readonly string $extensionName) {}

            public function name(): string
            {
                return $this->extensionName;
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void {}

            public function providers(): array
            {
                return [];
            }
        };
    }

    private function createManifest(string $name): ExtensionManifest
    {
        return ExtensionManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'extension_class' => 'TestExtension',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]);
    }
}
