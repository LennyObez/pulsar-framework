<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Provider\OpenAiProvider;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\AI\Streaming\AiStreamAccumulator;
use Pulsar\AI\Streaming\AiStreamEventType;
use Pulsar\AI\Streaming\OpenAiStreamParser;
use Pulsar\AI\Streaming\SseReader;
use Pulsar\AI\ToolDefinition;
use Pulsar\Tests\Unit\AI\Streaming\Support\EventLog;
use Pulsar\Tests\Unit\AI\Streaming\Support\FakeStreamTransport;
use Pulsar\Tests\Unit\AI\Streaming\Support\FixedHttpClient;

use function implode;
use function json_decode;
use function str_split;

/**
 * Streaming the OpenAI chat-completions API.
 *
 * OpenAI's stream differs from Anthropic's in every way that matters to a
 * parser: no frame names, tool call arguments as indexed string slices, usage
 * on a trailing chunk with no choices, and a `[DONE]` sentinel that is about the
 * connection rather than the model. The last one gets its own test, because
 * treating `[DONE]` as the terminal event is exactly how a socket that closed
 * early would be mistaken for a finished answer.
 */
#[CoversClass(OpenAiProvider::class)]
#[CoversClass(OpenAiStreamParser::class)]
#[CoversClass(SseReader::class)]
#[CoversClass(AiStream::class)]
#[CoversClass(AiStreamAccumulator::class)]
final class OpenAiStreamTest extends TestCase
{
    private const string UNSTREAMED_BODY = '{"id":"c1","model":"gpt-4o","choices":[{"index":0,'
        . '"message":{"role":"assistant","content":"Hello, world"},"finish_reason":"stop"}],'
        . '"usage":{"prompt_tokens":12,"completion_tokens":7,"total_tokens":19}}';

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

