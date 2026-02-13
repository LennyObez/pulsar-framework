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
            <a href="/admin/resources/<?= $e($resource->name()) ?>/create" class="admin-btn admin-btn--primary">Create <?= $e($resource->label()) ?></a>
            <?php endif; ?>
            <?php if (in_array(ResourceOperation::Export, $resource->operations(), true)): ?>
            <a href="/admin/resources/<?= $e($resource->name()) ?>/export?format=csv" class="admin-btn admin-btn--secondary">Export CSV</a>
            <?php endif; ?>
        </div>
        <div class="admin-toolbar__info">
            <span><?= $e((string) $result->total) ?> total records</span>
        </div>
    </div>

    <table class="admin-table">
        <thead>
            <tr>
                <?php foreach ($listFields as $field): ?>
                <th<?= $field->sortable ? ' class="sortable"' : '' ?>><?= $e($field->label) ?></th>
                <?php endforeach; ?>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($result->data as $row): ?>
            <tr>
                <?php foreach ($listFields as $field): ?>
                <td><?= $e((string) ($row[$field->name] ?? '')) ?></td>
                <?php endforeach; ?>
                <td class="admin-table__actions">
                    <a href="/admin/resources/<?= $e($resource->name()) ?>/<?= $e((string) ($row[$resource->primaryKey()] ?? '')) ?>">View</a>
                    <?php if (in_array(ResourceOperation::Update, $resource->operations(), true)): ?>
                    <a href="/admin/resources/<?= $e($resource->name()) ?>/<?= $e((string) ($row[$resource->primaryKey()] ?? '')) ?>/edit">Edit</a>
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
