@extends('admin.layout')

@section('title', 'CMS Settings')

@section('content')
<div class="cms-settings">
    <header class="cms-settings__header">
        <h1 class="cms-settings__title">CMS Settings</h1>
    </header>

    {{-- Settings group tabs --}}
    <nav class="cms-settings__tabs" aria-label="Settings groups">
        <ul class="cms-settings__tab-list" role="tablist">
            @foreach ($groups ?? ['general', 'cache', 'content'] as $grp)
                <li role="presentation">
                    <a href="/admin/cms/settings/{{ $grp }}"
                       class="cms-settings__tab @if (($group ?? '') === $grp) cms-settings__tab--active @endif"
                       role="tab"
                       aria-selected="{{ ($group ?? '') === $grp ? 'true' : 'false' }}">
                        {{ ucfirst($grp) }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    @if (isset($locales) && count($locales) > 1)
        @include('cms::admin._partials.locale-tabs', [
            'locales' => $locales,
            'activeLocale' => $activeLocale ?? 'en',
            'baseUrl' => '/admin/cms/settings/' . ($group ?? 'general'),
        ])
    @endif

    <form method="POST" action="/admin/cms/settings/{{ $group ?? 'general' }}" class="cms-settings__form">
        @csrf
        @method('PUT')

        <input type="hidden" name="locale" value="{{ $activeLocale ?? '' }}">

        @if (empty($settings))
            <p class="cms-widget__empty">No settings found for this group.</p>
        @endif

        @foreach ($settings ?? [] as $key => $value)
            <div class="cms-form-group">
                <label for="setting-{{ $key }}" class="cms-form-group__label">
                    {{ ucfirst(str_replace(['_', '.'], ' ', $key)) }}
                </label>
                @if (is_bool($value))
                    <label class="cms-form-group__label cms-form-group__label--checkbox">
                        <input type="hidden" name="settings[{{ $key }}]" value="0">
                        <input type="checkbox"
                               id="setting-{{ $key }}"
                               name="settings[{{ $key }}]"
                               value="1"
                               class="cms-form-group__checkbox"
                               @if ($value) checked @endif>
                        Enabled
                    </label>
                @elseif (is_int($value) || is_float($value))
                    <input type="number"
                           id="setting-{{ $key }}"
                           name="settings[{{ $key }}]"
                           value="{{ $value }}"
                           class="cms-form-group__input"
                           step="{{ is_float($value) ? 'any' : '1' }}">
                @elseif (is_array($value))
                    <textarea id="setting-{{ $key }}"
                              name="settings[{{ $key }}]"
                              class="cms-form-group__textarea"
                              rows="4">{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</textarea>
                    <span class="cms-form-group__hint">JSON format</span>
                @else
                    <?php $__strVal = (string) $value; ?>
                    @if (strlen($__strVal) > 200)
                        <textarea id="setting-{{ $key }}"
                                  name="settings[{{ $key }}]"
                                  class="cms-form-group__textarea"
                                  rows="3">{{ $__strVal }}</textarea>
                    @else
                        <input type="text"
                               id="setting-{{ $key }}"
                               name="settings[{{ $key }}]"
                               value="{{ $__strVal }}"
                               class="cms-form-group__input">
                    @endif
                @endif
            </div>
        @endforeach

        <div class="cms-form-group">
            <label for="settings-reason" class="cms-form-group__label">Change Reason</label>
            <input type="text"
                   id="settings-reason"
                   name="reason"
                   class="cms-form-group__input"
                   placeholder="Optional reason for this change (for audit log)">
        </div>

        @can('cms.settings.manage')
            <button type="submit" class="cms-btn cms-btn--primary">Save Settings</button>
        @endcan
    </form>
</div>
@endsection
