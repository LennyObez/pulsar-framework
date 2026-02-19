@extends('admin.layout')

@section('title', 'Menus')

@section('content')
<div class="cms-menu-list">
    <header class="cms-menu-list__header">
        <h1 class="cms-menu-list__title">Menus</h1>
        @can('cms.menus.manage')
            <a href="/admin/cms/menus/create" class="cms-btn cms-btn--primary">Create Menu</a>
        @endcan
    </header>

    @if (isset($locales) && count($locales) > 1)
        @include('cms::admin._partials.locale-tabs', [
            'locales' => $locales,
            'activeLocale' => $activeLocale ?? 'en',
            'baseUrl' => '/admin/cms/menus',
        ])
    @endif

    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th">Location</th>
                <th class="cms-table__th">Items</th>
                <th class="cms-table__th">Created</th>
                <th class="cms-table__th">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($menus))
                <tr>
                    <td colspan="4" class="cms-table__empty">No menus found. Create your first menu to define site navigation.</td>
                </tr>
            @endif

            @foreach ($menus as $menu)
                <tr class="cms-table__row">
                    <td class="cms-table__td cms-table__td--title">
                        <a href="/admin/cms/menus/{{ $menu['location'] }}/edit">{{ $menu['location'] ?? '' }}</a>
                    </td>
                    <td class="cms-table__td">{{ $menu['item_count'] ?? 0 }}</td>
                    <td class="cms-table__td">
                        <time datetime="{{ $menu['created_at'] ?? '' }}">{{ $menu['created_at_human'] ?? $menu['created_at'] ?? '' }}</time>
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Menu actions">
                            <a href="/admin/cms/menus/{{ $menu['location'] }}/edit" class="cms-btn cms-btn--sm cms-btn--outline">Edit</a>
                            @can('cms.menus.manage')
                                <form method="POST" action="/admin/cms/menus/{{ $menu['location'] }}" class="cms-inline-form" data-cms-confirm="Are you sure you want to delete this menu?">
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
</div>
@endsection
