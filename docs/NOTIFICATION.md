# Notifications

Pulsar's notification module provides multi-channel delivery with consent tracking, legal basis enforcement, and preference management. Notifications can be delivered through mail, SMS, database, Slack, webhooks, and logs -- with full audit trails for regulated industries.

## Quick Start

Enable notifications in `config/notification.php`:

```php
// config/notification.php
return [
    'enabled' => true,
    'default_channels' => ['mail'],
];
```

Create a notification and send it:

```php
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;
use Pulsar\Notification\Notification;
use Pulsar\Notification\NotifiableInterface;
use Pulsar\Notification\Consent\NotificationClassification;
use Pulsar\Notification\Consent\NotificationType;

#[NotificationType(classification: NotificationClassification::Transactional)]
final class OrderShippedNotification extends Notification
{
    public function __construct(
        private readonly string $trackingNumber,
    ) {}

    public function via(NotifiableInterface $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(NotifiableInterface $notifiable): Mailable
    {
        return new class($this->trackingNumber) extends Mailable {
            public function __construct(private readonly string $tracking) {}

            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Your order has shipped');
            }

            public function content(): Content
            {
                return new Content(
                    html: '<p>Tracking: ' . $this->tracking . '</p>',
                    text: 'Tracking: ' . $this->tracking,
                );
            }
        };
    }

    public function toDatabase(NotifiableInterface $notifiable): array
    {
        return [
            'type' => 'order_shipped',
            'tracking' => $this->trackingNumber,
        ];
    }
}

// Send
$notificationManager->send($user, new OrderShippedNotification('1Z999AA10123456784'));
```

## Configuration Reference

All options in `config/notification.php`:

| Key                       | Type         | Default | Env Override                   | Description                                        |
| ------------------------- | ------------ | ------- | ------------------------------ | -------------------------------------------------- |
| `enabled`                 | bool         | `false` | `NOTIFICATION_ENABLED`         | Enable the notification system                     |
| `default_channels`        | string[]     | `[]`    | --                             | Fallback channels when notification has no `via()` |
| `rate_limit_per_minute`   | int          | `60`    | `NOTIFICATION_RATE_LIMIT`      | Max notifications per minute per notifiable        |
| `regulated`               | bool         | `false` | `NOTIFICATION_REGULATED`       | Require legal basis for all notification types     |
| `audit_hash_enabled`      | bool         | `false` | `NOTIFICATION_AUDIT_HASH`      | HMAC-hash metadata in audit logs                   |
| `unsubscribe_url_pattern` | string\|null | `null`  | `NOTIFICATION_UNSUBSCRIBE_URL` | URL pattern for RFC 8058 List-Unsubscribe headers  |

## Notifiable Interface

Any entity that receives notifications must implement `NotifiableInterface`:

```php
use Pulsar\Notification\NotifiableInterface;

final class User implements NotifiableInterface
{
    public function routeNotificationFor(string $channel): mixed
    {
        return match ($channel) {
            'mail' => $this->email,
            'sms' => $this->phoneNumber,
            'slack' => $this->slackWebhookUrl,
            default => null,
        };
    }

    public function getNotifiableId(): string
    {
        return (string) $this->id;
    }

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
```

## Channels

Six built-in channels are available. Each channel is only registered if its dependencies are present in the container.

| Channel    | Dependency                           | Notification Method |
| ---------- | ------------------------------------ | ------------------- |
| `mail`     | `MailManagerInterface`               | `toMail()`          |
| `sms`      | `SmsGatewayInterface`                | `toSms()`           |
| `database` | `DatabaseNotificationStoreInterface` | `toDatabase()`      |
| `slack`    | `NotificationHttpClientInterface`    | `toSlack()`         |
| `webhook`  | `NotificationHttpClientInterface`    | `toWebhook()`       |
| `log`      | `LoggerInterface`                    | --                  |

### Mail Channel

Sends notifications as email via `MailManagerInterface`. Automatically adds RFC 8058 `List-Unsubscribe` and `List-Unsubscribe-Post` headers for marketing notifications when `unsubscribe_url_pattern` is configured.

```php
public function toMail(NotifiableInterface $notifiable): Mailable
{
    return (new WelcomeEmail($notifiable->name))
        ->to($notifiable->routeNotificationFor('mail'));
}
```

### SMS Channel

Sends text messages through a `SmsGatewayInterface` adapter (Twilio, Vonage, etc.):

