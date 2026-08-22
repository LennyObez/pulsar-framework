<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Content;

use League\CommonMark\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Content\MarkdownRenderer;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;

#[CoversClass(MarkdownRenderer::class)]
final class MarkdownRendererTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $renderer = new MarkdownRenderer();

        self::assertInstanceOf(MarkdownRendererInterface::class, $renderer);
    }

    #[Test]
    public function emptyStringReturnsEmpty(): void
    {
        $renderer = new MarkdownRenderer();

        self::assertSame('', $renderer->render(''));
    }

    #[Test]
    public function constructorAcceptsCustomNestingLevel(): void
    {
        // Should not throw: verifies the constructor parameter
        $renderer = new MarkdownRenderer(maxNestingLevel: 5);

        self::assertInstanceOf(MarkdownRendererInterface::class, $renderer);
    }

    #[Test]
    public function renderThrowsDueToDoubleStrikethroughRegistration(): void
    {
        // The source code registers both GithubFlavoredMarkdownExtension (which includes
        // StrikethroughExtension) AND a standalone StrikethroughExtension. This causes
        // a delimiter processor conflict. This test documents the known issue.
        $renderer = new MarkdownRenderer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot add two delimiter processors');

        $renderer->render('Hello world');
    }
}
