<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\InadmissibleEvidenceException;
use Pulsar\Compliance\Control\IncompleteEvidenceException;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\Evidence\EvidenceSourceInterface;
use Pulsar\Compliance\Report\ComplianceAssessment;
use Pulsar\Compliance\Report\JsonReportRenderer;
use Pulsar\Compliance\Report\MarkdownReportRenderer;
use Pulsar\Compliance\Report\ReportContext;
use Pulsar\Compliance\Report\ReportFormat;
use Pulsar\Compliance\Report\ReportRendererInterface;
use Pulsar\Compliance\Report\TextReportRenderer;
use Pulsar\Config\AppConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Throwable;

use function array_map;
use function count;
use function implode;
use function in_array;
use function sprintf;

use const PHP_SAPI;
use const PHP_VERSION;

/**
 * Assess the running deployment against the controls its enabled frameworks
 * declare, and print the result as an artefact an assessor can be handed.
 *
 * The exit code is the gate: a control an enabled framework claims and the
 * deployment does not show fails the command. Nothing here can be made to pass
 * by editing a mapping — every outcome is computed by a probe from evidence
 * gathered when this command runs, which is why the command exists at all.
 *
 * Gathering happens inside {@see execute()} and never at wiring time. A gatherer
 * resolved during boot would read services before the wirings that replace them
 * had run, and the report would then measure, and truthfully record, a
 * deployment that the running application is not (ADR-0041).
 */
final class ComplianceReportCommand extends Command
{
    public function __construct(
        private readonly ControlCatalog $catalog,
        private readonly EvidenceSourceInterface $evidence,
        private readonly ComplianceConfig $config,
        private readonly AppConfig $app,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'compliance:report';
        $this->description = 'Assess the deployment against its enabled compliance frameworks';
        $this->addOption(
            'framework',
            'Report on one enabled framework only (e.g. pci_dss)',
        );
        $this->addOption(
            'format',
            sprintf('Output shape: %s (default text)', ReportFormat::accepted()),
            null,
            ReportFormat::Text->value,
        );
        $this->addOption(
            'strict',
            'Also fail on partially satisfied controls',
        );
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = ReportFormat::tryFrom($input->getStringOption('format', ReportFormat::Text->value));

        if ($format === null) {
            $output->errorln(sprintf(
                'Unknown --format "%s". Accepted: %s.',
                $input->getStringOption('format'),
                ReportFormat::accepted(),
            ));

            return ExitCode::Invalid->value;
        }

        $frameworks = $this->frameworks($input, $output);

        if ($frameworks === null) {
            return ExitCode::Invalid->value;
        }

        $unmapped = $this->unmappedFrameworks($frameworks);

        try {
            $findings = new ControlAssessment($this->catalog)
                ->assessFrameworks($frameworks, $this->evidence->gather());
        } catch (IncompleteEvidenceException $e) {
            $output->errorln('Compliance evidence could not be gathered: ' . $e->getMessage());

            return ExitCode::Invalid->value;
        } catch (InadmissibleEvidenceException $e) {
            $output->errorln('A control probe returned a verdict its evidence cannot support. '
                . 'This is a defect in the probe, not a finding about the deployment, and is '
                . 'deliberately not reported as one: ' . $e->getMessage());

            return ExitCode::Invalid->value;
        } catch (Throwable $e) {
            $output->errorln('Compliance assessment failed: ' . $e->getMessage());

            return ExitCode::Invalid->value;
        }

        $assessment = new ComplianceAssessment($this->context(), $frameworks, $findings);

        $output->writeln($this->renderer($format)->render($assessment));

        return $this->verdict($assessment, $unmapped, $format, $input->getBoolOption('strict'), $output);
    }

    /**
     * The frameworks to assess, or null when the request cannot be honoured.
     *
     * A `--framework` outside the enabled set is refused rather than assessed:
     * config/compliance.php is what the deployment committed to, and a report
     * about a framework nobody enabled is a report about nothing.
     *
     * @return list<ComplianceFramework>|null
     */
    private function frameworks(InputInterface $input, OutputInterface $output): ?array
    {
        $enabled = $this->config->enabledFrameworks;

        if ($enabled === []) {
            $output->errorln(
                'config/compliance.php enables no framework, so there is nothing to assess. '
                    . 'That is deliberately not a pass: a report of "nothing to assess" printed '
                    . 'as green is how a misconfigured pipeline reports compliance forever.',
            );

            return null;
        }

        $requested = $input->getNullableStringOption('framework');

        if ($requested === null || $requested === '') {
            return $enabled;
        }

        $framework = ComplianceFramework::tryFrom($requested);

        if ($framework === null || !in_array($framework, $enabled, true)) {
            $output->errorln(sprintf(
                'Unknown or disabled --framework "%s". Enabled frameworks: %s.',
                $requested,
                implode(', ', array_map(
                    static fn(ComplianceFramework $enabledFramework): string => $enabledFramework->value,
                    $enabled,
                )),
            ));

            return null;
        }

        return [$framework];
    }

