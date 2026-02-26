<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Config\CmsConfig;
use ReflectionClass;

#[CoversClass(CmsConfig::class)]
final class CmsConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new CmsConfig();

        self::assertSame('en', $config->defaultLocale);
        self::assertSame(['en'], $config->supportedLocales);
        self::assertFalse($config->defaultLocaleInUrl);
        self::assertFalse($config->editorialWorkflow);
        self::assertFalse($config->eventSourcing);
        self::assertFalse($config->atomicSnapshots);
        self::assertSame(10, $config->maxHierarchyDepth);
        self::assertInstanceOf(CmsCacheConfig::class, $config->cache);
    }

    #[Test]
    public function fromArrayWithAllValues(): void
    {
        $config = CmsConfig::fromArray([
            'default_locale' => 'fr',
            'supported_locales' => ['fr', 'en', 'de'],
            'default_locale_in_url' => true,
            'editorial_workflow' => true,
            'event_sourcing' => true,
            'atomic_snapshots' => true,
            'max_hierarchy_depth' => 5,
            'cache' => [],
        ]);

        self::assertSame('fr', $config->defaultLocale);
        self::assertSame(['fr', 'en', 'de'], $config->supportedLocales);
        self::assertTrue($config->defaultLocaleInUrl);
        self::assertTrue($config->editorialWorkflow);
        self::assertTrue($config->eventSourcing);
        self::assertTrue($config->atomicSnapshots);
        self::assertSame(5, $config->maxHierarchyDepth);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CmsConfig::fromArray([]);

        self::assertSame('en', $config->defaultLocale);
        self::assertFalse($config->editorialWorkflow);
        self::assertSame(10, $config->maxHierarchyDepth);
    }

    #[Test]
    public function fromArrayPartialValues(): void
    {
        $config = CmsConfig::fromArray([
            'editorial_workflow' => true,
        ]);

        self::assertTrue($config->editorialWorkflow);
        self::assertSame('en', $config->defaultLocale); // Default preserved
        self::assertFalse($config->eventSourcing); // Default preserved
    }

    #[Test]
    public function isReadonlyClass(): void
    {
        $reflection = new ReflectionClass(CmsConfig::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
