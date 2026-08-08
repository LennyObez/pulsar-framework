<?php

declare(strict_types=1);

/**
 * Fails the build when a test run executed far less than it should have.
 *
 * A suite reports success in two very different situations: everything ran and
 * passed, or very little ran at all. The second is the dangerous one, because from
 * the outside the two look identical — a run that executed a tenth of the suite
 * prints the same cheerful summary as one that executed all of it.
 *
 * Two distinct collapses are worth catching, and they need different questions:
 *
 *  - **Tests disappeared.** A directory dropped out of the configuration, an
 *    autoload change made a whole file unreachable, a testsuite name was renamed.
 *    The total falls and nothing objects. Guarded by --min-tests.
 *
 *  - **Tests abstained.** An optional extension failed to install, a service was
 *    unreachable, a platform capability was absent; every test behind the guard
 *    calls markTestSkipped() and the job still passes. Guarded by
 *    --max-skipped-percent.
 *
 * A percentage rather than a count for the second: skip counts scale with the
 * suite, and a fixed number silently loosens every time tests are added.
 *
 * Counting is done over the testcase elements themselves rather than the summary
 * attributes on <testsuite>. Attribute names and their presence differ between
 * PHPUnit's own JUnit writer and the merged output a parallel runner produces;
 * the elements do not.
 *
 * Usage:
 *   php tools/ci/assert-test-yield.php junit.xml --min-tests=43000 --max-skipped-percent=1.0
 */

/** @var list<string> $arguments */
$arguments = array_values(array_filter($argv ?? [], 'is_string'));
array_shift($arguments);

$path = null;
$minTests = null;
$maxSkippedPercent = null;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--min-tests=')) {
        $minTests = (int) substr($argument, strlen('--min-tests='));

        continue;
    }

    if (str_starts_with($argument, '--max-skipped-percent=')) {
        $maxSkippedPercent = (float) substr($argument, strlen('--max-skipped-percent='));

        continue;
    }

    if ($path === null) {
        $path = $argument;
    }
}

if ($path === null || $minTests === null || $maxSkippedPercent === null) {
    fwrite(STDERR, "usage: php tools/ci/assert-test-yield.php <junit.xml>"
        . " --min-tests=<n> --max-skipped-percent=<p>\n");

    exit(2);
}

if (!is_file($path)) {
    fwrite(STDERR, sprintf(
        "FAIL: no JUnit report at %s.\n\n"
        . "The run produced no report at all, which this gate cannot distinguish from a\n"
        . "run that executed nothing. Check the --log-junit path on the test step.\n",
        $path,
    ));

    exit(1);
}

$previous = libxml_use_internal_errors(true);
$document = simplexml_load_file($path);
libxml_use_internal_errors($previous);

if ($document === false) {
    fwrite(STDERR, sprintf("FAIL: %s is not parseable XML.\n", $path));

    exit(1);
}

$cases = $document->xpath('//testcase') ?: [];
$skipped = $document->xpath('//testcase/skipped') ?: [];

$total = count($cases);
$skippedCount = count($skipped);
$skippedPercent = $total > 0 ? round(($skippedCount / $total) * 100, 3) : 0.0;

printf("Executed  : %d test case(s)\n", $total);
printf("Skipped   : %d (%.3f%%)\n", $skippedCount, $skippedPercent);
printf("Floors    : >= %d tests, <= %.3f%% skipped\n", $minTests, $maxSkippedPercent);

$failures = [];

if ($total < $minTests) {
    $failures[] = sprintf(
        'only %d test case(s) ran, below the floor of %d. Tests did not fail — they '
        . 'were never collected. Something dropped out of tools/php/phpunit.xml, or a '
        . 'whole file stopped being discoverable.',
        $total,
        $minTests,
    );
}

if ($skippedPercent > $maxSkippedPercent) {
    $failures[] = sprintf(
        '%.3f%% of tests skipped, above the ceiling of %.3f%%. A green run that skipped '
        . 'this much has measured its own environment, not the code. Find what became '
        . 'unavailable — an extension, a service, a platform capability — and either '
        . 'restore it or stop claiming the job exercises it.',
        $skippedPercent,
        $maxSkippedPercent,
    );
}

if ($failures !== []) {
    fwrite(STDERR, "\nFAIL:\n  - " . implode("\n  - ", $failures) . "\n");

    exit(1);
}

echo "\nTest yield within bounds.\n";
