# Mail

Pulsar's mail module provides a unified API for sending email through multiple transport backends. It supports typed Mailable classes, inline and file attachments, TLS encryption policy enforcement, HIPAA-compliant PHI scrubbing, audit logging, and async delivery via the queue system.

## Quick start

Enable mail in `config/mail.php` and set a default sender:

```php
// config/mail.php
return [
    'enabled' => true,
    'default_driver' => 'smtp',
    'default_from_address' => 'noreply@example.com',
    'default_from_name' => 'My App',
];
```

Create a Mailable and send it:

```php
use Pulsar\Mail\Address;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManagerInterface;

final class WelcomeEmail extends Mailable
{
    public function __construct(
        private readonly string $userName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to the platform',
            to: [],
        );
    }

    public function content(): Content
    {
        return new Content(
            html: '<h1>Welcome, ' . $this->userName . '!</h1>',
            text: 'Welcome, ' . $this->userName . '!',
        );
    }
}

// Send via the mail manager
$mailManager->send(
    (new WelcomeEmail('Alice'))->to('alice@example.com'),
);
```

## Configuration reference

All options in `config/mail.php`:

| Key                    | Type   | Default | Env Override             | Description                             |
| ---------------------- | ------ | ------- | ------------------------ | --------------------------------------- |
| `enabled`              | bool   | `false` | `MAIL_ENABLED`           | Enable the mail system                  |
| `default_driver`       | string | `smtp`  | `MAIL_DRIVER`            | Default transport driver                |
| `default_from_address` | string | `''`    | `MAIL_FROM_ADDRESS`      | Default sender email                    |
| `default_from_name`    | string | `''`    | `MAIL_FROM_NAME`         | Default sender display name             |
| `default_reply_to`     | string | `''`    | `MAIL_REPLY_TO`          | Default reply-to address                |
| `encryption_policy`    | string | `none`  | `MAIL_ENCRYPTION_POLICY` | TLS policy: `require`, `prefer`, `none` |
| `hipaa_mode`           | bool   | `false` | `MAIL_HIPAA_MODE`        | Enable PHI scrubbing                    |
| `audit_hash_enabled`   | bool   | `false` | `MAIL_AUDIT_HASH`        | HMAC-hash metadata in audit logs        |
| `driver_options`       | array  | `[]`    | --                       | Per-driver configuration                |

## Transports

Seven built-in transports are available:

| Driver     | Type    | Description                         |
| ---------- | ------- | ----------------------------------- |
| `smtp`     | Socket  | PHP native socket with STARTTLS/SSL |
| `ses`      | API     | AWS SES v2                          |
| `mailgun`  | API     | Mailgun v3                          |
| `postmark` | API     | Postmark                            |
| `sendgrid` | API     | Sendgrid v3                         |
| `log`      | Dev     | Writes to PSR-3 logger              |
| `array`    | Testing | Stores messages in memory           |

### SMTP configuration

```php
'driver_options' => [
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'user@example.com',
        'password' => 'secret',
        'encryption' => 'tls',  // 'tls', 'ssl', or 'none'
        'timeout' => 30,
    ],
],
```

### API transport configuration

