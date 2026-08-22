<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\AltTextValidator;
use Pulsar\Extension\Accessibility\Validator\Severity;

final class AltTextValidatorTest extends TestCase
{
    private AltTextValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AltTextValidator();
    }

    #[Test]
    public function image_with_alt_text_produces_no_violations(): void
    {
        $html = '<img src="photo.jpg" alt="A sunset over the mountains">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function image_without_alt_attribute_detects_error(): void
    {
        $html = '<img src="photo.jpg">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('missing-alt', $violations[0]->rule);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('1.1.1', $violations[0]->wcagCriterion);
    }

    #[Test]
    public function image_with_empty_alt_for_decorative_produces_no_violations(): void
    {
        $html = '<img src="divider.png" alt="">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function image_with_generic_alt_detects_warning(): void
    {
        $html = '<img src="photo.jpg" alt="image">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('generic-alt-text', $violations[0]->rule);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('1.1.1', $violations[0]->wcagCriterion);
    }

    #[Test]
    public function image_with_role_presentation_produces_no_violations(): void
    {
        $html = '<img src="decorative.png" role="presentation">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function image_with_aria_hidden_produces_no_violations(): void
    {
        $html = '<img src="decorative.png" aria-hidden="true">';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function multiple_images_reports_each_issue(): void
    {
        $html = '<img src="a.jpg"><img src="b.jpg" alt="photo"><img src="c.jpg" alt="Good description">';

        $violations = $this->validator->validate($html);

        self::assertCount(2, $violations);

        $rules = array_map(static fn($v) => $v->rule, $violations);
        self::assertContains('missing-alt', $rules);
        self::assertContains('generic-alt-text', $rules);
    }

    #[Test]
    public function html_with_no_images_produces_no_violations(): void
    {
        $html = '<p>No images here.</p>';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function generic_alt_text_is_case_insensitive(): void
    {
        $html = '<img src="photo.jpg" alt="PHOTO">';

        $violations = $this->validator->validate($html);

        self::assertCount(1, $violations);
        self::assertSame('generic-alt-text', $violations[0]->rule);
    }
}
