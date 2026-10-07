<?php

declare(strict_types=1);

/**
 * One version, one source of truth.
 *
 * composer.json's `version` is the source. Everything else in the repository that
 * states which version this is -- the compile-time constants, 34 bundled extension
 * manifests, the supported-versions table a reporter reads before filing a CVE, the
 * README status line, the issue template, the installation guide -- is DERIVED from
 * it by this script or VERIFIED against it, and the build refuses a tree where any of
 * them disagrees.
 *
 * Before this, exactly one file was derived (src/Core/Version.php) and everything else
 * drifted: 26 manifests sat at 1.0.0-rc.11, seven claimed 1.0.0 -- a release the
 * framework has not made -- one was still at 0.2.0, and .github/SECURITY.md told the
 * world that security fixes went to rc.11 while the current release was rc.12. None of
 * it was noticed, because nothing was looking.
 *
 * ---------------------------------------------------------------------------------
 * WHY composer.json KEEPS ITS `version` FIELD
 *
 * `composer validate` warns that a published package should leave `version` out and
 * let the git tag be the truth, because Packagist derives the version from the tag and
 * a field that disagrees with the tag misleads every reader of the file. That is a
 * real hazard and it is worth answering rather than ignoring.
 *
 * It is answered by three facts about THIS repository.
 *
 *   1. There is no tag to derive from. `git describe` on this history reports "No tags
 *      can describe HEAD"; the only tags are `backup/*` and `pre-rebase-*`. Releases
 *      here are cut from composer.json. A source of truth that is empty is not one.
 *
 *   2. The repository must be self-consistent BEFORE the tag exists. Everything this
 *      script governs -- the manifests, the supported-versions table, the status line
 *      -- has to be right in the commit that prepares the release, which is by
 *      definition a commit no release tag points at yet. Under tag-as-truth the
 *      enforced state of a release branch would be "still says the previous version",
 *      which is precisely the drift being removed.
 *
 *   3. Dropping the field makes the runtime answer worse, not better. `Version::full()`
 *      prefers `Composer\InstalledVersions::getPrettyVersion('pulsar/framework')` --
 *      the version Composer actually resolved -- and falls back to the constants only
 *      where the autoloader is absent. In an installed application that primary source
 *      is the tag, which is the honest answer. In a source checkout it is the root
 *      package, and the root package reports `1.0.0-rc.12` today ONLY because the
 *      field is there; without it Composer reports `dev-<branch>`, and the framework
 *      starts telling applications it is a branch name.
 *
 * So the field stays, and the hazard the warning names is closed from the other end: a
 * tag is VERIFIED against the field rather than trusted instead of it. When a
 * version-shaped tag points at HEAD it must equal composer.json's version, so the one
 * moment where the two can disagree -- the release commit -- is the one moment this
 * script compares them.
 *
 * ---------------------------------------------------------------------------------
 * WHAT AN EXTENSION VERSION MEANS
 *
 * docs/extension-versioning.md already states the rule and has since it was written:
 * core extensions are versioned IN LOCKSTEP with the framework, community extensions
 * independently. There was nothing to arbitrate between the three conventions found in
 * the tree -- the rule was written down and never enforced. This script enforces it:
 *
 *   - every bundled `pulsar.json` declares the framework's version as its own;
 *   - its `pulsar.min_version` is that same version, which is also the only value that
 *     is correct under both readings of `PulsarVersionConfig::isSatisfiedByCurrent()`
 *     (it compares against `Version::short()` today, so `1.0.0` satisfies it, and it
 *     would still hold if that were ever tightened to the full version);
 *   - a `requires`/`suggests` entry naming a `pulsar/*` sibling declares `>=` that same
 *     version, because bundled extensions ship together and a lower floor advertises a
 *     pairing that has never existed and was never tested;
 *   - a sibling named there must actually be one of the bundled extensions, and a
 *     `pulsar.json` may not carry a composer-style `require` key: `ExtensionManifest`
 *     reads neither, so both are dependency claims nothing enforces.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE DOCUMENTATION SITES ARE A DECLARED MAP
 *
 * "Every mention of rc.11 is wrong" is false, and that is the whole difficulty. An ADR
 * narrating what happened at rc.11 is correct and must never move; a manifest
 * declaring rc.11 as its own version is wrong. No derivation can tell a sentence about
 * the present from a sentence about the past, so DECLARED_SITES records which
 * sentences are claims about the present -- file, pattern, and the form the sentence
 * spells the version in.
 *
 * A hand-written map is the thing this repository distrusts most, so it is falsifiable
 * in both directions:
 *
 *   - a site whose pattern matches nothing FAILS. The map cannot rot into silence by
 *     the sentence being reworded; somebody has to look at it again.
 *   - every remaining mention of a release-candidate tag that is not the current one is
 *     scanned for, and each must sit in a file recorded in NARRATED_FILES with a
 *     reason. A new file saying "the current release is <old>" is refused rather than
 *     joining the drift.
 *
 * Test expectations are deliberately NOT derived. tests/Unit/Core/VersionTest.php and
 * tests/E2E/BootPipelineTest.php pin the version as a literal, and those fail loudly on
 * a bump, which makes them checks rather than drift. This gate exists for the
 * declarations that stayed silent.
 *
 * ---------------------------------------------------------------------------------
 * Usage:
 *   php tools/version/sync-version.php                # derive every site
 *   php tools/version/sync-version.php --check        # exit 1 on any disagreement
 *   php tools/version/sync-version.php --root=PATH    # judge a fixture tree instead
 *
 * `--root` exists so this gate's own judgement can be tested against a tree that
 * disagrees on purpose, which the repository itself must never do. It follows the
 * precedent of --composer=/--workflow= on the parity gate and --index=/--baseline= on
 * the class-shape gate.
 *
 * Exit codes:
 *   0  every declared version agrees with composer.json
 *   1  something disagrees, or a declared site has gone missing
 *   2  the check could not be made -- unreadable file, unparseable version, no git,
 *      nothing found to check. Never a pass.
 */

