<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Encryption;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Param;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Features\Encryption\BlindIndexer;

#[CoversClass(BlindIndexer::class)]
final class BlindIndexerTest extends TestCase
{
    #[Test]
    public function computeReturnsBinaryParam(): void
    {
        $hash = random_bytes(32);
        $encryptor = $this->createMock(ColumnEncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('blindIndex')
            ->with('test@example.com', 32)
            ->willReturn($hash);

        $indexer = new BlindIndexer($encryptor);
        $param = $indexer->compute('test@example.com');

        self::assertInstanceOf(Param::class, $param);
        self::assertSame($hash, $param->bytes());
        self::assertSame(PDO::PARAM_LOB, $param->pdoType());
    }

    #[Test]
    public function computePassesCustomHashLength(): void
    {
        $hash = random_bytes(16);
        $encryptor = $this->createMock(ColumnEncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('blindIndex')
            ->with('hello', 16)
            ->willReturn($hash);

        $indexer = new BlindIndexer($encryptor);
        $param = $indexer->compute('hello', 16);

        self::assertSame($hash, $param->bytes());
    }

    #[Test]
    public function computeDefaultHashLengthIs32(): void
    {
        $encryptor = $this->createMock(ColumnEncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('blindIndex')
            ->with('value', 32)
            ->willReturn(str_repeat("\x00", 32));

        $indexer = new BlindIndexer($encryptor);
        $indexer->compute('value');
    }

    #[Test]
    public function computeReturnsDifferentResultsForDifferentInputs(): void
    {
        $hash1 = random_bytes(32);
        $hash2 = random_bytes(32);

        $encryptor = $this->createStub(ColumnEncryptorInterface::class);
        $encryptor->method('blindIndex')
            ->willReturnMap([
                ['alice@example.com', 32, $hash1],
                ['bob@example.com', 32, $hash2],
            ]);

        $indexer = new BlindIndexer($encryptor);
        $param1 = $indexer->compute('alice@example.com');
        $param2 = $indexer->compute('bob@example.com');

        self::assertNotSame($param1->bytes(), $param2->bytes());
    }

    #[Test]
    public function computeReturnsSameResultForSameInput(): void
    {
        $hash = random_bytes(32);
        $encryptor = $this->createStub(ColumnEncryptorInterface::class);
        $encryptor->method('blindIndex')
            ->willReturn($hash);

        $indexer = new BlindIndexer($encryptor);
        $param1 = $indexer->compute('same-input');
        $param2 = $indexer->compute('same-input');

        self::assertSame($param1->bytes(), $param2->bytes());
    }

    #[Test]
    public function computeHandlesEmptyString(): void
    {
        $hash = random_bytes(32);
        $encryptor = $this->createMock(ColumnEncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('blindIndex')
            ->with('', 32)
            ->willReturn($hash);

        $indexer = new BlindIndexer($encryptor);
        $param = $indexer->compute('');

        self::assertSame($hash, $param->bytes());
    }
}
