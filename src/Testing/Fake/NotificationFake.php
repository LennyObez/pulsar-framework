<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use Closure;
use PHPUnit\Framework\Assert;
use Pulsar\Api\Api;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationManagerInterface;

use function array_filter;
use function array_values;
use function count;
use function get_class;
use function implode;
use function sprintf;

/**
 * Fake notification manager that records all sent notifications for assertion.
 *
 * Captures the notifiable entity and notification instance for each send,
 * enabling tests to verify notifications were sent to the right recipients.
 * @api
 */
#[Api(since: '1.0.0')]
final class NotificationFake implements NotificationManagerInterface
{
    /** @var list<array{notifiable: NotifiableInterface, notification: Notification}> */
    private array $sentNotifications = [];

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $this->sentNotifications[] = [
            'notifiable' => $notifiable,
            'notification' => $notification,
        ];
    }

    public function sendNow(NotifiableInterface $notifiable, Notification $notification, ?array $channels = null): void
    {
        $this->sentNotifications[] = [
            'notifiable' => $notifiable,
            'notification' => $notification,
        ];
    }

    /**
     * Assert that a notification of the given class was sent.
     *
     * @param class-string<Notification> $notificationClass
     * @param int|null $count Exact number expected (null = at least one)
     */
    public function assertSent(string $notificationClass, ?int $count = null): void
    {
        $matching = $this->notificationsOfType($notificationClass);
        $matchCount = count($matching);

        if ($count !== null) {
            Assert::assertSame(
                $count,
                $matchCount,
                sprintf(
                    "Expected %d notification(s) of [%s], but %d were sent.\nSent notifications: %s",
                    $count,
                    $notificationClass,
                    $matchCount,
                    $this->formatSentList(),
                ),
            );
        } else {
            Assert::assertGreaterThan(
                0,
                $matchCount,
                sprintf(
                    "Expected notification [%s] to be sent, but it was not.\nSent notifications: %s",
                    $notificationClass,
                    $this->formatSentList(),
                ),
            );
        }
    }

    /**
     * Assert that a notification was sent to a specific notifiable.
     *
     * @param class-string<Notification> $notificationClass
     */
    public function assertSentTo(NotifiableInterface $notifiable, string $notificationClass): void
    {
        $matching = array_filter(
            $this->sentNotifications,
            static fn(array $entry): bool => $entry['notification'] instanceof $notificationClass
                && $entry['notifiable']->getNotifiableId() === $notifiable->getNotifiableId(),
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected notification [%s] to be sent to notifiable [%s], but it was not.\nSent to: %s",
                $notificationClass,
                $notifiable->getNotifiableId(),
                $this->formatRecipientsFor($notificationClass),
            ),
        );
    }

    /**
     * Assert that a notification matching a callback was sent.
     *
     * @param class-string<Notification> $notificationClass
     * @param Closure(Notification, NotifiableInterface): bool $callback
     */
    public function assertSentWith(string $notificationClass, Closure $callback): void
    {
        $matching = array_filter(
            $this->sentNotifications,
            static fn(array $entry): bool => $entry['notification'] instanceof $notificationClass
                && $callback($entry['notification'], $entry['notifiable']),
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected notification [%s] matching callback to be sent, but none matched.\nTotal [%s] sent: %d",
                $notificationClass,
                $notificationClass,
                count($this->notificationsOfType($notificationClass)),
            ),
        );
    }

    /**
     * Assert that a notification was NOT sent.
     *
     * @param class-string<Notification> $notificationClass
     */
    public function assertNotSent(string $notificationClass): void
    {
        $matching = $this->notificationsOfType($notificationClass);

        Assert::assertCount(
            0,
            $matching,
            sprintf(
                'Expected notification [%s] NOT to be sent, but it was sent %d time(s).',
                $notificationClass,
                count($matching),
            ),
        );
    }

    /**
     * Assert that no notifications were sent at all.
     */
    public function assertNothingSent(): void
    {
        Assert::assertCount(
            0,
            $this->sentNotifications,
            sprintf(
                "Expected no notifications to be sent, but %d were.\nSent: %s",
                count($this->sentNotifications),
                $this->formatSentList(),
            ),
        );
    }

    /**
     * Get all sent notifications.
     *
     * @return list<array{notifiable: NotifiableInterface, notification: Notification}>
     */
    public function sent(): array
    {
        return $this->sentNotifications;
    }

    /**
     * Get all sent notifications of a specific type.
     *
     * @param class-string<Notification> $notificationClass
     *
     * @return list<array{notifiable: NotifiableInterface, notification: Notification}>
     */
    public function notificationsOfType(string $notificationClass): array
    {
        return array_values(array_filter(
            $this->sentNotifications,
            static fn(array $entry): bool => $entry['notification'] instanceof $notificationClass,
        ));
    }

    /**
     * Reset all recorded state.
     */
    public function reset(): void
    {
        $this->sentNotifications = [];
    }

    private function formatSentList(): string
    {
        if ($this->sentNotifications === []) {
            return '(none)';
        }

        $classes = [];

        foreach ($this->sentNotifications as $entry) {
            $class = get_class($entry['notification']);

            if (!isset($classes[$class])) {
                $classes[$class] = 0;
            }

            ++$classes[$class];
        }

        $parts = [];

        foreach ($classes as $class => $classCount) {
            $parts[] = sprintf('%s (%dx)', $class, $classCount);
        }

        return implode(', ', $parts);
    }

    /**
     * @param class-string<Notification> $notificationClass
     */
    private function formatRecipientsFor(string $notificationClass): string
    {
        $ids = [];

        foreach ($this->sentNotifications as $entry) {
            if ($entry['notification'] instanceof $notificationClass) {
                $ids[] = $entry['notifiable']->getNotifiableId();
            }
        }

        return $ids === [] ? '(none)' : implode(', ', $ids);
    }
}
