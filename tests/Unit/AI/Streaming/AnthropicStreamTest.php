<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Exception\AiException;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Provider\AnthropicProvider;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\AI\Streaming\AiStreamAccumulator;
use Pulsar\AI\Streaming\AiStreamDelta;
use Pulsar\AI\Streaming\AiStreamEventType;
use Pulsar\AI\Streaming\AnthropicStreamParser;
use Pulsar\AI\Streaming\SseReader;
use Pulsar\AI\ToolDefinition;
use Pulsar\Tests\Unit\AI\Streaming\Support\EventLog;
use Pulsar\Tests\Unit\AI\Streaming\Support\FakeStreamTransport;
use Pulsar\Tests\Unit\AI\Streaming\Support\FixedHttpClient;

use function implode;
use function json_decode;
use function str_split;

/**
 * Streaming the Anthropic Messages API.
 *
 * The four claims that matter are all here: deltas reach the caller as they
 * arrive rather than in one batch at the end, the accumulated response is the
 * same object the unstreamed call builds from the same conversation, a stream
 * that stops before its terminal event is refused rather than returned, and a
 * tool call survives being cut into JSON fragments on the wire.
 */
#[CoversClass(AnthropicProvider::class)]
#[CoversClass(AnthropicStreamParser::class)]
#[CoversClass(SseReader::class)]
#[CoversClass(AiStream::class)]
#[CoversClass(AiStreamAccumulator::class)]
#[CoversClass(AiStreamDelta::class)]
final class AnthropicStreamTest extends TestCase
{
    /**
     * The same conversation answered in one shot by the Messages API.
     */
    private const string UNSTREAMED_BODY = '{"id":"msg_1","model":"claude-sonnet-4-6",'
        . '"content":[{"type":"text","text":"Hello, world"}],'
        . '"stop_reason":"end_turn","usage":{"input_tokens":12,"output_tokens":7}}';

    #[Test]
    public function deltasReachTheCallerBetweenTransportChunksRatherThanAllAtTheEnd(): void
    {
        $log = new EventLog();

        $transport = new FakeStreamTransport(
            self::completeFrames(),
            static function (int $index) use ($log): void {
                $log->record('transport:' . $index);
            },
        );

        foreach ($this->provider($transport)->streamChat([ChatMessage::user('Hi')]) as $delta) {
            if ($delta->type === AiStreamEventType::Text) {
                $log->record('text:' . $delta->text);
            }
        }

        // A buffering implementation logs every transport marker first and only
        // then the text markers. The interleaving is the whole claim, so the
        // assertion is on the order, not on the final string.
        self::assertSame(
            [
                'transport:0',
                'transport:1',
                'transport:2',
                'text:Hello',
                'transport:3',
                'text:, world',
                'transport:4',
                'transport:5',
                'transport:6',
            ],
            $log->events(),
        );
    }

    #[Test]
    public function framesAreReassembledWhenEveryChunkBoundaryFallsMidFrame(): void
    {
        // Seven bytes at a time cuts through JSON payloads, through field names
        // and between the two newlines that terminate a frame — every place a
        // TCP boundary can land.
        $pieces = str_split(implode('', self::completeFrames()), 7);

        $response = $this->provider(new FakeStreamTransport($pieces))
            ->streamChat([ChatMessage::user('Hi')])
            ->response();

        self::assertSame('Hello, world', $response->content);
        self::assertSame('stop', $response->finishReason);
        self::assertSame(7, $response->outputTokens);
    }

    #[Test]
    public function theAccumulatedResponseEqualsTheUnstreamedOneForTheSameFixture(): void
    {
        $messages = [ChatMessage::user('Hi')];

        $streamed = $this->provider(new FakeStreamTransport(self::completeFrames()))
            ->streamChat($messages)
            ->response();

        $unstreamed = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
            httpClient: new FixedHttpClient(self::UNSTREAMED_BODY),
        )->chat($messages);

