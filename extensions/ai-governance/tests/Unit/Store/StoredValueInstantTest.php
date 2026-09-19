<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\Store\StoredValue;

/**
 * MySQL returns a DATETIME(6) column with six zero fraction digits; the stores write
 * whole seconds. Anything else in the column was not written by instantToStore().
 */
#[CoversClass(StoredValue::class)]
final class StoredValueInstantTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function spellingsOfTheSameInstant(): iterable
    {
        yield 'as written' => ['2026-08-28 10:54:00'];
        yield 'MySQL DATETIME(6)' => ['2026-08-28 10:54:00.000000'];
        yield 'one zero digit' => ['2026-08-28 10:54:00.0'];
        yield 'three zero digits' => ['2026-08-28 10:54:00.000'];
    }

    #[Test]
    #[DataProvider('spellingsOfTheSameInstant')]
    public function aZeroFractionReadsAsTheInstantWritten(string $stored): void
    {
        self::assertSame(
            StoredValue::instant('2026-08-28 10:54:00', 'ai_models', 'registered_at')->getTimestamp(),
            StoredValue::instant($stored, 'ai_models', 'registered_at')->getTimestamp(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function valuesNoStoreWrote(): iterable
    {
        yield 'a non-zero fraction' => ['2026-08-28 10:54:00.500000'];
        yield 'an ISO T separator' => ['2026-08-28T10:54:00'];
        yield 'an offset' => ['2026-08-28 10:54:00+00:00'];
        yield 'an empty column' => [''];
    }

    #[Test]
    #[DataProvider('valuesNoStoreWrote')]
    public function aValueNoStoreWroteIsRefused(string $stored): void
    {
        $this->expectException(AiGovernanceException::class);

        (void) StoredValue::instant($stored, 'ai_models', 'registered_at');
    }
}
