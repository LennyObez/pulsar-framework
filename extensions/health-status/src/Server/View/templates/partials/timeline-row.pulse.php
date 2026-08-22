<?php
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Resilience\HealthCheck\HealthStatus;

assert($snapshot instanceof HealthSnapshot);

$statusClass = match ($snapshot->overallStatus) {
    HealthStatus::Healthy => 'healthy',
    HealthStatus::Degraded => 'degraded',
    HealthStatus::Unhealthy => 'unhealthy',
};

$statusLabel = match ($snapshot->overallStatus) {
    HealthStatus::Healthy => 'Healthy',
    HealthStatus::Degraded => 'Degraded',
    HealthStatus::Unhealthy => 'Unhealthy',
};

$timeFormatted = $snapshot->capturedAt->format('Y-m-d H:i:s');
$timeIso = $snapshot->capturedAt->format(DateTimeImmutable::ATOM);
$durationFormatted = number_format($snapshot->totalDurationMs, 1) . ' ms';
$total = count($snapshot->results);
$passed = 0;
foreach ($snapshot->results as $r) {
    if ($r['status'] === 'healthy') {
        $passed++;
    }
}
$failed = $total - $passed;
?>
<div class="timeline__row" role="row">
    <time class="timeline__timestamp" datetime="<?= htmlspecialchars($timeIso, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"><?= htmlspecialchars($timeFormatted, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></time>
    <span class="status-badge status-badge--<?= $statusClass ?>"><?= $statusLabel ?></span>
    <span class="timeline__summary">
        <span class="timeline__passed"><?= $passed ?> passed</span>
<?php if ($failed > 0): ?>
        / <span class="timeline__failed"><?= $failed ?> failed</span>
<?php endif; ?>
        of <?= $total ?>
    </span>
    <span class="timeline__duration"><?= htmlspecialchars($durationFormatted, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
</div>
