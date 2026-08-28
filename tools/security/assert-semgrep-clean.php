<?php

declare(strict_types=1);

/**
 * Turns the Semgrep ruleset into a gate that can actually fail a build.
 *
 * Until this script existed, `composer security:lint` invoked Semgrep with
 * neither `--error` nor `--severity`, so it exited 0 whatever it found, and no
 * workflow under .github/ invoked Semgrep at all. The ruleset's own header said
 * so in as many words: "a rule in this file cannot stop a merge". Findings
 * nothing acts on are not review material, they are decoration.
 *
 * Three things this script does that `semgrep --error` alone does not.
 *
 * 1. It fails on WARNING, not on ERROR. Every rule in the Pulsar ruleset is
 *    severity WARNING, so a threshold of ERROR would leave the gate inert while
 *    looking enforced -- the same defect one level up. The threshold is the
 *    lowest severity any rule uses, which is the only setting under which no
 *    rule can be written that the gate ignores. It is applied to the FINDINGS
 *    rather than passed to Semgrep as `--severity`, because that flag selects
 *    which rules run: `--severity=WARNING` would drop a future ERROR rule.
 *
 * 2. It ratchets against a recorded baseline instead of demanding a clean tree
 *    on day one. The tree currently carries 51 `hash('sha256', ...)` call sites
 *    outside the ruleset's RFC-interoperability allowlist. That is ADR-0006
 *    migration debt, stated as such in the rule's own comment and in the V6.2
 *    row of docs/security/asvs-l2-matrix.md -- SHA-256 is ASVS-approved, so it
 *    is debt rather than a control failure. Blocking every merge until all 51
 *    are migrated would produce a permanently red gate, which gets read exactly
 *    as often as a permanently green one. The baseline enumerates them so a
 *    reviewer can see the debt, and the gate rejects the fifty-second. Same
 *    instrument as tools/php/substitutability-baseline.json, same rule: a floor
 *    that only moves down. Regenerate it deliberately, never to silence a
 *    finding.
 *
 * 3. It fails when Semgrep could not parse a file. Measured with semgrep 1.155.0
 *    on this tree: 744 files produce a recoverable `PartialParsing` error -- the
 *    `readonly` class modifier, which its PHP frontend rejects -- and the
 *    recovery is genuine, the class body is still scanned (verified by planting
 *    a matching call inside a readonly class and seeing it reported). One file,
 *    src/Resilience/RetryPolicy.php, produces an unrecoverable `Syntax error`
 *    instead, and that file is scanned by nothing: a `hash('sha256', ...)`
 *    planted at line 132 of a copy of it was NOT reported. A file Semgrep cannot
 *    read is a hole in the gate, and a hole the gate does not mention is
 *    indistinguishable from coverage. Unparsed files are recorded in the
 *    baseline with a reason; a new one fails the build, and so does a recorded
 *    one whose reason was never written.
 *
 * Exit codes:
 *   0  no finding outside the baseline, no unrecorded blind spot
 *   1  a new finding, or a new file Semgrep cannot parse
 *   2  Semgrep could not be run, or did not produce readable output -- never
 *      reported as a pass, because "the tool was missing" and "the tool found
 *      nothing" must not look alike from the outside
 *
 * Usage:
 *   php tools/security/assert-semgrep-clean.php
 *   php tools/security/assert-semgrep-clean.php --generate-baseline
 *   php tools/security/assert-semgrep-clean.php --severity=ERROR
 *   php tools/security/assert-semgrep-clean.php --semgrep=/path/to/semgrep
 *   php tools/security/assert-semgrep-clean.php --report=report.json --baseline=b.json
 *
 * `--report` reads a Semgrep JSON document already produced instead of running
 * Semgrep, and `--baseline` points at a different baseline file. Together they are
 * how this script's judgement is tested without a Semgrep install: the part worth
 * testing is what it concludes from a report, not that a subprocess starts.
 */

