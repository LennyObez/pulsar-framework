<?php

declare(strict_types=1);

/**
 * Adds @psalm-api annotation to each PHP file listed in the input file.
 *
 * Strategy: find the `class X` / `final class X` / `abstract class X` /
 * `interface X` / `trait X` / `enum X` declaration line. Look upward for the
 * nearest closing `*\/` of a doc-block. Insert the @psalm-api line before that
 * closing token. If no doc-block precedes the declaration (only attributes or
 * blank lines), prepend a minimal doc-block above the attribute / declaration.
 *
 * Idempotent: skips files that already contain `@psalm-api`.
 *
 * Usage:
 *     php tools/scripts/add-psalm-api.php <list-file>
 *
 * The list file is a newline-separated list of PHP file paths.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php add-psalm-api.php <list-file>\n");
    exit(1);
}

$listFile = $argv[1];
if (!is_file($listFile)) {
    fwrite(STDERR, "List file not found: $listFile\n");
    exit(1);
}

$annotation = ' * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.';

$paths = array_filter(array_map('trim', file($listFile, FILE_IGNORE_NEW_LINES) ?: []));
$updated = 0;
$skipped = 0;
$missing = 0;

foreach ($paths as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[missing] $path\n");
        $missing++;

        continue;
    }

    $contents = file_get_contents($path);
    if ($contents === false || $contents === '') {
        fwrite(STDERR, "[empty]   $path\n");

        continue;
    }

    if (str_contains($contents, '@psalm-api')) {
        $skipped++;

        continue;
    }

    // Locate the class declaration line.
    $declRe = '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+\w+/m';
    if (preg_match($declRe, $contents, $declMatch, PREG_OFFSET_CAPTURE) !== 1) {
        fwrite(STDERR, "[no-decl] $path\n");

        continue;
    }

    $declOffset = (int) $declMatch[0][1];

    // Walk back to the start of the prelude (consecutive #[Attr] lines + optional docblock).
    $before = substr($contents, 0, $declOffset);

    // Strip trailing attribute / blank lines off `$before` to find the doc-block.
    $tail = preg_split('/\R/', rtrim($before, "\r\n")) ?: [];
    $attrLines = 0;
    for ($i = count($tail) - 1; $i >= 0; $i--) {
        $trim = ltrim($tail[$i]);
        if ($trim === '' || str_starts_with($trim, '#[')) {
            $attrLines++;

            continue;
        }
        break;
    }

    // Now look at the line just before the attribute prelude — it should be a `*/`.
    $cutLine = count($tail) - 1 - $attrLines;
    $hasDocBlock = $cutLine >= 0 && str_ends_with(rtrim($tail[$cutLine]), '*/');

    if ($hasDocBlock) {
        // Insert @psalm-api line BEFORE the `*/` line.
        $tailLines = $tail;
        array_splice($tailLines, $cutLine, 0, [$annotation]);
        // If the line immediately above the original */ does NOT start with " * @",
        // also prepend a blank-doc-line separator " *" for visual grouping.
        $aboveIdx = $cutLine - 1;
        if ($aboveIdx >= 0) {
            $aboveLine = ltrim($tailLines[$aboveIdx]);
            if ($aboveLine !== '' && !str_starts_with($aboveLine, '* @') && str_starts_with($aboveLine, '*')) {
                array_splice($tailLines, $cutLine, 0, [' *']);
            }
        }
        $newBefore = implode("\n", $tailLines) . "\n";
    } else {
        // No doc-block — prepend a new minimal one above the attribute / declaration prelude.
        $newDoc = "/**\n$annotation\n */\n";
        $newBefore = rtrim($before, "\r\n") . "\n" . $newDoc;
    }

    $newContents = $newBefore . substr($contents, $declOffset);

    if (file_put_contents($path, $newContents) === false) {
        fwrite(STDERR, "[write-fail] $path\n");

        continue;
    }

    $updated++;
    echo "[updated] $path\n";
}

echo "\nUpdated $updated, skipped $skipped (already annotated), missing $missing\n";