const VERSION_GATE_COMPOSER = 'composer.json';
const VERSION_GATE_CONSTANTS = 'src/Core/Version.php';
const VERSION_GATE_SELF = 'tools/version/sync-version.php';

/** A semver-ish token as it appears inside prose, a table cell or a YAML scalar. */
const VERSION_GATE_TOKEN = '[0-9A-Za-z][0-9A-Za-z.+\-]*[0-9A-Za-z]';

/** The full version, e.g. `1.0.0-rc.12`. */
const VERSION_GATE_FORM_FULL = 'full';

/** The prerelease shorthand this repository's prose uses, e.g. `rc.12`. */
const VERSION_GATE_FORM_SHORT = 'short';

/**
 * Every sentence in the repository that states which version this IS.
 *
 * Each entry is a file, a pattern whose single capture group is the version token, the
 * form that sentence spells it in, and what the sentence claims. A pattern matching
 * nothing is a failure: the claim did not stop being a claim because somebody reworded
 * it.
 *
 * @var array<string, list<array{pattern: string, form: string, claim: string}>>
 */
const VERSION_GATE_DECLARED_SITES = [
    'README.md' => [
        [
            'pattern' => '~\*\*Status:\*\* Release Candidate \((' . VERSION_GATE_TOKEN . ')\)~',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the status line the repository opens with',
        ],
    ],
    '.github/SECURITY.md' => [
        [
            'pattern' => '~^\| (' . VERSION_GATE_TOKEN . ') +\| Yes +\|$~m',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the version the supported-versions table says receives security fixes',
        ],
        [
            'pattern' => '~^\| < (' . VERSION_GATE_TOKEN . ') +\| No +\|$~m',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the boundary below which that same table says fixes stop',
        ],
    ],
    'ROADMAP.md' => [
        [
            'pattern' => '~^### (' . VERSION_GATE_TOKEN . ') → 1\.0\.0 - GA gating work$~mu',
            'form' => VERSION_GATE_FORM_SHORT,
            'claim' => 'the release the GA gating section counts from',
        ],
        [
            'pattern' => '~stands between (' . VERSION_GATE_TOKEN . ') and the 1\.0\.0 GA tag~',
            'form' => VERSION_GATE_FORM_SHORT,
            'claim' => 'the release the remaining GA work is measured from',
        ],
        [
            'pattern' => '~upgrade guide for `(' . VERSION_GATE_TOKEN . ') → 1\.0\.0`~u',
            'form' => VERSION_GATE_FORM_SHORT,
            'claim' => 'the release the GA upgrade guide will be written from',
        ],
    ],
    'docs/install.md' => [
        [
            'pattern' => '~^Pulsar Framework (' . VERSION_GATE_TOKEN . '): Installation~m',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the release the installation guide describes',
        ],
        [
            'pattern' => '~^\[INFO\] Framework\n  Version: +(' . VERSION_GATE_TOKEN . ')$~m',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the version the sample `pulsar diagnose` output shows',
        ],
        [
            'pattern' => '~^composer require pulsar/framework:\^(' . VERSION_GATE_TOKEN . ')$~m',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the release candidate the pre-Packagist install command tells a reader to require',
        ],
    ],
    'docs/extension-versioning.md' => [
        [
            'pattern' => '~"name": "pulsar/admin",\n  "version": "(' . VERSION_GATE_TOKEN . ')"~',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the version in the worked example of a core extension manifest',
        ],
        [
            'pattern' => '~"name": "pulsar/admin",[\s\S]{0,240}?"min_version": "(' . VERSION_GATE_TOKEN . ')"~',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the min_version in that same worked example',
        ],
    ],
    'docs/compliance.md' => [
        [
            'pattern' => '~requirements as of Pulsar (' . VERSION_GATE_TOKEN . ')\.~',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the release the regulatory-currency statement is dated to',
        ],
    ],
    'docs/prd-1.0.0.md' => [
        [
            'pattern' => '~^1\. (' . VERSION_GATE_TOKEN . ') \(now\)~m',
            'form' => VERSION_GATE_FORM_SHORT,
            'claim' => 'the release the PRD calls the current one',
        ],
    ],
    'docs/architecture/superglobal-isolation.md' => [
        [
            'pattern' => '~^## Current state \((' . VERSION_GATE_TOKEN . ')\)$~m',
            'form' => VERSION_GATE_FORM_SHORT,
            'claim' => 'the release the isolation status is stated for',
        ],
    ],
    '.github/workflows/benchmark-rc-gate.yml' => [
        [
            'pattern' => '~RC version being validated \(e\.g\. (' . VERSION_GATE_TOKEN . ')\)~',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the version the RC benchmark gate offers the operator as the one to type',
        ],
    ],
    'resources/playground/catalog.html' => [
        [
            'pattern' => '~The current release is (' . VERSION_GATE_TOKEN . ')\.~',
            'form' => VERSION_GATE_FORM_FULL,
            'claim' => 'the release the component catalogue tells a visitor is current',
        ],
    ],
];

