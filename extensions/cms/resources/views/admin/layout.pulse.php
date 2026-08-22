@extends('admin.base-layout')

@section('sidebar')
@if ($safeMode ?? false)
    <div class="cms-safe-mode-banner" role="alert">
        <i class="fa-solid fa-triangle-exclamation cms-safe-mode-banner__icon" aria-hidden="true"></i>
        <div class="cms-safe-mode-banner__content">
            <strong class="cms-safe-mode-banner__title">Safe Mode Active</strong>
            <p class="cms-safe-mode-banner__text">The active theme has been disabled due to an error. The default fallback theme is in use.</p>
        </div>
    </div>
@endif
<?php
    $active = $activeSection ?? '';
$contentSections = ['content', 'taxonomies', 'menus', 'fields', 'reviews', 'media', 'comments'];
$seoSections = ['search-analytics', 'seo-redirects', 'seo-link-health', 'seo-sitemap', 'seo-robots'];
$commerceSections = ['products', 'orders', 'promotions', 'digital-assets'];
$appearanceSections = ['themes', 'plugins', 'live-css'];
$toolsSections = ['export', 'import', 'site-import', 'backups'];
$adminSections = ['users', '2fa', 'settings', 'business-profile'];
?>
<nav aria-label="CMS Navigation">
    {{-- Dashboard --}}
    <ul class="cms-sidebar__list">
        <li class="cms-sidebar__item">
            <a href="/admin/cms" class="cms-sidebar__link @if ($active === 'dashboard') cms-sidebar__link--active @endif"
                @if ($active === 'dashboard') aria-current="page" @endif>
                <i class="fa-solid fa-gauge-high cms-sidebar__icon" aria-hidden="true"></i>
                Dashboard
            </a>
        </li>
    </ul>

    {{-- Content --}}
    <details class="cms-sidebar__group" @if (in_array($active, $contentSections, true)) open @endif>
        <summary class="cms-sidebar__group-toggle">
            <i class="fa-solid fa-pen-to-square cms-sidebar__group-icon" aria-hidden="true"></i>
            Content
            <i class="fa-solid fa-chevron-down cms-sidebar__chevron" aria-hidden="true"></i>
        </summary>
        <ul class="cms-sidebar__sublist">
            @can('cms.content.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/content" class="cms-sidebar__link @if ($active === 'content') cms-sidebar__link--active @endif"
                        @if ($active === 'content') aria-current="page" @endif>
                        <i class="fa-solid fa-newspaper cms-sidebar__icon" aria-hidden="true"></i>
                        All Content
                    </a>
                </li>
            @endcan
            @can('cms.taxonomy.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/taxonomies" class="cms-sidebar__link @if ($active === 'taxonomies') cms-sidebar__link--active @endif"
                        @if ($active === 'taxonomies') aria-current="page" @endif>
                        <i class="fa-solid fa-tags cms-sidebar__icon" aria-hidden="true"></i>
                        Taxonomies
                    </a>
                </li>
            @endcan
            @can('cms.menus.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/menus" class="cms-sidebar__link @if ($active === 'menus') cms-sidebar__link--active @endif"
                        @if ($active === 'menus') aria-current="page" @endif>
                        <i class="fa-solid fa-bars cms-sidebar__icon" aria-hidden="true"></i>
                        Menus
                    </a>
                </li>
            @endcan
            @can('cms.content.manage_fields')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/fields/article" class="cms-sidebar__link @if ($active === 'fields') cms-sidebar__link--active @endif"
                        @if ($active === 'fields') aria-current="page" @endif>
                        <i class="fa-solid fa-sliders cms-sidebar__icon" aria-hidden="true"></i>
                        Custom Fields
                    </a>
                </li>
            @endcan
            @can('cms.content.approve')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/reviews" class="cms-sidebar__link @if ($active === 'reviews') cms-sidebar__link--active @endif"
                        @if ($active === 'reviews') aria-current="page" @endif>
                        <i class="fa-solid fa-clipboard-check cms-sidebar__icon" aria-hidden="true"></i>
                        Reviews
                        @if (($pendingReviewCount ?? 0) > 0)
                            <span class="cms-sidebar__badge" aria-label="{{ $pendingReviewCount }} pending reviews">{{ $pendingReviewCount }}</span>
                        @endif
                    </a>
                </li>
            @endcan
            @can('cms.media.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/media" class="cms-sidebar__link @if ($active === 'media') cms-sidebar__link--active @endif"
                        @if ($active === 'media') aria-current="page" @endif>
                        <i class="fa-solid fa-photo-film cms-sidebar__icon" aria-hidden="true"></i>
                        Media
                    </a>
                </li>
            @endcan
            @can('cms.comments.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/comments" class="cms-sidebar__link @if ($active === 'comments') cms-sidebar__link--active @endif"
                        @if ($active === 'comments') aria-current="page" @endif>
                        <i class="fa-solid fa-comments cms-sidebar__icon" aria-hidden="true"></i>
                        Comments
                        @if (($pendingCommentCount ?? 0) > 0)
                            <span class="cms-sidebar__badge" aria-label="{{ $pendingCommentCount }} pending comments">{{ $pendingCommentCount }}</span>
                        @endif
                    </a>
                </li>
            @endcan
        </ul>
    </details>

    {{-- SEO & Analytics --}}
    @can('cms.seo.view')
        <details class="cms-sidebar__group" @if (in_array($active, $seoSections, true)) open @endif>
            <summary class="cms-sidebar__group-toggle">
                <i class="fa-solid fa-magnifying-glass-chart cms-sidebar__group-icon" aria-hidden="true"></i>
                SEO & Analytics
                <i class="fa-solid fa-chevron-down cms-sidebar__chevron" aria-hidden="true"></i>
            </summary>
            <ul class="cms-sidebar__sublist">
                @can('cms.search.view_analytics')
                    <li class="cms-sidebar__item">
                        <a href="/admin/cms/search-analytics" class="cms-sidebar__link @if ($active === 'search-analytics') cms-sidebar__link--active @endif"
                            @if ($active === 'search-analytics') aria-current="page" @endif>
                            <i class="fa-solid fa-chart-line cms-sidebar__icon" aria-hidden="true"></i>
                            Search Analytics
                        </a>
                    </li>
                @endcan
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/seo/redirects" class="cms-sidebar__link @if ($active === 'seo-redirects') cms-sidebar__link--active @endif"
                        @if ($active === 'seo-redirects') aria-current="page" @endif>
                        <i class="fa-solid fa-arrow-right-arrow-left cms-sidebar__icon" aria-hidden="true"></i>
                        Redirects
                    </a>
                </li>
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/seo/link-health" class="cms-sidebar__link @if ($active === 'seo-link-health') cms-sidebar__link--active @endif"
                        @if ($active === 'seo-link-health') aria-current="page" @endif>
                        <i class="fa-solid fa-link cms-sidebar__icon" aria-hidden="true"></i>
                        Link Health
                    </a>
                </li>
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/seo/sitemap" class="cms-sidebar__link @if ($active === 'seo-sitemap') cms-sidebar__link--active @endif"
                        @if ($active === 'seo-sitemap') aria-current="page" @endif>
                        <i class="fa-solid fa-sitemap cms-sidebar__icon" aria-hidden="true"></i>
                        Sitemap
                    </a>
                </li>
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/seo/robots" class="cms-sidebar__link @if ($active === 'seo-robots') cms-sidebar__link--active @endif"
                        @if ($active === 'seo-robots') aria-current="page" @endif>
                        <i class="fa-solid fa-robot cms-sidebar__icon" aria-hidden="true"></i>
                        Robots.txt
                    </a>
                </li>
            </ul>
        </details>
    @endcan

    {{-- Commerce --}}
    <details class="cms-sidebar__group" @if (in_array($active, $commerceSections, true)) open @endif>
        <summary class="cms-sidebar__group-toggle">
            <i class="fa-solid fa-cart-shopping cms-sidebar__group-icon" aria-hidden="true"></i>
            Commerce
            <i class="fa-solid fa-chevron-down cms-sidebar__chevron" aria-hidden="true"></i>
        </summary>
        <ul class="cms-sidebar__sublist">
            @can('cms.products.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/products" class="cms-sidebar__link @if ($active === 'products') cms-sidebar__link--active @endif"
                        @if ($active === 'products') aria-current="page" @endif>
                        <i class="fa-solid fa-box-open cms-sidebar__icon" aria-hidden="true"></i>
                        Products
                    </a>
                </li>
            @endcan
            @can('cms.orders.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/orders" class="cms-sidebar__link @if ($active === 'orders') cms-sidebar__link--active @endif"
                        @if ($active === 'orders') aria-current="page" @endif>
                        <i class="fa-solid fa-receipt cms-sidebar__icon" aria-hidden="true"></i>
                        Orders
                    </a>
                </li>
            @endcan
            @can('cms.promotions.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/promotions" class="cms-sidebar__link @if ($active === 'promotions') cms-sidebar__link--active @endif"
                        @if ($active === 'promotions') aria-current="page" @endif>
                        <i class="fa-solid fa-percent cms-sidebar__icon" aria-hidden="true"></i>
                        Promotions
                    </a>
                </li>
            @endcan
            @can('cms.digital_assets.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/products" class="cms-sidebar__link @if ($active === 'digital-assets') cms-sidebar__link--active @endif"
                        @if ($active === 'digital-assets') aria-current="page" @endif
                        title="Digital assets are managed per product">
                        <i class="fa-solid fa-cloud-arrow-down cms-sidebar__icon" aria-hidden="true"></i>
                        Digital Assets
                    </a>
                </li>
            @endcan
        </ul>
    </details>

    {{-- Appearance --}}
    <details class="cms-sidebar__group" @if (in_array($active, $appearanceSections, true)) open @endif>
        <summary class="cms-sidebar__group-toggle">
            <i class="fa-solid fa-paintbrush cms-sidebar__group-icon" aria-hidden="true"></i>
            Appearance
            <i class="fa-solid fa-chevron-down cms-sidebar__chevron" aria-hidden="true"></i>
        </summary>
        <ul class="cms-sidebar__sublist">
            @can('cms.themes.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/themes" class="cms-sidebar__link @if ($active === 'themes') cms-sidebar__link--active @endif"
                        @if ($active === 'themes') aria-current="page" @endif>
                        <i class="fa-solid fa-palette cms-sidebar__icon" aria-hidden="true"></i>
                        Themes
                    </a>
                </li>
            @endcan
            @can('cms.plugins.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/plugins" class="cms-sidebar__link @if ($active === 'plugins') cms-sidebar__link--active @endif"
                        @if ($active === 'plugins') aria-current="page" @endif>
                        <i class="fa-solid fa-puzzle-piece cms-sidebar__icon" aria-hidden="true"></i>
                        Plugins
                    </a>
                </li>
            @endcan
            @can('cms.livecss.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/live-css" class="cms-sidebar__link @if ($active === 'live-css') cms-sidebar__link--active @endif"
                        @if ($active === 'live-css') aria-current="page" @endif>
                        <i class="fa-solid fa-code cms-sidebar__icon" aria-hidden="true"></i>
                        Live CSS
                    </a>
                </li>
            @endcan
        </ul>
    </details>

    {{-- Tools --}}
    <details class="cms-sidebar__group" @if (in_array($active, $toolsSections, true)) open @endif>
        <summary class="cms-sidebar__group-toggle">
            <i class="fa-solid fa-toolbox cms-sidebar__group-icon" aria-hidden="true"></i>
            Tools
            <i class="fa-solid fa-chevron-down cms-sidebar__chevron" aria-hidden="true"></i>
        </summary>
        <ul class="cms-sidebar__sublist">
            @can('cms.tools.export')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/export" class="cms-sidebar__link @if ($active === 'export') cms-sidebar__link--active @endif"
                        @if ($active === 'export') aria-current="page" @endif>
                        <i class="fa-solid fa-file-export cms-sidebar__icon" aria-hidden="true"></i>
                        Export
                    </a>
                </li>
            @endcan
            @can('cms.tools.import')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/import" class="cms-sidebar__link @if ($active === 'import') cms-sidebar__link--active @endif"
                        @if ($active === 'import') aria-current="page" @endif>
                        <i class="fa-solid fa-file-import cms-sidebar__icon" aria-hidden="true"></i>
                        Import
                    </a>
                </li>
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/site-import" class="cms-sidebar__link @if ($active === 'site-import') cms-sidebar__link--active @endif"
                        @if ($active === 'site-import') aria-current="page" @endif>
                        <i class="fa-solid fa-globe cms-sidebar__icon" aria-hidden="true"></i>
                        Site Import
                    </a>
                </li>
            @endcan
            @can('cms.backups.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/backups" class="cms-sidebar__link @if ($active === 'backups') cms-sidebar__link--active @endif"
                        @if ($active === 'backups') aria-current="page" @endif>
                        <i class="fa-solid fa-database cms-sidebar__icon" aria-hidden="true"></i>
                        Backups
                    </a>
                </li>
            @endcan
        </ul>
    </details>

    {{-- Administration --}}
    <details class="cms-sidebar__group" @if (in_array($active, $adminSections, true)) open @endif>
        <summary class="cms-sidebar__group-toggle">
            <i class="fa-solid fa-user-shield cms-sidebar__group-icon" aria-hidden="true"></i>
            Administration
            <i class="fa-solid fa-chevron-down cms-sidebar__chevron" aria-hidden="true"></i>
        </summary>
        <ul class="cms-sidebar__sublist">
            @can('cms.users.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/users" class="cms-sidebar__link @if ($active === 'users') cms-sidebar__link--active @endif"
                        @if ($active === 'users') aria-current="page" @endif>
                        <i class="fa-solid fa-users cms-sidebar__icon" aria-hidden="true"></i>
                        @t('admin.nav.users')
                    </a>
                </li>
            @endcan
            <li class="cms-sidebar__item">
                <a href="/admin/cms/2fa" class="cms-sidebar__link @if ($active === '2fa') cms-sidebar__link--active @endif"
                    @if ($active === '2fa') aria-current="page" @endif>
                    <i class="fa-solid fa-shield-halved cms-sidebar__icon" aria-hidden="true"></i>
                    2FA settings
                </a>
            </li>
            @can('cms.settings.view')
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/settings/business" class="cms-sidebar__link @if ($active === 'business-profile') cms-sidebar__link--active @endif"
                        @if ($active === 'business-profile') aria-current="page" @endif>
                        <i class="fa-solid fa-building cms-sidebar__icon" aria-hidden="true"></i>
                        @t('admin.nav.business_profile')
                    </a>
                </li>
                <li class="cms-sidebar__item">
                    <a href="/admin/cms/settings/general" class="cms-sidebar__link @if ($active === 'settings') cms-sidebar__link--active @endif"
                        @if ($active === 'settings') aria-current="page" @endif>
                        <i class="fa-solid fa-gear cms-sidebar__icon" aria-hidden="true"></i>
                        Settings
                    </a>
                </li>
            @endcan
        </ul>
    </details>
</nav>
@endsection

@section('content')
    @yield('cms-content')
@endsection
