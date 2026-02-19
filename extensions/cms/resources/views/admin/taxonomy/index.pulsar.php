@extends('admin.layout')

@section('title', 'Taxonomies')

@section('content')
<div class="cms-taxonomy-list">
    <header class="cms-taxonomy-list__header">
        <h1 class="cms-taxonomy-list__title">Taxonomies</h1>
        @can('cms.taxonomy.manage')
            <a href="/admin/cms/taxonomy/create" class="cms-btn cms-btn--primary">Create Taxonomy</a>
        @endcan
    </header>

    @if (isset($locales) && count($locales) > 1)
        @include('cms::admin._partials.locale-tabs', [
            'locales' => $locales,
            'activeLocale' => $activeLocale ?? 'en',
            'baseUrl' => '/admin/cms/taxonomy',
        ])
    @endif

    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">Name</th>
                <th class="cms-table__th" scope="col">Slug</th>
                <th class="cms-table__th" scope="col">Type</th>
                <th class="cms-table__th" scope="col">Terms</th>
                <th class="cms-table__th" scope="col">Created</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($taxonomies))
                <tr>
                    <td colspan="6" class="cms-table__empty">No taxonomies found. Create your first taxonomy to organize content.</td>
                </tr>
            @endif

            @foreach ($taxonomies as $taxonomy)
                <tr class="cms-table__row">
                    <td class="cms-table__td cms-table__td--title">
                        <a href="/admin/cms/taxonomy/{{ $taxonomy['slug'] }}">{{ $taxonomy['name'] ?? $taxonomy['slug'] }}</a>
                    </td>
                    <td class="cms-table__td"><code>{{ $taxonomy['slug'] }}</code></td>
                    <td class="cms-table__td">
                        <span class="cms-type-label">{{ ($taxonomy['hierarchical'] ?? false) ? 'Hierarchical' : 'Flat' }}</span>
                    </td>
                    <td class="cms-table__td">{{ $taxonomy['term_count'] ?? 0 }}</td>
                    <td class="cms-table__td">
                        <time datetime="{{ $taxonomy['created_at'] ?? '' }}">{{ $taxonomy['created_at_human'] ?? $taxonomy['created_at'] ?? '' }}</time>
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Taxonomy actions">
                            <a href="/admin/cms/taxonomy/{{ $taxonomy['slug'] }}/edit" class="cms-btn cms-btn--sm cms-btn--outline">Edit</a>
                            @can('cms.taxonomy.manage')
                                <form method="POST" action="/admin/cms/taxonomy/{{ $taxonomy['slug'] }}" class="cms-inline-form" data-cms-confirm="Are you sure you want to delete this taxonomy and all its terms?">
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
