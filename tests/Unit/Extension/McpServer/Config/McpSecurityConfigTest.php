<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use ReflectionClass;

#[CoversClass(McpSecurityConfig::class)]
final class McpSecurityConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = McpSecurityConfig::fromArray([]);

        self::assertSame([], $config->pathAllowlist);
        self::assertSame(60, $config->rateLimitPerMinute);
        self::assertSame([], $config->toolRateLimits);
        self::assertSame(1, $config->maxConcurrentActions);
    }

    #[Test]
    public function fromArrayWithExplicitValues(): void
    {
        $config = McpSecurityConfig::fromArray([
            'path_allowlist' => ['/var/www', '/tmp'],
            'rate_limit_per_minute' => 120,
            'tool_rate_limits' => ['pulsar.tests.run' => 10, 'pulsar.analysis.run' => 5],
            'max_concurrent_actions' => 3,
        ]);

        self::assertSame(['/var/www', '/tmp'], $config->pathAllowlist);
        self::assertSame(120, $config->rateLimitPerMinute);
        self::assertSame(['pulsar.tests.run' => 10, 'pulsar.analysis.run' => 5], $config->toolRateLimits);
        self::assertSame(3, $config->maxConcurrentActions);
    }

    #[Test]
    public function fromArrayCastsRateLimitToInt(): void
    {
        $config = McpSecurityConfig::fromArray([
            'rate_limit_per_minute' => '200',
            'max_concurrent_actions' => 5,
        ]);

        // rate_limit_per_minute requires is_int() — string '200' falls back to default 60
        self::assertSame(60, $config->rateLimitPerMinute);
        self::assertSame(5, $config->maxConcurrentActions);
    }

    #[Test]
    public function fromArrayWithEmptyPathAllowlist(): void
    {
        $config = McpSecurityConfig::fromArray(['path_allowlist' => []]);

        self::assertSame([], $config->pathAllowlist);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('partialDataProvider')]
    public function fromArrayWithPartialData(array $data, string $field, mixed $expected): void
    {
        $config = McpSecurityConfig::fromArray($data);

        self::assertSame($expected, $config->$field);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, mixed}>
     */
    public static function partialDataProvider(): iterable
    {
        yield 'only path_allowlist' => [
            ['path_allowlist' => ['/opt']],
            'pathAllowlist',
            ['/opt'],
        ];

        yield 'only rate_limit_per_minute' => [
            ['rate_limit_per_minute' => 30],
            'rateLimitPerMinute',
            30,
        ];

        yield 'only tool_rate_limits' => [
            ['tool_rate_limits' => ['foo' => 1]],
            'toolRateLimits',
            ['foo' => 1],
        ];

        yield 'only max_concurrent_actions' => [
            ['max_concurrent_actions' => 10],
            'maxConcurrentActions',
            10,
        ];
    }

    #[Test]
    public function isReadonly(): void
    {
        $config = McpSecurityConfig::fromArray([
            'path_allowlist' => ['/a'],
            'rate_limit_per_minute' => 42,
        ]);

        $reflection = new ReflectionClass($config);
        self::assertTrue($reflection->isReadOnly());
    }
}
