<?php
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Resilience\HealthCheck\HealthStatus;

assert($current instanceof HealthSnapshot);
assert(is_array($history));
assert(is_array($incidents));

$overallClass = match ($current->overallStatus) {
    HealthStatus::Healthy => 'healthy',
    HealthStatus::Degraded => 'degraded',
    HealthStatus::Unhealthy => 'unhealthy',
};

$overallLabel = match ($current->overallStatus) {
    HealthStatus::Healthy => 'All Systems Operational',
    HealthStatus::Degraded => 'Partial System Degradation',
    HealthStatus::Unhealthy => 'Major System Outage',
};

$lastUpdatedIso = $current->capturedAt->format(DateTimeImmutable::ATOM);
$lastUpdatedDisplay = $current->capturedAt->format('M j, Y H:i:s T');
?>
<meta http-equiv="refresh" content="30">

<div class="status-banner status-banner--<?= $overallClass ?>" role="status" aria-live="polite">
    <span class="status-banner__icon" aria-hidden="true"></span>
    <span><?= htmlspecialchars($overallLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
</div>

<section aria-labelledby="checks-heading">
    <h2 id="checks-heading" class="status-section-title">Health Checks</h2>
    <div class="status-grid">
<?php foreach ($current->results as $result): ?>
        <?php $checkResult = $result; ?>
        <?php include __DIR__ . '/partials/check-card.pulse.php'; ?>
<?php endforeach; ?>
    </div>
</section>

<?php if ($incidents !== []): ?>
<section class="incidents-section" aria-labelledby="incidents-heading">
    <h2 id="incidents-heading" class="status-section-title">Active Incidents</h2>
<?php /** @var mixed $incident */
foreach ($incidents as $incident): ?>
    <?php include __DIR__ . '/partials/incident-banner.pulse.php'; ?>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($history !== []): ?>
<section class="timeline-section" aria-labelledby="history-heading">
    <h2 id="history-heading" class="status-section-title">History</h2>
    <div class="timeline" role="table" aria-label="Health check history">
        <div class="timeline__row" role="row" aria-hidden="true">
            <span><strong>Time</strong></span>
            <span><strong>Status</strong></span>
            <span><strong>Results</strong></span>
            <span><strong>Duration</strong></span>
        </div>
<?php /** @var mixed $snapshot */
foreach ($history as $snapshot): ?>
        <?php include __DIR__ . '/partials/timeline-row.pulse.php'; ?>
<?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<p class="last-updated">
    Last updated: <time class="last-updated__time" datetime="<?= htmlspecialchars($lastUpdatedIso, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"><?= htmlspecialchars($lastUpdatedDisplay, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></time>
</p>
