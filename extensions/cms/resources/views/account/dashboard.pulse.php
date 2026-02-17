@extends('account.layout')

@section('title', @t('account.dashboard'))

@section('content')
<h1 class="pui-heading pui-heading--xl">@t('account.dashboard')</h1>

<div class="pui-stat-grid">
    <div class="pui-stat">
        <span class="pui-stat__label">@t('account.member_since')</span>
        <span class="pui-stat__value">{{ $customer['created_at'] ? date('M j, Y', strtotime($customer['created_at'])) : '--' }}</span>
    </div>
</div>

@if (!empty($sections))
    <h2 class="pui-heading pui-heading--lg pui-mt-8 pui-mb-4">@t('account.quick_links')</h2>
    <div class="pui-card-grid">
        @foreach ($sections as $section)
            <a href="/account/section/{{ $section->id }}" class="pui-card pui-card--interactive">
                <div class="pui-card__body">
                    <h3 class="pui-card__title">{{ $section->label }}</h3>
                    @if ($section->badgeCount !== null)
                        <p class="pui-card__subtitle">{{ $section->badgeCount }} @t('account.items')</p>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
@endif
@endsection
