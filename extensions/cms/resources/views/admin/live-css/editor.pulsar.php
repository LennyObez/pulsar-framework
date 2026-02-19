@extends('cms::admin.layout')

@section('title', 'Live CSS Editor')

@section('cms-content')
<div class="cms-livecss-editor" data-cms-livecss-editor>
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Live CSS Editor — {{ $theme['display_name'] ?? 'Theme' }}</h1>
    </header>

    <form method="POST" action="/admin/cms/live-css/save" class="cms-livecss-editor__form" data-cms-livecss-form>
        @csrf
        <input type="hidden" name="theme_id" value="{{ $theme['id'] ?? '' }}">

        <div class="cms-livecss-editor__split" style="display: flex; gap: 1rem; min-height: 600px;">
            {{-- Left Panel: Token editor + CSS --}}
            <div class="cms-livecss-editor__panel" style="flex: 1; overflow-y: auto;">
                {{-- Token Groups --}}
                @if (!empty($tokens))
                    <fieldset class="cms-fieldset">
                        <legend class="cms-fieldset__legend">Theme Tokens</legend>
                        <?php
                        $__groups = [];
                        foreach ($tokens as $token) {
                            $group = $token['group'] ?? 'General';
                            $__groups[$group][] = $token;
                        }
                        ?>
                        @foreach ($__groups as $groupName => $groupTokens)
                            <div class="cms-livecss-editor__token-group">
                                <h3 class="cms-livecss-editor__group-title">{{ $groupName }}</h3>
                                @foreach ($groupTokens as $token)
                                    <div class="cms-form-group">
                                        <label for="token-{{ $token['name'] }}" class="cms-form-group__label">{{ $token['label'] ?? $token['name'] }}</label>
                                        <?php
                                        $__tokenType = $token['type'] ?? 'text';
                        $__tokenValue = $currentOverrides['token_overrides'][$token['name']] ?? $token['default'] ?? '';
                        ?>
                                        @if ($__tokenType === 'color')
                                            <input type="color"
                                                   id="token-{{ $token['name'] }}"
                                                   name="token_overrides[{{ $token['name'] }}]"
                                                   value="{{ $__tokenValue }}"
                                                   class="cms-form-group__input cms-form-group__input--color"
                                                   data-cms-livecss-token="{{ $token['name'] }}">
                                        @elseif ($__tokenType === 'font')
                                            <select id="token-{{ $token['name'] }}"
                                                    name="token_overrides[{{ $token['name'] }}]"
                                                    class="cms-form-group__select"
                                                    data-cms-livecss-token="{{ $token['name'] }}">
                                                @foreach ($token['constraints']['options'] ?? [] as $fontOpt)
                                                    <option value="{{ $fontOpt }}" @if ($__tokenValue === $fontOpt) selected @endif>{{ $fontOpt }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($__tokenType === 'size')
                                            <input type="range"
                                                   id="token-{{ $token['name'] }}"
                                                   name="token_overrides[{{ $token['name'] }}]"
                                                   value="{{ $__tokenValue }}"
                                                   class="cms-form-group__input cms-form-group__input--range"
                                                   min="{{ $token['constraints']['min'] ?? 0 }}"
                                                   max="{{ $token['constraints']['max'] ?? 100 }}"
                                                   step="{{ $token['constraints']['step'] ?? 1 }}"
                                                   data-cms-livecss-token="{{ $token['name'] }}">
                                            <span class="cms-form-group__hint" data-cms-range-value>{{ $__tokenValue }}</span>
                                        @else
                                            <input type="text"
                                                   id="token-{{ $token['name'] }}"
                                                   name="token_overrides[{{ $token['name'] }}]"
                                                   value="{{ $__tokenValue }}"
                                                   class="cms-form-group__input"
                                                   data-cms-livecss-token="{{ $token['name'] }}">
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </fieldset>
                @endif

                {{-- CSS Override Textarea --}}
                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Custom CSS</legend>
                    <div class="cms-form-group">
                        <label for="css-content" class="cms-form-group__label">CSS Overrides</label>
                        <textarea id="css-content"
                                  name="css_content"
                                  class="cms-form-group__textarea cms-form-group__textarea--code"
                                  rows="15"
                                  style="font-family: monospace; min-height: 300px;"
                                  data-cms-livecss-css>{{ $currentOverrides['css_content'] ?? '' }}</textarea>
                    </div>
                </fieldset>

                {{-- Save Section --}}
                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Save Changes</legend>
                    <div class="cms-form-group">
                        <label for="save-reason" class="cms-form-group__label">Reason <span class="cms-required" aria-label="required">*</span></label>
                        <input type="text"
                               id="save-reason"
                               name="reason"
                               class="cms-form-group__input"
                               required
                               aria-required="true"
                               minlength="5"
                               placeholder="Describe the changes you made">
                    </div>
                    <button type="submit" class="cms-btn cms-btn--primary">Save Overrides</button>
                </fieldset>
            </div>

            {{-- Right Panel: Preview --}}
            <div class="cms-livecss-editor__preview" style="flex: 1;">
                <iframe src="{{ $previewUrl ?? '/' }}?preview_token={{ $previewToken ?? '' }}"
                        class="cms-livecss-editor__iframe"
                        style="width: 100%; height: 100%; border: 1px solid var(--cms-border-color, #ddd); border-radius: 4px;"
                        title="Live preview"
                        data-cms-livecss-preview></iframe>
            </div>
        </div>
    </form>

    {{-- Version History --}}
    <section class="cms-card">
        <h2 class="cms-card__title">Version History</h2>
        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th" scope="col">Version</th>
                    <th class="cms-table__th" scope="col">Date</th>
                    <th class="cms-table__th" scope="col">Author</th>
                    <th class="cms-table__th" scope="col">Reason</th>
                    <th class="cms-table__th" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($history))
                    <tr>
                        <td colspan="5" class="cms-table__empty">No version history yet.</td>
                    </tr>
                @endif

                @foreach ($history ?? [] as $version)
                    <tr class="cms-table__row @if ($version['is_active'] ?? false) cms-table__row--active @endif">
                        <td class="cms-table__td">
                            #{{ $version['version'] ?? '' }}
                            @if ($version['is_active'] ?? false)
                                <span class="cms-badge cms-badge--published">Current</span>
                            @endif
                        </td>
                        <td class="cms-table__td">
                            <time datetime="{{ $version['created_at'] ?? '' }}">{{ $version['created_at'] ?? '' }}</time>
                        </td>
                        <td class="cms-table__td">{{ $version['created_by'] ?? '' }}</td>
                        <td class="cms-table__td">{{ $version['reason'] ?? '' }}</td>
                        <td class="cms-table__td cms-table__td--actions">
                            <div class="cms-action-group" role="group" aria-label="Version actions">
                                @if (!($version['is_active'] ?? false))
                                    <button type="button"
                                            class="cms-btn cms-btn--sm cms-btn--warning"
                                            data-cms-rollback-id="{{ $version['id'] }}"
                                            data-cms-rollback-version="{{ $version['version'] }}">
                                        Rollback
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Rollback Form (hidden, used by JS) --}}
    <form method="POST" action="/admin/cms/live-css/rollback" data-cms-rollback-form hidden>
        @csrf
        <input type="hidden" name="override_id" data-cms-rollback-override-id>
    </form>
