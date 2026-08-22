<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Lazy;

#[CoversClass(Lazy::class)]
final class LazyTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $lazy = new Lazy();

        self::assertSame('<div data-live-lazy-placeholder></div>', $lazy->placeholder);
        self::assertFalse($lazy->onInteraction);
    }

    #[Test]
    public function customPlaceholder(): void
    {
        $lazy = new Lazy(placeholder: '<span>Loading...</span>');

        self::assertSame('<span>Loading...</span>', $lazy->placeholder);
    }

    #[Test]
    public function onInteractionMode(): void
    {
        $lazy = new Lazy(onInteraction: true);

        self::assertTrue($lazy->onInteraction);
    }
}
