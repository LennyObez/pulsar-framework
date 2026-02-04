<?php
/**
 * @var list<array{label?: string, value?: string, subtitle?: string}>|null $metrics
 */
/** @var list<array{label?: string, value?: string, subtitle?: string}> $typedMetrics */
$typedMetrics = $metrics ?? [];
?>
<div class="metrics-row">
    <?php foreach ($typedMetrics as $metric): ?>
        <div class="metric-card">
            <div class="metric-label"><?= htmlspecialchars($metric['label'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="metric-value"><?= htmlspecialchars($metric['value'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <?php
            $subtitle = $metric['subtitle'] ?? '';
        if ($subtitle !== ''): ?>
                <div class="metric-subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
