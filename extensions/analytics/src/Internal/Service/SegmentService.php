<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Analytics\Contracts\SegmentServiceInterface;
use Pulsar\Extension\Analytics\Domain\Segment;
use Pulsar\Extension\Analytics\Domain\SegmentDimension;
use Pulsar\Extension\Analytics\Domain\SegmentFilter;
use Pulsar\Extension\Analytics\Domain\SegmentOperator;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Audience segment CRUD and visitor counting.
 */
#[Internal(reason: 'Segment service; use SegmentServiceInterface')]
final readonly class SegmentService implements SegmentServiceInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function create(string $siteId, string $name, array $filters): Segment
    {
        $id = bin2hex(random_bytes(18));
        $now = new DateTimeImmutable();

        $filtersJson = json_encode(
            array_map(static fn(SegmentFilter $f): array => [
                'dimension' => $f->dimension->value,
                'operator' => $f->operator->value,
                'value' => $f->value,
            ], $filters),
            JSON_THROW_ON_ERROR,
        );

        $this->connection->execute(
            'INSERT INTO analytics_segments (id, site_id, name, filters, created_at) VALUES (:id, :site_id, :name, :filters, :created_at)',
            [
                'id' => $id,
                'site_id' => $siteId,
                'name' => $name,
                'filters' => $filtersJson,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        return new Segment(
            id: $id,
            siteId: $siteId,
            name: $name,
            filters: $filters,
            createdAt: $now,
        );
    }

    #[Override]
    public function findById(string $id): ?Segment
    {
        $row = $this->connection->query(
            'SELECT * FROM analytics_segments WHERE id = :id',
            ['id' => $id],
        )->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    #[Override]
    public function listForSite(string $siteId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM analytics_segments WHERE site_id = :site_id ORDER BY created_at DESC',
            ['site_id' => $siteId],
        );

        $segments = [];

        foreach ($result->rows as $row) {
            $segments[] = $this->hydrate($row);
        }

        return $segments;
    }

    #[Override]
    public function delete(string $id): void
    {
        $affected = $this->connection->execute(
            'DELETE FROM analytics_segments WHERE id = :id',
            ['id' => $id],
        );

        if ($affected === 0) {
            throw AnalyticsException::notFound('Segment', $id);
        }
    }

    #[Override]
    public function countVisitors(string $segmentId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $segment = $this->findById($segmentId);

        if ($segment === null) {
            throw AnalyticsException::notFound('Segment', $segmentId);
        }

        $whereClauses = ['pv.site_id = :site_id', 'pv.created_at >= :from', 'pv.created_at <= :to'];
        $params = [
            'site_id' => $segment->siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        $joins = '';

        foreach ($segment->filters as $i => $filter) {
            $paramKey = 'filter_' . $i;
            $clause = $this->buildFilterClause($filter, $paramKey);

            if ($clause !== null) {
                if ($filter->dimension === SegmentDimension::EntryPage) {
                    $joins = ' INNER JOIN analytics_sessions s ON pv.session_id = s.session_id AND pv.site_id = s.site_id';
                }

                $whereClauses[] = $clause;
                $params[$paramKey] = $filter->value;
            }
        }

        $where = implode(' AND ', $whereClauses);
        $sql = "SELECT COUNT(DISTINCT pv.visitor_id) AS cnt FROM analytics_page_views pv{$joins} WHERE {$where}";

        $row = $this->connection->query($sql, $params)->first();

        return $row?->getInt('cnt') ?? 0;
    }

    private function buildFilterClause(SegmentFilter $filter, string $paramKey): ?string
    {
        $column = match ($filter->dimension) {
            SegmentDimension::Country => 'pv.country_code',
            SegmentDimension::Browser => 'pv.browser',
            SegmentDimension::Os => 'pv.os',
            SegmentDimension::DeviceType => 'pv.device_type',
            SegmentDimension::ReferrerSource => 'pv.referrer_source',
            SegmentDimension::EntryPage => 's.entry_page',
            SegmentDimension::PageVisited => 'pv.pathname',
            SegmentDimension::UtmSource => 'pv.utm_source',
            SegmentDimension::UtmMedium => 'pv.utm_medium',
            SegmentDimension::UtmCampaign => 'pv.utm_campaign',
            default => null,
        };

        if ($column === null) {
            return null;
        }

        return match ($filter->operator) {
            SegmentOperator::Equals => "{$column} = :{$paramKey}",
            SegmentOperator::NotEquals => "{$column} != :{$paramKey}",
            SegmentOperator::Contains => $this->compileLike($column, $paramKey, '%', '%'),
            SegmentOperator::NotContains => $this->compileLike($column, $paramKey, '%', '%', true),
            SegmentOperator::StartsWith => $this->compileLike($column, $paramKey, '', '%'),
            default => null,
        };
    }

    /**
     * Build a portable LIKE expression that works across MySQL, PostgreSQL, and SQLite.
     *
     * MySQL/PG use CONCAT(), SQLite uses the || concatenation operator.
     */
    private function compileLike(string $column, string $paramKey, string $prefix, string $suffix, bool $negate = false): string
    {
        $keyword = $negate ? 'NOT LIKE' : 'LIKE';
        $driver = $this->connection->driver();

        if ($driver === Driver::SQLite) {
            $parts = [];
            if ($prefix !== '') {
                $parts[] = "'{$prefix}'";
            }
            $parts[] = ":{$paramKey}";
            if ($suffix !== '') {
                $parts[] = "'{$suffix}'";
            }

            $pattern = implode(' || ', $parts);

            return "{$column} {$keyword} ({$pattern})";
        }

        // MySQL / PostgreSQL: CONCAT()
        $args = [];
        if ($prefix !== '') {
            $args[] = "'{$prefix}'";
        }
        $args[] = ":{$paramKey}";
        if ($suffix !== '') {
            $args[] = "'{$suffix}'";
        }

        $concat = 'CONCAT(' . implode(', ', $args) . ')';

        return "{$column} {$keyword} {$concat}";
    }

    /**
     * @param \Pulsar\Database\Row $row
     */
    private function hydrate(mixed $row): Segment
    {
        /** @var list<array{dimension: string, operator: string, value: string}> $filtersData */
        $filtersData = json_decode($row->getString('filters'), true, flags: JSON_THROW_ON_ERROR);

        $filters = array_map(
            static fn(array $f): SegmentFilter => new SegmentFilter(
                dimension: SegmentDimension::from($f['dimension']),
                operator: SegmentOperator::from($f['operator']),
                value: $f['value'],
            ),
            $filtersData,
        );

        return new Segment(
            id: $row->getString('id'),
            siteId: $row->getString('site_id'),
            name: $row->getString('name'),
            filters: $filters,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
