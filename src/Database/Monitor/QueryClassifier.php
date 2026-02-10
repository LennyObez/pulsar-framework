<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Pulsar\Api\Internal;

use function preg_replace;
use function str_starts_with;
use function strtoupper;

/**
 * Classifies SQL queries by their primary operation type.
 */
#[Internal(reason: 'Implementation detail of SQL monitoring')]
final class QueryClassifier
{
    /**
     * Classify a SQL statement by its primary operation.
     */
    public static function classify(string $sql): QueryClassification
    {
        $normalized = strtoupper(self::stripLeadingComments($sql));

        if (str_starts_with($normalized, 'WITH')) {
            return self::classifyCte($normalized);
        }

        return self::classifyKeyword($normalized);
    }

    private static function classifyKeyword(string $normalized): QueryClassification
    {
        return match (true) {
            str_starts_with($normalized, 'INSERT') => QueryClassification::Insert,
            str_starts_with($normalized, 'UPDATE') => QueryClassification::Update,
            str_starts_with($normalized, 'DELETE') => QueryClassification::Delete,
            str_starts_with($normalized, 'CREATE'),
            str_starts_with($normalized, 'ALTER'),
            str_starts_with($normalized, 'DROP'),
            str_starts_with($normalized, 'TRUNCATE') => QueryClassification::Ddl,
            default => QueryClassification::Select,
        };
    }

    private static function classifyCte(string $normalized): QueryClassification
    {
        $withoutCte = preg_replace('/^WITH\s+.*?\)\s*/s', '', $normalized);
        if ($withoutCte === null || $withoutCte === '') {
            return QueryClassification::Select;
        }

        return self::classifyKeyword($withoutCte);
    }

    private static function stripLeadingComments(string $sql): string
    {
        $result = ltrim($sql);

        while (true) {
            if (str_starts_with($result, '--')) {
                $newline = strpos($result, "\n");
                $result = $newline === false ? '' : ltrim(substr($result, $newline + 1));
            } elseif (str_starts_with($result, '/*')) {
                $end = strpos($result, '*/');
                $result = $end === false ? '' : ltrim(substr($result, $end + 2));
            } else {
                break;
            }
        }

        return $result;
    }
}
