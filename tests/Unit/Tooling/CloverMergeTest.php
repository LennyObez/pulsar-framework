<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

use function array_values;
use function fclose;
use function file_put_contents;
use function is_dir;
use function is_file;
use function proc_close;
use function proc_open;
use function rand;
use function simplexml_load_file;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

/**
 * Guards the merge that turns the coverage parts back into one report.
 *
 * The parts exist only because php-code-coverage cannot hold the whole suite in
 * memory. That makes this merge load-bearing: the coverage gate reads its output
 * and nothing else, so an arithmetic slip here would move the enforced figure
 * without moving a single line of covered code.
 *
 * The case that matters most is the third: a line covered by two parts must count
 * once, not twice. Summing the parts' own metrics — the obvious implementation —
 * gets that wrong, and gets it wrong in the flattering direction.
 */
final class CloverMergeTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../tools/ci/merge-clover.php';

    /** @var list<string> */
    private array $written = [];

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
    public function aSinglePartSurvivesTheMergeUnchanged(): void
    {
        $part = $this->part(['/src/A.php' => [[10, 'stmt', 3], [11, 'stmt', 0]]]);

        $merged = $this->merge($part);

        self::assertSame('1', (string) $merged->project->metrics['files']);
        self::assertSame('2', (string) $merged->project->metrics['statements']);
        self::assertSame('1', (string) $merged->project->metrics['coveredstatements']);
    }

    #[Test]
    public function aLineCoveredByOnlyOnePartIsCoveredInTheMerge(): void
    {
        $a = $this->part(['/src/A.php' => [[10, 'stmt', 1], [11, 'stmt', 0]]]);
        $b = $this->part(['/src/A.php' => [[10, 'stmt', 0], [11, 'stmt', 4]]]);

        $merged = $this->merge($a, $b);

        self::assertSame('2', (string) $merged->project->metrics['statements']);
        self::assertSame(
            '2',
            (string) $merged->project->metrics['coveredstatements'],
            'each part covered one of the two lines, so together they cover both',
        );
    }

    #[Test]
    public function aLineCoveredByTwoPartsIsCountedOnce(): void
    {
        $a = $this->part(['/src/A.php' => [[10, 'stmt', 2], [11, 'stmt', 0]]]);
        $b = $this->part(['/src/A.php' => [[10, 'stmt', 5], [11, 'stmt', 0]]]);

        $merged = $this->merge($a, $b);

        // Summing the parts' metrics would report 2 of 4 statements covered — the
        // same 50% by luck. The line counts are what expose the double count.
        self::assertSame('2', (string) $merged->project->metrics['statements']);
        self::assertSame('1', (string) $merged->project->metrics['coveredstatements']);

        $lines = $merged->project->file[0]->line;
        self::assertSame('10', (string) $lines[0]['num']);
        self::assertSame(
            '7',
            (string) $lines[0]['count'],
            'hit counts add up across parts even though the line is one line',
        );
    }

    #[Test]
    public function filesSeenByDifferentPartsAreAllPresent(): void
    {
        $a = $this->part(['/src/A.php' => [[1, 'stmt', 1]]]);
        $b = $this->part(['/src/B.php' => [[1, 'stmt', 0]]]);

        $merged = $this->merge($a, $b);

        self::assertSame('2', (string) $merged->project->metrics['files']);
        self::assertSame('/src/A.php', (string) $merged->project->file[0]['name']);
        self::assertSame('/src/B.php', (string) $merged->project->file[1]['name']);
    }

    #[Test]
    public function methodsAndStatementsAreCountedSeparately(): void
    {
        $a = $this->part(['/src/A.php' => [[5, 'method', 0], [6, 'stmt', 1]]]);
        $b = $this->part(['/src/A.php' => [[5, 'method', 2], [6, 'stmt', 0]]]);

        $merged = $this->merge($a, $b);

        self::assertSame('1', (string) $merged->project->metrics['methods']);
        self::assertSame('1', (string) $merged->project->metrics['coveredmethods']);
        self::assertSame('1', (string) $merged->project->metrics['statements']);
        self::assertSame('1', (string) $merged->project->metrics['coveredstatements']);
    }

    #[Test]
    public function branchDataSurvivesTheMergeWhenTheDriverEmitsIt(): void
    {
        // PCOV emits no cond lines, so the CI report has none. Xdebug does, and the
        // merge has to carry them or a branch-aware run would lose the one dimension
        // it exists to measure.
        $a = $this->part(['/src/A.php' => [[10, 'cond', 1], [11, 'cond', 0]]]);
        $b = $this->part(['/src/A.php' => [[10, 'cond', 0], [11, 'cond', 3]]]);

        $merged = $this->merge($a, $b);

        self::assertSame('2', (string) $merged->project->metrics['conditionals']);
        self::assertSame('2', (string) $merged->project->metrics['coveredconditionals']);
        self::assertSame(
            '0',
            (string) $merged->project->metrics['statements'],
            'a cond line is not a statement, and counting it as one would inflate the gate',
        );
    }

    #[Test]
    public function itRefusesAMissingPartRatherThanMergingWhatItHas(): void
    {
        $a = $this->part(['/src/A.php' => [[1, 'stmt', 1]]]);
        $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_absent_part.xml';

        [$status, , $stderr] = $this->invoke($this->outputPath(), $a, $missing);

        self::assertSame(1, $status);
        self::assertStringContainsString('not found', $stderr);
    }

    #[Test]
    public function itRefusesToWriteAReportWithNoFilesInIt(): void
    {
        $empty = $this->write(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<coverage generated="1"><project timestamp="1"></project></coverage>',
        );

        [$status, , $stderr] = $this->invoke($this->outputPath(), $empty);

        // An empty merge reads as 0/0 downstream, which the threshold gate would
        // report as "not measured" — a silent pass for a run that produced nothing.
        self::assertSame(1, $status);
        self::assertStringContainsString('no files', $stderr);
    }

    /**
     * @param array<string, list<array{0: int, 1: string, 2: int}>> $files
     */
    private function part(array $files): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<coverage generated="1"><project timestamp="1">';

        foreach ($files as $name => $lines) {
            $xml .= '<file name="' . $name . '">';

            foreach ($lines as [$num, $type, $count]) {
                $xml .= '<line num="' . $num . '" type="' . $type . '" count="' . $count . '"/>';
            }

            $xml .= '<metrics loc="20" ncloc="18" classes="1"/></file>';
        }

        return $this->write($xml . '<metrics files="1"/></project></coverage>');
    }

    private function merge(string ...$parts): SimpleXMLElement
    {
        $output = $this->outputPath();

        [$status, $stdout, $stderr] = $this->invoke($output, ...$parts);

        self::assertSame(0, $status, $stdout . $stderr);

        $merged = simplexml_load_file($output);
        self::assertNotFalse($merged, 'the merge must produce readable XML');

        return $merged;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function invoke(string $output, string ...$parts): array
    {
        $command = array_values([PHP_BINARY, self::SCRIPT, $output, ...$parts]);

        // Descriptor 0 gets its own pipe and is closed at once: an unspecified
        // descriptor is inherited, and handing a parallel runner's control pipe to
        // an unrelated subprocess is a hazard for the sake of nothing.
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            is_dir(__DIR__) ? __DIR__ : null,
        );

        self::assertIsResource($process, 'could not start the merge');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function outputPath(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_merged_' . rand(100000, 999999) . '.xml';
        $this->written[] = $path;

        return $path;
    }

    private function write(string $contents): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_part_' . rand(100000, 999999) . '.xml';
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }
}
