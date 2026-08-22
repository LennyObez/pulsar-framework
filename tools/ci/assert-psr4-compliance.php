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

$command = 'composer dump-autoload --optimize --strict-psr --no-interaction 2>&1';
$output = shell_exec($command);

if (!is_string($output)) {
    fwrite(STDERR, "Could not run: {$command}\n");

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

printf("OK: every class complies with its PSR-4 rule (%d documented exception(s) skipped).\n", $allowed);

exit(0);
