<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\Site;

final class SiteTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $now = new DateTimeImmutable('2026-03-01');

        $site = new Site(
            id: 'site-1',
            domain: 'example.com',
            name: 'Example Site',
            trackingId: 'plsr_abc12345',
            timezone: 'America/New_York',
            settings: ['theme' => 'dark'],
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertSame('site-1', $site->id);
        self::assertSame('example.com', $site->domain);
        self::assertSame('Example Site', $site->name);
        self::assertSame('plsr_abc12345', $site->trackingId);
        self::assertSame('America/New_York', $site->timezone);
        self::assertSame(['theme' => 'dark'], $site->settings);
        self::assertSame($now, $site->createdAt);
        self::assertSame($now, $site->updatedAt);
    }

    #[Test]
    public function constructWithDefaults(): void
    {
        $site = new Site(
            id: 'site-2',
            domain: 'test.com',
            name: 'Test',
            trackingId: 'plsr_test0001',
        );

        self::assertSame('UTC', $site->timezone);
        self::assertSame([], $site->settings);
        self::assertInstanceOf(DateTimeImmutable::class, $site->createdAt);
        self::assertInstanceOf(DateTimeImmutable::class, $site->updatedAt);
    }
}
