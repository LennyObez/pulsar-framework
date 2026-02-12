@extends('cms::admin.layout')

@section('title', 'Promotions')

@section('cms-content')
<div class="cms-content-list">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Promotions</h1>
        <div class="cms-content-list__actions">
            @can('cms.commerce.promotions.create')
                <a href="/admin/cms/promotions/create" class="cms-btn cms-btn--primary">New Promotion</a>
            @endcan
        </div>
    </header>

    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">Name</th>
                <th class="cms-table__th" scope="col">Type</th>
                <th class="cms-table__th" scope="col">Value</th>
                <th class="cms-table__th" scope="col">Dates</th>
                <th class="cms-table__th" scope="col">Uses</th>
                <th class="cms-table__th" scope="col">Active</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($promotions))
                <tr>
                    <td colspan="7" class="cms-table__empty">No promotions found. Create your first promotion to get started.</td>
                </tr>
            @endif

            @foreach ($promotions as $promotion)
                <tr class="cms-table__row">
                    <td class="cms-table__td cms-table__td--title">
                        <a href="/admin/cms/promotions/{{ $promotion['id'] }}/edit" class="cms-content-list__link">
                            {{ $promotion['name'] ?? '' }}
                        </a>
                    </td>
                    <td class="cms-table__td">
                        <span class="cms-badge cms-badge--info">{{ ucwords(str_replace('_', ' ', $promotion['type'] ?? '')) }}</span>
                    </td>
                    <td class="cms-table__td">
                        <?php
                        $__type = $promotion['type'] ?? '';
                        $__value = $promotion['value'] ?? 0;
                        if ($__type === 'percentage') {
                            echo htmlspecialchars((string) $__value, ENT_QUOTES, 'UTF-8') . '%';
                        } elseif ($__type === 'fixed_amount') {
                            echo htmlspecialchars(number_format($__value / 100, 2), ENT_QUOTES, 'UTF-8');
                        } elseif ($__type === 'buy_x_get_y') {
                            echo 'Buy ' . htmlspecialchars((string) $__value, ENT_QUOTES, 'UTF-8') . ' get 1';
                        } else {
                            echo htmlspecialchars((string) $__value, ENT_QUOTES, 'UTF-8');
                        }
                        ?>
                    </td>
                    <td class="cms-table__td">
                        @if ($promotion['starts_at'] ?? null)
                            <time datetime="{{ $promotion['starts_at'] }}">{{ $promotion['starts_at'] }}</time>
                        @else
                            <span class="cms-text--muted">No start</span>
                        @endif
                        &mdash;
                        @if ($promotion['expires_at'] ?? null)
                            <time datetime="{{ $promotion['expires_at'] }}">{{ $promotion['expires_at'] }}</time>
                        @else
                            <span class="cms-text--muted">No end</span>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        {{ $promotion['current_uses'] ?? 0 }}
                        @if (($promotion['max_uses'] ?? null) !== null)
                            / {{ $promotion['max_uses'] }}
                        @else
                            / <span class="cms-text--muted">unlimited</span>
                        @endif
                    </td>
                    <td class="cms-table__td">
                        @if ($promotion['is_active'] ?? false)
                            <span class="cms-badge cms-badge--published">Active</span>
                        @else
                            <span class="cms-badge cms-badge--archived">Inactive</span>
                        @endif
                    </td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Promotion actions">
                            @can('cms.commerce.promotions.edit')
                                <a href="/admin/cms/promotions/{{ $promotion['id'] }}/edit" class="cms-btn cms-btn--sm cms-btn--outline" title="Edit">Edit</a>
                            @endcan
                            @can('cms.commerce.promotions.delete')
                                <form method="POST" action="/admin/cms/promotions/{{ $promotion['id'] }}" class="cms-inline-form" data-cms-confirm="Are you sure you want to deactivate this promotion?">
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
</div>
@endsection
