<?php

declare(strict_types=1);

use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldType;

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val);
/** @var DataResourceInterface $resource */
$resource = $templateData['resource'];
/** @var array<string, mixed> $data */
$data = $templateData['data'];
/** @var string $mode */
$mode = $templateData['mode'];
/** @var string $id */
$id = $templateData['id'] ?? '';
$formFields = array_filter($resource->fields(), static fn($f): bool => $f->visibleOnForm && $f->editable);
$actionUrl = $mode === 'create'
    ? "/admin/resources/{$resource->name()}"
    : "/admin/resources/{$resource->name()}/$id";
$method = $mode === 'create' ? 'POST' : 'PUT';
?>
<div class="admin-resource-form">
    <div class="admin-toolbar">
        <a href="/admin/resources/<?= $e($resource->name()) ?>" class="admin-btn admin-btn--secondary" data-t="admin.resource.cancel"><?= __('admin.resource.cancel') ?></a>
    </div>

    <form class="admin-form" data-action="<?= $e($actionUrl) ?>" data-method="<?= $e($method) ?>">
        <?php foreach ($formFields as $field): ?>
        <div class="admin-form__group">
            <label for="field-<?= $e($field->name) ?>" class="admin-form__label">
                <?= $e($field->label) ?>
                <?php if ($field->helpText !== null): ?>
                <small class="admin-form__help"><?= $e($field->helpText) ?></small>
                <?php endif; ?>
            </label>
            <?php if ($field->type === FieldType::Text): ?>
            <textarea
                id="field-<?= $e($field->name) ?>"
                name="<?= $e($field->name) ?>"
                class="admin-form__textarea"
                placeholder="<?= $e($field->placeholder ?? '') ?>"
            ><?php /** @var mixed $fieldVal */ $fieldVal = $data[$field->name] ?? '';
                echo $e(is_scalar($fieldVal) ? (string) $fieldVal : ''); ?></textarea>
            <?php elseif ($field->type === FieldType::Boolean): ?>
            <input
                type="checkbox"
                id="field-<?= $e($field->name) ?>"
                name="<?= $e($field->name) ?>"
                class="admin-form__checkbox"
                value="1"
                <?= !empty($data[$field->name]) ? 'checked' : '' ?>
            >
            <?php elseif ($field->type === FieldType::Enum && $field->enumValues !== []): ?>
            <select
                id="field-<?= $e($field->name) ?>"
                name="<?= $e($field->name) ?>"
                class="admin-form__select"
            >
                <option value="" data-t="admin.resource.select"><?= __('admin.resource.select') ?></option>
                <?php foreach ($field->enumValues as $enumVal): ?>
                <option value="<?= $e($enumVal) ?>" <?= ($data[$field->name] ?? '') === $enumVal ? 'selected' : '' ?>><?= $e($enumVal) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input
                type="<?= $e(inputType($field->type)) ?>"
                id="field-<?= $e($field->name) ?>"
                name="<?= $e($field->name) ?>"
                class="admin-form__input"
                value="<?php /** @var mixed $inputVal */ $inputVal = $data[$field->name] ?? ''; ?><?= $e(is_scalar($inputVal) ? (string) $inputVal : '') ?>"
                placeholder="<?= $e($field->placeholder ?? '') ?>"
            >
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="admin-form__actions">
            <button type="submit" class="admin-btn admin-btn--primary">
                <?= $mode === 'create' ? __('admin.resource.create', ['resource' => $resource->label()]) : __('admin.resource.save_changes') ?>
            </button>
        </div>
    </form>
</div>
<?php
// Helper function for input type mapping
function inputType(FieldType $type): string
{
    return match ($type) {
        FieldType::Integer, FieldType::Float => 'number',
        FieldType::Email => 'email',
        FieldType::Url => 'url',
        FieldType::Date => 'date',
        FieldType::DateTime => 'datetime-local',
        default => 'text',
    };
}
?>
