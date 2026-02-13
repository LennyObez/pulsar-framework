<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Exception;

use Pulsar\Api\Api;
use Pulsar\Runtime\ResourceEntry;
use RuntimeException;

use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * Thrown in strict mode when unreleased resources are detected at request end.
 */
#[Api(since: '1.0.0')]
final class ResourceLeakException extends RuntimeException
{
    public static function unclosedResource(ResourceEntry $entry): self
    {
        return new self(sprintf(
            'Unclosed resource detected: [%s] %s: %s (tracked at %.4f)',
            $entry->type,
            $entry->id,
            $entry->description,
            $entry->trackedAt,
        ));
    }

    /** @param list<ResourceEntry> $entries */
    public static function multipleLeaks(array $entries): self
    {
        $count = count($entries);
        $details = implode("\n  - ", array_map(
            static fn(ResourceEntry $e): string => sprintf('[%s] %s: %s', $e->type, $e->id, $e->description),
            $entries,
        ));

        return new self(sprintf(
            "%d unclosed resource(s) detected:\n  - %s",
            $count,
            $details,
        ));
    }
}
