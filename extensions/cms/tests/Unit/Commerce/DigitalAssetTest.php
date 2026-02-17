<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\DigitalAsset;

#[CoversClass(DigitalAsset::class)]
final class DigitalAssetTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $asset = new DigitalAsset(
            id: 'da-001',
            productId: 'prod-001',
            fileStoragePath: '/storage/files/ebook.pdf',
            fileHash: 'abc123sha256',
            fileName: 'ebook.pdf',
            fileSize: 5242880,
            maxDownloads: 3,
        );

        self::assertSame('da-001', $asset->id);
        self::assertSame('prod-001', $asset->productId);
        self::assertSame('/storage/files/ebook.pdf', $asset->fileStoragePath);
        self::assertSame('abc123sha256', $asset->fileHash);
        self::assertSame('ebook.pdf', $asset->fileName);
        self::assertSame(5242880, $asset->fileSize);
        self::assertSame(3, $asset->maxDownloads);
    }
}
