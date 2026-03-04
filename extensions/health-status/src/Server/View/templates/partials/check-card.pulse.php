<?php
/**
 * @var array{name: string, status: string, message: string, latency_ms: float} $checkResult
 */

$statusClass = match ($checkResult['status']) {
    'healthy' => 'healthy',
    'degraded' => 'degraded',
    default => 'unhealthy',
};

$statusLabel = match ($checkResult['status']) {
    'healthy' => 'Healthy',
    'degraded' => 'Degraded',
    default => 'Unhealthy',
};

$escapedName = htmlspecialchars($checkResult['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$escapedMessage = htmlspecialchars($checkResult['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$formattedLatency = number_format($checkResult['latency_ms'], 1) . ' ms';
?>
<article class="check-card check-card--<?= $statusClass ?>" aria-label="<?= $escapedName ?>: <?= $statusLabel ?>">
    <div class="check-card__header">
        <h3 class="check-card__name"><?= $escapedName ?></h3>
        <span class="check-card__icon" role="img" aria-label="<?= $statusLabel ?>"></span>
    </div>
    <div class="check-card__body">
        <span class="status-badge status-badge--<?= $statusClass ?>"><?= $statusLabel ?></span>
        <span class="latency-value" aria-label="Latency: <?= htmlspecialchars($formattedLatency, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"><?= htmlspecialchars($formattedLatency, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
    </div>
<?php if ($checkResult['message'] !== ''): ?>
    <p class="check-card__message" title="<?= $escapedMessage ?>"><?= $escapedMessage ?></p>
<?php endif; ?>
</article>
