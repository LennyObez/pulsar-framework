@extends('cms::admin.layout')

@section('title', 'Import Content')

@section('cms-content')
<div class="cms-content-form" data-cms-import-tool>
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Import Content</h1>
    </header>

    <div class="cms-content-form__main">
        {{-- File Upload --}}
        <fieldset class="cms-fieldset">
            <legend class="cms-fieldset__legend">Upload JSON</legend>

            <div class="cms-form-group">
                <label for="import-file" class="cms-form-group__label">Import File</label>
                <div class="cms-upload-zone" data-cms-drop-zone>
                    <input type="file"
                           id="import-file"
                           accept="application/json,.json"
                           class="cms-upload-zone__input"
                           data-cms-import-file>
                    <p class="cms-upload-zone__text">Drag and drop a JSON file here, or click to browse.</p>
                </div>
            </div>

            <div class="cms-form-group">
                <label for="import-json" class="cms-form-group__label">Or paste JSON content</label>
                <textarea id="import-json"
                          class="cms-form-group__textarea cms-form-group__textarea--code"
                          rows="10"
                          style="font-family: monospace;"
                          placeholder='{"content": [...], "taxonomies": [...]}'
                          data-cms-import-json></textarea>
            </div>
        </fieldset>

        {{-- Dry Run --}}
        <form method="POST" action="/admin/cms/tools/import/dry-run" data-cms-import-dryrun-form>
            @csrf
            <input type="hidden" name="json_content" data-cms-import-payload>
            <button type="submit" class="cms-btn cms-btn--outline">Dry Run</button>
        </form>

        {{-- Dry Run Results --}}
        <section class="cms-card" data-cms-import-results hidden>
            <h2 class="cms-card__title">Dry Run Results</h2>
            <table class="cms-table">
                <thead class="cms-table__head">
                    <tr>
                        <th class="cms-table__th" scope="col">Entity Type</th>
                        <th class="cms-table__th" scope="col">Would Create</th>
                        <th class="cms-table__th" scope="col">Would Update</th>
                        <th class="cms-table__th" scope="col">Would Skip</th>
                        <th class="cms-table__th" scope="col">Warnings</th>
                    </tr>
                </thead>
                <tbody class="cms-table__body" data-cms-import-results-body>
                </tbody>
            </table>
        </section>

        {{-- Execute Import --}}
        <form method="POST" action="/admin/cms/tools/import/execute" data-cms-import-execute-form hidden>
            @csrf
            <input type="hidden" name="json_content" data-cms-import-execute-payload>
            <button type="submit" class="cms-btn cms-btn--primary" data-cms-step-up>Execute Import</button>
        </form>
    </div>
</div>

@include('cms::admin._partials.step-up-prompt')

<script>
(function () {
    var fileInput = document.querySelector('[data-cms-import-file]');
    var jsonTextarea = document.querySelector('[data-cms-import-json]');
    var dryRunForm = document.querySelector('[data-cms-import-dryrun-form]');
    var dryRunPayload = document.querySelector('[data-cms-import-payload]');
    var resultsSection = document.querySelector('[data-cms-import-results]');
    var resultsBody = document.querySelector('[data-cms-import-results-body]');
    var executeForm = document.querySelector('[data-cms-import-execute-form]');
    var executePayload = document.querySelector('[data-cms-import-execute-payload]');
    var dropZone = document.querySelector('[data-cms-drop-zone]');

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = this.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                if (jsonTextarea) jsonTextarea.value = e.target.result;
            };
            reader.readAsText(file);
        });
    }

    if (dropZone) {
        dropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            this.classList.add('cms-upload-zone--active');
        });
        dropZone.addEventListener('dragleave', function () {
            this.classList.remove('cms-upload-zone--active');
        });
        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            this.classList.remove('cms-upload-zone--active');
            var file = e.dataTransfer.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (ev) {
                if (jsonTextarea) jsonTextarea.value = ev.target.result;
            };
            reader.readAsText(file);
        });
    }

    if (dryRunForm) {
        dryRunForm.addEventListener('submit', function (e) {
            if (dryRunPayload && jsonTextarea) {
                dryRunPayload.value = jsonTextarea.value;
            }
        });
    }

    // Populate results after dry run (server-side rendered via redirect or AJAX)
    if (typeof window.__importDryRunResult !== 'undefined' && resultsBody) {
        var result = window.__importDryRunResult;
        resultsBody.innerHTML = '';
        for (var type in result.counts || {}) {
            var c = result.counts[type];
            var row = document.createElement('tr');
            row.className = 'cms-table__row';
            row.innerHTML = '<td class="cms-table__td">' + type + '</td>' +
                '<td class="cms-table__td">' + (c.create || 0) + '</td>' +
                '<td class="cms-table__td">' + (c.update || 0) + '</td>' +
                '<td class="cms-table__td">' + (c.skip || 0) + '</td>' +
                '<td class="cms-table__td">' + ((c.warnings || []).length) + '</td>';
            resultsBody.appendChild(row);
        }
        resultsSection.hidden = false;
        if (executeForm) {
            executeForm.hidden = false;
            if (executePayload && jsonTextarea) {
                executePayload.value = jsonTextarea.value;
            }
        }
    }
})();
</script>
@endsection
