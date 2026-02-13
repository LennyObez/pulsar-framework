@extends('cms::admin.layout')

@section('title', 'Export Content')

@section('cms-content')
<div class="cms-content-form">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Export Content</h1>
    </header>

    <form method="POST" action="/admin/cms/tools/export/download" class="cms-content-form__form" id="export-form">
        @csrf

        <div class="cms-content-form__main">
            <fieldset class="cms-fieldset">
                <legend class="cms-fieldset__legend">Entity Types</legend>

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
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="media_files" class="cms-form-group__checkbox">
                        Media Files
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="comments" class="cms-form-group__checkbox">
                        Comments
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="users" class="cms-form-group__checkbox">
                        Users
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="checkbox" name="scope[]" value="configuration" class="cms-form-group__checkbox">
                        Configuration
                    </label>
                </div>
            </fieldset>

            <fieldset class="cms-fieldset">
                <legend class="cms-fieldset__legend">Content Filters</legend>

                <div class="cms-form-group">
                    <label for="export-content-type" class="cms-form-group__label">Content Type</label>
                    <select id="export-content-type" name="content_types[]" class="cms-form-group__select">
                        <option value="">All content types</option>
                        <option value="page">Page</option>
                        <option value="article">Article</option>
                        <option value="product">Product</option>
                    </select>
                </div>

                <div class="cms-form-group">
                    <label for="export-date-from" class="cms-form-group__label">Date From</label>
                    <input type="date" id="export-date-from" name="date_from" class="cms-input">
                </div>

                <div class="cms-form-group">
                    <label for="export-date-to" class="cms-form-group__label">Date To</label>
                    <input type="date" id="export-date-to" name="date_to" class="cms-input">
                </div>

                <div class="cms-form-group">
                    <label for="export-status" class="cms-form-group__label">Status</label>
                    <select id="export-status" name="status" class="cms-form-group__select">
                        <option value="">All statuses</option>
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="archived">Archived</option>
                    </select>
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

            <fieldset class="cms-fieldset">
                <legend class="cms-fieldset__legend">Format</legend>

                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="radio" name="export_format" value="json" class="cms-form-group__radio" checked>
                        JSON
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="radio" name="export_format" value="zip" class="cms-form-group__radio" {{ empty($supports_zip) ? 'disabled' : '' }}>
                        ZIP with media
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="radio" name="export_format" value="markdown" class="cms-form-group__radio">
                        Markdown
                    </label>
                </div>
                <div class="cms-form-group">
                    <label class="cms-form-group__label">
                        <input type="radio" name="export_format" value="csv" class="cms-form-group__radio">
                        CSV
                    </label>
                </div>
            </fieldset>

            <div style="display: flex; gap: 0.75rem; align-items: center;">
                <button type="submit" class="cms-btn cms-btn--primary" id="export-submit-btn">Generate &amp; Download</button>
                <button type="button" class="cms-btn cms-btn--outline" id="export-backup-btn">Full Backup (ZIP)</button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('export-form');
    var submitBtn = document.getElementById('export-submit-btn');
    var backupBtn = document.getElementById('export-backup-btn');

    if (form) {
        form.addEventListener('submit', function (e) {
            var format = form.querySelector('input[name="export_format"]:checked');
            if (!format) return;

            var formatValue = format.value;
            if (formatValue === 'json') {
                form.action = '/admin/cms/tools/export/download';
            } else if (formatValue === 'zip') {
                form.action = '/admin/cms/tools/export/zip';
            } else if (formatValue === 'markdown') {
                form.action = '/admin/cms/export/markdown';
            } else if (formatValue === 'csv') {
                form.action = '/admin/cms/export/csv';
            }
        });
    }

    if (backupBtn && form) {
        backupBtn.addEventListener('click', function () {
            // Select all entity type checkboxes
            var checkboxes = form.querySelectorAll('input[name="scope[]"]');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = true;
            }

            // Select ZIP format
            var zipRadio = form.querySelector('input[name="export_format"][value="zip"]');
            if (zipRadio && !zipRadio.disabled) {
                zipRadio.checked = true;
            }

            // Submit
            form.action = '/admin/cms/tools/export/zip';
            form.submit();
        });
    }
})();
</script>
@endsection
