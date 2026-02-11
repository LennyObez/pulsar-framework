<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ErrorTrackingConfig;

#[CoversClass(ErrorTrackingConfig::class)]
final class ErrorTrackingConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new ErrorTrackingConfig();

        self::assertTrue($config->enabled);
        self::assertSame(500, $config->maxGroups);
        self::assertSame(5, $config->maxRecentEventsPerGroup);
        self::assertSame([], $config->sensitiveFields);
    }

    #[Test]
    public function constructorWithCustomValues(): void
    {
        $config = new ErrorTrackingConfig(
            enabled: false,
            maxGroups: 1000,
            maxRecentEventsPerGroup: 20,
            sensitiveFields: ['password', 'ssn', 'credit_card'],
        );

        self::assertFalse($config->enabled);
        self::assertSame(1000, $config->maxGroups);
        self::assertSame(20, $config->maxRecentEventsPerGroup);
        self::assertSame(['password', 'ssn', 'credit_card'], $config->sensitiveFields);
    }

    #[Test]
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $config = ErrorTrackingConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(500, $config->maxGroups);
        self::assertSame(5, $config->maxRecentEventsPerGroup);
        self::assertSame([], $config->sensitiveFields);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'enabled' => false,
            'max_groups' => 250,
            'max_recent_events_per_group' => 10,
            'sensitive_fields' => ['api_key', 'token', 'secret'],
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(250, $config->maxGroups);
        self::assertSame(10, $config->maxRecentEventsPerGroup);
        self::assertSame(['api_key', 'token', 'secret'], $config->sensitiveFields);
    }

    #[Test]
    public function fromArrayEnabledDefaultsToTrue(): void
    {
        $config = ErrorTrackingConfig::fromArray([]);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayEnabledCoercesFalsy(): void
    {
        $config = ErrorTrackingConfig::fromArray(['enabled' => 0]);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayEnabledCoercesTruthy(): void
    {
        $config = ErrorTrackingConfig::fromArray(['enabled' => 'yes']);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayCoercesNumericStringMaxGroups(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'max_groups' => '750',
        ]);

        self::assertSame(750, $config->maxGroups);
    }

    #[Test]
    public function fromArrayCoercesNumericStringMaxRecentEvents(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'max_recent_events_per_group' => '15',
        ]);

        self::assertSame(15, $config->maxRecentEventsPerGroup);
    }

    #[Test]
    public function fromArrayFallsBackOnNonNumericMaxGroups(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'max_groups' => 'unlimited',
        ]);

        self::assertSame(500, $config->maxGroups);
    }

    #[Test]
    public function fromArrayFallsBackOnNonNumericMaxRecentEvents(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'max_recent_events_per_group' => 'all',
        ]);

        self::assertSame(5, $config->maxRecentEventsPerGroup);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'enabled' => null,
            'max_groups' => null,
            'max_recent_events_per_group' => null,
            'sensitive_fields' => null,
        ]);

        // null ?? true yields true, so (bool) true = true
        self::assertTrue($config->enabled);
        self::assertSame(500, $config->maxGroups);
        self::assertSame(5, $config->maxRecentEventsPerGroup);
    }

    #[Test]
    public function fromArrayHandlesSensitiveFieldsPassthrough(): void
    {
        $fields = ['password', 'credit_card_number', 'ssn', 'api_secret'];

        $config = ErrorTrackingConfig::fromArray([
            'sensitive_fields' => $fields,
        ]);

        self::assertSame($fields, $config->sensitiveFields);
    }

    #[Test]
    public function fromArrayWithEmptySensitiveFields(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'sensitive_fields' => [],
        ]);

        self::assertSame([], $config->sensitiveFields);
    }

    /**
     * @return iterable<string, array{string, mixed, int}>
     */
    public static function maxGroupsCoercionProvider(): iterable
    {
        yield 'integer' => ['max_groups', 200, 200];
        yield 'numeric string' => ['max_groups', '300', 300];
        yield 'float numeric' => ['max_groups', 400.5, 400];
        yield 'non-numeric string' => ['max_groups', 'many', 500];
        yield 'boolean' => ['max_groups', true, 500];
        yield 'array' => ['max_groups', [100], 500];
    }

    #[Test]
    #[DataProvider('maxGroupsCoercionProvider')]
    public function fromArrayHandlesMaxGroupsCoercion(string $key, mixed $value, int $expected): void
    {
        $config = ErrorTrackingConfig::fromArray([$key => $value]);

        self::assertSame($expected, $config->maxGroups);
    }

    /**
     * @return iterable<string, array{string, mixed, int}>
     */
    public static function maxRecentEventsCoercionProvider(): iterable
    {
        yield 'integer' => ['max_recent_events_per_group', 3, 3];
        yield 'numeric string' => ['max_recent_events_per_group', '10', 10];
        yield 'float numeric' => ['max_recent_events_per_group', 7.8, 7];
        yield 'non-numeric string' => ['max_recent_events_per_group', 'few', 5];
        yield 'null' => ['max_recent_events_per_group', null, 5];
    }

    #[Test]
    #[DataProvider('maxRecentEventsCoercionProvider')]
    public function fromArrayHandlesMaxRecentEventsCoercion(string $key, mixed $value, int $expected): void
    {
        $config = ErrorTrackingConfig::fromArray([$key => $value]);

        self::assertSame($expected, $config->maxRecentEventsPerGroup);
    }

    #[Test]
    public function fromArrayPreservesExtraKeysSilently(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'enabled' => true,
            'unknown_option' => 'ignored',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(500, $config->maxGroups);
    }
}
