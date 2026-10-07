<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Redaction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Redaction\DefaultRedactionPolicy;

#[CoversClass(DefaultRedactionPolicy::class)]
final class DefaultRedactionPolicyTest extends TestCase
{
    #[Test]
    public function redactsSensitiveKeysByDefault(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'username' => 'admin',
            'password' => 'secret123',
            'api_key' => 'key-abc',
        ]);

        self::assertSame('admin', $result['username']);
        self::assertSame('[REDACTED]', $result['password']);
        self::assertSame('[REDACTED]', $result['api_key']);
    }

    #[Test]
    public function redactsBearerTokensInValues(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'auth_header' => 'Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature',
        ]);

        /** @var string $authHeader */
        $authHeader = $result['auth_header'];
        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $authHeader);
    }

    #[Test]
    public function redactsConnectionStringsWithCredentials(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'dsn' => 'mysql://root:secret@localhost/db',
        ]);

        /** @var string $dsn */
        $dsn = $result['dsn'];
        self::assertStringNotContainsString('secret', $dsn);
    }

    #[Test]
    public function redactsNestedArrayValues(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'config' => [
                'token' => 'my-secret-token',
                'host' => 'localhost',
            ],
        ]);

        /** @var array<string, mixed> $config */
        $config = $result['config'];
        self::assertSame('[REDACTED]', $config['token']);
        self::assertSame('localhost', $config['host']);
    }

    #[Test]
    public function redactsPasswordInDsnStrings(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'connection' => 'host=localhost;password=secret123;user=admin',
        ]);

        /** @var string $connection */
        $connection = $result['connection'];
        self::assertStringNotContainsString('secret123', $connection);
    }

    #[Test]
    public function preservesNonSensitiveValues(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'name' => 'John',
            'count' => 42,
            'active' => true,
        ]);

        self::assertSame('John', $result['name']);
        self::assertSame(42, $result['count']);
        self::assertTrue($result['active']);
    }

    #[Test]
    public function additionalPatternsAreApplied(): void
    {
        $policy = new DefaultRedactionPolicy(additionalPatterns: [
            '/CUSTOM-[A-Z]{10}/',
        ]);

        $result = $policy->redact([
            'header' => 'Token CUSTOM-ABCDEFGHIJ',
        ]);

        /** @var string $header */
        $header = $result['header'];
        self::assertStringNotContainsString('CUSTOM-ABCDEFGHIJ', $header);
    }
}
