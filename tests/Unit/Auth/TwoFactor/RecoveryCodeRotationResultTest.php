<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\RecoveryCodeRotationResult;
use Pulsar\Auth\TwoFactor\RecoveryCodeSet;

use function sprintf;

#[CoversClass(RecoveryCodeRotationResult::class)]
final class RecoveryCodeRotationResultTest extends TestCase
{
    private function createSet(string $setId, int $codeCount): RecoveryCodeSet
    {
        return new RecoveryCodeSet(
            setId: $setId,
            codeHashes: array_fill(0, $codeCount, '$hash$'),
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: time(),
        );
    }

    #[Test]
    public function constructorSetsSetAndPlaintextCodes(): void
    {
        $set = $this->createSet('set-001', 3);
        $plaintextCodes = ['ABCD-1234', 'EFGH-5678', 'IJKL-9012'];

        $result = new RecoveryCodeRotationResult(
            set: $set,
            plaintextCodes: $plaintextCodes,
        );

        self::assertSame($set, $result->set);
        self::assertSame($plaintextCodes, $result->plaintextCodes);
    }

    #[Test]
    public function plaintextCodesCountMatchesSet(): void
    {
        $set = $this->createSet('set-002', 8);
        $plaintext = array_map(
            static fn(int $i): string => sprintf('CODE-%04d', $i),
            range(1, 8),
        );

        $result = new RecoveryCodeRotationResult($set, $plaintext);

        self::assertCount(8, $result->plaintextCodes);
    }

    #[Test]
    public function emptyCodesAreValid(): void
    {
        $set = $this->createSet('set-003', 0);
        $result = new RecoveryCodeRotationResult($set, []);

        self::assertSame([], $result->plaintextCodes);
    }
}
