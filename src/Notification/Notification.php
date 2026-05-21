<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use BadMethodCallException;
use Pulsar\Api\Api;
use Pulsar\Mail\Mailable;

/**
 * Base class for all notifications.
 *
 * Concrete notifications extend this class and implement the delivery
 * methods for channels they support (toMail, toSms, etc.).
 * @api
 */
#[Api(since: '1.0.0')]
abstract class Notification
{
    protected ?string $localeValue = null;

    /**
     * Determine which channels the notification should be delivered through.
     *
     * @return list<string> Channel names (matching NotificationChannelType values)
     */
    abstract public function via(NotifiableInterface $notifiable): array;

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(NotifiableInterface $notifiable): Mailable
    {
        unset($notifiable);
        throw new BadMethodCallException('toMail() is not implemented for ' . static::class);
    }

    /**
     * Build the database representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(NotifiableInterface $notifiable): array
    {
        unset($notifiable);
        throw new BadMethodCallException('toDatabase() is not implemented for ' . static::class);
    }

    /**
     * Build the Slack message representation of the notification.
     */
    public function toSlack(NotifiableInterface $notifiable): SlackMessage
    {
        unset($notifiable);
        throw new BadMethodCallException('toSlack() is not implemented for ' . static::class);
    }

    /**
     * Build the webhook payload representation of the notification.
     */
    public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
    {
        unset($notifiable);
        throw new BadMethodCallException('toWebhook() is not implemented for ' . static::class);
    }

    /**
     * Build the SMS message representation of the notification.
     */
    public function toSms(NotifiableInterface $notifiable): SmsMessage
    {
        unset($notifiable);
        throw new BadMethodCallException('toSms() is not implemented for ' . static::class);
    }

    /**
     * Build the broadcast (WebSocket) representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toBroadcast(NotifiableInterface $notifiable): array
    {
        unset($notifiable);
        throw new BadMethodCallException('toBroadcast() is not implemented for ' . static::class);
    }

    /**
     * Build the push notification representation.
     */
    public function toPush(NotifiableInterface $notifiable): PushMessage
    {
        unset($notifiable);
        throw new BadMethodCallException('toPush() is not implemented for ' . static::class);
    }

    /**
     * Set the locale for this notification.
     *
     * @return $this
     */
    public function locale(?string $locale): static
    {
        $this->localeValue = $locale;

        return $this;
    }

    /**
     * Get the configured locale, if any.
     */
    public function getLocale(): ?string
    {
        return $this->localeValue;
    }
}
