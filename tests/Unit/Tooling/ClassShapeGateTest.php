<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Plants what `composer class-shape` exists to refuse, and observes the refusal.
 *
 * tools/ci/assert-substitutability-and-immutability.php asks two questions of every
 * class in the repository — can a consumer substitute what the framework depends on,
 * and is every property as immutable as it could be — and the answers it dislikes go
 * into tools/php/substitutability-baseline.json. That baseline holds over twelve
 * hundred entries, which is exactly the situation in which a gate can stop working
 * without anyone noticing: it reports nothing new, and reporting nothing new is also
 * what success looks like.
 *
 * Both questions are planted here, in one fixture, against the real script:
 *
 *   Q1  a class whose constructor takes a `final` concrete class, so no consumer can
 *       decorate, spy on or tenant-scope that dependency;
 *   Q2  a property written only during construction and never again, left mutable.
 *
 * The baseline is then generated over the same fixture and the run repeated, because
 * a gate that refuses everything unconditionally is as useless as one that refuses
 * nothing, and an exit code alone cannot tell the two apart. Removing the entry must
 * re-arm it — that is the ratchet the baseline is supposed to be.
 *
 * `--index=` and `--baseline=` are the script's own options, documented in its
 * header; no change to the gate was needed to drive it from here.
 */
#[GuardsGate(gate: 'composer class-shape', plants: 'a constructor dependency on a final concrete class, and a property written only during construction and left mutable')]
final class ClassShapeGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string SCRIPT = 'tools/ci/assert-substitutability-and-immutability.php';

    /** A final concrete class: nothing a consumer can implement or extend. */
    private const string SEALED = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe;

        final class SealedClock
        {
            public function now(): int
            {
                return 0;
            }
        }
        PHP;

    /**
     * Depends on the sealed class (question 1) and keeps a construction-time-only
     * property mutable (question 2).
     */
    private const string CONSUMER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe;

        final class Consumer
        {
            private string $name;

            public function __construct(private SealedClock $clock, string $name)
            {
                $this->name = $name;
            }

            public function describe(): string
            {
                return $this->name . (string) $this->clock->now();
            }
        }
        PHP;

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function itRefusesADependencyNoConsumerCanSubstituteAndAPropertyLeftMutable(): void
    {
        $tree = $this->plantFixture();

        [$status, $stdout, $stderr] = $this->analyse($tree, $tree . '/no-baseline.json');

        self::assertSame(
            1,
            $status,
            "composer class-shape accepted a class that depends on a final concrete class and keeps a\n"
            . "write-once property mutable. Had it stayed silent, both would ship: the dependency is a\n"
            . "seam a consumer of this framework cannot reach — no decorator, no spy, no tenant-scoped\n"
            . "variant — and the property is state the owner does not control.\n" . $stdout . $stderr,
        );

        // Exactly three: one substitutability site and two properties. A count is what
        // separates "the gate blocks on these verdicts" from "the gate happens to be red";
        // dropping final-concrete from the blocking list would leave the exit code at 1 and
        // change only this number.
        self::assertStringContainsString(
            'FAIL: 3 unbaselined finding(s)',
            $stderr,
            'the gate no longer blocks on all three planted findings, so one of its two questions '
            . 'reports without refusing and its verdicts have become advisory',
        );

        $report = $this->report($tree, $tree . '/no-baseline.json');

        self::assertSame(
            ['final-concrete'],
            $this->siteVerdicts($report),
            'question 1 no longer reports a dependency on a final concrete class, so the substitutability '
            . 'half of the gate has stopped asking its question',
        );
        self::assertContains(
            'should-be-readonly',
            $this->propertyVerdicts($report),
            'question 2 no longer reports a property written only during construction, so the immutability '
            . 'half of the gate has stopped asking its question',
        );
    }

    /**
     * The baseline half. Without this, the refusal above would also be produced by a
     * script that fails on every input, and the 1254 baselined findings would be
     * evidence of nothing.
     */
    #[Test]
    public function theBaselineSilencesExactlyWhatItRecordsAndNothingMore(): void
    {
        $tree = $this->plantFixture();
        $baseline = $tree . '/baseline.json';

        [$generated] = $this->runGate([
            '-d',
            'memory_limit=2G',
            self::SCRIPT,
            '--index=' . $tree,
            '--baseline=' . $baseline,
            '--generate-baseline',
            $tree,
        ]);

        self::assertSame(0, $generated, 'the gate could not record its own findings');

        [$withBaseline, $stdout, $stderr] = $this->analyse($tree, $baseline);

        self::assertSame(
            0,
            $withBaseline,
            "the gate still refuses findings it has just recorded as accepted, so the baseline it reads\n"
            . "is not the baseline it writes and nobody can ever get the tree green.\n" . $stdout . $stderr,
        );

        // Removing an entry must re-arm the gate for that site: that is the whole
        // difference between a baseline and a permanent exemption.
        $recorded = json_decode((string) file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($recorded);
        self::assertIsArray($recorded['entries'] ?? null);
        self::assertNotSame([], $recorded['entries'], 'the generated baseline is empty, so it silences nothing');

        $shortened = $recorded;
        $shortened['entries'] = [];
        $this->plantFile($tree, 'shortened.json', (string) json_encode($shortened));

        [$reArmed] = $this->analyse($tree, $tree . '/shortened.json');

        self::assertSame(
            1,
            $reArmed,
            "emptying tools/php/substitutability-baseline.json's entries left the gate green, so the\n"
            . "baseline is not what suppresses the findings — something else is, and shrinking the\n"
            . 'baseline would no longer re-arm the gate for the sites it stops naming.',
        );
    }

    private function plantFixture(): string
    {
        $tree = $this->plantTree('class-shape');

        $this->plantFile($tree, 'SealedClock.php', self::SEALED);
        $this->plantFile($tree, 'Consumer.php', self::CONSUMER);

        return $tree;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function analyse(string $tree, string $baseline): array
    {
        return $this->runGate([
            '-d',
            'memory_limit=2G',
            self::SCRIPT,
            '--index=' . $tree,
            '--baseline=' . $baseline,
            $tree,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $tree, string $baseline): array
    {
        [, $stdout] = $this->runGate([
            '-d',
            'memory_limit=2G',
            self::SCRIPT,
            '--index=' . $tree,
            '--baseline=' . $baseline,
            '--json',
            $tree,
        ]);

        /** @var mixed $decoded */
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded, 'the gate no longer emits a JSON report: ' . $stdout);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private function propertyVerdicts(array $report): array
    {
        return $this->verdictsIn($report, 'mutability', 'properties');
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private function siteVerdicts(array $report): array
    {
        return $this->verdictsIn($report, 'substitutability', 'sites');
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private function verdictsIn(array $report, string $section, string $collection): array
    {
        $body = $report[$section] ?? null;

        self::assertIsArray($body, 'the JSON report has no ' . $section . ' section');

        $rows = $body[$collection] ?? null;

        self::assertIsArray($rows, 'the ' . $section . ' section has no ' . $collection . ' list');
        self::assertNotSame([], $rows, $section . ' judged nothing at all, so its verdicts prove nothing');

        $verdicts = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('verdict', $row);
            self::assertIsString($row['verdict']);
            $verdicts[] = $row['verdict'];
        }

        return $verdicts;
    }
}
