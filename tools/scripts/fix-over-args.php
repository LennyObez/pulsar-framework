<?php

declare(strict_types=1);

/**
 * Drops the first argument from method invocations flagged by PHPStan as
 * "invoked with N parameters, M required" when N == M + 1. This catches the
 * common test regression where controllers had their leading $request param
 * removed but call sites still pass `$this->createRequest()` / similar.
 *
 * Usage: php tools/scripts/fix-over-args.php <phpstan-raw-output>
 *
 * Only edits when:
 *   - The mismatch is exactly +1 (single arg to drop)
 *   - The first arg matches a request-shaped expression
 *
 * Leaves everything else alone so genuine multi-arg mismatches stay visible.
 */

$out = $argv[1] ?? '';
if (!is_file($out)) {
    fwrite(STDERR, "Need phpstan-raw output as arg\n");
    exit(1);
}

$content = file_get_contents($out) ?: '';
// Anchorless pattern — line boundaries fail under CRLF in multi-line mode on
// Windows. The `\.php` + `Method` literal anchor each match unambiguously.
$pattern = '#(?<file>(?:[A-Za-z]:)?[^\r\n:]+(?::[^\r\n:]+)*\.php):(?<line>\d+):Method ([^ ]+)::(?<method>\w+)\(\) invoked with (?<actual>\d+) parameters?, (?<expected>\d+) required\.#';

preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

$grouped = [];
foreach ($matches as $m) {
    $delta = (int) $m['actual'] - (int) $m['expected'];
    if ($delta !== 1) {
        continue;
    }
    $grouped[$m['file']][(int) $m['line']] = $m['method'];
}

$updated = 0;
$skipped = 0;

foreach ($grouped as $file => $sites) {
    if (!is_file($file)) {
        continue;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?? [];
    if (!$lines) {
        continue;
    }
    $touched = false;

    foreach ($sites as $lineNum => $method) {
        $idx = $lineNum - 1;
        if (!isset($lines[$idx])) {
            $skipped++;
            continue;
        }
        $line = $lines[$idx];

        // Match `->$method(<arg>, ...)` or `->$method(<arg>)` and drop the
        // first arg if it looks like a request/server-request expression.
        $re = '/(->' . preg_quote($method, '/') . '\()((?:\$this->createRequest\(\)|\$request|\$mockRequest|\$req|\$serverRequest))(\s*\)|(\s*,\s*))/';
        $new = preg_replace_callback($re, static function (array $m): string {
            // Drop the first arg
            if (isset($m[3]) && trim($m[3]) === ')') {
                return $m[1] . ')';
            }
            // Drop the first arg + the following comma+space
            return $m[1];
        }, $line, 1, $count);

        if ($count > 0 && $new !== $line) {
            $lines[$idx] = $new;
            $touched = true;
            $updated++;
            echo "[fix] $file:$lineNum  $method()\n";
        } else {
            $skipped++;
            echo "[skip] $file:$lineNum  $method() — pattern did not match cleanly\n";
        }
    }

    if ($touched) {
        file_put_contents($file, implode("\n", $lines) . "\n");
    }
}

echo "\nUpdated $updated, skipped $skipped\n";
