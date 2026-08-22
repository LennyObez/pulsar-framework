@extends('errors.layout')

@section('theme', 'light')
@section('title')@t('errors.422.title')@endsection

@section('content')
    <h1 class="error-page__heading" id="error-title" data-t="errors.422.heading">
        @t('errors.422.heading')
    </h1>
    <p class="error-page__description" data-t="errors.422.description">
        @t('errors.422.description')
    </p>
@endsection

@section('actions')
    <a class="error-page__btn error-page__btn--primary" href="javascript:history.back()" data-t="errors.go_back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
        @t('errors.go_back')
    </a>
    <a class="error-page__btn error-page__btn--secondary" href="/" data-t="errors.go_home">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        @t('errors.go_home')
    </a>
    <a class="error-page__btn error-page__btn--ghost" href="/contact" data-t="errors.contact_support">
        @t('errors.contact_support')
    </a>
@endsection
