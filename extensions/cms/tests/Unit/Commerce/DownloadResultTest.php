<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\DownloadResult;

#[CoversClass(DownloadResult::class)]
final class DownloadResultTest extends TestCase
{
    #[Test]
    public function successfulDownload(): void
    {
        $result = new DownloadResult(
            success: true,
            filePath: '/storage/files/ebook.pdf',
            fileName: 'ebook.pdf',
            downloadsRemaining: 2,
        );

        self::assertTrue($result->success);
        self::assertSame('/storage/files/ebook.pdf', $result->filePath);
        self::assertSame('ebook.pdf', $result->fileName);
        self::assertSame(2, $result->downloadsRemaining);
    }

    #[Test]
    public function failedDownload(): void
    {
        $result = new DownloadResult(
            success: false,
            filePath: null,
            fileName: null,
            downloadsRemaining: null,
        );

        self::assertFalse($result->success);
        self::assertNull($result->filePath);
        self::assertNull($result->fileName);
        self::assertNull($result->downloadsRemaining);
    }
}
