{{-- Locale tab navigation. Expects: $locales (array of locale codes), $activeLocale (current locale), $baseUrl (URL without locale param) --}}
<nav class="cms-locale-tabs" aria-label="Language selector">
    <ul class="cms-locale-tabs__list" role="tablist">
        @foreach ($locales as $locale)
            <li class="cms-locale-tabs__item" role="presentation">
                <a href="{{ $baseUrl }}{{ str_contains($baseUrl, '?') ? '&' : '?' }}locale={{ $locale }}"
                   class="cms-locale-tabs__link @if ($locale === $activeLocale) cms-locale-tabs__link--active @endif"
                   role="tab"
                   aria-selected="{{ $locale === $activeLocale ? 'true' : 'false' }}"
                   data-cms-locale="{{ $locale }}">
                    {{ strtoupper($locale) }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
