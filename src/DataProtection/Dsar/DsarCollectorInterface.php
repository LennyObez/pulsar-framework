<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use Pulsar\Api\Api;

/**
 * Interface for extensions that hold personal data to implement.
 *
 * Each extension or module that stores personal data should register
 * a collector that can gather all data for a given subject ID. The
 * DsarRequestHandler orchestrates all collectors during a DSAR.
 * @api
 */
#[Api(since: '1.0.0')]
interface DsarCollectorInterface
{
    /**
     * Collect all personal data held for a subject.
     *
     * @param string $subjectId The data subject identifier
     *
     * @return DsarDataSet The collected data with category metadata
     */
    public function collect(string $subjectId): DsarDataSet;

    /**
     * The human-readable name of this data source.
     */
    public function sourceName(): string;
}
