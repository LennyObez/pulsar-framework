<?php
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;

assert($incident instanceof Incident);

$severityClass = match ($incident->severity) {
    IncidentSeverity::Minor => 'minor',
    IncidentSeverity::Major => 'major',
    IncidentSeverity::Critical => 'critical',
};

$severityLabel = match ($incident->severity) {
    IncidentSeverity::Minor => 'Minor',
    IncidentSeverity::Major => 'Major',
    IncidentSeverity::Critical => 'Critical',
};

$startedFormatted = $incident->startedAt->format('Y-m-d H:i:s');
$startedIso = $incident->startedAt->format(DateTimeImmutable::ATOM);

$now = new DateTimeImmutable();
$endTime = $incident->resolvedAt ?? $now;
$durationSeconds = $endTime->getTimestamp() - $incident->startedAt->getTimestamp();

if ($durationSeconds >= 3600) {
    $durationFormatted = sprintf('%dh %dm', intdiv($durationSeconds, 3600), intdiv($durationSeconds % 3600, 60));
} elseif ($durationSeconds >= 60) {
    $durationFormatted = sprintf('%dm %ds', intdiv($durationSeconds, 60), $durationSeconds % 60);
} else {
    $durationFormatted = sprintf('%ds', max(0, $durationSeconds));
}

$escapedCheckName = htmlspecialchars($incident->checkName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$escapedMessage = htmlspecialchars($incident->message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<div class="incident-banner incident-banner--<?= $severityClass ?>" role="alert" aria-label="<?= $severityLabel ?> incident: <?= $escapedCheckName ?>">
    <div class="incident-banner__header">
        <span class="incident-banner__severity"><?= $severityLabel ?></span>
        <span class="incident-banner__check-name"><?= $escapedCheckName ?></span>
    </div>
    <p class="incident-banner__message"><?= $escapedMessage ?></p>
    <div class="incident-banner__meta">
        <span class="incident-banner__meta-item">
            Started: <time datetime="<?= htmlspecialchars($startedIso, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"><?= htmlspecialchars($startedFormatted, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></time>
        </span>
        <span class="incident-banner__meta-item">
            Duration: <?= htmlspecialchars($durationFormatted, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
        </span>
    </div>
</div>
