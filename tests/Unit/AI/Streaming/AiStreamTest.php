<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming;

use Exception;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\AI\Streaming\AiStreamAccumulator;
use Pulsar\AI\Streaming\AiStreamDelta;
use Pulsar\AI\Streaming\AiTokenUsage;
use Pulsar\AI\Streaming\ToolCallDelta;

use function iterator_to_array;

/**
 * The {@see AiStream} contract on its own, without a provider.
 *
 * Two agents will build decorators over this type, so the shape has to hold
 * without reference to any particular wire format: single-pass iteration, a
 * response that exists only once the terminal event has been observed, and a
 * refusal — never a short answer — in every other case.
 */
#[CoversClass(AiStream::class)]
#[CoversClass(AiStreamAccumulator::class)]
#[CoversClass(AiStreamDelta::class)]
#[CoversClass(AiTokenUsage::class)]
#[CoversClass(ToolCallDelta::class)]
final class AiStreamTest extends TestCase
{
    #[Test]
    public function theDeltasComeFirstAndTheResponseAfterThem(): void
    {
        $stream = new AiStream(self::completeGenerator(), 'fake');

        $deltas = iterator_to_array($stream, false);

        self::assertCount(4, $deltas);
        self::assertSame('Hello, world', $stream->response()->content);
    }

    #[Test]
    public function callingResponseWithoutIteratingDrainsTheStreamAndReturnsIt(): void
    {
        $stream = new AiStream(self::completeGenerator(), 'fake');

        $response = $stream->response();

        self::assertSame('Hello, world', $response->content);
        self::assertSame(12, $response->inputTokens);
        self::assertSame(7, $response->outputTokens);
        self::assertSame('stop', $response->finishReason);
    }

    #[Test]
    public function theResponseIsAnswerableRepeatedlyOnceTheStreamHasFinished(): void
    {
        $stream = new AiStream(self::completeGenerator(), 'fake');

        $first = $stream->response();
        $second = $stream->response();

        self::assertSame($first, $second);
    }

    #[Test]
    public function aStreamThatHasBeenReadToItsEndCannotBeReplayed(): void
    {
        $stream = new AiStream(self::completeGenerator(), 'fake');

        iterator_to_array($stream, false);

        // The deltas were consumed, not buffered. The engine refuses the second
        // pass, which is the correct answer and needs no state of our own to say.
        $this->expectException(Exception::class);
        $this->expectExceptionMessageIsOrContains('already closed generator');

        foreach ($stream as $_delta) {
            // Nothing to replay.
        }
    }

    #[Test]
    public function stoppingEarlyLosesTheDeltasButNotTheAnswer(): void
    {
        $stream = new AiStream(self::completeGenerator(), 'fake');
        $seen = 0;

        foreach ($stream as $_delta) {
            ++$seen;

            break;
        }

        self::assertSame(1, $seen);

        // The caller stopped reading; the model did not stop generating. Asking
        // for the response finishes the job, and the answer is the real one
        // rather than a short one assembled from what happened to be read.
        $response = $stream->response();

        self::assertSame('Hello, world', $response->content);
        self::assertSame('stop', $response->finishReason);
        self::assertSame(7, $response->outputTokens);
    }

    #[Test]
    public function theProviderNameTravelsWithTheStreamForDecoratorsToPassOn(): void
    {
        $stream = new AiStream(self::completeGenerator(), 'anthropic');

        self::assertSame('anthropic', $stream->providerName);
    }

    #[Test]
    public function aDecoratorCanWrapAStreamAndKeepBothTheDeltasAndTheResponse(): void
    {
        $inner = new AiStream(self::completeGenerator(), 'fake');
        $observed = [];

        $outer = new AiStream(
            (static function () use ($inner, &$observed): Generator {
                foreach ($inner as $delta) {
                    $observed[] = $delta->type->value;

                    yield $delta;
                }

                return $inner->response();
            })(),
            $inner->providerName,
        );

        $response = $outer->response();

        self::assertSame(['usage', 'text', 'text', 'finish'], $observed);
        self::assertSame('Hello, world', $response->content);
        self::assertSame(7, $response->outputTokens);
        self::assertSame('fake', $outer->providerName);
    }

