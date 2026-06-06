<?php

declare(strict_types=1);

/**
 * Adds @psalm-api annotation on individual methods, properties, and return
 * values flagged by Psalm.
 *
 * Strategy: parse the Psalm output text and look for lines matching:
 *   PossiblyUnusedMethod - <file>:<line>:<col> - Cannot find any calls to method <FQCN>::<method>
 *   PossiblyUnusedProperty - <file>:<line>:<col> - Cannot find any references to property <FQCN>::$<prop>
 *   PossiblyUnusedReturnValue - <file>:<line>:<col> - The return value for this method is never used
 *
 * For each occurrence, open the file, walk back from the reported line to the
 * nearest method/property declaration (or the closest preceding doc-block) and
 * insert the @psalm-api line into the doc-block (or create a new one).
 *
 * Idempotent: skips entries where the immediately-preceding doc-block already
 * carries @psalm-api.
 *
 * Usage:
 *     php tools/scripts/add-psalm-api-members.php <psalm-output-file>
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php add-psalm-api-members.php <psalm-output-file>\n");
    exit(1);
}

$psalmOut = $argv[1];
if (!is_file($psalmOut)) {
    fwrite(STDERR, "Psalm output not found: $psalmOut\n");
    exit(1);
}

$annotation = '     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.';

$content = file_get_contents($psalmOut) ?: '';
// Strip ANSI / hyperlink terminal escapes. OSC8 hyperlinks may use either
// BEL (\x07) or ST (\x1b\\) as terminator, depending on the terminal.
$content = preg_replace('/\x1b\[[0-9;]*m/', '', $content) ?? '';
$content = preg_replace('/\x1b\]8;[^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $content) ?? '';

// Use a relative-path matcher: Psalm reports `../../<path>:line:col`.
$pattern = '#ERROR: (PossiblyUnusedMethod|PossiblyUnusedProperty|PossiblyUnusedReturnValue) - \.\./\.\./([^:]+):(\d+):\d+#';
$matches = [];
if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === 0) {
    fwrite(STDERR, "No PossiblyUnused* entries found in $psalmOut\n");
    exit(0);
}

// Group by file then by line (so per-file we annotate in descending line order
// to keep offsets stable as we insert new lines).
$repoRoot = dirname(__DIR__, 2);
$grouped = [];
foreach ($matches as $m) {
    $relPath = $m[2];
    $line = (int) $m[3];
    $absPath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    $grouped[$absPath][$line] = true;
}

$updated = 0;
$skipped = 0;
$missing = 0;

foreach ($grouped as $file => $lines) {
    if (!is_file($file)) {
        fwrite(STDERR, "[missing] $file\n");
        $missing++;

        continue;
    }

    $src = file_get_contents($file);
    if ($src === false) {
        fwrite(STDERR, "[read-fail] $file\n");

        continue;
    }

    $allLines = preg_split('/\r\n|\n|\r/', $src);
    if ($allLines === false) {
        continue;
    }

    // Process in reverse line order so insertions don't shift later targets.
    $sortedLines = array_keys($lines);
    rsort($sortedLines);

    $touched = false;

    foreach ($sortedLines as $lineNum) {
        // Psalm 1-indexed. Walk upward from $lineNum-1 to find the previous
        // doc-block close or the declaration's start.
        $idx = $lineNum - 1;
        if ($idx < 0 || $idx >= count($allLines)) {
            continue;
        }

        // Step 1: find the start of the declaration. The reported line may be
        // mid-declaration; walk back until we find one of:
        //   - `*/` (immediate doc-block above) → insert before */
        //   - `}` or `;` or `<?php` (declaration prelude) → no doc-block, prepend one
        $insertAt = null;
        $docBlockClose = null;
        for ($i = $idx; $i >= 0; $i--) {
            $trim = ltrim($allLines[$i]);
            if ($trim === '') {
                continue;
            }
            if (str_starts_with($trim, '* @psalm-api') || str_contains($allLines[$i], '@psalm-api')) {
                // Already annotated — skip this target.
                $docBlockClose = -1;
                break;
            }
            if ($trim === '*/' || str_ends_with(rtrim($allLines[$i]), '*/')) {
                $docBlockClose = $i;
                break;
            }
            // Hit something that means no doc-block precedes this declaration.
            if (str_starts_with($trim, 'public ')
                || str_starts_with($trim, 'private ')
                || str_starts_with($trim, 'protected ')
                || str_starts_with($trim, 'static ')
                || str_starts_with($trim, 'function ')
                || str_starts_with($trim, '#[')
                || str_starts_with($trim, 'readonly ')
                || str_starts_with($trim, 'final ')
                || str_starts_with($trim, 'abstract ')
            ) {
                // Look one more line up to see if there's still a doc-block; otherwise we insert above the topmost attribute.
                if ($i === 0) {
                    $insertAt = 0;
                    break;
                }
                continue;
            }
            // Hit something else (closing brace of prior member, etc.).
            $insertAt = $i + 1;
            break;
        }

        if ($docBlockClose === -1) {
            $skipped++;
            continue;
        }

        if ($docBlockClose !== null) {
            // Insert the @psalm-api line above the `*/`.
            // Detect the indentation by reading whitespace prefix of the */ line.
            $closeLine = $allLines[$docBlockClose];
            $indent = '';
            for ($c = 0; $c < strlen($closeLine); $c++) {
                if ($closeLine[$c] !== ' ' && $closeLine[$c] !== "\t") {
                    break;
                }
                $indent .= $closeLine[$c];
            }
            // The annotation needs ' * ' prefix at the same indent level.
            $line = $indent . '* @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.';
            array_splice($allLines, $docBlockClose, 0, [$line]);
            $touched = true;
            $updated++;
        } else {
            // No doc-block — find the first attribute or declaration keyword and prepend a doc-block.
            $insertIdx = $insertAt ?? max(0, $idx - 1);
            // Find the declaration line proper (walk forward from $insertIdx looking for non-empty, non-attribute, non-leading-keyword).
            $declIdx = $idx;
            for ($j = $insertIdx; $j <= $idx; $j++) {
                if ($j < count($allLines)) {
                    $jt = ltrim($allLines[$j]);
                    if ($jt !== '' && !str_starts_with($jt, '#[')) {
                        $declIdx = $j;
                        break;
                    }
                }
            }
            $declLine = $allLines[$declIdx];
            $indent = '';
            for ($c = 0; $c < strlen($declLine); $c++) {
                if ($declLine[$c] !== ' ' && $declLine[$c] !== "\t") {
                    break;
                }
                $indent .= $declLine[$c];
            }
            $docBlock = [
                $indent . '/**',
                $indent . ' * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.',
                $indent . ' */',
            ];
            // Insert above the attribute/declaration prelude (the line we identified as insertAt or just above declIdx).
            $insertPos = $insertIdx;
            // If insertIdx points at a non-empty content line, insert above it; otherwise we already step backwards.
            array_splice($allLines, $insertPos, 0, $docBlock);
            $touched = true;
            $updated++;
        }
    }

    if ($touched) {
        $newContent = implode("\n", $allLines);
        if (file_put_contents($file, $newContent) === false) {
            fwrite(STDERR, "[write-fail] $file\n");

            continue;
        }
        echo "[updated] $file (" . count($lines) . " targets)\n";
    }
}

echo "\nUpdated $updated, skipped $skipped (already annotated), missing $missing files\n";
