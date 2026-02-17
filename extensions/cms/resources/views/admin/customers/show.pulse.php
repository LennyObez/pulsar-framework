@extends('admin.layout')

@section('title', $customer['display_name'] ?? @t('admin.customers.detail'))

@section('content')
<div class="cms-content">
    <header class="cms-content__header">
        <div class="cms-content__breadcrumbs">
            <a href="/admin/cms/customers" class="cms-breadcrumb__link">@t('admin.customers.title')</a>
            <span class="cms-breadcrumb__separator">/</span>
            <span class="cms-breadcrumb__current">{{ $customer['display_name'] }}</span>
        </div>
        <h1 class="cms-content__title">
            <span class="pui-avatar pui-avatar--lg" aria-hidden="true">{{ strtoupper(substr($customer['display_name'] ?? '?', 0, 1)) }}</span>
            {{ $customer['display_name'] }}
        </h1>
    </header>

    <div class="pui-stat-grid pui-mb-6">
        <div class="pui-stat">
            <span class="pui-stat__label">@t('admin.customers.member_since')</span>
            <span class="pui-stat__value">{{ $stats['member_since'] ?? '--' }}</span>
        </div>
        @if (isset($stats['total_orders']))
            <div class="pui-stat">
                <span class="pui-stat__label">@t('admin.customers.total_orders')</span>
                <span class="pui-stat__value">{{ $stats['total_orders'] }}</span>
            </div>
        @endif
        @if (isset($stats['total_spent']))
            <div class="pui-stat">
                <span class="pui-stat__label">@t('admin.customers.total_spent')</span>
                <span class="pui-stat__value">{{ number_format($stats['total_spent'] / 100, 2) }} {{ $stats['currency'] ?? 'EUR' }}</span>
            </div>
        @endif
    </div>

    <div class="cms-tabs" role="tablist">
        <a href="/admin/cms/customers/{{ $customer['id'] }}?section=overview"
           class="cms-tabs__tab @if (($active_section ?? 'overview') === 'overview') cms-tabs__tab--active @endif"
           role="tab">@t('admin.customers.overview')</a>

        @foreach ($sections ?? [] as $section)
            <a href="/admin/cms/customers/{{ $customer['id'] }}?section={{ $section->id }}"
               class="cms-tabs__tab @if (($active_section ?? '') === $section->id) cms-tabs__tab--active @endif"
               role="tab">
                {{ $section->label }}
                @if ($section->badgeCount !== null)
                    <span class="cms-badge cms-badge--sm">{{ $section->badgeCount }}</span>
                @endif
            </a>
        @endforeach

        <a href="/admin/cms/customers/{{ $customer['id'] }}?section=notes"
           class="cms-tabs__tab @if (($active_section ?? '') === 'notes') cms-tabs__tab--active @endif"
           role="tab">@t('admin.customers.notes')</a>
    </div>

    <div class="cms-tabs__panel" role="tabpanel">
        @if (($active_section ?? 'overview') === 'overview')
            <div class="pui-grid pui-grid--cols-2 pui-gap-6">
                <div class="pui-card">
                    <div class="pui-card__header">@t('admin.customers.contact_info')</div>
                    <div class="pui-card__body">
                        <dl class="cms-dl">
                            <dt>@t('admin.customers.email')</dt>
                            <dd>{{ $customer['email'] }}</dd>
                            <dt>@t('admin.customers.user_id')</dt>
                            <dd>{{ $customer['user_id'] ?? @t('admin.customers.no_linked_user') }}</dd>
                            <dt>@t('admin.customers.tenant')</dt>
                            <dd>{{ $customer['tenant_id'] ?? @t('admin.customers.single_tenant') }}</dd>
                        </dl>
                    </div>
                </div>

                <div class="pui-card">
                    <div class="pui-card__header">@t('admin.customers.billing_address')</div>
                    <div class="pui-card__body">
                        @if (!empty($customer['billing_address']))
                            <address class="cms-address">
                                {{ $customer['billing_address']['line1'] ?? '' }}<br>
                                @if (!empty($customer['billing_address']['line2']))
                                    {{ $customer['billing_address']['line2'] }}<br>
                                @endif
                                {{ $customer['billing_address']['city'] ?? '' }}
                                {{ $customer['billing_address']['postalCode'] ?? '' }}<br>
                                {{ $customer['billing_address']['country'] ?? '' }}
                            </address>
                        @else
                            <p class="pui-card__text">@t('admin.customers.no_address')</p>
                        @endif
                    </div>
                </div>
            </div>

        @elseif (($active_section ?? '') === 'notes')
            <div class="pui-card pui-mb-4">
                <div class="pui-card__header">@t('admin.customers.add_note')</div>
                <div class="pui-card__body">
                    <form method="POST" action="/admin/cms/customers/{{ $customer['id'] }}/notes">
                        @csrf
                        <div class="pui-form-group pui-mb-4">
                            <label for="note" class="pui-form-group__label">@t('admin.customers.note_content')</label>
                            <textarea id="note" name="note" class="pui-input" rows="3" required minlength="1"></textarea>
                        </div>
                        <button type="submit" class="pui-btn pui-btn--primary pui-btn--sm">@t('admin.customers.save_note')</button>
                    </form>
                </div>
            </div>

            @if (!empty($customer['notes']))
                <div class="pui-card">
                    <div class="pui-card__header">@t('admin.customers.note_history')</div>
                    <div class="pui-card__body">
                        <pre class="cms-notes">{{ $customer['notes'] }}</pre>
                    </div>
                </div>
            @endif

        @else
            {!! $section_html !!}
        @endif
    </div>
</div>
@endsection
