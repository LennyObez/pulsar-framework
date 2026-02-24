<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\ABTest\ConversionEvent;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;

#[Internal(reason: 'Raw-DB repository — use ExperimentRepositoryInterface for public API')]
final readonly class DbExperimentRepository implements ExperimentRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_experiments WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_CONTENT_ID = <<<'SQL'
        SELECT * FROM cms_experiments
        WHERE content_id = :content_id AND status = 'running'
        LIMIT 1
        SQL;

    private const string SQL_FIND_RUNNING = <<<'SQL'
        SELECT * FROM cms_experiments WHERE status = 'running'
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'name', 'content_id', 'status', 'traffic_percentage', 'start_at', 'end_at', 'created_at',
    ];

    private const array UPSERT_UPDATE = ['name', 'status', 'traffic_percentage', 'start_at', 'end_at'];

    private const array UPSERT_VARIANT_COLUMNS = ['id', 'experiment_id', 'name', 'content_id', 'weight'];
    private const array UPSERT_VARIANT_UPDATE = ['name', 'content_id', 'weight'];

    private const string SQL_FIND_VARIANTS = <<<'SQL'
        SELECT * FROM cms_experiment_variants WHERE experiment_id = :experiment_id ORDER BY weight DESC
        SQL;

    private const string SQL_INSERT_CONVERSION = <<<'SQL'
        INSERT INTO cms_conversion_events (id, experiment_id, variant_id, visitor_id, type, created_at)
        VALUES (:id, :experiment_id, :variant_id, :visitor_id, :type, :created_at)
        SQL;

    private const string SQL_COUNT_IMPRESSIONS = <<<'SQL'
        SELECT variant_id,
               COUNT(*) FILTER (WHERE type = 'impression') AS impressions,
               COUNT(*) FILTER (WHERE type != 'impression') AS conversions
        FROM cms_conversion_events
        WHERE experiment_id = :experiment_id
        GROUP BY variant_id
        SQL;

    private const string SQL_COUNT_IMPRESSIONS_COMPAT = <<<'SQL'
        SELECT variant_id,
               SUM(CASE WHEN type = 'impression' THEN 1 ELSE 0 END) AS impressions,
               SUM(CASE WHEN type != 'impression' THEN 1 ELSE 0 END) AS conversions
        FROM cms_conversion_events
        WHERE experiment_id = :experiment_id
        GROUP BY variant_id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?Experiment
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrateExperiment($row);
    }

    public function findByContentId(string $contentId): ?Experiment
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CONTENT_ID, ['content_id' => $contentId]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrateExperiment($row);
    }

    public function findRunning(): array
    {
        $result = $this->connection->query(self::SQL_FIND_RUNNING);

        return $result->map(self::hydrateExperiment(...));
    }

    public function save(Experiment $experiment): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_experiments',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $experiment->id,
            'name' => $experiment->name,
            'content_id' => $experiment->contentId,
            'status' => $experiment->status->value,
            'traffic_percentage' => $experiment->trafficPercentage,
            'start_at' => $experiment->startAt?->format('c'),
            'end_at' => $experiment->endAt?->format('c'),
            'created_at' => $experiment->createdAt->format('c'),
        ]);
    }

    public function saveVariant(ExperimentVariant $variant): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_experiment_variants',
            self::UPSERT_VARIANT_COLUMNS,
            ['id'],
            self::UPSERT_VARIANT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $variant->id,
            'experiment_id' => $variant->experimentId,
            'name' => $variant->name,
            'content_id' => $variant->contentId,
            'weight' => $variant->weight,
        ]);
    }

    public function findVariants(string $experimentId): array
    {
        $result = $this->connection->query(self::SQL_FIND_VARIANTS, ['experiment_id' => $experimentId]);

        return $result->map(self::hydrateVariant(...));
    }

    public function recordConversion(ConversionEvent $event): void
    {
        $this->connection->execute(self::SQL_INSERT_CONVERSION, [
            'id' => $event->id,
            'experiment_id' => $event->experimentId,
            'variant_id' => $event->variantId,
            'visitor_id' => $event->visitorId,
            'type' => $event->type,
            'created_at' => $event->createdAt->format('c'),
        ]);
    }

    public function getConversionCounts(string $experimentId): array
    {
        // Use FILTER syntax for PostgreSQL, fallback to CASE for SQLite/MySQL
        $sql = $this->connection->driver() === \Pulsar\Database\Driver::PostgreSQL
            ? self::SQL_COUNT_IMPRESSIONS
            : self::SQL_COUNT_IMPRESSIONS_COMPAT;

        $result = $this->connection->query($sql, ['experiment_id' => $experimentId]);

        $counts = [];

        foreach ($result->rows as $row) {
            $variantId = $row->getString('variant_id');
            $counts[$variantId] = [
                'impressions' => $row->getInt('impressions'),
                'conversions' => $row->getInt('conversions'),
            ];
        }

        return $counts;
    }

    private static function hydrateExperiment(Row $row): Experiment
    {
        return new Experiment(
            id: $row->getString('id'),
            name: $row->getString('name'),
            contentId: $row->getString('content_id'),
            status: ExperimentStatus::from($row->getString('status')),
            trafficPercentage: (float) $row->getString('traffic_percentage'),
            startAt: self::toDateTime($row->getNullableString('start_at')),
            endAt: self::toDateTime($row->getNullableString('end_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }

    private static function hydrateVariant(Row $row): ExperimentVariant
    {
        return new ExperimentVariant(
            id: $row->getString('id'),
            experimentId: $row->getString('experiment_id'),
            name: $row->getString('name'),
            contentId: $row->getString('content_id'),
            weight: $row->getInt('weight'),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
