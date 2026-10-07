<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\MfaScopeRank;

#[CoversClass(MfaScopeRank::class)]
final class MfaScopeRankTest extends TestCase
{
    public function testRanksAreOrderedBroadestToNarrowest(): void
    {
        self::assertLessThan(MfaScopeRank::rank('privileged'), MfaScopeRank::rank('always'));
        self::assertLessThan(MfaScopeRank::rank('sensitive-data'), MfaScopeRank::rank('privileged'));
        self::assertLessThan(MfaScopeRank::rank('none'), MfaScopeRank::rank('sensitive-data'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function knownScopeProvider(): iterable
    {
        yield 'always' => ['always', 0];
        yield 'privileged' => ['privileged', 1];
        yield 'sensitive-data' => ['sensitive-data', 2];
        yield 'none' => ['none', 3];
    }

    #[DataProvider('knownScopeProvider')]
    public function testKnownScopeRanks(string $scope, int $expected): void
    {
        self::assertSame($expected, MfaScopeRank::rank($scope));
    }

    public function testUnknownScopeFallsBackToNoneRank(): void
    {
        // An unrecognized scope must resolve to the narrowest ('none') rank so a
        // typo or future scope is never silently treated as more permissive.
        self::assertSame(MfaScopeRank::rank('none'), MfaScopeRank::rank('conditional'));
    }
}
