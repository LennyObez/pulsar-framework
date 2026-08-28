<?php

declare(strict_types=1);

/**
 * Fails the build when the gate a contributor runs and the gate CI enforces are
 * not the same gate.
 *
 * .github/CONTRIBUTING.md tells contributors to run the same checks as CI. That
 * sentence was false in both directions: `composer qa` was executed by no
 * workflow -- its only mention anywhere in the repository was that line of
 * CONTRIBUTING -- while ci.yml re-implemented a narrower list of its own and
 * left out `security:lint` entirely. Nobody had lied; the two lists simply drifted,
 * because nothing coupled them and drift is what uncoupled lists do.
 *
 * THE CHOICE, since there were three ways to fix it and only one survives contact
 * with how this repository actually runs.
 *
 *   (a) CI calls `composer qa`. One list, trivially in sync -- and it collapses
 *       nine parallel jobs into one serial one. The test suite alone is 30
 *       minutes and coverage is 60; php-quality currently finishes in a fraction
 *       of that and gates the rest. Every contributor would wait for the sum of
 *       what they now wait for the maximum of. Rejected: the parallelism is not
 *       an implementation detail of CI, it is most of why CI is usable.
 *
 *   (b) `qa` is redefined as whatever CI happens to run. That inverts the
 *       dependency without removing it -- the two lists still drift, just in the
 *       other direction, and now the local gate is defined by a YAML file that
 *       nobody edits with the local gate in mind.
 *
 *   (c) One declared list, two consumers, and a check that they agree. composer.json's
 *       `qa` script stays the single definition of the gate. CI keeps its jobs and
 *       its parallelism. THIS script asserts that every leaf of `qa` is executed
 *       somewhere in ci.yml, and that ci.yml claims no gate `qa` does not have.
 *
 * (c) is what runs. Divergence stops being a thing reviewers are asked to notice
 * and becomes a thing the build refuses.
 *
 * HOW A CI STEP DECLARES WHICH GATE IT IS
 *
 * Most steps need nothing: a step whose command is literally `composer phpstan`
 * is matched by reading it. Some steps run the same gate a different way, for
 * reasons that belong to CI and not to the gate -- the boundary check takes a
 * `--diff-base` on pull requests, and the test suite runs under paratest with a
 * yield assertion rather than under `composer test`. Those steps carry an
 * annotation comment naming the gate they satisfy:
 *
 *     # qa-gate: test
 *     - name: Run all tests in parallel (isolation guard, no coverage)
 *
 * The annotation lives in the workflow rather than in a mapping file off to the
 * side, so the claim sits where the reviewer is already looking.
 *
 * Exit codes:
 *   0  the two agree
 *   1  they do not
 *   2  one of the two files could not be read or parsed -- never a pass
 *
 * Usage:
 *   php tools/ci/assert-qa-ci-parity.php
 *   php tools/ci/assert-qa-ci-parity.php --composer=a.json --workflow=b.yml
 *
 * The two path options exist so the check's own judgement can be tested against
 * fixtures that disagree on purpose. A gate is worth its own correctness, and this
 * one cannot be exercised against the real pair without breaking the repository.
 */

const QA_PARITY_COMPOSER = 'composer.json';
const QA_PARITY_WORKFLOW = '.github/workflows/ci.yml';

/** The composer script whose leaves define the gate. */
const QA_PARITY_ROOT_SCRIPT = 'qa';

/**
 * Expands a composer script into the leaf scripts it ultimately runs.
 *
 * `qa` lists `@boundary:check`, which is itself a list of `@boundary:deptrac` and
 * `@boundary:custom`. CI runs the two halves as separate steps, so comparing at
 * the top level would report a match that is not one.
 *
 * @param array<string, mixed> $scripts
 * @param list<string>         $seen    Guards against a script that references itself
 *
 * @return list<string>
 */
function qa_parity_leaves(array $scripts, string $name, array $seen = []): array
{
    if (in_array($name, $seen, true)) {
        return [];
    }

    $definition = $scripts[$name] ?? null;

    if (is_string($definition)) {
        return [$name];
    }

    if (!is_array($definition)) {
        return [$name];
    }

    $seen[] = $name;
    $leaves = [];

    foreach ($definition as $entry) {
        if (!is_string($entry)) {
            continue;
        }

        if (!str_starts_with($entry, '@')) {
            // A raw shell command inside the list, not a reference to another script.
            // It cannot be matched by name, so it is reported rather than guessed at.
            $leaves[] = $entry;

            continue;
        }

        $leaves = [...$leaves, ...qa_parity_leaves($scripts, substr($entry, 1), $seen)];
    }

    return array_values(array_unique($leaves));
}

/**
 * The workflow with every whole-line comment removed.
 *
 * Necessary, and found the hard way: the first version of this script scanned the
 * raw file and reported full parity, because a comment in ci.yml explaining that a
 * step was "annotated rather than run as `composer boundary:custom`" was itself
 * read as running it. A gate that accepts prose about a command as proof the
 * command runs is the failure mode this whole change exists to remove, one level
 * down. Annotations are extracted before this runs, so they survive.
 */
function qa_parity_strip_comments(string $workflow): string
{
    $lines = preg_split('/\R/', $workflow);

    if ($lines === false) {
        return $workflow;
    }

    $kept = array_filter($lines, static fn(string $line): bool => preg_match('/^\s*#/', $line) !== 1);

    return implode("\n", $kept);
}

