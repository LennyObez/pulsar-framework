<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Redaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Redaction\DefaultRedactionPolicy;

final class DefaultRedactionPolicyTest extends TestCase
{
    private DefaultRedactionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DefaultRedactionPolicy();
    }

    #[Test]
    public function redactsSensitiveKeysByDefault(): void
    {
        $result = $this->policy->redact([
            'password' => 'secret123',
            'api_key' => 'key123',
            'token' => 'tok_abc',
            'name' => 'Alice',
        ]);

        self::assertSame('[REDACTED]', $result['password']);
        self::assertSame('[REDACTED]', $result['api_key']);
        self::assertSame('[REDACTED]', $result['token']);
        self::assertSame('Alice', $result['name']);
    }

    #[Test]
    public function redactsBearerTokensInValues(): void
    {
        $result = $this->policy->redact([
            'header' => 'Bearer eyJhbGciOiJIUzI1NiJ9.test',
        ]);

        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $result['header']);
    }

    #[Test]
    public function redactsConnectionStringsWithCredentials(): void
    {
        $result = $this->policy->redact([
            'dsn' => 'mysql://root:password123@localhost/db',
        ]);

        self::assertStringNotContainsString('password123', $result['dsn']);
    }

    #[Test]
    public function redactsNestedArrays(): void
    {
        $result = $this->policy->redact([
            'data' => [
                'secret' => 'hidden',
                'name' => 'visible',
            ],
        ]);

        self::assertSame('[REDACTED]', $result['data']['secret']);
        self::assertSame('visible', $result['data']['name']);
    }

    #[Test]
    public function preservesNonSensitiveData(): void
    {
        $result = $this->policy->redact([
            'status_code' => 200,
            'path' => '/api/users',
            'method' => 'GET',
        ]);

        self::assertSame(200, $result['status_code']);
        self::assertSame('/api/users', $result['path']);
        self::assertSame('GET', $result['method']);
    }

    #[Test]
    public function supportsAdditionalPatterns(): void
    {
        $policy = new DefaultRedactionPolicy(additionalPatterns: ['/CUSTOM_\w+/']);

        $result = $policy->redact([
            'value' => 'Contains CUSTOM_SECRET_VALUE in text',
        ]);

        self::assertStringNotContainsString('CUSTOM_SECRET_VALUE', $result['value']);
    }
}
