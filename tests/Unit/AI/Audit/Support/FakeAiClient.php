<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Audit\Support;

use Generator;
use Override;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\AI\Streaming\AiStreamDelta;
use Throwable;

use function usleep;

/**
 * A provider that returns exactly what a test told it to, or throws.
 *
 * Not a PHPUnit mock: the streaming assertions need a real
 * {@see AiStream} over a real generator, because what they check — that the
 * `finally` in the auditing decorator runs when a consumer drops the stream — is
 * a property of generator destruction that a stubbed iterator does not reproduce.
 */
final class FakeAiClient implements AiClientInterface
{
    public ?Throwable $failWith = null;

    public ?AiResponse $response = null;

    public ?EmbeddingResult $embedding = null;

    /** @var list<AiStreamDelta> */
    public array $deltas = [];

    /** Thrown from inside the stream generator, after the deltas above. */
    public ?Throwable $streamFailsWith = null;

    /** Microseconds the call sleeps for, so a latency assertion has something to measure. */
    public int $delayUs = 0;

    public function __construct(private readonly string $provider = 'fake') {}

    #[Override]
    public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        return $this->answer();
    }

    #[Override]
    public function complete(string $prompt, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        return $this->answer();
    }

    #[Override]
    public function structuredOutput(
        string $prompt,
        array $schema,
        AiRequestOptions $options = new AiRequestOptions(),
    ): AiResponse {
        return $this->answer();
    }

    #[Override]
    public function embed(array $inputs, AiRequestOptions $options = new AiRequestOptions()): EmbeddingResult
    {
        $this->maybeFail();

        return $this->embedding ?? new EmbeddingResult([], 0, 'fake-embed');
    }

    #[Override]
    public function streamChat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiStream
    {
        $this->maybeFail();

        return new AiStream($this->stream(), $this->provider);
    }

    #[Override]
    public function providerName(): string
    {
        return $this->provider;
    }

    /**
     * @return Generator<int, AiStreamDelta, mixed, AiResponse>
     *
     * @throws Throwable
     */
    private function stream(): Generator
    {
        foreach ($this->deltas as $delta) {
            yield $delta;
        }

        if ($this->streamFailsWith !== null) {
            throw $this->streamFailsWith;
        }

        return $this->response ?? new AiResponse('', 0, 0, 'stop');
    }

    /**
     * @throws Throwable
     */
    private function answer(): AiResponse
    {
        $this->maybeFail();

        return $this->response ?? new AiResponse('ok', 3, 5, 'stop', [], 'fake-model');
    }

    /**
     * @throws Throwable
     */
    private function maybeFail(): void
    {
        if ($this->delayUs > 0) {
            usleep($this->delayUs);
        }

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
