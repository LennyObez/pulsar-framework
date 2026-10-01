<?php

declare(strict_types=1);

/**
 * Fails the build when a document states a mutation-testing threshold that
 * infection.json5 does not enforce.
 *
 * THE DISAGREEMENT THIS EXISTS FOR. Three sources described the Infection gate
 * and no two agreed:
 *
 *   - infection.json5 configures `minCoveredMsi: 90` and no `minMsi` at all.
 *   - docs/adr/0042-coverage-and-mutation-are-bounded-by-memory.md says "Covered
 *     MSI is enforced at 90; plain MSI is not, because it counts mutants no test
 *     reaches and the test scope is narrowed on purpose." That is correct.
 *   - docs/testing.md said "MSI ... Minimum: 80%" and docs/prd-1.0.0.md said
 *     "CI gate: ... Infection MSI >= 80". Both describe a plain-MSI gate that has
 *     never existed. A contributor reading either would believe a number was
 *     being enforced that nothing enforces, and -- the part that matters -- would
 *     read a green mutation run as evidence for it.
 *
 * The prose was aligned to the configuration rather than the configuration to the
 * prose, and deliberately: plain MSI counts mutants no test reaches, `source` is
 * narrowed to src/Auth, src/Security and src/Audit because Infection's initial run
 * does not fit a runner's memory otherwise, and a plain-MSI floor over a
 * deliberately narrowed scope fails for reasons that have nothing to do with test
 * quality. ADR-0042 argues that; this script is what stops the argument being
 * quietly relitigated in a documentation edit.
 *
 * WHAT IT CHECKS. Every Markdown file in the repository is read. On any line that
 * mentions MSI, every percentage-shaped threshold is extracted and required to be
 * the configured one:
 *
 *   - a line that also says "covered" is a claim about `minCoveredMsi` and must
 *     name its value;
 *   - a line that does not is a claim about plain MSI, which is refused outright
 *     while `minMsi` is unset, and required to match when it is set.
 *
 * That second rule is the one with teeth. Setting `minMsi` in infection.json5
 * makes the sentences in docs/testing.md legal again, so this gate does not
 * enforce a preference -- it enforces that the documents and the configuration are
 * one statement. Change either and the build says so.
 *
 * THE LINE IS THE UNIT, and that is a constraint on how to write rather than a
 * limitation to work around. A sentence that mixes a coverage floor with an MSI
 * mention -- "line coverage >= 80%, Infection MSI >= 80" -- has both numbers read
 * as MSI thresholds, because nothing in the text says which belongs to which.
 * Splitting it is the fix, and the refusal below quotes the line so the writer can
 * see why. Any narrower rule would be guessing at which number a clause is about,
 * and a gate that guesses is a gate whose failures get overridden.
 *
 * WHY THE CORPUS COMES FROM GIT, AND WHY IT INCLUDES UNTRACKED FILES. `git
 * ls-files --cached --others --exclude-standard` is every file git considers part
 * of this repository: committed ones and new ones nobody has added yet, minus
 * everything .gitignore excludes. A plain filesystem walk was tried first and
 * takes minutes on a synced working copy, which for a gate that runs on every
 * `composer qa` is the difference between a gate people run and one they skip.
 * `--others` is not optional: this repository has already been bitten by a ratchet
 * whose search skipped untracked files, so it was green on a branch it would have
 * refused the moment that branch was committed. A new ADR is untracked for exactly
 * as long as it takes to review it, which is when it is being read.
 *
 * RECORDS ARE EXCLUDED, AND THE EXCLUSIONS ARE CHECKED. CHANGELOG.md and
 * ROADMAP.md say what a past release enforced; rewriting either to today's figure
 * would falsify the record rather than fix a claim. The dated audit memos under
 * docs/audit/ need no entry: .gitignore already keeps them out of the corpus.
 * tools/version/sync-version.php excludes the release history for the same reason.
 * An excluded path that no longer exists is itself a failure: the next file to
 * take the name would inherit the exemption unread.
 *
 * A CORPUS THAT MENTIONS MSI NOWHERE IS A FAILURE TOO. Otherwise the way to
 * satisfy this gate is to delete the sentences, which is the failure mode it was
 * written to close one level up.
 *
 * Exit codes:
 *   0  every documented threshold matches infection.json5
 *   1  a document states a threshold the configuration does not enforce
 *   2  infection.json5, git or the corpus could not be read -- never a pass
 *
 * Usage:
 *   php tools/ci/assert-mutation-thresholds.php [--root=DIR]
 */

const CONFIG_RELATIVE = 'infection.json5';

/**
 * Paths whose MSI figures are a record of what was true on a date, not a claim
 * about the gate that runs today. A trailing slash means "everything under here".
 */
const RECORDS = ['CHANGELOG.md', 'ROADMAP.md'];

/**
 * @param array<string, list<string>|string|false> $options
 */
