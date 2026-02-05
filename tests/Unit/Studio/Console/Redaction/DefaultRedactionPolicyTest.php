<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Redaction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Redaction\DefaultRedactionPolicy;

#[CoversClass(DefaultRedactionPolicy::class)]
final class DefaultRedactionPolicyTest extends TestCase
{
    #[Test]
    public function redactsSensitiveFieldNames(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'password' => 'secret123',
            'token' => 'abc123',
            'secret' => 'hidden',
            'api_key' => 'key-value',
            'authorization' => 'Bearer xyz',
            'name' => 'Alice',
        ]);

        self::assertSame('[REDACTED]', $result['password']);
        self::assertSame('[REDACTED]', $result['token']);
        self::assertSame('[REDACTED]', $result['secret']);
        self::assertSame('[REDACTED]', $result['api_key']);
        self::assertSame('[REDACTED]', $result['authorization']);
        self::assertSame('Alice', $result['name']);
    }

    #[Test]
    public function redactsCredentialFields(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'credential' => 'user:pass',
            'credit_card' => '4111111111111111',
            'ssn' => '123-45-6789',
            'social_security' => '987654321',
            'private_key' => '-----BEGIN RSA PRIVATE KEY-----',
        ]);

        self::assertSame('[REDACTED]', $result['credential']);
        self::assertSame('[REDACTED]', $result['credit_card']);
        self::assertSame('[REDACTED]', $result['ssn']);
        self::assertSame('[REDACTED]', $result['social_security']);
        self::assertSame('[REDACTED]', $result['private_key']);
    }

    #[Test]
    public function preservesNonSensitiveFields(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'username' => 'alice',
            'email' => 'alice@example.com',
            'status' => 'active',
            'count' => 42,
        ]);

        self::assertSame('alice', $result['username']);
        self::assertSame('alice@example.com', $result['email']);
        self::assertSame('active', $result['status']);
        self::assertSame(42, $result['count']);
    }

    #[Test]
    public function redactsDsnStringsInValues(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'connection' => 'host=localhost;password=secret123;port=5432',
            'dsn' => 'user=admin;pwd=mypassword;server=db.example.com',
        ]);

        /** @var string $connection */
        $connection = $result['connection'];
        /** @var string $dsn */
        $dsn = $result['dsn'];

        self::assertStringContainsString('[REDACTED]', $connection);
        self::assertStringNotContainsString('secret123', $connection);
        self::assertStringContainsString('[REDACTED]', $dsn);
        self::assertStringNotContainsString('mypassword', $dsn);
    }

    #[Test]
    public function redactsConnectionStringsWithCredentials(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'database_url' => 'mysql://admin:secret@localhost:3306/mydb',
            'redis_url' => 'redis://user:password@redis.example.com:6379',
        ]);

        /** @var string $databaseUrl */
        $databaseUrl = $result['database_url'];
        /** @var string $redisUrl */
        $redisUrl = $result['redis_url'];

        self::assertStringContainsString('[REDACTED]', $databaseUrl);
        self::assertStringNotContainsString('admin:secret', $databaseUrl);
        self::assertStringContainsString('[REDACTED]', $redisUrl);
        self::assertStringNotContainsString('user:password', $redisUrl);
    }

    #[Test]
    public function redactsBearerTokensInValues(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'auth_header' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ',
            'message' => 'Request with Bearer token123 in the middle',
        ]);

        /** @var string $authHeader */
        $authHeader = $result['auth_header'];
        /** @var string $message */
        $message = $result['message'];

        self::assertStringContainsString('[REDACTED]', $authHeader);
        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $authHeader);
        self::assertStringContainsString('[REDACTED]', $message);
        self::assertStringNotContainsString('token123', $message);
    }

    #[Test]
    public function redactsAwsStyleKeys(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'aws_key' => 'AKIAIOSFODNN7EXAMPLE',
            'temp_key' => 'ASIAXEXAMPLEKEY12345',
        ]);

        /** @var string $awsKey */
        $awsKey = $result['aws_key'];
        /** @var string $tempKey */
        $tempKey = $result['temp_key'];

        self::assertStringContainsString('[REDACTED]', $awsKey);
        self::assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $awsKey);
        self::assertStringContainsString('[REDACTED]', $tempKey);
        self::assertStringNotContainsString('ASIAXEXAMPLEKEY12345', $tempKey);
    }

    #[Test]
    public function redactsApiKeyPatterns(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'config' => 'api_key=sk_live_abc123xyz',
            'settings' => 'apikey: secret_value_here',
            'env' => 'API-KEY=another-secret',
        ]);

        /** @var string $config */
        $config = $result['config'];
        /** @var string $settings */
        $settings = $result['settings'];
        /** @var string $env */
        $env = $result['env'];

        self::assertStringContainsString('[REDACTED]', $config);
        self::assertStringNotContainsString('sk_live_abc123xyz', $config);
        self::assertStringContainsString('[REDACTED]', $settings);
        self::assertStringNotContainsString('secret_value_here', $settings);
        self::assertStringContainsString('[REDACTED]', $env);
        self::assertStringNotContainsString('another-secret', $env);
    }

    #[Test]
    public function redactsNestedArrays(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'user' => [
                'name' => 'Alice',
                'password' => 'secret123',
                'profile' => [
                    'bio' => 'Hello',
                    'api_token' => 'tok_abc123',
                ],
            ],
        ]);

        /** @var array<string, mixed> $user */
        $user = $result['user'];
        self::assertSame('Alice', $user['name']);
        self::assertSame('[REDACTED]', $user['password']);

        /** @var array<string, mixed> $profile */
        $profile = $user['profile'];
        self::assertSame('Hello', $profile['bio']);
        self::assertSame('[REDACTED]', $profile['api_token']);
    }

    #[Test]
    public function redactsDeeplyNestedStructures(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'secret_value' => 'hidden',
                            'normal_value' => 'visible',
                        ],
                    ],
                ],
            ],
        ]);

        /** @var array<string, mixed> $level1 */
        $level1 = $result['level1'];
        /** @var array<string, mixed> $level2 */
        $level2 = $level1['level2'];
        /** @var array<string, mixed> $level3 */
        $level3 = $level2['level3'];
        /** @var array<string, mixed> $level4 */
        $level4 = $level3['level4'];

        self::assertSame('[REDACTED]', $level4['secret_value']);
        self::assertSame('visible', $level4['normal_value']);
    }

    #[Test]
    public function handlesCaseInsensitiveKeys(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'PASSWORD' => 'upper',
            'Token' => 'mixed',
            'API_KEY' => 'caps',
            'Secret_Value' => 'mixed2',
        ]);

        self::assertSame('[REDACTED]', $result['PASSWORD']);
        self::assertSame('[REDACTED]', $result['Token']);
        self::assertSame('[REDACTED]', $result['API_KEY']);
        self::assertSame('[REDACTED]', $result['Secret_Value']);
    }

    #[Test]
    public function handlesEmptyArray(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function handlesNonStringValues(): void
    {
        $policy = new DefaultRedactionPolicy();

        $result = $policy->redact([
            'count' => 42,
            'active' => true,
            'rate' => 3.14,
            'nothing' => null,
        ]);

        self::assertSame(42, $result['count']);
        self::assertTrue($result['active']);
        self::assertSame(3.14, $result['rate']);
        self::assertNull($result['nothing']);
    }

    #[Test]
    public function supportsAdditionalPatterns(): void
    {
        $policy = new DefaultRedactionPolicy(['/custom_secret_\d+/']);

        $result = $policy->redact([
            'data' => 'Found custom_secret_12345 in text',
            'normal' => 'No match here',
        ]);

        /** @var string $data */
        $data = $result['data'];

        self::assertStringContainsString('[REDACTED]', $data);
        self::assertStringNotContainsString('custom_secret_12345', $data);
        self::assertSame('No match here', $result['normal']);
    }

    #[Test]
    public function redactsBase64EncodedSecrets(): void
    {
        $policy = new DefaultRedactionPolicy();

        // Long base64 string (32+ chars) should be redacted
        $result = $policy->redact([
            'encoded' => 'c2VjcmV0X2tleV90aGF0X2lzX3ZlcnlfbG9uZ19hbmRfc2hvdWxkX2JlX3JlZGFjdGVk',
        ]);

        /** @var string $encoded */
        $encoded = $result['encoded'];

        self::assertStringContainsString('[REDACTED]', $encoded);
    }

    #[Test]
    public function preservesShortBase64Strings(): void
    {
        $policy = new DefaultRedactionPolicy();

        // Short base64 strings (< 32 chars) should be preserved
        $result = $policy->redact([
            'short' => 'SGVsbG8gV29ybGQ=', // "Hello World" encoded - 16 chars
        ]);

        self::assertSame('SGVsbG8gV29ybGQ=', $result['short']);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('sensitiveFieldNamesProvider')]
    public function redactsSensitiveFieldNamesFromProvider(array $input, array $expected): void
    {
        $policy = new DefaultRedactionPolicy();
        $result = $policy->redact($input);

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{input: array<string, mixed>, expected: array<string, mixed>}>
     */
    public static function sensitiveFieldNamesProvider(): iterable
    {
        yield 'password field' => [
            'input' => ['password' => 'secret'],
            'expected' => ['password' => '[REDACTED]'],
        ];

        yield 'user_password field' => [
            'input' => ['user_password' => 'secret'],
            'expected' => ['user_password' => '[REDACTED]'],
        ];

        yield 'auth_token field' => [
            'input' => ['auth_token' => 'tok123'],
            'expected' => ['auth_token' => '[REDACTED]'],
        ];

        yield 'client_secret field' => [
            'input' => ['client_secret' => 'sec123'],
            'expected' => ['client_secret' => '[REDACTED]'],
        ];

        yield 'non-sensitive field' => [
            'input' => ['username' => 'alice'],
            'expected' => ['username' => 'alice'],
        ];
    }
}
