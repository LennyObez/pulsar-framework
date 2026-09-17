<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\Evidence\EvidenceSourceInterface;
use Pulsar\Compliance\Report\ComplianceAssessment;
use Pulsar\Compliance\Report\JsonReportRenderer;
use Pulsar\Compliance\Report\MarkdownReportRenderer;
use Pulsar\Compliance\Report\ReportContext;
use Pulsar\Compliance\Report\ReportFormat;
use Pulsar\Compliance\Report\ReportVocabulary;
use Pulsar\Compliance\Report\TextReportRenderer;
use Pulsar\Config\AppConfig;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Console\Command\ComplianceReportCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Tests\Support\Compliance\EvidenceFixture;
use Pulsar\Tests\Support\Compliance\PartialProbe;
use Pulsar\Tests\Support\Compliance\RecordingProbe;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;
use Pulsar\Tests\Support\Compliance\ThrowingEvidenceSource;

use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ComplianceReportCommand::class)]
#[CoversClass(ComplianceAssessment::class)]
#[CoversClass(TextReportRenderer::class)]
#[CoversClass(JsonReportRenderer::class)]
#[CoversClass(MarkdownReportRenderer::class)]
#[CoversClass(ReportContext::class)]
#[CoversClass(ReportFormat::class)]
#[CoversClass(ReportVocabulary::class)]
final class ComplianceReportCommandTest extends TestCase
{
    #[Test]
    public function nameAndDescription(): void
    {
        $command = self::command(EvidenceFixture::everythingObserved());

        self::assertSame('compliance:report', $command->name);
        self::assertStringContainsString('compliance', $command->description);
    }