/**
 * Every composer script the workflow invokes directly, by reading its `run:` text.
 *
 * Deliberately a text scan and not a YAML parse: `run:` blocks are shell, a step
 * can invoke several scripts in one block, and the thing being asserted is what
 * the shell will actually execute.
 *
 * @return list<string>
 */
function qa_parity_invoked(string $workflow): array
{
    preg_match_all(
        '/\bcomposer\s+([a-z0-9](?:[a-z0-9:_-]*[a-z0-9])?)/i',
        qa_parity_strip_comments($workflow),
        $matches,
    );

    $names = $matches[1];

    // `composer install`, `composer audit` and friends are composer's own commands,
    // not scripts in this repository; they are harmless here because the comparison
    // only ever asks whether a NAMED QA LEAF appears in this set.
    return array_values(array_unique($names));
}

/**
 * The gates CI claims through `# qa-gate:` annotations.
 *
 * @return list<string>
 */
function qa_parity_annotated(string $workflow): array
{
    preg_match_all('/^\s*#\s*qa-gate:\s*(\S+)\s*$/m', $workflow, $matches);

    return array_values(array_unique($matches[1]));
}

$root = dirname(__DIR__, 2);
$composerPath = $root . '/' . QA_PARITY_COMPOSER;
$workflowPath = $root . '/' . QA_PARITY_WORKFLOW;

/** @var list<string> $arguments */
$arguments = array_values(array_filter($argv ?? [], 'is_string'));
array_shift($arguments);

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--composer=')) {
        $composerPath = substr($argument, strlen('--composer='));

        continue;
    }

    if (str_starts_with($argument, '--workflow=')) {
        $workflowPath = substr($argument, strlen('--workflow='));

        continue;
    }

    fwrite(STDERR, sprintf(
        "Unrecognised argument: %s\n"
        . "Usage: php tools/ci/assert-qa-ci-parity.php [--composer=PATH] [--workflow=PATH]\n",
        $argument,
    ));

    exit(2);
}

$composerRaw = @file_get_contents($composerPath);
$workflow = @file_get_contents($workflowPath);

if (!is_string($composerRaw) || !is_string($workflow)) {
    fwrite(STDERR, sprintf(
        "qa-ci-parity: could not read %s and %s.\n",
        $composerPath,
        $workflowPath,
    ));

    exit(2);
}

try {
    /** @var array<string, mixed> $composer */
    $composer = json_decode($composerRaw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'qa-ci-parity: composer.json is not valid JSON: ' . $exception->getMessage() . "\n");

    exit(2);
}

$scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

if (!array_key_exists(QA_PARITY_ROOT_SCRIPT, $scripts)) {
    fwrite(STDERR, sprintf(
        "qa-ci-parity: composer.json declares no `%s` script, so there is no gate to compare against.\n",
        QA_PARITY_ROOT_SCRIPT,
    ));

    exit(2);
}

/** @var array<string, mixed> $scripts */
$leaves = qa_parity_leaves($scripts, QA_PARITY_ROOT_SCRIPT);

// This script is itself a leaf of `qa`. Excluding it is not an exemption: the
// assertion "CI runs the parity check" is what the parity check would be making
// about itself, and a check that vouches for its own presence proves nothing. CI
// running it is asserted by the step existing, which a reviewer can see.
$leaves = array_values(array_filter(
    $leaves,
    static fn(string $leaf): bool => $leaf !== 'qa:parity',
));

$invoked = qa_parity_invoked($workflow);
$annotated = qa_parity_annotated($workflow);
$covered = [...$invoked, ...$annotated];

$missing = array_values(array_filter(
    $leaves,
    static fn(string $leaf): bool => !in_array($leaf, $covered, true),
));

$unknownAnnotations = array_values(array_filter(
    $annotated,
    static fn(string $claim): bool => !in_array($claim, $leaves, true),
));

$failed = false;

if ($missing !== []) {
    $failed = true;
    fwrite(STDERR, sprintf(
        "qa-ci-parity: %d gate(s) in `composer %s` that %s does not run.\n\n  - %s\n\n"
        . "A gate a contributor runs and CI does not is a gate that stops nothing at the only\n"
        . "moment it matters. Add a step that runs it, or -- if CI runs it by another command --\n"
        . "put `# qa-gate: <name>` on the line above that step's `- name:`.\n\n",
        count($missing),
        QA_PARITY_ROOT_SCRIPT,
        QA_PARITY_WORKFLOW,
        implode("\n  - ", $missing),
    ));
}

if ($unknownAnnotations !== []) {
    $failed = true;
    fwrite(STDERR, sprintf(
        "qa-ci-parity: %d `# qa-gate:` annotation(s) in %s naming something `composer %s` does not\n"
        . "contain.\n\n  - %s\n\n"
        . "Either the gate was removed from composer.json and the annotation outlived it, or the\n"
        . "name is misspelled. A stale annotation is worse than none: it makes the workflow claim\n"
        . "coverage it does not have.\n\n",
        count($unknownAnnotations),
        QA_PARITY_WORKFLOW,
        QA_PARITY_ROOT_SCRIPT,
        implode("\n  - ", $unknownAnnotations),
    ));
}

if ($failed) {
    exit(1);
}

printf(
    "qa-ci-parity: OK. All %d gate(s) in `composer %s` are executed by %s (%d by name, %d by annotation).\n",
    count($leaves),
    QA_PARITY_ROOT_SCRIPT,
    QA_PARITY_WORKFLOW,
    count(array_intersect($leaves, $invoked)),
    count(array_intersect($leaves, $annotated)),
);

exit(0);
