{{-- Content lock banner. Expects: $lock (array|null with locked_by, locked_at, expires_at keys), $contentId (string) --}}
@if($lock !== null)
<div class="cms-alert cms-alert--warning cms-lock-banner" role="alert">
    <div class="cms-lock-banner__info">
        <svg class="cms-lock-banner__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M14 8V6a4 4 0 10-8 0v2H4v8a2 2 0 002 2h8a2 2 0 002-2V8h-2zm-6-2a2 2 0 114 0v2H8V6z" fill="currentColor"/>
        </svg>
        <span>
            This content is locked by <strong>{{ $lock['locked_by'] }}</strong>
            since <time datetime="{{ $lock['locked_at'] }}">{{ $lock['locked_at'] }}</time>.
            Lock expires at <time datetime="{{ $lock['expires_at'] }}">{{ $lock['expires_at'] }}</time>.
        </span>
    </div>

    @can('cms.content.admin')
    <form method="POST" action="/admin/cms/content/{{ $contentId }}/break-lock" class="cms-lock-banner__action">
        <button type="submit"
                class="cms-btn cms-btn--danger cms-btn--sm"
                onclick="return confirm('Are you sure you want to break this lock? The other user may lose unsaved changes.')">
            Break Lock
        </button>
    </form>
    @endcan
</div>
@endif
