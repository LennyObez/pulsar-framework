<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\CssOverride;

#[CoversClass(CssOverride::class)]
final class CssOverrideTest extends TestCase
{
    #[Test]
    public function create_returns_active_override(): void
    {
        $override = CssOverride::create(
            id: 'css-1',
            themeId: 'theme-1',
            version: 3,
            cssContent: ':root { --color-primary: blue; }',
            cssHash: 'sha256hash',
            tokenOverrides: ['--color-primary' => 'blue'],
            createdBy: 'user-1',
            reason: 'Brand color update',
        );

        self::assertSame('css-1', $override->id);
        self::assertSame('theme-1', $override->themeId);
        self::assertSame(3, $override->version);
        self::assertSame(':root { --color-primary: blue; }', $override->cssContent);
        self::assertSame('sha256hash', $override->cssHash);
        self::assertSame(['--color-primary' => 'blue'], $override->tokenOverrides);
        self::assertTrue($override->isActive);
        self::assertSame('user-1', $override->createdBy);
        self::assertSame('Brand color update', $override->reason);
        self::assertNull($override->tenantId);
    }

    #[Test]
    public function create_with_tenant(): void
    {
        $override = CssOverride::create(
            id: 'css-2',
            themeId: 'theme-1',
            version: 1,
            cssContent: 'body { color: red; }',
            cssHash: 'hash2',
            tokenOverrides: [],
            createdBy: 'admin-1',
            reason: 'Initial setup',
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $override->tenantId);
    }

    #[Test]
    public function empty_token_overrides_are_valid(): void
    {
        $override = CssOverride::create(
            id: 'css-3',
            themeId: 'theme-1',
            version: 1,
            cssContent: '.custom { display: none; }',
            cssHash: 'hash3',
            tokenOverrides: [],
            createdBy: 'user-1',
            reason: 'Hide element',
        );

        self::assertSame([], $override->tokenOverrides);
    }
}
