<?php

declare(strict_types=1);

/**
 * Fail the build when any class violates its PSR-4 autoload rule.
 *
 * `composer dump-autoload --optimize` happily records a class whose namespace or
 * filename does not match its PSR-4 rule: the optimized classmap makes it work
 * anyway, so the violation stays invisible until something tries to autoload the
 * class by name. `--strict-psr` reports those classes — but it only WARNS, and
 * composer still exits 0, so on its own it gates nothing.
 *
 * This wraps it into an actual gate. Without one, a class can only be reached by
 * whoever happens to have included its file already, which is exactly the
 * fragility that made 66 tests fail the moment they ran in parallel workers.
 *
 * Deliberate exceptions are listed below: fixtures whose whole purpose is to sit
 * in a foreign namespace (the extension-autoloader tests and the boundary-leak
 * fixture).
 */

/**
 * Classes allowed to violate their PSR-4 rule, with the reason.
 *
 * @var array<string, string>
 */
const ALLOWED_VIOLATIONS = [
    // Fixtures for the extension autoloader: their namespace intentionally does
    // not derive from the tests/ PSR-4 root, because the test asserts that the
    // extension autoloader can map an arbitrary namespace to an arbitrary path.
    'PulsarAutoloadFixture\\Widget' => 'extension-autoloader fixture: foreign namespace is the point',
    'PulsarAutoloadFixture\\Sub\\Leaf' => 'extension-autoloader fixture: foreign namespace is the point',
    'PulsarAutoloadFixture\\Deep\\Leaf' => 'extension-autoloader fixture: foreign namespace is the point',
    // Simulates an extension leaking a class into a namespace it does not own,
    // so the boundary checker has something real to catch.
    'Pulsar\\Extension\\Payments\\Contracts\\FakeAdapterLeak' => 'boundary-leak fixture: squats a foreign namespace on purpose',
];

/**
 * Composer project this runs against; defaults to the repository.
 *
 * `--root=` exists so the gate can be pointed at a planted project that violates
 * PSR-4 on purpose and observed refusing it. Without it the only way to see this
 * script fail would be to break the repository, and a gate nobody has ever watched
 * fail is indistinguishable from no gate.
 */
$root = dirname(__DIR__, 2);

foreach (array_slice($argv ?? [], 1) as $argument) {
    if (is_string($argument) && str_starts_with($argument, '--root=')) {
        $root = substr($argument, strlen('--root='));

        continue;
    }

    fwrite(STDERR, sprintf("Unknown option: %s\n", is_string($argument) ? $argument : '<non-string>'));

    exit(2);
}

if (!is_dir($root)) {
    fwrite(STDERR, sprintf("Not a directory: %s\n", $root));

    exit(2);
}

$command = sprintf(
    'composer dump-autoload --optimize --strict-psr --no-interaction --working-dir=%s 2>&1',
    escapeshellarg($root),
);
$output = shell_exec($command);

if (!is_string($output)) {
    fwrite(STDERR, "Could not run: {$command}\n");

    exit(1);
}

// A run that produced no classmap checked nothing, and "no violations found" is
// the same sentence whether the tree is clean or whether composer never got as far
// as reading it. `--strict-psr` only warns, so composer's own exit code cannot tell
// the two apart either: this is the only place the difference can be noticed.
if (preg_match('/Generated (?:optimized )?autoload files containing (\d+) classes/', $output, $generated) !== 1) {
    fwrite(STDERR, sprintf(
        "composer produced no classmap, so nothing was checked for PSR-4 compliance.\n"
        . "This is not a clean tree; it is an unmeasured one. Composer said:\n\n%s\n",
        rtrim($output),
    ));

    exit(1);
}

if ((int) $generated[1] === 0) {
    fwrite(STDERR, sprintf(
        "composer generated a classmap of 0 classes in %s, so every class in it complied with\n"
        . "its PSR-4 rule for the trivial reason that there were none. Check the autoload\n"
        . "configuration before reading this as a pass.\n",
        $root,
    ));

    exit(1);
}

preg_match_all('/Class (\S+) located in (\S+) does not comply with psr-4/', $output, $matches, PREG_SET_ORDER);

$violations = [];

foreach ($matches as $match) {
    $class = $match[1];

    if (array_key_exists($class, ALLOWED_VIOLATIONS)) {
        continue;
    }

    $violations[] = sprintf('%s (%s)', $class, $match[2]);
}

$allowed = count($matches) - count($violations);

if ($violations !== []) {
    fwrite(STDERR, sprintf(
        "PSR-4 violations (%d):\n  - %s\n\n"
        . "These classes cannot be autoloaded by name; they only resolve if their file\n"
        . "happens to have been included already. Move each class to a file whose path and\n"
        . "name match its namespace, or add an explicit entry to ALLOWED_VIOLATIONS in\n"
        . "tools/ci/assert-psr4-compliance.php with the reason.\n",
        count($violations),
        implode("\n  - ", $violations),
    ));

    exit(1);
}

printf(
    "OK: every one of %d class(es) complies with its PSR-4 rule (%d documented exception(s) skipped).\n",
    (int) $generated[1],
    $allowed,
);

exit(0);
