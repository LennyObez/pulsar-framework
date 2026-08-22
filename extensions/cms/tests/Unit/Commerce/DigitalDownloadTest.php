<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\DigitalDownload;

#[CoversClass(DigitalDownload::class)]
final class DigitalDownloadTest extends TestCase
{
    #[Test]
    public function isValidReturnsTrueWhenDownloadsRemainingAndNotExpired(): void
    {
        $download = new DigitalDownload(
            id: 'dl-001',
            orderItemId: 'oi-001',
            digitalAssetId: 'da-001',
            downloadToken: 'secure-token',
            downloadsRemaining: 3,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertTrue($download->isValid());
    }

    #[Test]
    public function isValidReturnsFalseWhenNoDownloadsRemaining(): void
    {
        $download = new DigitalDownload(
            id: 'dl-002',
            orderItemId: 'oi-001',
            digitalAssetId: 'da-001',
            downloadToken: 'token',
            downloadsRemaining: 0,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertFalse($download->isValid());
    }

    #[Test]
    public function isValidReturnsFalseWhenExpired(): void
    {
        $download = new DigitalDownload(
            id: 'dl-003',
            orderItemId: 'oi-001',
            digitalAssetId: 'da-001',
            downloadToken: 'token',
            downloadsRemaining: 5,
            expiresAt: new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($download->isValid());
    }

    #[Test]
    public function isValidAcceptsExplicitNowParameter(): void
    {
        $expiresAt = new DateTimeImmutable('2026-06-01T00:00:00Z');

        $download = new DigitalDownload(
            id: 'dl-004',
            orderItemId: 'oi-001',
            digitalAssetId: 'da-001',
            downloadToken: 'token',
            downloadsRemaining: 1,
            expiresAt: $expiresAt,
        );

        $beforeExpiry = new DateTimeImmutable('2026-05-31T23:59:59Z');
        $afterExpiry = new DateTimeImmutable('2026-06-01T00:00:01Z');

        self::assertTrue($download->isValid($beforeExpiry));
        self::assertFalse($download->isValid($afterExpiry));
    }
}
