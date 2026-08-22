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
            <legend class="cms-fieldset__legend">Upload File</legend>

            <div class="cms-form-group">
                <label for="import-file" class="cms-form-group__label">Import File</label>
                <div class="cms-upload-zone" data-cms-drop-zone>
                    <input type="file"
                           id="import-file"
                           accept="application/json,.json,application/zip,.zip"
                           class="cms-upload-zone__input"
                           data-cms-import-file>
                    <p class="cms-upload-zone__text">Drag and drop a JSON or ZIP file here, or click to browse.</p>
                </div>
                <span class="cms-form-group__hint">Accepted formats: .json (CMS export), .zip (bundle with media)</span>
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

        {{-- Step 1: Analyze --}}
        <form method="POST" action="/admin/cms/tools/import/analyze" data-cms-import-analyze-form>
            @csrf
            <input type="hidden" name="import_file" data-cms-import-analyze-payload>
            <button type="submit" class="cms-btn cms-btn--outline">Analyze Import</button>
        </form>

        {{-- Dry Run (legacy) --}}
        <form method="POST" action="/admin/cms/tools/import/dry-run" data-cms-import-dryrun-form>
            @csrf
            <input type="hidden" name="json_content" data-cms-import-payload>
            <button type="submit" class="cms-btn cms-btn--outline" style="margin-top: 0.5rem;">Dry Run (JSON only)</button>
        </form>

        {{-- Analysis Results --}}
        <section class="cms-card" data-cms-import-analysis hidden>
            <h2 class="cms-card__title">Import Analysis</h2>
            <table class="cms-table">
                <thead class="cms-table__head">
                    <tr>
                        <th class="cms-table__th" scope="col">Entity Type</th>
                        <th class="cms-table__th" scope="col">Count</th>
                        <th class="cms-table__th" scope="col">Duplicates</th>
                    </tr>
                </thead>
                <tbody class="cms-table__body" data-cms-import-analysis-body>
                </tbody>
            </table>

            <div class="cms-form-group" style="margin-top: 1rem;" data-cms-import-missing-deps hidden>
                <h3 style="font-size: 0.875rem; font-weight: 600; color: var(--cms-amber);">Missing Dependencies</h3>
                <ul data-cms-import-missing-list style="font-size: 0.875rem; color: var(--cms-muted-color);"></ul>
            </div>
        </section>

        {{-- Dry Run Results (legacy) --}}
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

        {{-- Step 2: Configure and Execute --}}
        <form method="POST" action="/admin/cms/tools/import/execute-with-options" data-cms-import-execute-form hidden>
            @csrf
            <input type="hidden" name="import_file" data-cms-import-execute-payload>

            <fieldset class="cms-fieldset" style="margin-bottom: 1rem;">
                <legend class="cms-fieldset__legend">Duplicate Resolution</legend>
                <div class="cms-form-group">
                    <select name="duplicate_policy" class="cms-form-group__select">
                        <option value="skip">Skip duplicates</option>
                        <option value="replace">Replace existing</option>
                        <option value="import_as_new">Import as new</option>
                        <option value="merge">Merge into existing</option>
                    </select>
                    <span class="cms-form-group__hint">
                        <strong>Skip:</strong> Ignore entities that already exist.
                        <strong>Replace:</strong> Overwrite existing with imported data.
                        <strong>Import as New:</strong> Create copies with new IDs.
                        <strong>Merge:</strong> Update only non-empty fields.
                    </span>
                </div>
            </fieldset>

            <button type="submit" class="cms-btn cms-btn--primary" data-cms-step-up>Execute Import</button>
        </form>

        {{-- Legacy Execute Import --}}
        <form method="POST" action="/admin/cms/tools/import/execute" data-cms-import-legacy-execute-form hidden>
            @csrf
            <input type="hidden" name="json_content" data-cms-import-legacy-execute-payload>
            <button type="submit" class="cms-btn cms-btn--primary" data-cms-step-up>Execute Import (JSON)</button>
        </form>

        {{-- Import Result Summary --}}
        <section class="cms-card" data-cms-import-report hidden>
            <h2 class="cms-card__title">Import Results</h2>
            <div class="cms-stats-grid" style="margin-bottom: 1rem;">
                <div class="cms-stats-grid__item">
                    <span class="cms-stats-grid__value" data-cms-report-created>0</span>
                    <span class="cms-stats-grid__label">Created</span>
                </div>
                <div class="cms-stats-grid__item">
                    <span class="cms-stats-grid__value" data-cms-report-updated>0</span>
                    <span class="cms-stats-grid__label">Updated</span>
                </div>
                <div class="cms-stats-grid__item">
                    <span class="cms-stats-grid__value" data-cms-report-skipped>0</span>
                    <span class="cms-stats-grid__label">Skipped</span>
                </div>
                <div class="cms-stats-grid__item">
                    <span class="cms-stats-grid__value" data-cms-report-failed>0</span>
                    <span class="cms-stats-grid__label">Failed</span>
                </div>
            </div>
            <div data-cms-report-errors hidden>
                <h3 style="font-size: 0.875rem; font-weight: 600; color: var(--cms-red);">Errors</h3>
                <ul data-cms-report-error-list style="font-size: 0.875rem;"></ul>
            </div>
        </section>

        {{-- Progress Indicator --}}
        <div class="cms-card" data-cms-import-progress hidden>
            <div class="cms-progress-bar" style="width: 100%;">
                <div class="cms-progress-bar__fill cms-progress-bar__fill--good" style="width: 0%;" data-cms-progress-fill></div>
                <span class="cms-progress-bar__label" data-cms-progress-label>Importing...</span>
            </div>
        </div>
    </div>
