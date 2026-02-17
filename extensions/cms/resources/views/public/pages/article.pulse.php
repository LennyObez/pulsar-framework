@extends('public.layouts.public')

@section('title', ($translation->metaTitle ?? $translation->title ?? 'Article') . ' - ' . ($siteName ?? 'Pulsar CMS'))

@section('meta_description', $translation->metaDescription ?? $translation->excerpt ?? '')

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
<div class="cms-article-layout">
    <article class="cms-article" itemscope itemtype="https://schema.org/Article">
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

        {{-- Article header --}}
        <header class="cms-article__header">
            <h1 class="cms-article__title" itemprop="headline">{{ $translation->title ?? '' }}</h1>

            <div class="cms-article__meta">
                @if (!empty($content->authorName))
                    <address class="cms-article__author" rel="author" itemprop="author" itemscope itemtype="https://schema.org/Person">
                        <span itemprop="name">{{ $content->authorName }}</span>
                    </address>
                @endif

                @if (!empty($content->publishedAt))
                    <time class="cms-article__date" datetime="{{ $content->publishedAt->format('c') }}" itemprop="datePublished">
                        {{ $content->publishedAt->format('F j, Y') }}
                    </time>
                @endif

                @if (!empty($translation->readingTimeMinutes))
                    <span class="cms-article__reading-time">
                        {{ $translation->readingTimeMinutes }} min read
                    </span>
                @endif
            </div>
        </header>

        {{-- Article body --}}
        <div class="cms-article__content pui-prose" itemprop="articleBody">
            @if (!empty($renderedBlocks))
                {!! $renderedBlocks !!}
            @elseif (!empty($translation->body))
                {!! $translation->body !!}
            @endif
        </div>

        {{-- Tags --}}
        @if (!empty($tags))
            <footer class="cms-article__tags">
                <span class="cms-article__tags-label">Tags:</span>
                <ul class="cms-article__tag-list" aria-label="Article tags">
                    @foreach ($tags as $tag)
                        <?php /** @var array{slug: string, name: string} $tag */ ?>
                        <li>
                            <a href="/category/{{ $tag['slug'] }}" class="cms-article__tag">{{ $tag['name'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </footer>
        @endif
    </article>

    {{-- Sidebar --}}
    <aside class="cms-article-sidebar" aria-label="Related content">
        {{-- Related articles --}}
        @if (!empty($relatedArticles))
            <section class="cms-sidebar-section" aria-labelledby="related-heading">
                <h2 id="related-heading" class="cms-sidebar-section__title">Related articles</h2>
                <ul class="cms-sidebar-section__list">
                    @foreach ($relatedArticles as $related)
                        <?php /** @var array{title: string, slug: string, published_at: string} $related */ ?>
                        <li class="cms-sidebar-section__item">
                            <a href="/articles/{{ $related['slug'] }}" class="cms-sidebar-section__link">
                                {{ $related['title'] }}
                            </a>
                            @if (!empty($related['published_at']))
                                <time class="cms-sidebar-section__date" datetime="{{ $related['published_at'] }}">
                                    {{ date('M j, Y', strtotime($related['published_at'])) }}
                                </time>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Categories --}}
        @if (!empty($categories))
            <section class="cms-sidebar-section" aria-labelledby="sidebar-categories-heading">
                <h2 id="sidebar-categories-heading" class="cms-sidebar-section__title">Categories</h2>
                <ul class="cms-sidebar-section__list">
                    @foreach ($categories as $cat)
                        <?php /** @var array{slug: string, name: string, count: int} $cat */ ?>
                        <li class="cms-sidebar-section__item">
                            <a href="/category/{{ $cat['slug'] }}" class="cms-sidebar-section__link">
                                {{ $cat['name'] }} <span class="cms-sidebar-section__count">({{ $cat['count'] }})</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </aside>
</div>

{{-- Comments section --}}
@if (($content->commentPolicy ?? 'closed') !== 'closed')
    <section class="cms-comments" aria-labelledby="comments-heading" id="comments">
        <div class="cms-comments__inner">
            <h2 id="comments-heading" class="cms-section-heading">
                Comments
                @if (!empty($commentCount))
                    <span class="cms-comments__count">({{ $commentCount }})</span>
                @endif
            </h2>

            {{-- Existing comments --}}
            @if (!empty($comments))
                <ol class="cms-comments__list">
                    @foreach ($comments as $comment)
                        <?php /** @var array{id: string, body: string, author_name: string, created_at: string, replies?: list<array{id: string, body: string, author_name: string, created_at: string}>} $comment */ ?>
                        <li class="cms-comment" id="comment-{{ $comment['id'] }}">
                            <div class="cms-comment__header">
                                <strong class="cms-comment__author">{{ $comment['author_name'] ?? 'Guest' }}</strong>
                                <time class="cms-comment__date" datetime="{{ $comment['created_at'] }}">
                                    {{ date('M j, Y \a\t g:i A', strtotime($comment['created_at'])) }}
                                </time>
                            </div>
                            <div class="cms-comment__body">{{ $comment['body'] }}</div>

                            {{-- Replies --}}
                            @if (!empty($comment['replies']))
                                <ol class="cms-comment__replies">
                                    @foreach ($comment['replies'] as $reply)
                                        <li class="cms-comment cms-comment--reply" id="comment-{{ $reply['id'] }}">
                                            <div class="cms-comment__header">
                                                <strong class="cms-comment__author">{{ $reply['author_name'] ?? 'Guest' }}</strong>
                                                <time class="cms-comment__date" datetime="{{ $reply['created_at'] }}">
                                                    {{ date('M j, Y \a\t g:i A', strtotime($reply['created_at'])) }}
                                                </time>
                                            </div>
                                            <div class="cms-comment__body">{{ $reply['body'] }}</div>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="cms-comments__empty">No comments yet. Be the first to share your thoughts.</p>
            @endif

            {{-- Comment form --}}
            <div class="cms-comment-form" id="respond">
                <h3 class="cms-comment-form__title">Leave a comment</h3>
                <form method="POST"
                      action="/api/cms/comments"
                      class="cms-comment-form__form"
                      data-cms-comment-form>
                    @csrf
                    <input type="hidden" name="content_id" value="{{ $content->id ?? '' }}">

                    @if (empty($identity))
                        <div class="cms-comment-form__row">
                            <div class="cms-comment-form__field">
                                <label for="comment-name" class="cms-comment-form__label">
                                    Name <span class="cms-required" aria-label="required">*</span>
                                </label>
                                <input type="text"
                                       id="comment-name"
                                       name="guest_name"
                                       required
                                       aria-required="true"
                                       class="cms-comment-form__input"
                                       maxlength="100">
                            </div>
                            <div class="cms-comment-form__field">
                                <label for="comment-email" class="cms-comment-form__label">Email</label>
                                <input type="email"
                                       id="comment-email"
                                       name="guest_email"
                                       class="cms-comment-form__input"
                                       maxlength="320">
                            </div>
                        </div>
                    @endif

                    <div class="cms-comment-form__field">
                        <label for="comment-body" class="cms-comment-form__label">
                            Comment <span class="cms-required" aria-label="required">*</span>
                        </label>
                        <textarea id="comment-body"
                                  name="body"
                                  required
                                  aria-required="true"
                                  class="cms-comment-form__textarea"
                                  rows="4"
                                  maxlength="5000"></textarea>
                    </div>

                    <button type="submit" class="pui-btn pui-btn--primary cms-comment-form__submit">
                        Post comment
                    </button>
                </form>
            </div>
        </div>
    </section>
@endif
@endsection
