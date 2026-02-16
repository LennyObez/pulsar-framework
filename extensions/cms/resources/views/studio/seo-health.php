<?php
/**
 * CMS Studio: SEO Health Report Panel.
 *
 * @var int|null $brokenLinkCount
 * @var list<array{id: string, sourceContentId: string, sourceLocale: string, targetUrl: string, httpStatusCode: int|null, lastCheckedAt: string}>|null $brokenLinks
 * @var int|null $orphanContentCount
 * @var list<array{id: string, title: string, type: string, status: string}>|null $orphanContent
 * @var string|null $sitemapLastGenerated
 * @var int|null $sitemapEntryCount
 * @var list<string>|null $sitemapErrors
 */

$typedBrokenLinkCount = $brokenLinkCount ?? 0;
$typedBrokenLinks = $brokenLinks ?? [];
$typedOrphanContentCount = $orphanContentCount ?? 0;
$typedOrphanContent = $orphanContent ?? [];
$typedSitemapLastGenerated = $sitemapLastGenerated ?? null;
$typedSitemapEntryCount = $sitemapEntryCount ?? 0;
$typedSitemapErrors = $sitemapErrors ?? [];
?>
<div class="explorer">
    <div class="explorer-header">
        <h2>SEO health report</h2>
    </div>

    <div class="metrics-row">
        <div class="metric-card" <?= $typedBrokenLinkCount > 0 ? 'style="border-color:var(--studio-red)"' : 'style="border-color:var(--studio-green)"' ?>>
            <div class="metric-label">Broken links</div>
            <div class="metric-value" style="color:<?= $typedBrokenLinkCount > 0 ? 'var(--studio-red)' : 'var(--studio-green)' ?>"
                aria-label="<?= $typedBrokenLinkCount ?> broken links"><?= $typedBrokenLinkCount ?></div>
        </div>
        <div class="metric-card" <?= $typedOrphanContentCount > 0 ? 'style="border-color:var(--studio-yellow)"' : 'style="border-color:var(--studio-green)"' ?>>
            <div class="metric-label">Orphan content</div>
            <div class="metric-value" style="color:<?= $typedOrphanContentCount > 0 ? 'var(--studio-yellow)' : 'var(--studio-green)' ?>"
                aria-label="<?= $typedOrphanContentCount ?> orphan pages"><?= $typedOrphanContentCount ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Sitemap entries</div>
            <div class="metric-value" aria-label="<?= $typedSitemapEntryCount ?> sitemap entries"><?= $typedSitemapEntryCount ?></div>
        </div>
    </div>

    <?php /* Sitemap Status */ ?>
    <div class="card">
        <h3>Sitemap status</h3>
        <dl style="margin:0">
            <div style="display:flex;justify-content:space-between;padding:var(--studio-space-sm) 0;border-bottom:1px solid var(--studio-border)">
                <dt style="color:var(--studio-text-muted)">Last generated</dt>
                <dd style="margin:0;font-weight:500">
                    <?php if ($typedSitemapLastGenerated !== null): ?>
                        <time datetime="<?= htmlspecialchars($typedSitemapLastGenerated, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($typedSitemapLastGenerated, ENT_QUOTES, 'UTF-8') ?>
                        </time>
                    <?php else: ?>
                        <span style="color:var(--studio-text-dim)">Never generated</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div style="display:flex;justify-content:space-between;padding:var(--studio-space-sm) 0;border-bottom:1px solid var(--studio-border)">
                <dt style="color:var(--studio-text-muted)">Entries</dt>
                <dd style="margin:0;font-weight:500"><?= $typedSitemapEntryCount ?></dd>
            </div>
            <?php if ($typedSitemapErrors !== []): ?>
                <div style="padding:var(--studio-space-sm) 0">
                    <dt style="color:var(--studio-text-muted);margin-bottom:var(--studio-space-xs)">Errors</dt>
                    <dd style="margin:0">
                        <ul style="list-style:none;padding:0;margin:0" role="list">
                            <?php foreach ($typedSitemapErrors as $sitemapError): ?>
                                <li class="error" style="margin-bottom:var(--studio-space-xs)"><?= htmlspecialchars($sitemapError, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>

    <?php /* Broken Links */ ?>
    <div class="card">
        <h3>Broken links
            <?php if ($typedBrokenLinkCount > 0): ?>
                <span class="badge" style="background:var(--studio-red)" aria-label="<?= $typedBrokenLinkCount ?> broken links"><?= $typedBrokenLinkCount ?></span>
            <?php endif; ?>
        </h3>
        <?php if ($typedBrokenLinks === []): ?>
            <div class="empty-state" role="status" style="min-height:100px">
                <p>No broken links detected.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Source Content</th>
                            <th scope="col">Target URL</th>
                            <th scope="col">Status Code</th>
                            <th scope="col">Last Checked</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($typedBrokenLinks as $link): ?>
                            <tr>
                                <td>
                                    <code title="<?= htmlspecialchars($link['sourceContentId'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(substr($link['sourceContentId'] ?? '', 0, 12) . '...', ENT_QUOTES, 'UTF-8') ?></code>
                                    <span style="color:var(--studio-text-dim);font-size:0.75rem"> (<?= htmlspecialchars($link['sourceLocale'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</span>
                                </td>
                                <td class="details-cell" style="max-width:300px;word-break:break-all">
                                    <?= htmlspecialchars($link['targetUrl'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?php $statusCode = $link['httpStatusCode'] ?? null; ?>
                                    <?php if ($statusCode !== null): ?>
                                        <span class="event-type-badge" style="background:rgba(239,68,68,0.15);color:var(--studio-red)" aria-label="HTTP status <?= $statusCode ?>">
                                            <?= $statusCode ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="event-type-badge" aria-label="Unreachable">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="time-cell">
                                    <time datetime="<?= htmlspecialchars($link['lastCheckedAt'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($link['lastCheckedAt'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    </time>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php /* Orphan Content */ ?>
    <div class="card">
        <h3>Orphan content
            <?php if ($typedOrphanContentCount > 0): ?>
                <span class="badge" style="background:var(--studio-yellow)" aria-label="<?= $typedOrphanContentCount ?> orphan pages"><?= $typedOrphanContentCount ?></span>
            <?php endif; ?>
        </h3>
        <?php if ($typedOrphanContent === []): ?>
            <div class="empty-state" role="status" style="min-height:100px">
                <p>No orphan content detected. All published content has taxonomy terms or menu links.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Type</th>
                            <th scope="col">Status</th>
                            <th scope="col">ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($typedOrphanContent as $content): ?>
                            <tr>
                                <td><?= htmlspecialchars($content['title'] ?? '(Untitled)', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="event-type-badge" aria-label="Content type: <?= htmlspecialchars($content['type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($content['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $contentStatus = $content['status'] ?? 'draft';
                            $contentStatusStyle = match ($contentStatus) {
                                'published' => 'background:rgba(34,197,94,0.15);color:var(--studio-green)',
                                'scheduled' => 'background:rgba(56,189,248,0.15);color:var(--studio-accent)',
                                default => '',
                            };
                            ?>
                                    <span class="event-type-badge" style="<?= $contentStatusStyle ?>" aria-label="Status: <?= htmlspecialchars($contentStatus, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($contentStatus), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <code title="<?= htmlspecialchars($content['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(substr($content['id'] ?? '', 0, 12) . '...', ENT_QUOTES, 'UTF-8') ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
