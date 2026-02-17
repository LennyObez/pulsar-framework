<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CommentsConfig;

#[CoversClass(CommentsConfig::class)]
final class CommentsConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreReasonable(): void
    {
        $config = new CommentsConfig();

        self::assertTrue($config->enabled);
        self::assertFalse($config->autoApproveAuthenticated);
        self::assertSame(15, $config->editWindowMinutes);
        self::assertSame(3, $config->maxNestingDepth);
        self::assertSame(5, $config->rateLimitPerMinute);
        self::assertSame(30, $config->rateLimitPerHour);
        self::assertTrue($config->guestCommentsAllowed);
        self::assertFalse($config->requireEmail);
        self::assertSame(10_000, $config->maxBodyLength);
        self::assertSame(3, $config->maxLinksPerComment);
        self::assertSame('website_url', $config->honeypotFieldName);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = CommentsConfig::fromArray([
            'enabled' => false,
            'auto_approve_authenticated' => true,
            'edit_window_minutes' => 30,
            'max_nesting_depth' => 5,
            'rate_limit_per_minute' => 2,
            'rate_limit_per_hour' => 10,
            'guest_comments_allowed' => false,
            'require_email' => true,
            'max_body_length' => 5000,
            'max_links_per_comment' => 1,
            'honeypot_field_name' => 'hp_trap',
        ]);

        self::assertFalse($config->enabled);
        self::assertTrue($config->autoApproveAuthenticated);
        self::assertSame(30, $config->editWindowMinutes);
        self::assertSame(5, $config->maxNestingDepth);
        self::assertSame(2, $config->rateLimitPerMinute);
        self::assertSame(10, $config->rateLimitPerHour);
        self::assertFalse($config->guestCommentsAllowed);
        self::assertTrue($config->requireEmail);
        self::assertSame(5000, $config->maxBodyLength);
        self::assertSame(1, $config->maxLinksPerComment);
        self::assertSame('hp_trap', $config->honeypotFieldName);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CommentsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertFalse($config->autoApproveAuthenticated);
        self::assertSame(15, $config->editWindowMinutes);
        self::assertSame(3, $config->maxNestingDepth);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = CommentsConfig::fromArray([
            'enabled' => 'yes',
            'auto_approve_authenticated' => 1,
            'edit_window_minutes' => '15',
            'max_nesting_depth' => 3.5,
            'rate_limit_per_minute' => true,
            'guest_comments_allowed' => 'true',
            'max_body_length' => '10000',
            'honeypot_field_name' => 42,
        ]);

        // Non-bool → defaults
        self::assertTrue($config->enabled);
        self::assertFalse($config->autoApproveAuthenticated);
        self::assertTrue($config->guestCommentsAllowed);
        // Non-int → defaults
        self::assertSame(15, $config->editWindowMinutes);
        self::assertSame(3, $config->maxNestingDepth);
        self::assertSame(5, $config->rateLimitPerMinute);
        self::assertSame(10_000, $config->maxBodyLength);
        // Non-string → default
        self::assertSame('website_url', $config->honeypotFieldName);
    }

    #[Test]
    public function zeroValuesAreAccepted(): void
    {
        $config = new CommentsConfig(
            editWindowMinutes: 0,
            maxNestingDepth: 0,
            rateLimitPerMinute: 0,
            maxBodyLength: 0,
            maxLinksPerComment: 0,
        );

        self::assertSame(0, $config->editWindowMinutes);
        self::assertSame(0, $config->maxNestingDepth);
        self::assertSame(0, $config->rateLimitPerMinute);
        self::assertSame(0, $config->maxBodyLength);
        self::assertSame(0, $config->maxLinksPerComment);
    }
}
