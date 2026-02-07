<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Consent\UserPreferences;
use ReflectionClass;

#[CoversClass(UserPreferences::class)]
final class UserPreferencesTest extends TestCase
{
    #[Test]
    public function it_constructs_with_defaults(): void
    {
        $prefs = new UserPreferences('user-1');

        self::assertSame('user-1', $prefs->notifiableId);
        self::assertSame([], $prefs->channelPreferences);
        self::assertSame([], $prefs->consentRecords);
    }

    #[Test]
    public function it_checks_opted_in(): void
    {
        $prefs = new UserPreferences(
            notifiableId: 'user-1',
            channelPreferences: ['mail' => true, 'sms' => false],
        );

        self::assertTrue($prefs->isOptedIn('mail'));
        self::assertFalse($prefs->isOptedIn('sms'));
    }

    #[Test]
    public function it_returns_false_for_unknown_channel_opted_in(): void
    {
        $prefs = new UserPreferences('user-1');

        self::assertFalse($prefs->isOptedIn('unknown'));
    }

    #[Test]
    public function it_checks_opted_out(): void
    {
        $prefs = new UserPreferences(
            notifiableId: 'user-1',
            channelPreferences: ['mail' => true, 'sms' => false],
        );

        self::assertFalse($prefs->isOptedOut('mail'));
        self::assertTrue($prefs->isOptedOut('sms'));
    }

    #[Test]
    public function it_returns_false_for_unknown_channel_opted_out(): void
    {
        $prefs = new UserPreferences('user-1');

        // No preference recorded = not opted out
        self::assertFalse($prefs->isOptedOut('unknown'));
    }

    #[Test]
    public function it_distinguishes_not_set_from_opted_out(): void
    {
        $prefs = new UserPreferences(
            notifiableId: 'user-1',
            channelPreferences: ['mail' => false],
        );

        // 'mail' is explicitly false = opted out
        self::assertTrue($prefs->isOptedOut('mail'));
        self::assertFalse($prefs->isOptedIn('mail'));

        // 'sms' is not set = NOT opted out (and NOT opted in)
        self::assertFalse($prefs->isOptedOut('sms'));
        self::assertFalse($prefs->isOptedIn('sms'));
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $reflection = new ReflectionClass(UserPreferences::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
