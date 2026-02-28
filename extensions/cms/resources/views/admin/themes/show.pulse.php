@extends('admin.layout')

@section('title', $theme['display_name'] ?? 'Theme Details')

@section('content')
<div class="cms-theme-show">
    <header class="cms-theme-show__header">
        <div class="cms-theme-show__meta">
            <h1 class="cms-theme-show__title">{{ $theme['display_name'] ?? $theme['slug'] ?? '' }}</h1>
            <span class="cms-theme-show__version">v{{ $theme['version'] ?? '0.0.0' }}</span>
            @if ($theme['is_active'] ?? false)
                <span class="cms-badge cms-badge--published" role="status">Active</span>
            @else
                <span class="cms-badge cms-badge--draft" role="status">Inactive</span>
            @endif
        </div>
        <div class="cms-theme-show__actions">
            @if (!($theme['is_active'] ?? false))
                @can('cms.themes.manage')
                    <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}/activate" class="cms-inline-form" data-cms-confirm="Activate this theme? The current active theme will be deactivated.">
                        @csrf
                        <button type="submit" class="cms-btn cms-btn--primary">Activate</button>
                    </form>
                @endcan
            @else
                @can('cms.themes.manage')
                    <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}/deactivate" class="cms-inline-form" data-cms-confirm="Deactivate this theme?">
                        @csrf
                        <button type="submit" class="cms-btn cms-btn--warning">Deactivate</button>
                    </form>
                @endcan
            @endif

            @can('cms.themes.view')
                <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}/preview" class="cms-inline-form">
                    @csrf
                    <button type="submit" class="cms-btn cms-btn--outline">Preview</button>
                </form>
            @endcan

            @if (!($theme['is_active'] ?? false))
                @can('cms.themes.delete')
                    <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}" class="cms-inline-form" data-cms-confirm="Delete this theme? This cannot be undone." data-cms-confirm-reason>
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="cms-btn cms-btn--danger">Delete</button>
                    </form>
                @endcan
            @endif

            <a href="/admin/cms/themes" class="cms-btn cms-btn--outline">Back to Themes</a>
        </div>
    </header>

    <div class="cms-theme-show__layout">
        {{-- Main details --}}
        <div class="cms-theme-show__main">
            {{-- Theme info --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Theme Details</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Slug</dt>
                        <dd class="cms-detail-list__value"><code>{{ $theme['slug'] ?? '' }}</code></dd>

                        <dt class="cms-detail-list__term">Display Name</dt>
                        <dd class="cms-detail-list__value">{{ $theme['display_name'] ?? '' }}</dd>

                        <dt class="cms-detail-list__term">Version</dt>
                        <dd class="cms-detail-list__value">{{ $theme['version'] ?? '' }}</dd>

                        @if (isset($theme['description']))
                            <dt class="cms-detail-list__term">Description</dt>
                            <dd class="cms-detail-list__value">{{ $theme['description'] }}</dd>
                        @endif

                        @if (isset($theme['author_name']))
                            <dt class="cms-detail-list__term">Author</dt>
                            <dd class="cms-detail-list__value">
                                @if (isset($theme['author_url']) && $theme['author_url'] !== null)
                                    <a href="{{ $theme['author_url'] }}" target="_blank" rel="noopener noreferrer">{{ $theme['author_name'] }}</a>
                                @else
                                    {{ $theme['author_name'] }}
                                @endif
                            </dd>
                        @endif

                        @if (isset($theme['license']))
                            <dt class="cms-detail-list__term">License</dt>
                            <dd class="cms-detail-list__value">{{ $theme['license'] }}</dd>
                        @endif

                        <dt class="cms-detail-list__term">Installed By</dt>
                        <dd class="cms-detail-list__value">{{ $theme['installed_by_name'] ?? $theme['installed_by'] ?? '' }}</dd>

                        <dt class="cms-detail-list__term">Installed</dt>
                        <dd class="cms-detail-list__value">
                            <time datetime="{{ $theme['installed_at'] ?? '' }}">{{ $theme['installed_at_human'] ?? $theme['installed_at'] ?? '' }}</time>
                        </dd>

                        @if (isset($theme['activated_at']))
                            <dt class="cms-detail-list__term">Last Activated</dt>
                            <dd class="cms-detail-list__value">
                                <time datetime="{{ $theme['activated_at'] }}">{{ $theme['activated_at_human'] ?? $theme['activated_at'] }}</time>
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Templates --}}
            @if (!empty($templates))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Templates</h3>
                    <div class="cms-sidebar-panel__body">
                        <table class="cms-table cms-table--compact">
                            <thead class="cms-table__head">
                                <tr>
                                    <th class="cms-table__th" scope="col">Template</th>
                                    <th class="cms-table__th" scope="col">Content Type</th>
                                </tr>
                            </thead>
                            <tbody class="cms-table__body">
                                @foreach ($templates as $template)
                                    <tr class="cms-table__row">
                                        <td class="cms-table__td"><code>{{ $template['name'] ?? '' }}</code></td>
                                        <td class="cms-table__td">{{ $template['content_type'] ?? 'General' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Assets --}}
            @if (!empty($assets))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Assets</h3>
                    <div class="cms-sidebar-panel__body">
                        <table class="cms-table cms-table--compact">
                            <thead class="cms-table__head">
                                <tr>
                                    <th class="cms-table__th" scope="col">Name</th>
                                    <th class="cms-table__th" scope="col">Path</th>
                                </tr>
                            </thead>
                            <tbody class="cms-table__body">
                                @foreach ($assets as $assetName => $assetPath)
                                    <tr class="cms-table__row">
                                        <td class="cms-table__td"><code>{{ $assetName }}</code></td>
                                        <td class="cms-table__td"><code>{{ $assetPath }}</code></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Editable tokens / settings --}}
            @if (!empty($settings))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Theme Settings</h3>
                    <div class="cms-sidebar-panel__body">
                        <table class="cms-table cms-table--compact">
                            <thead class="cms-table__head">
                                <tr>
                                    <th class="cms-table__th" scope="col">Setting</th>
                                    <th class="cms-table__th" scope="col">Value</th>
                                </tr>
                            </thead>
                            <tbody class="cms-table__body">
                                @foreach ($settings as $settingKey => $settingValue)
                                    <tr class="cms-table__row">
                                        <td class="cms-table__td"><code>{{ $settingKey }}</code></td>
                                        <td class="cms-table__td">{{ is_string($settingValue) ? $settingValue : json_encode($settingValue) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- Provenance sidebar --}}
        <aside class="cms-theme-show__sidebar">
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Provenance</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Package Hash</dt>
                        <dd class="cms-detail-list__value">
                            @if ($theme['provenance_verified'] ?? false)
                                <span class="cms-badge cms-badge--approved">Verified</span>
                            @else
                                <span class="cms-badge cms-badge--archived">Not Verified</span>
                            @endif
                        </dd>

                        <dt class="cms-detail-list__term">Signature</dt>
                        <dd class="cms-detail-list__value">
                            @if ($theme['signature_verified'] ?? false)
                                <span class="cms-badge cms-badge--approved">Valid Signature</span>
                            @else
                                <span class="cms-badge cms-badge--in-review">Unsigned</span>
                            @endif
                        </dd>

                        @if (isset($theme['manifest_hash']))
                            <dt class="cms-detail-list__term">Manifest Hash</dt>
                            <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($theme['manifest_hash'] ?? '', 0, 16) }}...</code></dd>
                        @endif

                        @if (isset($theme['package_hash']))
                            <dt class="cms-detail-list__term">Package Hash</dt>
                            <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($theme['package_hash'] ?? '', 0, 16) }}...</code></dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Regions --}}
            @if (!empty($regions))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Template Regions</h3>
                    <div class="cms-sidebar-panel__body">
                        <ul class="cms-tag-list">
                            @foreach ($regions as $region)
                                <li class="cms-tag-list__item"><code>{{ $region }}</code></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{-- Supported content types --}}
            @if (!empty($supportedContentTypes))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Supported Content Types</h3>
                    <div class="cms-sidebar-panel__body">
                        <ul class="cms-tag-list">
                            @foreach ($supportedContentTypes as $contentType)
                                <li class="cms-tag-list__item">{{ $contentType }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{-- Storage info --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Storage</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Path</dt>
                        <dd class="cms-detail-list__value"><code>{{ $theme['storage_path'] ?? '' }}</code></dd>
                    </dl>
                </div>
            </div>
        </aside>
    </div>
</div>

@include('cms::admin._partials.confirm-modal')
@endsection
