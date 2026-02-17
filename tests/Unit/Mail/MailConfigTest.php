<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\MailConfig;
use Pulsar\Config\MailDriverType;
use Pulsar\Config\MailEncryptionPolicy;
use ReflectionClass;

#[CoversClass(MailConfig::class)]
final class MailConfigTest extends TestCase
{
    private Environment $emptyEnv;

    protected function setUp(): void
    {
        $this->emptyEnv = Environment::load('/nonexistent/.env');
    }

    #[Test]
    public function it_creates_with_defaults(): void
    {
        $config = MailConfig::fromArray([], $this->emptyEnv);

        self::assertFalse($config->enabled);
        self::assertSame(MailDriverType::Smtp, $config->defaultDriver);
        self::assertSame('', $config->defaultFromAddress);
        self::assertSame('', $config->defaultFromName);
        self::assertSame('', $config->defaultReplyTo);
        self::assertSame(MailEncryptionPolicy::None, $config->encryptionPolicy);
        self::assertFalse($config->hipaaMode);
        self::assertFalse($config->auditHashEnabled);
        self::assertSame([], $config->driverOptions);
    }

    #[Test]
    public function it_parses_full_array(): void
    {
        $data = [
            'enabled' => true,
            'default_driver' => 'ses',
            'default_from_address' => 'noreply@example.com',
            'default_from_name' => 'Pulsar App',
            'default_reply_to' => 'support@example.com',
            'encryption_policy' => 'require',
            'hipaa_mode' => true,
            'audit_hash_enabled' => true,
            'driver_options' => ['ses' => ['region' => 'us-east-1']],
        ];

        $config = MailConfig::fromArray($data, $this->emptyEnv);

        self::assertTrue($config->enabled);
        self::assertSame(MailDriverType::Ses, $config->defaultDriver);
        self::assertSame('noreply@example.com', $config->defaultFromAddress);
        self::assertSame('Pulsar App', $config->defaultFromName);
        self::assertSame('support@example.com', $config->defaultReplyTo);
        self::assertSame(MailEncryptionPolicy::Require, $config->encryptionPolicy);
        self::assertTrue($config->hipaaMode);
        self::assertTrue($config->auditHashEnabled);
        self::assertSame(['ses' => ['region' => 'us-east-1']], $config->driverOptions);
    }

    #[Test]
    public function it_falls_back_to_smtp_for_unknown_driver(): void
    {
        $data = ['default_driver' => 'nonexistent'];
        $config = MailConfig::fromArray($data, $this->emptyEnv);

        self::assertSame(MailDriverType::Smtp, $config->defaultDriver);
    }

    #[Test]
    public function it_falls_back_to_none_for_unknown_encryption_policy(): void
    {
        $data = ['encryption_policy' => 'invalid'];
        $config = MailConfig::fromArray($data, $this->emptyEnv);

        self::assertSame(MailEncryptionPolicy::None, $config->encryptionPolicy);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $config = MailConfig::fromArray([], $this->emptyEnv);

        $reflection = new ReflectionClass($config);
        self::assertTrue($reflection->isReadOnly());
    }
}
