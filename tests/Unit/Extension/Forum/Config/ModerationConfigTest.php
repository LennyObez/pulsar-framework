<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\ModerationConfig;

#[CoversClass(ModerationConfig::class)]
final class ModerationConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new ModerationConfig();
        self::assertSame(5, $config->autoHideThreshold);
        self::assertSame(3, $config->notifyThreshold);
        self::assertSame(90, $config->dismissedReportRetentionDays);
    }

    #[Test]
    public function fromArrayWithEmptyArray(): void
    {
        $config = ModerationConfig::fromArray([]);
        self::assertSame(5, $config->autoHideThreshold);
        self::assertSame(3, $config->notifyThreshold);
        self::assertSame(90, $config->dismissedReportRetentionDays);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = ModerationConfig::fromArray([
            'auto_hide_threshold' => 10,
            'notify_threshold' => 5,
            'dismissed_report_retention_days' => 180,
        ]);
        self::assertSame(10, $config->autoHideThreshold);
        self::assertSame(5, $config->notifyThreshold);
        self::assertSame(180, $config->dismissedReportRetentionDays);
    }

    #[Test]
    public function fromArrayWithPartialData(): void
    {
        $config = ModerationConfig::fromArray(['auto_hide_threshold' => 8]);
        self::assertSame(8, $config->autoHideThreshold);
        self::assertSame(3, $config->notifyThreshold);
    }

    #[Test]
    public function fromArrayIgnoresNonIntValues(): void
    {
        $config = ModerationConfig::fromArray([
            'auto_hide_threshold' => 'abc',
            'notify_threshold' => 3.5,
            'dismissed_report_retention_days' => null,
        ]);
        self::assertSame(5, $config->autoHideThreshold);
        self::assertSame(3, $config->notifyThreshold);
        self::assertSame(90, $config->dismissedReportRetentionDays);
    }
}
