<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Inertia;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Inertia\InertiaConfig;

#[CoversClass(InertiaConfig::class)]
final class InertiaConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new InertiaConfig();

        self::assertSame('app', $config->rootView);
        self::assertSame('X-Inertia-Version', $config->versionHeader);
        self::assertSame('', $config->componentPathPrefix);
    }

    #[Test]
    public function from_array(): void
    {
        $config = InertiaConfig::fromArray([
            'root_view' => 'dashboard',
            'version_header' => 'X-My-Version',
            'component_path_prefix' => 'Pages/',
        ]);

        self::assertSame('dashboard', $config->rootView);
        self::assertSame('X-My-Version', $config->versionHeader);
        self::assertSame('Pages/', $config->componentPathPrefix);
    }

    #[Test]
    public function from_array_invalid_types_use_defaults(): void
    {
        $config = InertiaConfig::fromArray([
            'root_view' => 42,
            'version_header' => false,
        ]);

        self::assertSame('app', $config->rootView);
        self::assertSame('X-Inertia-Version', $config->versionHeader);
    }
}
