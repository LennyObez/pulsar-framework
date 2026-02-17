<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Analytics\Contracts\EcommerceServiceInterface;
use Pulsar\Extension\Analytics\Domain\EcommerceItem;
use Pulsar\Extension\Analytics\Domain\EcommerceTransaction;

use function array_key_first;
use function array_map;
use function array_slice;
use function array_values;
use function json_decode;
use function json_encode;
use function round;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * E-commerce analytics tracking and reporting.
 */
#[Internal(reason: 'E-commerce service; use EcommerceServiceInterface')]
final readonly class EcommerceService implements EcommerceServiceInterface
{
    private const string SQL_INSERT_TRANSACTION = <<<'SQL'
        INSERT INTO analytics_ecommerce_transactions (
            id, site_id, visitor_id, session_id, order_id,
            revenue, tax, shipping, currency, items, created_at
        ) VALUES (
            :id, :site_id, :visitor_id, :session_id, :order_id,
            :revenue, :tax, :shipping, :currency, :items, :created_at
        )
        SQL;

    private const string SQL_SUMMARY = <<<'SQL'
        SELECT
            currency,
            COALESCE(SUM(revenue), 0) AS total_revenue,
            COUNT(*) AS total_transactions,
            COALESCE(AVG(revenue), 0) AS avg_order_value
        FROM analytics_ecommerce_transactions
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
        GROUP BY currency
        ORDER BY total_revenue DESC
        SQL;

    private const string SQL_REVENUE_TIMESERIES = <<<'SQL'
        SELECT
            DATE(created_at) AS date,
            COALESCE(SUM(revenue), 0) AS revenue,
            COUNT(*) AS transactions
        FROM analytics_ecommerce_transactions
        WHERE site_id = :site_id
            AND created_at >= :from
            AND created_at <= :to
        GROUP BY DATE(created_at)
        ORDER BY date
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function recordTransaction(EcommerceTransaction $transaction): void
    {
        $itemsJson = $transaction->items !== []
            ? json_encode(
                array_map(static fn(EcommerceItem $i): array => [
                    'product_id' => $i->productId,
                    'name' => $i->name,
                    'category' => $i->category,
                    'price' => $i->price,
                    'quantity' => $i->quantity,
                    'variant' => $i->variant,
                ], $transaction->items),
                JSON_THROW_ON_ERROR,
            )
            : null;

        $this->connection->execute(self::SQL_INSERT_TRANSACTION, [
            'id' => $transaction->id,
            'site_id' => $transaction->siteId,
            'visitor_id' => $transaction->visitorId,
            'session_id' => $transaction->sessionId,
            'order_id' => $transaction->orderId,
            'revenue' => $transaction->revenue,
            'tax' => $transaction->tax,
            'shipping' => $transaction->shipping,
            'currency' => $transaction->currency,
            'items' => $itemsJson,
            'created_at' => $transaction->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    #[Override]
    public function getSummary(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $currencyResults = $this->connection->query(self::SQL_SUMMARY, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        $totalTransactions = 0;
        $primaryCurrency = 'USD';

        /** @var array<string, array{revenue: float, transactions: int, avg_order_value: float}> $byCurrency */
        $byCurrency = [];

        foreach ($currencyResults->rows as $row) {
            $currency = $row->getString('currency');
            $revenue = $row->getFloat('total_revenue');
            $transactions = $row->getInt('total_transactions');
            $totalTransactions += $transactions;

            $byCurrency[$currency] = [
                'revenue' => round($revenue, 2),
                'transactions' => $transactions,
                'avg_order_value' => $transactions > 0 ? round($revenue / $transactions, 2) : 0.0,
            ];
        }

        // Primary currency is the one with the most transactions
        if ($byCurrency !== []) {
            $primaryCurrency = array_key_first($byCurrency);
        }

        $primaryData = $byCurrency[$primaryCurrency] ?? ['revenue' => 0.0, 'transactions' => 0, 'avg_order_value' => 0.0];

        // Calculate conversion rate from total sessions
        $sessionsRow = $this->connection->query(
            'SELECT COUNT(DISTINCT session_id) AS cnt FROM analytics_page_views WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to',
            [
                'site_id' => $siteId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        )->first();

        $totalSessions = $sessionsRow?->getInt('cnt') ?? 0;
        $conversionRate = $totalSessions > 0
            ? round(($totalTransactions / (float) $totalSessions) * 100, 2)
            : 0.0;

        // Count total items sold
        $itemsRow = $this->connection->query(
            'SELECT items FROM analytics_ecommerce_transactions WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to',
            [
                'site_id' => $siteId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        );

        $itemsSold = 0;

        foreach ($itemsRow->rows as $r) {
            $itemsJson = $r->getNullableString('items');

            if ($itemsJson !== null) {
                /** @var list<array{quantity: int}> $items */
                $items = json_decode($itemsJson, true, flags: JSON_THROW_ON_ERROR);

                foreach ($items as $item) {
                    $itemsSold += $item['quantity'] ?? 1;
                }
            }
        }

        return [
            'revenue' => $primaryData['revenue'],
            'transactions' => $totalTransactions,
            'average_order_value' => $primaryData['avg_order_value'],
            'conversion_rate' => $conversionRate,
            'items_sold' => $itemsSold,
            'currency' => $primaryCurrency,
            'revenue_by_currency' => $byCurrency,
        ];
    }

    #[Override]
    public function getTopProducts(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 10): array
    {
        $result = $this->connection->query(
            'SELECT items FROM analytics_ecommerce_transactions WHERE site_id = :site_id AND created_at >= :from AND created_at <= :to',
            [
                'site_id' => $siteId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        );

        /** @var array<string, array{product_id: string, name: string, revenue: float, quantity: int}> $products */
        $products = [];

        foreach ($result->rows as $row) {
            $itemsJson = $row->getNullableString('items');

            if ($itemsJson === null) {
                continue;
            }

            /** @var list<array{product_id: string, name: string, price: float, quantity: int}> $items */
            $items = json_decode($itemsJson, true, flags: JSON_THROW_ON_ERROR);

            foreach ($items as $item) {
                $pid = $item['product_id'];

                if (!isset($products[$pid])) {
                    $products[$pid] = [
                        'product_id' => $pid,
                        'name' => $item['name'],
                        'revenue' => 0.0,
                        'quantity' => 0,
                    ];
                }

                $products[$pid]['revenue'] += ($item['price'] ?? 0.0) * ($item['quantity'] ?? 1);
                $products[$pid]['quantity'] += $item['quantity'] ?? 1;
            }
        }

        usort($products, static fn(array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return array_slice(array_values($products), 0, $limit);
    }

    #[Override]
    public function getRevenueTimeseries(string $siteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $result = $this->connection->query(self::SQL_REVENUE_TIMESERIES, [
            'site_id' => $siteId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        $data = [];

        foreach ($result->rows as $row) {
            $data[] = [
                'date' => $row->getString('date'),
                'revenue' => round($row->getFloat('revenue'), 2),
                'transactions' => $row->getInt('transactions'),
            ];
        }

        return $data;
    }
}
