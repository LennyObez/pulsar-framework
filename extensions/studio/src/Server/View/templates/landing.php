<?php
/**
 * @var string $formattedCount
 * @var string $formattedSize
 * @var string $samplingPct
 * @var int $retentionDays
 * @var int $maxSizeMb
 * @var string $collectors
 * @var string $storagePath
 */
$typedCount = $formattedCount ?? '0';
$typedSize = $formattedSize ?? '0 B';
$typedSampling = $samplingPct ?? '100%';
$typedRetention = $retentionDays ?? 7;
$typedMaxSize = $maxSizeMb ?? 100;
$typedCollectors = $collectors ?? '';
$typedStorage = $storagePath ?? '';
?>
<nav class="studio-nav">
    <a href="/studio" class="nav-brand" data-t="studio.title"><?= __('studio.title') ?></a>
    <div class="nav-links">
        <a href="/studio/console" data-t="studio.console_overview"><?= __('studio.console_overview') ?></a>
        <a href="/studio/console/requests" data-t="studio.http_requests"><?= __('studio.http_requests') ?></a>
        <a href="/studio/console/database" data-t="studio.database_queries"><?= __('studio.database_queries') ?></a>
        <a href="/studio/console/logs" data-t="studio.logs"><?= __('studio.logs') ?></a>
        <a href="/studio/console/exceptions" data-t="studio.exceptions"><?= __('studio.exceptions') ?></a>
        <a href="/studio/console/benchmarks" data-t="studio.benchmarks"><?= __('studio.benchmarks') ?></a>
        <a href="/studio/console/activity" data-t="studio.activity_log"><?= __('studio.activity_log') ?></a>
        <a href="/studio/console/health" data-t="studio.health"><?= __('studio.health') ?></a>
        <a href="/studio/console/deployments" data-t="studio.deployments"><?= __('studio.deployments') ?></a>
    </div>
</nav>
<div class="dashboard">
    <div class="metrics-row">
        <div class="metric-card">
            <div class="metric-label" data-t="studio.events_recorded"><?= __('studio.events_recorded') ?></div>
            <div class="metric-value"><?= htmlspecialchars($typedCount, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="metric-subtitle" data-t="studio.total_events"><?= __('studio.total_events') ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label" data-t="studio.storage_used"><?= __('studio.storage_used') ?></div>
            <div class="metric-value"><?= htmlspecialchars($typedSize, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="metric-subtitle"><?= __('studio.storage_limit', ['size' => $typedMaxSize]) ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label" data-t="studio.sampling_rate"><?= __('studio.sampling_rate') ?></div>
            <div class="metric-value"><?= htmlspecialchars($typedSampling, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="metric-subtitle" data-t="studio.events_captured"><?= __('studio.events_captured') ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label" data-t="studio.retention"><?= __('studio.retention') ?></div>
            <div class="metric-value"><?= $typedRetention ?>d</div>
            <div class="metric-subtitle" data-t="studio.max_age"><?= __('studio.max_age') ?></div>
        </div>
    </div>
    <div class="card">
        <h3 data-t="studio.explorers"><?= __('studio.explorers') ?></h3>
        <div class="landing-nav-grid">
            <a href="/studio/console" class="landing-nav-card">
                <span class="card-title" data-t="studio.console_overview"><?= __('studio.console_overview') ?></span>
                <span class="card-desc" data-t="studio.console_overview_desc"><?= __('studio.console_overview_desc') ?></span>
            </a>
            <a href="/studio/console/requests" class="landing-nav-card">
                <span class="card-title" data-t="studio.http_requests"><?= __('studio.http_requests') ?></span>
                <span class="card-desc" data-t="studio.http_requests_desc"><?= __('studio.http_requests_desc') ?></span>
            </a>
            <a href="/studio/console/database" class="landing-nav-card">
                <span class="card-title" data-t="studio.database_queries"><?= __('studio.database_queries') ?></span>
                <span class="card-desc" data-t="studio.database_queries_desc"><?= __('studio.database_queries_desc') ?></span>
            </a>
            <a href="/studio/console/logs" class="landing-nav-card">
                <span class="card-title" data-t="studio.logs"><?= __('studio.logs') ?></span>
                <span class="card-desc" data-t="studio.logs_desc"><?= __('studio.logs_desc') ?></span>
            </a>
            <a href="/studio/console/exceptions" class="landing-nav-card">
                <span class="card-title" data-t="studio.exceptions"><?= __('studio.exceptions') ?></span>
                <span class="card-desc" data-t="studio.exceptions_desc"><?= __('studio.exceptions_desc') ?></span>
            </a>
            <a href="/studio/console/benchmarks" class="landing-nav-card">
                <span class="card-title" data-t="studio.benchmarks"><?= __('studio.benchmarks') ?></span>
                <span class="card-desc" data-t="studio.benchmarks_desc"><?= __('studio.benchmarks_desc') ?></span>
            </a>
            <a href="/studio/console/activity" class="landing-nav-card">
                <span class="card-title" data-t="studio.activity_log"><?= __('studio.activity_log') ?></span>
                <span class="card-desc" data-t="studio.activity_log_desc"><?= __('studio.activity_log_desc') ?></span>
            </a>
            <a href="/studio/console/health" class="landing-nav-card">
                <span class="card-title" data-t="studio.health"><?= __('studio.health') ?></span>
                <span class="card-desc" data-t="studio.health_desc"><?= __('studio.health_desc') ?></span>
            </a>
            <a href="/studio/console/deployments" class="landing-nav-card">
                <span class="card-title" data-t="studio.deployments"><?= __('studio.deployments') ?></span>
                <span class="card-desc" data-t="studio.deployments_desc"><?= __('studio.deployments_desc') ?></span>
            </a>
        </div>
    </div>
    <div class="card">
        <h3 data-t="studio.configuration"><?= __('studio.configuration') ?></h3>
        <table class="config-table">
            <tr>
                <td data-t="studio.config_storage"><?= __('studio.config_storage') ?></td>
                <td><code><?= htmlspecialchars($typedStorage, ENT_QUOTES, 'UTF-8') ?></code></td>
            </tr>
            <tr>
                <td data-t="studio.config_sampling"><?= __('studio.config_sampling') ?></td>
                <td><?= htmlspecialchars($typedSampling, ENT_QUOTES, 'UTF-8') ?> <?= __('studio.of_events') ?></td>
            </tr>
            <tr>
                <td data-t="studio.config_retention"><?= __('studio.config_retention') ?></td>
                <td><?= __('studio.retention_value', ['days' => $typedRetention, 'size' => $typedMaxSize]) ?></td>
            </tr>
            <tr>
                <td data-t="studio.config_collectors"><?= __('studio.config_collectors') ?></td>
                <td><div class="collector-badges"><?= $typedCollectors ?></div></td>
            </tr>
        </table>
    </div>
</div>
