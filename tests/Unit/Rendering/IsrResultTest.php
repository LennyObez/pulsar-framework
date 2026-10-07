<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\IsrResult;

#[CoversClass(IsrResult::class)]
final class IsrResultTest extends TestCase
{
    #[Test]
    public function cacheHitFreshPage(): void
    {
        $result = new IsrResult('<p>Content</p>', hit: true, stale: false, path: '/about');

        self::assertSame('<p>Content</p>', $result->html);
        self::assertTrue($result->hit);
        self::assertFalse($result->stale);
        self::assertSame('/about', $result->path);
    }

    #[Test]
    public function cacheMiss(): void
    {
        $result = new IsrResult('', hit: false, stale: false, path: '/new-page');

        self::assertFalse($result->hit);
        self::assertSame('', $result->html);
    }

    #[Test]
    public function stalePage(): void
    {
        $result = new IsrResult('<p>Old</p>', hit: true, stale: true, path: '/blog/post');

        self::assertTrue($result->hit);
        self::assertTrue($result->stale);
    }
}
