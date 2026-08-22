<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\FormLabelValidator;
use Pulsar\Extension\Accessibility\Validator\Severity;

final class FormLabelValidatorGroupTest extends TestCase
{
    private FormLabelValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FormLabelValidator();
    }

    #[Test]
    public function radioGroupWithoutFieldsetProducesWarning(): void
    {
        $html = '
            <label for="r1">Option A</label>
            <input type="radio" name="choice" id="r1" value="a">
            <label for="r2">Option B</label>
            <input type="radio" name="choice" id="r2" value="b">
        ';

        $violations = $this->validator->validate($html);

        $fieldsetViolations = array_filter(
            $violations,
            static fn($v): bool => $v->rule === 'missing-fieldset',
        );

        self::assertNotEmpty($fieldsetViolations);
        $violation = reset($fieldsetViolations);
        self::assertNotFalse($violation);
        self::assertSame(Severity::Warning, $violation->severity);
        self::assertSame('1.3.1', $violation->wcagCriterion);
        self::assertStringContainsString('radio', $violation->message);
        self::assertStringContainsString('choice', $violation->message);
    }

    #[Test]
    public function radioGroupInsideFieldsetProducesNoWarning(): void
    {
        $html = '
            <fieldset>
                <legend>Choose</legend>
                <label for="r1">Option A</label>
                <input type="radio" name="choice" id="r1" value="a">
                <label for="r2">Option B</label>
                <input type="radio" name="choice" id="r2" value="b">
            </fieldset>
        ';

        $violations = $this->validator->validate($html);

        $fieldsetViolations = array_filter(
            $violations,
            static fn($v): bool => $v->rule === 'missing-fieldset',
        );

        self::assertEmpty($fieldsetViolations);
    }

    #[Test]
    public function checkboxGroupWithoutFieldsetProducesWarning(): void
    {
        $html = '
            <label for="c1">Opt A</label>
            <input type="checkbox" name="opts" id="c1" value="a">
            <label for="c2">Opt B</label>
            <input type="checkbox" name="opts" id="c2" value="b">
        ';

        $violations = $this->validator->validate($html);

        $fieldsetViolations = array_filter(
            $violations,
            static fn($v): bool => $v->rule === 'missing-fieldset',
        );

        self::assertNotEmpty($fieldsetViolations);
        $violation = reset($fieldsetViolations);
        self::assertNotFalse($violation);
        self::assertStringContainsString('checkbox', $violation->message);
    }

    #[Test]
    public function groupWithRoleGroupProducesNoWarning(): void
    {
        $html = '
            <div role="group" aria-label="Choices">
                <label for="r1">Option A</label>
                <input type="radio" name="choice" id="r1" value="a">
                <label for="r2">Option B</label>
                <input type="radio" name="choice" id="r2" value="b">
            </div>
        ';

        $violations = $this->validator->validate($html);

        $fieldsetViolations = array_filter(
            $violations,
            static fn($v): bool => $v->rule === 'missing-fieldset',
        );

        self::assertEmpty($fieldsetViolations);
    }

    #[Test]
    public function singleRadioDoesNotTriggerGroupCheck(): void
    {
        $html = '<label for="r1">Yes</label><input type="radio" name="agree" id="r1" value="yes">';

        $violations = $this->validator->validate($html);

        $fieldsetViolations = array_filter(
            $violations,
            static fn($v): bool => $v->rule === 'missing-fieldset',
        );

        self::assertEmpty($fieldsetViolations);
    }

    #[Test]
    public function radioWithoutNameAttributeIsIgnoredForGroupCheck(): void
    {
        $html = '
            <label for="r1">A</label><input type="radio" id="r1">
            <label for="r2">B</label><input type="radio" id="r2">
        ';

        $violations = $this->validator->validate($html);

        $fieldsetViolations = array_filter(
            $violations,
            static fn($v): bool => $v->rule === 'missing-fieldset',
        );

        self::assertEmpty($fieldsetViolations);
    }

    #[Test]
    public function emptyHtmlProducesNoViolations(): void
    {
        $violations = $this->validator->validate('');

        self::assertSame([], $violations);
    }

    #[Test]
    public function imageTypeInputProducesNoViolation(): void
    {
        $html = '<input type="image" src="submit.png" alt="Submit">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function resetTypeInputProducesNoViolation(): void
    {
        $html = '<input type="reset" value="Reset">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function inputWithNoTypeDefaultsToText(): void
    {
        // Default type is "text", which needs a label
        $html = '<input name="field">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-form-label', $violations[0]->rule);
    }

    #[Test]
    public function violationIncludesElementSnippet(): void
    {
        $html = '<input type="text" name="email">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertStringContainsString('input', $violations[0]->element);
    }

    #[Test]
    public function violationIncludesLineNumber(): void
    {
        $html = '<input type="text" name="field">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->line);
    }

    #[Test]
    public function multipleUngroupedRadioGroupsDetected(): void
    {
        $html = '
            <label for="r1">A</label><input type="radio" name="group1" id="r1" value="a">
            <label for="r2">B</label><input type="radio" name="group1" id="r2" value="b">
            <label for="r3">C</label><input type="radio" name="group2" id="r3" value="c">
            <label for="r4">D</label><input type="radio" name="group2" id="r4" value="d">
        ';

        $violations = $this->validator->validate($html);

        $fieldsetViolations = array_filter(
            $violations,
            static fn($v): bool => $v->rule === 'missing-fieldset',
        );

        self::assertCount(2, $fieldsetViolations);
    }
}