/**
 * The bug-report template's version dropdown, whose first option must be the current
 * release. Handled apart from the map above because its repair is an INSERT: a bump
 * adds the new release at the top and keeps the older ones, since people do report bugs
 * against the version they are still running.
 */
const VERSION_GATE_ISSUE_TEMPLATE = '.github/ISSUE_TEMPLATE/bug.yml';

/**
 * Files that name releases other than the current one for reasons that are not a claim
 * about which version this is.
 *
 * This is the other half of the map, and it is what stops a NEW stale declaration from
 * joining the drift unnoticed: any file outside this list and outside the trees below
 * that names a release-candidate tag which is not the current one fails the gate, with
 * the choice stated -- derive it, or record it here with a reason.
 *
 * @var array<string, string> path => why the versions it names are not claims about the present
 */
const VERSION_GATE_NARRATED_FILES = [
    VERSION_GATE_ISSUE_TEMPLATE => 'the options after the first are the older releases people may still '
        . 'be running and may need to report a bug against. The first option is the claim, and it is '
        . 'derived.',
    'ROADMAP.md' => 'the "Previous releases" section is the release history, and the coverage and MSI '
        . 'ramps name the releases those thresholds are scheduled for. Its three present-tense claims '
        . 'are declared sites above.',
    '.github/workflows/ci.yml' => 'the version step records which release the drift it now refuses went '
        . 'unnoticed through.',
    'config/extensions.php' => 'records that an entry read `core` until rc.12 and why it no longer does.',
    'docs/authentication.md' => 'tells operators upgrading from rc.10 or earlier to re-key a table, '
        . 'which stays true for as long as anyone can be upgrading from it.',
    'docs/events.md' => 'records that the outbox installer ran at boot in rc.11 and that rc.12 deleted it.',
    'docs/extension-versioning.md' => 'records the state the bundled manifests were found in before lockstep '
        . 'was enforced, which is the evidence for enforcing it. Its worked example is two declared sites '
        . 'above.',
    'docs/install.md' => 'carries the rc.10 -> rc.11 migration note about the extension set that expanded '
        . 'then. Its two present-tense claims are declared sites above.',
    'docs/prd-1.0.0.md' => 'plans the cluster of work each remaining release candidate carries. Its claim '
        . 'about which release is current is a declared site above.',
    'docs/security/archive/README.md' => 'the map of the archive: it says which release each archived '
        . 'snapshot was written against, which is the whole reason the snapshot is filed rather than deleted.',
    'docs/security/archive/oauth2-webauthn-compliance-review.md' => 'an archived, dated review, scoped in its '
        . 'header to the contract surface it actually read. It lives under docs/security/archive/ so that a '
        . 'pre-release snapshot cannot be mistaken for current policy; the rc.11 mention is part of the record.',
    'docs/upgrade.md' => 'an upgrade guide names the versions it migrates between; each mention is the '
        . 'record of one migration, and the next release adds a section rather than rewriting the ones '
        . 'already written.',
    'resources/themes/default.css' => 'a `Since:` marker for the release the theme was introduced in.',
    'resources/themes/legacy.css' => 'describes the UI theme that predates rc.11, which is what makes it '
        . 'the legacy one.',
    'src/Auth/TwoFactor/TwoFactorManagerInterface.php' => 'records the release in which two of its methods '
        . 'changed signature.',
    'src/Runtime/Http/HttpResponseSerializer.php' => 'records the release in which chunked transfer encoding '
        . 'stopped being emitted on responses.',
    VERSION_GATE_CONSTANTS => 'illustrates the shape of the string `Version::full()` returns with `1.2.3-rc.4`, '
        . 'a version deliberately unlike any this project has made, so that the example cannot be read as the '
        . 'answer. The constants themselves are derived above.',
    'tools/php/phpstan.neon' => 'explains that certain classes were deprecated in rc.11 and must stay under '
        . 'test until they are removed.',
    'tools/ci/assert-coverage-threshold.php' => 'documents the release each step of the coverage ramp is '
        . 'scheduled for.',
];

/**
 * Paths where a version mention is narration by construction, so listing the files one
 * by one would record nothing a reader could not derive from the path itself.
 *
 * @var array<string, string> path prefix => why
 */
const VERSION_GATE_NARRATED_TREES = [
    'CHANGELOG.md' => 'a changelog is the release history; every version in it is a heading over what that '
        . 'release did.',
    'docs/adr/' => 'an ADR is a dated record of a decision, and ADR-0001 forbids rewriting one after the fact.',
    'tools/api/public-api.snapshot.json' => 'generated; every version in it is a `since` marker for when an '
        . 'API appeared.',
    VERSION_GATE_SELF => 'this file argues from the versions that had drifted, and names them to do it.',
];

