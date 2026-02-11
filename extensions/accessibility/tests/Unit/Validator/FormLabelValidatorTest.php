<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\FormLabelValidator;
use Pulsar\Extension\Accessibility\Validator\Severity;

final class FormLabelValidatorTest extends TestCase
{
    private FormLabelValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FormLabelValidator();
    }

    #[Test]
    public function input_with_matching_label_produces_no_violations(): void
    {
        $html = '<label for="name">Name</label><input id="name" type="text">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function input_wrapped_in_label_produces_no_violations(): void
    {
        $html = '<label>Name <input type="text"></label>';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function input_without_any_label_detects_error(): void
    {
        $html = '<input type="text">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('4.1.2', $violations[0]->wcagCriterion);
    }

    #[Test]
    public function hidden_input_produces_no_violation(): void
    {
        $html = '<input type="hidden" name="token" value="abc">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function submit_button_produces_no_violation(): void
    {
        $html = '<input type="submit" value="Send">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function select_without_label_detects_error(): void
    {
        $html = '<select name="country"><option>US</option></select>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function textarea_without_label_detects_error(): void
    {
        $html = '<textarea name="bio"></textarea>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function input_with_aria_label_produces_no_violations(): void
    {
        $html = '<input type="text" aria-label="Search">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function input_with_aria_labelledby_produces_no_violations(): void
    {
        $html = '<span id="lbl">Search</span><input type="text" aria-labelledby="lbl">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function input_with_title_produces_no_violations(): void
    {
        $html = '<input type="text" title="Search field">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function button_type_input_produces_no_violation(): void
    {
        $html = '<input type="button" value="Click me">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }
}