        self::assertEquals($unstreamed, $streamed);

        // Spelled out as well, because assertEquals on two identically wrong
        // objects passes.
        self::assertSame('Hello, world', $streamed->content);
        self::assertSame(12, $streamed->inputTokens);
        self::assertSame(7, $streamed->outputTokens);
        self::assertSame('stop', $streamed->finishReason);
        self::assertSame('claude-sonnet-4-6', $streamed->model);
    }

    #[Test]
    public function aStreamThatStopsBeforeItsTerminalEventIsRefusedRatherThanReturned(): void
    {
        $frames = self::completeFrames();

        // The connection dies cleanly after the second text delta: a well-formed
        // prefix, 200 OK, and no message_delta ever.
        $stream = $this->provider(new FakeStreamTransport([$frames[0], $frames[1], $frames[2], $frames[3]]))
            ->streamChat([ChatMessage::user('Hi')]);

        $seen = '';

        try {
            foreach ($stream as $delta) {
                if ($delta->type === AiStreamEventType::Text) {
                    $seen .= $delta->text;
                }
            }

            self::fail('Iterating a truncated stream to its end must not succeed');
        } catch (AiStreamException $refusal) {
            self::assertSame('Hello, world', $seen, 'the prefix really did arrive');
            self::assertStringContainsString('without a terminal event', $refusal->getMessage());
            self::assertSame('Hello, world', $refusal->partialContent);
        }

        // Asking a second time does not launder it into an answer either.
        try {
            $again = $stream->response();
            self::fail('A refused stream must stay refused, not answer ' . $again->finishReason);
        } catch (AiStreamException $stillRefused) {
            self::assertStringContainsString('without a complete response', $stillRefused->getMessage());
        }
    }

    #[Test]
    public function aToolCallSurvivesBeingSplitIntoJsonFragments(): void
    {
        $frames = [
            <<<'SSE'
                event: message_start
                data: {"type":"message_start","message":{"usage":{"input_tokens":20,"output_tokens":1}}}


                SSE,
            <<<'SSE'
                event: content_block_start
                data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}


                SSE,
            <<<'SSE'
                event: content_block_delta
                data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Checking."}}


                SSE,
            <<<'SSE'
                event: content_block_start
                data: {"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"toolu_1","name":"get_balance"}}


                SSE,
            <<<'SSE'
                event: content_block_delta
                data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\"iban\""}}


                SSE,
            <<<'SSE'
                event: content_block_delta
                data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":": \"NL91ABNA\"}"}}


                SSE,
            <<<'SSE'
                event: message_delta
                data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":31}}


                SSE,
        ];

        $response = $this->provider(new FakeStreamTransport($frames))
            ->streamChat(
                [ChatMessage::user('What is my balance?')],
                new AiRequestOptions(tools: [
                    new ToolDefinition('get_balance', 'Read an account balance', ['type' => 'object']),
                ]),
            )
            ->response();

        self::assertTrue($response->hasToolCalls());
        self::assertCount(1, $response->toolCalls);
        self::assertSame('toolu_1', $response->toolCalls[0]->id);
        self::assertSame('get_balance', $response->toolCalls[0]->name);
        self::assertSame(['iban' => 'NL91ABNA'], $response->toolCalls[0]->arguments);
        self::assertSame('tool_use', $response->finishReason);
        self::assertSame('Checking.', $response->content);
        self::assertSame(31, $response->outputTokens);
    }

    #[Test]
    public function aToolCallWhoseArgumentsWereCutInHalfFailsRatherThanArrivingWithHalfItsParameters(): void
    {
        $frames = [
            <<<'SSE'
                event: content_block_start
                data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_1","name":"transfer"}}


                SSE,
            <<<'SSE'
                event: content_block_delta
                data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"amount\": 100, \"to\""}}


                SSE,
            <<<'SSE'
                event: message_delta
                data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":9}}


                SSE,
        ];

        $stream = $this->provider(new FakeStreamTransport($frames))->streamChat([ChatMessage::user('Send it')]);

        try {
            $degraded = $stream->response();
            self::fail('A half-written tool call must not be handed on: ' . $degraded->finishReason);
        } catch (AiStreamException $refusal) {
            self::assertStringContainsString('not a JSON object', $refusal->getMessage());
        }
    }

    #[Test]
    public function anErrorFrameMidStreamThrowsAndCarriesWhatHadArrived(): void
    {
        $frames = self::completeFrames();

        $withError = [
            $frames[0],
            $frames[2],
            <<<'SSE'
                event: error
                data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}


                SSE,
        ];

        $stream = $this->provider(new FakeStreamTransport($withError))->streamChat([ChatMessage::user('Hi')]);

        try {
            foreach ($stream as $_delta) {
                // Drain until the error frame.
            }

            self::fail('The error frame must abort the stream');
        } catch (AiStreamException $failure) {
            self::assertStringContainsString('Overloaded', $failure->getMessage());
            self::assertSame('Hello', $failure->partialContent);
        }
    }

    #[Test]
    public function theRequestAsksForAStreamAndCarriesTheApiHeaders(): void
    {
        $transport = new FakeStreamTransport(self::completeFrames());

        $response = $this->provider($transport)
            ->streamChat([ChatMessage::user('Hi')], new AiRequestOptions(timeoutSeconds: 45))
            ->response();

        self::assertSame('stop', $response->finishReason);
        self::assertSame('https://api.anthropic.com/v1/messages', $transport->url);
        self::assertSame(45, $transport->idleTimeoutSeconds);
        self::assertTrue($transport->sentHeader('x-api-key'));
        self::assertTrue($transport->sentHeader('anthropic-version'));
        self::assertSame('text/event-stream', $transport->headers['Accept']);

        /** @var mixed $sent */
        $sent = json_decode($transport->body, true);
        self::assertIsArray($sent);
        self::assertTrue(($sent['stream'] ?? null) === true);
    }

    #[Test]
    public function anSsrfRefusalIsRaisedByTheCallThatAskedForTheStreamNotByWhoeverIteratesIt(): void
    {
        $transport = new FakeStreamTransport(self::completeFrames());

        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: 'http://169.254.169.254/latest',
            streamTransport: $transport,
        );

        try {
            $provider->streamChat([ChatMessage::user('Hi')]);
            self::fail('A blocked URL must be refused before a stream object exists');
        } catch (AiException $blocked) {
            self::assertStringContainsString('SSRF protection blocked', $blocked->getMessage());
            self::assertSame(0, $transport->callCount, 'nothing may be sent once the URL is refused');
        }
    }

    #[Test]
    public function everyDeltaIsTypedAndTheTerminalEventArrivesExactlyOnce(): void
    {
        $types = [];

        $stream = $this->provider(new FakeStreamTransport(self::completeFrames()))
            ->streamChat([ChatMessage::user('Hi')]);

        foreach ($stream as $delta) {
            self::assertInstanceOf(AiStreamDelta::class, $delta);
            $types[] = $delta->type;
        }

        self::assertSame(
            [
                AiStreamEventType::Usage,
                AiStreamEventType::Text,
                AiStreamEventType::Text,
                AiStreamEventType::Usage,
                AiStreamEventType::Finish,
            ],
            $types,
        );
        self::assertSame('stop', $stream->response()->finishReason);
    }

    private function provider(FakeStreamTransport $transport): AnthropicProvider
    {
        return new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
            baseUrl: 'https://api.anthropic.com/v1',
            streamTransport: $transport,
        );
    }

    /**
     * The complete stream for "Hello, world", one frame per entry.
     *
     * @return list<string>
     */
    private static function completeFrames(): array
    {
        return [
            <<<'SSE'
                event: message_start
                data: {"type":"message_start","message":{"id":"msg_1","model":"claude-sonnet-4-6","usage":{"input_tokens":12,"output_tokens":1}}}


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
