<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\ResponseEmitter;
use ReflectionMethod;

#[CoversClass(ResponseEmitter::class)]
final class ResponseEmitterTest extends TestCase
{
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
}
