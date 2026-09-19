<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\MonitoringRecord;

/**
 * Where monitoring results are retained.
 *
 * ISO 42001:2023 Clause 9.1 ends by requiring the organisation to *retain
 * appropriate documented information as evidence of the results*. Running a
 * monitoring hook discharges the middle of that clause and none of its end: a
 * {@see MonitoringResult} that is returned to a caller and dropped is a
 * measurement nobody can produce afterwards, which is the same position an
 * auditor finds when no monitoring ran at all. This contract is the difference
 * between the two.
 *
 * ONE METHOD REMOVES, and it is here on retention grounds rather than for the
 * convenience of anything that inspects this store. Documented information is
 * *controlled* documented information (Clause 7.5.3): it is kept for a defined
 * period and then disposed of, and a store with no disposal path makes a
 * retention schedule unimplementable and leaves records about a retired model
 * accumulating for as long as the deployment lives. {@see purgeForModel()} is
 * how a retention job discharges that, and it is scoped to one model precisely
 * so that it cannot be used to empty the register.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface MonitoringRecordStoreInterface
{
    /**
     * Retain one monitoring result.
     *
     * Appends. A store that overwrote would hold a current status rather than a
     * history, and Clause 9.1 asks for evidence of the results — plural, over
     * time — because a drift that appears and is corrected is exactly what the
     * clause exists to make visible.
     */
    public function record(MonitoringRecord $record): void;

    /**
     * The retained records for one model, newest first.
     *
     * @param non-empty-string $modelId
     * @param positive-int     $limit   How many to return at most
     *
     * @return list<MonitoringRecord>
     */
    #[NoDiscard]
    public function forModel(string $modelId, int $limit = 100): array;

    /**
     * How many records are retained for one model.
     *
     * Separate from {@see forModel()} because a caller establishing that a
     * record survived should not have to page a history to find out, and a
     * caller bounding what it wrote needs a number rather than a page.
     *
     * @param non-empty-string $modelId
     */
    #[NoDiscard]
    public function countForModel(string $modelId): int;

    /**
     * Dispose of every retained record for one model, and report how many went.
     *
     * The return value is not optional to read: this is a deletion of evidence,
     * and a caller that discards the count cannot say afterwards what it removed.
     *
     * @param non-empty-string $modelId
     */
    #[NoDiscard]
    public function purgeForModel(string $modelId): int;
}
