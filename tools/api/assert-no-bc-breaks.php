<?php

declare(strict_types=1);

/**
 * Refuses a backward-compatibility break on the `#[Api]` surface that nobody wrote down.
 *
 * WHY THIS EXISTS. `Pulsar\Api\BcBreakDetector` has shipped since 1.0.0 and
 * docs/deprecation-policy.md told readers "CI runs this automatically on every pull
 * request". Nothing ran it. Its only caller in the whole tree was its own unit test, so a
 * removed `#[Api]` method reached `main` with the SemVer promise enforced by review alone.
 * That is the ADR-0060 shape at its purest: the detector existed, worked, and refused
 * nothing.
 *
 * WHY IT DOES NOT COMPARE AGAINST A RELEASE. The obvious wiring — diff the committed
 * `tools/api/public-api.snapshot.json` against a snapshot rebuilt from the working tree —
 * is a tautology, and writing it would have reproduced the defect it was meant to close.
 * `Pulsar\Tests\Unit\Api\PublicApiSnapshotTest` already asserts the committed file equals a
 * fresh build, so on any tree where `composer test` passes those two documents are equal by
 * construction and the comparison can only ever report nothing. A contributor who removes a
 * method and regenerates the snapshot — which they must, to get the suite green — would
 * sail past it.
 *
 * The comparison has to cross a commit. So the base is a snapshot read out of git, and the
 * current side is built from the working tree:
 *
 *   - Locally (`composer bc:check`, no arguments) the base is `HEAD`. That catches the
 *     break in the working tree, before it is committed, which is where a contributor can
 *     still cheaply decide not to make it.
 *   - On a pull request CI passes `--base=origin/<base ref>`, and the script compares
 *     against the merge base with that ref. That catches a break made anywhere on the
 *     branch, including one committed and snapshot-regenerated several commits ago.
 *
 * Comparing against a *released* snapshot is the shape this becomes after 1.0.0, when
 * there is a release to compare with. Before then there is no published prior version and
 * pretending otherwise would mean comparing against nothing.
 *
 * WHY THE CHANGELOG IS THE ACKNOWLEDGEMENT. A gate that cannot be satisfied is a gate that
 * gets deleted, and pre-GA this repository does remove `#[Api]` symbols on purpose —
 * `SchedulerConfig::$lockTimeout`, `ApiException::entitySerializationBanned()`,
 * `EvidenceCollector` and the `database.pool` section are all deliberate, all recorded.
 * They are recorded in one place: CHANGELOG.md under `## [Unreleased]`, each with a
 * **Breaking:** note naming the symbol. So that section is what this gate accepts as the
 * acknowledgement. It needs no second list to keep in step with the first, it prunes itself
 * when a release ships, and — unlike a baseline file — the thing it demands is the sentence
 * a reader of the changelog needs anyway.
 *
 * The match is deliberately generous: `Class::member`, or the class name and the member
 * name both appearing in the section. Its job is to force a human sentence about the break
 * to exist, not to parse one. A gate strict enough to reject a correctly-written entry over
 * punctuation would be argued with instead of obeyed.
 *
 * Usage:
 *   php tools/api/assert-no-bc-breaks.php                     # base = HEAD
 *   php tools/api/assert-no-bc-breaks.php --base=origin/main  # base = merge-base with ref
 *   php tools/api/assert-no-bc-breaks.php --base-file=a.json --head-file=b.json
 *
 * The two `--*-file` options exist so tests/Unit/Api/BcBreakGateTest.php can plant a
 * removal and watch this refuse it without inventing a git history, and so the negative
 * test runs in a second rather than in the minutes a full reflection scan costs.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Pulsar\Api\BcBreak;
use Pulsar\Api\BcBreakDetector;
use Pulsar\Api\BcBreakSeverity;
use Pulsar\Extensibility\ExtensionAutoloader;
use Pulsar\Tooling\Api\ApiSnapshotBuilder;

$root = dirname(__DIR__, 2);
$baseRef = 'HEAD';
$baseFile = null;
$headFile = null;
$changelogPath = $root . '/CHANGELOG.md';

foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--base=')) {
        $baseRef = substr($argument, 7);

        continue;
    }

    if (str_starts_with($argument, '--base-file=')) {
        $baseFile = substr($argument, 12);

        continue;
    }

    if (str_starts_with($argument, '--head-file=')) {
        $headFile = substr($argument, 12);

        continue;
    }

    if (str_starts_with($argument, '--changelog=')) {
        $changelogPath = substr($argument, 12);

        continue;
    }

    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, 7);
        $changelogPath = $root . '/CHANGELOG.md';

        continue;
    }

    fwrite(STDERR, "bc:check: unrecognised argument {$argument}\n");

    exit(2);
}

/**
 * Decode a snapshot document, refusing anything that is not one.
 *
 * A snapshot that fails to parse must stop the gate rather than read as an empty
 * document: an empty base makes every symbol look new and reports all clear, which
 * is the exact failure mode the detector itself was repaired out of.
 *
 * @return array<string, mixed>
 */
