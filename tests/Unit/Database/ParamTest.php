<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Param;

use function strlen;

#[CoversClass(Param::class)]
final class ParamTest extends TestCase
{
    #[Test]
    public function binaryCreatesBinaryParam(): void
    {
        $bytes = "\x00\x01\x02\x03\x04";
        $param = Param::binary($bytes);

        self::assertSame($bytes, $param->bytes());
        self::assertSame(PDO::PARAM_LOB, $param->pdoType());
    }

    #[Test]
    public function binaryHandlesEmptyBytes(): void
    {
        $param = Param::binary('');

        self::assertSame('', $param->bytes());
        self::assertSame(PDO::PARAM_LOB, $param->pdoType());
    }

    #[Test]
    public function binaryPreservesRawBinaryData(): void
    {
        $binaryData = random_bytes(32);
        $param = Param::binary($binaryData);

        self::assertSame($binaryData, $param->bytes());
    }

    #[Test]
    public function binaryHandlesLargePayload(): void
    {
        $largeData = str_repeat("\xFF", 1024 * 1024);
        $param = Param::binary($largeData);

        self::assertSame($largeData, $param->bytes());
        self::assertSame(PDO::PARAM_LOB, $param->pdoType());
    }

    #[Test]
    public function binaryHandlesUtf8String(): void
    {
        $utf8 = 'Hello, World! Привет мир!';
        $param = Param::binary($utf8);

        self::assertSame($utf8, $param->bytes());
    }

    #[Test]
    public function binaryHandlesNullBytes(): void
    {
        $data = "before\x00after";
        $param = Param::binary($data);

        self::assertSame($data, $param->bytes());
        self::assertSame(12, strlen($param->bytes()));
    }
}
