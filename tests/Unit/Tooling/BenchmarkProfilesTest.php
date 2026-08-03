<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tooling\Support\JsonDocument;

use function dirname;
use function is_string;

/**
 * Guards the benchmark matrix against declaring a setting nothing reads.
 *
 * profiles.json carried an `optimize` flag on every profile, seven of them set to
 * true, and run.php never looked at it. The seven "-optimized" profiles therefore
 * ran under byte-identical conditions to their plain twins, and the matrix printed
 * both as separate measurements — the most misleading shape a performance report
 * can take, because the duplicate rows look like evidence that the optimisation
 * does nothing.
 *
 * A declared setting that no consumer reads is indistinguishable from a working one
 * by every means except reading the runner, so the check has to be automatic.
 */
final class BenchmarkProfilesTest extends TestCase
{
    private const string PROFILES = __DIR__ . '/../../../tools/bench/profiles.json';
    private const string RUNNER = __DIR__ . '/../../../tools/bench/run.php';

    #[Test]
    public function everySettingDeclaredByAProfileIsReadByTheRunner(): void
    {
        $runner = (string) file_get_contents(self::RUNNER);
        $declared = [];

        foreach (JsonDocument::fromFile(self::PROFILES)->documents() as $profile) {
            foreach (array_keys($profile->toArray()) as $setting) {
                if (is_string($setting) && !str_starts_with($setting, '_')) {
                    $declared[$setting] = true;
                }
            }
        }

        self::assertNotSame([], $declared, 'profiles.json declares no settings at all');

        $unread = [];

        foreach (array_keys($declared) as $setting) {
            // The runner reads a setting through the JsonDocument accessors, so its
            // name appears as a quoted literal in the loading block.
            if (!str_contains($runner, "'" . $setting . "'")) {
                $unread[] = $setting;
            }
        }

        self::assertSame(
            [],
            $unread,
            'tools/bench/profiles.json declares settings that tools/bench/run.php never reads: '
            . implode(', ', $unread)
            . '. Either the runner should honour them, or they should not be in the manifest — a flag '
            . 'that reads as configuration and changes nothing makes two identical runs look like a '
            . 'comparison.',
        );
    }

    /**
     * The flag has to separate the profiles, or it is decoration with a consumer.
     */
    #[Test]
    public function theOptimizeFlagActuallyPartitionsTheMatrix(): void
    {
        $on = $off = 0;

        foreach (JsonDocument::fromFile(self::PROFILES)->documents() as $profile) {
            $profile->boolOr('optimize', false) ? $on++ : $off++;
        }

        self::assertGreaterThan(0, $on, 'no profile enables optimize, so the flag measures nothing');
        self::assertGreaterThan(0, $off, 'every profile enables optimize, so there is nothing to compare against');
    }

    #[Test]
    public function theRunnerSelectsARealCacheStateFromTheFlag(): void
    {
        $runner = (string) file_get_contents(self::RUNNER);

        self::assertStringContainsString(
            "'optimize' : 'optimize:clear'",
            $runner,
            'run.php must warm or clear the framework cache per profile. Reading the flag without '
            . 'acting on it would leave the matrix reporting duplicate rows exactly as before.',
        );
    }

    #[Test]
    public function bothCacheCommandsTheRunnerReliesOnExist(): void
    {
        $console = dirname(__DIR__, 3) . '/src/Console/Command/';

        self::assertFileExists($console . 'OptimizeCommand.php');
        self::assertFileExists($console . 'OptimizeClearCommand.php');
    }
}
