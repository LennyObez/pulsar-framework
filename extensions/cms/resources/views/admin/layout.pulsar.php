@extends('admin.layout')

@section('sidebar')
<nav class="cms-sidebar" aria-label="CMS Navigation">
    <ul class="cms-sidebar__list">
        <li class="cms-sidebar__item">
            <a href="/admin/cms" class="cms-sidebar__link @if (($activeSection ?? '') === 'dashboard') cms-sidebar__link--active @endif">
                <span class="cms-sidebar__icon" aria-hidden="true">&#9632;</span>
                Dashboard
            </a>
        </li>

        @can('cms.content.view')
            <li class="cms-sidebar__item">
                <a href="/admin/cms/content" class="cms-sidebar__link @if (($activeSection ?? '') === 'content') cms-sidebar__link--active @endif">
                    <span class="cms-sidebar__icon" aria-hidden="true">&#9997;</span>
                    Content
                </a>
            </li>
        @endcan

        @can('cms.taxonomy.view')
            <li class="cms-sidebar__item">
                <a href="/admin/cms/taxonomy" class="cms-sidebar__link @if (($activeSection ?? '') === 'taxonomy') cms-sidebar__link--active @endif">
                    <span class="cms-sidebar__icon" aria-hidden="true">&#9733;</span>
                    Taxonomies
                </a>
            </li>
        @endcan

        @can('cms.menus.view')
            <li class="cms-sidebar__item">
                <a href="/admin/cms/menus" class="cms-sidebar__link @if (($activeSection ?? '') === 'menus') cms-sidebar__link--active @endif">
                    <span class="cms-sidebar__icon" aria-hidden="true">&#9776;</span>
                    Menus
                </a>
            </li>
        @endcan

        @can('cms.content.approve')
            <li class="cms-sidebar__item">
                <a href="/admin/cms/reviews" class="cms-sidebar__link @if (($activeSection ?? '') === 'reviews') cms-sidebar__link--active @endif">
                    <span class="cms-sidebar__icon" aria-hidden="true">&#10003;</span>
                    Reviews
                    @if (($pendingReviewCount ?? 0) > 0)
                        <span class="cms-sidebar__badge">{{ $pendingReviewCount }}</span>
                    @endif
                </a>
            </li>
        @endcan

        @can('cms.content.manage_fields')
            <li class="cms-sidebar__item">
                <a href="/admin/cms/fields/article" class="cms-sidebar__link @if (($activeSection ?? '') === 'fields') cms-sidebar__link--active @endif">
                    <span class="cms-sidebar__icon" aria-hidden="true">&#9881;</span>
                    Custom Fields
                </a>
            </li>
        @endcan

        @can('cms.settings.view')
            <li class="cms-sidebar__item">
                <a href="/admin/cms/settings/general" class="cms-sidebar__link @if (($activeSection ?? '') === 'settings') cms-sidebar__link--active @endif">
                    <span class="cms-sidebar__icon" aria-hidden="true">&#9881;</span>
                    Settings
                </a>
            </li>
        @endcan
    </ul>
</nav>
@endsection

@section('content')
    @yield('cms-content')
@endsection
