@extends('errors.layout')

@section('theme', 'dark')
@section('title')@t('errors.502.title')@endsection

@section('content')
    <h1 class="error-page__heading" id="error-title" data-t="errors.502.heading">
        @t('errors.502.heading')
    </h1>
    <p class="error-page__description" data-t="errors.502.description">
        @t('errors.502.description')
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
