<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Newsletter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\NewsletterBlock;

#[CoversClass(NewsletterBlock::class)]
final class NewsletterBlockTest extends TestCase
{
    private NewsletterBlock $block;

    protected function setUp(): void
    {
        $this->block = new NewsletterBlock();
    }

    #[Test]
    public function typeReturnsNewsletter(): void
    {
        self::assertSame('newsletter', $this->block->type());
    }

    #[Test]
    public function schemaReturnsValidStructure(): void
    {
        $schema = $this->block->schema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);

        $properties = $schema['properties'];
        self::assertIsArray($properties);
        self::assertArrayHasKey('heading', $properties);
        self::assertArrayHasKey('buttonText', $properties);

        self::assertArrayHasKey('required', $schema);
        $required = $schema['required'];
        self::assertIsArray($required);
        self::assertContains('heading', $required);
        self::assertContains('buttonText', $required);
    }

    #[Test]
    public function renderProducesWebComponentWithAttributes(): void
    {
        $html = $this->block->render([
            'heading' => 'Join Us',
            'buttonText' => 'Subscribe',
        ]);

        self::assertStringContainsString('<cms-newsletter-signup', $html);
        self::assertStringContainsString('data-heading="Join Us"', $html);
        self::assertStringContainsString('data-button-text="Subscribe"', $html);
        self::assertStringContainsString('</cms-newsletter-signup>', $html);
    }

    #[Test]
    public function renderIncludesDescriptionWhenProvided(): void
    {
        $html = $this->block->render([
            'heading' => 'Newsletter',
            'description' => 'Stay updated',
            'buttonText' => 'Go',
        ]);

        self::assertStringContainsString('data-description="Stay updated"', $html);
    }

    #[Test]
    public function renderOmitsDescriptionAttributeWhenEmpty(): void
    {
        $html = $this->block->render([
            'heading' => 'Newsletter',
            'buttonText' => 'Go',
        ]);

        self::assertStringNotContainsString('data-description', $html);
    }

    #[Test]
    public function renderEscapesHtmlSpecialCharacters(): void
    {
        $html = $this->block->render([
            'heading' => 'A&B <script>',
            'buttonText' => '"Click"',
        ]);

        self::assertStringContainsString('A&amp;B &lt;script&gt;', $html);
        self::assertStringContainsString('&quot;Click&quot;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function validateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'heading' => 'Newsletter',
            'buttonText' => 'Subscribe',
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateAcceptsOptionalDescriptionAndSuccessMessage(): void
    {
        $errors = $this->block->validate([
            'heading' => 'Newsletter',
            'buttonText' => 'Subscribe',
            'description' => 'Stay updated',
            'successMessage' => 'Thanks!',
        ]);

        self::assertSame([], $errors);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $expectedPatterns
     */
    #[Test]
    #[DataProvider('provideInvalidData')]
    public function validateRejectsInvalidData(array $data, array $expectedPatterns): void
    {
        $errors = $this->block->validate($data);

        self::assertNotEmpty($errors);

        foreach ($expectedPatterns as $pattern) {
            $found = false;

            foreach ($errors as $error) {
                if (str_contains($error, $pattern)) {
                    $found = true;
                    break;
                }
            }

            self::assertTrue($found, "Expected error containing '{$pattern}' not found in: " . implode(', ', $errors));
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function provideInvalidData(): iterable
    {
        yield 'missing heading' => [
            ['buttonText' => 'Subscribe'],
            ['heading'],
        ];

        yield 'missing buttonText' => [
            ['heading' => 'Newsletter'],
            ['buttonText'],
        ];

        yield 'empty heading' => [
            ['heading' => '', 'buttonText' => 'Go'],
            ['heading'],
        ];

        yield 'non-string description' => [
            ['heading' => 'Newsletter', 'buttonText' => 'Go', 'description' => 42],
            ['description'],
        ];

        yield 'non-string successMessage' => [
            ['heading' => 'Newsletter', 'buttonText' => 'Go', 'successMessage' => []],
            ['successMessage'],
        ];

        yield 'both missing' => [
            [],
            ['heading', 'buttonText'],
        ];
    }
}
