<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Turbo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Turbo\TurboStream;
use Pulsar\Http\Turbo\TurboStreamAction;

#[CoversClass(TurboStream::class)]
final class TurboStreamTest extends TestCase
{
    #[Test]
    public function append_renders_correct_html(): void
    {
        $stream = TurboStream::append('messages', '<div>Hello</div>');
        $html = $stream->toHtml();

        self::assertStringContainsString('action="append"', $html);
        self::assertStringContainsString('target="messages"', $html);
        self::assertStringContainsString('<template><div>Hello</div></template>', $html);
        self::assertStringStartsWith('<pulsar-stream', $html);
        self::assertStringEndsWith('</pulsar-stream>', $html);
    }

    #[Test]
    public function prepend_renders(): void
    {
        $stream = TurboStream::prepend('list', '<li>First</li>');

        self::assertSame(TurboStreamAction::Prepend, $stream->action());
        self::assertSame('list', $stream->target());
        self::assertSame('<li>First</li>', $stream->html());
    }

    #[Test]
    public function replace_renders(): void
    {
        $stream = TurboStream::replace('item-1', '<div id="item-1">Updated</div>');

        self::assertSame(TurboStreamAction::Replace, $stream->action());
        self::assertStringContainsString('action="replace"', $stream->toHtml());
    }

    #[Test]
    public function update_renders(): void
    {
        $stream = TurboStream::update('counter', '<span>42</span>');

        self::assertSame(TurboStreamAction::Update, $stream->action());
    }

    #[Test]
    public function remove_renders_without_template(): void
    {
        $stream = TurboStream::remove('deleted-item');
        $html = $stream->toHtml();

        self::assertSame(TurboStreamAction::Remove, $stream->action());
        self::assertStringNotContainsString('<template>', $html);
        self::assertSame('', $stream->html());
    }

    #[Test]
    public function before_renders(): void
    {
        $stream = TurboStream::before('ref', '<div>Before</div>');

        self::assertSame(TurboStreamAction::Before, $stream->action());
    }

    #[Test]
    public function after_renders(): void
    {
        $stream = TurboStream::after('ref', '<div>After</div>');

        self::assertSame(TurboStreamAction::After, $stream->action());
    }

    #[Test]
    public function target_is_html_escaped(): void
    {
        $stream = TurboStream::append('item"with"quotes', '<p>Test</p>');
        $html = $stream->toHtml();

        self::assertStringContainsString('target="item&quot;with&quot;quotes"', $html);
    }
}
