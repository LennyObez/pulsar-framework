<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\WebSocketCloseCode;

#[CoversNothing]
final class WebSocketCloseCodeTest extends TestCase
{
    #[Test]
    #[DataProvider('rfc6455CodesProvider')]
    public function caseHasCorrectValue(WebSocketCloseCode $code, int $expectedValue): void
    {
        self::assertSame($expectedValue, $code->value);
    }

    /**
     * @return iterable<string, array{WebSocketCloseCode, int}>
     */
    public static function rfc6455CodesProvider(): iterable
    {
        yield 'Normal' => [WebSocketCloseCode::Normal, 1000];
        yield 'GoingAway' => [WebSocketCloseCode::GoingAway, 1001];
        yield 'ProtocolError' => [WebSocketCloseCode::ProtocolError, 1002];
        yield 'UnsupportedData' => [WebSocketCloseCode::UnsupportedData, 1003];
        yield 'NoStatusReceived' => [WebSocketCloseCode::NoStatusReceived, 1005];
        yield 'AbnormalClosure' => [WebSocketCloseCode::AbnormalClosure, 1006];
        yield 'InvalidPayload' => [WebSocketCloseCode::InvalidPayload, 1007];
        yield 'PolicyViolation' => [WebSocketCloseCode::PolicyViolation, 1008];
        yield 'MessageTooBig' => [WebSocketCloseCode::MessageTooBig, 1009];
        yield 'MandatoryExtension' => [WebSocketCloseCode::MandatoryExtension, 1010];
        yield 'InternalError' => [WebSocketCloseCode::InternalError, 1011];
        yield 'ServiceRestart' => [WebSocketCloseCode::ServiceRestart, 1012];
        yield 'TryAgainLater' => [WebSocketCloseCode::TryAgainLater, 1013];
    }

    #[Test]
    public function fromReturnsValidCase(): void
    {
        self::assertSame(WebSocketCloseCode::Normal, WebSocketCloseCode::from(1000));
    }

    #[Test]
    public function tryFromReturnsNullForUnknownCode(): void
    {
        self::assertNull(WebSocketCloseCode::tryFrom(9999));
    }

    #[Test]
    public function allCasesAreMapped(): void
    {
        $cases = WebSocketCloseCode::cases();

        self::assertCount(13, $cases);
    }
}
