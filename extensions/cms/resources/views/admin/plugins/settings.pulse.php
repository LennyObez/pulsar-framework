@extends('admin.layout')

@section('title', ($plugin['display_name'] ?? 'Plugin') . ' Settings')

@section('content')
<div class="cms-plugin-settings">
    <header class="cms-plugin-settings__header">
        <h1 class="cms-plugin-settings__title">{{ $plugin['display_name'] ?? 'Plugin' }} Settings</h1>
        <a href="/admin/cms/plugins" class="cms-btn cms-btn--outline">Back to Plugins</a>
    </header>

    <form method="POST" action="/admin/cms/plugins/{{ $plugin['id'] }}/settings" class="cms-plugin-settings__form">
        @csrf
        @method('PUT')

        @if (empty($schema))
            <p class="cms-widget__empty">This plugin has no configurable settings.</p>
        @endif

        @foreach ($schema ?? [] as $key => $field)
            <div class="cms-form-group">
                <label for="plugin-setting-{{ $key }}" class="cms-form-group__label">
                    {{ $field['label'] ?? ucfirst(str_replace(['_', '.'], ' ', $key)) }}
                </label>

                @if (isset($field['hint']))
                    <p class="cms-form-group__hint">{{ $field['hint'] }}</p>
                @endif

                <?php $__fieldType = $field['type'] ?? 'text'; ?>
                <?php $__fieldValue = $values[$key] ?? $field['default'] ?? ''; ?>

                @if ($__fieldType === 'boolean')
                    <label class="cms-form-group__label cms-form-group__label--checkbox">
                        <input type="hidden" name="settings[{{ $key }}]" value="0">
                        <input type="checkbox"
                               id="plugin-setting-{{ $key }}"
                               name="settings[{{ $key }}]"
                               value="1"
                               class="cms-form-group__checkbox"
                               @if ($__fieldValue) checked @endif>
                        Enabled
                    </label>
                @elseif ($__fieldType === 'number')
                    <input type="number"
                           id="plugin-setting-{{ $key }}"
                           name="settings[{{ $key }}]"
                           value="{{ $__fieldValue }}"
                           class="cms-form-group__input"
                           @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                           @if (isset($field['max'])) max="{{ $field['max'] }}" @endif
                           step="{{ $field['step'] ?? '1' }}">
                @elseif ($__fieldType === 'select')
                    <select id="plugin-setting-{{ $key }}"
                            name="settings[{{ $key }}]"
                            class="cms-filter-form__select">
                        @foreach ($field['options'] ?? [] as $optVal => $optLabel)
                            <option value="{{ $optVal }}" @if ((string) $__fieldValue === (string) $optVal) selected @endif>{{ $optLabel }}</option>
                        @endforeach
                    </select>
                @elseif ($__fieldType === 'textarea')
                    <textarea id="plugin-setting-{{ $key }}"
                              name="settings[{{ $key }}]"
                              class="cms-form-group__textarea"
                              rows="{{ $field['rows'] ?? 4 }}">{{ $__fieldValue }}</textarea>
                @else
                    <input type="text"
                           id="plugin-setting-{{ $key }}"
                           name="settings[{{ $key }}]"
                           value="{{ $__fieldValue }}"
                           class="cms-form-group__input"
                           @if (isset($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif>
                @endif
            </div>
        @endforeach

        @if (!empty($schema))
            <div class="cms-plugin-settings__actions">
                <button type="submit" class="cms-btn cms-btn--primary">Save Settings</button>
                <a href="/admin/cms/plugins" class="cms-btn cms-btn--outline">Cancel</a>
            </div>
        @endif
    </form>
</div>
@endsection
