<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Attribute\Sensitive;
use Pulsar\Console\Repl\SecretRedactor;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

#[CoversClass(SecretRedactor::class)]
final class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new SecretRedactor(new SensitiveDataScrubber());
    }

    #[Test]
    public function redactOutputReplacesKnownSecrets(): void
    {
        $this->redactor->addSecretValue('my-api-key-12345');

        $output = $this->redactor->redactOutput('Connection using key my-api-key-12345 established');

        self::assertStringNotContainsString('my-api-key-12345', $output);
        self::assertStringContainsString('********', $output);
    }

    #[Test]
    public function redactOutputIgnoresShortSecrets(): void
    {
        $this->redactor->addSecretValue('ab');

        $output = $this->redactor->redactOutput('ab is a short string');

        self::assertStringContainsString('ab', $output);
    }

    #[Test]
    public function redactOutputIgnoresEmptySecrets(): void
    {
        $this->redactor->addSecretValue('');

        $output = $this->redactor->redactOutput('nothing to redact');

        self::assertSame('nothing to redact', $output);
    }

    #[Test]
    public function redactOutputRedactsDsnCredentials(): void
    {
        $output = $this->redactor->redactOutput('mysql://root:s3cret@localhost:3306/db');

        self::assertStringNotContainsString('s3cret', $output);
        self::assertStringContainsString('root:********@', $output);
    }

    #[Test]
    public function redactOutputHandlesMultipleDsns(): void
    {
        $output = $this->redactor->redactOutput(
            'primary: pgsql://admin:pass1@db1/app, replica: pgsql://reader:pass2@db2/app',
        );

        self::assertStringNotContainsString('pass1', $output);
        self::assertStringNotContainsString('pass2', $output);
    }

    #[Test]
    public function redactObjectDumpRedactsSensitiveProperties(): void
    {
        $obj = new class ('public-val', 'secret-val') {
            public function __construct(
                public string $name,
                #[Sensitive(reason: 'API key')]
                public string $apiKey,
            ) {}
        };

        $dump = $this->redactor->redactObjectDump($obj);

        self::assertSame('public-val', $dump['name']);
        self::assertSame('********', $dump['apiKey']);
        self::assertArrayHasKey('__class', $dump);
    }

    #[Test]
    public function redactObjectDumpPreservesNonSensitive(): void
    {
        $obj = new class ('hello', 42) {
            public function __construct(
                public string $label,
                public int $count,
            ) {}
        };

        $dump = $this->redactor->redactObjectDump($obj);

        self::assertSame('hello', $dump['label']);
        self::assertSame(42, $dump['count']);
    }

    #[Test]
    public function scrubArrayDelegatesToScrubber(): void
    {
        $data = [
            'username' => 'admin',
            'password' => 'secret123',
            'api_key' => 'key-abc',
        ];

        $scrubbed = $this->redactor->scrubArray($data);

        self::assertSame('admin', $scrubbed['username']);
        self::assertSame('[REDACTED]', $scrubbed['password']);
        self::assertSame('[REDACTED]', $scrubbed['api_key']);
    }

    #[Test]
    public function addSecretValueDeduplicates(): void
    {
        $this->redactor->addSecretValue('same-secret');
        $this->redactor->addSecretValue('same-secret');

        // Should not cause double-replacement issues
        $output = $this->redactor->redactOutput('same-secret');
        self::assertSame('********', $output);
    }

    #[Test]
    public function redactOutputWithMultipleSecrets(): void
    {
        $this->redactor->addSecretValue('secret-one-value');
        $this->redactor->addSecretValue('secret-two-value');

        $output = $this->redactor->redactOutput('Keys: secret-one-value and secret-two-value');

        self::assertStringNotContainsString('secret-one-value', $output);
        self::assertStringNotContainsString('secret-two-value', $output);
    }
}
