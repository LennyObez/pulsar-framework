@extends('admin.layout')

@section('title', 'Themes')

@section('content')
<div class="cms-themes">
    <header class="cms-themes__header">
        <h1 class="cms-themes__title">Installed Themes</h1>
        <div class="cms-themes__actions">
            @can('cms.themes.install')
                <a href="/admin/cms/themes/install" class="cms-btn cms-btn--primary">Upload &amp; Install</a>
            @endcan
            <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
        </div>
    </header>

    {{-- Theme card grid --}}
    <div class="cms-themes__grid">
        @if (empty($themes))
            <p class="cms-themes__empty">No themes installed. Upload your first theme to get started.</p>
        @endif

        @foreach ($themes as $theme)
            <div class="cms-themes__card" data-cms-theme-id="{{ $theme['id'] }}">
                <div class="cms-themes__card-header">
                    <h2 class="cms-themes__card-name">{{ $theme['display_name'] ?? $theme['slug'] ?? '' }}</h2>
                    <span class="cms-themes__card-version">v{{ $theme['version'] ?? '0.0.0' }}</span>
                </div>

                <div class="cms-themes__card-badges">
                    @if ($theme['is_active'] ?? false)
                        <span class="cms-badge cms-badge--published" role="status">Active</span>
                    @else
                        <span class="cms-badge cms-badge--draft" role="status">Inactive</span>
                    @endif

                    @if (($theme['provenance_verified'] ?? false) && ($theme['signature_verified'] ?? false))
                        <span class="cms-badge cms-badge--approved" title="Provenance and signature verified">Verified</span>
                    @elseif ($theme['provenance_verified'] ?? false)
                        <span class="cms-badge cms-badge--in-review" title="Hash verified but unsigned">Unverified</span>
                    @else
                        <span class="cms-badge cms-badge--archived" title="Provenance verification failed">Unverified</span>
                    @endif
                </div>

                <p class="cms-themes__card-description">
                    {{ mb_strimwidth($theme['description'] ?? 'No description available.', 0, 150, '...') }}
                </p>

                @if (isset($theme['author_name']))
                    <p class="cms-themes__card-author">
                        By
                        @if (isset($theme['author_url']) && $theme['author_url'] !== null)
                            <a href="{{ $theme['author_url'] }}" target="_blank" rel="noopener noreferrer">{{ $theme['author_name'] }}</a>
                        @else
                            {{ $theme['author_name'] }}
                        @endif
                    </p>
                @endif

                <div class="cms-themes__card-meta">
                    <span class="cms-themes__card-installed">
                        Installed <time datetime="{{ $theme['installed_at'] ?? '' }}">{{ $theme['installed_at_human'] ?? $theme['installed_at'] ?? '' }}</time>
                    </span>
                </div>

                <div class="cms-themes__card-actions">
                    @if (!($theme['is_active'] ?? false))
                        @can('cms.themes.manage')
                            <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}/activate" class="cms-inline-form" data-cms-confirm="Activate this theme? The current active theme will be deactivated.">
                                @csrf
                                <button type="submit" class="cms-btn cms-btn--sm cms-btn--primary">Activate</button>
                            </form>
                        @endcan
                    @else
                        @can('cms.themes.manage')
                            <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}/deactivate" class="cms-inline-form" data-cms-confirm="Deactivate this theme? The site will fall back to the default theme.">
                                @csrf
                                <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Deactivate</button>
                            </form>
                        @endcan
                    @endif

                    @can('cms.themes.view')
                        <a href="/admin/cms/themes/{{ $theme['id'] }}" class="cms-btn cms-btn--sm cms-btn--outline">Details</a>
                    @endcan

                    @can('cms.themes.view')
                        <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}/preview" class="cms-inline-form">
                            @csrf
                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--outline">Preview</button>
                        </form>
                    @endcan

                    @if (!($theme['is_active'] ?? false))
                        @can('cms.themes.delete')
                            <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}" class="cms-inline-form" data-cms-confirm="Delete this theme? This cannot be undone." data-cms-confirm-reason>
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Delete</button>
                            </form>
                        @endcan
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>

@include('cms::admin._partials.confirm-modal')
@endsection
