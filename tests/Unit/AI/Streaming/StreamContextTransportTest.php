<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Streaming\HttpStreamOpenerInterface;
use Pulsar\AI\Streaming\OpenedHttpStream;
use Pulsar\AI\Streaming\StreamContextTransport;

use function fclose;
use function fwrite;
use function implode;
use function iterator_to_array;
use function microtime;
use function stream_socket_pair;
use function strlen;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_INET;
use const STREAM_SOCK_STREAM;

/**
 * The transport, proved against real sockets rather than a network.
 *
 * `stream_socket_pair()` gives two connected in-process endpoints, so the
 * blocking read loop, the idle timeout and the peer-closed path can all be
 * driven for real without a server, a subprocess, or a port. The only thing
 * left behind a seam is the `fopen()` call itself.
 */
#[CoversClass(StreamContextTransport::class)]
#[CoversClass(OpenedHttpStream::class)]
final class StreamContextTransportTest extends TestCase
{
    #[Test]
    public function bytesAreYieldedAsTheyArriveRatherThanAfterTheBodyIsComplete(): void
    {
        [$server, $client] = self::socketPair();

        fwrite($server, 'first');
        fwrite($server, 'second');
        fclose($server);

        $chunks = iterator_to_array(
            new StreamContextTransport('fake')->read($client, 'https://example.test', 5),
            false,
        );

        self::assertNotSame([], $chunks);
        self::assertSame('firstsecond', implode('', $chunks));
    }

    #[Test]
    public function eachReadIsCappedSoALargeBodyIsNotBufferedWhole(): void
    {
        [$server, $client] = self::socketPair();

        fwrite($server, 'abcdefghij');
        fclose($server);

        $chunks = iterator_to_array(
            new StreamContextTransport('fake', chunkBytes: 4)->read($client, 'https://example.test', 5),
            false,
        );

        // Four bytes at a time: the reader never holds more than it was told to.
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(4, strlen($chunk));
        }

        self::assertSame('abcdefghij', implode('', $chunks));
    }

    #[Test]
    public function aSocketThatGoesSilentIsDeclaredDeadAfterTheIdleWindowRatherThanHangingForever(): void
    {
        [$server, $client] = self::socketPair();

        fwrite($server, 'the beginning of an answer');
        // The peer stays open and says nothing more. Without an idle timeout
        // this read never returns.

        $transport = new StreamContextTransport('fake');
        $started = microtime(true);

        try {
            iterator_to_array($transport->read($client, 'https://example.test', 1), false);
            self::fail('A silent socket must not be waited on indefinitely');
        } catch (AiStreamException $stall) {
            self::assertStringContainsString('sent nothing for 1 second', $stall->getMessage());
        } finally {
            fclose($server);
        }

        $elapsed = microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.5, $elapsed, 'the idle window must actually be waited out');
        self::assertLessThan(10.0, $elapsed, 'and it must not be waited out for very much longer');
    }

    #[Test]
    public function aNonSuccessStatusRefusesTheStreamAndCarriesTheErrorBody(): void
    {
        [$server, $client] = self::socketPair();
        fwrite($server, '{"error":{"message":"rate limit reached"}}');
        fclose($server);

        $transport = new StreamContextTransport('openai', new FixedOpener($client, [
            'HTTP/1.1 429 Too Many Requests',
            'Content-Type: application/json',
        ]));

        try {
            $transport->postStream('https://api.openai.test/v1/chat/completions', '{}', [], 5);
            self::fail('A 429 must not be read as the start of a stream');
        } catch (AiStreamException $refusal) {
            self::assertSame(429, $refusal->getCode());
            self::assertStringContainsString('rate limit reached', $refusal->getMessage());
        }
    }

    #[Test]
    public function anInformationalStatusLineDoesNotHideTheRealOne(): void
    {
        self::assertSame(200, StreamContextTransport::statusFrom([
            'HTTP/1.1 100 Continue',
            'HTTP/1.1 200 OK',
            'Content-Type: text/event-stream',
        ]));
        self::assertSame(503, StreamContextTransport::statusFrom(['HTTP/2 503 Service Unavailable']));
        self::assertSame(0, StreamContextTransport::statusFrom([]));
    }

    #[Test]
    public function theRequestRefusesRedirectsAndCarriesTheCallersHeadersAndIdleWindow(): void
    {
        [$server, $client] = self::socketPair();
        fwrite($server, 'data: hello' . "\n\n");
        fclose($server);

        $opener = new FixedOpener($client, ['HTTP/1.1 200 OK']);
        $transport = new StreamContextTransport('anthropic', $opener);

        $chunks = iterator_to_array(
            $transport->postStream(
                'https://api.anthropic.test/v1/messages',
                '{"stream":true}',
                ['x-api-key' => 'secret', 'Accept' => 'text/event-stream'],
                42,
            ),
            false,
        );

        self::assertSame('data: hello' . "\n\n", implode('', $chunks));
        self::assertSame('https://api.anthropic.test/v1/messages', $opener->url);

        // A redirect chased inside the transport would leave the provider's SSRF
        // check behind, so the wrapper is told never to chase one.
        self::assertSame(0, $opener->options['follow_location'] ?? null);
        self::assertSame(42, $opener->options['timeout'] ?? null);
        self::assertSame('POST', $opener->options['method'] ?? null);
        self::assertSame('{"stream":true}', $opener->options['content'] ?? null);
        self::assertSame(
            "x-api-key: secret\r\nAccept: text/event-stream\r\n",
            $opener->options['header'] ?? null,
        );
    }

    #[Test]
    public function aConnectionThatCannotBeOpenedIsReportedRatherThanReturningAnEmptyStream(): void
    {
        $transport = new StreamContextTransport('anthropic', new RefusingOpener());

        $this->expectException(AiStreamException::class);
        $this->expectExceptionMessageIsOrContains('could not be opened');

        $transport->postStream('https://api.anthropic.test/v1/messages', '{}', [], 5);
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private static function socketPair(): array
    {
        // socketpair(2) takes only AF_UNIX on Linux; PHP on Windows emulates the pair over INET.
        $pair = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        self::assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }
}

/**
 * Hands the transport a socket that is already open, with scripted headers.
 */
final class FixedOpener implements HttpStreamOpenerInterface
{
    public string $url = '';

    /** @var array<string, mixed> */
    public array $options = [];

    /**
     * @param resource $handle
     * @param list<string> $headerLines
     */
    public function __construct(
        private readonly mixed $handle,
        private readonly array $headerLines,
    ) {}

    #[Override]
    public function open(string $url, array $httpContextOptions): OpenedHttpStream
    {
        $this->url = $url;
        $this->options = $httpContextOptions;

        return new OpenedHttpStream($this->handle, $this->headerLines);
    }
}

/**
 * Stands in for a connection that never came up.
 */
final class RefusingOpener implements HttpStreamOpenerInterface
{
    #[Override]
    public function open(string $url, array $httpContextOptions): OpenedHttpStream
    {
        throw AiStreamException::transportFailure('anthropic', $url, 'the connection could not be opened');
    }
}
