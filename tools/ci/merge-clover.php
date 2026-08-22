<?php

declare(strict_types=1);

/**
 * Merge the Clover reports the sequential coverage parts produce into one.
 *
 * The parts exist because php-code-coverage keeps a per-test map in memory (see
 * tools/ci/partition-tests.php); each writes its own Clover, and this rebuilds
 * the single report the threshold gate and the artifact expect.
 *
 * Merging happens at line level: a line is covered if any part covered it, and
 * its hit count is the sum across parts. File and project metrics are then
 * recomputed from the merged lines rather than summed from the parts, because
 * summing would count a line covered by two parts twice.
 *
 * What this does not reproduce: the per-class metrics blocks. Clover records
 * class metrics but never says which lines belong to which class, so they cannot
 * be recomputed from the parts, and carrying one part's numbers forward would
 * publish a figure that is wrong for the merged run. They are left out rather
 * than faked. File-level and project-level metrics — everything the coverage
 * gate reads, and everything a reader would total up by hand — are exact.
 *
 * Streaming with XMLReader, one part at a time: a Clover report over 2,500-odd
 * source files is large enough that loading ten of them as DOM trees would
 * reintroduce the memory problem the parts were created to solve.
 *
 * Usage:
 *   php tools/ci/merge-clover.php <output.xml> <part1.xml> [<part2.xml> ...]
 */

$arguments = $argv ?? [];
$output = $arguments[1] ?? '';
$parts = array_slice($arguments, 2);

if ($output === '' || $parts === []) {
    fwrite(STDERR, "Usage: php tools/ci/merge-clover.php <output.xml> <part1.xml> [<part2.xml> ...]\n");

    exit(2);
}

/**
 * Line coverage per file, merged across parts.
 *
 * @var array<string, array<int, array{type: string, count: int, attrs: array<string, string>}>> $lineData
 */
$lineData = [];

/**
 * Source facts per file: they describe the file, not the run, so the first part
 * to report them is as good as any.
 *
 * @var array<string, array{loc: int, ncloc: int, classes: int}> $fileFacts
 */
$fileFacts = [];

foreach ($parts as $part) {
    if (!is_file($part)) {
        fwrite(STDERR, sprintf("Coverage part not found at \"%s\".\n", $part));

        exit(1);
    }

    $reader = new XMLReader();

    if (!$reader->open($part)) {
        fwrite(STDERR, sprintf("\"%s\" is not a readable Clover report.\n", $part));

        exit(1);
    }

    $currentFile = null;

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        if ($reader->name === 'file') {
            $currentFile = (string) $reader->getAttribute('name');
            $lineData[$currentFile] ??= [];

            continue;
        }

        if ($currentFile === null) {
            continue;
        }

        if ($reader->name === 'line') {
            $number = (int) $reader->getAttribute('num');
            $count = (int) $reader->getAttribute('count');
            $known = $lineData[$currentFile][$number] ?? null;

            if ($known !== null) {
                $known['count'] += $count;
                $lineData[$currentFile][$number] = $known;

                continue;
            }

            $attributes = [];

            foreach (['name', 'visibility', 'complexity', 'crap'] as $attribute) {
                $value = $reader->getAttribute($attribute);

                if ($value !== null) {
                    $attributes[$attribute] = $value;
                }
            }

            $lineData[$currentFile][$number] = [
                'type' => (string) $reader->getAttribute('type'),
                'count' => $count,
                'attrs' => $attributes,
            ];

            continue;
        }

        if ($reader->name === 'metrics' && !isset($fileFacts[$currentFile])) {
            $fileFacts[$currentFile] = [
                'loc' => (int) $reader->getAttribute('loc'),
                'ncloc' => (int) $reader->getAttribute('ncloc'),
                'classes' => (int) $reader->getAttribute('classes'),
            ];
        }
    }

    $reader->close();
}

if ($lineData === []) {
    fwrite(STDERR, "The parts contain no files — refusing to write an empty report.\n");

    exit(1);
}

$document = new DOMDocument('1.0', 'UTF-8');
$document->formatOutput = true;

$generated = (string) time();

$coverage = $document->createElement('coverage');
$coverage->setAttribute('generated', $generated);
$document->appendChild($coverage);

$project = $document->createElement('project');
$project->setAttribute('timestamp', $generated);
$coverage->appendChild($project);

