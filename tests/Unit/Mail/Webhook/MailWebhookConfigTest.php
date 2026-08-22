<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\MailWebhookConfig;

#[CoversClass(MailWebhookConfig::class)]
final class MailWebhookConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new MailWebhookConfig();

        self::assertFalse($config->enabled);
        self::assertSame('', $config->provider);
        self::assertSame('/_pulsar/mail/webhook', $config->path);
        self::assertSame(300, $config->replayWindowSeconds);
        self::assertSame([], $config->ipAllowlist);
        self::assertFalse($config->isUsable());
    }

    #[Test]
    public function fromArrayParsesEveryField(): void
    {
        $config = MailWebhookConfig::fromArray([
            'enabled' => true,
            'provider' => 'mailgun',
            'secret' => 'sk-123',
            'path' => '/hooks/mail',
            'replay_window_seconds' => '120',
            'ip_allowlist' => ['203.0.113.0/24', 42, 'note: bad'],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('mailgun', $config->provider);
        self::assertSame('sk-123', $config->secret);
        self::assertSame('/hooks/mail', $config->path);
        self::assertSame(120, $config->replayWindowSeconds);
        self::assertSame(['203.0.113.0/24', 'note: bad'], $config->ipAllowlist, 'non-strings filtered out');
    }

    #[Test]
    public function secretBasedProviderNeedsASecretToBeUsable(): void
    {
        $noSecret = MailWebhookConfig::fromArray(['enabled' => true, 'provider' => 'postmark']);
        self::assertFalse($noSecret->isUsable());

        $withSecret = MailWebhookConfig::fromArray(['enabled' => true, 'provider' => 'postmark', 'secret' => 't']);
        self::assertTrue($withSecret->isUsable());
    }

    #[Test]
    public function sesIsUsableWithoutASecret(): void
    {
        // SES uses certificate-based verification, not a shared secret.
        $config = MailWebhookConfig::fromArray(['enabled' => true, 'provider' => 'ses']);

        self::assertTrue($config->isUsable());
    }

    #[Test]
    public function disabledIsNeverUsable(): void
    {
        self::assertFalse(MailWebhookConfig::fromArray(['provider' => 'ses'])->isUsable());
    }
}
