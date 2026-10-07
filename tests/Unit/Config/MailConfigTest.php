<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\MailConfig;
use Pulsar\Config\MailDriverType;
use Pulsar\Config\MailEncryptionPolicy;

#[CoversClass(MailConfig::class)]
final class MailConfigTest extends TestCase
{
    private Environment $emptyEnv;

    protected function setUp(): void
    {
        $this->emptyEnv = Environment::load('/nonexistent/.env');
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new MailConfig();

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
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
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
    public function fromArrayWithFullConfig(): void
    {
        $config = MailConfig::fromArray([
            'enabled' => true,
            'default_driver' => 'ses',
            'default_from_address' => 'noreply@example.com',
            'default_from_name' => 'Example App',
            'default_reply_to' => 'support@example.com',
            'encryption_policy' => 'require',
            'hipaa_mode' => true,
            'audit_hash_enabled' => true,
            'driver_options' => ['host' => 'smtp.example.com', 'port' => 587],
        ], $this->emptyEnv);

        self::assertTrue($config->enabled);
        self::assertSame(MailDriverType::Ses, $config->defaultDriver);
        self::assertSame('noreply@example.com', $config->defaultFromAddress);
        self::assertSame('Example App', $config->defaultFromName);
        self::assertSame('support@example.com', $config->defaultReplyTo);
        self::assertSame(MailEncryptionPolicy::Require, $config->encryptionPolicy);
        self::assertTrue($config->hipaaMode);
        self::assertTrue($config->auditHashEnabled);
        self::assertSame(['host' => 'smtp.example.com', 'port' => 587], $config->driverOptions);
    }

    #[Test]
    public function fromArrayHandlesInvalidDriverFallback(): void
    {
        $config = MailConfig::fromArray([
            'default_driver' => 'nonexistent_driver',
        ], $this->emptyEnv);

        self::assertSame(MailDriverType::Smtp, $config->defaultDriver);
    }

    #[Test]
    public function fromArrayHandlesNonStringDriverFallback(): void
    {
        $config = MailConfig::fromArray([
            'default_driver' => 42,
        ], $this->emptyEnv);

        self::assertSame(MailDriverType::Smtp, $config->defaultDriver);
    }

    #[Test]
    public function fromArrayHandlesInvalidEncryptionPolicyFallback(): void
    {
        $config = MailConfig::fromArray([
            'encryption_policy' => 'unknown_policy',
        ], $this->emptyEnv);

        self::assertSame(MailEncryptionPolicy::None, $config->encryptionPolicy);
    }

    #[Test]
    public function fromArrayHandlesNonStringEncryptionPolicyFallback(): void
    {
        $config = MailConfig::fromArray([
            'encryption_policy' => 123,
        ], $this->emptyEnv);

        self::assertSame(MailEncryptionPolicy::None, $config->encryptionPolicy);
    }

    #[Test]
    public function fromArrayHandlesNonStringFromAddress(): void
    {
        $config = MailConfig::fromArray([
            'default_from_address' => 42,
            'default_from_name' => [],
            'default_reply_to' => true,
        ], $this->emptyEnv);

        self::assertSame('', $config->defaultFromAddress);
        self::assertSame('', $config->defaultFromName);
        self::assertSame('', $config->defaultReplyTo);
    }

    #[Test]
    public function fromArrayHandlesNonBoolHipaaMode(): void
    {
        $config = MailConfig::fromArray([
            'hipaa_mode' => 'yes',
            'audit_hash_enabled' => 1,
        ], $this->emptyEnv);

        self::assertFalse($config->hipaaMode);
        self::assertFalse($config->auditHashEnabled);
    }

    #[Test]
    public function fromArrayHandlesNonArrayDriverOptions(): void
    {
        $config = MailConfig::fromArray([
            'driver_options' => 'not_an_array',
        ], $this->emptyEnv);

        self::assertSame([], $config->driverOptions);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = MailConfig::fromArray([
            'enabled' => null,
            'default_driver' => null,
            'default_from_address' => null,
            'default_from_name' => null,
            'default_reply_to' => null,
            'encryption_policy' => null,
            'hipaa_mode' => null,
            'audit_hash_enabled' => null,
            'driver_options' => null,
        ], $this->emptyEnv);

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
    public function fromArrayEnvironmentOverridesEnabled(): void
    {
        $envFile = $this->createTempEnvFile(['MAIL_ENABLED' => 'true']);
        $env = Environment::load($envFile);

        $config = MailConfig::fromArray(['enabled' => false], $env);

        self::assertTrue($config->enabled);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentEnabledFalseString(): void
    {
        $envFile = $this->createTempEnvFile(['MAIL_ENABLED' => 'false']);
        $env = Environment::load($envFile);

        $config = MailConfig::fromArray(['enabled' => true], $env);

        self::assertFalse($config->enabled);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesDriver(): void
    {
        $envFile = $this->createTempEnvFile(['MAIL_DRIVER' => 'postmark']);
        $env = Environment::load($envFile);

        $config = MailConfig::fromArray(['default_driver' => 'ses'], $env);

        self::assertSame(MailDriverType::Postmark, $config->defaultDriver);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesFromFields(): void
    {
        $envFile = $this->createTempEnvFile([
            'MAIL_FROM_ADDRESS' => 'env@example.com',
            'MAIL_FROM_NAME' => 'Env Name',
            'MAIL_REPLY_TO' => 'env-reply@example.com',
        ]);
        $env = Environment::load($envFile);

        $config = MailConfig::fromArray([
            'default_from_address' => 'config@example.com',
            'default_from_name' => 'Config Name',
            'default_reply_to' => 'config-reply@example.com',
        ], $env);

        self::assertSame('env@example.com', $config->defaultFromAddress);
        self::assertSame('Env Name', $config->defaultFromName);
        self::assertSame('env-reply@example.com', $config->defaultReplyTo);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesEncryptionPolicy(): void
    {
        $envFile = $this->createTempEnvFile(['MAIL_ENCRYPTION_POLICY' => 'prefer']);
        $env = Environment::load($envFile);

        $config = MailConfig::fromArray(['encryption_policy' => 'require'], $env);

        self::assertSame(MailEncryptionPolicy::Prefer, $config->encryptionPolicy);

        @unlink($envFile);
    }

    #[Test]
    public function fromArrayEnvironmentOverridesHipaaAndAudit(): void
    {
        $envFile = $this->createTempEnvFile([
            'MAIL_HIPAA_MODE' => 'true',
            'MAIL_AUDIT_HASH' => 'true',
        ]);
        $env = Environment::load($envFile);

        $config = MailConfig::fromArray([
            'hipaa_mode' => false,
            'audit_hash_enabled' => false,
        ], $env);

        self::assertTrue($config->hipaaMode);
        self::assertTrue($config->auditHashEnabled);

        @unlink($envFile);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allDriverTypesProvider(): iterable
    {
        yield 'smtp' => ['smtp'];
        yield 'ses' => ['ses'];
        yield 'mailgun' => ['mailgun'];
        yield 'postmark' => ['postmark'];
        yield 'sendgrid' => ['sendgrid'];
        yield 'log' => ['log'];
        yield 'array' => ['array'];
    }

    #[Test]
    #[DataProvider('allDriverTypesProvider')]
    public function fromArrayAcceptsAllValidDriverTypes(string $driver): void
    {
        $config = MailConfig::fromArray(['default_driver' => $driver], $this->emptyEnv);

        self::assertSame(MailDriverType::from($driver), $config->defaultDriver);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allEncryptionPoliciesProvider(): iterable
    {
        yield 'require' => ['require'];
        yield 'prefer' => ['prefer'];
        yield 'none' => ['none'];
    }

    #[Test]
    #[DataProvider('allEncryptionPoliciesProvider')]
    public function fromArrayAcceptsAllValidEncryptionPolicies(string $policy): void
    {
        $config = MailConfig::fromArray(['encryption_policy' => $policy], $this->emptyEnv);

        self::assertSame(MailEncryptionPolicy::from($policy), $config->encryptionPolicy);
    }

    /**
     * @param array<string, string> $vars
     */
    private function createTempEnvFile(array $vars): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_env_');
        self::assertNotFalse($path);

        $lines = [];
        foreach ($vars as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }
}
