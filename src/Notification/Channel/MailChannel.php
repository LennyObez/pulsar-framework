<?php

declare(strict_types=1);

namespace Pulsar\Notification\Channel;

use Pulsar\Api\Internal;
use Pulsar\Config\NotificationChannelType;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Notification\Consent\NotificationClassification;
use Pulsar\Notification\Consent\NotificationClassificationRegistry;
use Pulsar\Notification\Exception\NotificationException;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotificationChannelInterface;
use Throwable;

use function is_string;
use function str_replace;
use function urlencode;

/**
 * Delivers notifications via email using the mail manager.
 *
 * For marketing notifications, automatically adds RFC 8058 List-Unsubscribe
 * and List-Unsubscribe-Post headers when an unsubscribe URL pattern is configured.
 */
#[Internal]
final readonly class MailChannel implements NotificationChannelInterface
{
    /**
     * @param string|null $unsubscribeUrlPattern URL pattern with {notifiable_id} and {channel} placeholders
     */
    public function __construct(
        private MailManagerInterface $mailManager,
        private ?NotificationClassificationRegistry $classificationRegistry = null,
        private ?string $unsubscribeUrlPattern = null,
    ) {}

    public function send(NotifiableInterface $notifiable, Notification $notification): void
    {
        $mailable = $notification->toMail($notifiable);

        $route = $notifiable->routeNotificationFor($this->name());

        if (is_string($route)) {
            $mailable->to($route);
        }

        $locale = $notification->getLocale() ?? $notifiable->preferredLocale();

        if ($locale !== null) {
            $mailable->locale($locale);
        }

        $this->applyUnsubscribeHeaders($mailable, $notifiable, $notification);

        try {
            $this->mailManager->send($mailable);
        } catch (Throwable $e) {
            throw NotificationException::deliveryFailed(
                $this->name(),
                $notifiable->getNotifiableId(),
                $e,
            );
        }
    }

    public function name(): string
    {
        return NotificationChannelType::Mail->value;
    }

    /**
     * Add RFC 8058 unsubscribe headers for marketing notifications.
     */
    private function applyUnsubscribeHeaders(
        Mailable $mailable,
        NotifiableInterface $notifiable,
        Notification $notification,
    ): void {
        if ($this->classificationRegistry === null || $this->unsubscribeUrlPattern === null) {
            return;
        }

        $classification = $this->classificationRegistry->classify($notification::class);

        if ($classification !== NotificationClassification::Marketing) {
            return;
        }

        $unsubscribeUrl = str_replace(
            ['{notifiable_id}', '{channel}'],
            [urlencode($notifiable->getNotifiableId()), urlencode($this->name())],
            $this->unsubscribeUrlPattern,
        );

        $mailable->header('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $mailable->header('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }
}
