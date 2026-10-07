<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\AI;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Provider\AnthropicProvider;
use Pulsar\AI\Streaming\AiStreamEventType;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Response\StreamedResponse;
use Pulsar\Tests\Unit\AI\Streaming\Support\EventLog;
use Pulsar\Tests\Unit\AI\Streaming\Support\FakeStreamTransport;

use function implode;
use function is_string;
use function json_encode;
use function str_contains;

/**
 * A model streamed to a browser, all the way through the kernel.
 *
 * This is the case the whole feature exists for, and it only works if nothing
 * on the path buffers: the provider's generator, the kernel's dispatch, and
 * {@see StreamedResponse} all have to stay lazy. The interleaving assertion is
 * what proves that — a buffered implementation produces the same bytes and
 * fails this test.
 *
 * {@see StreamedResponse::getSource()} is what {@see \Pulsar\Http\ResponseEmitter}
 * iterates, so asserting on it is asserting on what reaches the socket.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(StreamedResponse::class)]
#[CoversClass(AnthropicProvider::class)]
final class StreamedModelThroughKernelTest extends TestCase
{
    #[Test]
    public function deltasReachTheEmitterAsTheModelProducesThemRatherThanAllAtTheEnd(): void
    {
        // Both sides of the interleaving are recorded into one list, so the
        // order asserted below is the order the two actually happened in.
        $log = new EventLog();

        $transport = new FakeStreamTransport(
            self::modelFrames(),
            static function (int $index) use ($log): void {
                $log->record('upstream:' . $index);
            },
        );

        $kernel = new Kernel();
        $kernel->router()->get('/chat', fn(): StreamedResponse => self::sseResponse($transport));

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/chat'));

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));

        // Nothing has been read from the model yet: the handler returned a
        // response, not an answer.
        self::assertCount(0, $log->events(), 'the handler returned a response, not an answer');

        $emitted = [];

        /** @var mixed $chunk */
        foreach ($response->getSource() as $chunk) {
            self::assertIsString($chunk);
            $emitted[] = $chunk;
            $log->record('emitted');
        }

        self::assertSame(
            [
                'upstream:0',
                'upstream:1',
                'upstream:2',
                'emitted',
                'upstream:3',
                'emitted',
                'upstream:4',
                'upstream:5',
                'upstream:6',
                'emitted',
            ],
            $log->events(),
            'a buffered path emits nothing until every upstream chunk has been read',
        );

        self::assertSame(
            [
                'data: {"text":"Hello"}' . "\n\n",
                'data: {"text":", world"}' . "\n\n",
                'event: done' . "\n" . 'data: {"finish_reason":"stop","input_tokens":12,"output_tokens":7}' . "\n\n",
            ],
            $emitted,
        );
    }

    #[Test]
    public function tokenAccountingSurvivesTheWholePathToTheBrowser(): void
    {
        $response = self::sseResponse(new FakeStreamTransport(self::modelFrames()));

        $body = (string) $response->getBody();

        // The counts are the provider's own, folded by the accumulator and
        // carried in the terminal frame. A streamed call that could not report
        // them would be a call no budget could charge.
        self::assertStringContainsString('"input_tokens":12', $body);
        self::assertStringContainsString('"output_tokens":7', $body);
    }

    #[Test]
    public function aMiddlewareThatReadsTheBodyDoesNotEmptyTheStreamForTheEmitter(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get(
            '/chat',
            fn(): StreamedResponse => self::sseResponse(new FakeStreamTransport(self::modelFrames())),
        );

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/chat'));
        self::assertInstanceOf(StreamedResponse::class, $response);

        // Compression, the response cache and audit middleware all do this.
        $inspected = (string) $response->getBody();

        /** @var list<string> $afterwards */
        $afterwards = [];

        /** @var mixed $chunk */
        foreach ($response->getSource() as $chunk) {
            if (is_string($chunk)) {
                $afterwards[] = $chunk;
            }
        }

        self::assertNotSame('', $inspected);
        self::assertSame($inspected, implode('', $afterwards));
        self::assertTrue(str_contains($inspected, '"output_tokens":7'));
    }

    #[Test]
    public function anUpstreamThatDiesMidStreamReachesTheApplicationInTimeToTellTheBrowser(): void
    {
        $frames = self::modelFrames();

        // Everything up to and including the first text delta, then nothing —
        // 200 OK and half a body, which is the failure a browser cannot see on
        // its own.
        $transport = new FakeStreamTransport([$frames[0], $frames[1], $frames[2]]);

        $kernel = new Kernel();
        $kernel->router()->get('/chat', fn(): StreamedResponse => self::sseResponse($transport));

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/chat'));
        self::assertInstanceOf(StreamedResponse::class, $response);

        /** @var list<string> $emitted */
        $emitted = [];

        /** @var mixed $chunk */
        foreach ($response->getSource() as $chunk) {
            if (is_string($chunk)) {
                $emitted[] = $chunk;
            }
        }

        self::assertSame('data: {"text":"Hello"}' . "\n\n", $emitted[0]);
        self::assertStringStartsWith('event: error', $emitted[1] ?? '');
        self::assertStringContainsString('without a terminal event', $emitted[1] ?? '');
        self::assertCount(2, $emitted, 'a truncated model must not produce a done frame');
    }

    /**
     * The application handler: a model streamed to the browser as SSE.
     */
    private static function sseResponse(FakeStreamTransport $transport): StreamedResponse
    {
        $stream = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
            streamTransport: $transport,
        )->streamChat([ChatMessage::user('Say hello')]);

        return new StreamedResponse(
            (static function () use ($stream): Generator {
                try {
                    foreach ($stream as $delta) {
                        if ($delta->type === AiStreamEventType::Text) {
                            yield 'data: ' . json_encode(['text' => $delta->text]) . "\n\n";
                        }
                    }

                    $response = $stream->response();

                    yield 'event: done' . "\n" . 'data: ' . json_encode([
                        'finish_reason' => $response->finishReason,
                        'input_tokens' => $response->inputTokens,
                        'output_tokens' => $response->outputTokens,
                    ]) . "\n\n";
                } catch (AiStreamException $failure) {
                    // Headers went out with the first byte, so the only way to
                    // tell the browser is in-band. Emitting a done frame here
                    // would be the lie the whole design exists to prevent.
                    yield 'event: error' . "\n" . 'data: ' . json_encode([
                        'message' => $failure->getMessage(),
                    ]) . "\n\n";
                }
            })(),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ],
        );
    }

    /**
     * @return list<string>
     */
    private static function modelFrames(): array
    {
        return [
            <<<'SSE'
                event: message_start
                data: {"type":"message_start","message":{"usage":{"input_tokens":12,"output_tokens":1}}}


                SSE,
            <<<'SSE'
                event: content_block_start
                data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}


                SSE,
            <<<'SSE'
                event: content_block_delta
                data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}


                SSE,
            <<<'SSE'
                event: content_block_delta
                data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":", world"}}


                SSE,
            <<<'SSE'
                event: content_block_stop
                data: {"type":"content_block_stop","index":0}


                SSE,
            <<<'SSE'
                event: message_delta
                data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":7}}


                SSE,
            <<<'SSE'
                event: message_stop
                data: {"type":"message_stop"}


                SSE,
        ];
    }
}
