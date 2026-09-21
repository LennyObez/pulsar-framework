<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Provider\OllamaProvider;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\AI\Streaming\AiStreamAccumulator;
use Pulsar\AI\Streaming\AiStreamEventType;
use Pulsar\AI\Streaming\NdJsonReader;
use Pulsar\AI\Streaming\OllamaStreamParser;
use Pulsar\AI\ToolDefinition;
use Pulsar\Tests\Unit\AI\Streaming\Support\EventLog;
use Pulsar\Tests\Unit\AI\Streaming\Support\FakeStreamTransport;
use Pulsar\Tests\Unit\AI\Streaming\Support\FixedHttpClient;

use function implode;
use function json_decode;
use function str_split;
use function substr;

/**
 * Streaming a local Ollama instance.
 *
 * Ollama does not speak SSE — `/api/chat` writes one JSON object per line — so
 * this exercises the other framing path end to end, including the case that
 * only NDJSON has: the final object, the one carrying `done` and the token
 * counts, arriving without its trailing newline.
 */
#[CoversClass(OllamaProvider::class)]
#[CoversClass(OllamaStreamParser::class)]
#[CoversClass(NdJsonReader::class)]
#[CoversClass(AiStream::class)]
#[CoversClass(AiStreamAccumulator::class)]
final class OllamaStreamTest extends TestCase
{
    private const string UNSTREAMED_BODY = '{"model":"llama3.1","message":{"role":"assistant",'
        . '"content":"Hello, world"},"done":true,"done_reason":"stop",'
        . '"prompt_eval_count":12,"eval_count":7}';

