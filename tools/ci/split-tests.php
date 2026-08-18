<?php

declare(strict_types=1);

/**
 * Partition the suite into N shards for the coverage pass.
 *
 * The list comes from PHPUnit's own discovery (`--list-tests-xml`), never from a
 * hand-maintained list of paths. That distinction is the whole point: a directory
 * added to phpunit.xml enters the shards automatically, whereas a splitter fed by
 * its own path list would silently stop covering it — the same class of gap that
 * left eight extension suites unexecuted.
 *
 * Distribution is round-robin over test IDs, so the shards stay balanced even
 * though a single class can hold hundreds of tests and its neighbour three.
 *
 * Usage:
 *   php tools/ci/split-tests.php <tests.xml> <shards> <output-prefix>
 *
 * Writes <output-prefix>1.txt … <output-prefix>N.txt, one test ID per line, in
 * the format `--test-id-filter-file` expects.
 */

$listPath = $argv[1] ?? '';
$shardCount = (int) ($argv[2] ?? 0);
$prefix = $argv[3] ?? '';

if ($listPath === '' || $shardCount < 1 || $prefix === '') {
    fwrite(STDERR, "Usage: php tools/ci/split-tests.php <tests.xml> <shards> <output-prefix>\n");

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
    // Never emit empty shards: they would run nothing, report full coverage of
    // nothing, and the merge would quietly lower the total instead of failing.
    fwrite(STDERR, "The test list contains no tests — refusing to write empty shards.\n");

    exit(1);
}

$shards = array_fill(0, $shardCount, []);

foreach ($ids as $index => $id) {
    $shards[$index % $shardCount][] = $id;
}

foreach ($shards as $index => $shard) {
    $target = sprintf('%s%d.txt', $prefix, $index + 1);

    if ($shard === []) {
        fwrite(STDERR, sprintf(
            "Shard %d would be empty (%d tests across %d shards).\n",
            $index + 1,
            count($ids),
            $shardCount,
        ));

        exit(1);
    }

    file_put_contents($target, implode("\n", $shard) . "\n");

    printf("%s: %d tests\n", $target, count($shard));
}

printf("Partitioned %d tests into %d shards.\n", count($ids), $shardCount);
