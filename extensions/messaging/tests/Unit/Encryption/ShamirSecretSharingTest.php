<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Encryption;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Encryption\ShamirSecretSharing;

#[CoversClass(ShamirSecretSharing::class)]
final class ShamirSecretSharingTest extends TestCase
{
    private ShamirSecretSharing $sss;

    protected function setUp(): void
    {
        $this->sss = new ShamirSecretSharing();
    }

    public function testSplitAndReconstructWithExactThreshold(): void
    {
        $secret = random_bytes(32);
        $shares = $this->sss->split($secret, 5, 3);

        self::assertCount(5, $shares);

        // Use exactly 3 shares
        $subset = [1 => $shares[1], 2 => $shares[2], 3 => $shares[3]];
        $reconstructed = $this->sss->reconstruct($subset);

        self::assertSame($secret, $reconstructed);
    }

    public function testSplitAndReconstructWithMoreThanThreshold(): void
    {
        $secret = random_bytes(32);
        $shares = $this->sss->split($secret, 5, 3);

        // Use 4 shares (more than threshold)
        $subset = [1 => $shares[1], 2 => $shares[2], 3 => $shares[3], 4 => $shares[4]];
        $reconstructed = $this->sss->reconstruct($subset);

        self::assertSame($secret, $reconstructed);
    }

    public function testAnyThresholdSubsetWorks(): void
    {
        $secret = random_bytes(16);
        $shares = $this->sss->split($secret, 5, 3);

        // Try different combinations of 3 shares
        $combinations = [
            [1, 2, 3],
            [1, 2, 4],
            [1, 2, 5],
            [1, 3, 5],
            [2, 4, 5],
            [3, 4, 5],
        ];

        foreach ($combinations as $indices) {
            $subset = [];
            foreach ($indices as $i) {
                $subset[$i] = $shares[$i];
            }

            $reconstructed = $this->sss->reconstruct($subset);
            self::assertSame(
                $secret,
                $reconstructed,
                'Shares [' . implode(', ', $indices) . '] should reconstruct the secret',
            );
        }
    }

    public function testSplitWithThresholdOf2(): void
    {
        $secret = 'hello';
        $shares = $this->sss->split($secret, 3, 2);

        self::assertCount(3, $shares);

        $reconstructed = $this->sss->reconstruct([1 => $shares[1], 2 => $shares[2]]);
        self::assertSame($secret, $reconstructed);
    }

    public function testThresholdLessThan2Throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Threshold must be at least 2');

        $this->sss->split('secret', 3, 1);
    }

    public function testTotalSharesLessThanThresholdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Total shares must be >= threshold');

        $this->sss->split('secret', 2, 3);
    }

    public function testTooManySharesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 255 shares');

        $this->sss->split('secret', 256, 2);
    }

    public function testReconstructWithTooFewSharesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least 2 shares');

        $this->sss->reconstruct([1 => 'share']);
    }

    #[DataProvider('secretsProvider')]
    public function testVariousSecretLengths(string $secret): void
    {
        $shares = $this->sss->split($secret, 5, 3);
        $subset = [1 => $shares[1], 3 => $shares[3], 5 => $shares[5]];
        $reconstructed = $this->sss->reconstruct($subset);

        self::assertSame($secret, $reconstructed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretsProvider(): iterable
    {
        yield '1 byte' => ["\x42"];
        yield '16 bytes' => [random_bytes(16)];
        yield '32 bytes (AES key)' => [random_bytes(32)];
        yield '64 bytes' => [random_bytes(64)];
        yield 'ascii string' => ['my-recovery-passphrase-here'];
    }
}
