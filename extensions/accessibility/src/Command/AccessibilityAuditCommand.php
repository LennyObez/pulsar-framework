<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Command;

use Override;
use Pulsar\Api\Api;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Accessibility\Audit\AccessibilityAuditor;
use Pulsar\Extension\Accessibility\Audit\AuditReport;
use Pulsar\Extension\Accessibility\Audit\ManualChecklistGenerator;
use Pulsar\Extension\Accessibility\Validator\AccessibilityViolation;
use Pulsar\Extension\Accessibility\Validator\Severity;

use function count;
use function is_dir;
use function is_file;
use function json_encode;
use function sprintf;
use function strtolower;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * CLI command for running accessibility audits on template files.
 *
 * This command is dev/CI only — it must never be registered in production mode.
 */
#[Api(since: '1.0.0')]
final class AccessibilityAuditCommand extends Command
{
    public function __construct(
        private readonly AccessibilityAuditor $auditor,
        private readonly ManualChecklistGenerator $checklistGenerator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'a11y:audit';
        $this->description = 'Run accessibility audit on template files (dev/CI only)';
        $this->addArgument('path', 'File or directory path to audit', required: true);
        $this->addOption('format', 'Output format: text or json', shortcut: 'f', default: 'text');
        $this->addOption('browser', 'Enable headless browser computed checks (requires Playwright)');
        $this->addOption('severity', 'Minimum severity to report: error, warning, info', shortcut: 's', default: 'warning');
        $this->addOption('pattern', 'Glob pattern for directory audit', shortcut: 'p', default: '*.php');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $path */
        $path = $input->getArgument('path');

        /** @var string $format */
        $format = $input->getOption('format') ?? 'text';

        /** @var string|null $browser */
        $browser = $input->getOption('browser');

        /** @var string $minSeverity */
        $minSeverity = $input->getOption('severity') ?? 'warning';

        /** @var string $pattern */
        $pattern = $input->getOption('pattern') ?? '*.php';

        if ($browser !== null) {
            $output->newLine();
            $output->writeln('  Browser-based auditing requires Playwright or Puppeteer to be installed.');
            $output->writeln('  This feature provides computed contrast ratios, focus order analysis,');
            $output->writeln('  and rendered ARIA property evaluation for pages served over HTTP.');
            $output->newLine();
            $output->writeln('  Install: npm install -D playwright');
            $output->writeln('  Usage:   pulsar a11y:audit http://localhost:8000 --browser');
            $output->newLine();

            return ExitCode::Success->value;
        }

        $report = $this->runAudit($path, $pattern);

        $filteredViolations = $this->filterBySeverity($report, $minSeverity);

        if ($format === 'json') {
            return $this->outputJson($output, $report, $filteredViolations);
        }

        return $this->outputText($output, $report, $filteredViolations);
    }

    private function runAudit(string $path, string $pattern): AuditReport
    {
        if (is_file($path)) {
            return $this->auditor->auditTemplateFile($path);
        }

        if (is_dir($path)) {
            return $this->auditor->auditDirectory($path, $pattern);
        }

        return $this->auditor->auditHtml('');
    }

    /**
     * @return list<AccessibilityViolation>
     */
    private function filterBySeverity(AuditReport $report, string $minSeverity): array
    {
        $minLevel = match (strtolower($minSeverity)) {
            'error' => 0,
            'warning' => 1,
            'info' => 2,
            default => 1,
        };

        $violations = [];

        foreach ($report->violations as $violation) {
            $level = match ($violation->severity) {
                Severity::Error => 0,
                Severity::Warning => 1,
                Severity::Info => 2,
            };

            if ($level <= $minLevel) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @param list<AccessibilityViolation> $violations
     */
    private function outputText(OutputInterface $output, AuditReport $report, array $violations): int
    {
        $summary = $report->summary();

        $output->newLine();
        $output->writeln('  Pulsar Accessibility Audit');
        $output->writeln('  ' . str_repeat('=', 40));
        $output->newLine();

        if ($violations === []) {
            $output->success('No violations found in automated checks.');
        } else {
            foreach ($violations as $violation) {
                $icon = match ($violation->severity) {
                    Severity::Error => 'ERROR',
                    Severity::Warning => 'WARN',
                    Severity::Info => 'INFO',
                };

                $output->writeln(sprintf(
                    '  [%s] %s (WCAG %s)',
                    $icon,
                    $violation->message,
                    $violation->wcagCriterion,
                ));

                if ($violation->element !== '') {
                    $output->writeln(sprintf('         Element: %s', $violation->element));
                }

                if ($violation->line !== null) {
                    $output->writeln(sprintf('         Line: %d', $violation->line));
                }

                $output->newLine();
            }
        }

        $output->writeln(sprintf(
            '  Summary: %d errors, %d warnings, %d info (%d checks, %d files)',
            $summary->errorCount,
            $summary->warningCount,
            $summary->infoCount,
            $summary->totalChecks,
            $summary->filesAudited,
        ));

        $output->newLine();
        $output->writeln('  LIMITATIONS');
        $output->writeln('  ' . str_repeat('-', 40));

        foreach ($report->limitations() as $limitation) {
            $output->writeln(sprintf('  - %s', $limitation));
        }

        $output->newLine();
        $output->writeln('  MANUAL TESTING CHECKLIST');
        $output->writeln('  ' . str_repeat('-', 40));

        foreach ($this->checklistGenerator->generateGrouped() as $category => $items) {
            $output->writeln(sprintf('  [%s]', $category));

            foreach ($items as $item) {
                $output->writeln(sprintf(
                    '    [ ] %s (WCAG %s %s)',
                    $item->description,
                    $item->wcagCriterion,
                    $item->wcagLevel,
                ));
            }

            $output->newLine();
        }

        $output->writeln('  This report covers automated checks only. Manual testing is required');
        $output->writeln('  for full WCAG 2.1 AA compliance assessment.');
        $output->newLine();

        return count($report->errors()) > 0 ? ExitCode::Error->value : ExitCode::Success->value;
    }

    /**
     * @param list<AccessibilityViolation> $violations
     */
    private function outputJson(OutputInterface $output, AuditReport $report, array $violations): int
    {
        $data = $report->toArray();
        $data['filtered_violations'] = array_map(
            static fn(AccessibilityViolation $v): array => [
                'rule' => $v->rule,
                'severity' => $v->severity->value,
                'element' => $v->element,
                'message' => $v->message,
                'wcag_criterion' => $v->wcagCriterion,
                'line' => $v->line,
            ],
            $violations,
        );

        $data['manual_checklist'] = array_map(
            static fn(\Pulsar\Extension\Accessibility\Audit\ChecklistItem $item): array => $item->toArray(),
            $this->checklistGenerator->generate(),
        );

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return count($report->errors()) > 0 ? ExitCode::Error->value : ExitCode::Success->value;
    }
}
