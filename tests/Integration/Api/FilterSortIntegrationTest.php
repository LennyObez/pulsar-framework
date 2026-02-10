<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Filter\Filter;
use Pulsar\Api\Filter\FilterParser;
use Pulsar\Api\Filter\FilterRegistry;
use Pulsar\Api\Filter\QueryFilter;
use Pulsar\Api\Sort\SortDefinition;
use Pulsar\Api\Sort\SortDirection;
use Pulsar\Api\Sort\SortParser;
use Pulsar\Api\Sort\SortRegistry;

/**
 * Integration test: end-to-end filter and sort pipeline with authorization gates.
 *
 * Exercises: filter registration → parsing → type validation → SQL mapping,
 * and sort registration → parsing → authorization enforcement.
 */
#[CoversClass(FilterParser::class)]
#[CoversClass(FilterRegistry::class)]
#[CoversClass(QueryFilter::class)]
#[CoversClass(SortParser::class)]
#[CoversClass(SortRegistry::class)]
final class FilterSortIntegrationTest extends TestCase
{
    private FilterRegistry $filterRegistry;
    private FilterParser $filterParser;
    private QueryFilter $queryFilter;
    private SortRegistry $sortRegistry;
    private SortParser $sortParser;

    protected function setUp(): void
    {
        $this->filterRegistry = new FilterRegistry();
        $this->filterRegistry->register('orders', [
            'status' => Filter::string(),
            'total' => Filter::float(),
            'created_at' => Filter::date('created_at'),
            'priority' => Filter::integer(),
            'active' => Filter::boolean(),
            'internal_notes' => Filter::string()->guard('admin'),
        ]);

        $this->filterParser = new FilterParser($this->filterRegistry);
        $this->queryFilter = new QueryFilter();

        $this->sortRegistry = new SortRegistry();
        $this->sortRegistry->register('orders', [
            'created_at' => new SortDefinition(column: 'created_at'),
            'total' => new SortDefinition(column: 'total'),
            'priority' => new SortDefinition(column: 'priority', guard: 'manager'),
        ]);

        $this->sortParser = new SortParser($this->sortRegistry);
    }

    // --- Full filter pipeline ---

    #[Test]
    public function fullFilterPipelineStringEquality(): void
    {
        $expressions = $this->filterParser->parse('orders', ['status' => 'eq:shipped']);
        $conditions = $this->queryFilter->apply($expressions);

        self::assertCount(1, $conditions);
        self::assertSame('status', $conditions[0]['column']);
        self::assertSame('=', $conditions[0]['operator']);
        self::assertSame('shipped', $conditions[0]['value']);
    }

    #[Test]
    public function fullFilterPipelineIntegerComparison(): void
    {
        $expressions = $this->filterParser->parse('orders', ['priority' => 'gte:3']);
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('priority', $conditions[0]['column']);
        self::assertSame('>=', $conditions[0]['operator']);
        self::assertSame(3, $conditions[0]['value']);
    }

    #[Test]
    public function fullFilterPipelineFloatComparison(): void
    {
        $expressions = $this->filterParser->parse('orders', ['total' => 'gt:99.99']);
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('total', $conditions[0]['column']);
        self::assertSame('>', $conditions[0]['operator']);
        self::assertSame(99.99, $conditions[0]['value']);
    }

    #[Test]
    public function fullFilterPipelineDateParsing(): void
    {
        $expressions = $this->filterParser->parse('orders', ['created_at' => 'gte:2025-06-01']);
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('created_at', $conditions[0]['column']);
        self::assertSame('>=', $conditions[0]['operator']);
        self::assertInstanceOf(DateTimeImmutable::class, $conditions[0]['value']);
    }

    #[Test]
    public function fullFilterPipelineBooleanTrue(): void
    {
        $expressions = $this->filterParser->parse('orders', ['active' => 'eq:true']);
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('active', $conditions[0]['column']);
        self::assertSame('=', $conditions[0]['operator']);
        self::assertTrue($conditions[0]['value']);
    }

    #[Test]
    public function fullFilterPipelineContainsProducesLike(): void
    {
        $expressions = $this->filterParser->parse('orders', ['status' => 'contains:ship']);
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('status', $conditions[0]['column']);
        self::assertSame('LIKE', $conditions[0]['operator']);
        self::assertSame('%ship%', $conditions[0]['value']);
    }

