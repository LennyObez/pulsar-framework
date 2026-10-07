<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\ExportIntegrityVerifier;

use function strlen;

#[CoversClass(ExportIntegrityVerifier::class)]
final class ExportIntegrityVerifierTest extends TestCase
{
    #[Test]
    public function computeHashReturnsDeterministicHex(): void
    {
        $content = '{"items":[1,2,3]}';

        $hash1 = ExportIntegrityVerifier::computeHash($content);
        $hash2 = ExportIntegrityVerifier::computeHash($content);

        self::assertSame($hash1, $hash2);
        // BLAKE2b default = 32 bytes = 64 hex chars
        self::assertSame(64, strlen($hash1));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash1);
    }

    #[Test]
    public function computeHashDifferentInputProducesDifferentHash(): void
    {
        $hash1 = ExportIntegrityVerifier::computeHash('content A');
        $hash2 = ExportIntegrityVerifier::computeHash('content B');

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function verifyReturnsTrueForMatchingHash(): void
    {
        $content = '{"exported": true}';
        $hash = ExportIntegrityVerifier::computeHash($content);

        self::assertTrue(ExportIntegrityVerifier::verify($content, $hash));
    }

    #[Test]
    public function verifyReturnsFalseForTamperedContent(): void
    {
        $content = '{"exported": true}';
        $hash = ExportIntegrityVerifier::computeHash($content);

        $tampered = '{"exported": false}';
        self::assertFalse(ExportIntegrityVerifier::verify($tampered, $hash));
    }

    #[Test]
    public function verifyReturnsFalseForWrongHash(): void
    {
        $content = '{"exported": true}';

        self::assertFalse(ExportIntegrityVerifier::verify($content, 'deadbeef'));
    }

    #[Test]
    public function computeHashHandlesEmptyString(): void
    {
        $hash = ExportIntegrityVerifier::computeHash('');

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function computeHashHandlesLargeContent(): void
    {
        $content = str_repeat('x', 100_000);
        $hash = ExportIntegrityVerifier::computeHash($content);

        self::assertSame(64, strlen($hash));
        self::assertTrue(ExportIntegrityVerifier::verify($content, $hash));
    }
}
