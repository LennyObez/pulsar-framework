@extends('admin.layout')

@section('title', @t('admin.customers.title'))

@section('content')
<div class="cms-content">
    <header class="cms-content__header">
        <h1 class="cms-content__title">@t('admin.customers.title')</h1>
    </header>

    <div class="cms-content__filters" data-cms-filter-bar>
        <form method="GET" action="/admin/cms/customers" class="cms-filter-form">
            <div class="cms-filter-form__group">
                <label for="filter-search" class="cms-filter-form__label">@t('admin.customers.search')</label>
                <input type="search" id="filter-search" name="search" value="{{ $search ?? '' }}" class="cms-filter-form__input" placeholder="@t('admin.customers.search_placeholder')">
            </div>
            <button type="submit" class="cms-btn cms-btn--outline">@t('admin.filter')</button>
        </form>
    </div>

    <table class="cms-table" data-cms-sortable-table>
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">@t('admin.customers.name')</th>
                <th class="cms-table__th" scope="col">@t('admin.customers.email')</th>
                <th class="cms-table__th" scope="col">@t('admin.customers.linked')</th>
                <th class="cms-table__th cms-table__th--sortable" scope="col" data-cms-sort="created_at">@t('admin.customers.joined')</th>
                <th class="cms-table__th" scope="col">@t('admin.actions')</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($customers))
                <tr>
                    <td colspan="5" class="cms-table__empty">@t('admin.customers.none_found')</td>
                </tr>
            @endif

            @foreach ($customers as $customer)
                <tr class="cms-table__row">
                    <td class="cms-table__td cms-table__td--title">
                        <div class="cms-user-cell">
                            <span class="pui-avatar pui-avatar--sm" aria-hidden="true">{{ strtoupper(substr($customer['display_name'] ?? '?', 0, 1)) }}</span>
                            <span>{{ $customer['display_name'] }}</span>
                        </div>
                    </td>
                    <td class="cms-table__td">{{ $customer['email'] }}</td>
                    <td class="cms-table__td">
                        @if ($customer['has_linked_user'])
                            <span class="cms-badge cms-badge--published">@t('admin.customers.linked_yes')</span>
                        @else
                            <span class="cms-badge cms-badge--draft">@t('admin.customers.guest')</span>
                        @endif
                    </td>
                    <td class="cms-table__td">{{ date('M j, Y', strtotime($customer['created_at'])) }}</td>
                    <td class="cms-table__td">
                        <a href="/admin/cms/customers/{{ $customer['id'] }}" class="cms-btn cms-btn--sm cms-btn--outline">@t('admin.view')</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (!empty($pagination))
        @include('admin.partials.pagination', ['pagination' => $pagination])
    @endif
</div>
@endsection