$totalFiles = 0;
$totalLoc = 0;
$totalNcloc = 0;
$totalClasses = 0;
$totalMethods = 0;
$totalCoveredMethods = 0;
$totalConditionals = 0;
$totalCoveredConditionals = 0;
$totalStatements = 0;
$totalCoveredStatements = 0;

ksort($lineData);

foreach ($lineData as $name => $lines) {
    $xmlFile = $document->createElement('file');
    $xmlFile->setAttribute('name', $name);
    $project->appendChild($xmlFile);

    ksort($lines);

    $methods = 0;
    $coveredMethods = 0;
    $statements = 0;
    $coveredStatements = 0;
    $conditionals = 0;
    $coveredConditionals = 0;

    foreach ($lines as $number => $line) {
        $xmlLine = $document->createElement('line');
        $xmlLine->setAttribute('num', (string) $number);
        $xmlLine->setAttribute('type', $line['type']);

        foreach ($line['attrs'] as $attribute => $value) {
            $xmlLine->setAttribute($attribute, $value);
        }

        $xmlLine->setAttribute('count', (string) $line['count']);
        $xmlFile->appendChild($xmlLine);

        $covered = $line['count'] > 0 ? 1 : 0;

        if ($line['type'] === 'method') {
            $methods++;
            $coveredMethods += $covered;
        } elseif ($line['type'] === 'cond') {
            $conditionals++;
            $coveredConditionals += $covered;
        } else {
            $statements++;
            $coveredStatements += $covered;
        }
    }

    $facts = $fileFacts[$name] ?? ['loc' => 0, 'ncloc' => 0, 'classes' => 0];

    $metrics = $document->createElement('metrics');
    $metrics->setAttribute('loc', (string) $facts['loc']);
    $metrics->setAttribute('ncloc', (string) $facts['ncloc']);
    $metrics->setAttribute('classes', (string) $facts['classes']);
    $metrics->setAttribute('methods', (string) $methods);
    $metrics->setAttribute('coveredmethods', (string) $coveredMethods);
    $metrics->setAttribute('conditionals', (string) $conditionals);
    $metrics->setAttribute('coveredconditionals', (string) $coveredConditionals);
    $metrics->setAttribute('statements', (string) $statements);
    $metrics->setAttribute('coveredstatements', (string) $coveredStatements);
    $metrics->setAttribute('elements', (string) ($methods + $statements + $conditionals));
    $metrics->setAttribute(
        'coveredelements',
        (string) ($coveredMethods + $coveredStatements + $coveredConditionals),
    );
    $xmlFile->appendChild($metrics);

    $totalFiles++;
    $totalLoc += $facts['loc'];
    $totalNcloc += $facts['ncloc'];
    $totalClasses += $facts['classes'];
    $totalMethods += $methods;
    $totalCoveredMethods += $coveredMethods;
    $totalConditionals += $conditionals;
    $totalCoveredConditionals += $coveredConditionals;
    $totalStatements += $statements;
    $totalCoveredStatements += $coveredStatements;
}

$projectMetrics = $document->createElement('metrics');
$projectMetrics->setAttribute('files', (string) $totalFiles);
$projectMetrics->setAttribute('loc', (string) $totalLoc);
$projectMetrics->setAttribute('ncloc', (string) $totalNcloc);
$projectMetrics->setAttribute('classes', (string) $totalClasses);
$projectMetrics->setAttribute('methods', (string) $totalMethods);
$projectMetrics->setAttribute('coveredmethods', (string) $totalCoveredMethods);
$projectMetrics->setAttribute('conditionals', (string) $totalConditionals);
$projectMetrics->setAttribute('coveredconditionals', (string) $totalCoveredConditionals);
$projectMetrics->setAttribute('statements', (string) $totalStatements);
$projectMetrics->setAttribute('coveredstatements', (string) $totalCoveredStatements);
$projectMetrics->setAttribute(
    'elements',
    (string) ($totalMethods + $totalStatements + $totalConditionals),
);
$projectMetrics->setAttribute(
    'coveredelements',
    (string) ($totalCoveredMethods + $totalCoveredStatements + $totalCoveredConditionals),
);
$project->appendChild($projectMetrics);

$directory = dirname($output);

if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
    fwrite(STDERR, sprintf("Cannot create \"%s\".\n", $directory));

    exit(1);
}

$document->save($output);

printf(
    "Merged %d parts into %s: %d files, %d/%d statements, %d/%d methods.\n",
    count($parts),
    $output,
    $totalFiles,
    $totalCoveredStatements,
    $totalStatements,
    $totalCoveredMethods,
    $totalMethods,
);
