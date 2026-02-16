<?php

declare(strict_types=1);

use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\ListResourceResult;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var DataResourceInterface $resource */
$resource = $templateData['resource'];
/** @var ListResourceResult $result */
$result = $templateData['result'];
$fields = $resource->fields();
$listFields = array_filter($fields, static fn($f): bool => $f->visibleOnList);
?>
<div class="admin-resource-list">
    <div class="admin-toolbar">
        <div class="admin-toolbar__actions">
            <?php if (in_array(ResourceOperation::Create, $resource->operations(), true)): ?>
            <a href="/admin/resources/<?= $e($resource->name()) ?>/create" class="admin-btn admin-btn--primary" data-t="admin.resource.create"><?= __('admin.resource.create', ['resource' => $resource->label()]) ?></a>
            <?php endif; ?>
            <?php if (in_array(ResourceOperation::Export, $resource->operations(), true)): ?>
            <a href="/admin/resources/<?= $e($resource->name()) ?>/export?format=csv" class="admin-btn admin-btn--secondary" data-t="admin.resource.export_csv"><?= __('admin.resource.export_csv') ?></a>
            <?php endif; ?>
        </div>
        <div class="admin-toolbar__info">
            <span data-t="admin.resource.total_records"><?= __('admin.resource.total_records', ['count' => $result->total]) ?></span>
        </div>
    </div>

    <table class="admin-table">
        <thead>
            <tr>
                <?php foreach ($listFields as $field): ?>
                <th<?= $field->sortable ? ' class="sortable"' : '' ?>><?= $e($field->label) ?></th>
                <?php endforeach; ?>
                <th data-t="admin.resource.actions"><?= __('admin.resource.actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($result->data as $row): ?>
            <tr>
                <?php foreach ($listFields as $field): ?>
                <?php $cellVal = $row[$field->name] ?? ''; ?><td><?= $e(is_scalar($cellVal) ? (string) $cellVal : '') ?></td>
                <?php endforeach; ?>
                <?php $pkVal = $row[$resource->primaryKey()] ?? '';
                $pkStr = is_scalar($pkVal) ? (string) $pkVal : ''; ?>
                <td class="admin-table__actions">
                    <a href="/admin/resources/<?= $e($resource->name()) ?>/<?= $e($pkStr) ?>" data-t="admin.resource.view"><?= __('admin.resource.view') ?></a>
                    <?php if (in_array(ResourceOperation::Update, $resource->operations(), true)): ?>
                    <a href="/admin/resources/<?= $e($resource->name()) ?>/<?= $e($pkStr) ?>/edit" data-t="admin.resource.edit"><?= __('admin.resource.edit') ?></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    extract([
        'page' => $result->page,
        'totalPages' => $result->totalPages,
        'baseUrl' => '/admin/resources/' . $resource->name(),
    ]);
include __DIR__ . '/partials/pagination.php';
?>
</div>
