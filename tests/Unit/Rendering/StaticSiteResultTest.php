<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Rendering\StaticSiteResult;

#[CoversClass(StaticSiteResult::class)]
final class StaticSiteResultTest extends TestCase
{
    #[Test]
    public function successfulGenerationWithNoErrors(): void
    {
        $result = new StaticSiteResult(
            pagesGenerated: 3,
            paths: ['/index.html', '/about.html', '/contact.html'],
            errors: [],
        );

        self::assertSame(3, $result->pagesGenerated);
        self::assertCount(3, $result->paths);
        self::assertFalse($result->hasErrors());
        self::assertTrue($result->isComplete());
    }

    #[Test]
    public function generationWithErrors(): void
    {
        $result = new StaticSiteResult(
            pagesGenerated: 1,
            paths: ['/index.html'],
            errors: ['Failed to render /broken.html'],
        );

        self::assertSame(1, $result->pagesGenerated);
        self::assertTrue($result->hasErrors());
        self::assertFalse($result->isComplete());
        self::assertSame(['Failed to render /broken.html'], $result->errors);
    }

    #[Test]
    public function emptyGeneration(): void
    {
        $result = new StaticSiteResult(0, [], []);

        self::assertSame(0, $result->pagesGenerated);
        self::assertSame([], $result->paths);
        self::assertTrue($result->isComplete());
        self::assertFalse($result->hasErrors());
    }
}
