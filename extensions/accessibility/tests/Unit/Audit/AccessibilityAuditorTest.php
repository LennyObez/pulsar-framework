<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Audit\AccessibilityAuditor;
use Pulsar\Extension\Accessibility\Validator\AltTextValidator;
use Pulsar\Extension\Accessibility\Validator\HeadingHierarchyValidator;

use function count;

final class AccessibilityAuditorTest extends TestCase
{
    #[Test]
    public function audit_html_with_valid_html_returns_no_errors(): void
    {
        $auditor = new AccessibilityAuditor([
            new HeadingHierarchyValidator(),
        ]);

        $report = $auditor->auditHtml('<h1>Title</h1><h2>Section</h2>');

        self::assertCount(0, $report->errors());
        self::assertSame(1, $report->checksRun);
        self::assertSame(0, $report->filesAudited);
    }

    #[Test]
    public function audit_html_with_issues_returns_violations(): void
    {
        $auditor = new AccessibilityAuditor([
            new HeadingHierarchyValidator(),
            new AltTextValidator(),
        ]);

        $html = '<h1>Title</h1><h3>Skip</h3><img src="test.jpg">';
        $report = $auditor->auditHtml($html);

        self::assertGreaterThan(0, count($report->violations));
        self::assertSame(2, $report->checksRun);
    }

    #[Test]
    public function audit_template_file_reads_and_audits_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y_tpl_');
        self::assertNotFalse($tmpFile);

        file_put_contents($tmpFile, '<h1>Title</h1><h2>Section</h2>');

        try {
            $auditor = new AccessibilityAuditor([
                new HeadingHierarchyValidator(),
            ]);

            $report = $auditor->auditTemplateFile($tmpFile);

            self::assertCount(0, $report->violations);
            self::assertSame(1, $report->filesAudited);
            self::assertSame(1, $report->checksRun);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function audit_template_file_with_nonexistent_path_returns_empty_report(): void
    {
        $auditor = new AccessibilityAuditor([
            new HeadingHierarchyValidator(),
        ]);

        $report = $auditor->auditTemplateFile('/nonexistent/file.html');

        self::assertCount(0, $report->violations);
        self::assertSame(0, $report->checksRun);
        self::assertSame(0, $report->filesAudited);
    }

    #[Test]
    public function audit_directory_audits_matching_files(): void
    {
        $tmpDir = sys_get_temp_dir() . '/a11y_test_' . uniqid();
        mkdir($tmpDir);

        file_put_contents($tmpDir . '/page1.html', '<h1>Title</h1><h3>Skip</h3>');
        file_put_contents($tmpDir . '/page2.html', '<img src="test.jpg">');

        try {
            $auditor = new AccessibilityAuditor([
                new HeadingHierarchyValidator(),
                new AltTextValidator(),
            ]);

            $report = $auditor->auditDirectory($tmpDir, '*.html');

            self::assertSame(2, $report->filesAudited);
            self::assertSame(4, $report->checksRun); // 2 validators * 2 files
            self::assertGreaterThan(0, count($report->violations));
        } finally {
            @unlink($tmpDir . '/page1.html');
            @unlink($tmpDir . '/page2.html');
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function audit_directory_with_nonexistent_path_returns_empty_report(): void
    {
        $auditor = new AccessibilityAuditor([
            new HeadingHierarchyValidator(),
        ]);

        $report = $auditor->auditDirectory('/nonexistent/directory');

        self::assertCount(0, $report->violations);
        self::assertSame(0, $report->filesAudited);
    }

    #[Test]
    public function audit_with_no_validators_returns_empty_report(): void
    {
        $auditor = new AccessibilityAuditor([]);

        $report = $auditor->auditHtml('<h1>Title</h1><h3>Skip</h3>');

        self::assertCount(0, $report->violations);
        self::assertSame(0, $report->checksRun);
    }
}
