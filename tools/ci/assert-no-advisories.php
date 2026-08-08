<?php

declare(strict_types=1);

/**
 * Assert that a dependency audit report contains no advisory, at any severity.
 *
 * One script covers both halves of the gate — `composer audit --format=json`
 * and `pnpm audit --json` — so the two ecosystems cannot drift into different
 * levels of strictness. They had: the PHP half was moved here to fail closed
 * while the JS half stayed inline in the workflow, summing `jq '... // 0'`
 * through a shell arithmetic expansion. When the audit command failed for any
 * reason — registry error, auth failure, a renamed flag — the capture was
 * empty, every jq call yielded an empty string, `$((...))` coerced those to 0,
 * and the step announced "no vulnerability advisories found" for an audit that
 * had never run.
 *
 * Both halves now fail CLOSED. A missing file, a truncated write, output that
 * is not JSON, and a report whose shape changed under us are each distinguished
 * from a clean tree, and each stops the build.
 *
 * Keeping the parsing here rather than in an inline `php -r` snippet also means
 * `composer security:audit` and `composer security:audit:js` run the identical
 * check locally, and PHPStan sees the code.
 *
 * Usage:
 *   php tools/ci/assert-no-advisories.php [--ecosystem=composer|pnpm] [report.json]
 *
 * Exit codes: 0 no advisories, 1 advisories found, 2 the report is unusable.
 */

const ADVISORY_GATE_USAGE = 'Usage: php tools/ci/assert-no-advisories.php [--ecosystem=composer|pnpm] [report.json]';

/**
 * The gate could not read the report.
 *
 * Exit 2 is deliberately distinct from exit 1: "we could not tell" must never be
 * mistaken for "we looked and the tree is clean".
 */
function advisoryGateUnusable(string $message): never
{
    fwrite(STDERR, $message . "\n");

    exit(2);
}

/**
 * Read a non-empty string field out of a decoded advisory.
 */
function advisoryStringField(mixed $advisory, string $key, string $fallback): string
{
    if (!is_array($advisory)) {
        return $fallback;
    }

    /** @var mixed $value */
    $value = $advisory[$key] ?? null;

    return is_string($value) && $value !== '' ? $value : $fallback;
}

/**
 * `composer audit --format=json` emits `advisories` as package => list of issues.
 *
 * @param array<mixed> $data
 * @return list<string>
 */
function composerAdvisoryLines(array $data, string $path): array
{
    // Composer always emits an `advisories` key, so its absence means the shape
    // changed under us rather than that the tree is clean.
    if (!array_key_exists('advisories', $data)) {
        advisoryGateUnusable(sprintf(
            'Audit report "%s" has no "advisories" key; composer\'s output format has changed.',
            $path,
        ));
    }

    /** @var mixed $advisories */
    $advisories = $data['advisories'];

    if (!is_array($advisories)) {
        advisoryGateUnusable('"advisories" is not a map of package => issues.');
    }

    $found = [];

    /** @var mixed $issues */
    foreach ($advisories as $package => $issues) {
        if (!is_array($issues)) {
            advisoryGateUnusable(sprintf('Advisories for "%s" are not a list.', (string) $package));
        }

        /** @var mixed $issue */
        foreach ($issues as $issue) {
            $found[] = sprintf(
                '%s: %s — %s — %s',
                strtoupper(advisoryStringField($issue, 'severity', 'UNKNOWN')),
                (string) $package,
                advisoryStringField($issue, 'cve', '(no CVE)'),
                advisoryStringField($issue, 'title', '(no title)'),
            );
        }
    }

    return $found;
}

/**
 * `pnpm audit --json` emits `advisories` as id => advisory, the severity totals
 * in `metadata.vulnerabilities`, and anything silenced by `pnpm.auditConfig` in
 * `muted`.
 *
 * All three are read. The shell version this replaces trusted the totals alone,
 * and a total is a summary: it can read zero while the advisory list is not
 * empty, and it is the field most likely to move if the format changes. Muted
 * entries fail as well — an ignore list that quietly switches the gate off is
 * the same fail-open in a more deliberate form.
 *
 * @param array<mixed> $data
 * @return list<string>
 */
