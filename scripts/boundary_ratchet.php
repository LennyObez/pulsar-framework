#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Boundary Baseline Ratchet — ensures boundary violations only decrease over time.
 *
 * Compares the current boundary baseline against the previous commit's baseline.
 * Fails if the baseline grew (new violations were added). Passes if the baseline
 * stayed the same or shrank.
 *
 * Usage:
 *   php scripts/boundary_ratchet.php                     # Compare against HEAD~1
 *   php scripts/boundary_ratchet.php --base=origin/main  # Compare against a specific ref
 *   php scripts/boundary_ratchet.php --json               # JSON output
 */

use Pulsar\Tooling\Support\JsonDocument;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$rootDir = dirname(__DIR__);
$baselinePath = 'tools/php/boundary-baseline.json';
$absoluteBaselinePath = $rootDir . '/' . $baselinePath;

// $argv only exists when register_argc_argv is on — always true under the CLI
// SAPI, but reading it unguarded would silently drop every flag if it were not.
/** @var list<string> $arguments */
$arguments = array_values(array_filter($argv ?? [], 'is_string'));

// Parse CLI arguments
$jsonOutput = in_array('--json', $arguments, true);
$baseRef = 'HEAD~1';

foreach ($arguments as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $baseRef = substr($arg, strlen('--base='));
    }
}

/**
 * Index baseline entries by "file|import".
 *
 * Every field is required: an entry that lost its `file` would key on
 * "|SomeImport" and make this ratchet treat an unrelated violation as already
 * accepted, which is the one failure mode a ratchet must not have.
 *
 * @return array<string, array{file: string, import: string, rule: string}>
 */
$indexViolations = static function (JsonDocument $document): array {
    $indexed = [];

    foreach ($document->children('violations') as $violation) {
        $entry = [
            'file' => $violation->string('file'),
            'import' => $violation->string('import'),
            'rule' => $violation->stringOr('rule', 'unspecified'),
        ];

        $indexed[$entry['file'] . '|' . $entry['import']] = $entry;
    }

    return $indexed;
};

// Load current baseline
$currentKeys = file_exists($absoluteBaselinePath)
    ? $indexViolations(JsonDocument::fromFile($absoluteBaselinePath))
    : [];

// Load previous baseline from git.
//
// The argument-array form of proc_open never spawns a shell, so $baseRef — a
// CLI-supplied value — cannot be interpreted as a command, and no escaping is
// needed. This replaces an exec() whose `2>/dev/null` redirection is Unix-only:
// on Windows the whole command failed, the previous baseline read as empty, and
// the ratchet reported every accepted violation as newly added. A gate that
// answers wrongly off-CI is worse than one that is only run on CI.
// nosemgrep: php.lang.security.exec-use.exec-use
$previousKeys = [];
$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$process = proc_open(
    ['git', '-C', $rootDir, 'show', "{$baseRef}:{$baselinePath}"],
    $descriptors,
    $pipes,
);

if (is_resource($process)) {
    $previousJson = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode === 0 && is_string($previousJson) && trim($previousJson) !== '') {
        $previousKeys = $indexViolations(
            JsonDocument::fromString($previousJson, "{$baseRef}:{$baselinePath}"),
        );
    } elseif (!$jsonOutput) {
        fwrite(STDERR, "Note: no baseline at {$baseRef}:{$baselinePath}; treating it as empty.\n");
    }
}

// Find new violations (in current but not in previous)
$added = array_diff_key($currentKeys, $previousKeys);

// Find removed violations (in previous but not in current)
$removed = array_diff_key($previousKeys, $currentKeys);

$currentCount = count($currentKeys);
$previousCount = count($previousKeys);
$addedCount = count($added);
$removedCount = count($removed);

$passed = $addedCount === 0;

if ($jsonOutput) {
    echo json_encode([
        'passed' => $passed,
        'previous_count' => $previousCount,
        'current_count' => $currentCount,
        'added' => $addedCount,
        'removed' => $removedCount,
        'new_violations' => array_values($added),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($passed ? 0 : 1);
}

echo "Boundary Ratchet Check\n";
echo "======================\n";
echo "  Base ref:   {$baseRef}\n";
echo "  Previous:   {$previousCount} violation(s)\n";
echo "  Current:    {$currentCount} violation(s)\n";
echo "  Added:      {$addedCount}\n";
echo "  Removed:    {$removedCount}\n";
echo "\n";

if ($addedCount > 0) {
    echo "FAILED — New violations were added:\n\n";

    foreach ($added as $v) {
        echo "  + {$v['file']} | {$v['import']}\n";
        echo "    Rule: {$v['rule']}\n\n";
    }

    echo "The baseline must not grow. Fix the violations or update the baseline with justification.\n";
    exit(1);
}

if ($removedCount > 0) {
    echo "PASSED — Baseline shrank by {$removedCount} violation(s).\n";
} else {
    echo "PASSED — Baseline unchanged.\n";
}

exit(0);
