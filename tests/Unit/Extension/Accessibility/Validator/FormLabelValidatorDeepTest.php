<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\FormLabelValidator;
use Pulsar\Extension\Accessibility\Validator\Severity;

#[CoversClass(FormLabelValidator::class)]
final class FormLabelValidatorDeepTest extends TestCase
{
    private FormLabelValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FormLabelValidator();
    }

    #[Test]
    public function inputWithForLabelIsValid(): void
    {
        $html = '<label for="name">Name</label><input id="name" type="text">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function inputWrappedInLabelIsValid(): void
    {
        $html = '<label>Name <input type="text"></label>';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function inputWithAriaLabelIsValid(): void
    {
        $html = '<input type="text" aria-label="Search">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function inputWithAriaLabelledbyIsValid(): void
    {
        $html = '<span id="lbl">Name</span><input type="text" aria-labelledby="lbl">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function inputWithTitleIsValid(): void
    {
        $html = '<input type="text" title="Enter your name">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function inputWithoutLabelIsError(): void
    {
        $html = '<input type="text">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('4.1.2', $violations[0]->wcagCriterion);
    }

    #[Test]
    public function selectWithoutLabelIsError(): void
    {
        $html = '<select><option>A</option></select>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
    }

    #[Test]
    public function textareaWithoutLabelIsError(): void
    {
        $html = '<textarea></textarea>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
    }

    #[Test]
    public function hiddenInputIsSkipped(): void
    {
        $html = '<input type="hidden" name="csrf">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function submitInputIsSkipped(): void
    {
        $html = '<input type="submit" value="Send">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function buttonInputIsSkipped(): void
    {
        $html = '<input type="button" value="Click">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function imageInputIsSkipped(): void
    {
        $html = '<input type="image" src="submit.png">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function resetInputIsSkipped(): void
    {
        $html = '<input type="reset" value="Reset">';

        self::assertSame([], $this->validator->validate($html));
    }

    #[Test]
    public function radioGroupWithoutFieldsetIsWarning(): void
    {
        $html = '<input type="radio" name="color" value="red"><input type="radio" name="color" value="blue">';

        $violations = $this->validator->validate($html);

        $fieldset = array_values(array_filter($violations, fn($v) => $v->rule === 'missing-fieldset'));
        self::assertCount(1, $fieldset);
        self::assertSame(Severity::Warning, $fieldset[0]->severity);
        self::assertSame('1.3.1', $fieldset[0]->wcagCriterion);
    }

    #[Test]
    public function radioGroupInsideFieldsetIsValid(): void
    {
        $html = '<fieldset><legend>Color</legend>'
            . '<input type="radio" name="color" value="red" aria-label="Red">'
            . '<input type="radio" name="color" value="blue" aria-label="Blue">'
            . '</fieldset>';

        $violations = $this->validator->validate($html);

        $fieldset = array_filter($violations, fn($v) => $v->rule === 'missing-fieldset');
        self::assertCount(0, $fieldset);
    }

    #[Test]
    public function checkboxGroupWithoutFieldsetIsWarning(): void
    {
        $html = '<input type="checkbox" name="opt" value="a" aria-label="A">'
            . '<input type="checkbox" name="opt" value="b" aria-label="B">';

        $violations = $this->validator->validate($html);

        $fieldset = array_filter($violations, fn($v) => $v->rule === 'missing-fieldset');
        self::assertCount(1, $fieldset);
    }

    #[Test]
    public function radioGroupWithRoleGroupIsValid(): void
    {
        $html = '<div role="group"><input type="radio" name="size" value="s" aria-label="S">'
            . '<input type="radio" name="size" value="m" aria-label="M"></div>';

        $violations = $this->validator->validate($html);

        $fieldset = array_filter($violations, fn($v) => $v->rule === 'missing-fieldset');
        self::assertCount(0, $fieldset);
    }

    #[Test]
    public function singleRadioNotGrouped(): void
    {
        $html = '<input type="radio" name="single" value="only" aria-label="Only">';

        $violations = $this->validator->validate($html);

        // Single radio doesn't need fieldset
        $fieldset = array_filter($violations, fn($v) => $v->rule === 'missing-fieldset');
        self::assertCount(0, $fieldset);
    }

    #[Test]
    public function inputWithIdButNoMatchingLabel(): void
    {
        $html = '<input type="text" id="name"><label for="email">Email</label>';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
    }

    #[Test]
    public function noFormElementsReturnsEmpty(): void
    {
        self::assertSame([], $this->validator->validate('<p>No forms</p>'));
    }

    #[Test]
    public function emptyHtmlReturnsEmpty(): void
    {
        self::assertSame([], $this->validator->validate(''));
    }

    #[Test]
    public function inputWithNoTypeDefaultsToText(): void
    {
        // No type attribute defaults to "text", which needs a label
        $html = '<input name="foo">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
    }
}
