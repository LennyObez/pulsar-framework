<?php
/**
 * @var list<array{timestamp_us?: int, event_type?: string, request_id?: string}>|null $events
 * @var bool|null $showCorrelation
 */
/** @var list<array{timestamp_us?: int, event_type?: string, request_id?: string}> $typedEvents */
$typedEvents = $events ?? [];
$showCorrelationFlag = $showCorrelation ?? true;
?>
<table class="data-table">
    <thead>
        <tr>
            <th>Time</th>
            <th>Type</th>
            <?php if ($showCorrelationFlag): ?>
                <th>Request ID</th>
            <?php endif; ?>
            <th>Details</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($typedEvents as $event): ?>
            <tr>
                <td class="time-cell"><?= htmlspecialchars(date('H:i:s', (int) (($event['timestamp_us'] ?? 0) / 1_000_000)), ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="event-type-badge"><?= htmlspecialchars($event['event_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                <?php if ($showCorrelationFlag): ?>
                    <td>
                        <?php
                        $requestId = $event['request_id'] ?? '';
                    if ($requestId !== ''): ?>
                            <a href="/studio/console/timeline/<?= htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') ?>" class="correlation-link">
                                <?= htmlspecialchars(substr($requestId, 0, 12) . '...', ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td class="details-cell"><?= htmlspecialchars($event['event_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