/** Severity names in ascending order of seriousness. */
const SEMGREP_SEVERITIES = ['INFO', 'WARNING', 'ERROR'];

/**
 * The lowest severity any rule in the Pulsar ruleset uses.
 *
 * Not a tuning knob. Raising it above the lowest severity present re-creates the
 * defect this script exists to remove: rules that run and cannot fail anything.
 */
const SEMGREP_DEFAULT_SEVERITY = 'WARNING';

const SEMGREP_RULESET = 'tools/security/semgrep-pulsar-rules.yml';
const SEMGREP_BASELINE = 'tools/security/semgrep-baseline.json';

/** First-party source. Neither vendor/ nor tests/ is scanned. */
const SEMGREP_TARGETS = ['src', 'extensions'];

/**
 * Normalises a path Semgrep reported into a repository-relative POSIX path.
 *
 * Semgrep emits the separator of the host, so the same finding fingerprints
 * differently on Windows and Linux unless this runs first -- which would make
 * the baseline unusable on one of the two platforms the project supports.
 */
function semgrep_normalise_path(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    $rootPrefix = str_replace('\\', '/', $root) . '/';

    if (str_starts_with($path, $rootPrefix)) {
        $path = substr($path, strlen($rootPrefix));
    }

    return ltrim($path, '/');
}

/**
 * Collapses the matched source text so trivial reformatting does not churn the
 * baseline, while a genuinely different call still fingerprints differently.
 */
function semgrep_normalise_snippet(string $snippet): string
{
    $collapsed = preg_replace('/\s+/', ' ', $snippet);

    return trim(is_string($collapsed) ? $collapsed : $snippet);
}

/**
 * Identity of a finding, deliberately excluding the line number.
 *
 * Line numbers move whenever anything above them is edited, and a baseline that
 * churns on unrelated edits is one people regenerate reflexively -- the one
 * habit that turns a ratchet back into a rubber stamp.
 */
function semgrep_fingerprint(string $rule, string $path, string $snippet): string
{
    return substr(hash('sha256', $rule . "\0" . $path . "\0" . $snippet), 0, 32);
}

/**
 * Runs Semgrep and returns its decoded JSON report, or null when it could not
 * be produced.
 *
 * @param list<string> $targets
 *
 * @return array<string, mixed>|null
 */
function semgrep_run(string $binary, string $root, array $targets, string &$diagnostic): ?array
{
    $outputFile = tempnam(sys_get_temp_dir(), 'pulsar-semgrep-');

    if ($outputFile === false) {
        $diagnostic = 'Could not create a temporary file for the Semgrep report.';

        return null;
    }

    // Config and targets are passed RELATIVE, with the repository root as the working
    // directory, because both are load-bearing. The rule ids Semgrep reports are derived
    // from the config path, and the `paths.exclude` globs in the ruleset
    // (`src/Cache/**`, `extensions/*/tests/**`, ...) are matched against the target path
    // as given. Passing absolute paths changes which files those globs exclude -- measured
    // on this tree, it silently changed the finding count.
    $command = [
        $binary,
        '--config=' . SEMGREP_RULESET,
        '--json',
        '--output=' . $outputFile,
        '--metrics=off',
        '--disable-version-check',
        '--quiet',
        // Single-threaded, and this is the difference between a gate and a coin toss.
        // Semgrep's PHP frontend hits a recoverable parse error on every `readonly class`
        // in the tree, and under its default parallel scheduling the recovery is not
        // deterministic: three full runs reported 745, 740 and 737 parse errors, and one
        // of them silently dropped a real finding
        // (extensions/payments/.../NullProvider.php:116) that the others reported. Two
        // consecutive `--jobs=1` runs were byte-identical -- same 51 findings, same 745
        // errors. A gate whose answer changes between runs teaches people to re-run it
        // until it is green, which is worse than not having it. The cost is about 4.5
        // minutes instead of 2.
        '--jobs=1',
        ...$targets,
    ];

    $descriptors = [
        0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, $root);

    if (!is_resource($process)) {
        @unlink($outputFile);
        $diagnostic = sprintf(
            "Could not start Semgrep (tried '%s').\n"
            . "Install it with `python -m pip install semgrep`, or point the gate at an existing\n"
            . "install with SEMGREP_BIN=/path/to/semgrep or --semgrep=/path/to/semgrep.\n"
            . 'A missing tool is reported as an error rather than a pass on purpose.',
            $binary,
        );

        return null;
    }

    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    $raw = @file_get_contents($outputFile);
    @unlink($outputFile);

    // 0 is a clean run and 1 is "findings were reported"; both produce a report.
    // Anything else means Semgrep itself failed, and its own stderr says more
    // about why than this script could.
    if ($status > 1 || !is_string($raw) || $raw === '') {
        $diagnostic = sprintf("Semgrep exited %d without a usable report.\n%s", $status, trim($stderr));

        return null;
    }

    try {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $diagnostic = 'Semgrep produced output this gate could not decode: ' . $exception->getMessage();

        return null;
    }

    return $decoded;
}

