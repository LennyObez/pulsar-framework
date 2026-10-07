#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CI gate: fail when a control an enabled framework claims is not observed in
 * the deployment being checked.
 *
 * What this replaces, and why none of it survives
 * -----------------------------------------------
 * The previous version read docs/compliance-matrix.md — a file that has never
 * existed in this repository, so the gate exited 1 unconditionally and was
 * therefore never observed to be broken. Had the file existed, it would have
 * checked that each backtick-quoted identifier in it named a class defined
 * somewhere under src/ or extensions/.
 *
 * That check is the ADR-0041 fallacy in its purest form. ADR-0041 records a
 * control that reported itself implemented because `TokenizationService` and
 * `DatabaseTokenStore` existed, while the deployment resolved an in-memory
 * store and the PANs were never rendered unreadable at all. A gate that asks
 * "does a class with this name exist?" would have passed that deployment, and
 * passed it every day, in green. Matching identifiers against a source tree
 * cannot distinguish a control that works from one that compiles, so no part of
 * it is kept.
 *
 * What it does instead: runs `pulsar compliance:report`, whose every outcome is
 * computed by a probe from facts gathered out of the running deployment, and
 * fails on any control an enabled framework claims and the deployment does not
 * show. The claim and the check are the same act.
 *
 * Usage:
 *   php scripts/check_compliance_claims.php
 *   php scripts/check_compliance_claims.php --framework=pci_dss
 *   php scripts/check_compliance_claims.php --strict   # residual gaps fail too
 *   php scripts/check_compliance_claims.php --json     # machine-readable result
 *
 * Exit codes mirror the command's, because this script adds no judgement of its
 * own: 0 every claimed control was observed, 1 at least one was not, 2 the
 * report could not be produced (which is never reported as a pass).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * One control the report says is claimed and not observed.
 */
final readonly class UnobservedControl
{
    /**
     * @param list<string> $remediations
     */
    public function __construct(
        public string $framework,
        public string $id,
        public string $title,
        public string $outcome,
        public string $summary,
        public array $remediations,
    ) {}
}

/**
 * Runs the report and reads its findings.
 *
 * The report is produced by the command and not re-derived here: a second,
 * weaker claims checker in the tree would recreate exactly the ambiguity this
 * work exists to remove — two answers to "is this control met?", disagreeing,
 * with no way to tell which one an assessor was shown.
 */
final class ComplianceClaimGate
{
    /** The report could not be produced at all; never reported as a pass. */
    private const int EXIT_UNPRODUCIBLE = 2;

    /**
     * @param list<string> $reportArguments Passed through to `pulsar compliance:report`
     */
    public function __construct(
        private readonly string $root,
        private readonly array $reportArguments,
    ) {}

