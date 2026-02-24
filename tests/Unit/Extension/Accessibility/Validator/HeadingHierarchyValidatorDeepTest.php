<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\HeadingHierarchyValidator;
use Pulsar\Extension\Accessibility\Validator\Severity;

#[CoversClass(HeadingHierarchyValidator::class)]
final class HeadingHierarchyValidatorDeepTest extends TestCase
{
    private HeadingHierarchyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new HeadingHierarchyValidator();
    }

    #[Test]
    public function noHeadingsReturnsEmpty(): void
    {
        $violations = $this->validator->validate('<p>No headings</p>');

        self::assertSame([], $violations);
    }

    #[Test]
    public function sequentialHeadingsAreValid(): void
    {
        $html = '<h1>Title</h1><h2>Subtitle</h2><h3>Section</h3>';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function skippedLevelIsError(): void
    {
        $html = '<h1>Title</h1><h3>Skipped</h3>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('heading-level-skip', $violations[0]->rule);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('1.3.1', $violations[0]->wcagCriterion);
    }

    #[Test]
    public function multipleH1sAreWarning(): void
    {
        $html = '<h1>First</h1><h1>Second</h1>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('multiple-h1', $violations[0]->rule);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function threeH1sProduceTwoWarnings(): void
    {
        $html = '<h1>First</h1><h1>Second</h1><h1>Third</h1>';

        $violations = $this->validator->validate($html);

        $multipleH1 = array_filter($violations, fn($v) => $v->rule === 'multiple-h1');
        self::assertCount(2, $multipleH1);
    }

    #[Test]
    public function multipleSkippedLevels(): void
    {
        $html = '<h1>Title</h1><h4>Deep skip</h4>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('heading-level-skip', $violations[0]->rule);
    }

    #[Test]
    public function descendingLevelsAreValid(): void
    {
        $html = '<h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2><h3>E</h3>';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function h2FollowedByH1IsValid(): void
    {
        // Going back up is not a skip
        $html = '<h1>First</h1><h2>Sub</h2><h1>Second</h1>';

        $violations = $this->validator->validate($html);

        // Only the multiple-h1 warning, no skip
        $skips = array_filter($violations, fn($v) => $v->rule === 'heading-level-skip');
        self::assertCount(0, $skips);
    }

    #[Test]
    public function violationHasElementSnippet(): void
    {
        $html = '<h1>Title</h1><h3>Skip</h3>';

        $violations = $this->validator->validate($html);

        self::assertStringContainsString('h3', $violations[0]->element);
    }

    #[Test]
    public function emptyHtmlReturnsEmpty(): void
    {
        self::assertSame([], $this->validator->validate(''));
    }
}
