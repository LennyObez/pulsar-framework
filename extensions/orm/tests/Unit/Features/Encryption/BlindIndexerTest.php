<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Encryption;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Param;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Features\Encryption\BlindIndexer;

final class BlindIndexerTest extends TestCase
{
    #[Test]
    public function computeReturnsBinaryParamWithHash(): void
    {
        $encryptor = $this->createStub(ColumnEncryptorInterface::class);
        $encryptor->method('blindIndex')->willReturn('hashed_value');

        $indexer = new BlindIndexer($encryptor);
        $result = $indexer->compute('plaintext', 32);

        self::assertInstanceOf(Param::class, $result);
    }

    #[Test]
    public function computeUsesDefaultHashLength(): void
    {
        $encryptor = $this->createMock(ColumnEncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('blindIndex')
            ->with('test', 32)
            ->willReturn('hash');

        $indexer = new BlindIndexer($encryptor);
        $indexer->compute('test');
    }

    #[Test]
    public function computeUsesCustomHashLength(): void
    {
        $encryptor = $this->createMock(ColumnEncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('blindIndex')
            ->with('data', 16)
            ->willReturn('shorthash');

        $indexer = new BlindIndexer($encryptor);
        $indexer->compute('data', 16);
    }
}
