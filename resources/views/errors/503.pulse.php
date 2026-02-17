@extends('errors.layout')

@section('theme', 'dark')
@section('title')@t('errors.503.title')@endsection

@section('content')
    <h1 class="error-page__heading" id="error-title" data-t="errors.503.heading">
        @t('errors.503.heading')
    </h1>
    <p class="error-page__description" data-t="errors.503.description">
        @t('errors.503.description')
    </p>
@endsection

@section('actions')
    <a class="error-page__btn error-page__btn--primary" href="javascript:location.reload()" data-t="errors.try_again">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        @t('errors.try_again')
    </a>
    <a class="error-page__btn error-page__btn--secondary" href="/" data-t="errors.go_home">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        @t('errors.go_home')
    </a>
@endsection

@section('extra-content')
    @if(isset($maintenanceMessage) && $maintenanceMessage !== null)
    <div class="error-page__maintenance" role="status" aria-live="polite">
        <strong data-t="errors.503.maintenance">@t('errors.503.maintenance')</strong>
        <span><?php echo htmlspecialchars((string) $maintenanceMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
        @if(isset($estimatedReturn) && $estimatedReturn !== null)
        <br>
        <span data-t="errors.503.estimated_return">
            @t('errors.503.estimated_return', ['time' => $estimatedReturn])
        </span>
        @endif
    </div>
    @endif

    @if(isset($retryAfter) && $retryAfter !== null)
    <div class="error-page__countdown" role="timer" aria-live="polite" aria-label="@t('errors.retry_in', ['seconds' => $retryAfter])">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span data-t="errors.retry_in">@t('errors.retry_in', ['seconds' => ''])</span>
        <span class="error-page__countdown-value" id="retry-countdown"><?php echo (int) $retryAfter; ?></span>
    </div>
    @endif
@endsection

@section('scripts')
<script>
    (function() {
        var el = document.getElementById('retry-countdown');
        if (!el) return;
        var seconds = parseInt(el.textContent, 10);
        if (isNaN(seconds) || seconds <= 0) return;
        var timer = setInterval(function() {
            seconds--;
            el.textContent = seconds;
            if (seconds <= 0) {
                clearInterval(timer);
                location.reload();
            }
        }, 1000);
    })();
</script>
@endsection
