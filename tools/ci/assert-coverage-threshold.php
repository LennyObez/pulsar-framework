<?php

declare(strict_types=1);

/**
 * Assert the coverage floor from a Clover report.
 *
 * The CI workflow enforced this with an inline `php -r` snippet, which meant a
 * developer could only discover a coverage regression after pushing. The logic
 * lives here so `composer coverage:floor` runs exactly the same check locally,
 * and so the ramp documented for the rc.x window (QUAL-COV-01) is edited in one
 * place instead of inside a YAML string.
 *
 * Usage:
 *   php tools/ci/assert-coverage-threshold.php [clover.xml] [threshold]
 *
 * Exits 1 when any measured dimension is below the threshold.
 */

$cloverPath = $argv[1] ?? 'coverage/clover.xml';

/**
 * Coverage floor for the current release step.
 *
 * QUAL-COV-01 (external audit) ramps this over the rc.x window:
 *   - rc.12 (now): 80 global, which brings the security path up to par.
 *   - rc.13: per-module gates at 95 for auth / security / crypto / audit.
 *   - 1.0.0 GA: 90 global.
 */
const DEFAULT_THRESHOLD = 80.0;

$threshold = isset($argv[2]) ? (float) $argv[2] : DEFAULT_THRESHOLD;

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
