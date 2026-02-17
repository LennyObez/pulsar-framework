<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csp;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Csp\CspViolationReport;

#[CoversClass(CspViolationReport::class)]
final class CspViolationReportTest extends TestCase
{
    #[Test]
    public function fromReportUriParsesAllFields(): void
    {
        $data = [
            'document-uri' => 'https://example.com/page',
            'violated-directive' => 'script-src',
            'effective-directive' => 'script-src',
            'original-policy' => "default-src 'self'",
            'blocked-uri' => 'https://evil.com/malware.js',
            'source-file' => 'https://example.com/app.js',
            'line-number' => 42,
            'column-number' => 7,
            'status-code' => 200,
            'disposition' => 'enforce',
            'referrer' => 'https://google.com',
            'script-sample' => 'alert(1)',
        ];

        $report = CspViolationReport::fromReportUri($data);

        self::assertSame('https://example.com/page', $report->documentUri);
        self::assertSame('script-src', $report->violatedDirective);
        self::assertSame('script-src', $report->effectiveDirective);
        self::assertSame("default-src 'self'", $report->originalPolicy);
        self::assertSame('https://evil.com/malware.js', $report->blockedUri);
        self::assertSame('https://example.com/app.js', $report->sourceFile);
        self::assertSame(42, $report->lineNumber);
        self::assertSame(7, $report->columnNumber);
        self::assertSame(200, $report->statusCode);
        self::assertSame('enforce', $report->disposition);
        self::assertSame('https://google.com', $report->referrer);
        self::assertSame('alert(1)', $report->scriptSample);
    }

    #[Test]
    public function fromReportUriHandlesMissingFields(): void
    {
        $report = CspViolationReport::fromReportUri([]);

        self::assertSame('', $report->documentUri);
        self::assertSame('', $report->violatedDirective);
        self::assertSame('', $report->effectiveDirective);
        self::assertSame('', $report->originalPolicy);
        self::assertSame('', $report->blockedUri);
        self::assertSame('', $report->sourceFile);
        self::assertSame(0, $report->lineNumber);
        self::assertSame(0, $report->columnNumber);
        self::assertSame(0, $report->statusCode);
        self::assertSame('', $report->disposition);
        self::assertSame('', $report->referrer);
        self::assertSame('', $report->scriptSample);
    }

    #[Test]
    public function fromReportToUsesReportingApiFieldNames(): void
    {
        $data = [
            'documentURL' => 'https://app.example.com/dashboard',
            'effectiveDirective' => 'style-src-elem',
            'originalPolicy' => "default-src 'self'; style-src 'self'",
            'blockedURL' => 'https://cdn.example.com/style.css',
            'sourceFile' => 'https://app.example.com/main.js',
            'lineNumber' => 15,
            'columnNumber' => 3,
            'statusCode' => 0,
            'disposition' => 'report',
            'referrer' => '',
            'sample' => '.hidden { display: none }',
        ];

        $report = CspViolationReport::fromReportTo($data);

        self::assertSame('https://app.example.com/dashboard', $report->documentUri);
        self::assertSame('style-src-elem', $report->violatedDirective);
        self::assertSame('style-src-elem', $report->effectiveDirective);
        self::assertSame('https://cdn.example.com/style.css', $report->blockedUri);
        self::assertSame(15, $report->lineNumber);
        self::assertSame(3, $report->columnNumber);
        self::assertSame('.hidden { display: none }', $report->scriptSample);
    }

    #[Test]
    public function fromReportToHandlesMissingFields(): void
    {
        $report = CspViolationReport::fromReportTo([]);

        self::assertSame('', $report->documentUri);
        self::assertSame('', $report->violatedDirective);
        self::assertSame(0, $report->lineNumber);
    }

    #[Test]
    public function receivedAtIsSetToCurrentTime(): void
    {
        $before = new DateTimeImmutable();

        $report = CspViolationReport::fromReportUri([
            'document-uri' => 'https://example.com',
        ]);

        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $report->receivedAt);
        self::assertLessThanOrEqual($after, $report->receivedAt);
    }

    #[Test]
    public function nonStringValuesDefaultToEmptyString(): void
    {
        $data = [
            'document-uri' => 12345,
            'violated-directive' => null,
            'effective-directive' => ['array'],
        ];

        $report = CspViolationReport::fromReportUri($data);

        self::assertSame('', $report->documentUri);
        self::assertSame('', $report->violatedDirective);
        self::assertSame('', $report->effectiveDirective);
    }

    #[Test]
    public function nonNumericLineNumberDefaultsToZero(): void
    {
        $data = [
            'line-number' => 'not-a-number',
            'column-number' => [],
        ];

        $report = CspViolationReport::fromReportUri($data);

        self::assertSame(0, $report->lineNumber);
        self::assertSame(0, $report->columnNumber);
    }
}
