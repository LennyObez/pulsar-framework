<?php
/**
 * CMS Studio — Content Cache Inspector Panel.
 *
 * @var list<array{cacheKey: string, contentId: string, isHit: bool}>|null $entries
 * @var float|null $hitRate
 * @var int|null $totalHits
 * @var int|null $totalMisses
 * @var float|null $metricsHitRate
 */

$typedEntries = $entries ?? [];
$typedHitRate = $hitRate ?? 0.0;
$typedTotalHits = $totalHits ?? 0;
$typedTotalMisses = $totalMisses ?? 0;
$typedMetricsHitRate = $metricsHitRate ?? 0.0;
$hitRatePercent = round($typedHitRate * 100);
$metricsPercent = round($typedMetricsHitRate * 100);
?>
<div class="explorer">
    <div class="explorer-header">
        <h2>Content Cache Inspector</h2>
    </div>

    <div class="metrics-row">
        <div class="metric-card">
            <div class="metric-label">Inspection Hit Rate</div>
            <div class="metric-value" role="meter"
                aria-valuenow="<?= $hitRatePercent ?>" aria-valuemin="0" aria-valuemax="100"
                aria-label="Cache inspection hit rate: <?= $hitRatePercent ?>%"><?= $hitRatePercent ?>%</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Metrics Hit Rate (Overall)</div>
            <div class="metric-value" role="meter"
                aria-valuenow="<?= $metricsPercent ?>" aria-valuemin="0" aria-valuemax="100"
                aria-label="Overall metrics hit rate: <?= $metricsPercent ?>%"><?= $metricsPercent ?>%</div>
        </div>
        <div class="metric-card">
            <div class="metric-label" aria-label="<?= $typedTotalHits ?> cache hits">Hits</div>
            <div class="metric-value" style="color:var(--studio-green)"><?= $typedTotalHits ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label" aria-label="<?= $typedTotalMisses ?> cache misses">Misses</div>
            <div class="metric-value" style="color:var(--studio-red)"><?= $typedTotalMisses ?></div>
        </div>
    </div>

    <div class="card">
        <h3>Invalidation</h3>
        <div style="display:flex;flex-wrap:wrap;gap:var(--studio-space-md);align-items:flex-end" role="group" aria-label="Cache invalidation actions">
            <form method="POST" action="/studio/cms/cache/invalidate-id" style="display:flex;gap:var(--studio-space-sm);align-items:flex-end">
                <div class="filter-group">
                    <label for="cache-invalidate-id">Content ID</label>
                    <input type="text" id="cache-invalidate-id" name="content_id"
                        class="filter-select" placeholder="Content ID"
                        aria-required="true" required>
                </div>
                <button type="submit" class="btn">Invalidate by ID</button>
            </form>
            <form method="POST" action="/studio/cms/cache/invalidate-tag" style="display:flex;gap:var(--studio-space-sm);align-items:flex-end">
                <div class="filter-group">
                    <label for="cache-invalidate-tag">Cache Tag</label>
                    <input type="text" id="cache-invalidate-tag" name="tag"
                        class="filter-select" placeholder="Cache tag"
                        aria-required="true" required>
                </div>
                <button type="submit" class="btn">Invalidate by Tag</button>
            </form>
            <form method="POST" action="/studio/cms/cache/flush"
                data-confirm="Are you sure you want to flush all CMS page cache entries?">
                <button type="submit" class="btn" style="color:var(--studio-red);border-color:var(--studio-red)">Flush All</button>
            </form>
        </div>
    </div>

    <?php if ($typedEntries === []): ?>
        <div class="empty-state" role="status">
            <h2>No Cached Pages Inspected</h2>
            <p>Provide content IDs to inspect their cache status.</p>
        </div>
    <?php else: ?>
        <div class="card" style="overflow-x:auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Cache Key</th>
                        <th scope="col">Content ID</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($typedEntries as $entry): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($entry['cacheKey'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><code><?= htmlspecialchars($entry['contentId'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td>
                                <?php if ($entry['isHit'] ?? false): ?>
                                    <span class="event-type-badge" style="background:rgba(34,197,94,0.15);color:var(--studio-green)" aria-label="Cache hit">HIT</span>
                                <?php else: ?>
                                    <span class="event-type-badge" style="background:rgba(239,68,68,0.15);color:var(--studio-red)" aria-label="Cache miss">MISS</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
