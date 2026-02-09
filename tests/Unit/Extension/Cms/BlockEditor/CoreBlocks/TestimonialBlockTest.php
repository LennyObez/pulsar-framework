<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\TestimonialBlock;

#[CoversClass(TestimonialBlock::class)]
final class TestimonialBlockTest extends TestCase
{
    private TestimonialBlock $block;

    protected function setUp(): void
    {
        $this->block = new TestimonialBlock();
    }

    #[Test]
    public function typeReturnsTestimonial(): void
    {
        self::assertSame('testimonial', $this->block->type());
    }

    #[Test]
    public function rendersWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render([
            'quote' => 'Great product!',
            'author' => 'Jane Doe',
        ]);

        self::assertStringContainsString('<blockquote class="testimonial">', $html);
        self::assertStringContainsString('<p class="testimonial__quote">Great product!</p>', $html);
        self::assertStringContainsString('<cite class="testimonial__author">Jane Doe</cite>', $html);
        self::assertStringNotContainsString('testimonial__rating', $html);
        self::assertStringNotContainsString('testimonial__avatar', $html);
        self::assertStringNotContainsString('testimonial__role', $html);
    }

    #[Test]
    public function rendersWithAllOptionalFields(): void
    {
        $html = $this->block->render([
            'quote' => 'Amazing!',
            'author' => 'John',
            'role' => 'CEO',
            'avatarUrl' => '/avatar.jpg',
            'rating' => 5,
        ]);

        self::assertStringContainsString('testimonial__rating', $html);
        self::assertStringContainsString("\u{2605}\u{2605}\u{2605}\u{2605}\u{2605}", $html);
        self::assertStringContainsString('<img src="/avatar.jpg" alt="John" class="testimonial__avatar">', $html);
        self::assertStringContainsString('<span class="testimonial__role">CEO</span>', $html);
    }

    #[Test]
    public function rendersCorrectStarCount(): void
    {
        $html = $this->block->render([
            'quote' => 'Good.',
            'author' => 'Alice',
            'rating' => 3,
        ]);

        self::assertStringContainsString("\u{2605}\u{2605}\u{2605}", $html);
        self::assertStringNotContainsString("\u{2605}\u{2605}\u{2605}\u{2605}", $html);
    }

    #[Test]
    public function escapesXssInQuote(): void
    {
        $html = $this->block->render([
            'quote' => '<script>xss</script>',
            'author' => 'Author',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesXssInAuthor(): void
    {
        $html = $this->block->render([
            'quote' => 'Fine.',
            'author' => '<img onerror="xss">',
        ]);

        self::assertStringNotContainsString('<img onerror', $html);
    }

    #[Test]
    public function validatesRequiredQuote(): void
    {
        $errors = $this->block->validate(['author' => 'Jane']);

        self::assertContains('quote is required and must be a string', $errors);
    }

    #[Test]
    public function validatesRequiredAuthor(): void
    {
        $errors = $this->block->validate(['quote' => 'Hello']);

        self::assertContains('author is required and must be a string', $errors);
    }

    #[Test]
    public function validatesRatingOutOfRange(): void
    {
        $errors = $this->block->validate([
            'quote' => 'Hello',
            'author' => 'Jane',
            'rating' => 6,
        ]);

        self::assertContains('rating must be an integer between 1 and 5', $errors);
    }

    #[Test]
    public function validatesRatingBelowMinimum(): void
    {
        $errors = $this->block->validate([
            'quote' => 'Hello',
            'author' => 'Jane',
            'rating' => 0,
        ]);

        self::assertContains('rating must be an integer between 1 and 5', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'quote' => 'Excellent!',
            'author' => 'Jane Doe',
            'rating' => 4,
        ]);

        self::assertSame([], $errors);
    }
}
