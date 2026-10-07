<?php

declare(strict_types=1);

/**
 * Assert the coverage floor from a Clover report.
 *
 * The logic lives in a script rather than an inline `php -r` snippet inside the
 * workflow so that `composer coverage:floor` runs exactly the same check
 * locally — a coverage regression is visible before pushing — and so the ramp
 * below is edited in one place instead of inside a YAML string.
 *
 * Usage:
 *   php tools/ci/assert-coverage-threshold.php [clover.xml] [threshold] [--report-only=A,B]
 *
 * Exits 1 when any measured dimension is below the threshold, except the
 * dimensions named in --report-only, whose figure is printed and not gated.
 *
 * That option exists for one honest case: a dimension measured for the first
 * time. Branch coverage has never been measured on this suite, because the only
 * driver that emits it cannot finish the suite inside a single job — see
 * ADR-0042. A floor chosen before the first measurement would be a number rather
 * than a standard, so the nightly workflow reports Conditions until there is a
 * figure to set the floor from. Naming the dimension on the command line keeps
 * that decision visible in the workflow instead of buried here.
 */

$arguments = $argv ?? [];

/** @var list<string> $positional */
$positional = [];

/** @var array<string, true> $reportOnly */
$reportOnly = [];

foreach (array_slice($arguments, 1) as $argument) {
    if (str_starts_with($argument, '--report-only=')) {
        foreach (explode(',', substr($argument, 14)) as $dimension) {
            $dimension = trim($dimension);

            if ($dimension !== '') {
                $reportOnly[$dimension] = true;
            }
        }

        continue;
    }

    $positional[] = $argument;
}

$cloverPath = $positional[0] ?? 'coverage/clover.xml';

/**
 * Coverage floor for the current release step.
 *
 * The floor ramps over the rc.x window:
 *   - rc.12 (now): 80 global, which brings the security path up to par.
 *   - rc.13: per-module gates at 95 for auth / security / crypto / audit.
 *   - 1.0.0 GA: 90 global.
 */
const DEFAULT_THRESHOLD = 80.0;

$threshold = isset($positional[1]) ? (float) $positional[1] : DEFAULT_THRESHOLD;

if (!is_file($cloverPath)) {
    fwrite(STDERR, sprintf(
        "Coverage report not found at \"%s\".\nRun `composer test:coverage` first (it writes coverage/clover.xml).\n",
        $cloverPath,
    ));

    exit(1);
}

$xml = simplexml_load_file($cloverPath);

if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, sprintf("\"%s\" is not a readable Clover report.\n", $cloverPath));

    exit(1);
}

$metrics = $xml->project->metrics;

/**
 * @param string $covered Clover attribute holding the covered count
 * @param string $total   Clover attribute holding the total count
 * @return array{float, int, int}
 */
$ratio = static function (SimpleXMLElement $metrics, string $covered, string $total): array {
    $totalCount = (int) ($metrics[$total] ?? 0);
    $coveredCount = (int) ($metrics[$covered] ?? 0);
    $percent = $totalCount > 0 ? round(($coveredCount / $totalCount) * 100, 2) : 0.0;

    return [$percent, $coveredCount, $totalCount];
};

$dimensions = [
    'Statements' => $ratio($metrics, 'coveredstatements', 'statements'),
    'Conditions' => $ratio($metrics, 'coveredconditionals', 'conditionals'),
    'Methods' => $ratio($metrics, 'coveredmethods', 'methods'),
];

$failed = [];

foreach ($dimensions as $name => [$percent, $coveredCount, $totalCount]) {
    // A dimension with nothing to divide by was not measured, and saying "0.00%"
    // about it is a lie in the alarming direction: it reads as a collapse in
    // quality rather than as an absent driver. PCOV records lines and methods and
    // has no notion of branches, so Conditions arrives as 0/0 under it, while
    // Xdebug, the only driver that emits branch data, cannot finish this suite
    // inside a single job. The distinction is reported rather than folded into a
    // percentage.
    if ($totalCount === 0) {
        printf("%-11s: not measured (the active coverage driver reports no data for it)\n", $name);

        continue;
    }

    // Named on --report-only: printed, and not allowed to decide the outcome.
    // A dimension being measured for the first time has no floor yet, and
    // inventing one before seeing the figure would be picking a number rather
    // than setting a standard.
    if (isset($reportOnly[$name])) {
        printf(
            "%-11s: %6.2f%% (%d/%d) — reported, not gated\n",
            $name,
            $percent,
            $coveredCount,
            $totalCount,
        );

        continue;
    }

    printf("%-11s: %6.2f%% (%d/%d)\n", $name, $percent, $coveredCount, $totalCount);

    if ($percent < $threshold) {
        $failed[] = sprintf('%s coverage %.2f%% is below the %.2f%% floor', $name, $percent, $threshold);
    }
}

if ($failed !== []) {
    fwrite(STDERR, "\nFAIL:\n  - " . implode("\n  - ", $failed) . "\n");

    exit(1);
}

printf("\nOK: every dimension meets the %.2f%% floor.\n", $threshold);

exit(0);
