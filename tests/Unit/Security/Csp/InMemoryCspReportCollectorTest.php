<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csp;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Csp\CspReportCollectorInterface;
use Pulsar\Security\Csp\CspViolationReport;
use Pulsar\Security\Csp\InMemoryCspReportCollector;

#[CoversClass(InMemoryCspReportCollector::class)]
final class InMemoryCspReportCollectorTest extends TestCase
{
    private InMemoryCspReportCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new InMemoryCspReportCollector();
    }

    #[Test]
    public function implementsCollectorInterface(): void
    {
        self::assertInstanceOf(CspReportCollectorInterface::class, $this->collector);
    }

    #[Test]
    public function collectStoresReport(): void
    {
        $report = $this->makeReport(effectiveDirective: 'script-src', blockedUri: 'https://evil.com/xss.js');

        $this->collector->collect($report);

        self::assertCount(1, $this->collector->all());
        self::assertSame($report, $this->collector->all()[0]);
    }

    #[Test]
    public function allReturnsEmptyWhenNoReportsCollected(): void
    {
        self::assertSame([], $this->collector->all());
    }

    #[Test]
    public function aggregateByDirectiveCountsPerDirective(): void
    {
        $this->collector->collect($this->makeReport(effectiveDirective: 'script-src'));
        $this->collector->collect($this->makeReport(effectiveDirective: 'script-src'));
        $this->collector->collect($this->makeReport(effectiveDirective: 'style-src'));

        $counts = $this->collector->aggregateByDirective();

        self::assertSame(2, $counts['script-src']);
        self::assertSame(1, $counts['style-src']);
    }

    #[Test]
    public function aggregateByDirectiveSkipsEmptyDirective(): void
    {
        $this->collector->collect($this->makeReport(effectiveDirective: ''));
        $this->collector->collect($this->makeReport(effectiveDirective: 'img-src'));

        $counts = $this->collector->aggregateByDirective();

        self::assertArrayNotHasKey('', $counts);
        self::assertSame(1, $counts['img-src']);
    }

    #[Test]
    public function aggregateByDirectiveReturnsEmptyForNoReports(): void
    {
        self::assertSame([], $this->collector->aggregateByDirective());
    }

    #[Test]
    public function aggregateByBlockedUriCountsPerUri(): void
    {
        $this->collector->collect($this->makeReport(blockedUri: 'https://evil.com/a.js'));
        $this->collector->collect($this->makeReport(blockedUri: 'https://evil.com/a.js'));
        $this->collector->collect($this->makeReport(blockedUri: 'https://tracker.io/t.js'));

        $counts = $this->collector->aggregateByBlockedUri();

        self::assertSame(2, $counts['https://evil.com/a.js']);
        self::assertSame(1, $counts['https://tracker.io/t.js']);
    }

    #[Test]
    public function aggregateByBlockedUriSkipsEmptyUri(): void
    {
        $this->collector->collect($this->makeReport(blockedUri: ''));
        $this->collector->collect($this->makeReport(blockedUri: 'https://cdn.example.com/x.js'));

        $counts = $this->collector->aggregateByBlockedUri();

        self::assertArrayNotHasKey('', $counts);
        self::assertSame(1, $counts['https://cdn.example.com/x.js']);
    }

    #[Test]
    public function aggregateByBlockedUriReturnsEmptyForNoReports(): void
    {
        self::assertSame([], $this->collector->aggregateByBlockedUri());
    }

    #[Test]
    public function multipleCollectsPreserveOrder(): void
    {
        $r1 = $this->makeReport(effectiveDirective: 'first');
        $r2 = $this->makeReport(effectiveDirective: 'second');
        $r3 = $this->makeReport(effectiveDirective: 'third');

        $this->collector->collect($r1);
        $this->collector->collect($r2);
        $this->collector->collect($r3);

        $all = $this->collector->all();
        self::assertCount(3, $all);
        self::assertSame('first', $all[0]->effectiveDirective);
        self::assertSame('third', $all[2]->effectiveDirective);
    }

    private function makeReport(
        string $effectiveDirective = 'default-src',
        string $blockedUri = 'https://example.com',
    ): CspViolationReport {
        return new CspViolationReport(
            documentUri: 'https://mysite.com/',
            violatedDirective: $effectiveDirective,
            effectiveDirective: $effectiveDirective,
            originalPolicy: "default-src 'self'",
            blockedUri: $blockedUri,
            sourceFile: '',
            lineNumber: 0,
            columnNumber: 0,
            statusCode: 200,
            disposition: 'enforce',
            referrer: '',
            scriptSample: '',
            receivedAt: new DateTimeImmutable(),
        );
    }
}
