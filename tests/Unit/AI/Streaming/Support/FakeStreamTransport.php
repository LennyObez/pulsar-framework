<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming\Support;

use Closure;
use Generator;
use Override;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Streaming\StreamTransportInterface;

use function array_key_exists;

/**
 * A scripted {@see StreamTransportInterface} that never opens a socket.
 *
 * Two things make it useful beyond replaying bytes. It calls {@see $onChunk}
 * immediately before yielding each piece, which lets a test prove the consumer
 * ran BETWEEN pieces rather than after all of them — the one claim a fully
 * buffered implementation would also satisfy if you only checked the final text.
 * And it can be told to throw partway through, which is how a connection that
 * dies after 200 OK is reproduced without a network.
 */
final class FakeStreamTransport implements StreamTransportInterface
{
    public string $url = '';

    public string $body = '';

    /** @var array<string, string> */
    public array $headers = [];

    public int $idleTimeoutSeconds = 0;

    public int $callCount = 0;

    /**
     * @param list<string> $chunks Body pieces to emit, in order
     * @param Closure(int): void|null $onChunk Called with the piece index before each yield
     * @param AiStreamException|null $failure Thrown instead of continuing, once
     *                                        {@see $failAfterChunkCount} pieces have been emitted
     * @param int|null $failAfterChunkCount How many pieces to emit before throwing
     */
    public function __construct(
        private readonly array $chunks,
        private readonly ?Closure $onChunk = null,
        private readonly ?AiStreamException $failure = null,
        private readonly ?int $failAfterChunkCount = null,
    ) {}

    #[Override]
    public function postStream(string $url, string $body, array $headers, int $idleTimeoutSeconds): iterable
    {
        $this->url = $url;
        $this->body = $body;
        $this->headers = $headers;
        $this->idleTimeoutSeconds = $idleTimeoutSeconds;
        ++$this->callCount;

        return $this->emit();
    }

    /**
     * Whether a header was sent, spelled as the provider wrote it.
     */
    public function sentHeader(string $name): bool
    {
        return array_key_exists($name, $this->headers);
    }

    /**
     * @return Generator<int, string, mixed, void>
     *
     * @throws AiStreamException
     */
    private function emit(): Generator
    {
        $emitted = 0;

        foreach ($this->chunks as $chunk) {
            $this->failIfDue($emitted);

            if ($this->onChunk !== null) {
                ($this->onChunk)($emitted);
            }

            yield $chunk;

            ++$emitted;
        }

        $this->failIfDue($emitted);
    }

    /**
     * @throws AiStreamException
     */
    private function failIfDue(int $emitted): void
    {
        if ($this->failure !== null && $emitted === $this->failAfterChunkCount) {
            throw $this->failure;
        }
    }
}
