<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\Compliance\FrameworkLabels;
use Pulsar\Extension\Cms\BlockEditor\Compliance\ObservedComplianceSourceInterface;
use Pulsar\Extension\Cms\BlockEditor\Compliance\ObservedFrameworkStatus;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ComplianceBadgeBlock;

/**
 * The badge may not outrun the report.
 *
 * The tests that matter here are the negative ones: an author cannot type a
 * status, cannot supply a label, cannot attach a certification seal, and cannot
 * name a framework the report has never heard of. Everything the block prints
 * about an outcome arrives from an assessment.
 */
#[CoversClass(ComplianceBadgeBlock::class)]
#[CoversClass(FrameworkLabels::class)]
final class ComplianceBadgeBlockTest extends TestCase
{
    private ComplianceBadgeBlock $block;

    #[Override]
    protected function setUp(): void
    {
        $this->block = new ComplianceBadgeBlock(new StubComplianceSource([
            'soc2' => new ObservedFrameworkStatus(
                frameworkKey: 'soc2',
                label: 'SOC 2',
                assessed: 18,
                satisfied: 9,
                partial: 5,
                gaps: 4,
                operatorChecklist: 24,
                generatedAt: '2026-08-20T09:00:00+00:00',
                environment: 'production',
            ),
            'iso27001' => new ObservedFrameworkStatus(
                frameworkKey: 'iso27001',
                label: 'ISO 27001',
                assessed: 8,
                satisfied: 3,
                partial: 4,
                gaps: 1,
                operatorChecklist: 2,
                generatedAt: '2026-08-20T09:00:00+00:00',
                environment: 'staging',
            ),
        ]));
    }

    public function testType(): void
    {
        self::assertSame('compliance-badge', $this->block->type());
    }

    public function testItRendersTheObservedCountsForAnAssessedFramework(): void
    {
        $html = $this->block->render(['badges' => [['framework' => 'soc2']]]);

        self::assertStringContainsString('SOC 2', $html);
        self::assertStringContainsString('9 of 18 controls observed', $html);
        self::assertStringContainsString('50.0%', $html);
    }

    public function testItAlwaysRendersTheAssessmentDate(): void
    {
        $html = $this->block->render(['badges' => [['framework' => 'soc2']]]);

        self::assertStringContainsString('assessed 2026-08-20T09:00:00+00:00', $html);
        self::assertStringContainsString('<time', $html);
    }

    public function testItNamesTheOrganizationalControlsItDidNotAssess(): void
    {
        $html = $this->block->render(['badges' => [['framework' => 'soc2']]]);

        self::assertStringContainsString('24 organizational controls not assessed here', $html);
    }

    public function testItDisclosesANonProductionEnvironment(): void
    {
        $html = $this->block->render(['badges' => [['framework' => 'iso27001']]]);

        self::assertStringContainsString('staging', $html);
    }

    public function testItDoesNotLabelAProductionAssessmentWithItsEnvironment(): void
    {
        $html = $this->block->render(['badges' => [['framework' => 'soc2']]]);

        self::assertStringNotContainsString('(production)', $html);
    }

    /**
     * The defect this block existed to enable: a public page asserting NIST CSF
     * conformity from a framework with no backup or restore primitive at all.
     */
    public function testAnUnassessedFrameworkRendersAsUnassessedRatherThanAsABareName(): void
    {
        $html = $this->block->render(['badges' => [['framework' => 'nist_csf']]]);

        self::assertStringContainsString('NIST CSF', $html);
        self::assertStringContainsString('not assessed', $html);
        self::assertStringContainsString('--unassessed', $html);
    }

    public function testAuthorSuppliedStatusTextIsNotRendered(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'soc2', 'status' => 'Certified']],
        ]);

        self::assertStringNotContainsString('Certified', $html);
    }

    public function testAuthorSuppliedLabelIsNotRendered(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'soc2', 'label' => 'SOC 2 Type II']],
        ]);

        self::assertStringNotContainsString('Type II', $html);
        self::assertStringContainsString('SOC 2', $html);
    }

    public function testAuthorSuppliedLogoIsNotRendered(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'soc2', 'logoUrl' => '/img/soc2-certified-seal.png']],
        ]);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('soc2-certified-seal', $html);
    }

    public function testAnUnknownFrameworkRendersNothing(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'fedramp-high']],
        ]);

        self::assertStringNotContainsString('fedramp', $html);
        // The container div remains; no badge is emitted inside it.
        self::assertStringNotContainsString('compliance-badge-block__badge--', $html);
    }

    /**
     * Content authored against the pre-report key space keeps working, and now
     * shows an observed result instead of an unevidenced one.
     */
    public function testLegacyHyphenatedKeysStillResolve(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'iso-27001']],
        ]);

        self::assertStringContainsString('ISO 27001', $html);
        self::assertStringContainsString('3 of 8 controls observed', $html);
    }

    public function testRenderWithUrlProducesALink(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'soc2', 'url' => '/compliance/soc2']],
        ]);

        self::assertStringContainsString('<a href="/compliance/soc2"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testRenderWithoutUrlUsesDiv(): void
    {
        $html = $this->block->render(['badges' => [['framework' => 'soc2']]]);

        self::assertStringContainsString('<div class="compliance-badge-block__badge', $html);
    }

    public function testRenderWithLayoutAndSize(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'soc2']],
            'layout' => 'grid',
            'size' => 'lg',
        ]);

        self::assertStringContainsString('compliance-badge-block--grid', $html);
        self::assertStringContainsString('compliance-badge-block--lg', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => '<script>xss</script>']],
            'title' => '"><img src=x>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateRequiresBadges(): void
    {
        self::assertContains('badges is required and must be an array', $this->block->validate([]));
    }

    public function testValidateRejectsAFrameworkNoReportCanEmit(): void
    {
        $errors = $this->block->validate([
            'badges' => [['framework' => 'fedramp-high']],
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('not a framework the compliance report can produce', $errors[0]);
    }

    public function testValidateRejectsRemovedClaimFieldsRatherThanIgnoringThem(): void
    {
        foreach (['label', 'status', 'logoUrl'] as $field) {
            $errors = $this->block->validate([
                'badges' => [['framework' => 'soc2', $field => 'anything']],
            ]);

            self::assertNotSame([], $errors, "$field should be rejected");
            self::assertStringContainsString('never author-supplied text or imagery', $errors[0]);
        }
    }

    public function testValidateRejectsInvalidLayout(): void
    {
        $errors = $this->block->validate([
            'badges' => [['framework' => 'soc2']],
            'layout' => 'masonry',
        ]);

        self::assertNotSame([], $errors);
    }

    public function testValidateAcceptsFrameworkAndUrlOnly(): void
    {
        $errors = $this->block->validate([
            'badges' => [['framework' => 'soc2', 'url' => '/soc2']],
            'layout' => 'grid',
            'size' => 'md',
        ]);

        self::assertSame([], $errors);
    }
}

/**
 * A source whose answers are fixed by the test, standing in for a report file.
 */
final readonly class StubComplianceSource implements ObservedComplianceSourceInterface
{
    /**
     * @param array<string, ObservedFrameworkStatus> $statuses
     */
    public function __construct(
        private array $statuses = [],
    ) {}

    #[Override]
    public function statusFor(string $frameworkKey): ?ObservedFrameworkStatus
    {
        return $this->statuses[$frameworkKey] ?? null;
    }
}