$decodeSnapshot = static function (string $json, string $origin): array {
    try {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "bc:check: {$origin} is not valid JSON: {$e->getMessage()}\n");

        exit(2);
    }

    if (!is_array($decoded) || !isset($decoded['api_classes']) || !is_array($decoded['api_classes'])) {
        fwrite(STDERR, "bc:check: {$origin} has no `api_classes` map; it is not an API snapshot.\n");

        exit(2);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
};

/**
 * Read a file from a git ref, or stop.
 *
 * `git show` is used rather than a checkout so nothing in the working tree is
 * touched: this gate runs inside `composer qa`, next to a tree the contributor is
 * still editing.
 */
$showFromRef = static function (string $ref, string $path, string $root): string {
    $command = sprintf(
        'git -C %s show %s 2>&1',
        escapeshellarg($root),
        escapeshellarg($ref . ':' . $path),
    );

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    if ($status !== 0) {
        fwrite(STDERR, sprintf(
            "bc:check: cannot read %s at %s.\n\n  %s\n\n"
            . "The base snapshot is what a break is measured against. Without it this gate\n"
            . "would compare the tree against nothing and report all clear, so it stops instead.\n"
            . "Pass --base=<ref> naming a ref this clone has, or fetch the one you meant.\n",
            $path,
            $ref,
            implode("\n  ", $output),
        ));

        exit(2);
    }

    return implode("\n", $output);
};

$snapshotPath = 'tools/api/public-api.snapshot.json';

if ($baseFile !== null) {
    if (!is_file($baseFile)) {
        fwrite(STDERR, "bc:check: --base-file {$baseFile} does not exist\n");

        exit(2);
    }

    $baseDescription = $baseFile;
    $previous = $decodeSnapshot((string) file_get_contents($baseFile), $baseFile);
} else {
    // A ref that is not HEAD is treated as a branch to compare against, so the
    // comparison is with the point the branch left it rather than with its tip.
    // Comparing against the tip would report every change made on main since the
    // branch started as this branch's break.
    $resolvedRef = $baseRef;

    if ($baseRef !== 'HEAD') {
        $mergeBase = [];
        $mergeStatus = 0;
        exec(
            sprintf('git -C %s merge-base HEAD %s 2>&1', escapeshellarg($root), escapeshellarg($baseRef)),
            $mergeBase,
            $mergeStatus,
        );

        if ($mergeStatus !== 0 || !isset($mergeBase[0]) || trim($mergeBase[0]) === '') {
            fwrite(STDERR, sprintf(
                "bc:check: no merge base between HEAD and %s.\n\n  %s\n",
                $baseRef,
                implode("\n  ", $mergeBase),
            ));

            exit(2);
        }

        $resolvedRef = trim($mergeBase[0]);
    }

    $baseDescription = $baseRef === $resolvedRef
        ? $baseRef
        : sprintf('%s (merge base with %s)', substr($resolvedRef, 0, 12), $baseRef);
    $previous = $decodeSnapshot($showFromRef($resolvedRef, $snapshotPath, $root), $baseDescription);
}

if ($headFile !== null) {
    if (!is_file($headFile)) {
        fwrite(STDERR, "bc:check: --head-file {$headFile} does not exist\n");

        exit(2);
    }

    $currentDescription = $headFile;
    $current = $decodeSnapshot((string) file_get_contents($headFile), $headFile);
} else {
    // Built, not read off disk. Reading the committed file would compare the
    // committed file against itself the moment a contributor regenerates it.
    ExtensionAutoloader::registerForPaths([$root . '/extensions']);
    $currentDescription = 'the working tree';
    /** @var array<string, mixed> $current */
    $current = new ApiSnapshotBuilder($root . '/src', $root . '/extensions')->build();
}

