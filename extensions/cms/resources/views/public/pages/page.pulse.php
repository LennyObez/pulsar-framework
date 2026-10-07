@extends('public.layouts.public')

@section('title', ($translation->metaTitle ?? $translation->title ?? 'Page') . ' - ' . ($siteName ?? 'Pulsar CMS'))

@section('meta_description', $translation->metaDescription ?? '')

@section('meta')
    @if (!empty($hreflang))
        @foreach ($hreflang as $link)
            <link rel="alternate" hreflang="{{ $link['locale'] }}" href="{{ $link['href'] }}">
        @endforeach
    @endif
    @if (!empty($jsonLd))
        {!! $jsonLd !!}
    @endif
    <link rel="canonical" href="{{ $canonicalUrl ?? '' }}">
@endsection

@section('content')
<div class="cms-page">
    {{-- Breadcrumbs --}}
    @if (!empty($breadcrumbs))
        <nav class="cms-breadcrumbs" aria-label="Breadcrumb">
            <ol class="cms-breadcrumbs__list" itemscope itemtype="https://schema.org/BreadcrumbList">
                @foreach ($breadcrumbs as $i => $crumb)
                    <?php /** @var array{label: string, url: string, is_current: bool} $crumb */ ?>
                    <li class="cms-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                        @if ($crumb['is_current'])
                            <span aria-current="page" itemprop="name">{{ $crumb['label'] }}</span>
                        @else
                            <a href="{{ $crumb['url'] }}" itemprop="item"><span itemprop="name">{{ $crumb['label'] }}</span></a>
                            <span class="cms-breadcrumbs__separator" aria-hidden="true">/</span>
                        @endif
                        <meta itemprop="position" content="{{ $i + 1 }}">
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    <article class="cms-page__content" itemscope itemtype="https://schema.org/WebPage">
        <header class="cms-page__header">
            <h1 class="cms-page__title" itemprop="name">{{ $translation->title ?? '' }}</h1>
            @if ($content !== null && $content->updatedAt !== null)
                <p class="cms-page__updated">
                    Last updated:
                    <time datetime="{{ $content->updatedAt->format('c') }}" itemprop="dateModified">
                        {{ $content->updatedAt->format('F j, Y') }}
                    </time>
                </p>
            @endif
        </header>

        <div class="cms-page__body pui-prose" itemprop="mainContentOfPage">
            @if (!empty($renderedBlocks))
                {!! $renderedBlocks !!}
            @elseif (!empty($translation->body))
                {!! $translation->body !!}
            @endif
        </div>
    </article>
</div>
@endsection
