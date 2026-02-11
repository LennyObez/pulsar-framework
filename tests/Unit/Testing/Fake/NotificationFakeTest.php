<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Fake;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Testing\Fake\NotificationFake;

#[CoversClass(NotificationFake::class)]
final class NotificationFakeTest extends TestCase
{
    private NotificationFake $fake;

    private TestNotifiable $notifiable;

    protected function setUp(): void
    {
        $this->fake = new NotificationFake();
        $this->notifiable = new TestNotifiable('user-1');
    }

    #[Test]
    public function send_records_notification(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());

        self::assertCount(1, $this->fake->sent());
    }

    #[Test]
    public function send_now_records_notification(): void
    {
        $this->fake->sendNow($this->notifiable, new TestNotification());

        self::assertCount(1, $this->fake->sent());
    }

    #[Test]
    public function assert_sent_passes_when_notification_exists(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());

        $this->fake->assertSent(TestNotification::class);
    }

    #[Test]
    public function assert_sent_fails_when_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected notification');

        $this->fake->assertSent(TestNotification::class);
    }

    #[Test]
    public function assert_sent_with_exact_count(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());
        $this->fake->send($this->notifiable, new TestNotification());

        $this->fake->assertSent(TestNotification::class, 2);
    }

    #[Test]
    public function assert_sent_to_specific_notifiable(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());

        $this->fake->assertSentTo($this->notifiable, TestNotification::class);
    }

    #[Test]
    public function assert_sent_to_fails_when_wrong_notifiable(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());
        $other = new TestNotifiable('user-2');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('user-2');

        $this->fake->assertSentTo($other, TestNotification::class);
    }

    #[Test]
    public function assert_sent_with_callback(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());

        $this->fake->assertSentWith(
            TestNotification::class,
            static fn(Notification $n, NotifiableInterface $to): bool => $to->getNotifiableId() === 'user-1',
        );
    }

    #[Test]
    public function assert_not_sent_passes_when_absent(): void
    {
        $this->fake->assertNotSent(TestNotification::class);
    }

    #[Test]
    public function assert_nothing_sent_passes_when_empty(): void
    {
        $this->fake->assertNothingSent();
    }

    #[Test]
    public function assert_nothing_sent_fails_when_not_empty(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected no notifications');

        $this->fake->assertNothingSent();
    }

    #[Test]
    public function notifications_of_type_returns_filtered_list(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());

        self::assertCount(1, $this->fake->notificationsOfType(TestNotification::class));
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        $this->fake->send($this->notifiable, new TestNotification());

        $this->fake->reset();

        self::assertCount(0, $this->fake->sent());
    }
}

/**
 * @internal Test-only notification
 */
final class TestNotification extends Notification
{
    public function via(NotifiableInterface $notifiable): array
    {
        return ['mail'];
    }
}

/**
 * @internal Test-only notifiable
 */
final class TestNotifiable implements NotifiableInterface
{
    public function __construct(
        private readonly string $id,
    ) {}

    public function routeNotificationFor(string $channel): mixed
    {
        return match ($channel) {
            'mail' => $this->id . '@test.com',
            default => null,
        };
    }

    public function getNotifiableId(): string
    {
        return $this->id;
    }

    public function preferredLocale(): ?string
    {
        return null;
    }
}