API transports (SES, Mailgun, Postmark, Sendgrid) make their HTTP calls through a `MailHttpClientInterface`. The framework ships a default implementation, so these drivers work with no extra wiring — set the driver and its `driver_options` and you are done. See [The mail HTTP client](#the-mail-http-client) below to reuse your own HTTP stack.

```php
'driver_options' => [
    'ses' => [
        'region' => 'us-east-1',
        'access_key' => '',
        'secret_key' => '',
        'endpoint' => null,  // Custom endpoint (optional)
    ],
    'mailgun' => [
        'domain' => 'mg.example.com',
        'api_key' => 'key-...',
        'endpoint' => 'https://api.mailgun.net',  // Use https://api.eu.mailgun.net for EU
    ],
    'postmark' => [
        'server_token' => 'xxxx-xxxx-xxxx',
    ],
    'sendgrid' => [
        'api_key' => 'SG...',
    ],
],
```

### The mail HTTP client

API transports delegate their HTTP calls to a `MailHttpClientInterface`, which keeps transport logic testable and lets you reuse your application's HTTP stack. Resolution is automatic, in order of preference:

- **Default (zero config).** When the application binds nothing, `MailWiring` registers a cURL-backed client (`CurlMailHttpClient`). `ext-curl` is already a framework requirement, so `MAIL_DRIVER=mailgun|ses|postmark|sendgrid` works out of the box with no application wiring. TLS peer and host verification are always enforced, and both the connect and total transfer times are bounded so a hung provider cannot stall a worker.
- **Reuse your HTTP stack (PSR-18).** Bind a PSR-18 `Psr\Http\Client\ClientInterface` in the container and `MailWiring` prefers it (adapting it via `Psr18MailHttpClient`), so mail flows through your client's connection pooling, retries, proxy, observability, and test doubles. The framework's PSR-17 request/stream factories are used unless you bind your own.
- **Full control.** Bind your own `MailHttpClientInterface` and the framework uses it as-is.

A transport-level failure (DNS, TLS, timeout) is wrapped in a driver-scoped `MailException`; an actual HTTP response — including a 4xx/5xx — is returned so the transport can surface the provider's status and body.

## Creating mailables

A Mailable defines its structure through two abstract methods:

- `envelope()`: returns an `Envelope` (subject, from, to, cc, bcc, replyTo)
- `content()`: returns a `Content` (html, text)

```php
use Pulsar\Mail\Mailable;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Content;
use Pulsar\Mail\Address;

final class InvoiceEmail extends Mailable
{
    public function __construct(
        private readonly string $invoiceNumber,
        private readonly string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice #' . $this->invoiceNumber,
            from: new Address('billing@example.com', 'Billing'),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: '<p>Please find your invoice attached.</p>',
            text: 'Please find your invoice attached.',
        );
    }
}
```

### Fluent overrides

All Mailable properties can be overridden at send time using the fluent API:

```php
$mailable = new InvoiceEmail('INV-001', $pdfBytes);

$mailable
    ->to('accounting@client.com', 'Client Accounting')
    ->cc('manager@client.com')
    ->bcc('archive@example.com')
    ->replyTo('support@example.com')
    ->subject('Updated: Invoice #INV-001')
    ->priority(1)
    ->header('X-Invoice-Id', 'INV-001')
    ->metadata('invoice_id', 'INV-001')
    ->locale('fr');
```

### Attachments

```php
// File attachment
$mailable->attach('invoice.pdf', $pdfContent, 'application/pdf');

// Inline image (for HTML embedding)
$mailable->inline('logo', $logoContent, 'image/png');
// Reference in HTML: <img src="cid:logo">
```

## Sending mail

### Synchronous

```php
use Pulsar\Mail\MailManagerInterface;

// Send a Mailable
$messageId = $mailManager->send($mailable);

// Send a raw Message directly
$messageId = $mailManager->raw($message);

// Use a specific transport
$transport = $mailManager->driver('ses');
$messageId = $transport->send($message);
```

### Asynchronous (via queue)

Use `SendMailJob` to queue mail for async delivery:

```php
use Pulsar\Mail\Job\SendMailJob;

$job = new SendMailJob(
    mailable: $mailable,
    mailManager: $mailManager,
    queueName: 'mail',
    maxAttemptCount: 3,
    timeoutSeconds: 60,
);

$queueManager->dispatch($job);
```

## Encryption policy

The `encryption_policy` setting controls TLS enforcement for outgoing mail:

| Policy    | Behavior                                                             |
| --------- | -------------------------------------------------------------------- |
| `require` | Reject delivery if TLS is unavailable; throws `MailException`        |
| `prefer`  | Use TLS when available, fall back to plaintext; emits fallback event |
| `none`    | No TLS requirement                                                   |

When a fallback occurs under the `prefer` policy, a `MailEncryptionFallbackEvent` is emitted with the recipient email, reason, and policy.

## HIPAA compliance mode

When `hipaa_mode` is enabled, the `PhiScrubber` is registered in the container and can be used to scan outgoing mail content for Protected Health Information (PHI) patterns before sending.

Detected patterns:

- SSN (xxx-xx-xxxx and 9-digit)
- Medical Record Numbers (MRN)
- US phone numbers
- Email addresses
- Dates of birth

Matches are replaced with `[REDACTED]`. The scrubber also exposes `containsPhi()` for detection without modification.

## Audit logging

When an `AuditLoggerInterface` is available, the mail manager logs every send attempt:

| Event           | Audit Action | Outcome   | Metadata                                             |
| --------------- | ------------ | --------- | ---------------------------------------------------- |
| Successful send | `mail.send`  | `success` | message_id, driver, recipient_count, has_attachments |
| Failed send     | `mail.send`  | `error`   | driver, recipient_count, has_attachments, error      |

## Events

Events are dispatched via `EventDispatcherInterface` when available:

| Event                         | When                               | Data                                      |
| ----------------------------- | ---------------------------------- | ----------------------------------------- |
| `MailSent`                    | Message sent successfully          | messageId, recipientCount, driver         |
| `MailFailed`                  | Message send failed                | messageId, reason, driver                 |
| `MailEncryptionFallbackEvent` | TLS fallback under `prefer` policy | messageId, recipientEmail, reason, policy |

## Metrics

When a `MetricRegistry` is available:

| Metric                   | Type    | Labels   | Description              |
| ------------------------ | ------- | -------- | ------------------------ |
| `mail_send_total`        | Counter | `driver` | Total mail send attempts |
| `mail_send_errors_total` | Counter | `driver` | Total mail send errors   |

## Webhook handling

Pulsar includes secure webhook processing for bounce and complaint events from mail providers. The `WebhookHandler` implements a security pipeline:

1. **Signature verification** via `WebhookVerifierInterface`
2. **Replay protection** with configurable time window (default: 300s)
3. **Deduplication** via `WebhookDeduplicationStoreInterface`
4. **IP allowlisting** via `IpAllowlistInterface`
5. **Audit logging** of all accepted and rejected events

Supported event types: `bounce`, `complaint`, `delivery`, `open`, `click`.

The `BounceHandler` and `ComplaintHandler` process provider-specific payloads and update delivery status with full audit trails.

## Testing

Use the `ArrayTransport` for testing:

```php
use Pulsar\Config\MailConfig;
use Pulsar\Config\MailDriverType;
use Pulsar\Mail\MailManager;

$config = new MailConfig(
    enabled: true,
    defaultDriver: MailDriverType::Array,
    defaultFromAddress: 'test@example.com',
    defaultFromName: 'Test',
);

$manager = new MailManager($config);
$manager->send($mailable);

// Retrieve sent messages
$transport = $manager->driver('array');
assert($transport instanceof \Pulsar\Mail\Transport\ArrayTransport);

$messages = $transport->sent();
assert(count($messages) === 1);
assert($messages[0]->subject === 'Welcome');

// Clear between tests
$transport->flush();
```

## See also

- [`notification.md`](notification.md) - Notification system (uses mail as a channel)
- [`compliance.md`](compliance.md) - Compliance framework
- [`audit-logging.md`](audit-logging.md) - Audit logging
- [`events.md`](events.md) - Event system
