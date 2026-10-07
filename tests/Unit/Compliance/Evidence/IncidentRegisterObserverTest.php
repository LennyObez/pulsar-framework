<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\IncidentRegisterObserver;
use Pulsar\Security\Incident\FileIncidentReporter;
use Pulsar\Security\Incident\Incident;
use Pulsar\Security\Incident\IncidentInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;
use Pulsar\Security\Incident\InMemoryIncidentReporter;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;

use function bin2hex;
use function explode;
use function file_get_contents;
use function is_file;
use function json_decode;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function trim;
use function unlink;

/**
 * The register measurement, driven directly against registers that behave in
 * each of the ways one can.
 *
 * Driven here rather than only through {@see \Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment}
 * because the interesting failures are not shapes a composition root can be
 * asked for: a register that accepts a record and forgets it, and one that
 * accepts a record and gives back a different one, are both bound, both
 * resolving to a class an accept list would take, and both worthless. The
 * fixture can express "no register" and "the in-memory register"; only a double
 * can express "it lied".
 *
 * The observer is called directly, which the production seal permits: it is
 * compiled from `src/Compliance/Evidence/`, so it is the component that measures
 * however it was reached. What a test cannot do is compose an
 * {@see \Pulsar\Compliance\Control\Observation} itself, which is the property
 * {@see \Pulsar\Tests\Unit\Compliance\Control\OutcomeSealTest} covers.
 */
