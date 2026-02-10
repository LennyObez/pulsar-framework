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

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$rootDir = dirname(__DIR__);
$baselinePath = 'tools/php/boundary-baseline.json';
$absoluteBaselinePath = $rootDir . '/' . $baselinePath;

// Parse CLI arguments
$jsonOutput = in_array('--json', $argv, true);
$baseRef = 'HEAD~1';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $baseRef = substr($arg, strlen('--base='));
    }
}

// Load current baseline
$currentViolations = [];
if (file_exists($absoluteBaselinePath)) {
    $data = json_decode(
        (string) file_get_contents($absoluteBaselinePath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $currentViolations = $data['violations'] ?? [];
}

// Load previous baseline from git
$previousViolations = [];
$command = sprintf(
    'git -C %s show %s:%s 2>/dev/null',
    escapeshellarg($rootDir),
    escapeshellarg($baseRef),
    escapeshellarg($baselinePath),
);
$output = [];
exec($command, $output, $exitCode);

if ($exitCode === 0) {
    $previousJson = implode("\n", $output);
    $previousData = json_decode($previousJson, true, 512, JSON_THROW_ON_ERROR);
    $previousViolations = $previousData['violations'] ?? [];
}

// Build keyed sets for comparison
$currentKeys = [];
foreach ($currentViolations as $v) {
    $key = $v['file'] . '|' . $v['import'];
    $currentKeys[$key] = $v;
}

$previousKeys = [];
foreach ($previousViolations as $v) {
    $key = $v['file'] . '|' . $v['import'];
    $previousKeys[$key] = $v;
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
