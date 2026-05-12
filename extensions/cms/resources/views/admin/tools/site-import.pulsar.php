@extends('cms::admin.layout')

@section('title', 'AI Site Definition Import')

@section('cms-content')
<div class="cms-content-form" data-cms-site-import-tool>
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">AI Site Definition Import</h1>
    </header>

    <div class="cms-content-form__main">
        {{-- Format Reference --}}
        <details class="cms-fieldset cms-fieldset--collapsible">
            <summary class="cms-fieldset__legend">Format Reference (N.3 Schema)</summary>
            <div class="cms-fieldset__body">
                <pre class="cms-code-block"><code>{
  "schema": "N.3",
  "site": {
    "name": "Site Name",
    "default_locale": "en"
  },
  "taxonomies": [
    {
      "slug": "category",
      "name": "Categories",
      "hierarchical": true,
      "terms": [
        { "slug": "news", "name": { "en": "News" } }
      ]
    }
  ],
  "media": [
    { "ref": "hero-img", "url": "https://...", "alt": { "en": "Hero image" } }
  ],
  "content": [
    {
      "type": "page",
      "slug": "about",
      "translations": {
        "en": { "title": "About Us", "body": "..." }
      },
      "taxonomy_terms": ["news"],
      "template": "default"
    }
  ],
  "menus": [
    {
      "slug": "main",
      "items": [
        { "label": { "en": "Home" }, "url": "/" }
      ]
    }
  ],
  "settings": { "site_name": "My Site" },
  "redirects": [
    { "from": "/old", "to": "/new", "status": 301 }
  ],
  "seo": {
    "robots_txt": "User-agent: *\nAllow: /",
    "default_meta": { "title_suffix": " | My Site" }
  }
}</code></pre>
            </div>
        </details>

        {{-- File Upload --}}
        <fieldset class="cms-fieldset">
            <legend class="cms-fieldset__legend">Upload Site Definition</legend>

            <div class="cms-form-group">
                <label for="site-import-file" class="cms-form-group__label">Definition File</label>
                <div class="cms-upload-zone" data-cms-drop-zone>
                    <input type="file"
                           id="site-import-file"
                           accept="application/json,.json"
                           class="cms-upload-zone__input"
                           data-cms-site-import-file>
                    <p class="cms-upload-zone__text">Drag and drop a JSON file here, or click to browse.</p>
                </div>
            </div>

            <div class="cms-form-group">
                <label for="site-import-json" class="cms-form-group__label">Or paste JSON content</label>
                <textarea id="site-import-json"
                          class="cms-form-group__textarea cms-form-group__textarea--code"
                          rows="10"
                          style="font-family: monospace;"
                          placeholder='{"schema": "N.3", "site": {...}}'
                          data-cms-site-import-json></textarea>
            </div>
        </fieldset>

        {{-- Dry Run --}}
        <form method="POST" action="/admin/cms/tools/site-import/dry-run" data-cms-site-import-dryrun-form>
            @csrf
            <input type="hidden" name="json_content" data-cms-site-import-payload>
            <button type="submit" class="cms-btn cms-btn--outline">Dry Run</button>
        </form>

        {{-- Dry Run Results --}}
        <section class="cms-card" data-cms-site-import-results hidden>
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
                <tbody class="cms-table__body" data-cms-site-import-results-body>
                </tbody>
            </table>
        </section>

        {{-- Execute Import --}}
        <form method="POST" action="/admin/cms/tools/site-import/execute" data-cms-site-import-execute-form hidden>
            @csrf
            <input type="hidden" name="json_content" data-cms-site-import-execute-payload>
            <button type="submit" class="cms-btn cms-btn--primary" data-cms-step-up>Execute Import</button>
        </form>
    </div>
</div>

@include('cms::admin._partials.step-up-prompt')

<script>
(function () {
    var fileInput = document.querySelector('[data-cms-site-import-file]');
    var jsonTextarea = document.querySelector('[data-cms-site-import-json]');
    var dryRunForm = document.querySelector('[data-cms-site-import-dryrun-form]');
    var dryRunPayload = document.querySelector('[data-cms-site-import-payload]');
    var resultsSection = document.querySelector('[data-cms-site-import-results]');
    var resultsBody = document.querySelector('[data-cms-site-import-results-body]');
    var executeForm = document.querySelector('[data-cms-site-import-execute-form]');
    var executePayload = document.querySelector('[data-cms-site-import-execute-payload]');
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
        dryRunForm.addEventListener('submit', function () {
            if (dryRunPayload && jsonTextarea) {
                dryRunPayload.value = jsonTextarea.value;
            }
        });
    }

    if (typeof window.__siteImportDryRunResult !== 'undefined' && resultsBody) {
        var result = window.__siteImportDryRunResult;
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