    #[Test]
    public function anAccumulatorRefusesToProduceAResponseWithoutATerminalEvent(): void
    {
        $accumulator = new AiStreamAccumulator('fake', 'test-model');
        $accumulator->accept(AiStreamDelta::text('half an answer'));

        self::assertFalse($accumulator->isFinished());
        self::assertSame('half an answer', $accumulator->partialContent());

        try {
            $invented = $accumulator->finish();
            self::fail('An unfinished stream must not answer with ' . $invented->finishReason);
        } catch (AiStreamException $refusal) {
            self::assertStringContainsString('without a terminal event', $refusal->getMessage());
        }
    }

    #[Test]
    public function aStreamThatFailedStaysFailedWhenAskedAgain(): void
    {
        $stream = new AiStream(self::failingGenerator(), 'fake');

        try {
            iterator_to_array($stream, false);
            self::fail('The failing stream must not iterate to the end');
        } catch (AiStreamException $failure) {
            self::assertStringContainsString('without a terminal event', $failure->getMessage());
        }

        try {
            $laundered = $stream->response();
            self::fail('A failed stream must not answer with ' . $laundered->finishReason);
        } catch (AiStreamException $refusal) {
            self::assertStringContainsString('without a complete response', $refusal->getMessage());
        }
    }

    #[Test]
    public function anAccumulatorTakesEachTokenCountFromTheEventThatReportedIt(): void
    {
        $accumulator = new AiStreamAccumulator('fake', 'test-model');

        // The opening event knows the prompt size and guesses the output size;
        // the closing event knows the real output size and says nothing about
        // the prompt. Overwriting both from either one loses a number.
        $accumulator->accept(AiStreamDelta::usage(new AiTokenUsage(inputTokens: 12, outputTokens: 1)));
        $accumulator->accept(AiStreamDelta::usage(new AiTokenUsage(outputTokens: 7)));
        $accumulator->accept(AiStreamDelta::finish('stop'));

        $response = $accumulator->finish();

        self::assertSame(12, $response->inputTokens);
        self::assertSame(7, $response->outputTokens);
        self::assertSame(19, $response->totalTokens());
    }

    #[Test]
    public function aToolWithNoParametersAccumulatesToEmptyArgumentsRatherThanFailing(): void
    {
        $accumulator = new AiStreamAccumulator('fake', 'test-model');
        $accumulator->accept(AiStreamDelta::toolCall(new ToolCallDelta(0, 'call_1', 'ping')));
        $accumulator->accept(AiStreamDelta::finish('tool_use'));

        $response = $accumulator->finish();

        self::assertCount(1, $response->toolCalls);
        self::assertSame('ping', $response->toolCalls[0]->name);
        self::assertSame([], $response->toolCalls[0]->arguments);
    }

    /**
     * A stream that stops before its terminal event, the way a dropped
     * connection does.
     *
     * @return Generator<int, AiStreamDelta, mixed, AiResponse>
     */
    private static function failingGenerator(): Generator
    {
        $accumulator = new AiStreamAccumulator('fake', 'test-model');

        $delta = AiStreamDelta::text('half an ans');
        $accumulator->accept($delta);

        yield $delta;

        return $accumulator->finish();
    }

    /**
     * @return Generator<int, AiStreamDelta, mixed, AiResponse>
     */
    private static function completeGenerator(): Generator
    {
        $accumulator = new AiStreamAccumulator('fake', 'test-model');

        $deltas = [
            AiStreamDelta::usage(new AiTokenUsage(inputTokens: 12, outputTokens: 1)),
            AiStreamDelta::text('Hello'),
            AiStreamDelta::text(', world'),
            AiStreamDelta::finish('stop'),
        ];

        foreach ($deltas as $delta) {
            $accumulator->accept($delta);

            yield $delta;
        }

        // The real output count lands with the closing event; folding it in
        // after the last yield mirrors what the providers do.
        $accumulator->accept(AiStreamDelta::usage(new AiTokenUsage(outputTokens: 7)));

        return $accumulator->finish();
    }
}
