<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_merge;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function proc_close;
use function proc_open;
use function rand;
use function range;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

/**
 * Guards the cut that keeps the coverage job inside the runner's memory.
 *
 * Every test must land in exactly one part. A test dropped here is a test that
 * silently stops contributing coverage, and the merged report would show the loss
 * as uncovered source rather than as a missing run — the failure would be read as
 * a code problem, not a tooling one.
 */
final class TestPartitionTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../tools/ci/partition-tests.php';

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
    public function everyTestLandsInExactlyOnePart(): void
    {
        $ids = array_map(static fn(int $n): string => sprintf('Suite\\ClassTest::case%d', $n), range(1, 100));

        $parts = $this->partition($ids, 7);

        self::assertCount(7, $parts);
        self::assertSame($ids, array_values(array_merge(...$parts)), 'no test lost, none duplicated, order kept');
    }

    #[Test]
    public function partsDifferInSizeByAtMostOneTest(): void
    {
        $ids = array_map(static fn(int $n): string => sprintf('T::c%d', $n), range(1, 105));

        $sizes = array_map(count(...), $this->partition($ids, 10));

        // 105 over 10: the first five parts absorb the remainder, one test each.
        self::assertSame([11, 11, 11, 11, 11, 10, 10, 10, 10, 10], $sizes);
    }

    #[Test]
    public function partsAreContiguousRatherThanInterleaved(): void
    {
        $ids = array_map(static fn(int $n): string => sprintf('T::c%d', $n), range(1, 10));

        $parts = $this->partition($ids, 2);

        // Contiguous, so a class's tests stay together and each part loads fewer
        // test classes. Round-robin would put c1 and c3 in the same part.
        self::assertSame(['T::c1', 'T::c2', 'T::c3', 'T::c4', 'T::c5'], $parts[0]);
        self::assertSame(['T::c6', 'T::c7', 'T::c8', 'T::c9', 'T::c10'], $parts[1]);
    }

    #[Test]
    public function anEmptyListIsRefusedRatherThanCutIntoEmptyParts(): void
    {
        [$status, , $stderr] = $this->invoke($this->listFile([]), 4);

        // Empty parts would run nothing, report full coverage of nothing, and drag
        // the merged total down as though the source had gone uncovered.
        self::assertSame(1, $status);
        self::assertStringContainsString('no tests', $stderr);
    }

    #[Test]
    public function morePartsThanTestsIsRefused(): void
    {
        [$status, , $stderr] = $this->invoke($this->listFile(['T::a', 'T::b']), 5);

        self::assertSame(1, $status);
        self::assertStringContainsString('Cannot cut 2 tests into 5 parts', $stderr);
    }

    #[Test]
    public function aMissingListIsRefused(): void
    {
        $absent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_absent_list.xml';

        [$status, , $stderr] = $this->invoke($absent, 2);

        self::assertSame(1, $status);
        self::assertStringContainsString('not found', $stderr);
    }

    #[Test]
    public function anInvalidInvocationExitsTwoSoACiTypoIsNotReadAsAFailedGate(): void
    {
        [$status, , $stderr] = $this->invoke($this->listFile(['T::a']), 0);

        self::assertSame(2, $status);
        self::assertStringContainsString('Usage:', $stderr);
    }

    /**
     * @param list<string> $ids
     * @return list<list<string>>
     */
    private function partition(array $ids, int $parts): array
    {
        $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_part_' . rand(100000, 999999) . '-';

        [$status, $stdout, $stderr] = $this->invoke($this->listFile($ids), $parts, $prefix);

        self::assertSame(0, $status, $stdout . $stderr);

        $result = [];

        for ($index = 1; $index <= $parts; $index++) {
            $path = sprintf('%s%d.txt', $prefix, $index);
            $this->written[] = $path;

            $result[] = explode("\n", trim((string) file_get_contents($path)));
        }

        return $result;
    }

    /**
     * @param list<string> $ids
     */
    private function listFile(array $ids): string
    {
        $methods = implode('', array_map(
            static fn(string $id): string => sprintf('<testMethod id="%s" name="x"/>', $id),
            $ids,
        ));

        return $this->write(
            '<?xml version="1.0"?>' . "\n"
            . '<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>'
            . '<testClass name="T" file="/T.php">' . $methods . '</testClass>'
            . '</tests></testSuite>',
        );
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function invoke(string $list, int $parts, ?string $prefix = null): array
    {
        $prefix ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_part_' . rand(100000, 999999) . '-';

        $command = [PHP_BINARY, self::SCRIPT, $list, (string) $parts, $prefix];

        // Descriptor 0 gets its own pipe and is closed at once: an unspecified
        // descriptor is inherited, and handing a parallel runner's control pipe to
        // an unrelated subprocess is a hazard for the sake of nothing.
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            is_dir(__DIR__) ? __DIR__ : null,
        );

        self::assertIsResource($process, 'could not start the partitioner');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function write(string $contents): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_list_' . rand(100000, 999999) . '.xml';
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }
}
