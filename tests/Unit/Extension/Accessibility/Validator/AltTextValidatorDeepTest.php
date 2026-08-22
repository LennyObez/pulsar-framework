<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\AltTextValidator;
use Pulsar\Extension\Accessibility\Validator\Severity;

#[CoversClass(AltTextValidator::class)]
final class AltTextValidatorDeepTest extends TestCase
{
    private AltTextValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AltTextValidator();
    }

    #[Test]
    public function noImagesReturnsEmpty(): void
    {
        $violations = $this->validator->validate('<p>No images here</p>');

        self::assertSame([], $violations);
    }

    #[Test]
    public function missingAltAttributeIsError(): void
    {
        $violations = $this->validator->validate('<img src="photo.jpg">');

        self::assertCount(1, $violations);
        self::assertSame('missing-alt', $violations[0]->rule);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('1.1.1', $violations[0]->wcagCriterion);
    }

    #[Test]
    public function emptyAltIsAccepted(): void
    {
        $violations = $this->validator->validate('<img src="decoration.png" alt="">');

        self::assertSame([], $violations);
    }

    #[Test]
    public function descriptiveAltIsAccepted(): void
    {
        $violations = $this->validator->validate('<img src="chart.png" alt="Sales chart for Q4 2024">');

        self::assertSame([], $violations);
    }

    #[Test]
    public function genericAltImageIsWarning(): void
    {
        $violations = $this->validator->validate('<img src="x.jpg" alt="image">');

        self::assertCount(1, $violations);
        self::assertSame('generic-alt-text', $violations[0]->rule);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function genericAltPhotoIsWarning(): void
    {
        $violations = $this->validator->validate('<img src="x.jpg" alt="Photo">');

        self::assertCount(1, $violations);
        self::assertSame('generic-alt-text', $violations[0]->rule);
    }

    #[Test]
    public function genericAltIconIsWarning(): void
    {
        $violations = $this->validator->validate('<img src="x.svg" alt="Icon">');

        self::assertCount(1, $violations);
        self::assertSame('generic-alt-text', $violations[0]->rule);
    }

    #[Test]
    public function genericAltLogoIsWarning(): void
    {
        $violations = $this->validator->validate('<img src="x.png" alt="logo">');

        self::assertCount(1, $violations);
        self::assertSame('generic-alt-text', $violations[0]->rule);
    }

    #[Test]
    public function genericAltScreenshotIsWarning(): void
    {
        $violations = $this->validator->validate('<img src="x.png" alt="Screenshot">');

        self::assertCount(1, $violations);
        self::assertSame('generic-alt-text', $violations[0]->rule);
    }

    #[Test]
    public function genericAltGraphicIsWarning(): void
    {
        $violations = $this->validator->validate('<img src="x.png" alt="graphic">');

        self::assertCount(1, $violations);
    }

    #[Test]
    public function genericAltPictureIsWarning(): void
    {
        $violations = $this->validator->validate('<img src="x.png" alt="PICTURE">');

        self::assertCount(1, $violations);
    }

    #[Test]
    public function rolePresentationIsExempt(): void
    {
        $violations = $this->validator->validate('<img src="x.png" role="presentation">');

        self::assertSame([], $violations);
    }

    #[Test]
    public function ariaHiddenTrueIsExempt(): void
    {
        $violations = $this->validator->validate('<img src="x.png" aria-hidden="true">');

        self::assertSame([], $violations);
    }

    #[Test]
    public function multipleViolations(): void
    {
        $html = '<img src="a.jpg"><img src="b.jpg" alt="image"><img src="c.jpg" alt="A detailed description">';

        $violations = $this->validator->validate($html);

        self::assertCount(2, $violations);
        self::assertSame('missing-alt', $violations[0]->rule);
        self::assertSame('generic-alt-text', $violations[1]->rule);
    }

    #[Test]
    public function emptyHtmlReturnsEmpty(): void
    {
        $violations = $this->validator->validate('');

        self::assertSame([], $violations);
    }

    #[Test]
    public function violationHasLineNumber(): void
    {
        $violations = $this->validator->validate('<img src="x.jpg">');

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->line);
    }

    #[Test]
    public function violationHasElementSnippet(): void
    {
        $violations = $this->validator->validate('<img src="test.jpg">');

        self::assertCount(1, $violations);
        self::assertStringContainsString('img', $violations[0]->element);
    }
}