    /**
     * @return array{status: int, report: array<string, mixed>|null, stderr: string}
     */
    public function run(): array
    {
        $command = [
            PHP_BINARY,
            $this->root . '/bin/pulsar',
            'compliance:report',
            '--format=json',
            ...$this->reportArguments,
        ];

        $stderrFile = tempnam(sys_get_temp_dir(), 'pulsar-compliance-');

        if ($stderrFile === false) {
            return [
                'status' => self::EXIT_UNPRODUCIBLE,
                'report' => null,
                'stderr' => 'Could not create a temporary file to capture the report command stderr.',
            ];
        }

        $descriptors = [
            0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->root);

        if (!is_resource($process)) {
            @unlink($stderrFile);

            return [
                'status' => self::EXIT_UNPRODUCIBLE,
                'report' => null,
                'stderr' => 'Could not start `pulsar compliance:report`.',
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);

        $stderr = (string) @file_get_contents($stderrFile);
        @unlink($stderrFile);

        return [
            'status' => $status,
            'report' => is_string($stdout) ? $this->decode($stdout) : null,
            'stderr' => trim($stderr),
        ];
    }

    /**
     * Controls the report itself marked as claimed and not observed.
     *
     * Read from the report rather than recomputed: the exit code and this list
     * must agree, and they can only be guaranteed to agree by having one source.
     *
     * @param array<string, mixed> $report
     *
     * @return list<UnobservedControl>
     */
    public function unobserved(array $report, bool $strict): array
    {
        $failing = $strict ? ['unsatisfied', 'partial'] : ['unsatisfied'];

        /** @var mixed $controls */
        $controls = $report['controls'] ?? [];
        $unobserved = [];

        if (!is_array($controls)) {
            return [];
        }

        /** @var mixed $control */
        foreach ($controls as $control) {
            if (!is_array($control)) {
                continue;
            }

            /** @var mixed $outcome */
            $outcome = $control['outcome'] ?? null;

            if (!is_string($outcome) || !in_array($outcome, $failing, true)) {
                continue;
            }

            /** @var mixed $remediations */
            $remediations = $control['remediations'] ?? [];

            $unobserved[] = new UnobservedControl(
                framework: self::text($control['framework'] ?? null),
                id: self::text($control['id'] ?? null),
                title: self::text($control['title'] ?? null),
                outcome: $outcome,
                summary: self::text($control['summary'] ?? null),
                remediations: is_array($remediations)
                    ? array_values(array_map(self::text(...), $remediations))
                    : [],
            );
        }

        return $unobserved;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $stdout): ?array
    {
        if (trim($stdout) === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}

/**
 * Encode the gate's own result. Pretty-printed because a human reads it in a CI
 * log at least as often as a machine parses it.
 *
 * @param array<string, mixed> $data
 */
function encodeJson(array $data): string
{
    return json_encode(
        $data,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION
            | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

$root = dirname(__DIR__);

/** @var list<string> $argumentList */
$argumentList = array_slice($argv ?? [], 1);

$json = in_array('--json', $argumentList, true);
$strict = in_array('--strict', $argumentList, true);

$passthrough = array_values(array_filter(
    $argumentList,
    static fn(string $argument): bool => $argument !== '--json',
));

$gate = new ComplianceClaimGate($root, $passthrough);
$result = $gate->run();

$report = $result['report'];
$status = $result['status'];

// Exit 2 — the report could not be produced. Deliberately not a pass: "nothing
// to assess", "the gatherer threw" and "every control was observed" must never
// print the same colour.
if ($report === null || $status === 2) {
    $message = $result['stderr'] !== ''
        ? $result['stderr']
        : 'pulsar compliance:report produced no readable report.';

    if ($json) {
        echo encodeJson([
            'gate' => 'compliance_claims',
            'status' => 'unproducible',
            'message' => $message,
        ]), "\n";
    } else {
        echo "FAIL: the compliance report could not be produced.\n\n";
        echo '  ', str_replace("\n", "\n  ", $message), "\n\n";
        echo "A report that cannot be produced is not a passing report. Fix the\n";
        echo "deployment or the configuration and run the gate again.\n";
    }

    exit(2);
}

$unobserved = $gate->unobserved($report, $strict);

/** @var mixed $summary */
$summary = $report['summary'] ?? [];
$summary = is_array($summary) ? $summary : [];

if ($json) {
    echo encodeJson([
        'gate' => 'compliance_claims',
        'status' => $unobserved === [] ? 'observed' : 'unobserved',
        'strict' => $strict,
        'summary' => $summary,
        'unobserved' => array_map(
            static fn(UnobservedControl $control): array => [
                'framework' => $control->framework,
                'id' => $control->id,
                'title' => $control->title,
                'outcome' => $control->outcome,
                'summary' => $control->summary,
                'remediations' => $control->remediations,
            ],
            $unobserved,
        ),
    ]), "\n";

    exit($unobserved === [] && $status === 0 ? 0 : 1);
}

if ($unobserved !== []) {
    echo "Controls claimed by an enabled framework that this deployment does not show:\n\n";

    foreach ($unobserved as $control) {
        printf("  [%s] %s %s — %s\n", $control->outcome, $control->framework, $control->id, $control->title);
        printf("        %s\n", $control->summary);

        foreach ($control->remediations as $remediation) {
            printf("        fix: %s\n", $remediation);
        }

        echo "\n";
    }

    printf("FAIL: %d control(s) claimed and not observed.\n", count($unobserved));
    echo "Either make the deployment show the control, or stop claiming it by\n";
    echo "removing its framework from enabled_frameworks in config/compliance.php.\n";
    echo "Do NOT weaken the mapping: a control's outcome is computed from the\n";
    echo "deployment and cannot be edited into passing.\n";

    exit(1);
}

// A non-zero status with no unobserved control means the command failed for a
// reason the findings do not name — an enabled framework with no mapping at all
// is the usual one. Propagate it rather than reporting green.
if ($status !== 0) {
    echo 'FAIL: the compliance report exited ', $status, " without naming a control.\n\n";

    if ($result['stderr'] !== '') {
        echo '  ', str_replace("\n", "\n  ", $result['stderr']), "\n";
    }

    exit(1);
}

/** @var mixed $assessed */
$assessed = $summary['assessed'] ?? 0;
/** @var mixed $checklist */
$checklist = $summary['operator_checklist'] ?? 0;

printf(
    "OK: all %d assessed control(s) were observed in this deployment.\n",
    is_int($assessed) ? $assessed : 0,
);
printf(
    "    %d operator-responsibility control(s) are listed in the report as a\n"
        . "    checklist and are counted toward nothing.\n",
    is_int($checklist) ? $checklist : 0,
);

exit(0);
