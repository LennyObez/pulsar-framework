@extends('admin.layout')

@section('title', 'Revision History')

@section('content')
<div class="cms-revision-list">
    <header class="cms-revision-list__header">
        <h1 class="cms-revision-list__title">Revision History</h1>
        <div class="cms-revision-list__actions">
            <a href="/admin/cms/content/{{ $contentId }}/edit" class="cms-btn cms-btn--outline">Back to Editor</a>
            <a href="/admin/cms/content/{{ $contentId }}" class="cms-btn cms-btn--outline">View Content</a>
        </div>
    </header>

    @if (isset($locales) && count($locales) > 1)
        @include('cms::admin._partials.locale-tabs', [
            'locales' => $locales,
            'activeLocale' => $activeLocale ?? 'en',
            'baseUrl' => '/admin/cms/content/' . $contentId . '/revisions',
        ])
    @endif

    <div class="cms-revision-timeline" role="list" aria-label="Revision timeline">
        @if (empty($revisions))
            <p class="cms-widget__empty">No revisions recorded for this content in the selected locale.</p>
        @endif

        @foreach ($revisions as $revision)
            <div class="cms-revision-timeline__entry" role="listitem" data-cms-revision-id="{{ $revision['id'] }}">
                <div class="cms-revision-timeline__marker">
                    <span class="cms-revision-timeline__number">{{ $revision['revision_number'] ?? '' }}</span>
                </div>
                <div class="cms-revision-timeline__content">
                    <div class="cms-revision-timeline__header">
                        <h3 class="cms-revision-timeline__title">
                            Revision #{{ $revision['revision_number'] ?? '' }}
                            @if ($loop->first)
                                <span class="cms-badge cms-badge--published">Current</span>
                            @endif
                        </h3>
                        <time datetime="{{ $revision['created_at'] ?? '' }}" class="cms-revision-timeline__date">
                            {{ $revision['created_at_human'] ?? $revision['created_at'] ?? '' }}
                        </time>
                    </div>

                    <dl class="cms-detail-list cms-detail-list--compact">
                        <dt class="cms-detail-list__term">Title</dt>
                        <dd class="cms-detail-list__value">{{ $revision['title'] ?? '' }}</dd>

                        <dt class="cms-detail-list__term">Author</dt>
                        <dd class="cms-detail-list__value">{{ $revision['author_name'] ?? $revision['author_id'] ?? '' }}</dd>

                        @if (!empty($revision['reason']))
                            <dt class="cms-detail-list__term">Change Reason</dt>
                            <dd class="cms-detail-list__value">{{ $revision['reason'] }}</dd>
                        @endif

                        <dt class="cms-detail-list__term">Evidence Hash</dt>
                        <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($revision['evidence_hash'] ?? '', 0, 16) }}...</code></dd>
                    </dl>

                    <div class="cms-revision-timeline__actions">
                        @if (!$loop->first)
                            @can('cms.content.restore')
                                <form method="POST"
                                      action="/admin/cms/content/{{ $contentId }}/revisions/{{ $revision['id'] }}/restore"
                                      class="cms-inline-form"
                                      data-cms-confirm="Restore this revision? The current content will be overwritten.">
                                    @csrf
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Restore This Revision</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
