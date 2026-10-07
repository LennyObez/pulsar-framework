<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\Compliance;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\Compliance\JsonReportComplianceSource;
use Pulsar\Extension\Cms\BlockEditor\Compliance\ObservedFrameworkStatus;

use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Every malformed input must yield "not assessed", never a partial reading.
 *
 * A page render is the wrong place to raise a compliance error, and the safe
 * direction for anything published to the public internet is to claim less.
 */
#[CoversClass(JsonReportComplianceSource::class)]
#[CoversClass(ObservedFrameworkStatus::class)]
final class JsonReportComplianceSourceTest extends TestCase
{
    private string $file = '';

    #[Override]
    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pulsar-report-');
        self::assertIsString($file);
        $this->file = $file;
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->file !== '') {
            @unlink($this->file);
        }
    }

    public function testItTalliesOutcomesPerFramework(): void
    {
        $status = $this->sourceFor($this->report([
            ['framework' => 'soc2', 'outcome' => 'satisfied'],
            ['framework' => 'soc2', 'outcome' => 'satisfied'],
            ['framework' => 'soc2', 'outcome' => 'partial'],
            ['framework' => 'soc2', 'outcome' => 'unsatisfied'],
            ['framework' => 'soc2', 'outcome' => 'operator_responsibility'],
            ['framework' => 'gdpr', 'outcome' => 'satisfied'],
        ]))->statusFor('soc2');

        self::assertInstanceOf(ObservedFrameworkStatus::class, $status);
        self::assertSame(2, $status->satisfied);
        self::assertSame(1, $status->partial);
        self::assertSame(1, $status->gaps);
        self::assertSame(1, $status->operatorChecklist);
        self::assertSame(4, $status->assessed);
        self::assertSame(50.0, $status->coveragePercent());
    }

    /**
     * NotApplicable rests on an operator assertion rather than on anything
     * observed, so it must not inflate either figure.
     */
    public function testNotApplicableIsExcludedFromBothFigures(): void
    {
        $status = $this->sourceFor($this->report([
            ['framework' => 'soc2', 'outcome' => 'satisfied'],
            ['framework' => 'soc2', 'outcome' => 'not_applicable'],
        ]))->statusFor('soc2');

        self::assertInstanceOf(ObservedFrameworkStatus::class, $status);
        self::assertSame(1, $status->assessed);
        self::assertSame(0, $status->operatorChecklist);
    }

    public function testAFrameworkAbsentFromTheReportIsNull(): void
    {
        $source = $this->sourceFor($this->report([
            ['framework' => 'soc2', 'outcome' => 'satisfied'],
        ]));

        self::assertNull($source->statusFor('nist_csf'));
    }

    public function testAMissingFileYieldsNothing(): void
    {
        $source = new JsonReportComplianceSource($this->file . '-does-not-exist');

        self::assertNull($source->statusFor('soc2'));
    }

    public function testUnreadableJsonYieldsNothing(): void
    {
        file_put_contents($this->file, '{ this is not json');

        self::assertNull(new JsonReportComplianceSource($this->file)->statusFor('soc2'));
    }

    public function testADocumentThatIsNotAComplianceReportYieldsNothing(): void
    {
        file_put_contents($this->file, (string) json_encode(['report' => 'something.else', 'controls' => []]));

        self::assertNull(new JsonReportComplianceSource($this->file)->statusFor('soc2'));
    }

    /**
     * Reading a moved field out of a shape this class was not written against
     * and publishing the result would be worse than showing nothing.
     */
    public function testAnUnknownSchemaVersionYieldsNothing(): void
    {
        $report = $this->report([['framework' => 'soc2', 'outcome' => 'satisfied']]);
        $report['schema_version'] = 2;

        file_put_contents($this->file, (string) json_encode($report));

        self::assertNull(new JsonReportComplianceSource($this->file)->statusFor('soc2'));
    }

    public function testItCarriesTheAssessmentTimestampAndEnvironment(): void
    {
        $status = $this->sourceFor($this->report([
            ['framework' => 'soc2', 'outcome' => 'satisfied'],
        ]))->statusFor('soc2');

        self::assertInstanceOf(ObservedFrameworkStatus::class, $status);
        self::assertSame('2026-08-20T09:00:00+00:00', $status->generatedAt);
        self::assertSame('production', $status->environment);
    }

    public function testAFrameworkWithOnlyOperatorControlsHasNoProbedControls(): void
    {
        $status = $this->sourceFor($this->report([
            ['framework' => 'soc2', 'outcome' => 'operator_responsibility'],
        ]))->statusFor('soc2');

        self::assertInstanceOf(ObservedFrameworkStatus::class, $status);
        self::assertFalse($status->hasProbedControls());
        self::assertSame(0.0, $status->coveragePercent());
    }

    /**
     * @param list<array{framework: string, outcome: string}> $controls
     *
     * @return array<string, mixed>
     */
    private function report(array $controls): array
    {
        return [
            'report' => 'pulsar.compliance',
            'schema_version' => 1,
            'generated_at' => '2026-08-20T09:00:00+00:00',
            'application' => ['name' => 'Pulsar', 'environment' => 'production'],
            'controls' => $controls,
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function sourceFor(array $report): JsonReportComplianceSource
    {
        file_put_contents($this->file, (string) json_encode($report));

        return new JsonReportComplianceSource($this->file);
    }
}