$stringOption = static function (array $options, string $name, string $default): string {
    $value = $options[$name] ?? null;

    return is_string($value) ? $value : $default;
};

/**
 * Run a command in the working tree and return its output, or null if it failed.
 *
 * argv arrives variadically rather than as an array so the list shape is a
 * property of the signature. `array_values` is still applied, because unpacking a
 * variadic does not guarantee a list — a named argument would give it a string key
 * and proc_open would then receive a map it cannot execute.
 */
$run = static function (string $cwd, string ...$command): ?string {
    $process = proc_open(
        array_values($command),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
    );

    if (!is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 ? $stdout : null;
};

/**
 * Read one integer setting out of infection.json5.
 *
 * JSON5 with `//` comments and unquoted keys is not JSON, and pulling in a parser
 * for two integers would be a dependency this gate does not need. The keys are
 * matched directly, and a key that appears twice is refused rather than resolved:
 * two values for one threshold is not a state with a right answer.
 */
$intSetting = static function (string $document, string $key): ?int {
    $found = preg_match_all('/^\s*' . preg_quote($key, '/') . '\s*:\s*(\d+)\s*,?\s*$/mi', $document, $matches);

    if ($found === false || $found === 0) {
        return null;
    }

    if ($found > 1) {
        fwrite(STDERR, sprintf(
            '%s declares %s %d times. Two values for one threshold is not a configuration this '
            . "gate can compare a document against.\n",
            CONFIG_RELATIVE,
            $key,
            $found,
        ));

        exit(2);
    }

    return (int) $matches[1][0];
};

/**
 * Every percentage-shaped threshold stated on one line.
 *
 * Deliberately narrow. A number counts only when it is written as a percentage
 * (`80%`) or introduced by a threshold marker (`>= 80`, `at 90`, `minimum: 90`).
 * Without that bound, "the 3,625 tests that cover them" on an MSI-mentioning line
 * would be read as a threshold of 3 and the gate would fail for a reason nobody
 * could act on.
 *
 * Decimals are skipped: "the run reported 93.4% MSI" is a measurement somebody
 * recorded, not a floor anybody set.
 *
 * @return list<int>
 */
$thresholdsOn = static function (string $line): array {
    // Two alternatives, and the exclusions on each are load-bearing:
    //
    //   - a marker followed by a number. `(?!\d|\.\d|%)` rejects a longer number and
    //     a decimal, but NOT a sentence-ending full stop -- "the gate is set at 70."
    //     is a claim, and an earlier version of this pattern read the period as a
    //     decimal point and let it through, which is a gate declining to see the one
    //     sentence shape a contributor is most likely to write.
    //   - a number written as a percentage, which needs no marker. The first
    //     alternative excludes a trailing `%` so `Minimum: 80%` is counted once
    //     rather than twice.
    $pattern = '/(?:>=|\x{2265}|>|at least|at|minimum(?:\s+of)?|min|minmsi|mincoveredmsi)\s*:?\s*(?<![\d.])(\d{1,3})(?!\d|\.\d|%)'
        . '|(?<![\d.])(\d{1,3})\s*%/iu';

    if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

    $thresholds = [];

    foreach ($matches as $match) {
        $value = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');

        if ($value === '') {
            continue;
        }

        $thresholds[] = (int) $value;
    }

    return $thresholds;
};

$options = getopt('', ['root:']);

if ($options === false) {
    fwrite(STDERR, "Could not parse command-line options.\n");

    exit(2);
}

$root = rtrim(str_replace('\\', '/', $stringOption($options, 'root', dirname(__DIR__, 2))), '/');
$configPath = $root . '/' . CONFIG_RELATIVE;

if (!is_file($configPath)) {
    fwrite(STDERR, sprintf(
        "%s does not exist, so there is no configured threshold for any document to agree with.\n",
        CONFIG_RELATIVE,
    ));

    exit(2);
}

$config = file_get_contents($configPath);

if ($config === false) {
    fwrite(STDERR, sprintf("Could not read %s.\n", CONFIG_RELATIVE));

    exit(2);
}

$minCoveredMsi = $intSetting($config, 'minCoveredMsi');
$minMsi = $intSetting($config, 'minMsi');

if ($minCoveredMsi === null && $minMsi === null) {
    fwrite(STDERR, sprintf(
        "%s sets neither minCoveredMsi nor minMsi.\n\nInfection then reports a score and exits 0 "
        . 'whatever it is, so `composer mutation` becomes a long way of producing a log file. '
        . 'Every sentence in the documentation describing a mutation gate would be describing '
        . 'nothing, which is the disagreement this gate exists to end -- so an unthresholded '
        . "configuration is refused rather than treated as a corpus with nothing to check.\n",
        CONFIG_RELATIVE,
    ));

    exit(2);
}

foreach (RECORDS as $record) {
    $path = $root . '/' . rtrim($record, '/');

    if (!file_exists($path)) {
        fwrite(STDERR, sprintf(
            "%s is excluded from this scan as a dated record, and does not exist.\n\nAn exclusion "
            . 'naming a path that is gone grants a permission nobody is using and hides that the '
            . 'list was written once and never revisited -- and the next file to take the name is '
            . "exempt before anyone has read it.\n",
            $record,
        ));

        exit(2);
    }
}

$listing = $run($root, 'git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', '*.md');

if ($listing === null) {
    fwrite(STDERR, sprintf(
        "Could not list the Markdown files in %s with git.\n\nThe corpus is not guessed at when git "
        . 'cannot supply it: a partial listing would make this gate pass over the documents it '
        . "could not see, which is indistinguishable from those documents agreeing.\n",
        $root,
    ));

    exit(2);
}

/** @var list<string> $documents */
$documents = [];

foreach (explode("\0", $listing) as $entry) {
    $entry = trim($entry);

    if ($entry === '') {
        continue;
    }

    foreach (RECORDS as $record) {
        if ($entry === $record || (str_ends_with($record, '/') && str_starts_with($entry, $record))) {
            continue 2;
        }
    }

    $documents[] = $entry;
}

if ($documents === []) {
    fwrite(STDERR, sprintf(
        'git listed no Markdown files under %s at all, so this gate would pass over an empty '
        . "corpus.\n",
        $root,
    ));

    exit(2);
}

sort($documents);

$violations = [];
$linesMentioningMsi = 0;

foreach ($documents as $relative) {
    $contents = file_get_contents($root . '/' . $relative);

    if ($contents === false) {
        fwrite(STDERR, sprintf("Could not read %s.\n", $relative));

        exit(2);
    }

    foreach (explode("\n", str_replace("\r\n", "\n", $contents)) as $number => $line) {
        if (preg_match('/\bMSI\b/i', $line) !== 1) {
            continue;
        }

        ++$linesMentioningMsi;

        $isCovered = stripos($line, 'covered') !== false;
        $expected = $isCovered ? $minCoveredMsi : $minMsi;

        foreach ($thresholdsOn($line) as $stated) {
            if ($expected === $stated) {
                continue;
            }

            $violations[] = sprintf(
                "%s:%d states a %s MSI threshold of %d.\n      %s\n      %s",
                $relative,
                $number + 1,
                $isCovered ? 'covered' : 'plain',
                $stated,
                trim($line),
                $expected === null
                    ? sprintf(
                        '%s configures no %s, so nothing enforces that number.',
                        CONFIG_RELATIVE,
                        $isCovered ? 'minCoveredMsi' : 'minMsi',
                    )
                    : sprintf(
                        '%s configures %s: %d.',
                        CONFIG_RELATIVE,
                        $isCovered ? 'minCoveredMsi' : 'minMsi',
                        $expected,
                    ),
            );
        }
    }
}

if ($linesMentioningMsi === 0) {
    fwrite(STDERR, sprintf(
        "No document mentions MSI at all.\n\nThe mutation gate is then configured in %s and "
        . 'described nowhere, and this check passes by having nothing to check -- the same shape '
        . 'as the disagreement it was written to end. Say what the gate enforces in '
        . "docs/testing.md.\n",
        CONFIG_RELATIVE,
    ));

    exit(1);
}

if ($violations !== []) {
    fwrite(STDERR, sprintf(
        "The documentation states mutation thresholds that %s does not enforce:\n\n  - %s\n\n"
        . 'A contributor reading one of these believes a number is being enforced that nothing '
        . "enforces, and reads a green mutation run as evidence for it.\n\nFix the sentence, or "
        . 'set the threshold in %s so the sentence becomes true. Both are legitimate; what is not '
        . 'is leaving the two disagreeing. docs/adr/0042-coverage-and-mutation-are-bounded-by-'
        . "memory.md sets out why plain MSI is deliberately unset.\n\nIf the quoted line states a "
        . 'threshold for something OTHER than MSI -- a coverage floor, say -- split the sentence. '
        . 'Every number on a line that mentions MSI is read as an MSI threshold, because nothing '
        . "in the text says which clause a number belongs to.\n",
        CONFIG_RELATIVE,
        implode("\n\n  - ", $violations),
        CONFIG_RELATIVE,
    ));

    exit(1);
}

fprintf(
    STDOUT,
    "mutation thresholds: OK (%d %s across %d documents agree with %s: minCoveredMsi %s, minMsi %s)\n",
    $linesMentioningMsi,
    $linesMentioningMsi === 1 ? 'line' : 'lines',
    count($documents),
    CONFIG_RELATIVE,
    $minCoveredMsi === null ? 'unset' : (string) $minCoveredMsi,
    $minMsi === null ? 'unset' : (string) $minMsi,
);

exit(0);
