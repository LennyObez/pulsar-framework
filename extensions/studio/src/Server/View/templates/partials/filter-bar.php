<?php
/**
 * @var list<string>|null $eventTypes
 * @var string|null $currentType
 * @var string|null $currentWindow
 */
/** @var list<string> $typedEventTypes */
$typedEventTypes = $eventTypes ?? [];
/** @var string $typedCurrentType */
$typedCurrentType = $currentType ?? '';
/** @var string $typedCurrentWindow */
$typedCurrentWindow = $currentWindow ?? '1h';
?>
<div class="filter-bar">
    <div class="filter-group">
        <label for="type-filter" data-t="studio.event_type"><?= __('studio.event_type') ?></label>
        <select id="type-filter" class="filter-select">
            <option value="" data-t="studio.all_types"><?= __('studio.all_types') ?></option>
            <?php foreach ($typedEventTypes as $type): ?>
                <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $typedCurrentType === $type ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label for="window-filter" data-t="studio.time_window"><?= __('studio.time_window') ?></label>
        <select id="window-filter" class="filter-select">
            <option value="5m" <?= $typedCurrentWindow === '5m' ? 'selected' : '' ?> data-t="studio.last_5_min"><?= __('studio.last_5_min') ?></option>
            <option value="1h" <?= $typedCurrentWindow === '1h' ? 'selected' : '' ?> data-t="studio.last_hour"><?= __('studio.last_hour') ?></option>
            <option value="24h" <?= $typedCurrentWindow === '24h' ? 'selected' : '' ?> data-t="studio.last_24h"><?= __('studio.last_24h') ?></option>
        </select>
    </div>
</div>
