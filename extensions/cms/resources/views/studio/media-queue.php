<?php
/**
 * CMS Studio — Media Processing Queue Panel.
 *
 * @var list<array{jobId: string, status: string, jobClass: string, mediaAssetId: string, derivativeType: string, createdAt: int, completedAt: int|null, failedAt: int|null}>|null $pending
 * @var list<array{jobId: string, status: string, jobClass: string, mediaAssetId: string, derivativeType: string, createdAt: int, completedAt: int|null, failedAt: int|null}>|null $completed
 * @var list<array{jobId: string, status: string, jobClass: string, mediaAssetId: string, derivativeType: string, createdAt: int, completedAt: int|null, failedAt: int|null}>|null $failed
 * @var string|null $activeTab
 * @var int|null $pendingCount
 */

$typedPending = $pending ?? [];
$typedCompleted = $completed ?? [];
$typedFailed = $failed ?? [];
$typedActiveTab = $activeTab ?? 'pending';
$typedPendingCount = $pendingCount ?? count($typedPending);

$tabs = [
    'pending' => ['label' => 'Pending', 'count' => count($typedPending), 'entries' => $typedPending],
    'completed' => ['label' => 'Completed', 'count' => count($typedCompleted), 'entries' => $typedCompleted],
    'failed' => ['label' => 'Failed', 'count' => count($typedFailed), 'entries' => $typedFailed],
];

$activeEntries = $tabs[$typedActiveTab]['entries'] ?? $typedPending;
?>
<div class="explorer">
    <div class="explorer-header">
        <h2>Media processing queue
            <?php if ($typedPendingCount > 0): ?>
                <span class="badge" aria-label="<?= $typedPendingCount ?> pending jobs"><?= $typedPendingCount ?></span>
            <?php endif; ?>
        </h2>
    </div>

    <nav style="display:flex;gap:var(--studio-space-xs);margin-bottom:var(--studio-space-md)" role="tablist" aria-label="Queue status filter">
        <?php foreach ($tabs as $tabKey => $tab): ?>
            <a href="/studio/cms/media-queue?tab=<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>"
                class="btn <?= $typedActiveTab === $tabKey ? 'btn-active' : '' ?>"
                role="tab"
                aria-selected="<?= $typedActiveTab === $tabKey ? 'true' : 'false' ?>"
                aria-controls="media-queue-panel"
                id="tab-<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?>
                <span style="opacity:0.7">(<?= $tab['count'] ?>)</span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div id="media-queue-panel" role="tabpanel"
        aria-labelledby="tab-<?= htmlspecialchars($typedActiveTab, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($activeEntries === []): ?>
            <div class="empty-state" role="status">
                <h2>No Jobs</h2>
                <p>No <?= htmlspecialchars($typedActiveTab, ENT_QUOTES, 'UTF-8') ?> media processing jobs.</p>
            </div>
        <?php else: ?>
            <div class="card" style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Asset</th>
                            <th scope="col">Derivative type</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                            <?php if ($typedActiveTab === 'completed'): ?>
                                <th scope="col">Completed</th>
                            <?php elseif ($typedActiveTab === 'failed'): ?>
                                <th scope="col">Failed</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeEntries as $job): ?>
                            <tr>
                                <td>
                                    <code title="<?= htmlspecialchars($job['mediaAssetId'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(substr($job['mediaAssetId'] ?? '', 0, 12) . '...', ENT_QUOTES, 'UTF-8') ?></code>
                                </td>
                                <td>
                                    <span class="event-type-badge" aria-label="Derivative type: <?= htmlspecialchars($job['derivativeType'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($job['derivativeType'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $status = $job['status'] ?? 'pending';
                            $statusStyle = match ($status) {
                                'pending' => 'background:rgba(56,189,248,0.15);color:var(--studio-accent)',
                                'completed' => 'background:rgba(34,197,94,0.15);color:var(--studio-green)',
                                'failed' => 'background:rgba(239,68,68,0.15);color:var(--studio-red)',
                                default => '',
                            };
                            ?>
                                    <span class="event-type-badge" style="<?= $statusStyle ?>" aria-label="Status: <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="time-cell">
                                    <?php $created = $job['createdAt'] ?? 0; ?>
                                    <time datetime="<?= htmlspecialchars(date('c', $created), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(date('Y-m-d H:i:s', $created), ENT_QUOTES, 'UTF-8') ?>
                                    </time>
                                </td>
                                <?php if ($typedActiveTab === 'completed'): ?>
                                    <td class="time-cell">
                                        <?php if (($job['completedAt'] ?? null) !== null): ?>
                                            <time datetime="<?= htmlspecialchars(date('c', (int) $job['completedAt']), ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars(date('Y-m-d H:i:s', (int) $job['completedAt']), ENT_QUOTES, 'UTF-8') ?>
                                            </time>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                <?php elseif ($typedActiveTab === 'failed'): ?>
                                    <td class="time-cell">
                                        <?php if (($job['failedAt'] ?? null) !== null): ?>
                                            <time datetime="<?= htmlspecialchars(date('c', (int) $job['failedAt']), ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars(date('Y-m-d H:i:s', (int) $job['failedAt']), ENT_QUOTES, 'UTF-8') ?>
                                            </time>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
