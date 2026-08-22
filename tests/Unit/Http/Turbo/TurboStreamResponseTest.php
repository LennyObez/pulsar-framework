<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Turbo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\Turbo\TurboStream;
use Pulsar\Http\Turbo\TurboStreamResponse;

#[CoversClass(TurboStreamResponse::class)]
final class TurboStreamResponseTest extends TestCase
{
    #[Test]
    public function empty_response(): void
    {
        $response = TurboStreamResponse::create()->toResponse();

        self::assertSame('', $response->body);
        self::assertSame(
            'text/vnd.turbo-stream.html; charset=utf-8',
            $response->headers->first('Content-Type'),
        );
    }

    #[Test]
    public function single_stream(): void
    {
        $response = TurboStreamResponse::create()
            ->append('list', '<li>Item</li>')
            ->toResponse();

        self::assertStringContainsString('action="append"', $response->body);
        self::assertStringContainsString('target="list"', $response->body);
        self::assertStringContainsString('<li>Item</li>', $response->body);
    }

    #[Test]
    public function multiple_streams(): void
    {
        $response = TurboStreamResponse::create()
            ->append('messages', '<p>New</p>')
            ->update('counter', '<span>5</span>')
            ->remove('notification-1')
            ->toResponse();

        $streams = $response->body;

        self::assertStringContainsString('action="append"', $streams);
        self::assertStringContainsString('action="update"', $streams);
        self::assertStringContainsString('action="remove"', $streams);
    }

    #[Test]
    public function with_stream_adds_stream_object(): void
    {
        $stream = TurboStream::replace('item', '<div>Replaced</div>');

        $response = TurboStreamResponse::create()
            ->withStream($stream)
            ->toResponse();

        self::assertStringContainsString('action="replace"', $response->body);
    }

    #[Test]
    public function prepend_convenience(): void
    {
        $response = TurboStreamResponse::create()
            ->prepend('feed', '<article>Latest</article>')
            ->toResponse();

        self::assertStringContainsString('action="prepend"', $response->body);
    }

    #[Test]
    public function replace_convenience(): void
    {
        $response = TurboStreamResponse::create()
            ->replace('card', '<div>New Card</div>')
            ->toResponse();

        self::assertStringContainsString('action="replace"', $response->body);
    }

    #[Test]
    public function update_convenience(): void
    {
        $response = TurboStreamResponse::create()
            ->update('title', '<h1>Updated</h1>')
            ->toResponse();

        self::assertStringContainsString('action="update"', $response->body);
    }

    #[Test]
    public function custom_status(): void
    {
        $response = TurboStreamResponse::create()
            ->append('log', '<p>Entry</p>')
            ->toResponse(ResponseStatus::Created);

        self::assertSame(ResponseStatus::Created, $response->status);
    }

    #[Test]
    public function streams_accessor(): void
    {
        $builder = TurboStreamResponse::create()
            ->append('a', '<p>1</p>')
            ->update('b', '<p>2</p>');

        self::assertCount(2, $builder->streams());
    }

    #[Test]
    public function immutability(): void
    {
        $original = TurboStreamResponse::create();
        $modified = $original->append('x', '<p>Y</p>');

        self::assertCount(0, $original->streams());
        self::assertCount(1, $modified->streams());
    }
}
