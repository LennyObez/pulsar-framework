@extends('errors.layout')

@section('theme', 'light')
@section('title')@t('errors.404.title')@endsection

@section('content')
    <h1 class="error-page__heading" id="error-title" data-t="errors.404.heading">
        @t('errors.404.heading')
    </h1>
    <p class="error-page__description" data-t="errors.404.description">
        @t('errors.404.description')
    </p>
@endsection

@section('actions')
    <a class="error-page__btn error-page__btn--primary" href="/" data-t="errors.go_home">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        @t('errors.go_home')
    </a>
    <a class="error-page__btn error-page__btn--secondary" href="javascript:history.back()" data-t="errors.go_back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
        @t('errors.go_back')
    </a>
    <a class="error-page__btn error-page__btn--ghost" href="/contact" data-t="errors.contact_support">
        @t('errors.contact_support')
    </a>
@endsection

@section('extra-content')
    <form class="error-page__search" action="/search" method="GET" role="search" aria-label="@t('errors.search')">
        <svg class="error-page__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
            class="error-page__search-input"
            type="search"
            name="q"
            placeholder="@t('errors.search_placeholder')"
            aria-label="@t('errors.search')"
            data-t="errors.search_placeholder"
            autocomplete="off"
        >
    </form>

    <div class="error-page__links-section" aria-label="@t('errors.popular_links')">
        <p class="error-page__links-label" data-t="errors.popular_links">
            @t('errors.popular_links')
        </p>
        <div class="error-page__links">
            <a class="error-page__link" href="/">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                @t('home')
            </a>
            <a class="error-page__link" href="/docs">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Documentation
            </a>
            <a class="error-page__link" href="/contact">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                @t('errors.contact_support')
            </a>
        </div>
    </div>
@endsection