/**
 * Reads a Semgrep JSON document already produced, instead of running Semgrep.
 *
 * @return array<string, mixed>|null
 */
function semgrep_read_report(string $path, string &$diagnostic): ?array
{
    $raw = @file_get_contents($path);

    if (!is_string($raw)) {
        $diagnostic = sprintf('Could not read the Semgrep report at %s.', $path);

        return null;
    }

    try {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $diagnostic = sprintf('%s is not a readable Semgrep report: %s', $path, $exception->getMessage());

        return null;
    }

    return $decoded;
}

/**
 * The findings at or above the threshold, keyed by fingerprint and counted.
 *
 * Counted rather than merely listed: two identical calls in one file share a
 * fingerprint, and without the count the second would inherit the first one's
 * baseline entry and never be seen.
 *
 * @param array<string, mixed> $report
 *
 * @return array<string, array{rule: string, path: string, snippet: string, count: int, lines: list<int>}>
 */
function semgrep_collect_findings(array $report, string $root, string $severity): array
{
    $threshold = (int) array_search($severity, SEMGREP_SEVERITIES, true);
    $results = is_array($report['results'] ?? null) ? $report['results'] : [];
    $collected = [];

    foreach ($results as $result) {
        if (!is_array($result)) {
            continue;
        }

        $extra = is_array($result['extra'] ?? null) ? $result['extra'] : [];
        $found = is_string($extra['severity'] ?? null) ? $extra['severity'] : SEMGREP_DEFAULT_SEVERITY;
        $rank = array_search($found, SEMGREP_SEVERITIES, true);

        if (is_int($rank) && $rank < $threshold) {
            continue;
        }

        $rule = is_string($result['check_id'] ?? null) ? $result['check_id'] : '(unnamed rule)';
        $path = semgrep_normalise_path(is_string($result['path'] ?? null) ? $result['path'] : '', $root);
        $snippet = semgrep_normalise_snippet(is_string($extra['lines'] ?? null) ? $extra['lines'] : '');
        $start = is_array($result['start'] ?? null) ? $result['start'] : [];
        $line = is_int($start['line'] ?? null) ? $start['line'] : 0;

        $fingerprint = semgrep_fingerprint($rule, $path, $snippet);
        $existing = $collected[$fingerprint] ?? null;

        // Each entry is rebuilt whole rather than mutated through a nested offset.
        // The types are then provable rather than merely true, which matters for a
        // file PHPStan analyses at level max.
        $collected[$fingerprint] = [
            'rule' => $rule,
            'path' => $path,
            'snippet' => $snippet,
            'count' => ($existing['count'] ?? 0) + 1,
            'lines' => [...($existing['lines'] ?? []), $line],
        ];
    }

    ksort($collected);

    return $collected;
}