    #[Test]
    public function aDeploymentThatShowsItsControlsPassesAndReportsThemObserved(): void
    {
        $output = new BufferedOutput();

        $exit = self::command(EvidenceFixture::everythingObserved())
            ->execute(new ArrayInput('compliance:report'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[OK  ] Req3.4', $output->buffer);
        self::assertStringContainsString('probe.pan_at_rest', $output->buffer);
        self::assertStringContainsString('Observed working: 1 required fact(s) were exercised', $output->buffer);
        self::assertStringContainsString('token_vault_persistence', $output->buffer);
        self::assertStringContainsString('(measured)', $output->buffer);
        self::assertStringContainsString('every claimed control', $output->buffer);
    }

    #[Test]
    public function aDeploymentThatDoesNotShowAControlFailsAndNamesTheGap(): void
    {
        $output = new BufferedOutput();

        $exit = self::command(EvidenceFixture::nothingObserved())
            ->execute(new ArrayInput('compliance:report'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('[GAP ] Req3.4', $output->buffer);
        self::assertStringContainsString('Not observed: token_vault_persistence', $output->buffer);
        self::assertStringContainsString('fix: Configure a durable token store.', $output->buffer);
        self::assertStringContainsString('pci_dss Req3.4', $output->errorBuffer);
    }

    #[Test]
    public function theEvidenceGradeAndItsObserverArePrintedBesideEveryFact(): void
    {
        $output = new BufferedOutput();

        self::command(EvidenceFixture::everythingObserved())
            ->execute(new ArrayInput('compliance:report'), $output);

        self::assertStringContainsString('measured', $output->buffer);
        self::assertStringContainsString(SyntheticObservation::class, $output->buffer);
    }

    #[Test]
    public function operatorResponsibilitiesAreListedAsAChecklistAndDoNotAffectTheExitCode(): void
    {
        $output = new BufferedOutput();

        $exit = self::command(EvidenceFixture::everythingObserved())
            ->execute(new ArrayInput('compliance:report'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Operator responsibilities', $output->buffer);
        self::assertStringContainsString('artefact: CI run showing composer qa green', $output->buffer);
        self::assertStringContainsString('operator checklist 1', $output->buffer);
    }

    #[Test]
    public function jsonOutputParsesAndCarriesWhatTheTextOutputSays(): void
    {
        $text = new BufferedOutput();
        $json = new BufferedOutput();

        self::command(EvidenceFixture::nothingObserved())
            ->execute(new ArrayInput('compliance:report'), $text);

        $exit = self::command(EvidenceFixture::nothingObserved())->execute(
            new ArrayInput('compliance:report', options: ['format' => 'json']),
            $json,
        );

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var mixed $decoded */
        $decoded = json_decode($json->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('pulsar.compliance', $decoded['report'] ?? null);
        self::assertSame(JsonReportRenderer::SCHEMA_VERSION, $decoded['schema_version'] ?? null);
        self::assertSame(['pci_dss'], $decoded['frameworks'] ?? null);

        $summary = $decoded['summary'] ?? null;
        self::assertIsArray($summary);
        self::assertSame(1, $summary['gaps'] ?? null);
        self::assertSame(0, $summary['satisfied'] ?? null);
        self::assertSame(1, $summary['operator_checklist'] ?? null);
        self::assertSame(0.0, $summary['probed_coverage_percent'] ?? null);

        $controls = $decoded['controls'] ?? null;
        self::assertIsArray($controls);

        $gap = self::controlById($controls, 'Req3.4');
        self::assertSame('unsatisfied', $gap['outcome'] ?? null);
        self::assertSame('probe.pan_at_rest', is_array($gap['probe'] ?? null) ? $gap['probe']['id'] : null);
        self::assertSame(['Configure a durable token store.'], $gap['remediations'] ?? null);

        $evidence = $gap['evidence'] ?? null;
        self::assertIsArray($evidence);
        self::assertIsArray($evidence[0] ?? null);
        self::assertSame(ObservationId::TokenVaultPersistence->value, $evidence[0]['id']);
        self::assertFalse($evidence[0]['present']);
        self::assertSame('measured', $evidence[0]['grade']);

        // The same control, the same verdict, the same remediation as the human report.
        self::assertStringContainsString('[GAP ] Req3.4', $text->buffer);
        self::assertStringContainsString('Configure a durable token store.', $text->buffer);

        $checklist = self::controlById($controls, 'Req6.5');
        self::assertSame('operator_responsibility', $checklist['outcome'] ?? null);
        self::assertArrayHasKey('probe', $checklist);
        self::assertNull($checklist['probe']);
        self::assertStringContainsString('composer qa green', is_string($checklist['operator_artefact'] ?? null) ? $checklist['operator_artefact'] : '');
    }

    #[Test]
    public function jsonOutputKeepsStdoutParseableByRoutingTheVerdictToStderr(): void
    {
        $output = new BufferedOutput();

        self::command(EvidenceFixture::nothingObserved())->execute(
            new ArrayInput('compliance:report', options: ['format' => 'json']),
            $output,
        );

        json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('pci_dss Req3.4', $output->errorBuffer);
        self::assertStringNotContainsString('FAIL', $output->buffer);
    }

    #[Test]
    public function markdownOutputIsATableTheAssessorCanRead(): void
    {
        $output = new BufferedOutput();

        self::command(EvidenceFixture::nothingObserved())->execute(
            new ArrayInput('compliance:report', options: ['format' => 'markdown']),
            $output,
        );

        self::assertStringContainsString('# Compliance report', $output->buffer);
        self::assertStringContainsString('## pci_dss', $output->buffer);
        self::assertStringContainsString('| `Req3.4`', $output->buffer);
        self::assertStringContainsString('## Operator responsibilities', $output->buffer);
        self::assertStringContainsString('does not constitute compliance certification', $output->buffer);
    }

    #[Test]
    public function strictAlsoFailsOnAPartiallySatisfiedControl(): void
    {
        $catalog = new ControlCatalog();
        $catalog->register(ControlDeclaration::probed(
            id: 'Req3.4',
            framework: ComplianceFramework::PciDss,
            title: 'Render PAN Unreadable Anywhere It Is Stored',
            requirement: 'Render PAN unreadable anywhere it is stored.',
            probe: new PartialProbe(),
            subject: ControlSubject::CardholderData,
        ));

        $lenient = new BufferedOutput();
        $strict = new BufferedOutput();

        // The vault is observed and the second fact the control needs is not, so
        // the Partial is reached from the evidence rather than declared by a stub.
        $partly = EvidenceFixture::everythingObservedExcept(ObservationId::BackupPrimitiveResolved);

        self::assertSame(
            ExitCode::Success->value,
            self::commandFor($catalog, $partly)
                ->execute(new ArrayInput('compliance:report'), $lenient),
        );

        self::assertSame(
            ExitCode::Error->value,
            self::commandFor($catalog, $partly)
                ->execute(new ArrayInput('compliance:report', options: ['strict' => true]), $strict),
        );

        // The failure names the outcome, so a residual gap failing only under
        // --strict is not read as a control the deployment never showed at all.
        self::assertStringContainsString('not fully observed', $strict->errorBuffer);
        self::assertStringContainsString('[partial] pci_dss Req3.4', $strict->errorBuffer);
        self::assertStringNotContainsString('FAIL', $lenient->errorBuffer);
    }

    #[Test]
    public function anUnknownFormatIsInvalidRatherThanAFailedAssessment(): void
    {
        $output = new BufferedOutput();

        $exit = self::command(EvidenceFixture::everythingObserved())->execute(
            new ArrayInput('compliance:report', options: ['format' => 'yaml']),
            $output,
        );

        self::assertSame(ExitCode::Invalid->value, $exit);
        self::assertStringContainsString('Unknown --format', $output->errorBuffer);
        self::assertSame('', $output->buffer);
    }

    #[Test]
    public function aFrameworkTheDeploymentDidNotEnableIsRefused(): void
    {
        $output = new BufferedOutput();

        $exit = self::command(EvidenceFixture::everythingObserved())->execute(
            new ArrayInput('compliance:report', options: ['framework' => 'gdpr']),
            $output,
        );

        self::assertSame(ExitCode::Invalid->value, $exit);
        self::assertStringContainsString('Unknown or disabled --framework', $output->errorBuffer);
    }

    #[Test]
    public function enablingNoFrameworkIsInvalidAndNeverAPass(): void
    {
        $output = new BufferedOutput();

        $command = new ComplianceReportCommand(
            self::catalog(),
            EvidenceFixture::everythingObserved(),
            new ComplianceConfig(enabledFrameworks: []),
            self::app(),
        );

        $exit = $command->execute(new ArrayInput('compliance:report'), $output);

        self::assertSame(ExitCode::Invalid->value, $exit);
        self::assertStringContainsString('nothing to assess', $output->errorBuffer);
    }

    #[Test]
    public function anEnabledFrameworkWithNoMappingIsAGapAndNotACleanSheet(): void
    {
        $output = new BufferedOutput();

        $command = new ComplianceReportCommand(
            new ControlCatalog(),
            EvidenceFixture::everythingObserved(),
            new ComplianceConfig(enabledFrameworks: [ComplianceFramework::PciDss]),
            self::app(),
        );

        $exit = $command->execute(new ArrayInput('compliance:report'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('No control is declared for enabled framework(s): pci_dss', $output->errorBuffer);
    }

    #[Test]
    public function evidenceThatCouldNotBeGatheredIsInvalidRatherThanAFailingReport(): void
    {
        $output = new BufferedOutput();

        $command = new ComplianceReportCommand(
            self::catalog(),
            new ThrowingEvidenceSource(),
            new ComplianceConfig(enabledFrameworks: [ComplianceFramework::PciDss]),
            self::app(),
        );

        $exit = $command->execute(new ArrayInput('compliance:report'), $output);

        self::assertSame(ExitCode::Invalid->value, $exit);
        self::assertStringContainsString('Compliance assessment failed', $output->errorBuffer);
        self::assertSame('', $output->buffer);
    }

    #[Test]
    public function theHeaderRecordsWhereTheReportWasProduced(): void
    {
        $output = new BufferedOutput();

        self::command(EvidenceFixture::everythingObserved())
            ->execute(new ArrayInput('compliance:report'), $output);

        self::assertStringContainsString('application   acme-payments (production)', $output->buffer);
        self::assertStringContainsString('sapi=', $output->buffer);
        self::assertStringContainsString('config/compliance.php enabled_frameworks', $output->buffer);
    }

    /**
     * @param array<array-key, mixed> $controls
     *
     * @return array<string, mixed>
     */
    private static function controlById(array $controls, string $id): array
    {
        /** @var mixed $control */
        foreach ($controls as $control) {
            if (is_array($control) && ($control['id'] ?? null) === $id) {
                /** @var array<string, mixed> $control */
                return $control;
            }
        }

        self::fail('No control "' . $id . '" in the JSON report.');
    }

    private static function command(EvidenceSourceInterface $evidence): ComplianceReportCommand
    {
        return self::commandFor(self::catalog(), $evidence);
    }

    private static function commandFor(
        ControlCatalog $catalog,
        EvidenceSourceInterface $evidence,
    ): ComplianceReportCommand {
        return new ComplianceReportCommand(
            $catalog,
            $evidence,
            new ComplianceConfig(enabledFrameworks: [ComplianceFramework::PciDss]),
            self::app(),
        );
    }

    /**
     * A two-control catalogue: one the software can observe, and one it cannot
     * and therefore refuses to grade.
     */
    private static function catalog(): ControlCatalog
    {
        $catalog = new ControlCatalog();

        $catalog->register(
            ControlDeclaration::probed(
                id: 'Req3.4',
                framework: ComplianceFramework::PciDss,
                title: 'Render PAN Unreadable Anywhere It Is Stored',
                requirement: 'Render PAN unreadable anywhere it is stored by using strong '
                    . 'one-way hash functions, truncation, index tokens, or strong cryptography.',
                probe: new RecordingProbe(
                    'probe.pan_at_rest',
                    ObservationId::TokenVaultPersistence,
                    'Configure a durable token store.',
                ),
                subject: ControlSubject::CardholderData,
            ),
            ControlDeclaration::operatorResponsibility(
                id: 'Req6.5',
                framework: ComplianceFramework::PciDss,
                title: 'Address Common Coding Vulnerabilities',
                requirement: 'Address common coding vulnerabilities in software development.',
                artefact: 'CI run showing composer qa green for the deployed commit',
            ),
        );

        return $catalog;
    }

    private static function app(): AppConfig
    {
        return new AppConfig(
            name: 'acme-payments',
            mode: EnvironmentMode::Production,
            debug: false,
            timezone: 'UTC',
            locale: 'en',
        );
    }
}
