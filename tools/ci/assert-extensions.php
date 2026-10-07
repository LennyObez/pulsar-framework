<?php

declare(strict_types=1);

/**
 * Fails the build when an extension a CI job promises to exercise is not
 * actually loaded.
 *
 * Optional-extension code in this repository is guarded by function_exists()
 * or extension_loaded(), and the tests behind those guards self-skip. That
 * combination is silently dishonest: if the extension fails to install, the
 * guarded branch never runs, every test still passes, and the job reports
 * green while the code path has never executed once. A stubbed symbol with no
 * real counterpart in the matrix is an untested promise, not coverage — and an
 * untested branch is where defects live undisturbed.
 *
 * This script turns that silence into a hard failure: the job must either
 * exercise what it claims, or stop claiming it.
 *
 * Usage:
 *   php tools/ci/assert-extensions.php redis memcached apcu igbinary zstd brotli
 */

// $argv only exists with register_argc_argv, always on under CLI — but reading it
// unguarded would make this gate assert "no extensions expected" and pass silently.
/** @var list<string> $arguments */
$arguments = array_values(array_filter($argv ?? [], 'is_string'));
$expected = array_slice($arguments, 1);

if ($expected === []) {
    fwrite(STDERR, "usage: php tools/ci/assert-extensions.php <ext> [<ext> ...]\n");

    exit(2);
}

$missing = [];

foreach ($expected as $extension) {
    $loaded = extension_loaded($extension);

    if (!$loaded) {
        $missing[] = $extension;
    }

    printf("%s  %s\n", $loaded ? 'ok  ' : 'MISS', $extension);
}

if ($missing !== []) {
    fwrite(STDERR, sprintf(
        "\nFAIL: %d expected extension(s) not loaded: %s\n\n"
        . "This job claims to exercise the code paths behind these extensions, but\n"
        . "they are absent, so those paths will silently self-skip and the job would\n"
        . "pass without ever running them. Either fix the installation, or drop the\n"
        . "claim (and the code, and its stub) — do not leave the promise standing.\n",
        count($missing),
        implode(', ', $missing),
    ));

    exit(1);
}

printf("\nAll %d expected extension(s) loaded.\n", count($expected));
