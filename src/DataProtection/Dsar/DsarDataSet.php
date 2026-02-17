<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use Pulsar\Api\Api;

/**
 * A set of personal data collected from a single source.
 *
 * Contains structured records and optional file attachments (e.g.,
 * uploaded profile photos, documents) for inclusion in the DSAR
 * data package.
 */
#[Api(since: '1.0.0')]
final readonly class DsarDataSet
{
    /**
     * @param string $sourceName Name of the data source
     * @param string $category Data category (e.g. 'profile', 'orders', 'analytics')
     * @param list<array<string, mixed>> $records Structured data records
     * @param list<DsarAttachment> $attachments File attachments
     */
    public function __construct(
        public string $sourceName,
        public string $category,
        public array $records,
        public array $attachments = [],
    ) {}

    /**
     * Create an empty data set (source holds no data for this subject).
     */
    public static function empty(string $sourceName, string $category): self
    {
        return new self($sourceName, $category, []);
    }
}
