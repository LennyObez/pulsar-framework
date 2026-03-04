@extends('errors.layout')

@section('theme', 'light')
@section('title')@t('errors.419.title')@endsection

@section('content')
    <h1 class="error-page__heading" id="error-title" data-t="errors.419.heading">
        @t('errors.419.heading')
    </h1>
    <p class="error-page__description" data-t="errors.419.description">
        @t('errors.419.description')
    </p>
@endsection

@section('actions')
    <a class="error-page__btn error-page__btn--primary" href="javascript:location.reload()" data-t="errors.refresh_page">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        @t('errors.refresh_page')
    </a>
    <a class="error-page__btn error-page__btn--secondary" href="/" data-t="errors.go_home">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        @t('errors.go_home')
    </a>
@endsection

@section('extra-styles')
<style>
    .error-page__session-hint {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        max-width: 400px;
        margin: 0 auto 2rem;
        padding: 1rem 1.25rem;
        background: var(--warning-bg);
        border: 1px solid var(--warning);
        border-radius: var(--radius-md);
        color: var(--warning);
        font-size: 0.875rem;
        text-align: left;
    }
    .error-page__session-hint svg { flex-shrink: 0; }
</style>
@endsection

@section('extra-content')
    <div class="error-page__session-hint" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span data-t="errors.419.description">@t('errors.419.description')</span>
    </div>
@endsection
