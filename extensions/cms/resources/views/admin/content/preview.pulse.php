@extends('admin.layout')

@section('title', 'Live preview: ' . ($translation['title'] ?? 'Content'))

@section('styles')
<style>
    .cms-preview-pane {
        display: grid;
        grid-template-columns: 1fr 1fr;
        height: calc(100vh - 3.5rem);
        overflow: hidden;
    }
    .cms-preview-pane__editor {
        overflow-y: auto;
        padding: 1.5rem;
        border-right: 1px solid var(--color-border, #e2e8f0);
    }
    .cms-preview-pane__viewer {
        display: flex;
        flex-direction: column;
        background: var(--color-bg-secondary, #f8fafc);
    }
    .cms-preview-pane__toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.5rem 1rem;
        background: var(--color-bg, #fff);
        border-bottom: 1px solid var(--color-border, #e2e8f0);
        font-size: 0.875rem;
        color: var(--color-text-muted, #64748b);
    }
    .cms-preview-pane__status {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.75rem;
    }
    .cms-preview-pane__status-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #10b981;
    }
    .cms-preview-pane__status-dot--updating {
        background: #f59e0b;
        animation: pulse-dot 1s infinite;
    }
    @keyframes pulse-dot {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.4; }
    }
    .cms-preview-pane__iframe {
        flex: 1;
        width: 100%;
        border: none;
        background: #fff;
    }
    @media (max-width: 1024px) {
        .cms-preview-pane {
            grid-template-columns: 1fr;
            grid-template-rows: 1fr 1fr;
        }
        .cms-preview-pane__editor {
            border-right: none;
            border-bottom: 1px solid var(--color-border, #e2e8f0);
        }
    }
</style>
@endsection

@section('content')
<div class="cms-preview-pane" data-cms-live-preview>
    {{-- Editor side --}}
    <div class="cms-preview-pane__editor">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
            <h2 style="margin: 0; font-size: 1.25rem; font-weight: 600;">Edit content</h2>
            <a href="/admin/cms/content/{{ $content['id'] }}/edit" class="cms-btn cms-btn--outline cms-btn--sm">
                Full editor
            </a>
        </div>

        <form id="preview-form" class="cms-content-form__form">
            <div class="cms-form-group" style="margin-bottom: 1rem;">
                <label for="preview-title" class="cms-form-group__label">Title</label>
                <input type="text"
                       id="preview-title"
                       name="title"
                       value="{{ $translation['title'] ?? '' }}"
                       class="cms-form-group__input"
                       data-preview-field="title">
            </div>

            <div class="cms-form-group" style="margin-bottom: 1rem;">
                <label for="preview-excerpt" class="cms-form-group__label">Excerpt</label>
                <textarea id="preview-excerpt"
                          name="excerpt"
                          class="cms-form-group__textarea"
                          rows="2"
                          data-preview-field="excerpt">{{ $translation['excerpt'] ?? '' }}</textarea>
            </div>

            <div class="cms-form-group" style="margin-bottom: 1rem;">
                <label for="preview-body" class="cms-form-group__label">Body</label>
                <textarea id="preview-body"
                          name="body"
                          class="cms-form-group__textarea cms-editor"
                          rows="16"
                          data-preview-field="body">{{ $translation['body'] ?? '' }}</textarea>
            </div>

            <input type="hidden" name="blocks_json" value="{{ isset($blocks) ? json_encode($blocks, JSON_UNESCAPED_UNICODE) : '[]' }}">
        </form>
    </div>

    {{-- Preview side --}}
    <div class="cms-preview-pane__viewer">
        <div class="cms-preview-pane__toolbar">
            <span>Live preview</span>
            <span class="cms-preview-pane__status">
                <span class="cms-preview-pane__status-dot" id="preview-status-dot"></span>
                <span id="preview-status-text">Ready</span>
            </span>
        </div>
        <iframe class="cms-preview-pane__iframe"
                id="preview-iframe"
                src="{{ $previewUrl ?? '' }}"
                title="Content preview"
                sandbox="allow-same-origin"
                loading="lazy"></iframe>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function() {
    'use strict';

    var form = document.getElementById('preview-form');
    var iframe = document.getElementById('preview-iframe');
    var statusDot = document.getElementById('preview-status-dot');
    var statusText = document.getElementById('preview-status-text');
    var debounceTimer = null;
    var DEBOUNCE_MS = 400;
    var previewUrl = '{{ $previewUrl ?? '' }}';

    if (!form || !iframe || !previewUrl) {
        return;
    }

    function setUpdating() {
        statusDot.className = 'cms-preview-pane__status-dot cms-preview-pane__status-dot--updating';
        statusText.textContent = 'Updating...';
    }

    function setReady() {
        statusDot.className = 'cms-preview-pane__status-dot';
        statusText.textContent = 'Ready';
    }

    function updatePreview() {
        setUpdating();

        var formData = new FormData(form);

        fetch(previewUrl, {
            method: 'POST',
            body: formData,
        })
        .then(function(response) {
            return response.text();
        })
        .then(function(html) {
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open();
            doc.write(html);
            doc.close();
            setReady();
        })
        .catch(function() {
            statusText.textContent = 'Error';
        });
    }

    var fields = form.querySelectorAll('[data-preview-field]');
    fields.forEach(function(field) {
        field.addEventListener('input', function() {
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            debounceTimer = setTimeout(updatePreview, DEBOUNCE_MS);
        });
    });
})();
</script>
@endsection
