<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CompareBlock;

use function count;

#[CoversClass(CompareBlock::class)]
final class CompareBlockTest extends TestCase
{
    private CompareBlock $block;

    protected function setUp(): void
    {
        $this->block = new CompareBlock();
    }

    #[Test]
    public function typeReturnsCompare(): void
    {
        self::assertSame('compare', $this->block->type());
    }

    #[Test]
    public function schemaContainsRequiredFields(): void
    {
        $schema = $this->block->schema();

        self::assertSame('object', $schema['type']);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('beforeImage', $schema['properties']);
        self::assertArrayHasKey('afterImage', $schema['properties']);
        self::assertIsArray($schema['required']);
        self::assertContains('beforeImage', $schema['required']);
        self::assertContains('afterImage', $schema['required']);
    }

    #[Test]
    public function renderProducesCustomElement(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/img/before.jpg', 'alt' => 'Before shot'],
            'afterImage' => ['src' => '/img/after.jpg', 'alt' => 'After shot'],
        ]);

        self::assertStringContainsString('<cms-image-compare', $html);
        self::assertStringContainsString('data-before-src="/img/before.jpg"', $html);
        self::assertStringContainsString('data-after-src="/img/after.jpg"', $html);
        self::assertStringContainsString('data-before-alt="Before shot"', $html);
        self::assertStringContainsString('data-after-alt="After shot"', $html);
    }

    #[Test]
    public function renderWithDefaultLabels(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/a.jpg', 'alt' => 'A'],
            'afterImage' => ['src' => '/b.jpg', 'alt' => 'B'],
        ]);

        self::assertStringContainsString('data-before-label="Before"', $html);
        self::assertStringContainsString('data-after-label="After"', $html);
    }

    #[Test]
    public function renderWithCustomLabels(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/a.jpg', 'alt' => 'A'],
            'afterImage' => ['src' => '/b.jpg', 'alt' => 'B'],
            'beforeLabel' => 'Old',
            'afterLabel' => 'New',
        ]);

        self::assertStringContainsString('data-before-label="Old"', $html);
        self::assertStringContainsString('data-after-label="New"', $html);
    }

    #[Test]
    public function renderWithCaptionWrapsInFigure(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/a.jpg', 'alt' => 'A'],
            'afterImage' => ['src' => '/b.jpg', 'alt' => 'B'],
            'caption' => 'Comparison result',
        ]);

        self::assertStringContainsString('<figure>', $html);
        self::assertStringContainsString('<figcaption>Comparison result</figcaption>', $html);
        self::assertStringContainsString('</figure>', $html);
    }

    #[Test]
    public function renderWithoutCaptionNoFigure(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/a.jpg', 'alt' => 'A'],
            'afterImage' => ['src' => '/b.jpg', 'alt' => 'B'],
        ]);

        self::assertStringNotContainsString('<figure>', $html);
    }

    #[Test]
    public function renderEscapesHtmlInAttributes(): void
    {
        $html = $this->block->render([
            'beforeImage' => ['src' => '/a.jpg', 'alt' => 'Image "quoted" & <tagged>'],
            'afterImage' => ['src' => '/b.jpg', 'alt' => 'Normal'],
        ]);

        self::assertStringNotContainsString('<tagged>', $html);
        self::assertStringContainsString('&amp;', $html);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        $errors = $this->block->validate([
            'beforeImage' => ['src' => '/a.jpg', 'alt' => 'Before'],
            'afterImage' => ['src' => '/b.jpg', 'alt' => 'After'],
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateReturnsErrorsForMissingBeforeImage(): void
    {
        $errors = $this->block->validate([
            'afterImage' => ['src' => '/b.jpg', 'alt' => 'After'],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('beforeImage', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorsForMissingAfterImage(): void
    {
        $errors = $this->block->validate([
            'beforeImage' => ['src' => '/a.jpg', 'alt' => 'Before'],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('afterImage', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorsForMissingSrcAndAlt(): void
    {
        $errors = $this->block->validate([
            'beforeImage' => ['src' => '/a.jpg'],
            'afterImage' => [],
        ]);

        self::assertNotEmpty($errors);
        // Missing beforeImage.alt and afterImage.src and afterImage.alt
        self::assertGreaterThanOrEqual(2, count($errors));
    }

    #[Test]
    public function validateRejectsNonArrayImages(): void
    {
        $errors = $this->block->validate([
            'beforeImage' => 'not-an-array',
            'afterImage' => 42,
        ]);

        self::assertCount(2, $errors);
    }
}
