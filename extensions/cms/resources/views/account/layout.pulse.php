<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}" dir="{{ $dir ?? 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') | @t('account.my_account')</title>
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    @yield('head')
</head>
<body>
    <div class="pui-sidebar-layout">
        <aside class="pui-sidebar">
            <div class="pui-sidebar__brand">
                <span class="pui-avatar pui-avatar--xl" aria-hidden="true">
                    {{ strtoupper(substr($customer['display_name'] ?? $customer['email'] ?? '?', 0, 1)) }}
                </span>
            </div>
            <div class="pui-sidebar__group">
                <p class="pui-sidebar__heading">{{ $customer['display_name'] ?? '' }}</p>
                <p class="pui-sidebar-nav__badge">{{ $customer['email'] ?? '' }}</p>
            </div>

            <nav aria-label="@t('account.navigation')">
                <div class="pui-sidebar__group">
                    <a href="/account" class="pui-sidebar__link @if (($active_section ?? '') === 'dashboard') pui-sidebar__link--active @endif">
                        <svg class="pui-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        @t('account.dashboard')
                    </a>
                    <a href="/account/profile" class="pui-sidebar__link @if (($active_section ?? '') === 'profile') pui-sidebar__link--active @endif">
                        <svg class="pui-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        @t('account.profile')
                    </a>

                    @foreach ($sections ?? [] as $section)
                        <a href="/account/section/{{ $section->id }}" class="pui-sidebar__link @if (($active_section ?? '') === $section->id) pui-sidebar__link--active @endif">
                            {{ $section->label }}
                            @if ($section->badgeCount !== null)
                                <span class="pui-sidebar__badge">{{ $section->badgeCount }}</span>
                            @endif
                        </a>
                    @endforeach

                    <a href="/account/settings" class="pui-sidebar__link @if (($active_section ?? '') === 'settings') pui-sidebar__link--active @endif">
                        <svg class="pui-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        @t('account.settings')
                    </a>
                </div>
            </nav>
        </aside>

        <main class="pui-sidebar-layout__content">
            <div class="pui-container">
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