/**
 * Any `tests/` directory, at the repository root or inside an extension.
 *
 * A prefix would only have caught the root one, and `extensions/studio/tests/` holds the
 * same kind of fixture for the same reason: a test that pins a version it does not
 * expect the framework to be at is doing its job.
 */
const VERSION_GATE_TEST_SEGMENT = '/tests/';

/**
 * A version introduced by `since` or `deprecated` names the release something APPEARED
 * in or STOPPED being recommended in, and is therefore never a claim about the current
 * one.
 *
 * Matched against the text immediately preceding the version, so it covers every
 * spelling the repository actually uses -- `#[Api(since: '...')]`, `"since": "..."`,
 * `@since ...`, `@deprecated Since ...`, `natively since v...` -- without listing the
 * several hundred sites that carry one. Anchored at the end and forbidding digits in
 * between so it cannot reach backwards past an unrelated version on the same line.
 */
const VERSION_GATE_LINEAGE_MARKER = '~\b(?:since|deprecated)\b[^0-9\n]{0,24}$~i';

/**
 * A release-candidate tag of this project, in either spelling the repository uses:
 * `1.0.0-rc.12` in a manifest or a table, `rc.12` in prose that has already said which
 * major series it means.
 *
 * The word boundary is load-bearing rather than tidy -- without it the short form matches
 * inside `arc.5`, and a gate that reports a word as a stale version teaches people to
 * ignore it. Both spellings are scanned because both were found drifting: the manifests
 * carried the long one and the roadmap the short one.
 *
 * An alternation rather than an optional prefix, so that the long form still matches inside
 * `v1.0.0-rc.11`, where a leading word boundary would refuse it and leave only `rc.11`
 * matched -- with the version digits then sitting between `since` and the match, which is
 * enough to hide the marker that makes the line history rather than a claim.
 *
 * Written in POSIX ERE as well as PCRE, because the same string is handed to `git grep -E`
 * and to preg_match_all(). Its group holds the alternation and is never read.
 */
const VERSION_GATE_RC_TAG = '([0-9]+\.[0-9]+\.[0-9]+-rc\.[0-9]+|\brc\.[0-9]+)';

/**
 * @param list<string> $arguments
 *
 * @return array{check: bool, root: string}
 */
function version_gate_parse_arguments(array $arguments, string $default): array
{
    $check = false;
    $root = $default;

    foreach ($arguments as $argument) {
        if ($argument === '--check') {
            $check = true;

            continue;
        }

        if (str_starts_with($argument, '--root=')) {
            $root = rtrim(substr($argument, strlen('--root=')), '/\\');

            continue;
        }

        fwrite(STDERR, sprintf(
            "Unrecognised argument: %s\nUsage: php %s [--check] [--root=PATH]\n",
            $argument,
            VERSION_GATE_SELF,
        ));

        exit(2);
    }

    return ['check' => $check, 'root' => $root];
}

/**
 * Reads a file the gate cannot proceed without.
 */
function version_gate_read(string $path): string
{
    $contents = @file_get_contents($path);

    if (!is_string($contents)) {
        fwrite(STDERR, "version: cannot read {$path}.\n");

        exit(2);
    }

    return $contents;
}

/**
 * Runs git in the tree under judgement and returns [exit code, stdout].
 *
 * git rather than a filesystem walk, because the walk would have to re-implement
 * .gitignore to avoid vendor/ and node_modules/, and because on a synced working copy
 * it is tens of times slower. A tree git cannot read is reported, never passed.
 *
 * @param list<string> $arguments
 *
 * @return array{0: int, 1: string}
 */
function version_gate_git(string $root, array $arguments): array
{
    $process = proc_open(
        array_values(['git', '-C', $root, ...$arguments]),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );

    if (!is_resource($process)) {
        fwrite(STDERR, "version: git is not available, and this gate reads the repository through it.\n");

        exit(2);
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_close($process);

    // `git grep` exits 1 for "no matches", which is a result and not a failure.
    if ($status > 1) {
        fwrite(STDERR, sprintf(
            "version: `git %s` failed in %s (%d): %s\n",
            implode(' ', $arguments),
            $root,
            $status,
            $stderr,
        ));

        exit(2);
    }

    return [$status, $stdout];
}

/**
 * Replaces one span, keeping a markdown table row the same width where the padding
 * beside it allows. Without this, a version one character longer than the last would
 * reflow a table and fail the Prettier gate in a file nobody edited by hand.
 */
function version_gate_replace_span(string $subject, int $offset, int $length, string $replacement): string
{
    $before = substr($subject, 0, $offset);
    $after = substr($subject, $offset + $length);
    $newline = strrpos($before, "\n");
    $line = $newline === false ? $before : substr($before, $newline + 1);
    $delta = strlen($replacement) - $length;

    if ($delta !== 0 && str_starts_with(ltrim($line), '|')) {
        if ($delta < 0) {
            $after = str_repeat(' ', -$delta) . $after;
        } elseif (preg_match('~^ {' . $delta . ',}~', $after) === 1) {
            $after = substr($after, $delta);
        }
    }

    return $before . $replacement . $after;
}

/**
 * Every match of a single-capture-group pattern, as [captured text, offset, length].
 *
 * @return list<array{0: string, 1: int, 2: int}>
 */
function version_gate_matches(string $subject, string $pattern): array
{
    $found = preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE);

    if ($found === false) {
        fwrite(STDERR, "version: pattern failed to compile: {$pattern}\n");

        exit(2);
    }

    /** @var array<int, list<array{0: string, 1: int}>> $matches */
    $group = $matches[1] ?? [];
    $spans = [];

    foreach ($group as $capture) {
        $spans[] = [$capture[0], $capture[1], strlen($capture[0])];
    }

    return $spans;
}

