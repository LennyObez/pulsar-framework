<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\OtlpProtocol;

#[CoversNothing]
final class OtlpProtocolTest extends TestCase
{
    #[Test]
    #[DataProvider('caseProvider')]
    public function backedValues(OtlpProtocol $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{OtlpProtocol, string}>
     */
    public static function caseProvider(): iterable
    {
        yield 'http/protobuf' => [OtlpProtocol::HttpProtobuf, 'http/protobuf'];
        yield 'grpc' => [OtlpProtocol::Grpc, 'grpc'];
    }

    #[Test]
    public function tryFromValidValue(): void
    {
        self::assertSame(OtlpProtocol::Grpc, OtlpProtocol::tryFrom('grpc'));
    }

    #[Test]
    public function tryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(OtlpProtocol::tryFrom('http/json'));
    }

    #[Test]
    public function allCasesAreCovered(): void
    {
        self::assertCount(2, OtlpProtocol::cases());
    }
}
