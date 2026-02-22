<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\HeadingHierarchyValidator;
use Pulsar\Extension\Accessibility\Validator\Severity;

final class HeadingHierarchyValidatorTest extends TestCase
{
    private HeadingHierarchyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new HeadingHierarchyValidator();
    }

    #[Test]
    public function valid_heading_hierarchy_produces_no_violations(): void
    {
        $html = '<h1>Title</h1><h2>Section</h2><h3>Subsection</h3>';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function heading_skip_detects_error_with_wcag_criterion(): void
    {
        $html = '<h1>Title</h1><h3>Skipped h2</h3>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('heading-level-skip', $violations[0]->rule);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('1.3.1', $violations[0]->wcagCriterion);
    }

    #[Test]
    public function multiple_h1_elements_detects_warning(): void
    {
        $html = '<h1>First</h1><h1>Second</h1>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('multiple-h1', $violations[0]->rule);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('1.3.1', $violations[0]->wcagCriterion);
        self::assertStringContainsString('2 total', $violations[0]->message);
    }

    #[Test]
    public function empty_html_produces_no_violations(): void
    {
        $violations = $this->validator->validate('');

        self::assertSame([], $violations);
    }

    #[Test]
    public function html_with_no_headings_produces_no_violations(): void
    {
        $html = '<p>Just a paragraph.</p><div>Some content</div>';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function descending_heading_levels_are_allowed(): void
    {
        $html = '<h1>Title</h1><h2>Section</h2><h3>Sub</h3><h2>Another section</h2>';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function multiple_h1_and_skip_both_reported(): void
    {
        $html = '<h1>First</h1><h1>Second</h1><h4>Skipped</h4>';

        $violations = $this->validator->validate($html);

        self::assertCount(2, $violations);

        $rules = array_map(static fn($v) => $v->rule, $violations);
        self::assertContains('multiple-h1', $rules);
        self::assertContains('heading-level-skip', $rules);
    }
}