/**
 * Splits git output into lines, dropping the empty tail.
 *
 * @return list<string>
 */
function version_gate_lines(string $output): array
{
    $lines = preg_split('~\R~', $output);

    if ($lines === false) {
        return [];
    }

    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
}

$repositoryRoot = dirname(__DIR__, 2);
/** @var list<string> $rawArguments */
$rawArguments = array_values(array_filter($argv ?? [], 'is_string'));
array_shift($rawArguments);
$options = version_gate_parse_arguments($rawArguments, $repositoryRoot);
$check = $options['check'];
$root = $options['root'];

$composerRaw = version_gate_read($root . '/' . VERSION_GATE_COMPOSER);

try {
    /** @var array<string, mixed> $composer */
    $composer = json_decode($composerRaw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'version: composer.json is not valid JSON: ' . $exception->getMessage() . "\n");

    exit(2);
}

/** @var mixed $declared */
$declared = $composer['version'] ?? null;
$version = is_string($declared) ? $declared : '';

if (preg_match('~^(\d+)\.(\d+)\.(\d+)(-[0-9A-Za-z.-]+)?$~', $version, $parts) !== 1) {
    fwrite(STDERR, sprintf(
        "version: composer.json declares '%s', which is not a semver string, so there is no source to\n"
        . "derive anything from. That field is the source of truth for the whole repository; see the\n"
        . "header of %s for why it is a field and not the git tag.\n",
        $version,
        VERSION_GATE_SELF,
    ));

    exit(2);
}

$major = (int) $parts[1];
$minor = (int) $parts[2];
$patch = (int) $parts[3];
$suffix = $parts[4] ?? '';
$shortForm = $suffix === '' ? $version : substr($suffix, 1);

/** @var list<string> $findings the tree and the source disagree */
$findings = [];
/** @var list<string> $blocking findings no rewrite can repair, because each needs a decision */
$blocking = [];
/** @var array<string, true> $filesWithFindings files already reported, so the scan does not say it twice */
$filesWithFindings = [];
/** @var array<string, string> $rewrites path => new contents */
$rewrites = [];
$sitesChecked = 0;

// -- The compile-time fallback constants ------------------------------------------
//
// The runtime PRIMARY source stays Composer\InstalledVersions (see Version::full());
// these are the fallback for an environment running without the Composer autoloader,
// and they are generated so they cannot disagree with the field they fall back from.

$constantsPath = $root . '/' . VERSION_GATE_CONSTANTS;
$constantsSource = version_gate_read($constantsPath);
$constants = $constantsSource;

$constantPatterns = [
    '~public const int MAJOR = \d+;~' => "public const int MAJOR = {$major};",
    '~public const int MINOR = \d+;~' => "public const int MINOR = {$minor};",
    '~public const int PATCH = \d+;~' => "public const int PATCH = {$patch};",
    "~public const string PRERELEASE_SUFFIX = '[^']*';~" => "public const string PRERELEASE_SUFFIX = '{$suffix}';",
];

foreach ($constantPatterns as $pattern => $replacement) {
    if (preg_match($pattern, $constants) !== 1) {
        fwrite(STDERR, sprintf(
            "version: %s no longer declares a constant matching %s, so the fallback cannot be derived.\n"
            . "Restore the constant or update this generator -- do not leave it unwritten.\n",
            VERSION_GATE_CONSTANTS,
            $pattern,
        ));

        exit(2);
    }

    $result = preg_replace($pattern, $replacement, $constants);

    if (!is_string($result)) {
        fwrite(STDERR, "version: failed to rewrite the constants (pattern: {$pattern}).\n");

        exit(2);
    }

    $constants = $result;
    ++$sitesChecked;
}

if ($constants !== $constantsSource) {
    $findings[] = sprintf(
        '%s: the compile-time fallback constants do not spell %s.',
        VERSION_GATE_CONSTANTS,
        $version,
    );
    $filesWithFindings[VERSION_GATE_CONSTANTS] = true;
    $rewrites[$constantsPath] = $constants;
}

// -- Every sentence that claims which version this is ------------------------------

foreach (VERSION_GATE_DECLARED_SITES as $relative => $sites) {
    $path = $root . '/' . $relative;
    $source = version_gate_read($path);
    $updated = $source;

    foreach ($sites as $site) {
        $spans = version_gate_matches($updated, $site['pattern']);

        if ($spans === []) {
            $missing = sprintf(
                "%s: nothing matches the declared site for %s.\n"
                . "      The sentence carrying this claim was reworded or removed. A claim about which\n"
                . "      version this is does not stop being one because its wording changed: restore it,\n"
                . '      or update VERSION_GATE_DECLARED_SITES in ' . VERSION_GATE_SELF . '.',
                $relative,
                $site['claim'],
            );
            $findings[] = $missing;
            $blocking[] = $missing;
            $filesWithFindings[$relative] = true;

            continue;
        }

        $want = $site['form'] === VERSION_GATE_FORM_SHORT ? $shortForm : $version;

        // Rewritten last match first, so the earlier offsets stay valid.
        foreach (array_reverse($spans) as $span) {
            ++$sitesChecked;

            if ($span[0] === $want) {
                continue;
            }

            $findings[] = sprintf(
                '%s: %s says %s, and the version is %s.',
                $relative,
                $site['claim'],
                $span[0],
                $want,
            );
            $filesWithFindings[$relative] = true;
            $updated = version_gate_replace_span($updated, $span[1], $span[2], $want);
        }
    }

    if ($updated !== $source) {
        $rewrites[$path] = $updated;
    }
}

