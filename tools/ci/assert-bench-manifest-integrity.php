<?php

declare(strict_types=1);

/**
 * Fails the build when the benchmark pipeline manifest has changed without the
 * change being written down.
 *
 * WHAT THIS REPLACES. .github/workflows/ci.yml carried a step named "Verify
 * pipeline manifest integrity" that computed `hash_file('sha256', ...)`, echoed
 * it, and exported it to the job summary. It compared the digest to nothing.
 * docs/performance.md described the same step as "this manifest is content-hashed
 * in CI; unauthorized changes fail the build", and the manifest's own header
 * repeated it. Three statements of a gate, and a step that could not fail —
 * ADR-0060 exactly: a check never observed to fail is indistinguishable from no
 * check.
 *
 * WHAT IT ACTUALLY BUYS, stated narrowly so nobody reads more into it. This is
 * not a defence against an attacker: anyone who can edit the manifest can also
 * run `--update` and edit the digest beside it. What it makes impossible is the
 * SILENT change — a middleware dropped from `request.authenticated_session`, a
 * storage backend swapped for a null one, a required side effect deleted — landing
 * in a diff that nobody reads as a change to the benchmark contract. After this,
 * changing the manifest means changing tools/php/bench-pipeline.manifest.sha256 in
 * the same commit, and that file exists for no other reason, so a reviewer seeing
 * it move knows precisely what moved. .github/CODEOWNERS routes both files through
 * review; the sign-off docs/performance.md asks for is a human step and this gate
 * does not claim to perform it.
 *
 * WHY THE DIGEST IS OVER NORMALISED BYTES. The manifest is hashed with CRLF
 * collapsed to LF. .gitattributes pins `* text=auto eol=lf`, so a healthy checkout
 * already has LF on every platform and the normalisation is a no-op — but a
 * contributor whose editor or whose `core.autocrlf` overrode it would otherwise
 * see a mismatch that has nothing to do with the manifest's content, and a gate
 * that fails for a reason the message cannot explain is a gate that gets
 * bypassed. {@see \Pulsar\Tests\Unit\Tooling\BenchPipelineManifestGateTest} plants
 * a CRLF manifest and requires it to pass, so the normalisation is observed rather
 * than assumed.
 *
 * Exit codes:
 *   0  the manifest matches the recorded digest (or --update rewrote it)
 *   1  the manifest and the digest disagree
 *   2  one of the two files is missing or unreadable — never a pass
 *
 * Usage:
 *   php tools/ci/assert-bench-manifest-integrity.php [--root=DIR] [--update] [--print-hash]
 */

const MANIFEST_RELATIVE = 'tools/php/bench-pipeline.manifest.php';
const DIGEST_RELATIVE = 'tools/php/bench-pipeline.manifest.sha256';

/**
 * @param array<string, list<string>|string|false> $options
 */
$stringOption = static function (array $options, string $name, string $default): string {
    $value = $options[$name] ?? null;

    return is_string($value) ? $value : $default;
};

$options = getopt('', ['root:', 'update', 'print-hash']);

if ($options === false) {
    fwrite(STDERR, "Could not parse command-line options.\n");

    exit(2);
}

$root = rtrim(str_replace('\\', '/', $stringOption($options, 'root', dirname(__DIR__, 2))), '/');
$manifestPath = $root . '/' . MANIFEST_RELATIVE;
$digestPath = $root . '/' . DIGEST_RELATIVE;

if (!is_file($manifestPath)) {
    fwrite(STDERR, sprintf(
        "%s does not exist.\n\nThe benchmark pipeline manifest is the declared contract for every "
        . 'request-class benchmark: which middleware run, which storage backends are real and which '
        . 'are stubs, what each benchmark must produce. A missing manifest is not an empty contract, '
        . "it is an unknown one, so this refuses rather than reporting a match against nothing.\n",
        MANIFEST_RELATIVE,
    ));

    exit(2);
}

$manifest = file_get_contents($manifestPath);

if ($manifest === false) {
    fwrite(STDERR, sprintf("Could not read %s.\n", MANIFEST_RELATIVE));

    exit(2);
}

$actual = hash('sha256', str_replace("\r\n", "\n", $manifest));

if (isset($options['print-hash'])) {
    echo $actual, "\n";

    exit(0);
}

if (isset($options['update'])) {
    if (file_put_contents($digestPath, $actual . '  ' . MANIFEST_RELATIVE . "\n") === false) {
        fwrite(STDERR, sprintf("Could not write %s.\n", DIGEST_RELATIVE));

        exit(2);
    }

    fprintf(STDOUT, "Recorded %s for %s.\n", $actual, MANIFEST_RELATIVE);
    fprintf(
        STDOUT,
        'Commit %s alongside the manifest change. Per docs/performance.md the change also needs '
        . 'Performance Engineer sign-off, and Architecture sign-off if it reduces security or '
        . "compliance coverage; this script records the digest and does not stand in for either.\n",
        DIGEST_RELATIVE,
    );

    exit(0);
}

if (!is_file($digestPath)) {
    fwrite(STDERR, sprintf(
        "%s does not exist, so there is nothing for the manifest to be checked against.\n\n"
        . 'This is the state the gate was in before it compared anything: a digest computed and '
        . "printed, and no recorded value to disagree with it. Run\n\n"
        . "    php tools/ci/assert-bench-manifest-integrity.php --update\n\n"
        . "and commit the result with the manifest it describes.\n",
        DIGEST_RELATIVE,
    ));

    exit(2);
}

$digestFile = file_get_contents($digestPath);

if ($digestFile === false) {
    fwrite(STDERR, sprintf("Could not read %s.\n", DIGEST_RELATIVE));

    exit(2);
}

// The sha256sum format: the digest, whitespace, the path it describes. Only the
// digest is read, and it is matched strictly — a truncated or re-wrapped file is
// an unreadable digest, not a digest that happens not to match, and the two need
// different messages because they need different fixes.
if (preg_match('/^([0-9a-f]{64})\b/', ltrim($digestFile), $matches) !== 1) {
    fwrite(STDERR, sprintf(
        '%s does not begin with a 64-character hex digest, so it records nothing this gate can '
        . "compare against. Regenerate it with --update rather than editing it by hand.\n",
        DIGEST_RELATIVE,
    ));

    exit(2);
}

$expected = $matches[1];

if (!hash_equals($expected, $actual)) {
    fwrite(STDERR, sprintf(
        "The benchmark pipeline manifest has changed and %s still records the previous digest.\n\n"
        . "  recorded: %s\n"
        . "  actual:   %s\n\n"
        . '%s declares the middleware stacks, storage backends, authentication requirements and '
        . 'required side effects that every request-class benchmark is measured under. A change to '
        . 'it moves what the numbers MEAN — dropping a middleware or swapping a real backend for a '
        . 'stub makes a benchmark faster without making anything faster — and the budgets in '
        . "tools/php/performance-budgets.json were derived under the old contract.\n\n"
        . "If the change is intended: re-derive the affected budgets, then run\n\n"
        . "    php tools/ci/assert-bench-manifest-integrity.php --update\n\n"
        . 'and commit the digest in the same change. docs/performance.md sets out the sign-off the '
        . "change needs; this gate makes the change visible, it does not approve it.\n",
        DIGEST_RELATIVE,
        $expected,
        $actual,
        MANIFEST_RELATIVE,
    ));

    exit(1);
}

fprintf(STDOUT, "bench manifest: OK (sha256 %s)\n", $actual);

exit(0);