$breaks = new BcBreakDetector()->detect($previous, $current);

/**
 * The `## [Unreleased]` section of the changelog, or an empty string.
 *
 * Empty is not an error: a tree with no unreleased section simply acknowledges
 * nothing, and every break it contains is unacknowledged. That is the correct
 * reading, and it is the strict one.
 */
$unreleased = '';

if (is_file($changelogPath)) {
    $changelog = (string) file_get_contents($changelogPath);

    if (preg_match('/^## \[Unreleased\]$(.*?)^## \[/ms', $changelog, $section) === 1) {
        $unreleased = $section[1];
    } elseif (preg_match('/^## \[Unreleased\]$(.*)$/ms', $changelog, $section) === 1) {
        $unreleased = $section[1];
    }
}

/**
 * Whether the changelog's unreleased section names this symbol.
 */
$acknowledged = static function (string $symbol, string $section): bool {
    if ($section === '') {
        return false;
    }

    if (str_contains($section, $symbol)) {
        return true;
    }

    $className = $symbol;
    $member = null;

    if (str_contains($symbol, '::')) {
        [$className, $member] = explode('::', $symbol, 2);
    }

    $shortName = str_contains($className, '\\')
        ? substr($className, (int) strrpos($className, '\\') + 1)
        : $className;

    if ($member === null) {
        return str_contains($section, $shortName);
    }

    if (str_contains($section, $shortName . '::' . $member)) {
        return true;
    }

    return str_contains($section, $shortName) && str_contains($section, $member);
};

$blocking = [];
$acknowledgedBreaks = [];
$warnings = [];

foreach ($breaks as $break) {
    if ($break->severity !== BcBreakSeverity::Error) {
        $warnings[] = $break;

        continue;
    }

    if ($acknowledged($break->symbol, $unreleased)) {
        $acknowledgedBreaks[] = $break;

        continue;
    }

    $blocking[] = $break;
}

/**
 * One line per break, in the order the detector produced them.
 *
 * The `instanceof` is not defensive noise: this closure is handed three different
 * arrays built above, and a static analyser reading a closure's parameter has no
 * docblock to narrow it. Checking is cheaper than annotating around the check.
 *
 * @param array<int, BcBreak> $list
 */
$render = static function (array $list): string {
    $lines = [];

    foreach ($list as $break) {
        if (!$break instanceof BcBreak) {
            continue;
        }

        $lines[] = sprintf('  [%s] %s', $break->type->value, $break->message);
    }

    return implode("\n", $lines);
};

printf(
    "bc:check: compared %s against %s.\n",
    $currentDescription,
    $baseDescription,
);

if ($warnings !== []) {
    printf(
        "\n%d break(s) below the stable surface (reported, not blocking):\n%s\n",
        count($warnings),
        $render($warnings),
    );
}

if ($acknowledgedBreaks !== []) {
    printf(
        "\n%d break(s) acknowledged in CHANGELOG.md [Unreleased]:\n%s\n",
        count($acknowledgedBreaks),
        $render($acknowledgedBreaks),
    );
}

if ($blocking !== []) {
    fwrite(STDERR, sprintf(
        "\nFAIL: %d backward-compatibility break(s) on the stable `#[Api]` surface that\n"
        . "nothing records.\n\n%s\n\n"
        . "Each of these removes or changes something a `#[Api(since: ...)]` type promised.\n"
        . "Either put it back, or write the break down: add an entry to CHANGELOG.md under\n"
        . "`## [Unreleased]` naming the symbol and saying why it went. This gate reads that\n"
        . "section, so the record and the release notes are the same sentence.\n",
        count($blocking),
        $render($blocking),
    ));

    exit(1);
}

/** @var array<string, mixed> $baseApiClasses */
$baseApiClasses = is_array($previous['api_classes'] ?? null) ? $previous['api_classes'] : [];

printf(
    "\nOK: no unrecorded break on the stable surface (%d `#[Api]` type(s) compared).\n",
    count($baseApiClasses),
);
