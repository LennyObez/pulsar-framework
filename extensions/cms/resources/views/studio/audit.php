<?php
/**
 * CMS Studio: Audit Trail Panel.
 *
 * @var list<array{id: string, eventType: string, outcome: string, actor: string, action: string, resource: string, timestamp: string, evidenceHash: string}>|null $entries
 * @var list<string>|null $eventTypes
 * @var string|null $currentEventType
 * @var string|null $dateFrom
 * @var string|null $dateTo
 * @var bool|null $chainValid
 */

$typedEntries = $entries ?? [];
$typedEventTypes = $eventTypes ?? [];
$typedCurrentEventType = $currentEventType ?? '';
$typedDateFrom = $dateFrom ?? '';
$typedDateTo = $dateTo ?? '';
$typedChainValid = $chainValid;
?>
<div class="explorer">
    <div class="explorer-header">
        <h2>CMS audit trail</h2>
        <div class="live-controls">
            <form method="POST" action="/studio/cms/audit/verify" style="display:inline">
                <button type="submit" class="btn">Verify Chain</button>
            </form>
        </div>
    </div>

    <?php if ($typedChainValid !== null): ?>
        <div class="<?= $typedChainValid ? 'card' : 'error' ?>" role="status" aria-live="polite">
            <?php if ($typedChainValid): ?>
                <p>Audit chain integrity verified &mdash; all entries are consistent.</p>
            <?php else: ?>
                <p>Audit chain integrity check FAILED &mdash; evidence chain is broken.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="filter-bar" role="search" aria-label="Filter audit entries">
        <div class="filter-group">
            <label for="audit-event-type">Event Type</label>
            <select id="audit-event-type" name="event_type" class="filter-select">
                <option value="">All Types</option>
                <?php foreach ($typedEventTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $typedCurrentEventType === $type ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="audit-date-from">From</label>
            <input type="date" id="audit-date-from" name="date_from"
                value="<?= htmlspecialchars($typedDateFrom, ENT_QUOTES, 'UTF-8') ?>"
                class="filter-select">
        </div>
        <div class="filter-group">
            <label for="audit-date-to">To</label>
            <input type="date" id="audit-date-to" name="date_to"
                value="<?= htmlspecialchars($typedDateTo, ENT_QUOTES, 'UTF-8') ?>"
                class="filter-select">
        </div>
    </div>

    <?php if ($typedEntries === []): ?>
        <div class="empty-state" role="status">
            <h2>No audit entries</h2>
            <p>No audit entries found for the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="card" style="overflow-x:auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Action</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Target</th>
                        <th scope="col">Outcome</th>
                        <th scope="col">Evidence Hash</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($typedEntries as $entry): ?>
                        <tr>
                            <td class="time-cell">
                                <time datetime="<?= htmlspecialchars($entry['timestamp'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($entry['timestamp'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </time>
                            </td>
                            <td>
                                <span class="event-type-badge" aria-label="Action: <?= htmlspecialchars($entry['action'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($entry['action'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($entry['actor'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="details-cell"><?= htmlspecialchars($entry['resource'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php
                                $outcome = $entry['outcome'] ?? 'unknown';
                        $outcomeBg = match ($outcome) {
                            'success' => 'background:rgba(34,197,94,0.15);color:var(--studio-green)',
                            'failure' => 'background:rgba(239,68,68,0.15);color:var(--studio-red)',
                            default => '',
                        };
                        ?>
                                <span class="event-type-badge" style="<?= $outcomeBg ?>" aria-label="Outcome: <?= htmlspecialchars($outcome, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($outcome, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="time-cell">
                                <code title="<?= htmlspecialchars($entry['evidenceHash'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(substr($entry['evidenceHash'] ?? '', 0, 16) . '...', ENT_QUOTES, 'UTF-8') ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
