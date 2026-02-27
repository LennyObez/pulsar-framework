@extends('admin.layout')

@section('title', $isEdit ? 'Edit Campaign' : 'Create Campaign')

@section('content')
<div class="cms-newsletter-campaign-form">
    <header class="cms-newsletter-campaign-form__header">
        <h1 class="cms-newsletter-campaign-form__title">{{ $isEdit ? 'Edit Campaign' : 'Create Campaign' }}</h1>
        <a href="/admin/cms/newsletter/campaigns" class="cms-btn cms-btn--outline">Back to Campaigns</a>
    </header>

    <div class="cms-newsletter-campaign-form__layout">
        {{-- Form panel --}}
        <div class="cms-newsletter-campaign-form__editor">
            <form method="POST"
                  action="{{ $isEdit ? '/admin/cms/newsletter/campaigns/' . $campaign['id'] : '/admin/cms/newsletter/campaigns' }}"
                  class="cms-form"
                  data-cms-newsletter-form>
                @csrf

                <div class="cms-form-group">
                    <label for="campaign-subject" class="cms-form-group__label">Subject <span class="cms-form-group__required">*</span></label>
                    <input type="text"
                           id="campaign-subject"
                           name="subject"
                           value="{{ $campaign['subject'] ?? '' }}"
                           class="cms-form-group__input"
                           required
                           maxlength="255"
                           placeholder="Enter email subject line">
                    <p class="cms-form-group__help">The subject line recipients will see in their inbox.</p>
                </div>

                <div class="cms-form-group">
                    <label for="campaign-locale" class="cms-form-group__label">Locale <span class="cms-form-group__required">*</span></label>
                    <select id="campaign-locale" name="locale" class="cms-form-group__select" required>
                        @foreach ($locales ?? ['en'] as $loc)
                            <option value="{{ $loc }}" @if (($campaign['locale'] ?? 'en') === $loc) selected @endif>{{ strtoupper($loc) }}</option>
                        @endforeach
                    </select>
                    <p class="cms-form-group__help">Target locale for recipient matching. Only confirmed subscribers with this locale will receive the campaign.</p>
                </div>

                <div class="cms-form-group">
                    <label for="campaign-body-html" class="cms-form-group__label">HTML Body <span class="cms-form-group__required">*</span></label>
                    <textarea id="campaign-body-html"
                              name="body_html"
                              class="cms-form-group__textarea cms-form-group__textarea--code"
                              required
                              rows="20"
                              placeholder="Enter HTML email content"
                              data-cms-preview-source="campaign-preview">{{ $campaign['body_html'] ?? '' }}</textarea>
                    <p class="cms-form-group__help">
                        Available variables: <code>@{{ email }}</code>, <code>@{{ subscriber_id }}</code>.
                        Use <code>@{{ unsubscribe_url }}</code> for the unsubscribe link.
                    </p>
                </div>

                <div class="cms-form-group">
                    <label for="campaign-body-text" class="cms-form-group__label">Plain Text Body</label>
                    <textarea id="campaign-body-text"
                              name="body_text"
                              class="cms-form-group__textarea"
                              rows="10"
                              placeholder="Optional plain text version">{{ $campaign['body_text'] ?? '' }}</textarea>
                    <p class="cms-form-group__help">Optional plain text fallback for email clients that do not support HTML.</p>
                </div>

                <div class="cms-form-group">
                    <label for="campaign-scheduled-at" class="cms-form-group__label">Schedule Send (optional)</label>
                    <input type="datetime-local"
                           id="campaign-scheduled-at"
                           name="scheduled_at"
                           value="{{ $campaign['scheduled_at'] ?? '' }}"
                           class="cms-form-group__input">
                    <p class="cms-form-group__help">Leave empty to save as draft. Set a date and time to schedule sending automatically.</p>
                </div>

                <div class="cms-form-group cms-form-group--actions">
                    <button type="submit" name="action" value="save_draft" class="cms-btn cms-btn--outline">
                        Save Draft
                    </button>
                    <button type="submit" name="action" value="schedule" class="cms-btn cms-btn--primary">
                        {{ $isEdit ? 'Update & Schedule' : 'Create & Schedule' }}
                    </button>
                    <a href="/admin/cms/newsletter/campaigns" class="cms-btn cms-btn--outline">Cancel</a>

                    @if ($isEdit && ($campaign['status'] ?? '') === 'draft')
                        <div class="cms-form-group--spacer"></div>
                        <button type="button"
                                class="cms-btn cms-btn--success"
                                data-cms-send-test="{{ $campaign['id'] }}"
                                title="Send a test email to verify content">
                            Send Test Email
                        </button>
                    @endif
                </div>
            </form>
        </div>

        {{-- Preview panel --}}
        <aside class="cms-newsletter-campaign-form__preview">
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">HTML Preview</h3>
                <div class="cms-sidebar-panel__body">
                    <div class="cms-newsletter-campaign-form__preview-frame" id="campaign-preview">
                        @if (isset($campaign['body_html']) && $campaign['body_html'] !== '')
                            <iframe srcdoc="{{ htmlspecialchars($campaign['body_html'], ENT_QUOTES, 'UTF-8') }}"
                                    class="cms-newsletter-campaign-form__iframe"
                                    sandbox="allow-same-origin"
                                    title="Campaign HTML preview"
                                    aria-label="Email content preview"></iframe>
                        @else
                            <p class="cms-widget__empty">Enter HTML content to see a live preview.</p>
                        @endif
                    </div>
                </div>
            </div>
        </aside>
    </div>

    @if ($isEdit && ($campaign['status'] ?? '') === 'draft')
        {{-- Test send modal --}}
        <dialog id="test-send-dialog" class="cms-dialog">
            <form method="POST" action="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/test">
                @csrf
                <h2 class="cms-dialog__title">Send Test Email</h2>
                <div class="cms-form-group">
                    <label for="test-email" class="cms-form-group__label">Email address</label>
                    <input type="email" id="test-email" name="test_email" class="cms-form-group__input" required placeholder="test@example.com">
                </div>
                <div class="cms-dialog__actions">
                    <button type="submit" class="cms-btn cms-btn--primary">Send Test</button>
                    <button type="button" class="cms-btn cms-btn--outline" data-cms-close-dialog>Cancel</button>
                </div>
            </form>
        </dialog>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var sourceTextarea = document.getElementById('campaign-body-html');
        var previewContainer = document.getElementById('campaign-preview');
        if (!sourceTextarea || !previewContainer) return;

        function updatePreview() {
            var html = sourceTextarea.value;
            var iframe = previewContainer.querySelector('iframe');
            if (html.trim() === '') {
                if (iframe) {
                    iframe.remove();
                }
                if (!previewContainer.querySelector('.cms-widget__empty')) {
                    var empty = document.createElement('p');
                    empty.className = 'cms-widget__empty';
                    empty.textContent = 'Enter HTML content to see a live preview.';
                    previewContainer.appendChild(empty);
                }
                return;
            }
            var emptyMsg = previewContainer.querySelector('.cms-widget__empty');
            if (emptyMsg) {
                emptyMsg.remove();
            }
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.className = 'cms-newsletter-campaign-form__iframe';
                iframe.setAttribute('sandbox', 'allow-same-origin');
                iframe.setAttribute('title', 'Campaign HTML preview');
                iframe.setAttribute('aria-label', 'Email content preview');
                previewContainer.appendChild(iframe);
            }
            iframe.srcdoc = html;
        }

        var debounceTimer = null;
        sourceTextarea.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(updatePreview, 500);
        });

        var testBtn = document.querySelector('[data-cms-send-test]');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                var dialog = document.getElementById('test-send-dialog');
                if (dialog && typeof dialog.showModal === 'function') {
                    dialog.showModal();
                }
            });
        }

        var closeButtons = document.querySelectorAll('[data-cms-close-dialog]');
        closeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dialog = btn.closest('dialog');
                if (dialog && typeof dialog.close === 'function') {
                    dialog.close();
                }
            });
        });
    });
</script>
@endsection
