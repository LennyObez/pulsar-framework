<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function renderOutputsBlockquoteWithQuoteAndAuthor(): void
    {
        $html = $this->block->render([
            'quote' => 'Great product!',
            'author' => 'Jane Doe',
        ]);

        self::assertStringContainsString('<blockquote class="testimonial">', $html);
        self::assertStringContainsString('Great product!', $html);
        self::assertStringContainsString('Jane Doe', $html);
    }

    #[Test]
    public function renderIncludesStarRating(): void
    {
        $html = $this->block->render([
            'quote' => 'Amazing',
            'author' => 'John',
            'rating' => 5,
        ]);

        self::assertStringContainsString('testimonial__rating', $html);
    }

    #[Test]
    public function renderIncludesAvatar(): void
    {
        $html = $this->block->render([
            'quote' => 'Nice',
            'author' => 'Alice',
            'avatarUrl' => '/img/alice.jpg',
        ]);

        self::assertStringContainsString('testimonial__avatar', $html);
        self::assertStringContainsString('src="/img/alice.jpg"', $html);
    }

    #[Test]
    public function renderIncludesRole(): void
    {
        $html = $this->block->render([
            'quote' => 'Wonderful',
            'author' => 'Bob',
            'role' => 'CEO, Acme Inc.',
        ]);

        self::assertStringContainsString('testimonial__role', $html);
        self::assertStringContainsString('CEO, Acme Inc.', $html);
    }

    #[Test]
    public function renderOmitsRatingWhenOutOfRange(): void
    {
        $html = $this->block->render([
            'quote' => 'Test',
            'author' => 'Test',
            'rating' => 6,
        ]);

        self::assertStringNotContainsString('testimonial__rating', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingQuote(): void
    {
        $errors = $this->block->validate(['author' => 'Test']);

        self::assertStringContainsString('quote is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingAuthor(): void
    {
        $errors = $this->block->validate(['quote' => 'Test']);

        self::assertStringContainsString('author is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForOutOfRangeRating(): void
    {
        $errors = $this->block->validate([
            'quote' => 'Test',
            'author' => 'Test',
            'rating' => 0,
        ]);

        self::assertStringContainsString('between 1 and 5', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'quote' => 'Excellent service',
            'author' => 'Customer',
            'rating' => 5,
        ]));
    }
}
