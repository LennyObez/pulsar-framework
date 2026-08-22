<?php

declare(strict_types=1);

/**
 * Cut the suite into N parts that a single coverage job runs one after another.
 *
 * These are not shards. Nothing here fans out across jobs; the parts run
 * sequentially inside one `PHP Coverage` job, and the only reason they exist is
 * memory. php-code-coverage records, for every covered line, the id of every
 * test that touched it (ProcessedCodeCoverageData, unconditionally — there is no
 * switch). Memory therefore grows with the number of tests, without bound. The
 * measurement, from the run that died on CI:
 *
 *     13,457 of 42,579 tests, ~8 minutes, killed by a signal on a 16 GB runner
 *     -> roughly 1.1 MB per test, so 44,544 tests need well over 40 GB
 *
 * Recycling the process every N tests is what keeps the peak bounded; it is the
 * only lever, because the growth is inside the coverage library.
 *
 * The list comes from PHPUnit's own discovery (`--list-tests-xml`), never from a
 * hand-maintained list of paths. That distinction is the whole point: a directory
 * added to phpunit.xml enters the parts automatically, whereas a partitioner fed
 * by its own path list would silently stop covering it — the same class of gap
 * that left eight extension suites unexecuted.
 *
 * Parts are contiguous, not round-robin. Wall-clock balance is irrelevant when
 * the parts run in sequence (the total is the sum either way), and keeping a
 * class's tests together means each part loads fewer test classes.
 *
 * Usage:
 *   php tools/ci/partition-tests.php <tests.xml> <parts> <output-prefix>
 *
 * Writes <output-prefix>1.txt … <output-prefix>N.txt, one test ID per line, in
 * the format `--test-id-filter-file` expects.
 */

$listPath = $argv[1] ?? '';
$partCount = (int) ($argv[2] ?? 0);
$prefix = $argv[3] ?? '';

if ($listPath === '' || $partCount < 1 || $prefix === '') {
    fwrite(STDERR, "Usage: php tools/ci/partition-tests.php <tests.xml> <parts> <output-prefix>\n");

    exit(2);
}

if (!is_file($listPath)) {
    fwrite(STDERR, sprintf("Test list not found at \"%s\".\n", $listPath));

    exit(1);
}

$xml = simplexml_load_file($listPath);

if ($xml === false) {
    fwrite(STDERR, sprintf("\"%s\" is not a readable test list.\n", $listPath));

    exit(1);
}

$xml->registerXPathNamespace('p', 'https://xml.phpunit.de/testSuite');

$ids = [];

foreach ($xml->xpath('//p:testMethod') ?: [] as $method) {
    $id = (string) $method['id'];

    if ($id !== '') {
        $ids[] = $id;
    }
}

if ($ids === []) {
    // Never emit an empty part: it would run nothing, report full coverage of
    // nothing, and the merge would quietly lower the total instead of failing.
    fwrite(STDERR, "The test list contains no tests — refusing to write empty parts.\n");

    exit(1);
}

$total = count($ids);

if ($partCount > $total) {
    fwrite(STDERR, sprintf("Cannot cut %d tests into %d parts.\n", $total, $partCount));

    exit(1);
}

$size = intdiv($total, $partCount);
$remainder = $total % $partCount;
$offset = 0;

for ($index = 0; $index < $partCount; $index++) {
    // The first $remainder parts take one extra test, so no part is short by
    // more than one and none is left empty.
    $length = $size + ($index < $remainder ? 1 : 0);
    $part = array_slice($ids, $offset, $length);
    $offset += $length;

    $target = sprintf('%s%d.txt', $prefix, $index + 1);
    file_put_contents($target, implode("\n", $part) . "\n");

    printf("%s: %d tests\n", $target, count($part));
}

printf("Cut %d tests into %d sequential parts.\n", $total, $partCount);
