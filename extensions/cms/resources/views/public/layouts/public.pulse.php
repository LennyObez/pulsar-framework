<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}" data-theme="light" data-extension="cms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $csrfToken ?? '' }}">
    <title>@yield('title', $siteName ?? 'Pulsar CMS')</title>
    <meta name="description" content="@yield('meta_description', $metaDescription ?? '')">
    @yield('meta')
    @if (!empty($seoConfig->googleSiteVerification))
        <meta name="google-site-verification" content="{{ htmlspecialchars($seoConfig->googleSiteVerification) }}">
    @endif
    @if (!empty($seoConfig->bingSiteVerification))
        <meta name="msvalidate.01" content="{{ htmlspecialchars($seoConfig->bingSiteVerification) }}">
    @endif
    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
    <link rel="stylesheet" href="/cms/assets/cms-public.css">
    @yield('styles')
</head>
<body class="cms-public">
    <a href="#main-content" class="pui-skip-link">Skip to main content</a>

    {{-- Site header with mega-menu navigation --}}
    <header class="cms-public-header" role="banner">
        <div class="cms-public-header__inner">
            <a href="/" class="cms-public-header__brand" aria-label="Home">
                {{ $siteName ?? 'Pulsar CMS' }}
            </a>

            <button class="cms-public-header__toggle"
                    type="button"
                    aria-expanded="false"
                    aria-controls="cms-mega-menu"
                    aria-label="Toggle navigation">
                <span class="cms-public-header__toggle-bar"></span>
                <span class="cms-public-header__toggle-bar"></span>
                <span class="cms-public-header__toggle-bar"></span>
            </button>

            <nav class="cms-mega-menu" id="cms-mega-menu" aria-label="Main navigation">
                <ul class="cms-mega-menu__list" role="menubar">
                    @if (!empty($menuItems))
                        @foreach ($menuItems as $item)
                            <?php /** @var array{label: string, url: string, children?: list<array{label: string, url: string}>} $item */ ?>
                            @if (!empty($item['children']))
                                <li class="cms-mega-menu__item cms-mega-menu__item--has-children" role="none">
                                    <button class="cms-mega-menu__link cms-mega-menu__trigger"
                                            role="menuitem"
                                            aria-haspopup="true"
                                            aria-expanded="false"
                                            type="button">
                                        {{ $item['label'] }}
                                        <svg class="cms-mega-menu__chevron" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">
                                            <path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                    <div class="cms-mega-menu__panel" role="menu">
                                        <ul class="cms-mega-menu__submenu">
                                            @foreach ($item['children'] as $child)
                                                <li role="none">
                                                    <a href="{{ $child['url'] }}" class="cms-mega-menu__sublink" role="menuitem">
                                                        {{ $child['label'] }}
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </li>
                            @else
                                <li class="cms-mega-menu__item" role="none">
                                    <a href="{{ $item['url'] }}" class="cms-mega-menu__link" role="menuitem">
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    @else
                        <li class="cms-mega-menu__item" role="none">
                            <a href="/" class="cms-mega-menu__link" role="menuitem">Home</a>
                        </li>
                        <li class="cms-mega-menu__item" role="none">
                            <a href="/articles" class="cms-mega-menu__link" role="menuitem">Articles</a>
                        </li>
                        <li class="cms-mega-menu__item" role="none">
                            <a href="/search" class="cms-mega-menu__link" role="menuitem">Search</a>
                        </li>
                    @endif
                </ul>

                <form class="cms-mega-menu__search" action="/search" method="GET" role="search" aria-label="Site search">
                    <label for="cms-header-search" class="pui-sr-only">Search</label>
                    <input type="search"
                           id="cms-header-search"
                           name="q"
                           class="cms-mega-menu__search-input"
                           placeholder="Search..."
                           aria-label="Search the site">
                    <button type="submit" class="cms-mega-menu__search-btn" aria-label="Submit search">
                        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M11.5 11.5L14.5 14.5M6.5 12A5.5 5.5 0 106.5 1a5.5 5.5 0 000 11z" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </form>

                @if (isset($config) && is_object($config) && count($config->supportedLocales) > 1)
                    <?php
                        $localeNames = ['en' => 'English', 'fr' => 'Français', 'nl' => 'Nederlands', 'de' => 'Deutsch', 'es' => 'Español', 'it' => 'Italiano', 'pt' => 'Português', 'pl' => 'Polski', 'ro' => 'Română', 'cs' => 'Čeština', 'el' => 'Ελληνικά', 'hu' => 'Magyar', 'sv' => 'Svenska', 'da' => 'Dansk', 'fi' => 'Suomi', 'sk' => 'Slovenčina', 'bg' => 'Български', 'hr' => 'Hrvatski', 'sl' => 'Slovenščina', 'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'et' => 'Eesti', 'ga' => 'Gaeilge', 'mt' => 'Malti', 'lb' => 'Lëtzebuergesch'];
                        $currentLocale = $locale ?? 'en';
                        $currentName = $localeNames[$currentLocale] ?? strtoupper($currentLocale);
                    ?>
                    <nav class="cms-locale-switcher" aria-label="Language">
                        <details>
                            <summary class="cms-locale-switcher__trigger">
                                <span class="cms-locale-switcher__code">{{ strtoupper($currentLocale) }}</span>
                                <span class="cms-locale-switcher__name">{{ $currentName }}</span>
                                <svg class="cms-locale-switcher__chevron" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true">
                                    <path d="M2 3.5L5 6.5L8 3.5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </summary>
                            <ul class="cms-locale-switcher__dropdown">
                                @foreach ($config->supportedLocales as $loc)
                                    <?php
                                        $locName = $localeNames[$loc] ?? strtoupper($loc);
                                        $isDefault = ($config->defaultLocale === $loc && !$config->defaultLocaleInUrl);
                                        $locHref = $isDefault ? '/' : '/' . $loc . '/';
                                        $isCurrent = $currentLocale === $loc;
                                    ?>
                                    @if (!$isCurrent)
                                        <li class="cms-locale-switcher__item">
                                            <a href="{{ $locHref }}" class="cms-locale-switcher__link" hreflang="{{ $loc }}" lang="{{ $loc }}">
                                                <span class="cms-locale-switcher__link-code">{{ strtoupper($loc) }}</span>
                                                <span class="cms-locale-switcher__link-name">{{ $locName }}</span>
                                            </a>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </details>
                    </nav>
                @endif
            </nav>
        </div>
    </header>

    @yield('hero')

    <main id="main-content" class="cms-public-main">
        @yield('content')
    </main>

    {{-- Site footer --}}
    <footer class="cms-public-footer" role="contentinfo">
        <div class="cms-public-footer__inner">
            <div class="cms-public-footer__grid">
                <div class="cms-public-footer__column">
                    <h2 class="cms-public-footer__heading">{{ $siteName ?? 'Pulsar CMS' }}</h2>
                    <p class="cms-public-footer__text">{{ $siteDescription ?? 'Powered by the Pulsar framework.' }}</p>
                </div>

                @if (!empty($footerLinks))
                    @foreach ($footerLinks as $group)
                        <?php /** @var array{title: string, links: list<array{label: string, url: string}>} $group */ ?>
                        <div class="cms-public-footer__column">
                            <h3 class="cms-public-footer__subheading">{{ $group['title'] }}</h3>
                            <ul class="cms-public-footer__list">
                                @foreach ($group['links'] as $link)
                                    <li><a href="{{ $link['url'] }}" class="cms-public-footer__link">{{ $link['label'] }}</a></li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @else
                    <div class="cms-public-footer__column">
                        <h3 class="cms-public-footer__subheading">Navigation</h3>
                        <ul class="cms-public-footer__list">
                            <li><a href="/" class="cms-public-footer__link">Home</a></li>
                            <li><a href="/articles" class="cms-public-footer__link">Articles</a></li>
                            <li><a href="/search" class="cms-public-footer__link">Search</a></li>
                        </ul>
                    </div>
                    <div class="cms-public-footer__column">
                        <h3 class="cms-public-footer__subheading">Feeds</h3>
                        <ul class="cms-public-footer__list">
                            <li><a href="/feed/rss" class="cms-public-footer__link">RSS</a></li>
                            <li><a href="/feed/atom" class="cms-public-footer__link">Atom</a></li>
                        </ul>
                    </div>
                @endif
            </div>

            <div class="cms-public-footer__bottom">
                <p class="cms-public-footer__copyright">&copy; {{ date('Y') }} {{ $siteName ?? 'Pulsar CMS' }}. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
    (function() {
        'use strict';
        var toggle = document.querySelector('.cms-public-header__toggle');
        var menu = document.getElementById('cms-mega-menu');
        if (toggle && menu) {
            toggle.addEventListener('click', function() {
                var expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!expanded));
                menu.classList.toggle('cms-mega-menu--open', !expanded);
            });
        }
        var triggers = document.querySelectorAll('.cms-mega-menu__trigger');
        triggers.forEach(function(trigger) {
            trigger.addEventListener('click', function() {
                var expanded = trigger.getAttribute('aria-expanded') === 'true';
                triggers.forEach(function(other) {
                    if (other !== trigger) {
                        other.setAttribute('aria-expanded', 'false');
                    }
                });
                trigger.setAttribute('aria-expanded', String(!expanded));
            });
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                triggers.forEach(function(t) { t.setAttribute('aria-expanded', 'false'); });
                if (menu) {
                    toggle.setAttribute('aria-expanded', 'false');
                    menu.classList.remove('cms-mega-menu--open');
                }
            }
        });
    })();
    </script>
    <script src="/ui/js/language-selector.js" defer></script>
    @yield('scripts')
</body>
</html>
