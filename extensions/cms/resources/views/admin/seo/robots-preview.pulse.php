@extends('admin.layout')

@section('title', 'Robots.txt Preview')

@section('content')
<div class="cms-robots-preview">
    <header class="cms-robots-preview__header">
        <h1 class="cms-robots-preview__title">Robots.txt Preview</h1>
        <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
    </header>

    <p class="cms-robots-preview__description">
        This is the generated <code>robots.txt</code> content served at <code>/robots.txt</code>.
        Changes to sitemap URLs and crawl directives are configured in the SEO settings.
    </p>

    <section class="cms-robots-preview__content" aria-labelledby="robots-content-heading">
        <h2 class="cms-robots-preview__section-title cms-sr-only" id="robots-content-heading">Robots.txt Content</h2>
        <pre class="cms-robots-preview__pre"><code>{{ $robotsTxt ?? '' }}</code></pre>
    </section>
</div>
@endsection
