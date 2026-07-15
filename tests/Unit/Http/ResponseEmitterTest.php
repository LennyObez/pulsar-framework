<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Response\StreamedResponse;
use Pulsar\Http\ResponseEmitter;
use ReflectionClassConstant;
use ReflectionMethod;

#[CoversClass(ResponseEmitter::class)]
final class ResponseEmitterTest extends TestCase
{
    #[Test]
    public function stripsSapiFingerprintHeaders(): void
    {
        // The `expose_php` X-Powered-By leak and the CLI server's versioned
        // Server value are SAPI-injected — they never reach the PSR-7 response,
        // so the emitter must drop them at emit time (OWASP ASVS V14.4.1).
        $constant = new ReflectionClassConstant(ResponseEmitter::class, 'STRIPPED_SAPI_HEADERS');

        /** @var list<string> $stripped */
        $stripped = $constant->getValue();

        self::assertContains('X-Powered-By', $stripped);
        self::assertContains('Server', $stripped);
    }

    #[Test]
    public function sanitizeHeaderValueStripsCrlf(): void
    {
        $emitter = new ResponseEmitter();
        $method = new ReflectionMethod($emitter, 'sanitizeHeaderValue');

        self::assertSame('valuehere', $method->invoke($emitter, "value\r\nhere"));
        self::assertSame('valuehere', $method->invoke($emitter, "value\rhere"));
        self::assertSame('valuehere', $method->invoke($emitter, "value\nhere"));
        self::assertSame('clean', $method->invoke($emitter, 'clean'));
    }

    #[Test]
    public function sanitizeHeaderValueStripsMultipleCrlfSequences(): void
    {
        $emitter = new ResponseEmitter();
        $method = new ReflectionMethod($emitter, 'sanitizeHeaderValue');

        self::assertSame(
            'X-Injected: evilContent-Type: text/html',
            $method->invoke($emitter, "X-Injected: evil\r\nContent-Type: text/html"),
        );
    }

    #[Test]
    public function sanitizePreservesNormalValues(): void
    {
        $emitter = new ResponseEmitter();
        $method = new ReflectionMethod($emitter, 'sanitizeHeaderValue');

        self::assertSame('text/html; charset=utf-8', $method->invoke($emitter, 'text/html; charset=utf-8'));
        self::assertSame('max-age=31536000; includeSubDomains', $method->invoke($emitter, 'max-age=31536000; includeSubDomains'));
        self::assertSame('', $method->invoke($emitter, ''));
    }

    #[Test]
    public function chunkFrameUsesHexLengthAndCrlfFraming(): void
    {
        // FR-3: a StreamedResponse must be emitted with HTTP/1.1 chunked framing —
        // each chunk is its byte length in hex, CRLF, the data, CRLF — rather than
        // materialized and echoed as one buffer.
        $emitter = new ResponseEmitter();
        $method = new ReflectionMethod($emitter, 'chunkFrame');

        self::assertSame("5\r\nhello\r\n", $method->invoke($emitter, 'hello'));
        // 16 bytes => hex length "10".
        self::assertSame("10\r\n0123456789abcdef\r\n", $method->invoke($emitter, '0123456789abcdef'));
    }

    #[Test]
    public function shouldEmitBodyIsFalseForHeadRequests(): void
    {
        // FR-36: a HEAD response carries the same headers as the equivalent GET
        // but no body. The emitter must know the request method to suppress it.
        $emitter = new ResponseEmitter();
        $method = new ReflectionMethod($emitter, 'shouldEmitBody');

        self::assertFalse($method->invoke($emitter, 'HEAD'));
        self::assertFalse($method->invoke($emitter, 'head'));
        self::assertTrue($method->invoke($emitter, 'GET'));
        self::assertTrue($method->invoke($emitter, null));
    }

    #[Test]
    public function headContentLengthIsTheBodySizeTheEquivalentGetWouldCarry(): void
    {
        // RFC 9110 §8.6: the Content-Length on a HEAD response MUST NOT differ
        // from the equivalent GET. With no body written, the SAPI would derive
        // 0 -- the emitter must advertise the real body size instead.
        $response = new Response(statusCode: 200, body: 'Hello world');

        self::assertSame(11, new ResponseEmitter()->headContentLength($response));
    }

    #[Test]
    public function headContentLengthIsZeroForAnEmptyBody(): void
    {
        // The equivalent GET would also carry 0: identical, therefore conformant.
        $response = new Response(statusCode: 204, body: '');

        self::assertSame(0, new ResponseEmitter()->headContentLength($response));
    }

    #[Test]
    public function headContentLengthDefersToAnExplicitHeader(): void
    {
        // An application-declared Content-Length passes through emitHeaders()
        // untouched; emitting a second value would corrupt the response.
        $response = new Response(statusCode: 200, body: 'Hello world')
            ->withHeader('Content-Length', '11');

        self::assertNull(new ResponseEmitter()->headContentLength($response));
    }

    #[Test]
    public function headContentLengthIsOmittedForStreamedResponses(): void
    {
        // A streamed GET carries no Content-Length either (chunked framing);
        // RFC 9110 permits omitting fields only determinable while generating
        // the content, so identical omission is conformant.
        $response = new StreamedResponse(new ArrayIterator(['chunk']));

        self::assertNull(new ResponseEmitter()->headContentLength($response));
    }
}
