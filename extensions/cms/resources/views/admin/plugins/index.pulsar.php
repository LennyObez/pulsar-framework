@extends('admin.layout')

@section('title', 'Plugins')

@section('content')
<div class="cms-plugins">
    <header class="cms-plugins__header">
        <h1 class="cms-plugins__title">Installed Plugins</h1>
        <div class="cms-plugins__actions">
            @can('cms.plugins.install')
                <a href="/admin/cms/plugins/install" class="cms-btn cms-btn--primary">Install Plugin</a>
            @endcan
            <a href="/admin/cms" class="cms-btn cms-btn--outline">Back to Dashboard</a>
        </div>
    </header>

    <table class="cms-table" data-cms-sortable-table>
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="name">Name</th>
                <th class="cms-table__th" scope="col">Version</th>
                <th class="cms-table__th" scope="col">Capabilities</th>
                <th class="cms-table__th" scope="col">Status</th>
                <th class="cms-table__th" scope="col">Provenance</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($plugins))
                <tr>
                    <td colspan="6" class="cms-table__empty">No plugins installed. Install your first plugin to extend your site.</td>
                </tr>
            @endif

            @foreach ($plugins as $plugin)
                <tr class="cms-table__row" data-cms-plugin-id="{{ $plugin['id'] }}">
                    <td class="cms-table__td cms-table__td--title">
                        <strong>{{ $plugin['display_name'] ?? $plugin['slug'] ?? '' }}</strong>
                        @if (isset($plugin['description']))
                            <br><small class="cms-plugins__description">{{ mb_strimwidth($plugin['description'], 0, 80, '...') }}</small>
                        @endif
                    </td>
                    <td class="cms-table__td">{{ $plugin['version'] ?? '0.0.0' }}</td>
                    <td class="cms-table__td">
                        <div class="cms-tag-list cms-tag-list--inline">
                            @foreach ($plugin['capabilities'] ?? [] as $capability)
                                <span class="cms-badge cms-badge--in-review">{{ $capability }}</span>
                            @endforeach
                            @if (empty($plugin['capabilities'] ?? []))
                                <span class="cms-plugins__no-caps">None</span>
                            @endif
                        </div>
                    </td>
                    <td class="cms-table__td">
                        @if ($plugin['enabled'] ?? false)
                            <span class="cms-badge cms-badge--published" role="status">Enabled</span>
                        @else
                            <span class="cms-badge cms-badge--draft" role="status">Disabled</span>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        @if ($plugin['signature_verified'] ?? false)
                            <span class="cms-badge cms-badge--approved" title="Cryptographically signed and verified">Signed</span>
                        @else
                            <span class="cms-badge cms-badge--archived" title="Plugin package is not signed">Unsigned</span>
                        @endif
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Plugin actions">
                            @can('cms.plugins.manage')
                                @if ($plugin['enabled'] ?? false)
                                    <form method="POST" action="/admin/cms/plugins/{{ $plugin['id'] }}/disable" class="cms-inline-form" data-cms-confirm="Disable this plugin? Features provided by this plugin will become unavailable.">
                                        @csrf
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--warning">Disable</button>
                                    </form>
                                @else
                                    <form method="POST" action="/admin/cms/plugins/{{ $plugin['id'] }}/enable" class="cms-inline-form">
                                        @csrf
                                        <button type="submit" class="cms-btn cms-btn--sm cms-btn--success">Enable</button>
                                    </form>
                                @endif
                            @endcan

                            @if (!empty($plugin['has_settings'] ?? false))
                                @can('cms.plugins.manage')
                                    <a href="/admin/cms/plugins/{{ $plugin['id'] }}/settings" class="cms-btn cms-btn--sm cms-btn--outline">Settings</a>
                                @endcan
                            @endif

                            @can('cms.plugins.delete')
                                <form method="POST" action="/admin/cms/plugins/{{ $plugin['id'] }}" class="cms-inline-form" data-cms-confirm="Delete this plugin? All plugin data will be permanently removed." data-cms-confirm-reason>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('cms::admin._partials.pagination', [
        'page' => $pagination['page'] ?? 1,
        'perPage' => $pagination['per_page'] ?? 20,
        'total' => $pagination['total'] ?? 0,
        'baseUrl' => '/admin/cms/plugins',
    ])
</div>

@include('cms::admin._partials.confirm-modal')
@endsection
