<?php

declare(strict_types=1);

use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var DataResourceInterface $resource */
$resource = $templateData['resource'];
/** @var array<string, mixed> $data */
$data = $templateData['data'];
/** @var string $id */
$id = $templateData['id'];
$detailFields = array_filter($resource->fields(), static fn($f): bool => $f->visibleOnDetail);
?>
<div class="admin-resource-view">
    <div class="admin-toolbar">
        <div class="admin-toolbar__actions">
            <a href="/admin/resources/<?= $e($resource->name()) ?>" class="admin-btn admin-btn--secondary" data-t="admin.resource.back_to_list"><?= __('admin.resource.back_to_list') ?></a>
            <?php if (in_array(ResourceOperation::Update, $resource->operations(), true)): ?>
            <a href="/admin/resources/<?= $e($resource->name()) ?>/<?= $e($id) ?>/edit" class="admin-btn admin-btn--primary" data-t="admin.resource.edit"><?= __('admin.resource.edit') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="admin-detail">
        <dl class="admin-detail__fields">
            <?php foreach ($detailFields as $field): ?>
            <div class="admin-detail__field">
                <dt><?= $e($field->label) ?></dt>
                <dd>
                    <?php if ($field->redacted): ?>
                    <span class="admin-redacted" title="<?= __('admin.resource.redacted') ?>" data-t="admin.resource.redacted"><?= $e(str_repeat("\u{2022}", 6)) ?></span>
                    <?php else: ?>
                    <?php /** @var mixed $detailVal */ $detailVal = $data[$field->name] ?? ''; ?><?= $e(is_scalar($detailVal) ? (string) $detailVal : '') ?>
                    <?php endif; ?>
                </dd>
            </div>
            <?php endforeach; ?>
        </dl>
    </div>
</div>
