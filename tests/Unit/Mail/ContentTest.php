<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Content;

#[CoversClass(Content::class)]
final class ContentTest extends TestCase
{
    #[Test]
    public function constructorStoresBothHtmlAndText(): void
    {
        $content = new Content(html: '<h1>Hello</h1>', text: 'Hello');

        self::assertSame('<h1>Hello</h1>', $content->html);
        self::assertSame('Hello', $content->text);
    }

    #[Test]
    public function defaultsToNull(): void
    {
        $content = new Content();

        self::assertNull($content->html);
        self::assertNull($content->text);
    }

    #[Test]
    public function htmlOnly(): void
    {
        $content = new Content(html: '<p>HTML only</p>');

        self::assertSame('<p>HTML only</p>', $content->html);
        self::assertNull($content->text);
    }

    #[Test]
    public function textOnly(): void
    {
        $content = new Content(text: 'Text only');

        self::assertNull($content->html);
        self::assertSame('Text only', $content->text);
    }
}
