<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\BadgeConfig;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Config\ModerationConfig;
use Pulsar\Extension\Forum\Config\ReputationConfig;

#[CoversClass(ForumConfig::class)]
#[CoversClass(ModerationConfig::class)]
#[CoversClass(ReputationConfig::class)]
#[CoversClass(BadgeConfig::class)]
final class ForumConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new ForumConfig();

        self::assertSame(25, $config->threadsPerPage);
        self::assertSame(20, $config->postsPerPage);
        self::assertSame(30, $config->postCooldownSeconds);
        self::assertFalse($config->requireThreadApproval);
        self::assertTrue($config->allowGuestViewing);
        self::assertSame(200, $config->maxTitleLength);
        self::assertSame(50_000, $config->maxBodyLength);
        self::assertSame(5, $config->maxTagsPerThread);
        self::assertSame(30, $config->editWindowMinutes);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = ForumConfig::fromArray([
            'threads_per_page' => 50,
            'posts_per_page' => 30,
            'post_cooldown_seconds' => 60,
            'require_thread_approval' => true,
            'allow_guest_viewing' => false,
            'max_title_length' => 100,
            'max_body_length' => 25_000,
            'max_tags_per_thread' => 10,
            'edit_window_minutes' => 15,
        ]);

        self::assertSame(50, $config->threadsPerPage);
        self::assertSame(30, $config->postsPerPage);
        self::assertSame(60, $config->postCooldownSeconds);
        self::assertTrue($config->requireThreadApproval);
        self::assertFalse($config->allowGuestViewing);
        self::assertSame(100, $config->maxTitleLength);
        self::assertSame(25_000, $config->maxBodyLength);
        self::assertSame(10, $config->maxTagsPerThread);
        self::assertSame(15, $config->editWindowMinutes);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = ForumConfig::fromArray([]);

        self::assertSame(25, $config->threadsPerPage);
        self::assertSame(20, $config->postsPerPage);
    }

    #[Test]
    public function fromArrayIncludesNestedConfigs(): void
    {
        $config = ForumConfig::fromArray([
            'moderation' => [
                'auto_hide_threshold' => 10,
            ],
        ]);

        self::assertSame(10, $config->moderation->autoHideThreshold);
    }

    #[Test]
    public function moderationConfigDefaults(): void
    {
        $config = new ModerationConfig();

        self::assertSame(5, $config->autoHideThreshold);
        self::assertSame(3, $config->notifyThreshold);
        self::assertSame(90, $config->dismissedReportRetentionDays);
    }

    #[Test]
    public function moderationConfigFromArray(): void
    {
        $config = ModerationConfig::fromArray([
            'auto_hide_threshold' => 8,
            'notify_threshold' => 5,
            'dismissed_report_retention_days' => 30,
        ]);

        self::assertSame(8, $config->autoHideThreshold);
        self::assertSame(5, $config->notifyThreshold);
        self::assertSame(30, $config->dismissedReportRetentionDays);
    }
}