</div>

@include('cms::admin._partials.step-up-prompt')

<script>
(function () {
    var fileInput = document.querySelector('[data-cms-import-file]');
    var jsonTextarea = document.querySelector('[data-cms-import-json]');
    var analyzeForm = document.querySelector('[data-cms-import-analyze-form]');
    var analyzePayload = document.querySelector('[data-cms-import-analyze-payload]');
    var dryRunForm = document.querySelector('[data-cms-import-dryrun-form]');
    var dryRunPayload = document.querySelector('[data-cms-import-payload]');
    var analysisSection = document.querySelector('[data-cms-import-analysis]');
    var analysisBody = document.querySelector('[data-cms-import-analysis-body]');
    var resultsSection = document.querySelector('[data-cms-import-results]');
    var resultsBody = document.querySelector('[data-cms-import-results-body]');
    var executeForm = document.querySelector('[data-cms-import-execute-form]');
    var executePayload = document.querySelector('[data-cms-import-execute-payload]');
    var legacyExecuteForm = document.querySelector('[data-cms-import-legacy-execute-form]');
    var legacyExecutePayload = document.querySelector('[data-cms-import-legacy-execute-payload]');
    var dropZone = document.querySelector('[data-cms-drop-zone]');
    var missingDeps = document.querySelector('[data-cms-import-missing-deps]');
    var missingList = document.querySelector('[data-cms-import-missing-list]');
    var importedFileContent = null;

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = this.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                importedFileContent = e.target.result;
                if (jsonTextarea && file.type === 'application/json') {
                    jsonTextarea.value = e.target.result;
                }
            };
            if (file.name.endsWith('.zip') || file.type === 'application/zip') {
                reader.readAsArrayBuffer(file);
            } else {
                reader.readAsText(file);
            }
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
            if (fileInput) {
                fileInput.files = e.dataTransfer.files;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                importedFileContent = ev.target.result;
                if (jsonTextarea && !file.name.endsWith('.zip')) {
                    jsonTextarea.value = ev.target.result;
                }
            };
            if (file.name.endsWith('.zip') || file.type === 'application/zip') {
                reader.readAsArrayBuffer(file);
            } else {
                reader.readAsText(file);
            }
        });
    }

    if (analyzeForm) {
        analyzeForm.addEventListener('submit', function (e) {
            if (analyzePayload && jsonTextarea) {
                analyzePayload.value = jsonTextarea.value;
            }
        });
    }

    if (dryRunForm) {
        dryRunForm.addEventListener('submit', function (e) {
            if (dryRunPayload && jsonTextarea) {
                dryRunPayload.value = jsonTextarea.value;
            }
        });
    }

    function createTd(text) {
        var td = document.createElement('td');
        td.className = 'cms-table__td';
        td.textContent = text;
        return td;
    }

    // Populate results after dry run (server-side rendered via redirect or AJAX)
    if (typeof window.__importDryRunResult !== 'undefined' && resultsBody) {
        var result = window.__importDryRunResult;
        while (resultsBody.firstChild) { resultsBody.removeChild(resultsBody.firstChild); }
        for (var type in result.counts || {}) {
            var c = result.counts[type];
            var row = document.createElement('tr');
            row.className = 'cms-table__row';
            row.appendChild(createTd(type));
            row.appendChild(createTd(String(c.create || 0)));
            row.appendChild(createTd(String(c.update || 0)));
            row.appendChild(createTd(String(c.skip || 0)));
            row.appendChild(createTd(String((c.warnings || []).length)));
            resultsBody.appendChild(row);
        }
        resultsSection.hidden = false;
        if (legacyExecuteForm) {
            legacyExecuteForm.hidden = false;
            if (legacyExecutePayload && jsonTextarea) {
                legacyExecutePayload.value = jsonTextarea.value;
            }
        }
    }

    // Populate analysis results
    if (typeof window.__importAnalysisResult !== 'undefined' && analysisBody) {
        var analysis = window.__importAnalysisResult;
        while (analysisBody.firstChild) { analysisBody.removeChild(analysisBody.firstChild); }
        var counts = analysis.entity_counts || {};
        var duplicates = analysis.duplicates_by_type || {};
        for (var type in counts) {
            var row = document.createElement('tr');
            row.className = 'cms-table__row';
            row.appendChild(createTd(type));
            row.appendChild(createTd(String(counts[type])));
            row.appendChild(createTd(String(duplicates[type] || 0)));
            analysisBody.appendChild(row);
        }
        analysisSection.hidden = false;

        var missing = analysis.missing_dependencies || [];
        if (missing.length > 0 && missingDeps && missingList) {
            while (missingList.firstChild) { missingList.removeChild(missingList.firstChild); }
            for (var i = 0; i < missing.length; i++) {
                var li = document.createElement('li');
                li.textContent = missing[i];
                missingList.appendChild(li);
            }
            missingDeps.hidden = false;
        }

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
