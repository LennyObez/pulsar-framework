<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function count;
use function json_encode;
use function sprintf;
use function str_pad;
use function strtoupper;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const STR_PAD_RIGHT;

/**
 * Console diagnostic output for compliance verification.
 *
 * Produces human-readable (colored) or machine-readable (JSON) output
 * suitable for `pulsar compliance:verify` and CI pipelines.
 */
#[Api(since: '1.0.0')]
final readonly class ComplianceDiagnostic
{
    /**
     * Format the verification report for console output.
     *
     * @param 'text'|'json' $format
     *
     * @return array{output: string, exitCode: int}
     */
    #[NoDiscard]
    public function format(VerificationReport $report, string $format = 'text'): array
    {
        $exitCode = $report->hasFailures() ? 1 : 0;

        if ($format === 'json') {
            return [
                'output' => json_encode(
                    $report->toArray(),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'exitCode' => $exitCode,
            ];
        }

        return [
            'output' => $this->formatText($report),
            'exitCode' => $exitCode,
        ];
    }

    private function formatText(VerificationReport $report): string
    {
        $lines = [];

        $lines[] = '';
        $lines[] = '  Pulsar Compliance Verification';
        $lines[] = '  ' . str_repeat('=', 50);
        $lines[] = '';

        // Frameworks
        if ($report->frameworks !== []) {
            $frameworkNames = array_map(
                static fn($f): string => $f->value,
                $report->frameworks,
            );
            $lines[] = sprintf('  Frameworks: %s', implode(', ', $frameworkNames));
            $lines[] = '';
        }

        // Results
        foreach ($report->results as $result) {
            $status = match ($result->status) {
                CheckStatus::Pass => $this->colorize('PASS', 'green'),
                CheckStatus::Fail => $this->colorize('FAIL', 'red'),
                CheckStatus::Skip => $this->colorize('SKIP', 'yellow'),
            };

            $lines[] = sprintf(
                '  [%s] %s  %s',
                $status,
                str_pad($result->checkId, 35, ' ', STR_PAD_RIGHT),
                $result->message,
            );

            // Show remediations for failures
            if ($result->status === CheckStatus::Fail && $result->remediations !== []) {
                foreach ($result->remediations as $remediation) {
                    $lines[] = sprintf('         %s %s', $this->colorize('FIX:', 'cyan'), $remediation);
                }
            }
        }

        $lines[] = '';

        // Conflicts
        if ($report->conflicts !== []) {
            $lines[] = sprintf('  %s', $this->colorize('Cross-Framework Conflicts', 'yellow'));
            $lines[] = '  ' . str_repeat('-', 40);

            foreach ($report->conflicts as $conflict) {
                $lines[] = sprintf(
                    '  %s vs %s',
                    strtoupper($conflict->frameworkA->value),
                    strtoupper($conflict->frameworkB->value),
                );
                $lines[] = sprintf('    %s', $conflict->description);
                $lines[] = sprintf('    %s %s', $this->colorize('Resolution:', 'green'), $conflict->resolution);
                $lines[] = '';
            }
        }

        // Regressions
        if ($report->regressions !== []) {
            $lines[] = sprintf('  %s', $this->colorize('Configuration Regressions', 'red'));
            $lines[] = '  ' . str_repeat('-', 40);

            foreach ($report->regressions as $regression) {
                $lines[] = sprintf(
                    '  %s %s',
                    $this->colorize('REGRESSION:', 'red'),
                    $regression->constraint,
                );
                $lines[] = sprintf('    Expected: %s', $regression->expectedDescription);
                $lines[] = sprintf('    Actual:   %s', $regression->actualDescription);
                $lines[] = sprintf('    %s %s', $this->colorize('FIX:', 'cyan'), $regression->remediation);
                $lines[] = '';
            }
        }

        // Summary
        $lines[] = '  ' . str_repeat('-', 50);
        $summaryColor = $report->hasFailures() ? 'red' : 'green';
        $lines[] = sprintf(
            '  %s: %d passed, %d failed, %d skipped (%.1f%% pass rate)',
            $this->colorize('Summary', $summaryColor),
            $report->passCount(),
            $report->failCount(),
            $report->skipCount(),
            $report->passRate(),
        );

        if ($report->hasConflicts()) {
            $lines[] = sprintf(
                '  %s: %d cross-framework conflict(s) detected',
                $this->colorize('Conflicts', 'yellow'),
                count($report->conflicts),
            );
        }

        if ($report->hasRegressions()) {
            $lines[] = sprintf(
                '  %s: %d regression(s) detected',
                $this->colorize('Regressions', 'red'),
                count($report->regressions),
            );
        }

        $lines[] = '';
        $lines[] = '  Disclaimer: This is a control coverage assessment,';
        $lines[] = '  not a compliance certification.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Apply ANSI color codes to text.
     */
    private function colorize(string $text, string $color): string
    {
        $codes = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'cyan' => "\033[36m",
            'reset' => "\033[0m",
        ];

        $code = $codes[$color] ?? '';
        $reset = $codes['reset'];

        return $code . $text . $reset;
    }
}
