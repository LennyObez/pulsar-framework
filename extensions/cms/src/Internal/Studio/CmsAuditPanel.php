<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Internal\Studio\Dto\AuditPanelEntry;
use Pulsar\Security\Audit\AuditChainResult;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;

use function array_filter;
use function array_values;
use function count;
use function str_starts_with;

/**
 * Studio panel data provider for CMS-specific audit events.
 *
 * Provides a filtered view of audit entries relevant to the CMS
 * (actions prefixed with "cms."), with support for filtering by
 * event type and date range. Includes chain verification capability.
 */
#[Internal]
final readonly class CmsAuditPanel
{
    /** CMS action prefix used to filter audit entries. */
    private const string CMS_ACTION_PREFIX = 'cms.';

    public function __construct(
        private AuditChainVerifier $chainVerifier,
    ) {}

    /**
     * Filter a list of audit entries to only those relevant to CMS operations.
     *
     * @param list<AuditEntry> $allEntries All audit entries from the sink
     * @param AuditEvent|null $eventTypeFilter Filter by specific event type
     * @param DateTimeImmutable|null $from Start of date range (inclusive)
     * @param DateTimeImmutable|null $to End of date range (inclusive)
     *
     * @return list<AuditPanelEntry>
     */
    public function getEntries(
        array $allEntries,
        ?AuditEvent $eventTypeFilter = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array {
        $filtered = array_values(array_filter(
            $allEntries,
            static function (AuditEntry $entry) use ($eventTypeFilter, $from, $to): bool {
                // Only include CMS-related actions
                if (!str_starts_with($entry->action, self::CMS_ACTION_PREFIX)) {
                    return false;
                }

                if ($eventTypeFilter !== null && $entry->event !== $eventTypeFilter) {
                    return false;
                }

                if ($from !== null && $entry->timestamp < $from) {
                    return false;
                }

                if ($to !== null && $entry->timestamp > $to) {
                    return false;
                }

                return true;
            },
        ));

        $result = [];

        foreach ($filtered as $entry) {
            $result[] = new AuditPanelEntry(
                id: $entry->id,
                eventType: $entry->event->value,
                outcome: $entry->outcome->value,
                actor: $entry->actor,
                action: $entry->action,
                resource: $entry->resource,
                timestamp: $entry->timestamp,
                evidenceHash: $entry->hmac,
            );
        }

        return $result;
    }

    /**
     * Verify the integrity of the audit chain for a set of entries.
     *
     * @param list<AuditEntry> $entries Ordered entries (oldest first)
     * @param string|null $expectedFirstPreviousHmac Seed HMAC for the first entry
     */
    public function verifyChain(array $entries, ?string $expectedFirstPreviousHmac = null): AuditChainResult
    {
        return $this->chainVerifier->verifyChain($entries, $expectedFirstPreviousHmac);
    }

    /**
     * Get the count of CMS audit entries from a full entry list.
     *
     * @param list<AuditEntry> $allEntries
     */
    public function countCmsEntries(array $allEntries): int
    {
        return count(array_filter(
            $allEntries,
            static fn(AuditEntry $e): bool => str_starts_with($e->action, self::CMS_ACTION_PREFIX),
        ));
    }
}