    #[Test]
    public function deltasReachTheCallerBetweenTransportChunksRatherThanAllAtTheEnd(): void
    {
        $log = new EventLog();

        $transport = new FakeStreamTransport(
            self::completeLines(),
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
                'text:Hello',
                'transport:1',
                'text:, world',
                'transport:2',
            ],
            $log->events(),
        );
    }

    #[Test]
    public function objectsAreReassembledWhenEveryChunkBoundaryFallsMidObject(): void
    {
        $pieces = str_split(implode('', self::completeLines()), 9);

        $response = $this->provider(new FakeStreamTransport($pieces))
            ->streamChat([ChatMessage::user('Hi')])
            ->response();

        self::assertSame('Hello, world', $response->content);
        self::assertSame('stop', $response->finishReason);
        self::assertSame(12, $response->inputTokens);
        self::assertSame(7, $response->outputTokens);
    }

    #[Test]
    public function theFinalObjectIsReadEvenWithoutItsTrailingNewline(): void
    {
        $complete = self::completeLines();

        // Ollama terminates every object with a newline, but the last one is the
        // last thing on the wire and a server may close before writing it. The
        // object is intact, so the response is complete.
        $lines = [$complete[0], $complete[1], substr($complete[2], 0, -1)];

        $response = $this->provider(new FakeStreamTransport($lines))
            ->streamChat([ChatMessage::user('Hi')])
            ->response();

        self::assertSame('Hello, world', $response->content);
        self::assertSame('stop', $response->finishReason);
        self::assertSame(7, $response->outputTokens);
    }

    #[Test]
    public function aFinalObjectCutInHalfIsRefusedRatherThanTreatedAsTheEnd(): void
    {
        $complete = self::completeLines();

        // Half of the terminal object: not valid JSON, and precisely the shape a
        // dropped connection leaves behind.
        $lines = [$complete[0], $complete[1], substr($complete[2], 0, 40)];

        $stream = $this->provider(new FakeStreamTransport($lines))->streamChat([ChatMessage::user('Hi')]);

        $this->expectException(AiStreamException::class);
        $this->expectExceptionMessageIsOrContains('cut mid-object');

        foreach ($stream as $_delta) {
            // Drain until the malformed tail.
        }
    }

    #[Test]
    public function aStreamThatNeverReportsDoneIsRefused(): void
    {
        $lines = self::completeLines();

        $stream = $this->provider(new FakeStreamTransport([$lines[0], $lines[1]]))
            ->streamChat([ChatMessage::user('Hi')]);

        $seen = '';

        try {
            foreach ($stream as $delta) {
                if ($delta->type === AiStreamEventType::Text) {
                    $seen .= $delta->text;
                }
            }

            self::fail('A stream with no done object must not complete');
        } catch (AiStreamException $refusal) {
            self::assertSame('Hello, world', $seen);
            self::assertStringContainsString('without a terminal event', $refusal->getMessage());
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
    public function theAccumulatedResponseEqualsTheUnstreamedOneForTheSameFixture(): void
    {
        $messages = [ChatMessage::user('Hi')];

        $streamed = $this->provider(new FakeStreamTransport(self::completeLines()))
            ->streamChat($messages)
            ->response();

        $unstreamed = new OllamaProvider(
            model: 'llama3.1',
            httpClient: new FixedHttpClient(self::UNSTREAMED_BODY),
        )->chat($messages);

        self::assertEquals($unstreamed, $streamed);

        self::assertSame('Hello, world', $streamed->content);
        self::assertSame(12, $streamed->inputTokens);
        self::assertSame(7, $streamed->outputTokens);
        self::assertSame('stop', $streamed->finishReason);
        self::assertSame('llama3.1', $streamed->model);
    }

    #[Test]
    public function aToolCallSurvivesAndMatchesTheUnstreamedParseOfTheSameCall(): void
    {
        $lines = [
            '{"model":"llama3.1","message":{"role":"assistant","content":"Checking."},"done":false}' . "\n",
            '{"model":"llama3.1","message":{"role":"assistant","content":"","tool_calls":'
                . '[{"function":{"name":"get_balance","arguments":{"iban":"NL91ABNA"}}}]},"done":false}' . "\n",
            '{"model":"llama3.1","message":{"role":"assistant","content":""},"done":true,'
                . '"done_reason":"stop","prompt_eval_count":30,"eval_count":18}' . "\n",
        ];

        $options = new AiRequestOptions(tools: [
            new ToolDefinition('get_balance', 'Read an account balance', ['type' => 'object']),
        ]);

        $transport = new FakeStreamTransport($lines);
        $streamed = $this->provider($transport)
            ->streamChat([ChatMessage::user('What is my balance?')], $options)
            ->response();

        self::assertTrue($streamed->hasToolCalls());
        self::assertSame('get_balance', $streamed->toolCalls[0]->name);
        self::assertSame(['iban' => 'NL91ABNA'], $streamed->toolCalls[0]->arguments);
        self::assertSame('', $streamed->toolCalls[0]->id, 'Ollama assigns no call id and none is invented');

        // The unstreamed path used to discard tool_calls outright, which would
        // have left the two paths disagreeing about identical bytes.
        $unstreamedBody = '{"model":"llama3.1","message":{"role":"assistant","content":"Checking.",'
            . '"tool_calls":[{"function":{"name":"get_balance","arguments":{"iban":"NL91ABNA"}}}]},'
            . '"done":true,"done_reason":"stop","prompt_eval_count":30,"eval_count":18}';

        $unstreamed = new OllamaProvider(
            model: 'llama3.1',
            httpClient: new FixedHttpClient($unstreamedBody),
        )->chat([ChatMessage::user('What is my balance?')], $options);

        self::assertEquals($unstreamed, $streamed);
    }

    #[Test]
    public function anErrorObjectMidStreamThrows(): void
    {
        $lines = [
            self::completeLines()[0],
            '{"error":"model \'llama3.1\' not found"}' . "\n",
        ];

        $stream = $this->provider(new FakeStreamTransport($lines))->streamChat([ChatMessage::user('Hi')]);

        try {
            foreach ($stream as $_delta) {
                // Drain until the error object.
            }

            self::fail('An error object must abort the stream');
        } catch (AiStreamException $failure) {
            self::assertStringContainsString('not found', $failure->getMessage());
            self::assertSame('Hello', $failure->partialContent);
        }
    }

    #[Test]
    public function theRequestAsksForAStreamAndCarriesTheToolDefinitions(): void
    {
        $transport = new FakeStreamTransport(self::completeLines());

        $response = $this->provider($transport)
            ->streamChat(
                [ChatMessage::user('Hi')],
                new AiRequestOptions(
                    timeoutSeconds: 20,
                    tools: [new ToolDefinition('get_balance', 'Read a balance', ['type' => 'object'])],
                ),
            )
            ->response();

        self::assertSame('stop', $response->finishReason);
        self::assertSame('http://localhost:11434/api/chat', $transport->url);
        self::assertSame(20, $transport->idleTimeoutSeconds);

        /** @var mixed $sent */
        $sent = json_decode($transport->body, true);
        self::assertIsArray($sent);
        self::assertTrue(($sent['stream'] ?? null) === true);
        self::assertIsArray(
            $sent['tools'] ?? null,
            'a stream that cannot ask for a tool can never carry one back',
        );
    }

    private function provider(FakeStreamTransport $transport): OllamaProvider
    {
        return new OllamaProvider(
            model: 'llama3.1',
            baseUrl: 'http://localhost:11434',
            streamTransport: $transport,
        );
    }

    /**
     * @return list<string>
     */
    private static function completeLines(): array
    {
        return [
            '{"model":"llama3.1","message":{"role":"assistant","content":"Hello"},"done":false}' . "\n",
            '{"model":"llama3.1","message":{"role":"assistant","content":", world"},"done":false}' . "\n",
            '{"model":"llama3.1","message":{"role":"assistant","content":""},"done":true,'
                . '"done_reason":"stop","prompt_eval_count":12,"eval_count":7}' . "\n",
        ];
    }
}
