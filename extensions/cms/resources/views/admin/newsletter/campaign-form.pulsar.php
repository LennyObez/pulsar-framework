@extends('admin.layout')

@section('title', $isEdit ? 'Edit Campaign' : 'Create Campaign')

@section('content')
<div class="cms-newsletter-campaign-form">
    <header class="cms-newsletter-campaign-form__header">
        <h1 class="cms-newsletter-campaign-form__title">{{ $isEdit ? 'Edit Campaign' : 'Create Campaign' }}</h1>
        <a href="/admin/cms/newsletter/campaigns" class="cms-btn cms-btn--outline">Back to Campaigns</a>
    </header>

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
            <input type="text"
                   id="campaign-locale"
                   name="locale"
                   value="{{ $campaign['locale'] ?? 'en' }}"
                   class="cms-form-group__input"
                   required
                   maxlength="10"
                   placeholder="en">
            <p class="cms-form-group__help">Target locale for recipient matching. Only confirmed subscribers with this locale will receive the campaign.</p>
        </div>

        <div class="cms-form-group">
            <label for="campaign-body-html" class="cms-form-group__label">HTML Body <span class="cms-form-group__required">*</span></label>
            <textarea id="campaign-body-html"
                      name="body_html"
                      class="cms-form-group__textarea cms-form-group__textarea--code"
                      required
                      rows="20"
                      placeholder="Enter HTML email content">{{ $campaign['body_html'] ?? '' }}</textarea>
            <p class="cms-form-group__help">
                Available variables: <code>{{email}}</code>, <code>{{subscriber_id}}</code>.
                Use <code>{{unsubscribe_url}}</code> for the unsubscribe link.
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

        <div class="cms-form-group cms-form-group--actions">
            <button type="submit" class="cms-btn cms-btn--primary">
                {{ $isEdit ? 'Update Campaign' : 'Create Campaign' }}
            </button>
            <a href="/admin/cms/newsletter/campaigns" class="cms-btn cms-btn--outline">Cancel</a>

            @if ($isEdit && ($campaign['status'] ?? '') === 'draft')
                <div class="cms-form-group--spacer"></div>
                <button type="button"
                        class="cms-btn cms-btn--success"
                        data-cms-schedule-campaign="{{ $campaign['id'] }}"
                        title="Schedule this campaign for later">
                    Schedule
                </button>
                <button type="button"
                        class="cms-btn cms-btn--primary"
                        data-cms-send-test="{{ $campaign['id'] }}"
                        title="Send a test email">
                    Send Test
                </button>
            @endif
        </div>
    </form>

    @if ($isEdit && ($campaign['status'] ?? '') === 'draft')
        {{-- Schedule modal --}}
        <dialog id="schedule-dialog" class="cms-dialog">
            <form method="POST" action="/admin/cms/newsletter/campaigns/{{ $campaign['id'] }}/schedule">
                @csrf
                <h2 class="cms-dialog__title">Schedule Campaign</h2>
                <div class="cms-form-group">
                    <label for="schedule-datetime" class="cms-form-group__label">Send at</label>
                    <input type="datetime-local" id="schedule-datetime" name="scheduled_at" class="cms-form-group__input" required>
                </div>
                <div class="cms-dialog__actions">
                    <button type="submit" class="cms-btn cms-btn--primary">Schedule</button>
                    <button type="button" class="cms-btn cms-btn--outline" data-cms-close-dialog>Cancel</button>
                </div>
            </form>
        </dialog>

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
@endsection