    #[Test]
    public function fullFilterPipelineMultipleFilters(): void
    {
        $expressions = $this->filterParser->parse('orders', [
            'status' => 'eq:shipped',
            'priority' => 'gte:2',
            'active' => 'eq:true',
        ]);
        $conditions = $this->queryFilter->apply($expressions);

        self::assertCount(3, $conditions);
        self::assertSame('status', $conditions[0]['column']);
        self::assertSame('priority', $conditions[1]['column']);
        self::assertSame('active', $conditions[2]['column']);
    }

    // --- Authorization gates ---

    #[Test]
    public function guardedFilterDeniedWithoutRole(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(403);

        (void) $this->filterParser->parse(
            'orders',
            ['internal_notes' => 'eq:important'],
            userRoles: ['user'],
        );
    }

    #[Test]
    public function guardedFilterAllowedWithRole(): void
    {
        $expressions = $this->filterParser->parse(
            'orders',
            ['internal_notes' => 'eq:important'],
            userRoles: ['admin'],
        );

        self::assertCount(1, $expressions);
        self::assertSame('important', $expressions[0]->value);
    }

    #[Test]
    public function guardedSortDeniedWithoutRole(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(403);

        (void) $this->sortParser->parse('orders', 'priority', userRoles: ['user']);
    }

    #[Test]
    public function guardedSortAllowedWithRole(): void
    {
        $expressions = $this->sortParser->parse('orders', 'priority', userRoles: ['manager']);

        self::assertCount(1, $expressions);
        self::assertSame('priority', $expressions[0]->column);
    }

    // --- Sort pipeline ---

    #[Test]
    public function fullSortPipelineAscending(): void
    {
        $expressions = $this->sortParser->parse('orders', 'created_at');

        self::assertCount(1, $expressions);
        self::assertSame('created_at', $expressions[0]->column);
        self::assertSame(SortDirection::Ascending, $expressions[0]->direction);
    }

    #[Test]
    public function fullSortPipelineDescending(): void
    {
        $expressions = $this->sortParser->parse('orders', '-total');

        self::assertCount(1, $expressions);
        self::assertSame('total', $expressions[0]->column);
        self::assertSame(SortDirection::Descending, $expressions[0]->direction);
    }

    #[Test]
    public function fullSortPipelineMultipleFields(): void
    {
        $expressions = $this->sortParser->parse('orders', '-created_at,total');

        self::assertCount(2, $expressions);
        self::assertSame('created_at', $expressions[0]->column);
        self::assertSame(SortDirection::Descending, $expressions[0]->direction);
        self::assertSame('total', $expressions[1]->column);
        self::assertSame(SortDirection::Ascending, $expressions[1]->direction);
    }

    // --- Error cases ---

    #[Test]
    public function invalidFilterTypeRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);

        (void) $this->filterParser->parse('orders', ['priority' => 'eq:not-a-number']);
    }

    #[Test]
    public function invalidFilterOperatorRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);

        (void) $this->filterParser->parse('orders', ['active' => 'gt:true']);
    }

    #[Test]
    public function unknownFilterFieldRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);

        (void) $this->filterParser->parse('orders', ['nonexistent' => 'eq:value']);
    }

    #[Test]
    public function unknownSortFieldRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);

        (void) $this->sortParser->parse('orders', 'nonexistent');
    }

    #[Test]
    public function unknownResourceForFilterRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);

        (void) $this->filterParser->parse('unknown_resource', ['field' => 'eq:value']);
    }

    #[Test]
    public function unknownResourceForSortRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);

        (void) $this->sortParser->parse('unknown_resource', 'field');
    }

    // --- Combined filter + sort ---

    #[Test]
    public function combinedFilterAndSortPipeline(): void
    {
        // Simulate a request with both filter and sort
        $filterExpressions = $this->filterParser->parse('orders', [
            'status' => 'eq:shipped',
            'total' => 'gte:50.00',
        ]);
        $sortExpressions = $this->sortParser->parse('orders', '-created_at,total');

        $conditions = $this->queryFilter->apply($filterExpressions);

        // Verify filter conditions
        self::assertCount(2, $conditions);
        self::assertSame('shipped', $conditions[0]['value']);
        self::assertSame(50.0, $conditions[1]['value']);

        // Verify sort expressions
        self::assertCount(2, $sortExpressions);
        self::assertSame(SortDirection::Descending, $sortExpressions[0]->direction);
        self::assertSame(SortDirection::Ascending, $sortExpressions[1]->direction);
    }
}
