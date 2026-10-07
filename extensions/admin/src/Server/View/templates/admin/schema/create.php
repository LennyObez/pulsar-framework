<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var list<string> $tables */
$tables = $templateData['tables'] ?? [];
/** @var array<string, bool> $capabilities */
$capabilities = $templateData['capabilities'] ?? [];
/** @var string $driver */
$driver = $templateData['driver'] ?? 'sqlite';
?>
<div
    data-schema-builder
    data-tables="<?= $e(json_encode($tables, JSON_THROW_ON_ERROR)) ?>"
    data-driver="<?= $e($driver) ?>"
    data-capabilities="<?= $e(json_encode($capabilities, JSON_THROW_ON_ERROR)) ?>"
>
    <noscript>
        <div class="admin-alert admin-alert--warning">
            <?= __('admin.schema.js_required') ?>
        </div>
    </noscript>
</div>
