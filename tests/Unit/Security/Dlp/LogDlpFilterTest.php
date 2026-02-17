<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\LogDlpFilter;
use Pulsar\Security\Dlp\SensitivePatternRegistry;

#[CoversClass(LogDlpFilter::class)]
final class LogDlpFilterTest extends TestCase
{
    public function testFilterReturnsSafeContentUnchanged(): void
    {
        $filter = $this->createFilter();
        $message = 'User logged in successfully.';

        self::assertSame($message, $filter->filter($message));
    }

    public function testFilterRedactsSsn(): void
    {
        $filter = $this->createFilter();
        $message = 'Processing SSN 123-45-6789 for user.';

        $result = $filter->filter($message);
        self::assertStringNotContainsString('123-45-6789', $result);
        self::assertStringContainsString('*', $result);
    }

    public function testFilterPassesThroughWhenDisabled(): void
    {
        $config = new DlpConfig(enabled: false);
        $filter = new LogDlpFilter(new SensitivePatternRegistry($config), $config);

        $message = 'SSN: 123-45-6789';
        self::assertSame($message, $filter->filter($message));
    }

    public function testFilterPassesThroughWhenScanLogsDisabled(): void
    {
        $config = new DlpConfig(scanLogs: false);
        $filter = new LogDlpFilter(new SensitivePatternRegistry($config), $config);

        $message = 'SSN: 123-45-6789';
        self::assertSame($message, $filter->filter($message));
    }

    public function testFilterContextRedactsStringValues(): void
    {
        $filter = $this->createFilter();
        $context = [
            'user' => 'john',
            'ssn' => '123-45-6789',
            'count' => 42,
        ];

        /** @var array<string, mixed> $result */
        $result = $filter->filterContext($context);
        self::assertSame('john', $result['user']);
        self::assertIsString($result['ssn']);
        self::assertStringNotContainsString('123-45-6789', $result['ssn']);
        self::assertSame(42, $result['count']);
    }

    public function testFilterContextHandlesNestedArrays(): void
    {
        $filter = $this->createFilter();
        $context = [
            'outer' => [
                'inner' => '123-45-6789',
                'safe' => 'hello',
            ],
        ];

        /** @var array<string, array<string, mixed>> $result */
        $result = $filter->filterContext($context);
        self::assertIsString($result['outer']['inner']);
        self::assertStringNotContainsString('123-45-6789', $result['outer']['inner']);
        self::assertSame('hello', $result['outer']['safe']);
    }

    public function testFilterContextPassesThroughWhenDisabled(): void
    {
        $config = new DlpConfig(enabled: false);
        $filter = new LogDlpFilter(new SensitivePatternRegistry($config), $config);

        $context = ['ssn' => '123-45-6789'];
        $result = $filter->filterContext($context);
        self::assertSame('123-45-6789', $result['ssn']);
    }

    private function createFilter(): LogDlpFilter
    {
        $config = new DlpConfig();
        return new LogDlpFilter(new SensitivePatternRegistry($config), $config);
    }
}
