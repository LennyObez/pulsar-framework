<?php

declare(strict_types=1);

/**
 * Fail the build when a Pulse template routes a translated string to the output
 * without escaping it.
 *
 * The translation helpers (`t`, `tRaw`, `trans`, `__`, `i18n`) return the raw
 * catalog string. In the template layer only two forms escape it: the `@t` /
 * `@i18n` directive, and a `{{ }}` echo. The trap is that the same seven
 * characters — `@t('k')` — mean different things depending on what surrounds
 * them: in markup the `@` is the directive marker, and inside a PHP tag or
 * another directive's argument list it is PHP's error-suppression operator, so
 * the helper is called directly and nothing escapes the result.
 *
 * `TemplateCompiler` refuses those forms at compile time, which protects every
 * template that is actually rendered. This gate is the standing repository-wide
 * version of the same check: it reads every `*.pulse.php` in the tree, including
 * the ones no test happens to render, and reports every site at once instead of
 * the first one a request stumbles into.
 *
 * The fact is produced by `TranslationOutputGuard` — the component that knows
 * which compiled form escapes. This script only walks files and reports.
 *
 * Usage:
 *   php tools/ci/assert-no-unescaped-translation.php
 *   php tools/ci/assert-no-unescaped-translation.php --root=/path/to/tree
 *
 * `--root=` exists so the gate can be pointed at a planted template that
 * violates the rule on purpose and be observed refusing it. A gate nobody has
 * ever watched fail is indistinguishable from no gate (ADR-0060).
 */

use Pulsar\View\Engine\TranslationOutputGuard;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** Directories that never hold first-party templates. */
const SKIPPED_DIRECTORIES = [
    '.git',
    'build',
    'coverage',
    'node_modules',
    'storage',
    'var',
    'vendor',
    'vendor-bin',
];

$root = dirname(__DIR__, 2);

foreach (array_slice($argv ?? [], 1) as $argument) {
    if (is_string($argument) && str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, strlen('--root=')), '/\\');
    }
}

if (!is_dir($root)) {
    fwrite(STDERR, sprintf("Root \"%s\" is not a directory.\n", $root));

    exit(2);
}

$directories = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filtered = new RecursiveCallbackFilterIterator(
    $directories,
    static fn(SplFileInfo $file): bool => !in_array($file->getFilename(), SKIPPED_DIRECTORIES, true),
);

$guard = new TranslationOutputGuard();
$scanned = 0;
$offences = [];

/** @var SplFileInfo $file */
foreach (new RecursiveIteratorIterator($filtered) as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.pulse.php')) {
        continue;
    }

    $source = file_get_contents($file->getPathname());

    if ($source === false) {
        fwrite(STDERR, sprintf("Could not read \"%s\".\n", $file->getPathname()));

        exit(2);
    }

    $scanned++;
    $violations = $guard->violations($source);

    if ($violations === []) {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    foreach ($violations as $violation) {
        $offences[] = sprintf('%s:%d  %s — %s', $relative, $violation->line, $violation->construct, $violation->reason);
    }
}

if ($offences !== []) {
    sort($offences);

    fwrite(STDERR, sprintf(
        "Unescaped translation output in %d site(s) across %d scanned template(s):\n  - %s\n\n"
        . "Write translations one of these ways, and no other:\n"
        . "  in markup            @t('key')                       escaped\n"
        . "  in an expression     {{ t('key') }}                  escaped\n"
        . "  deliberate markup    @tRaw('key')                    raw, on purpose, greppable\n"
        . "Never write a translation helper inside <?php … ?> or <?= … ?>, never write @t\n"
        . "inside {{ }}, {!! !!} or another directive's argument list, and never echo a\n"
        . "translation through {!! !!}. For a page title, use the @section block form:\n"
        . "  @section('title')@t('page.title')@endsection\n",
        count($offences),
        $scanned,
        implode("\n  - ", $offences),
    ));

    exit(1);
}

// A scan that reached nothing is not a clean scan, and the two are one exit code
// apart. Every way this gate can quietly stop working ends here: a root that is not
// the project, a directory list that grew a skip it should not have, a rename of the
// `.pulse.php` suffix. Each of those leaves $scanned at zero and every other line of
// this script agreeing there is nothing to report. Nine gates in this repository were
// found passing for reasons of exactly that shape, so the count is asserted and not
// merely printed.
if ($scanned === 0) {
    fwrite(STDERR, sprintf(
        "No Pulse template was found under \"%s\", so this gate checked nothing.\n\n"
        . "Exiting 0 would report \"the templates are clean\" and \"the scan never reached a\n"
        . "template\" as the same answer, and only one of them is health. Point --root at a\n"
        . "tree that holds `*.pulse.php` files, or find out why this one no longer does.\n",
        $root,
    ));

    exit(2);
}

printf("OK: %d Pulse template(s) scanned, no unescaped translation output.\n", $scanned);

exit(0);
