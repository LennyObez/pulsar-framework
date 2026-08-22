<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\BadgeConfig;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Config\ModerationConfig;
use Pulsar\Extension\Forum\Config\ReputationConfig;

final class ForumConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreApplied(): void
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
        self::assertInstanceOf(ModerationConfig::class, $config->moderation);
        self::assertInstanceOf(ReputationConfig::class, $config->reputation);
        self::assertInstanceOf(BadgeConfig::class, $config->badges);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = ForumConfig::fromArray([]);

        self::assertSame(25, $config->threadsPerPage);
        self::assertSame(20, $config->postsPerPage);
        self::assertSame(30, $config->postCooldownSeconds);
        self::assertFalse($config->requireThreadApproval);
        self::assertTrue($config->allowGuestViewing);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = ForumConfig::fromArray([
            'threads_per_page' => 50,
            'posts_per_page' => 10,
            'post_cooldown_seconds' => 60,
            'require_thread_approval' => true,
            'allow_guest_viewing' => false,
            'max_title_length' => 100,
            'max_body_length' => 25_000,
            'max_tags_per_thread' => 3,
            'edit_window_minutes' => 15,
        ]);

        self::assertSame(50, $config->threadsPerPage);
        self::assertSame(10, $config->postsPerPage);
        self::assertSame(60, $config->postCooldownSeconds);
        self::assertTrue($config->requireThreadApproval);
        self::assertFalse($config->allowGuestViewing);
        self::assertSame(100, $config->maxTitleLength);
        self::assertSame(25_000, $config->maxBodyLength);
        self::assertSame(3, $config->maxTagsPerThread);
        self::assertSame(15, $config->editWindowMinutes);
    }

    #[Test]
    public function fromArrayIgnoresNonIntegerValues(): void
    {
        $config = ForumConfig::fromArray([
            'threads_per_page' => 'not_a_number',
            'posts_per_page' => null,
            'post_cooldown_seconds' => 3.14,
        ]);

        self::assertSame(25, $config->threadsPerPage);
        self::assertSame(20, $config->postsPerPage);
        self::assertSame(30, $config->postCooldownSeconds);
    }

    #[Test]
    public function fromArrayDelegatesSubConfigs(): void
    {
        $config = ForumConfig::fromArray([
            'moderation' => ['auto_hide_threshold' => 10],
            'reputation' => ['points_per_thread' => 5],
            'badges' => ['enabled' => false],
        ]);

        self::assertSame(10, $config->moderation->autoHideThreshold);
        self::assertSame(5, $config->reputation->pointsPerThread);
        self::assertFalse($config->badges->enabled);
    }

    #[Test]
    public function fromArrayHandlesNonArraySubConfigs(): void
    {
        $config = ForumConfig::fromArray([
            'moderation' => 'invalid',
            'reputation' => 42,
            'badges' => null,
        ]);

        self::assertSame(5, $config->moderation->autoHideThreshold);
        self::assertSame(2, $config->reputation->pointsPerThread);
        self::assertTrue($config->badges->enabled);
    }
}
