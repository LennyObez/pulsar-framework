<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Contracts\StudioModuleInterface;
use Pulsar\Extension\Studio\Exception\StudioException;
use Pulsar\Extension\Studio\Internal\StudioModuleRegistry;
use Pulsar\Routing\RouterInterface;

#[CoversClass(StudioModuleRegistry::class)]
final class StudioModuleRegistryTest extends TestCase
{
    #[Test]
    public function registerAddsModule(): void
    {
        $registry = new StudioModuleRegistry();
        $module = $this->createModule('test-module', '/test', 10);

        $registry->register($module);

        self::assertSame($module, $registry->get('test-module'));
    }

    #[Test]
    public function modulesReturnsSortedByNavOrder(): void
    {
        $registry = new StudioModuleRegistry();

        $moduleA = $this->createModule('module-a', '/a', 20);
        $moduleB = $this->createModule('module-b', '/b', 5);
        $moduleC = $this->createModule('module-c', '/c', 10);

        $registry->register($moduleA);
        $registry->register($moduleB);
        $registry->register($moduleC);

        $modules = $registry->modules();

        self::assertSame('module-b', $modules[0]->moduleId());
        self::assertSame('module-c', $modules[1]->moduleId());
        self::assertSame('module-a', $modules[2]->moduleId());
    }

    #[Test]
    public function getReturnsNullForUnknownModule(): void
    {
        $registry = new StudioModuleRegistry();

        self::assertNull($registry->get('nonexistent'));
    }

    #[Test]
    public function registerRejectsInvalidModuleId(): void
    {
        $registry = new StudioModuleRegistry();
        $module = $this->createModule('Invalid Module!', '/invalid', 1);

        $this->expectException(StudioException::class);
        $this->expectExceptionMessageIsOrContains('Invalid Studio module ID');

        $registry->register($module);
    }

    #[Test]
    public function registerRejectsDuplicateModuleId(): void
    {
        $registry = new StudioModuleRegistry();
        $module1 = $this->createModule('duplicate', '/dup1', 1);
        $module2 = $this->createModule('duplicate', '/dup2', 2);

        $registry->register($module1);

        $this->expectException(StudioException::class);
        $this->expectExceptionMessageIsOrContains('Duplicate Studio module ID');

        $registry->register($module2);
    }

    #[Test]
    public function registerRejectsRoutePrefixCollision(): void
    {
        $registry = new StudioModuleRegistry();
        $module1 = $this->createModule('module-a', '/shared-prefix', 1);
        $module2 = $this->createModule('module-b', '/shared-prefix', 2);

        $registry->register($module1);

        $this->expectException(StudioException::class);
        $this->expectExceptionMessageIsOrContains('route prefix');

        $registry->register($module2);
    }

    #[Test]
    public function modulesReturnsEmptyListWhenEmpty(): void
    {
        $registry = new StudioModuleRegistry();

        self::assertSame([], $registry->modules());
    }

    private function createModule(string $id, string $prefix, int $navOrder): StudioModuleInterface
    {
        return new class ($id, $prefix, $navOrder) implements StudioModuleInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $prefix,
                private readonly int $navOrder,
            ) {}

            public function moduleId(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return $this->id;
            }

            public function icon(): string
            {
                return 'icon';
            }

            public function navEntries(): array
            {
                return [];
            }

            public function registerRoutes(RouterInterface $router): void {}

            public function routePrefix(): string
            {
                return $this->prefix;
            }

            public function navOrder(): int
            {
                return $this->navOrder;
            }
        };
    }
}
