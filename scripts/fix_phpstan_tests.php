<?php

/**
 * Script to fix PHPStan errors in test files.
 * Processes each file and error, applying targeted fixes.
 *
 * This is a one-shot tool, delete after use.
 */

declare(strict_types=1);

$errorFile = __DIR__ . '/phpstan-errors.txt';
$errors = file($errorFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($errors === false) {
    echo "Cannot read error file\n";
    exit(1);
}

$fileErrors = [];
foreach ($errors as $line) {
    // Parse: C:\path\file.php:123:Error message
    if (preg_match('/^(.+\.php):(\d+):(.+)$/', $line, $m)) {
        $file = $m[1];
        $lineNum = (int) $m[2];
        $message = $m[3];
        $fileErrors[$file][] = ['line' => $lineNum, 'message' => $message];
    }
}

echo "Found errors in " . count($fileErrors) . " files\n";

$totalFixed = 0;

foreach ($fileErrors as $file => $errors) {
    if (!file_exists($file)) {
        echo "SKIP: $file does not exist\n";
        continue;
    }

    $content = file_get_contents($file);
    if ($content === false) continue;
    $lines = explode("\n", $content);
    $modified = false;

    // Process errors in reverse line order to not shift line numbers
    usort($errors, fn($a, $b) => $b['line'] <=> $a['line']);

    foreach ($errors as $error) {
        $lineIdx = $error['line'] - 1;
        $msg = $error['message'];

        if ($lineIdx < 0 || $lineIdx >= count($lines)) continue;

        $currentLine = $lines[$lineIdx];

        // Fix: "Cannot access offset 'X' on mixed" or "Cannot access offset 0 on mixed"
        // Pattern: $var['key'][0]['nested'] where intermediate is mixed
        // Fix: add assertIsArray() before the access
        if (str_contains($msg, 'Cannot access offset') && str_contains($msg, 'on mixed')) {
            // Find the variable being accessed on this line
            // e.g., self::assertSame('Patient', $resources[0]['type']);
            // We need to add assertIsArray($resources) before this line
            // But only if assertIsArray is not already there for this var

            // This is complex to do generically. Skip for now - manual fixes needed.
            continue;
        }

        // Fix: "assertCount() expects Countable|iterable, mixed given"
        if (str_contains($msg, 'assertCount() expects Countable|iterable, mixed given')) {
            // Find the variable passed as 2nd arg
            if (preg_match('/assertCount\(\s*\d+\s*,\s*(\$[\w\[\]\'\"->]+)/s', $currentLine, $m)) {
                $var = $m[1];
                $indent = str_repeat(' ', strlen($currentLine) - strlen(ltrim($currentLine)));
                $assertLine = $indent . "self::assertIsArray($var);";
                // Insert before this line
                array_splice($lines, $lineIdx, 0, [$assertLine]);
                $modified = true;
                $totalFixed++;
            }
            continue;
        }

        // Fix: "assertStringNotContainsString() expects string, string|null given"
        if (str_contains($msg, 'assertStringNotContainsString() expects string, string|null given')
            || str_contains($msg, 'assertStringContainsString() expects string, string|null given')) {
            // The 2nd param is string|null — wrap with (string) cast or add assertIsString
            if (preg_match('/(assertString(?:Not)?ContainsString\(.+?,\s*)(\$[\w\[\]\'\"->]+)/', $currentLine, $m)) {
                $var = $m[2];
                $indent = str_repeat(' ', strlen($currentLine) - strlen(ltrim($currentLine)));
                $assertLine = $indent . "self::assertIsString($var);";
                array_splice($lines, $lineIdx, 0, [$assertLine]);
                $modified = true;
                $totalFixed++;
            }
            continue;
        }

        // Fix: "assertStringContainsString() expects string, mixed given"
        if (str_contains($msg, 'assertStringContainsString() expects string, mixed given')) {
            if (preg_match('/(assertStringContainsString\(.+?,\s*)(\$[\w\[\]\'\"->]+)/', $currentLine, $m)) {
                $var = $m[2];
                $indent = str_repeat(' ', strlen($currentLine) - strlen(ltrim($currentLine)));
                $assertLine = $indent . "self::assertIsString($var);";
                array_splice($lines, $lineIdx, 0, [$assertLine]);
                $modified = true;
                $totalFixed++;
            }
            continue;
        }
    }

    if ($modified) {
        file_put_contents($file, implode("\n", $lines));
        echo "FIXED: $file ($totalFixed fixes so far)\n";
    }
}

echo "\nTotal fixes applied: $totalFixed\n";
echo "Remaining errors need manual attention.\n";