    /**
     * Enabled frameworks for which no mapping declares a single control.
     *
     * Reported as a failure, not as a clean sheet. The deployment committed to
     * a standard and the software has nothing to say about it, which is the
     * absence-read-as-assurance failure this whole command exists to end.
     *
     * @param list<ComplianceFramework> $frameworks
     *
     * @return list<ComplianceFramework>
     */
    private function unmappedFrameworks(array $frameworks): array
    {
        $declared = $this->catalog->frameworks();
        $unmapped = [];

        foreach ($frameworks as $framework) {
            if (!in_array($framework, $declared, true)) {
                $unmapped[] = $framework;
            }
        }

        return $unmapped;
    }

    /**
     * Print the closing verdict and decide the exit code.
     *
     * Under `--format=json` nothing but the document reaches stdout, so a
     * pipeline can parse the output without stripping a status line first; the
     * verdict travels in the exit code and, when it is a failure, on stderr.
     *
     * @param list<ComplianceFramework> $unmapped
     */
    private function verdict(
        ComplianceAssessment $assessment,
        array $unmapped,
        ReportFormat $format,
        bool $strict,
        OutputInterface $output,
    ): int {
        $failing = $assessment->failing($strict);
        $machine = $format === ReportFormat::Json;

        if ($unmapped !== []) {
            $names = implode(', ', array_map(
                static fn(ComplianceFramework $framework): string => $framework->value,
                $unmapped,
            ));

            $output->errorln(sprintf(
                'No control is declared for enabled framework(s): %s. An enabled framework with '
                    . 'no mapping is a gap, not a clean sheet.',
                $names,
            ));
        }

        if ($failing !== [] || $unmapped !== []) {
            $output->errorln(sprintf(
                'Compliance: FAIL (%d control%s claimed and not %sobserved%s)',
                count($failing),
                count($failing) === 1 ? '' : 's',
                // Under --strict a partially satisfied control fails too, and
                // saying "not observed" of it would overstate the finding.
                $strict ? 'fully ' : '',
                $unmapped === [] ? '' : sprintf(', %d framework(s) unmapped', count($unmapped)),
            ));

            foreach ($failing as $finding) {
                $output->errorln(self::failureLine($finding));
            }

            return ExitCode::Error->value;
        }

        if (!$machine) {
            $output->newLine();
            $output->success(sprintf(
                'Compliance: every claimed control of %d framework%s was observed',
                count($assessment->frameworks),
                count($assessment->frameworks) === 1 ? '' : 's',
            ));
            $output->newLine();
        }

        return ExitCode::Success->value;
    }

    /**
     * One failing control on stderr, carrying its outcome so a residual gap
     * failing only under --strict is not read as an unmet control.
     */
    private static function failureLine(ControlFinding $finding): string
    {
        return sprintf(
            '  [%s] %s %s: %s',
            $finding->outcome->value,
            $finding->declaration->framework->value,
            $finding->declaration->id,
            $finding->summary,
        );
    }

    private function renderer(ReportFormat $format): ReportRendererInterface
    {
        return match ($format) {
            ReportFormat::Text => new TextReportRenderer(),
            ReportFormat::Json => new JsonReportRenderer(),
            ReportFormat::Markdown => new MarkdownReportRenderer(),
        };
    }

    /**
     * Provenance for the document. The SAPI is recorded because this is a CLI
     * boot: extensions, OPcache state and wiring outcomes can differ from the
     * process that serves traffic, and a report that hid that would be handed
     * over as a statement about a deployment it never observed.
     */
    private function context(): ReportContext
    {
        return new ReportContext(
            applicationName: $this->app->name === '' ? 'Pulsar' : $this->app->name,
            environment: $this->app->mode->value,
            generatedAt: $this->clock?->now() ?? new DateTimeImmutable('now'),
            phpVersion: PHP_VERSION,
            sapi: PHP_SAPI,
        );
    }
}
