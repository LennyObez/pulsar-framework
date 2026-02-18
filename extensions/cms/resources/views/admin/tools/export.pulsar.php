@extends('cms::admin.layout')

@section('title', 'Export Content')

@section('cms-content')
<div class="cms-content-form">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Export Content</h1>
    </header>

    <form method="POST" action="/admin/cms/tools/export/download" class="cms-content-form__form">
        @csrf

        <div class="cms-content-form__main">
            <fieldset class="cms-fieldset">
                <legend class="cms-fieldset__legend">Scope</legend>

                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="content" class="cms-form-group__checkbox" checked>
                        Content
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="taxonomies" class="cms-form-group__checkbox" checked>
                        Taxonomies
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="menus" class="cms-form-group__checkbox" checked>
                        Menus
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="settings" class="cms-form-group__checkbox" checked>
                        Settings
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="media_refs" class="cms-form-group__checkbox">
                        Media References
                    </label>
                </div>
            </fieldset>

            <fieldset class="cms-fieldset">
                <legend class="cms-fieldset__legend">Locale Filter</legend>
                <div class="cms-form-group">
                    <label for="export-locales" class="cms-form-group__label">Locales</label>
                    <select id="export-locales" name="locales[]" class="cms-form-group__select" multiple size="5">
                        @foreach ($locales ?? [] as $loc)
                            <option value="{{ $loc }}">{{ strtoupper($loc) }}</option>
                        @endforeach
                    </select>
                    <span class="cms-form-group__hint">Leave empty to export all locales.</span>
                </div>
            </fieldset>

            <div class="cms-form-group">
                <label class="cms-form-group__label">
                    <input type="checkbox"
                           name="include_pii"
                           value="1"
                           class="cms-form-group__checkbox">
                    Include PII (personally identifiable information)
                </label>
                <div class="cms-alert cms-alert--warning" role="alert">
                    <strong>Warning:</strong> Including PII requires step-up authentication. The exported file will contain sensitive data. Handle according to your data protection policy.
                </div>
            </div>

            <button type="submit" class="cms-btn cms-btn--primary">Generate &amp; Download</button>
        </div>
    </form>
</div>
@endsection