```php
use Pulsar\Notification\SmsMessage;

public function toSms(NotifiableInterface $notifiable): SmsMessage
{
    return new SmsMessage(
        to: $notifiable->routeNotificationFor('sms'),
        body: 'Your verification code is 123456',
        from: '+15551234567',
    );
}
```

### Database Channel

Persists notifications for in-app notification feeds:

```php
public function toDatabase(NotifiableInterface $notifiable): array
{
    return [
        'type' => 'invoice_paid',
        'invoice_id' => $this->invoiceId,
        'amount' => $this->amount,
    ];
}
```

### Slack Channel

Delivers messages to Slack via incoming webhooks:

```php
use Pulsar\Notification\SlackMessage;

public function toSlack(NotifiableInterface $notifiable): SlackMessage
{
    return new SlackMessage(
        channel: '#alerts',
        text: 'Server CPU at 95%',
    );
}
```

### Webhook Channel

Sends arbitrary payloads to webhook endpoints:

```php
use Pulsar\Notification\WebhookPayload;

public function toWebhook(NotifiableInterface $notifiable): WebhookPayload
{
    return new WebhookPayload(
        url: 'https://partner.example.com/webhooks',
        data: ['event' => 'order.completed', 'order_id' => $this->orderId],
    );
}
```

## Notification Classification

Every notification class should declare its classification using the `#[NotificationType]` attribute:

```php
use Pulsar\Notification\Consent\NotificationClassification;
use Pulsar\Notification\Consent\NotificationType;

#[NotificationType(classification: NotificationClassification::Transactional)]
final class PasswordResetNotification extends Notification { /* ... */ }

#[NotificationType(classification: NotificationClassification::Marketing)]
final class NewsletterNotification extends Notification { /* ... */ }
```

| Classification  | Consent Behavior                          |
| --------------- | ----------------------------------------- |
| `Transactional` | Always delivered; bypasses opt-out checks |
| `Marketing`     | Requires explicit opt-in via preferences  |

The `NotificationClassificationRegistry` resolves classifications from attributes at runtime and caches the result.

## Consent and Preferences

### Preference Store

Implement `PreferenceStoreInterface` to persist user notification preferences:

```php
use Pulsar\Notification\Consent\PreferenceStoreInterface;
use Pulsar\Notification\Consent\UserPreferences;
use Pulsar\Notification\Consent\ConsentType;
use Pulsar\Notification\Consent\LegalBasis;
use Pulsar\Notification\Consent\ConsentSource;

final class DatabasePreferenceStore implements PreferenceStoreInterface
{
    public function getPreferences(string $notifiableId): UserPreferences { /* ... */ }

    public function updatePreference(
        string $notifiableId,
        string $channel,
        ConsentType $consentType,
        LegalBasis $legalBasis,
        ConsentSource $source,
        ?string $ipHash = null,
    ): void { /* ... */ }

    public function getConsentHistory(string $notifiableId): array { /* ... */ }
}
```

### Consent Types

| Type     | Description               |
| -------- | ------------------------- |
| `OptIn`  | User explicitly opted in  |
| `OptOut` | User explicitly opted out |

### Consent Sources

| Source       | Description                      |
| ------------ | -------------------------------- |
| `UserAction` | User clicked a preference toggle |
| `Api`        | Changed via API call             |
| `Import`     | Imported from external system    |

### Marketing Notification Flow

When a marketing notification is sent:

1. The `NotificationManager` checks the `NotificationClassificationRegistry`
2. If classified as `Marketing`, it queries `PreferenceStoreInterface`
3. If the notifiable has opted in to the channel, delivery proceeds
4. If not opted in, delivery is blocked and an audit log is recorded

Transactional notifications always bypass this check.

## Legal Basis

For regulated industries (GDPR, HIPAA), enable regulated mode:

```php
// config/notification.php
'regulated' => true,
```

Register legal bases for all notification types at boot:

```php
use Pulsar\Notification\Consent\LegalBasisRegistry;
use Pulsar\Notification\Consent\LegalBasis;

$registry = $container->get(LegalBasisRegistry::class);

$registry->register(PasswordResetNotification::class, LegalBasis::Contract);
$registry->register(SecurityAlertNotification::class, LegalBasis::LegitimateInterest);
$registry->register(NewsletterNotification::class, LegalBasis::Consent);

// Validate at boot -- throws if any registered type lacks a mapping
$registry->validate();
```

Available legal bases (GDPR Article 6):

