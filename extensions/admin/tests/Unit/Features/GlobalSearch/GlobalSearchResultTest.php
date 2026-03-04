<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\GlobalSearch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchResult;

#[CoversClass(GlobalSearchResult::class)]
final class GlobalSearchResultTest extends TestCase
{
    #[Test]
    public function constructor_sets_results_and_total(): void
    {
        $results = [
            'users' => [['id' => '1', 'name' => 'John']],
            'orders' => [['id' => '10', 'total' => '99.99']],
        ];

        $result = new GlobalSearchResult(results: $results, totalMatches: 2);

        self::assertSame($results, $result->results);
        self::assertSame(2, $result->totalMatches);
    }

    #[Test]
    public function empty_results(): void
    {
        $result = new GlobalSearchResult(results: [], totalMatches: 0);

        self::assertSame([], $result->results);
        self::assertSame(0, $result->totalMatches);
    }
}
