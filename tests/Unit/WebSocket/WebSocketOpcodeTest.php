<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\WebSocketOpcode;

#[CoversNothing]
final class WebSocketOpcodeTest extends TestCase
{
    #[Test]
    #[DataProvider('controlOpcodeProvider')]
    public function controlOpcodesAreControl(WebSocketOpcode $opcode): void
    {
        self::assertTrue($opcode->isControl());
        self::assertFalse($opcode->isData());
    }

    /**
     * @return iterable<string, array{WebSocketOpcode}>
     */
    public static function controlOpcodeProvider(): iterable
    {
        yield 'Close' => [WebSocketOpcode::Close];
        yield 'Ping' => [WebSocketOpcode::Ping];
        yield 'Pong' => [WebSocketOpcode::Pong];
    }

    #[Test]
    #[DataProvider('dataOpcodeProvider')]
    public function dataOpcodesAreData(WebSocketOpcode $opcode): void
    {
        self::assertTrue($opcode->isData());
        self::assertFalse($opcode->isControl());
    }

    /**
     * @return iterable<string, array{WebSocketOpcode}>
     */
    public static function dataOpcodeProvider(): iterable
    {
        yield 'Continuation' => [WebSocketOpcode::Continuation];
        yield 'Text' => [WebSocketOpcode::Text];
        yield 'Binary' => [WebSocketOpcode::Binary];
    }

    #[Test]
    public function opcodeValues(): void
    {
        self::assertSame(0x0, WebSocketOpcode::Continuation->value);
        self::assertSame(0x1, WebSocketOpcode::Text->value);
        self::assertSame(0x2, WebSocketOpcode::Binary->value);
        self::assertSame(0x8, WebSocketOpcode::Close->value);
        self::assertSame(0x9, WebSocketOpcode::Ping->value);
        self::assertSame(0xA, WebSocketOpcode::Pong->value);
    }
}
