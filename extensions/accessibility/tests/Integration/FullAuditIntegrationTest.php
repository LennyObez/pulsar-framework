<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Audit\AccessibilityAuditor;
use Pulsar\Extension\Accessibility\Validator\AltTextValidator;
use Pulsar\Extension\Accessibility\Validator\FormLabelValidator;
use Pulsar\Extension\Accessibility\Validator\HeadingHierarchyValidator;
use Pulsar\Extension\Accessibility\Validator\LandmarkStructureValidator;

use function count;

final class FullAuditIntegrationTest extends TestCase
{
    private AccessibilityAuditor $auditor;

    protected function setUp(): void
    {
        $this->auditor = new AccessibilityAuditor([
            new HeadingHierarchyValidator(),
            new FormLabelValidator(),
            new AltTextValidator(),
            new LandmarkStructureValidator(),
        ]);
    }

    #[Test]
    public function full_audit_detects_known_issues_in_sample_html(): void
    {
        $html = <<<'HTML'
            <h1>My Page</h1>
            <h3>Skipped heading level</h3>
            <img src="hero.jpg">
            <img src="logo.png" alt="logo">
            <input type="text" name="email">
            <select name="country"><option>US</option></select>
            HTML;

        $report = $this->auditor->auditHtml($html);

        // Heading skip: h1 -> h3
        $headingSkips = array_filter(
            $report->violations,
            static fn($v) => $v->rule === 'heading-level-skip',
        );
        self::assertCount(1, $headingSkips);

        // Missing alt on hero.jpg
        $missingAlts = array_filter(
            $report->violations,
            static fn($v) => $v->rule === 'missing-alt',
        );
        self::assertCount(1, $missingAlts);

        // Generic alt "logo"
        $genericAlts = array_filter(
            $report->violations,
            static fn($v) => $v->rule === 'generic-alt-text',
        );
        self::assertCount(1, $genericAlts);

        // Missing labels for input and select
        $missingLabels = array_filter(
            $report->violations,
            static fn($v) => $v->rule === 'missing-form-label',
        );
        self::assertCount(2, $missingLabels);

        // Missing main and nav landmarks
        $missingMain = array_filter(
            $report->violations,
            static fn($v) => $v->rule === 'missing-main-landmark',
        );
        self::assertCount(1, $missingMain);

        $missingNav = array_filter(
            $report->violations,
            static fn($v) => $v->rule === 'missing-nav-landmark',
        );
        self::assertCount(1, $missingNav);

        // Verify overall counts
        self::assertTrue($report->hasErrors());
        self::assertSame(4, $report->checksRun);
    }

    #[Test]
    public function full_audit_on_well_formed_html_returns_no_errors(): void
    {
        $html = <<<'HTML'
            <nav aria-label="Main">
                <a href="/">Home</a>
            </nav>
            <main>
                <h1>Welcome</h1>
                <h2>About</h2>
                <p>Content here.</p>
                <img src="photo.jpg" alt="A beautiful landscape at sunset">
                <label for="email">Email</label>
                <input id="email" type="email">
            </main>
            HTML;

        $report = $this->auditor->auditHtml($html);

        self::assertCount(0, $report->errors());
        self::assertFalse($report->hasErrors());
    }

    #[Test]
    public function full_audit_file_integration(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y_int_');
        self::assertNotFalse($tmpFile);

        $html = '<h1>Page</h1><h3>Skip</h3><img src="test.jpg">';
        file_put_contents($tmpFile, $html);

        try {
            $report = $this->auditor->auditTemplateFile($tmpFile);

            self::assertSame(1, $report->filesAudited);
            self::assertSame(4, $report->checksRun);
            self::assertTrue($report->hasErrors());

            // Verify report serialization works end-to-end
            $array = $report->toArray();
            self::assertArrayHasKey('summary', $array);
            self::assertArrayHasKey('violations', $array);
            self::assertArrayHasKey('limitations', $array);
            self::assertArrayHasKey('disclaimer', $array);
            self::assertArrayHasKey('timestamp', $array);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function summary_counts_match_filtered_violations(): void
    {
        $html = <<<'HTML'
            <h1>Title</h1>
            <h1>Duplicate H1</h1>
            <h4>Skipped</h4>
            <img src="a.jpg">
            HTML;

        $report = $this->auditor->auditHtml($html);
        $summary = $report->summary();

        self::assertSame(count($report->errors()), $summary->errorCount);
        self::assertSame(count($report->warnings()), $summary->warningCount);
        self::assertSame(count($report->infos()), $summary->infoCount);
        self::assertSame(
            $summary->errorCount + $summary->warningCount + $summary->infoCount,
            $summary->totalViolations,
        );
    }
}
