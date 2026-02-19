@extends('cms::admin.layout')

@section('title', 'Products')

@section('cms-content')
<div class="cms-content-list">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Products</h1>
        <div class="cms-content-list__actions">
            @can('cms.commerce.products.create')
                <a href="/admin/cms/products/create" class="cms-btn cms-btn--primary">New Product</a>
            @endcan
        </div>
    </header>

    <div class="cms-content-list__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/products" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-status" class="cms-filter-form__label">Status</label>
                <select id="filter-status" name="status" class="cms-filter-form__select">
                    <option value="">All Statuses</option>
                    <option value="draft" @if (($filters['status'] ?? '') === 'draft') selected @endif>Draft</option>
                    <option value="active" @if (($filters['status'] ?? '') === 'active') selected @endif>Active</option>
                    <option value="archived" @if (($filters['status'] ?? '') === 'archived') selected @endif>Archived</option>
                </select>
            </div>

            <div class="cms-filter-form__group">
                <label for="filter-search" class="cms-filter-form__label">Search</label>
                <input type="search"
                       id="filter-search"
                       name="search"
                       value="{{ $filters['search'] ?? '' }}"
                       class="cms-filter-form__input"
                       placeholder="Search by name or SKU">
            </div>

            <button type="submit" class="cms-btn cms-btn--outline">Filter</button>
        </form>
    </div>

    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th">Name</th>
                <th class="cms-table__th">SKU</th>
                <th class="cms-table__th">Price</th>
                <th class="cms-table__th">Stock</th>
                <th class="cms-table__th">Status</th>
                <th class="cms-table__th">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($products))
                <tr>
                    <td colspan="6" class="cms-table__empty">No products found. Create your first product to get started.</td>
                </tr>
            @endif

            @foreach ($products as $product)
                <tr class="cms-table__row">
                    <td class="cms-table__td cms-table__td--title">
                        <a href="/admin/cms/products/{{ $product['id'] }}/edit" class="cms-content-list__link">
                            {{ $product['name'] ?? '(Unnamed)' }}
                        </a>
                    </td>
                    <td class="cms-table__td">
                        <code>{{ $product['sku'] ?? '' }}</code>
                    </td>
                    <td class="cms-table__td">
                        <?php
                        $__amount = ($product['price_amount'] ?? 0) / 100;
                        $__currency = strtoupper($product['price_currency'] ?? 'USD');
                        ?>
                        {{ number_format($__amount, 2) }} {{ $__currency }}
                    </td>
                    <td class="cms-table__td">
                        @if ($product['digital'] ?? false)
                            <span class="cms-badge cms-badge--info">Digital</span>
                        @elseif (($product['stock_quantity'] ?? null) === null)
                            <span class="cms-text--muted">Unlimited</span>
                        @else
                            {{ $product['stock_quantity'] }}
                        @endif
                    </td>
                    <td class="cms-table__td">
                        @include('cms::admin._partials.status-badge', ['status' => $product['status'] ?? 'draft'])
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Product actions">
                            @can('cms.commerce.products.edit')
                                <a href="/admin/cms/products/{{ $product['id'] }}/edit" class="cms-btn cms-btn--sm cms-btn--outline" title="Edit">Edit</a>
                            @endcan
                            @can('cms.commerce.products.delete')
                                <form method="POST" action="/admin/cms/products/{{ $product['id'] }}" class="cms-inline-form" data-cms-confirm="Are you sure you want to delete this product?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger" title="Delete">Delete</button>
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
        'baseUrl' => '/admin/cms/products',
    ])
</div>
@endsection