/**
 * Files Semgrep abandoned entirely, which are therefore scanned by no rule.
 *
 * `PartialParsing` is excluded: it is recoverable and the rest of the file is
 * still matched. Only the unrecoverable class of error is a blind spot.
 *
 * @param array<string, mixed> $report
 *
 * @return list<string>
 */
function semgrep_collect_unparsed(array $report, string $root): array
{
    $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
    $unparsed = [];

    foreach ($errors as $error) {
        if (!is_array($error)) {
            continue;
        }

        $type = $error['type'] ?? null;
        $name = is_array($type) ? ($type[0] ?? null) : $type;

        if (!is_string($name) || $name === 'PartialParsing') {
            continue;
        }

        $path = is_string($error['path'] ?? null) ? $error['path'] : null;

        if ($path === null) {
            continue;
        }

        $unparsed[] = semgrep_normalise_path($path, $root);
    }

    $unparsed = array_values(array_unique($unparsed));
    sort($unparsed);

    return $unparsed;
}

/**
 * The Semgrep version string, recorded in the baseline.
 *
 * Which files its PHP frontend can parse changes between releases, so a baseline
 * whose blind-spot list was recorded under a different version deserves to be
 * re-measured rather than trusted.
 */
function semgrep_version(string $binary, string $root): string
{
    $descriptors = [
        0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open([$binary, '--version'], $descriptors, $pipes, $root);

    if (!is_resource($process)) {
        return 'unknown';
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $version = trim($stdout);

    return $version === '' ? 'unknown' : $version;
}

/**
 * @return array{findings: array<string, array{rule: string, path: string, snippet: string, count: int}>, unparsed: array<string, string>, version: string}|null
 */
function semgrep_load_baseline(string $path): ?array
{
    $raw = @file_get_contents($path);

    if (!is_string($raw)) {
        return null;
    }

    try {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    $findings = [];
    $rawFindings = is_array($decoded['findings'] ?? null) ? $decoded['findings'] : [];

    foreach ($rawFindings as $entry) {
        if (!is_array($entry) || !is_string($entry['fingerprint'] ?? null)) {
            continue;
        }

        $findings[$entry['fingerprint']] = [
            'rule' => is_string($entry['rule'] ?? null) ? $entry['rule'] : '',
            'path' => is_string($entry['path'] ?? null) ? $entry['path'] : '',
            'snippet' => is_string($entry['snippet'] ?? null) ? $entry['snippet'] : '',
            'count' => is_int($entry['count'] ?? null) ? $entry['count'] : 1,
        ];
    }

    $unparsed = [];
    $rawUnparsed = is_array($decoded['unparsed'] ?? null) ? $decoded['unparsed'] : [];

    foreach ($rawUnparsed as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
            continue;
        }

        $unparsed[$entry['path']] = is_string($entry['reason'] ?? null) ? $entry['reason'] : '';
    }

    return [
        'findings' => $findings,
        'unparsed' => $unparsed,
        'version' => is_string($decoded['semgrep_version'] ?? null) ? $decoded['semgrep_version'] : 'unknown',
    ];
}

/**
 * @param array<string, array{rule: string, path: string, snippet: string, count: int, lines: list<int>}> $findings
 * @param list<string>                                                                                    $unparsed
 * @param array<string, string>                                                                           $reasons  Existing justifications, preserved across regeneration
 */
function semgrep_write_baseline(
    string $path,
    string $version,
    string $severity,
    array $findings,
    array $unparsed,
    array $reasons,
): bool {
    $entries = [];

    foreach ($findings as $fingerprint => $finding) {
        $entries[] = [
            'fingerprint' => $fingerprint,
            'rule' => $finding['rule'],
            'path' => $finding['path'],
            'count' => $finding['count'],
            'snippet' => mb_substr($finding['snippet'], 0, 180),
        ];
    }

    $blindSpots = [];

    foreach ($unparsed as $file) {
        $blindSpots[] = [
            'path' => $file,
            // A justification already written by a human is never overwritten by a
            // regeneration; only a newly appearing file gets the placeholder, and the
            // placeholder is what tells a reviewer one is owed.
            'reason' => $reasons[$file] ?? 'UNJUSTIFIED: state why this file cannot be parsed and what that hides.',
        ];
    }

    $document = [
        'semgrep_version' => $version,
        'ruleset' => SEMGREP_RULESET,
        'severity_threshold' => $severity,
        'targets' => SEMGREP_TARGETS,
        'findings' => $entries,
        'unparsed' => $blindSpots,
    ];

    $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!is_string($encoded)) {
        return false;
    }

    return file_put_contents($path, $encoded . "\n") !== false;
}

/** @var list<string> $arguments */
$arguments = array_values(array_filter($argv ?? [], 'is_string'));
array_shift($arguments);

$root = str_replace('\\', '/', dirname(__DIR__, 2));
$generateBaseline = false;
$severity = SEMGREP_DEFAULT_SEVERITY;
$environmentBinary = getenv('SEMGREP_BIN');
$binary = is_string($environmentBinary) && $environmentBinary !== '' ? $environmentBinary : 'semgrep';
$reportPath = null;
$baselinePath = $root . '/' . SEMGREP_BASELINE;

foreach ($arguments as $argument) {
    if ($argument === '--generate-baseline') {
        $generateBaseline = true;

        continue;
    }

    if (str_starts_with($argument, '--severity=')) {
        $severity = strtoupper(substr($argument, strlen('--severity=')));

        continue;
    }

    if (str_starts_with($argument, '--semgrep=')) {
        $binary = substr($argument, strlen('--semgrep='));

        continue;
    }

    if (str_starts_with($argument, '--report=')) {
        $reportPath = substr($argument, strlen('--report='));

        continue;
    }

    if (str_starts_with($argument, '--baseline=')) {
        $baselinePath = substr($argument, strlen('--baseline='));

        continue;
    }

    fwrite(STDERR, sprintf(
        "Unrecognised argument: %s\n"
        . "Usage: php tools/security/assert-semgrep-clean.php [--generate-baseline] [--severity=LEVEL]\n"
        . "                                                   [--semgrep=PATH] [--report=PATH] [--baseline=PATH]\n",
        $argument,
    ));

    exit(2);
}

if (!in_array($severity, SEMGREP_SEVERITIES, true)) {
    fwrite(STDERR, sprintf(
        "Unknown severity '%s'. Expected one of: %s\n",
        $severity,
        implode(', ', SEMGREP_SEVERITIES),
    ));

    exit(2);
}

$diagnostic = '';
$report = $reportPath === null
    ? semgrep_run($binary, $root, SEMGREP_TARGETS, $diagnostic)
    : semgrep_read_report($reportPath, $diagnostic);

if ($report === null) {
    fwrite(STDERR, "semgrep-gate: could not obtain a report.\n\n" . $diagnostic . "\n");

    exit(2);
}

$findings = semgrep_collect_findings($report, $root, $severity);
$unparsed = semgrep_collect_unparsed($report, $root);

if ($generateBaseline) {
    $existing = semgrep_load_baseline($baselinePath);
    $written = semgrep_write_baseline(
        $baselinePath,
        semgrep_version($binary, $root),
        $severity,
        $findings,
        $unparsed,
        $existing['unparsed'] ?? [],
    );

    if (!$written) {
        fwrite(STDERR, sprintf("semgrep-gate: could not write %s\n", $baselinePath));

        exit(2);
    }

    printf(
        "semgrep-gate: baseline written with %d finding group(s) and %d unparsed file(s).\n",
        count($findings),
        count($unparsed),
    );

    exit(0);
}

$baseline = semgrep_load_baseline($baselinePath);

if ($baseline === null) {
    fwrite(STDERR, sprintf(
        "semgrep-gate: %s is missing or unreadable.\n"
        . "Without it the gate cannot tell a pre-existing finding from a new one, and an\n"
        . "unknown answer is not a pass. Generate it with `composer security:lint:baseline`.\n",
        SEMGREP_BASELINE,
    ));

    exit(2);
}

$newFindings = [];
$resolved = [];

foreach ($findings as $fingerprint => $finding) {
    $allowed = $baseline['findings'][$fingerprint]['count'] ?? 0;

    if ($finding['count'] > $allowed) {
        $newFindings[] = $finding + ['fingerprint' => $fingerprint, 'allowed' => $allowed];
    }
}

foreach ($baseline['findings'] as $fingerprint => $entry) {
    if (!array_key_exists($fingerprint, $findings)) {
        $resolved[] = $entry;
    }
}

$newBlindSpots = array_values(array_filter(
    $unparsed,
    static fn(string $file): bool => !array_key_exists($file, $baseline['unparsed']),
));

$unjustified = [];

foreach ($baseline['unparsed'] as $file => $reason) {
    if (in_array($file, $unparsed, true) && str_starts_with($reason, 'UNJUSTIFIED')) {
        $unjustified[] = $file;
    }
}

$failed = false;

if ($newFindings !== []) {
    $failed = true;
    fwrite(STDERR, sprintf("semgrep-gate: %d finding(s) not present in the baseline.\n\n", count($newFindings)));

    foreach ($newFindings as $finding) {
        fwrite(STDERR, sprintf(
            "  %s\n    %s:%s\n    %s\n    baseline allows %d occurrence(s) of this exact match, found %d\n\n",
            $finding['rule'],
            $finding['path'],
            implode(',', array_map(strval(...), $finding['lines'])),
            mb_substr($finding['snippet'], 0, 160),
            $finding['allowed'],
            $finding['count'],
        ));
    }

    fwrite(
        STDERR,
        "Fix the finding, or -- if it is genuinely a false positive -- suppress it at the call\n"
        . "site with `// nosemgrep: <rule-id> -- <reason>` so the reason sits next to the code it\n"
        . "excuses. Do not regenerate the baseline to make this pass: the baseline records debt\n"
        . "that predates the gate, and it only moves down.\n\n",
    );
}

if ($newBlindSpots !== []) {
    $failed = true;
    fwrite(STDERR, sprintf(
        "semgrep-gate: %d file(s) Semgrep could not parse at all, and which no rule therefore\n"
        . "examined. These are holes in this gate, not clean files.\n\n  - %s\n\n"
        . "Either restructure the file so Semgrep's PHP frontend accepts it, or record it in\n"
        . "%s with a reason stating what the hole hides.\n\n",
        count($newBlindSpots),
        implode("\n  - ", $newBlindSpots),
        SEMGREP_BASELINE,
    ));
}

if ($unjustified !== []) {
    $failed = true;
    fwrite(STDERR, sprintf(
        "semgrep-gate: %d baselined blind spot(s) still carry the placeholder reason.\n\n  - %s\n\n"
        . "A recorded hole with no stated justification is a hole nobody decided to accept.\n\n",
        count($unjustified),
        implode("\n  - ", $unjustified),
    ));
}

if ($resolved !== []) {
    // Not a failure: a contributor who fixes a call site should not also be made to
    // regenerate a file to get green. But it is printed loudly, because a baseline
    // that is never pruned stops being a floor and becomes a permission slip.
    printf(
        "semgrep-gate: %d baselined finding(s) no longer match. The debt has shrunk -- run\n"
        . "`composer security:lint:baseline` and commit the smaller baseline.\n",
        count($resolved),
    );
}

if ($failed) {
    exit(1);
}

$total = array_sum(array_map(static fn(array $finding): int => $finding['count'], $findings));

printf(
    "semgrep-gate: OK. %d finding(s) at or above %s, all recorded in the baseline; %d unparsed file(s), all recorded.\n",
    $total,
    $severity,
    count($unparsed),
);

exit(0);
