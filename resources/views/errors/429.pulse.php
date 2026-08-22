@extends('errors.layout')

@section('theme', 'light')
@section('title')@t('errors.429.title')@endsection

@section('content')
    <h1 class="error-page__heading" id="error-title" data-t="errors.429.heading">
        @t('errors.429.heading')
    </h1>
    <p class="error-page__description" data-t="errors.429.description">
        @t('errors.429.description')
    </p>
@endsection

@section('actions')
    <a class="error-page__btn error-page__btn--secondary" href="/" data-t="errors.go_home">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        @t('errors.go_home')
    </a>
    <a class="error-page__btn error-page__btn--ghost" href="javascript:history.back()" data-t="errors.go_back">
        @t('errors.go_back')
    </a>
@endsection

@section('extra-content')
    @if(isset($retryAfter) && $retryAfter !== null)
    <div class="error-page__countdown" role="timer" aria-live="polite" aria-label="@t('errors.retry_in', ['seconds' => $retryAfter])">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span data-t="errors.retry_in">@t('errors.retry_in', ['seconds' => ''])</span>
        <span class="error-page__countdown-value" id="retry-countdown"><?php echo (int) $retryAfter; ?></span>
    </div>
    @endif
@endsection

@section('scripts')
<script src="/ui/js/error-countdown.js" defer></script>
@endsection
