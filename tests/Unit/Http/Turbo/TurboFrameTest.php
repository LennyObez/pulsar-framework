<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Turbo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Turbo\TurboFrame;

#[CoversClass(TurboFrame::class)]
final class TurboFrameTest extends TestCase
{
    #[Test]
    public function creates_frame_with_content(): void
    {
        $frame = TurboFrame::create('comments', '<p>Comment 1</p>');
        $html = $frame->toHtml();

        self::assertStringContainsString('id="comments"', $html);
        self::assertStringContainsString('<p>Comment 1</p>', $html);
        self::assertStringStartsWith('<pulsar-frame', $html);
        self::assertStringEndsWith('</pulsar-frame>', $html);
    }

    #[Test]
    public function lazy_frame_has_src_and_loading(): void
    {
        $frame = TurboFrame::lazy('sidebar', '/sidebar', 'Loading...');
        $html = $frame->toHtml();

        self::assertStringContainsString('src="/sidebar"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('Loading...', $html);
    }

    #[Test]
    public function with_target(): void
    {
        $frame = TurboFrame::create('nav')
            ->withTarget('_top');
        $html = $frame->toHtml();

        self::assertStringContainsString('target="_top"', $html);
    }

    #[Test]
    public function with_disabled(): void
    {
        $frame = TurboFrame::create('form')
            ->withDisabled();
        $html = $frame->toHtml();

        self::assertStringContainsString('disabled', $html);
    }

    #[Test]
    public function with_disabled_false(): void
    {
        $frame = TurboFrame::create('form')
            ->withDisabled(false);
        $html = $frame->toHtml();

        self::assertStringNotContainsString('disabled', $html);
    }

    #[Test]
    public function with_custom_attribute(): void
    {
        $frame = TurboFrame::create('chat')
            ->withAttribute('data-channel', 'room-1');
        $html = $frame->toHtml();

        self::assertStringContainsString('data-channel="room-1"', $html);
    }

    #[Test]
    public function id_is_html_escaped(): void
    {
        $frame = TurboFrame::create('id"with"quotes');
        $html = $frame->toHtml();

        self::assertStringContainsString('id="id&quot;with&quot;quotes"', $html);
    }

    #[Test]
    public function id_accessor(): void
    {
        $frame = TurboFrame::create('my-frame');

        self::assertSame('my-frame', $frame->id());
    }

    #[Test]
    public function immutability(): void
    {
        $original = TurboFrame::create('a', 'content');
        $modified = $original->withTarget('_top');

        self::assertStringNotContainsString('target=', $original->toHtml());
        self::assertStringContainsString('target="_top"', $modified->toHtml());
    }

    #[Test]
    public function empty_content(): void
    {
        $frame = TurboFrame::create('empty');
        $html = $frame->toHtml();

        self::assertSame('<pulsar-frame id="empty"></pulsar-frame>', $html);
    }
}
