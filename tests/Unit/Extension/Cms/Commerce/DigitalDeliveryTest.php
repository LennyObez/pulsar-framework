<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\DigitalAsset;
use Pulsar\Extension\Cms\Commerce\DigitalDownload;
use Pulsar\Extension\Cms\Commerce\DownloadResult;

use function strlen;

#[CoversClass(DigitalDownload::class)]
#[CoversClass(DigitalAsset::class)]
#[CoversClass(DownloadResult::class)]
final class DigitalDeliveryTest extends TestCase
{
    // ── Token generation produces non-empty string ──────────────────

    #[Test]
    public function test_download_token_is_non_empty(): void
    {
        $download = new DigitalDownload(
            id: 'dl-001',
            orderItemId: 'item-001',
            digitalAssetId: 'asset-001',
            downloadToken: bin2hex(random_bytes(32)),
            downloadsRemaining: 3,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertNotEmpty($download->downloadToken);
        self::assertSame(64, strlen($download->downloadToken)); // 32 bytes = 64 hex chars
    }

    // ── Expiry check: future = valid ────────────────────────────────

    #[Test]
    public function test_future_expiry_is_valid(): void
    {
        $download = new DigitalDownload(
            id: 'dl-002',
            orderItemId: 'item-002',
            digitalAssetId: 'asset-002',
            downloadToken: 'token-valid',
            downloadsRemaining: 5,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertTrue($download->isValid());
    }

    // ── Expiry check: past = invalid ────────────────────────────────

    #[Test]
    public function test_past_expiry_is_invalid(): void
    {
        $download = new DigitalDownload(
            id: 'dl-003',
            orderItemId: 'item-003',
            digitalAssetId: 'asset-003',
            downloadToken: 'token-expired',
            downloadsRemaining: 5,
            expiresAt: new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($download->isValid());
    }

    // ── Download count enforcement: 0 remaining = rejected ──────────

    #[Test]
    public function test_zero_remaining_downloads_is_invalid(): void
    {
        $download = new DigitalDownload(
            id: 'dl-004',
            orderItemId: 'item-004',
            digitalAssetId: 'asset-004',
            downloadToken: 'token-exhausted',
            downloadsRemaining: 0,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertFalse($download->isValid());
    }

    #[Test]
    public function test_one_remaining_download_is_valid(): void
    {
        $download = new DigitalDownload(
            id: 'dl-005',
            orderItemId: 'item-005',
            digitalAssetId: 'asset-005',
            downloadToken: 'token-last',
            downloadsRemaining: 1,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertTrue($download->isValid());
    }

    // ── Explicit now parameter ──────────────────────────────────────

    #[Test]
    public function test_is_valid_with_explicit_now(): void
    {
        $expiry = new DateTimeImmutable('2025-06-01');

        $download = new DigitalDownload(
            id: 'dl-006',
            orderItemId: 'item-006',
            digitalAssetId: 'asset-006',
            downloadToken: 'token-explicit',
            downloadsRemaining: 2,
            expiresAt: $expiry,
        );

        $beforeExpiry = new DateTimeImmutable('2025-05-15');
        $afterExpiry = new DateTimeImmutable('2025-07-01');

        self::assertTrue($download->isValid($beforeExpiry));
        self::assertFalse($download->isValid($afterExpiry));
    }

    // ── DownloadResult DTO ──────────────────────────────────────────

    #[Test]
    public function test_download_result_success(): void
    {
        $result = new DownloadResult(
            success: true,
            filePath: '/storage/assets/ebook.pdf',
            fileName: 'ebook.pdf',
            downloadsRemaining: 4,
        );

        self::assertTrue($result->success);
        self::assertSame('/storage/assets/ebook.pdf', $result->filePath);
        self::assertSame('ebook.pdf', $result->fileName);
        self::assertSame(4, $result->downloadsRemaining);
    }

    #[Test]
    public function test_download_result_failure(): void
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

    // ── DigitalAsset entity ─────────────────────────────────────────

    #[Test]
    public function test_digital_asset_creation(): void
    {
        $asset = new DigitalAsset(
            id: 'asset-001',
            productId: 'product-001',
            fileStoragePath: '/storage/digital/ebook.pdf',
            fileHash: 'abc123def456',
            fileName: 'ebook.pdf',
            fileSize: 1_500_000,
            maxDownloads: 5,
        );

        self::assertSame('asset-001', $asset->id);
        self::assertSame('product-001', $asset->productId);
        self::assertSame('/storage/digital/ebook.pdf', $asset->fileStoragePath);
        self::assertSame('abc123def456', $asset->fileHash);
        self::assertSame('ebook.pdf', $asset->fileName);
        self::assertSame(1_500_000, $asset->fileSize);
        self::assertSame(5, $asset->maxDownloads);
    }
}
