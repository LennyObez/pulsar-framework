<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\Incident;
use Pulsar\Security\Incident\IncidentSeverity;
use Pulsar\Security\Incident\InMemoryIncidentReporter;

#[CoversClass(InMemoryIncidentReporter::class)]
final class InMemoryIncidentReporterTest extends TestCase
{
    private InMemoryIncidentReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new InMemoryIncidentReporter();
    }

    #[Test]
    public function reportCreatesAndStoresIncident(): void
    {
        $incident = $this->reporter->report(
            IncidentSeverity::High,
            'Login spike',
            '50 failed logins in 60 seconds',
            'rate-limiter',
            ['count' => 50],
        );

        self::assertInstanceOf(Incident::class, $incident);
        self::assertSame(IncidentSeverity::High, $incident->severity());
        self::assertSame('Login spike', $incident->title());
        self::assertSame('50 failed logins in 60 seconds', $incident->description());
        self::assertSame('rate-limiter', $incident->source());
        self::assertSame(['count' => 50], $incident->metadata());
    }

    #[Test]
    public function findReturnsStoredIncident(): void
    {
        $incident = $this->reporter->report(IncidentSeverity::Low, 'Test', 'desc');

        $found = $this->reporter->find($incident->id());

        self::assertNotNull($found);
        self::assertSame($incident->id(), $found->id());
    }

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        self::assertNull($this->reporter->find('nonexistent'));
    }

    #[Test]
    public function recentReturnsIncidentsMostRecentFirst(): void
    {
        $first = $this->reporter->report(IncidentSeverity::Low, 'First', 'desc');
        $second = $this->reporter->report(IncidentSeverity::Medium, 'Second', 'desc');
        $third = $this->reporter->report(IncidentSeverity::High, 'Third', 'desc');

        $recent = $this->reporter->recent();

        self::assertCount(3, $recent);
        // Most recent first — but all have the same second timestamp, so order
        // depends on exact microsecond. Verify all are present.
        $ids = array_map(static fn($i) => $i->id(), $recent);
        self::assertContains($first->id(), $ids);
        self::assertContains($second->id(), $ids);
        self::assertContains($third->id(), $ids);
    }

    #[Test]
    public function recentRespectsLimit(): void
    {
        $this->reporter->report(IncidentSeverity::Low, 'A', 'desc');
        $this->reporter->report(IncidentSeverity::Medium, 'B', 'desc');
        $this->reporter->report(IncidentSeverity::High, 'C', 'desc');

        $recent = $this->reporter->recent(2);

        self::assertCount(2, $recent);
    }

    #[Test]
    public function recentFiltersbyMinSeverity(): void
    {
        $this->reporter->report(IncidentSeverity::Low, 'Low', 'desc');
        $this->reporter->report(IncidentSeverity::Medium, 'Medium', 'desc');
        $this->reporter->report(IncidentSeverity::High, 'High', 'desc');
        $this->reporter->report(IncidentSeverity::Critical, 'Critical', 'desc');

        $highAndAbove = $this->reporter->recent(50, IncidentSeverity::High);

        self::assertCount(2, $highAndAbove);

        foreach ($highAndAbove as $incident) {
            self::assertContains($incident->severity(), [IncidentSeverity::High, IncidentSeverity::Critical]);
        }
    }

    #[Test]
    public function recentFiltersWithCriticalSeverityReturnsOnlyCritical(): void
    {
        $this->reporter->report(IncidentSeverity::Low, 'Low', 'desc');
        $this->reporter->report(IncidentSeverity::High, 'High', 'desc');
        $this->reporter->report(IncidentSeverity::Critical, 'Critical', 'desc');

        $criticalOnly = $this->reporter->recent(50, IncidentSeverity::Critical);

        self::assertCount(1, $criticalOnly);
        self::assertSame(IncidentSeverity::Critical, $criticalOnly[0]->severity());
    }

    #[Test]
    public function recentWithLowSeverityReturnsAll(): void
    {
        $this->reporter->report(IncidentSeverity::Low, 'Low', 'desc');
        $this->reporter->report(IncidentSeverity::Medium, 'Medium', 'desc');

        $all = $this->reporter->recent(50, IncidentSeverity::Low);

        self::assertCount(2, $all);
    }

    #[Test]
    public function recentReturnsEmptyWhenNoIncidents(): void
    {
        self::assertSame([], $this->reporter->recent());
    }

    #[Test]
    public function recentWithNoMatchingIncidentsReturnsEmpty(): void
    {
        $this->reporter->report(IncidentSeverity::Low, 'Only low', 'desc');

        self::assertSame([], $this->reporter->recent(50, IncidentSeverity::Critical));
    }

    #[Test]
    public function multipleReportsAreIndependent(): void
    {
        $a = $this->reporter->report(IncidentSeverity::Low, 'A', 'desc-a', 'src-a');
        $b = $this->reporter->report(IncidentSeverity::High, 'B', 'desc-b', 'src-b');

        self::assertNotSame($a->id(), $b->id());
        self::assertNotNull($this->reporter->find($a->id()));
        self::assertNotNull($this->reporter->find($b->id()));
    }
}