</div>

@include('cms::admin._partials.confirm-destructive', [
    'actionDescription' => 'rollback to a previous CSS version',
    'formAction' => '/admin/cms/live-css/rollback',
    'formMethod' => 'POST',
    'csrfToken' => $csrfToken ?? '',
])

<script>
(function () {
    var previewFrame = document.querySelector('[data-cms-livecss-preview]');
    var tokenInputs = document.querySelectorAll('[data-cms-livecss-token]');
    var cssTextarea = document.querySelector('[data-cms-livecss-css]');

    function sendPreviewUpdate() {
        if (!previewFrame || !previewFrame.contentWindow) return;
        var tokens = {};
        for (var i = 0; i < tokenInputs.length; i++) {
            tokens[tokenInputs[i].getAttribute('data-cms-livecss-token')] = tokenInputs[i].value;
        }
        previewFrame.contentWindow.postMessage({
            type: 'pulsar-livecss-update',
            tokens: tokens,
            css: cssTextarea ? cssTextarea.value : ''
        }, '*');
    }

    for (var i = 0; i < tokenInputs.length; i++) {
        tokenInputs[i].addEventListener('input', function () {
            var rangeHint = this.parentElement.querySelector('[data-cms-range-value]');
            if (rangeHint) rangeHint.textContent = this.value;
            sendPreviewUpdate();
        });
    }

    if (cssTextarea) {
        cssTextarea.addEventListener('input', sendPreviewUpdate);
    }

    // Rollback handler
    var rollbackBtns = document.querySelectorAll('[data-cms-rollback-id]');
    var destructiveModal = document.querySelector('[data-cms-destructive-modal]');
    var destructiveForm = destructiveModal ? destructiveModal.querySelector('[data-cms-destructive-form]') : null;

    for (var j = 0; j < rollbackBtns.length; j++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                if (destructiveForm) {
                    destructiveForm.action = '/admin/cms/live-css/rollback';
                    var methodInput = destructiveForm.querySelector('input[name="_method"]');
                    if (methodInput) methodInput.value = 'POST';

                    var existingId = destructiveForm.querySelector('input[name="override_id"]');
                    if (existingId) {
                        existingId.value = btn.getAttribute('data-cms-rollback-id');
                    } else {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'override_id';
                        input.value = btn.getAttribute('data-cms-rollback-id');
                        destructiveForm.appendChild(input);
                    }
                }

                if (destructiveModal) {
                    destructiveModal.hidden = false;
                }
            });
        })(rollbackBtns[j]);
    }
})();
</script>
@endsection
