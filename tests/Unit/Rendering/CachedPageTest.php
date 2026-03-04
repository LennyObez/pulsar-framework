<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\CachedPage;

#[CoversClass(CachedPage::class)]
final class CachedPageTest extends TestCase
{
    #[Test]
    public function constructorStoresHtmlAndTimestamp(): void
    {
        $page = new CachedPage('<h1>Hello</h1>', 1710000000);

        self::assertSame('<h1>Hello</h1>', $page->html);
        self::assertSame(1710000000, $page->generatedAt);
    }

    #[Test]
    public function emptyHtmlIsValid(): void
    {
        $page = new CachedPage('', 0);

        self::assertSame('', $page->html);
        self::assertSame(0, $page->generatedAt);
    }
}
