<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $templateData
 */
$e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
/** @var \Pulsar\Extension\Admin\Contracts\DataResourceInterface $resource */
$resource = $templateData['resource'];
/** @var array<string, mixed> $data */
$data = $templateData['data'];
/** @var string $mode */
$mode = $templateData['mode'];
$id = $templateData['id'] ?? '';
$formFields = array_filter($resource->fields(), static fn($f): bool => $f->visibleOnForm && $f->editable);
$actionUrl = $mode === 'create'
    ? "/admin/resources/{$resource->name()}"
    : "/admin/resources/{$resource->name()}/$id";
$method = $mode === 'create' ? 'POST' : 'PUT';
?>
<div class="admin-resource-form">
    <div class="admin-toolbar">
        <a href="/admin/resources/<?= $e($resource->name()) ?>" class="admin-btn admin-btn--secondary">Cancel</a>
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
            <?php if ($field->type === \Pulsar\Extension\Admin\Domain\FieldType::Text): ?>
            <textarea
                id="field-<?= $e($field->name) ?>"
                name="<?= $e($field->name) ?>"
                class="admin-form__textarea"
                placeholder="<?= $e($field->placeholder ?? '') ?>"
            ><?= $e((string) ($data[$field->name] ?? '')) ?></textarea>
            <?php elseif ($field->type === \Pulsar\Extension\Admin\Domain\FieldType::Boolean): ?>
            <input
                type="checkbox"
                id="field-<?= $e($field->name) ?>"
                name="<?= $e($field->name) ?>"
                class="admin-form__checkbox"
                value="1"
                <?= !empty($data[$field->name]) ? 'checked' : '' ?>
            >
            <?php elseif ($field->type === \Pulsar\Extension\Admin\Domain\FieldType::Enum && $field->enumValues !== []): ?>
            <select
                id="field-<?= $e($field->name) ?>"
                name="<?= $e($field->name) ?>"
                class="admin-form__select"
            >
                <option value="">Select...</option>
                <?php foreach ($field->enumValues as $enumVal): ?>
                <option value="<?= $e($enumVal) ?>" <?= ($data[$field->name] ?? '') === $enumVal ? 'selected' : '' ?>><?= $e($enumVal) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input
                type="<?= $e($this->inputType($field->type)) ?>"
                id="field-<?= $e($field->name) ?>"
                name="<?= $e($field->name) ?>"
                class="admin-form__input"
                value="<?= $e((string) ($data[$field->name] ?? '')) ?>"
                placeholder="<?= $e($field->placeholder ?? '') ?>"
            >
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="admin-form__actions">
            <button type="submit" class="admin-btn admin-btn--primary">
                <?= $e($mode === 'create' ? 'Create' : 'Save Changes') ?>
            </button>
        </div>
    </form>
</div>
<?php
// Helper function for input type mapping
function inputType(\Pulsar\Extension\Admin\Domain\FieldType $type): string
{
    return match ($type) {
        \Pulsar\Extension\Admin\Domain\FieldType::Integer, \Pulsar\Extension\Admin\Domain\FieldType::Float => 'number',
        \Pulsar\Extension\Admin\Domain\FieldType::Email => 'email',
        \Pulsar\Extension\Admin\Domain\FieldType::Url => 'url',
        \Pulsar\Extension\Admin\Domain\FieldType::Date => 'date',
        \Pulsar\Extension\Admin\Domain\FieldType::DateTime => 'datetime-local',
        default => 'text',
    };
}
?>
