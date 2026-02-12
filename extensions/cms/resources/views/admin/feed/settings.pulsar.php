@extends('admin.layout')

@section('title', 'Feed Settings')

@section('content')
<div class="cms-feed-settings">
    <header class="cms-feed-settings__header">
        <h1 class="cms-feed-settings__title">Feed Settings</h1>
        <a href="/admin/cms/settings" class="cms-btn cms-btn--outline">Back to Settings</a>
    </header>

    <p class="cms-feed-settings__description">
        Configure RSS and Atom feed generation for each content type.
        Feeds are automatically generated and cached for 60 minutes.
    </p>

    <form method="POST" action="/admin/cms/feed/settings" class="cms-form" data-cms-feed-settings>
        @csrf

        <table class="cms-table">
            <thead class="cms-table__head">
                <tr>
                    <th class="cms-table__th" scope="col">Content Type</th>
                    <th class="cms-table__th" scope="col">RSS Feed</th>
                    <th class="cms-table__th" scope="col">Atom Feed</th>
                    <th class="cms-table__th" scope="col">Items per Feed</th>
                    <th class="cms-table__th" scope="col">Feed URL</th>
                </tr>
            </thead>
            <tbody class="cms-table__body">
                @if (empty($contentTypes))
                    <tr>
                        <td colspan="5" class="cms-table__empty">No content types configured.</td>
                    </tr>
                @endif

                @foreach ($contentTypes ?? [] as $type)
                    <tr class="cms-table__row">
                        <td class="cms-table__td">
                            <strong>{{ $type['label'] ?? $type['slug'] ?? '' }}</strong>
                            <br>
                            <code class="cms-code">{{ $type['slug'] ?? '' }}</code>
                        </td>
                        <td class="cms-table__td">
                            <label class="cms-toggle">
                                <input type="checkbox"
                                       name="feeds[{{ $type['slug'] ?? '' }}][rss]"
                                       value="1"
                                       @if ($type['rss_enabled'] ?? false) checked @endif>
                                <span class="cms-toggle__slider"></span>
                                <span class="cms-sr-only">Enable RSS feed for {{ $type['label'] ?? $type['slug'] ?? '' }}</span>
                            </label>
                        </td>
                        <td class="cms-table__td">
                            <label class="cms-toggle">
                                <input type="checkbox"
                                       name="feeds[{{ $type['slug'] ?? '' }}][atom]"
                                       value="1"
                                       @if ($type['atom_enabled'] ?? false) checked @endif>
                                <span class="cms-toggle__slider"></span>
                                <span class="cms-sr-only">Enable Atom feed for {{ $type['label'] ?? $type['slug'] ?? '' }}</span>
                            </label>
                        </td>
                        <td class="cms-table__td">
                            <input type="number"
                                   name="feeds[{{ $type['slug'] ?? '' }}][limit]"
                                   value="{{ $type['feed_limit'] ?? 20 }}"
                                   min="1"
                                   max="100"
                                   class="cms-form-group__input cms-form-group__input--sm"
                                   style="width: 80px;"
                                   aria-label="Items per feed for {{ $type['label'] ?? $type['slug'] ?? '' }}">
                        </td>
                        <td class="cms-table__td">
                            @if ($type['rss_enabled'] ?? false)
                                <a href="/feed/{{ $type['slug'] ?? '' }}/rss" class="cms-btn cms-btn--sm cms-btn--outline" target="_blank" rel="noopener">RSS</a>
                            @endif
                            @if ($type['atom_enabled'] ?? false)
                                <a href="/feed/{{ $type['slug'] ?? '' }}/atom" class="cms-btn cms-btn--sm cms-btn--outline" target="_blank" rel="noopener">Atom</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="cms-form-group cms-form-group--actions" style="margin-top: 24px;">
            <button type="submit" class="cms-btn cms-btn--primary">Save Feed Settings</button>
        </div>
    </form>
</div>
@endsection
