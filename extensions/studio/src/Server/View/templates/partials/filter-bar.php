<?php
/**
 * @var list<string>|null $eventTypes
 * @var string|null $currentType
 * @var string|null $currentWindow
 */
/** @var list<string> $typedEventTypes */
$typedEventTypes = $eventTypes ?? [];
$typedCurrentType = $currentType ?? '';
$typedCurrentWindow = $currentWindow ?? '1h';
?>
<div class="filter-bar">
    <div class="filter-group">
        <label for="type-filter">Event Type</label>
        <select id="type-filter" class="filter-select">
            <option value="">All Types</option>
            <?php foreach ($typedEventTypes as $type): ?>
                <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $typedCurrentType === $type ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label for="window-filter">Time Window</label>
        <select id="window-filter" class="filter-select">
            <option value="5m" <?= $typedCurrentWindow === '5m' ? 'selected' : '' ?>>Last 5 min</option>
            <option value="1h" <?= $typedCurrentWindow === '1h' ? 'selected' : '' ?>>Last hour</option>
            <option value="24h" <?= $typedCurrentWindow === '24h' ? 'selected' : '' ?>>Last 24h</option>
        </select>
    </div>
</div>