function pnpmAdvisoryLines(array $data, string $path): array
{
    foreach (['advisories', 'muted', 'metadata'] as $key) {
        if (!array_key_exists($key, $data)) {
            advisoryGateUnusable(sprintf(
                'Audit report "%s" has no "%s" key; pnpm\'s output format has changed.',
                $path,
                $key,
            ));
        }
    }

    /** @var mixed $advisories */
    $advisories = $data['advisories'];

    if (!is_array($advisories)) {
        advisoryGateUnusable('"advisories" is not a map of id => advisory.');
    }

    $found = [];

    /** @var mixed $advisory */
    foreach ($advisories as $id => $advisory) {
        $cves = '';

        if (is_array($advisory)) {
            /** @var mixed $rawCves */
            $rawCves = $advisory['cves'] ?? null;

            if (is_array($rawCves)) {
                $cves = implode(', ', array_values(array_filter($rawCves, 'is_string')));
            }
        }

        $found[] = sprintf(
            '%s: %s — %s — %s',
            strtoupper(advisoryStringField($advisory, 'severity', 'UNKNOWN')),
            advisoryStringField($advisory, 'module_name', (string) $id),
            $cves !== '' ? $cves : advisoryStringField($advisory, 'github_advisory_id', '(no CVE)'),
            advisoryStringField($advisory, 'title', '(no title)'),
        );
    }

    /** @var mixed $muted */
    $muted = $data['muted'];

    if (!is_array($muted)) {
        advisoryGateUnusable('"muted" is not a list of silenced advisories.');
    }

    /** @var mixed $entry */
    foreach ($muted as $entry) {
        $found[] = sprintf(
            'MUTED: %s — %s — silenced by pnpm.auditConfig, which this gate does not honour.',
            advisoryStringField($entry, 'module_name', '(unknown module)'),
            advisoryStringField($entry, 'title', '(no title)'),
        );
    }

    /** @var mixed $metadata */
    $metadata = $data['metadata'];

    if (!is_array($metadata) || !array_key_exists('vulnerabilities', $metadata)) {
        advisoryGateUnusable(sprintf(
            'Audit report "%s" carries no "metadata.vulnerabilities" totals; pnpm\'s output format has changed.',
            $path,
        ));
    }

    /** @var mixed $totals */
    $totals = $metadata['vulnerabilities'];

    if (!is_array($totals) || $totals === []) {
        advisoryGateUnusable('"metadata.vulnerabilities" is not a map of severity => count.');
    }

    $counted = 0;

    /** @var mixed $count */
    foreach ($totals as $severity => $count) {
        if (!is_int($count) || $count < 0) {
            advisoryGateUnusable(sprintf('"metadata.vulnerabilities.%s" is not a count.', (string) $severity));
        }

        $counted += $count;
    }

    // The totals and the advisory list are read independently so neither can
    // mask the other: a populated list under all-zero totals already failed
    // above, and a positive total under an empty list fails here.
    if ($counted > 0 && $found === []) {
        $found[] = sprintf(
            'UNKNOWN: metadata.vulnerabilities totals %d, while "advisories" is empty.',
            $counted,
        );
    }

    return $found;
}

$ecosystem = 'composer';
$path = null;

foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--ecosystem=')) {
        $ecosystem = substr($argument, strlen('--ecosystem='));

        continue;
    }

    if (str_starts_with($argument, '-')) {
        advisoryGateUnusable(sprintf("Unknown option \"%s\".\n%s", $argument, ADVISORY_GATE_USAGE));
    }

    if ($path !== null) {
        advisoryGateUnusable(sprintf(
            "Two report paths given (\"%s\" then \"%s\").\n%s",
            $path,
            $argument,
            ADVISORY_GATE_USAGE,
        ));
    }

    $path = $argument;
}

if ($ecosystem !== 'composer' && $ecosystem !== 'pnpm') {
    advisoryGateUnusable(sprintf(
        "Unknown ecosystem \"%s\"; expected composer or pnpm.\n%s",
        $ecosystem,
        ADVISORY_GATE_USAGE,
    ));
}

$path ??= $ecosystem === 'composer' ? 'audit-results.json' : 'pnpm-audit-results.json';

if (!is_file($path)) {
    advisoryGateUnusable(sprintf(
        "Audit report \"%s\" does not exist.\nGenerate it first: %s\n",
        $path,
        $ecosystem === 'composer'
            ? sprintf('composer audit --format=json | tee %s', $path)
            : sprintf('pnpm audit --json > %s', $path),
    ));
}

$raw = file_get_contents($path);

if ($raw === false || trim($raw) === '') {
    advisoryGateUnusable(sprintf('Audit report "%s" is empty or unreadable.', $path));
}

try {
    /** @var mixed $data */
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    advisoryGateUnusable(sprintf('Audit report "%s" is not valid JSON: %s', $path, $e->getMessage()));
}

if (!is_array($data)) {
    advisoryGateUnusable(sprintf('Audit report "%s" did not decode to an object.', $path));
}

$found = match ($ecosystem) {
    'composer' => composerAdvisoryLines($data, $path),
    'pnpm' => pnpmAdvisoryLines($data, $path),
};

if ($found !== []) {
    foreach ($found as $line) {
        echo $line, "\n";
    }

    fwrite(STDERR, sprintf(
        "\n%d advisor%s detected in the %s tree. Every severity fails the build.\n",
        count($found),
        count($found) === 1 ? 'y' : 'ies',
        $ecosystem,
    ));

    exit(1);
}

printf("No %s vulnerability advisories found, at any severity.\n", $ecosystem);

exit(0);