| Legal Basis          | Description                                   |
| -------------------- | --------------------------------------------- |
| `Consent`            | Data subject gave explicit consent            |
| `Contract`           | Processing necessary for contract performance |
| `LegalObligation`    | Required by law                               |
| `VitalInterest`      | Necessary to protect life                     |
| `PublicTask`         | Necessary for public interest                 |
| `LegitimateInterest` | Legitimate interest with balancing test       |

## Internationalization

Notifications support locale-aware content delivery:

```php
// Set locale explicitly
$notification->locale('fr');

// Or let the channel resolve from the notifiable
// Priority: explicit locale > notifiable's preferredLocale() > default locale
```

Template keys follow the pattern `notifications.{type}.{channel}.subject` and `notifications.{type}.{channel}.body` for use with `TranslatorInterface`.

## Async Delivery

Use `SendNotificationJob` for queue-based async delivery:

```php
use Pulsar\Notification\Job\SendNotificationJob;

$job = new SendNotificationJob(
    notifiable: $user,
    notification: new OrderShippedNotification($tracking),
    notificationManager: $notificationManager,
    channels: ['mail', 'sms'],  // null = use notification's via()
    queueName: 'notifications',
    maxAttemptCount: 3,
    timeoutSeconds: 60,
);

$queueManager->dispatch($job);
```

## Events

Events are dispatched via `EventDispatcherInterface` when available:

| Event                | When                       | Data                                          |
| -------------------- | -------------------------- | --------------------------------------------- |
| `NotificationSent`   | Channel delivery succeeded | notificationId, notifiableId, channel         |
| `NotificationFailed` | Channel delivery failed    | notificationId, notifiableId, channel, reason |

## Audit Logging

When an `AuditLoggerInterface` is available, the notification manager logs:

| Event                    | Audit Action                 | Outcome   | Metadata                                                |
| ------------------------ | ---------------------------- | --------- | ------------------------------------------------------- |
| Delivery succeeded       | `notification.sent`          | `success` | notification_id, notifiable_id, channel, classification |
| Delivery failed          | `notification.failed`        | `failure` | notification_id, notifiable_id, channel, reason         |
| Consent check (opted in) | `notification.consent_check` | `success` | notification_id, notifiable_id, channel, result         |
| Consent check (blocked)  | `notification.consent_check` | `denied`  | notification_id, notifiable_id, channel, result         |

## Webhook Integration for Bounces/Complaints

Mail providers send bounce and complaint events via webhooks. Pulsar's `WebhookHandler` provides a secure processing pipeline:

```
Request -> Signature Verify -> Replay Check -> Dedup -> Parse -> Audit Log
```

### Setup

Register the webhook handler and configure a verifier for your provider:

```php
use Pulsar\Mail\Webhook\WebhookHandler;
use Pulsar\Mail\Webhook\WebhookRequest;
use Pulsar\Mail\Webhook\InMemoryDeduplicationStore;

$handler = new WebhookHandler(
    verifier: $providerVerifier,
    deduplicationStore: new InMemoryDeduplicationStore(),
    auditLogger: $auditLogger,
    replayWindowSeconds: 300,
);

$result = $handler->handle(new WebhookRequest(
    provider: 'ses',
    payload: $requestBody,
    signature: $signatureHeader,
    timestamp: $timestampHeader,
    eventId: $eventIdHeader,
));

if ($result->accepted) {
    // Process $result->eventType (bounce, complaint, delivery, open, click)
}
```

Dedicated `BounceHandler` and `ComplaintHandler` process provider payloads and update delivery status with audit logging.

## Testing

Use the `LogChannel` for notification testing:

```php
use Pulsar\Config\NotificationChannelType;
use Pulsar\Config\NotificationConfig;
use Pulsar\Notification\Channel\LogChannel;
use Pulsar\Notification\NotificationManager;
use Psr\Log\NullLogger;

// Create a manager with only the log channel
$logger = new NullLogger();  // or a test logger that captures messages
$config = new NotificationConfig(enabled: true);
$channels = ['log' => new LogChannel($logger)];
$manager = new NotificationManager($config, $channels);

$manager->send($notifiable, $notification);
```

For mail channel testing, combine with `ArrayTransport` (see [MAIL.md](MAIL.md#testing)).

## See Also

- [`MAIL.md`](MAIL.md) -- Mail system (used by the mail channel)
- [`COMPLIANCE.md`](COMPLIANCE.md) -- Compliance framework
- [`AUDIT_LOGGING.md`](AUDIT_LOGGING.md) -- Audit logging
- [`EVENTS.md`](EVENTS.md) -- Event system
- [`I18N.md`](I18N.md) -- Internationalization
