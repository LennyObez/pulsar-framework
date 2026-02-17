@extends('public.layouts.public')

@section('title', 'Page Not Found - ' . ($siteName ?? 'Pulsar CMS'))

@section('meta_description', 'The page you are looking for does not exist.')

@section('content')
<div class="cms-error-page">
    <article class="cms-error-page__content">
        <h1 class="cms-error-page__title">404</h1>
        <p class="cms-error-page__subtitle">Page Not Found</p>
        <p class="cms-error-page__message">The page you are looking for does not exist or has been moved.</p>
        <div class="cms-error-page__actions">
            <a href="/" class="pui-btn pui-btn--primary">Back to Homepage</a>
        </div>
    </article>
</div>
@endsection
