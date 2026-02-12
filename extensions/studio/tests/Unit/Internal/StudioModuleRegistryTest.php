<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Contracts\StudioModuleInterface;
use Pulsar\Extension\Studio\Exception\StudioException;
use Pulsar\Extension\Studio\Internal\StudioModuleRegistry;
use Pulsar\Routing\RouterInterface;

final class StudioModuleRegistryTest extends TestCase
{
    #[Test]
    public function registerAddsModule(): void
    {
        $registry = new StudioModuleRegistry();
        $module = $this->createModule('test-module', '/studio/test', 10);

        $registry->register($module);

        self::assertSame($module, $registry->get('test-module'));
    }

    #[Test]
    public function modulesReturnsSortedByNavOrder(): void
    {
        $registry = new StudioModuleRegistry();
        $moduleA = $this->createModule('mod-a', '/studio/a', 20);
        $moduleB = $this->createModule('mod-b', '/studio/b', 10);
        $moduleC = $this->createModule('mod-c', '/studio/c', 30);

        $registry->register($moduleA);
        $registry->register($moduleB);
        $registry->register($moduleC);

        $modules = $registry->modules();

        self::assertSame('mod-b', $modules[0]->moduleId());
        self::assertSame('mod-a', $modules[1]->moduleId());
        self::assertSame('mod-c', $modules[2]->moduleId());
    }

    #[Test]
    public function getReturnsNullForUnknownModule(): void
    {
        $registry = new StudioModuleRegistry();

        self::assertNull($registry->get('nonexistent'));
    }

    #[Test]
    public function registerThrowsForInvalidModuleId(): void
    {
        $registry = new StudioModuleRegistry();
        $module = $this->createModule('INVALID ID!', '/studio/bad', 10);

        $this->expectException(StudioException::class);
        $registry->register($module);
    }

    #[Test]
    public function registerThrowsForDuplicateModuleId(): void
    {
        $registry = new StudioModuleRegistry();
        $module1 = $this->createModule('my-mod', '/studio/m1', 10);
        $module2 = $this->createModule('my-mod', '/studio/m2', 20);

        $registry->register($module1);

        $this->expectException(StudioException::class);
        $registry->register($module2);
    }

    #[Test]
    public function registerThrowsForRoutePrefixCollision(): void
    {
        $registry = new StudioModuleRegistry();
        $module1 = $this->createModule('mod-a', '/studio/shared', 10);
        $module2 = $this->createModule('mod-b', '/studio/shared', 20);

        $registry->register($module1);

        $this->expectException(StudioException::class);
        $registry->register($module2);
    }

    private function createModule(string $id, string $routePrefix, int $navOrder): StudioModuleInterface
    {
        return new class ($id, $routePrefix, $navOrder) implements StudioModuleInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $prefix,
                private readonly int $order,
            ) {}

            #[Override]
            public function moduleId(): string
            {
                return $this->id;
            }

            #[Override]
            public function label(): string
            {
                return 'Test';
            }

            #[Override]
            public function icon(): string
            {
                return 'icon';
            }

            #[Override]
            public function navEntries(): array
            {
                return [];
            }

            #[Override]
            public function registerRoutes(RouterInterface $router): void {}

            #[Override]
            public function routePrefix(): string
            {
                return $this->prefix;
            }

            #[Override]
            public function navOrder(): int
            {
                return $this->order;
            }
        };
    }
}