// -- The bug template's version dropdown -------------------------------------------

$templatePath = $root . '/' . VERSION_GATE_ISSUE_TEMPLATE;
$template = version_gate_read($templatePath);
$optionPattern = '~(label: Pulsar version\n\s*options:\n)([ \t]*)- (' . VERSION_GATE_TOKEN . ')~';

if (preg_match($optionPattern, $template, $option) !== 1) {
    $absent = sprintf(
        "%s: no `Pulsar version` dropdown with a first option was found, so nothing asks a reporter\n"
        . '      which version they are running.',
        VERSION_GATE_ISSUE_TEMPLATE,
    );
    $findings[] = $absent;
    $blocking[] = $absent;
    $filesWithFindings[VERSION_GATE_ISSUE_TEMPLATE] = true;
} else {
    ++$sitesChecked;

    if ($option[3] !== $version) {
        $findings[] = sprintf(
            '%s: the version dropdown offers %s first and never offers %s at all.',
            VERSION_GATE_ISSUE_TEMPLATE,
            $option[3],
            $version,
        );
        $filesWithFindings[VERSION_GATE_ISSUE_TEMPLATE] = true;

        $inserted = preg_replace(
            $optionPattern,
            '${1}${2}- ' . $version . "\n" . '${2}- ${3}',
            $template,
            1,
        );

        if (is_string($inserted)) {
            $rewrites[$templatePath] = $inserted;
        }
    }
}

// -- The bundled extension manifests -----------------------------------------------

$manifestList = version_gate_git($root, [
    'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', 'extensions/*pulsar.json',
])[1];
$manifestPaths = array_values(array_filter(
    explode("\0", $manifestList),
    static fn(string $path): bool => $path !== '',
));
sort($manifestPaths);

if ($manifestPaths === []) {
    fwrite(STDERR, sprintf(
        "version: no extension manifest was found under %s/extensions. This repository bundles dozens of\n"
        . "them; finding none means the enumeration reached nothing, and a scan that reached nothing must\n"
        . "never be reported as a clean result.\n",
        $root,
    ));

    exit(2);
}

/** @var array<string, string> $bundled extension name => manifest path */
$bundled = [];
/** @var array<string, array<string, mixed>> $manifests path => decoded manifest */
$manifests = [];

foreach ($manifestPaths as $relative) {
    $raw = version_gate_read($root . '/' . $relative);

    try {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fwrite(STDERR, sprintf("version: %s is not valid JSON: %s\n", $relative, $exception->getMessage()));

        exit(2);
    }

    $manifests[$relative] = $manifest;
    /** @var mixed $name */
    $name = $manifest['name'] ?? null;

    if (is_string($name)) {
        $bundled[$name] = $relative;
    }
}