#[CoversClass(IncidentRegisterObserver::class)]
final class IncidentRegisterObserverTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->written = [];
    }

    #[Test]
    public function noRegisterIsAnAbsentFactAndNotAPass(): void
    {
        $observation = self::observer()->observe(null);

        self::assertSame(ObservationId::IncidentRecordedAndRetained, $observation->id);
        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertStringContainsString('No incident register is in service', $observation->detail);
    }

    /**
     * The whole point, against the register a default installation actually
     * gets: the record goes to a file and comes back off the file.
     */
    #[Test]
    public function theFileRegisterRecordsDatesAndReturnsTheIncident(): void
    {
        $observation = self::observer()->observe(new FileIncidentReporter($this->logPath()));

        self::assertTrue($observation->present, $observation->detail);
        self::assertTrue($observation->isAdmissibleAsProof());
        self::assertStringContainsString('read back by id unchanged', $observation->detail);
        self::assertStringContainsString('3 subject(s) ran', $observation->detail);
    }

    /**
     * What it leaves behind, asserted rather than described: exactly one row,
     * Low, under the probe source, carrying the run marker.
     *
     * This is the one measurement in the evidence set that cannot undo its own
     * write, so the size and shape of what it leaves is a property worth
     * holding still.
     */
    #[Test]
    public function itLeavesExactlyOneLowSeverityRowThatSaysWhatItIs(): void
    {
        $path = $this->logPath();

        (void) self::observer()->observe(new FileIncidentReporter($path));

        $lines = explode("\n", trim((string) file_get_contents($path)));

        self::assertCount(1, $lines, 'One report run must leave one row.');

        /** @var array{severity: string, source: string, title: string, metadata: array<string, string>} $row */
        $row = json_decode($lines[0], true);

        self::assertSame(IncidentSeverity::Low->value, $row['severity']);
        self::assertSame(IncidentRegisterObserver::PROBE_SOURCE, $row['source']);
        self::assertStringContainsString('not a security event', $row['title']);
        self::assertArrayHasKey('probe_marker', $row['metadata']);
    }

    /**
     * And the row is INVISIBLE to the deadline check that reads the same
     * register, which is the reason the severity is Low.
     *
     * {@see \Pulsar\Compliance\Verification\BreachNotificationCheck} reads the
     * register at High and above and fails a deployment holding a reportable
     * incident past its notification deadline. A probe row written at any higher
     * severity would, hours later, fail the very check it exists to support: the
     * measurement would have manufactured the breach it reports on. That is a
     * property of two classes agreeing, so it is asserted rather than commented.
     */
    #[Test]
    public function theRetainedRowIsBelowTheSeverityTheDeadlineCheckInspects(): void
    {
        $register = new FileIncidentReporter($this->logPath());

        (void) self::observer()->observe($register);

        self::assertSame(
            [],
            $register->recent(50, IncidentSeverity::High),
            'The probe row must never be reportable, or the report creates the breach it checks.',
        );
        self::assertCount(1, $register->recent(50, IncidentSeverity::Low));
    }

    /**
     * A register that will not accept a record RAN and refused; it is not the
     * same fact as no register at all, and the report must not print it the same
     * way.
     */
    #[Test]
    public function aRegisterThatRefusesTheRecordIsBoundAndNotUsable(): void
    {
        $observation = self::observer()->observe(self::registerThat(
            static fn(): never => throw new RuntimeException('the log directory is read-only'),
        ));

        self::assertFalse($observation->present);
        self::assertStringContainsString('bound but not usable', $observation->detail);
        self::assertStringContainsString('the log directory is read-only', $observation->detail);
    }

    /**
     * The failure the resolved identity cannot see: a register that accepts the
     * record, reports success, and has nothing when asked for it back.
     */
    #[Test]
    public function aRegisterThatForgetsWhatItAcceptedFailsTheControl(): void
    {
        $observation = self::observer()->observe(new class implements IncidentReporterInterface {
            #[Override]
            public function report(
                IncidentSeverity $severity,
                string $title,
                string $description,
                string $source = '',
                array $metadata = [],
            ): IncidentInterface {
                return Incident::create($severity, $title, $description, $source, $metadata);
            }

            #[Override]
            public function find(string $id): ?IncidentInterface
            {
                return null;
            }

            #[Override]
            public function recent(int $limit = 50, ?IncidentSeverity $minSeverity = null): array
            {
                return [];
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('did not survive the call that created it', $observation->detail);
    }

    /**
     * And the failure that is worse than losing the record, because it looks
     * like success: the row comes back with a different clock.
     *
     * A deadline computed from a record whose timestamp moved is wrong in the
     * direction nobody checks, and Article 33 is entirely a statement about a
     * deadline.
     */
    #[Test]
    public function aRegisterThatAltersTheTimestampFailsTheControl(): void
    {
        $observation = self::observer()->observe(new class implements IncidentReporterInterface {
            private ?IncidentInterface $stored = null;

            #[Override]
            public function report(
                IncidentSeverity $severity,
                string $title,
                string $description,
                string $source = '',
                array $metadata = [],
            ): IncidentInterface {
                $incident = Incident::create($severity, $title, $description, $source, $metadata);

                $this->stored = new Incident(
                    id: $incident->id(),
                    severity: $incident->severity(),
                    title: $incident->title(),
                    description: $incident->description(),
                    reportedAt: new DateTimeImmutable('@1'),
                    source: $incident->source(),
                    metadata: $incident->metadata(),
                );

                return $incident;
            }

            #[Override]
            public function find(string $id): ?IncidentInterface
            {
                return $this->stored;
            }

            #[Override]
            public function recent(int $limit = 50, ?IncidentSeverity $minSeverity = null): array
            {
                return $this->stored === null ? [] : [$this->stored];
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('a deadline computed from the stored record', $observation->detail);
    }

    /**
     * A register that keeps the row and drops the context around it cannot carry
     * a notification: Article 33 asks a controller to describe the nature of the
     * breach and the categories of subjects affected.
     */
    #[Test]
    public function aRegisterThatDropsTheMetadataFailsTheControl(): void
    {
        $observation = self::observer()->observe(new class implements IncidentReporterInterface {
            private ?IncidentInterface $stored = null;

            #[Override]
            public function report(
                IncidentSeverity $severity,
                string $title,
                string $description,
                string $source = '',
                array $metadata = [],
            ): IncidentInterface {
                $incident = Incident::create($severity, $title, $description, $source, $metadata);

                $this->stored = new Incident(
                    id: $incident->id(),
                    severity: $incident->severity(),
                    title: $incident->title(),
                    description: $incident->description(),
                    reportedAt: $incident->reportedAt(),
                    source: $incident->source(),
                    metadata: [],
                );

                return $incident;
            }

            #[Override]
            public function find(string $id): ?IncidentInterface
            {
                return $this->stored;
            }

            #[Override]
            public function recent(int $limit = 50, ?IncidentSeverity $minSeverity = null): array
            {
                return $this->stored === null ? [] : [$this->stored];
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('the metadata did not survive', $observation->detail);
    }

    /**
     * The in-memory register PASSES this measurement, and saying so here is the
     * point rather than an embarrassment.
     *
     * Every subject it runs is satisfied inside one process, and the register is
     * empty again at the end of the request. That is why
     * {@see ObservationId::IncidentReporterResolved} stays an essential fact in
     * {@see \Pulsar\Compliance\Probe\BreachNotificationProbe}: this measurement
     * cannot see durability and must not be relied on for it.
     */
    #[Test]
    public function theInMemoryRegisterPassesTheMeasurementAndIsCaughtElsewhere(): void
    {
        $observation = self::observer()->observe(new InMemoryIncidentReporter());

        self::assertTrue($observation->present, $observation->detail);
    }

    private static function observer(): IncidentRegisterObserver
    {
        return new IncidentRegisterObserver(new Randomizer(new Secure()));
    }

    /**
     * A register whose report() does whatever the test says.
     *
     * @param callable(): IncidentInterface $onReport
     */
    private static function registerThat(callable $onReport): IncidentReporterInterface
    {
        return new class ($onReport) implements IncidentReporterInterface {
            /**
             * @param callable(): IncidentInterface $onReport
             */
            public function __construct(private $onReport) {}

            #[Override]
            public function report(
                IncidentSeverity $severity,
                string $title,
                string $description,
                string $source = '',
                array $metadata = [],
            ): IncidentInterface {
                return ($this->onReport)();
            }

            #[Override]
            public function find(string $id): ?IncidentInterface
            {
                throw new RuntimeException('The refusing register holds nothing to find.');
            }

            #[Override]
            public function recent(int $limit = 50, ?IncidentSeverity $minSeverity = null): array
            {
                return [];
            }
        };
    }

    private function logPath(): string
    {
        $directory = sys_get_temp_dir() . '/pulsar_incident_probe_' . bin2hex(random_bytes(6));
        @mkdir($directory, 0o700, true);

        $path = $directory . '/incidents.jsonl';
        $this->written[] = $path;

        return $path;
    }
}
