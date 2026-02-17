<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\FileIncidentReporter;
use Pulsar\Security\Incident\Incident;
use Pulsar\Security\Incident\IncidentSeverity;

use function file_exists;
use function file_get_contents;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

#[CoversClass(FileIncidentReporter::class)]
final class FileIncidentReporterTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = tempnam(sys_get_temp_dir(), 'pulsar_incident_') . '.jsonl';

        // tempnam creates the file; remove it so reporter can start fresh
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
    }

    #[Test]
    public function reportWritesIncidentToFile(): void
    {
        $reporter = new FileIncidentReporter($this->logPath);

        $incident = $reporter->report(
            IncidentSeverity::High,
            'Disk encryption failure',
            'Volume /data failed to encrypt',
            'volume-manager',
        );

        self::assertInstanceOf(Incident::class, $incident);
        self::assertFileExists($this->logPath);

        $contents = file_get_contents($this->logPath);
        self::assertIsString($contents);
        self::assertStringContainsString($incident->id(), $contents);
        self::assertStringContainsString('Disk encryption failure', $contents);
    }

    #[Test]
    public function reportAppendsMultipleIncidents(): void
    {
        $reporter = new FileIncidentReporter($this->logPath);

        $reporter->report(IncidentSeverity::Low, 'First', 'desc');
        $reporter->report(IncidentSeverity::Medium, 'Second', 'desc');
        $reporter->report(IncidentSeverity::High, 'Third', 'desc');

        $contents = file_get_contents($this->logPath);
        self::assertIsString($contents);

        $lines = explode("\n", trim($contents));
        self::assertCount(3, $lines);
    }

    #[Test]
    public function findReturnsStoredIncident(): void
    {
        $reporter = new FileIncidentReporter($this->logPath);

        $incident = $reporter->report(IncidentSeverity::Critical, 'Found', 'desc');

        $found = $reporter->find($incident->id());

        self::assertNotNull($found);
        self::assertSame($incident->id(), $found->id());
        self::assertSame(IncidentSeverity::Critical, $found->severity());
        self::assertSame('Found', $found->title());
    }

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        $reporter = new FileIncidentReporter($this->logPath);

        self::assertNull($reporter->find('nonexistent'));
    }

    #[Test]
    public function findReturnsNullWhenFileDoesNotExist(): void
    {
        $reporter = new FileIncidentReporter('/tmp/nonexistent_pulsar_test.jsonl');

        self::assertNull($reporter->find('any-id'));
    }

    #[Test]
    public function recentReturnsIncidentsSortedByTimestampDesc(): void
    {
        $reporter = new FileIncidentReporter($this->logPath);

        $reporter->report(IncidentSeverity::Low, 'First', 'desc');
        $reporter->report(IncidentSeverity::Medium, 'Second', 'desc');
        $reporter->report(IncidentSeverity::High, 'Third', 'desc');

        $recent = $reporter->recent();

        self::assertCount(3, $recent);
        $titles = array_map(static fn($i) => $i->title(), $recent);
        self::assertContains('First', $titles);
        self::assertContains('Second', $titles);
        self::assertContains('Third', $titles);
    }

    #[Test]
    public function recentRespectsLimit(): void
    {
        $reporter = new FileIncidentReporter($this->logPath);

        $reporter->report(IncidentSeverity::Low, 'A', 'desc');
        $reporter->report(IncidentSeverity::Medium, 'B', 'desc');
        $reporter->report(IncidentSeverity::High, 'C', 'desc');

        $recent = $reporter->recent(2);

        self::assertCount(2, $recent);
    }

    #[Test]
    public function recentFiltersByMinSeverity(): void
    {
        $reporter = new FileIncidentReporter($this->logPath);

        $reporter->report(IncidentSeverity::Low, 'Low', 'desc');
        $reporter->report(IncidentSeverity::Medium, 'Medium', 'desc');
        $reporter->report(IncidentSeverity::High, 'High', 'desc');
        $reporter->report(IncidentSeverity::Critical, 'Critical', 'desc');

        $highAndAbove = $reporter->recent(50, IncidentSeverity::High);

        self::assertCount(2, $highAndAbove);

        foreach ($highAndAbove as $incident) {
            self::assertContains($incident->severity(), [IncidentSeverity::High, IncidentSeverity::Critical]);
        }
    }

    #[Test]
    public function recentReturnsEmptyWhenNoFile(): void
    {
        $reporter = new FileIncidentReporter('/tmp/nonexistent_pulsar_test_2.jsonl');

        self::assertSame([], $reporter->recent());
    }

    #[Test]
    public function recentReturnsEmptyWhenNoMatchingSeverity(): void
    {
        $reporter = new FileIncidentReporter($this->logPath);

        $reporter->report(IncidentSeverity::Low, 'Low only', 'desc');

        self::assertSame([], $reporter->recent(50, IncidentSeverity::Critical));
    }

    #[Test]
    public function createsDirectoryIfNotExists(): void
    {
        $dir = sys_get_temp_dir() . '/pulsar_test_incident_dir_' . bin2hex(random_bytes(4));
        $path = $dir . '/incidents.jsonl';

        try {
            $reporter = new FileIncidentReporter($path);
            $reporter->report(IncidentSeverity::Low, 'Dir test', 'desc');

            self::assertFileExists($path);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }
}