foreach ($manifests as $relative => $manifest) {
    $path = $root . '/' . $relative;
    $source = version_gate_read($path);
    $updated = $source;

    /** @var mixed $own */
    $own = $manifest['version'] ?? null;
    ++$sitesChecked;

    if (!is_string($own)) {
        $silent = sprintf('%s: declares no `version`, so it states nothing about what it is.', $relative);
        $findings[] = $silent;
        $blocking[] = $silent;
        $filesWithFindings[$relative] = true;
    } elseif ($own !== $version) {
        $findings[] = sprintf(
            '%s: declares version %s. Bundled extensions ship in lockstep with the framework '
            . '(docs/extension-versioning.md), so it is %s.',
            $relative,
            $own,
            $version,
        );
        $filesWithFindings[$relative] = true;
        $updated = (string) preg_replace('~("version"\s*:\s*)"[^"]*"~', '${1}"' . $version . '"', $updated, 1);
    }

    /** @var mixed $pulsarSection */
    $pulsarSection = $manifest['pulsar'] ?? null;
    /** @var array<string, mixed> $pulsar */
    $pulsar = is_array($pulsarSection) ? $pulsarSection : [];
    /** @var mixed $min */
    $min = $pulsar['min_version'] ?? null;
    ++$sitesChecked;

    if (!is_string($min)) {
        $unbound = sprintf(
            '%s: declares no `pulsar.min_version`, so it would load against any framework at all.',
            $relative,
        );
        $findings[] = $unbound;
        $blocking[] = $unbound;
        $filesWithFindings[$relative] = true;
    } elseif ($min !== $version) {
        $findings[] = sprintf(
            '%s: binds to framework >=%s. In lockstep that is %s.',
            $relative,
            $min,
            $version,
        );
        $filesWithFindings[$relative] = true;
        $updated = (string) preg_replace('~("min_version"\s*:\s*)"[^"]*"~', '${1}"' . $version . '"', $updated, 1);
    }

    /** @var mixed $max */
    $max = $pulsar['max_version'] ?? null;

    if (is_string($max)) {
        ++$sitesChecked;

        if (version_compare($version, $max, '>')) {
            $excluded = sprintf(
                '%s: declares max_version %s, which excludes the framework it ships inside (%s).',
                $relative,
                $max,
                $version,
            );
            $findings[] = $excluded;
            $blocking[] = $excluded;
            $filesWithFindings[$relative] = true;
        }
    }

    if (array_key_exists('require', $manifest)) {
        $foreign = sprintf(
            "%s: carries a composer-style `require` key. ExtensionManifest does not read it, so whatever\n"
            . "      it names is enforced by nothing, while `pulsar.min_version` -- which is read -- says\n"
            . '      something of its own. Remove the key.',
            $relative,
        );
        $findings[] = $foreign;
        $blocking[] = $foreign;
        $filesWithFindings[$relative] = true;
    }

    foreach (['requires', 'suggests'] as $section) {
        /** @var mixed $entries */
        $entries = $manifest[$section] ?? null;

        if (!is_array($entries)) {
            continue;
        }

        /** @var mixed $constraint */
        foreach ($entries as $sibling => $constraint) {
            if (!is_string($sibling) || !str_starts_with($sibling, 'pulsar/') || !is_string($constraint)) {
                continue;
            }

            ++$sitesChecked;

            if (!array_key_exists($sibling, $bundled)) {
                $unknown = sprintf(
                    "%s: `%s` names %s, which this repository does not ship. The floor it should declare\n"
                    . "      cannot be derived from a package that does not exist, and a reader cannot act\n"
                    . '      on it either. Name a bundled extension, or drop the entry.',
                    $relative,
                    $section,
                    $sibling,
                );
                $findings[] = $unknown;
                $blocking[] = $unknown;
                $filesWithFindings[$relative] = true;

                continue;
            }

            $want = '>=' . $version;

            if ($constraint !== $want) {
                $findings[] = sprintf(
                    '%s: `%s` puts %s at %s, advertising a pairing that never shipped. In lockstep it is %s.',
                    $relative,
                    $section,
                    $sibling,
                    $constraint,
                    $want,
                );
                $filesWithFindings[$relative] = true;
                $updated = (string) preg_replace(
                    '~("' . preg_quote($sibling, '~') . '"\s*:\s*)"[^"]*"~',
                    '${1}"' . $want . '"',
                    $updated,
                    1,
                );
            }
        }
    }

    if ($updated !== $source) {
        $rewrites[$path] = $updated;
    }
}

// -- A tag pointing at HEAD is verified, never trusted -----------------------------

$taggedHead = 0;

foreach (version_gate_lines(version_gate_git($root, ['tag', '--points-at', 'HEAD'])[1]) as $tag) {
    if (preg_match('~^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$~', trim($tag), $tagParts) !== 1) {
        // backup/*, pre-rebase-* and friends name no release and claim no version.
        continue;
    }

    ++$taggedHead;
    ++$sitesChecked;

    if ($tagParts[1] !== $version) {
        $mismatched = sprintf(
            "tag %s points at HEAD while composer.json declares %s.\n"
            . "      Packagist would publish this commit as %s and every consumer would resolve that,\n"
            . '      while the tree, the manifests and Version::full() all say %s.',
            trim($tag),
            $version,
            $tagParts[1],
            $version,
        );
        $findings[] = $mismatched;
        $blocking[] = $mismatched;
    }
}

// -- Nothing else claims to be a release this is not -------------------------------
//
// The positive control comes first: if the search cannot see the repository it must say
// so, rather than report a tree with nothing wrong in it.

$control = version_gate_git($root, [
    'grep', '-n', '-I', '--no-color', '--untracked', '-F', $version, '--', VERSION_GATE_COMPOSER,
]);

if ($control[0] !== 0 || !str_contains($control[1], $version)) {
    fwrite(STDERR, sprintf(
        "version: the repository search cannot find %s in %s, the file it was just read from. The scan is\n"
        . "therefore looking at nothing, and its silence would mean nothing.\n",
        $version,
        VERSION_GATE_COMPOSER,
    ));

    exit(2);
}

$mentions = version_gate_git($root, [
    'grep', '-n', '-I', '--no-color', '--untracked', '-E', VERSION_GATE_RC_TAG, '--', '.', ':!*.lock',
])[1];

// `--untracked` walks the working directory, so on a case-insensitive filesystem the one
// file `docs/install.md` can be reported both under the casing git tracks and under the
// casing the disk holds. They are the same file, and the tracked name is the one every map
// and every other platform uses; resolving through this keeps the verdict identical on
// Windows and on the Linux runner, which a gate failing on only one of them would not be.
$tracked = [];

foreach (version_gate_lines(version_gate_git($root, ['ls-files'])[1]) as $trackedPath) {
    $tracked[strtolower($trackedPath)] = $trackedPath;
}

/** @var array<string, true> $narrated files whose mentions the record accounts for */
$narrated = [];

