@extends('admin.layout')

@section('title', $translation['title'] ?? 'Content Preview')

@section('content')
<div class="cms-content-show">
    <header class="cms-content-show__header">
        <div class="cms-content-show__meta">
            <h1 class="cms-content-show__title">{{ $translation['title'] ?? '(Untitled)' }}</h1>
            @include('cms::admin._partials.status-badge', ['status' => $content['status'] ?? 'draft'])
        </div>
        <div class="cms-content-show__actions">
            @can('cms.content.edit')
                <a href="/admin/cms/content/{{ $content['id'] }}/edit" class="cms-btn cms-btn--primary">Edit</a>
            @endcan
            <a href="/admin/cms/content" class="cms-btn cms-btn--outline">Back to List</a>
        </div>
    </header>

    {{-- Locale tabs for preview --}}
    @if (isset($locales) && count($locales) > 1)
        @include('cms::admin._partials.locale-tabs', [
            'locales' => $locales,
            'activeLocale' => $activeLocale ?? 'en',
            'baseUrl' => '/admin/cms/content/' . $content['id'],
        ])
    @endif

    <div class="cms-content-show__layout">
        <article class="cms-content-show__body">
            {{-- Content metadata --}}
            <dl class="cms-detail-list">
                <dt class="cms-detail-list__term">Type</dt>
                <dd class="cms-detail-list__value">{{ ucfirst($content['type'] ?? 'page') }}</dd>

                <dt class="cms-detail-list__term">Slug</dt>
                <dd class="cms-detail-list__value"><code>{{ $translation['path'] ?? $translation['slug'] ?? '' }}</code></dd>

                <dt class="cms-detail-list__term">Author</dt>
                <dd class="cms-detail-list__value">{{ $content['author_name'] ?? $content['author_id'] ?? '' }}</dd>

                <dt class="cms-detail-list__term">Created</dt>
                <dd class="cms-detail-list__value">
                    <time datetime="{{ $content['created_at'] ?? '' }}">{{ $content['created_at'] ?? '' }}</time>
                </dd>

                <dt class="cms-detail-list__term">Last Modified</dt>
                <dd class="cms-detail-list__value">
                    <time datetime="{{ $content['updated_at'] ?? '' }}">{{ $content['updated_at'] ?? '' }}</time>
                </dd>

                @if (isset($content['published_at']))
                    <dt class="cms-detail-list__term">Published</dt>
                    <dd class="cms-detail-list__value">
                        <time datetime="{{ $content['published_at'] }}">{{ $content['published_at'] }}</time>
                    </dd>
                @endif

                @if (isset($content['data_classification']))
                    <dt class="cms-detail-list__term">Classification</dt>
                    <dd class="cms-detail-list__value">{{ ucfirst($content['data_classification']) }}</dd>
                @endif
            </dl>

            {{-- Excerpt --}}
            @if (!empty($translation['excerpt']))
                <div class="cms-content-show__excerpt">
                    <h2 class="cms-content-show__section-title">Excerpt</h2>
                    <p>{{ $translation['excerpt'] }}</p>
                </div>
            @endif

            {{-- Body preview --}}
            <div class="cms-content-show__preview">
                <h2 class="cms-content-show__section-title">Content Preview</h2>
                <div class="cms-content-show__rendered">
                    {!! $translation['body'] ?? '' !!}
                </div>
            </div>

            {{-- Content Blocks --}}
            @if (!empty($blocks))
                <div class="cms-content-show__blocks">
                    <h2 class="cms-content-show__section-title">Content Blocks</h2>
                    @foreach ($blocks as $block)
                        <div class="cms-content-show__block">
                            <span class="cms-content-show__block-type">{{ ucfirst($block['type'] ?? 'text') }}</span>
                            <span class="cms-content-show__block-order">#{{ $block['sort_order'] ?? 0 }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Custom Field Values --}}
            @if (!empty($customFields))
                <div class="cms-content-show__custom-fields">
                    <h2 class="cms-content-show__section-title">Custom Fields</h2>
                    <dl class="cms-detail-list">
                        @foreach ($customFields as $field)
                            <dt class="cms-detail-list__term">{{ $field['field_key'] ?? '' }}</dt>
                            <dd class="cms-detail-list__value">{{ $field['value'] ?? '' }}</dd>
                        @endforeach
                    </dl>
                </div>
            @endif
        </article>

        {{-- Sidebar metadata --}}
        <aside class="cms-content-show__sidebar">
            {{-- Lock status --}}
            @if (isset($lock))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Lock Status</h3>
                    <div class="cms-sidebar-panel__body">
                        <p>Locked by <strong>{{ $lock['locked_by'] ?? 'unknown' }}</strong></p>
                        <p>Expires: <time datetime="{{ $lock['expires_at'] ?? '' }}">{{ $lock['expires_at'] ?? '' }}</time></p>
                    </div>
                </div>
            @endif

            {{-- Translations --}}
            @if (!empty($translations))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Translations</h3>
                    <div class="cms-sidebar-panel__body">
                        <ul class="cms-translation-list">
                            @foreach ($translations as $trans)
                                <li class="cms-translation-list__item">
                                    <a href="/admin/cms/content/{{ $content['id'] }}?locale={{ $trans['locale'] }}">
                                        <strong>{{ strtoupper($trans['locale']) }}</strong> — {{ $trans['title'] ?? '(Untitled)' }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{-- SEO --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">SEO</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Meta Title</dt>
                        <dd class="cms-detail-list__value">{{ $translation['meta_title'] ?? '(Not set)' }}</dd>
                        <dt class="cms-detail-list__term">Meta Description</dt>
                        <dd class="cms-detail-list__value">{{ $translation['meta_description'] ?? '(Not set)' }}</dd>
                    </dl>
                </div>
            </div>

            {{-- Revisions link --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">History</h3>
                <div class="cms-sidebar-panel__body">
                    <a href="/admin/cms/content/{{ $content['id'] }}/revisions" class="cms-btn cms-btn--outline cms-btn--full">
                        View Revisions
                    </a>
                </div>
            </div>
        </aside>
    </div>
</div>
@endsection
