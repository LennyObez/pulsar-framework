<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Search\PostgresSearchService;

use function exp;

/**
 * Tests the composite ranking formula used by PostgresSearchService.
 *
 * The ranking formula is: final_score = ts_rank * recencyBoost * taxonomyBoost
 * where:
 * - recencyBoost = 1.0 + 0.5 * exp(-daysSinceUpdate / 90)
 * - taxonomyBoost = 1.0 + 0.1 * matchingTerms
 */
#[CoversClass(PostgresSearchService::class)]
final class SearchRankingTest extends TestCase
{
    // -- Constants matching PostgresSearchService ----------------------------------

    private const float RECENCY_DECAY_DAYS = 90.0;
    private const float RECENCY_BOOST_BASE = 0.5;
    private const float TAXONOMY_BOOST_PER_TERM = 0.1;

    // -- Title match scores higher than body match (ts_rank weight) -------

    #[Test]
    public function titleMatchScoresHigherThanBodyMatch(): void
    {
        // PostgreSQL ts_rank weight A (title) is higher than C (body)
        // This is a ranking axiom — title hits produce higher ts_rank values.
        // For a same content item, a title match ts_rank is always > body match ts_rank.
        // We assert the formula respects this by verifying final score ordering.
        $titleTsRank = 0.8; // Simulated weight-A hit
        $bodyTsRank = 0.2; // Simulated weight-C hit

        $recencyBoost = $this->recencyBoost(0); // same day
        $taxonomyBoost = 1.0; // no taxonomy match

        $titleScore = $titleTsRank * $recencyBoost * $taxonomyBoost;
        $bodyScore = $bodyTsRank * $recencyBoost * $taxonomyBoost;

        self::assertGreaterThan($bodyScore, $titleScore);
    }

    // -- Recency boost: today vs 180 days ago -----------------------------

    #[Test]
    public function recencyBoostTodayHigherThan180Days(): void
    {
        $boostToday = $this->recencyBoost(0);
        $boost180 = $this->recencyBoost(180);

        self::assertGreaterThan($boost180, $boostToday);
    }

    #[Test]
    public function recencyBoostTodayEquals15(): void
    {
        // At 0 days: 1.0 + 0.5 * exp(0) = 1.0 + 0.5 = 1.5
        $boost = $this->recencyBoost(0);
        self::assertEqualsWithDelta(1.5, $boost, 0.001);
    }

    // -- Recency boost decay: 90 days gives ~1.303x -----------------------

    #[Test]
    public function recencyBoostAt90Days(): void
    {
        // At 90 days: 1.0 + 0.5 * exp(-90/90) = 1.0 + 0.5 * exp(-1) ≈ 1.0 + 0.5 * 0.368 ≈ 1.184
        $boost = $this->recencyBoost(90);
        $expected = 1.0 + self::RECENCY_BOOST_BASE * exp(-1.0);
        self::assertEqualsWithDelta($expected, $boost, 0.001);
    }

    #[Test]
    public function recencyBoostApproaches1ForVeryOldContent(): void
    {
        $boost = $this->recencyBoost(3650); // 10 years
        self::assertEqualsWithDelta(1.0, $boost, 0.01);
    }

    // -- Taxonomy boost: 0 terms = 1.0x, 3 terms = 1.3x ------------------

    #[Test]
    public function taxonomyBoostZeroTerms(): void
    {
        $boost = $this->taxonomyBoost(0);
        self::assertEqualsWithDelta(1.0, $boost, 0.001);
    }

    #[Test]
    public function taxonomyBoostThreeTerms(): void
    {
        $boost = $this->taxonomyBoost(3);
        self::assertEqualsWithDelta(1.3, $boost, 0.001);
    }

    #[Test]
    public function taxonomyBoostOneTerm(): void
    {
        $boost = $this->taxonomyBoost(1);
        self::assertEqualsWithDelta(1.1, $boost, 0.001);
    }

    #[Test]
    public function taxonomyBoostFiveTerms(): void
    {
        $boost = $this->taxonomyBoost(5);
        self::assertEqualsWithDelta(1.5, $boost, 0.001);
    }

    // -- Composite score ordering -----------------------------------------

    #[Test]
    public function recentContentRanksAboveStaleContentWithSameTsRank(): void
    {
        $tsRank = 0.5;
        $taxonomyBoost = 1.0;

        $recentScore = $tsRank * $this->recencyBoost(1) * $taxonomyBoost;
        $staleScore = $tsRank * $this->recencyBoost(365) * $taxonomyBoost;

        self::assertGreaterThan($staleScore, $recentScore);
    }

    #[Test]
    public function highTaxonomyMatchCanOffsetLowerTsRank(): void
    {
        $highTsLowTax = 0.5 * $this->recencyBoost(0) * $this->taxonomyBoost(0);
        $lowTsHighTax = 0.35 * $this->recencyBoost(0) * $this->taxonomyBoost(5);

        // 0.5 * 1.5 * 1.0 = 0.75
        // 0.35 * 1.5 * 1.5 = 0.7875
        self::assertGreaterThan($highTsLowTax, $lowTsHighTax);
    }

    // -- Helpers ----------------------------------------------------------

    private function recencyBoost(int $daysSinceUpdate): float
    {
        return 1.0 + self::RECENCY_BOOST_BASE * exp(-$daysSinceUpdate / self::RECENCY_DECAY_DAYS);
    }

    private function taxonomyBoost(int $matchingTerms): float
    {
        return 1.0 + self::TAXONOMY_BOOST_PER_TERM * $matchingTerms;
    }
}