foreach (version_gate_lines($mentions) as $line) {
    $separator = strpos($line, ':');

    if ($separator === false) {
        continue;
    }

    $file = str_replace('\\', '/', substr($line, 0, $separator));
    $file = $tracked[strtolower($file)] ?? $file;
    $text = substr($line, $separator + 1);

    if (preg_match_all('~' . VERSION_GATE_RC_TAG . '~', $text, $named, PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }

    $occurrences = $named[0];
    $unaccounted = [];

    foreach ($occurrences as $occurrence) {
        // Both spellings of the current release, since both are scanned for.
        if ($occurrence[0] === $version || $occurrence[0] === $shortForm) {
            continue;
        }

        // A version the line introduces as the one something appeared in, or stopped
        // being recommended in, is history however recent it is. The offset is clamped
        // because PREG_OFFSET_CAPTURE types it as possibly -1 for a group that did not
        // participate, and a negative length would make substr() trim from the far end
        // and read the marker off an unrelated part of the line.
        $offset = $occurrence[1];
        $preceding = $offset > 0 ? substr($text, 0, $offset) : '';

        if (preg_match(VERSION_GATE_LINEAGE_MARKER, $preceding) === 1) {
            continue;
        }

        $unaccounted[] = $occurrence[0];
    }

    /** @var list<string> $tokens */
    $tokens = array_values(array_unique($unaccounted));

    if ($tokens === []) {
        continue;
    }

    if (str_contains('/' . $file, VERSION_GATE_TEST_SEGMENT)) {
        continue;
    }

    foreach (array_keys(VERSION_GATE_NARRATED_TREES) as $prefix) {
        if (str_starts_with($file, $prefix)) {
            continue 2;
        }
    }

    if (array_key_exists($file, VERSION_GATE_NARRATED_FILES)) {
        $narrated[$file] = true;

        continue;
    }

    // A file the passes above already reported has had its drift named once; saying it
    // again from a different angle would only bury the findings that are new.
    if (array_key_exists($file, $filesWithFindings)) {
        continue;
    }

    if ($file === VERSION_GATE_COMPOSER || array_key_exists($file, VERSION_GATE_DECLARED_SITES)
        || array_key_exists($file, $manifests)) {
        // Every version-bearing declaration in these files is judged above and agrees.
        // A mention that survives that is a sentence no map accounts for -- a stale
        // release named in a description, a comment, a table nobody declared.
        $finding = sprintf(
            '%s: names %s in prose the gate does not govern, and nothing records what that mention is for.',
            $file,
            implode(', ', $tokens),
        );
        $findings[] = $finding;
        $blocking[] = $finding;

        continue;
    }

    $finding = sprintf(
        "%s: names %s, which is not the current release, and this file is in neither map.\n"
        . "      Either the sentence claims which version this is -- declare it in\n"
        . "      VERSION_GATE_DECLARED_SITES so it is derived -- or it narrates a release that has been,\n"
        . '      and belongs in VERSION_GATE_NARRATED_FILES with the reason.',
        $file,
        implode(', ', $tokens),
    );
    $findings[] = $finding;
    $blocking[] = $finding;
}

// -- Verdict ------------------------------------------------------------------------
//
// There is deliberately no `if ($sitesChecked === 0) exit(2)` here. It was written first,
// as the usual guard against a scan reporting a clean tree because it reached nothing --
// and PHPStan proved it could never be true, the four constant patterns having already
// exited 2 or counted. A guard that cannot fire is the very thing this repository refuses
// to keep (ADR-0060), so it is gone rather than left standing to look reassuring. The two
// guards that CAN fire do the work: an empty manifest enumeration exits 2, and the search
// is made to find the current version in the file it was read from before its silence is
// allowed to mean anything.

if ($check) {
    if ($findings !== []) {
        fwrite(STDERR, sprintf(
            "version: %d declared version(s) disagree with composer.json (%s).\n\n  - %s\n\n"
            . "Run `composer version:sync` for the ones that can be derived; the rest name what to do.\n"
            . "composer.json's `version` is the single source -- see the header of %s for why it is a\n"
            . "field and not the git tag.\n\n",
            count($findings),
            $version,
            implode("\n  - ", $findings),
            VERSION_GATE_SELF,
        ));

        exit(1);
    }

    printf(
        "version: OK. %d declared version(s) across %d extension manifest(s) and %d documented file(s) all\n"
        . "say %s; %d file(s) narrate an earlier release on the record.%s\n",
        $sitesChecked,
        count($manifests),
        count(VERSION_GATE_DECLARED_SITES) + 1,
        $version,
        count($narrated),
        $taggedHead === 0 ? '' : sprintf(' %d release tag(s) on HEAD agree.', $taggedHead),
    );

    exit(0);
}

foreach ($rewrites as $path => $contents) {
    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "version: cannot write {$path}.\n");

        exit(2);
    }

    printf("  derived %s\n", substr($path, strlen($root) + 1));
}

if ($rewrites === []) {
    printf("version: nothing to derive; every declared version already says %s.\n", $version);
} else {
    printf(
        "version: derived %d file(s) from composer.json (%s). Run `pnpm format:fix` if a table changed width.\n",
        count($rewrites),
        $version,
    );
}

if ($blocking !== []) {
    fwrite(STDERR, sprintf(
        "\nversion: %d finding(s) no rewrite can repair, because each needs a decision:\n\n  - %s\n\n",
        count($blocking),
        implode("\n  - ", $blocking),
    ));

    exit(1);
}

exit(0);
