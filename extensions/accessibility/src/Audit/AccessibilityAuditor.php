<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Audit;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Accessibility\Validator\AccessibilityViolation;
use Pulsar\Extension\Accessibility\Validator\ValidatorInterface;

use function array_merge;
use function count;
use function file_get_contents;
use function glob;
use function is_dir;
use function is_file;

/**
 * Orchestrates WCAG validators against HTML content.
 *
 * Runs all registered validators and aggregates results into an audit report.
 */
#[Api(since: '1.0.0')]
final readonly class AccessibilityAuditor
{
    /** @var list<ValidatorInterface> */
    private array $validators;

    /**
     * @param list<ValidatorInterface> $validators
     */
    public function __construct(array $validators)
    {
        $this->validators = $validators;
    }

    /**
     * Audit a raw HTML string.
     */
    public function auditHtml(string $html): AuditReport
    {
        $violations = [];

        foreach ($this->validators as $validator) {
            $violations = array_merge($violations, $validator->validate($html));
        }

        return new AuditReport(
            violations: $violations,
            checksRun: count($this->validators),
            filesAudited: 0,
            timestamp: new DateTimeImmutable(),
        );
    }

    /**
     * Audit a single template file.
     */
    public function auditTemplateFile(string $path): AuditReport
    {
        if (!is_file($path)) {
            return new AuditReport(
                violations: [],
                checksRun: 0,
                filesAudited: 0,
                timestamp: new DateTimeImmutable(),
            );
        }

        $html = file_get_contents($path);

        if ($html === false) {
            return new AuditReport(
                violations: [],
                checksRun: 0,
                filesAudited: 0,
                timestamp: new DateTimeImmutable(),
            );
        }

        $violations = [];

        foreach ($this->validators as $validator) {
            $violations = array_merge($violations, $validator->validate($html));
        }

        return new AuditReport(
            violations: $violations,
            checksRun: count($this->validators),
            filesAudited: 1,
            timestamp: new DateTimeImmutable(),
        );
    }

    /**
     * Audit all files matching a glob pattern in a directory.
     */
    public function auditDirectory(string $path, string $pattern = '*.php'): AuditReport
    {
        if (!is_dir($path)) {
            return new AuditReport(
                violations: [],
                checksRun: 0,
                filesAudited: 0,
                timestamp: new DateTimeImmutable(),
            );
        }

        $globPattern = rtrim($path, '/\\') . '/' . $pattern;
        $files = glob($globPattern);

        if ($files === false || $files === []) {
            return new AuditReport(
                violations: [],
                checksRun: count($this->validators),
                filesAudited: 0,
                timestamp: new DateTimeImmutable(),
            );
        }

        /** @var list<AccessibilityViolation> $allViolations */
        $allViolations = [];

        foreach ($files as $file) {
            $html = file_get_contents($file);

            if ($html === false) {
                continue;
            }

            foreach ($this->validators as $validator) {
                $allViolations = array_merge($allViolations, $validator->validate($html));
            }
        }

        return new AuditReport(
            violations: $allViolations,
            checksRun: count($this->validators) * count($files),
            filesAudited: count($files),
            timestamp: new DateTimeImmutable(),
        );
    }
}