        self::assertSame(
            [
                'transport:0',
                'transport:1',
                'text:Hello',
                'transport:2',
                'text:, world',
                'transport:3',
                'transport:4',
                'transport:5',
            ],
            $log->events(),
        );
    }

    #[Test]
    public function framesAreReassembledWhenEveryChunkBoundaryFallsMidFrame(): void
    {
        $pieces = str_split(implode('', self::completeFrames()), 11);

        $response = $this->provider(new FakeStreamTransport($pieces))
            ->streamChat([ChatMessage::user('Hi')])
            ->response();

        self::assertSame('Hello, world', $response->content);
        self::assertSame('stop', $response->finishReason);
    }

    #[Test]
    public function theAccumulatedResponseEqualsTheUnstreamedOneForTheSameFixture(): void
    {
        $messages = [ChatMessage::user('Hi')];

        $streamed = $this->provider(new FakeStreamTransport(self::completeFrames()))
            ->streamChat($messages)
            ->response();

        $unstreamed = new OpenAiProvider(
            apiKey: 'test-key',
            model: 'gpt-4o',
            httpClient: new FixedHttpClient(self::UNSTREAMED_BODY),
        )->chat($messages);

        self::assertEquals($unstreamed, $streamed);

        self::assertSame('Hello, world', $streamed->content);
        self::assertSame(12, $streamed->inputTokens);
        self::assertSame(7, $streamed->outputTokens);
        self::assertSame('stop', $streamed->finishReason);
        self::assertSame('gpt-4o', $streamed->model);
    }

    #[Test]
    public function aStreamThatEndsWithDoneButNoFinishReasonIsStillRefused(): void
    {
        $frames = self::completeFrames();

        // Everything except the chunk carrying finish_reason. The connection
        // closes politely, `[DONE]` and all — and the model never said it was
        // done, so this must not read as a complete answer.
        $stream = $this->provider(new FakeStreamTransport([$frames[0], $frames[1], $frames[2], $frames[5]]))
            ->streamChat([ChatMessage::user('Hi')]);

        $seen = '';

        try {
            foreach ($stream as $delta) {
                if ($delta->type === AiStreamEventType::Text) {
                    $seen .= $delta->text;
                }
            }

            self::fail('[DONE] alone must not complete a stream');
        } catch (AiStreamException $refusal) {
            self::assertSame('Hello, world', $seen);
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
    public function aConnectionThatDiesMidBodyIsReportedWithWhatHadArrived(): void
    {
        $frames = self::completeFrames();

        $transport = new FakeStreamTransport(
            $frames,
            failure: AiStreamException::transportFailure('openai', 'https://api.openai.com/v1/chat/completions', 'the connection failed mid-body'),
            failAfterChunkCount: 3,
        );

        $stream = $this->provider($transport)->streamChat([ChatMessage::user('Hi')]);

        try {
            foreach ($stream as $_delta) {
                // Drain until the socket dies.
            }

            self::fail('A dead socket must abort the stream');
        } catch (AiStreamException $failure) {
            self::assertStringContainsString('failed mid-body', $failure->getMessage());
            self::assertSame('Hello, world', $failure->partialContent);
        }
    }

    #[Test]
    public function aToolCallSurvivesBeingSplitIntoIndexedArgumentSlices(): void
    {
        $frames = [
            <<<'SSE'
                data: {"choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"get_balance","arguments":""}}]},"finish_reason":null}]}


                SSE,
            <<<'SSE'
                data: {"choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"iban\""}}]},"finish_reason":null}]}


                SSE,
            <<<'SSE'
                data: {"choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":": \"NL91ABNA\"}"}}]},"finish_reason":null}]}


                SSE,
            <<<'SSE'
                data: {"choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}]}


                SSE,
            <<<'SSE'
                data: {"choices":[],"usage":{"prompt_tokens":30,"completion_tokens":18,"total_tokens":48}}


                SSE,
            <<<'SSE'
                data: [DONE]


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
        self::assertSame('call_1', $response->toolCalls[0]->id);
        self::assertSame('get_balance', $response->toolCalls[0]->name);
        self::assertSame(['iban' => 'NL91ABNA'], $response->toolCalls[0]->arguments);
        self::assertSame('tool_calls', $response->finishReason);
        self::assertSame(30, $response->inputTokens);
        self::assertSame(18, $response->outputTokens);
    }

    #[Test]
    public function twoConcurrentToolCallsStayApartAndKeepTheirWireOrder(): void
    {
        $frames = [
            <<<'SSE'
                data: {"choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"call_a","function":{"name":"first","arguments":"{\"a\":"}},{"index":1,"id":"call_b","function":{"name":"second","arguments":"{\"b\":"}}]},"finish_reason":null}]}


                SSE,
            <<<'SSE'
                data: {"choices":[{"index":0,"delta":{"tool_calls":[{"index":1,"function":{"arguments":"2}"}},{"index":0,"function":{"arguments":"1}"}}]},"finish_reason":null}]}


                SSE,
            <<<'SSE'
                data: {"choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}]}


                SSE,
        ];

        $response = $this->provider(new FakeStreamTransport($frames))
            ->streamChat([ChatMessage::user('Do both')])
            ->response();

        self::assertCount(2, $response->toolCalls);
        self::assertSame('first', $response->toolCalls[0]->name);
        self::assertSame(['a' => 1], $response->toolCalls[0]->arguments);
        self::assertSame('second', $response->toolCalls[1]->name);
        self::assertSame(['b' => 2], $response->toolCalls[1]->arguments);
    }

    #[Test]
    public function anErrorPayloadMidStreamThrows(): void
    {
        $frames = self::completeFrames();

        $withError = [
            $frames[1],
            <<<'SSE'
                data: {"error":{"message":"rate limit reached","type":"rate_limit_error"}}


                SSE,
        ];

        $stream = $this->provider(new FakeStreamTransport($withError))->streamChat([ChatMessage::user('Hi')]);

        $this->expectException(AiStreamException::class);
        $this->expectExceptionMessageIsOrContains('rate limit reached');

        foreach ($stream as $_delta) {
            // Drain until the error payload.
        }
    }

    #[Test]
    public function theRequestAsksForAStreamAndForItsTokenCounts(): void
    {
        $transport = new FakeStreamTransport(self::completeFrames());

        $response = $this->provider($transport)
            ->streamChat([ChatMessage::user('Hi')], new AiRequestOptions(timeoutSeconds: 30))
            ->response();

        self::assertSame('stop', $response->finishReason);
        self::assertSame('https://api.openai.com/v1/chat/completions', $transport->url);
        self::assertSame(30, $transport->idleTimeoutSeconds);
        self::assertSame('text/event-stream', $transport->headers['Accept']);
        self::assertSame('Bearer test-key', $transport->headers['Authorization']);

        /** @var mixed $sent */
        $sent = json_decode($transport->body, true);
        self::assertIsArray($sent);
        self::assertTrue(($sent['stream'] ?? null) === true);
        self::assertSame(
            ['include_usage' => true],
            $sent['stream_options'] ?? null,
            'without include_usage a streamed call reports no tokens at all',
        );
    }

    private function provider(FakeStreamTransport $transport): OpenAiProvider
    {
        return new OpenAiProvider(
            apiKey: 'test-key',
            model: 'gpt-4o',
            baseUrl: 'https://api.openai.com/v1',
            streamTransport: $transport,
        );
    }

    /**
     * @return list<string>
     */
    private static function completeFrames(): array
    {
        return [
            <<<'SSE'
                data: {"id":"c1","object":"chat.completion.chunk","model":"gpt-4o","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}


                SSE,
            <<<'SSE'
                data: {"id":"c1","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}


                SSE,
            <<<'SSE'
                data: {"id":"c1","choices":[{"index":0,"delta":{"content":", world"},"finish_reason":null}]}


                SSE,
            <<<'SSE'
                data: {"id":"c1","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}


                SSE,
            <<<'SSE'
                data: {"id":"c1","choices":[],"usage":{"prompt_tokens":12,"completion_tokens":7,"total_tokens":19}}


                SSE,
            <<<'SSE'
                data: [DONE]


                SSE,
        ];
    }
}
