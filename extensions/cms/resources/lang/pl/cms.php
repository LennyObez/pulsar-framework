<?php

declare(strict_types=1);

return [
    // Navigation
    'nav.dashboard' => 'Panel',
    'nav.content' => 'Tresc',
    'nav.media' => 'Media',
    'nav.comments' => 'Komentarze',
    'nav.forms' => 'Formularze',
    'nav.users' => 'Uzytkownicy',
    'nav.menus' => 'Menu',
    'nav.settings' => 'Ustawienia',
    'nav.plugins' => 'Wtyczki',
    'nav.themes' => 'Motywy',
    'nav.tools' => 'Narzedzia',
    'nav.seo' => 'SEO',
    'nav.products' => 'Produkty',
    'nav.orders' => 'Zamowienia',
    'nav.promotions' => 'Promocje',
    'nav.newsletter' => 'Newsletter',

    // Dashboard
    'dashboard.title' => 'Panel',
    'dashboard.welcome' => 'Witamy w systemie CMS',
    'dashboard.quick_actions' => 'Szybkie akcje',
    'dashboard.recent_content' => 'Ostatnia tresc',
    'dashboard.recent_comments' => 'Ostatnie komentarze',

    // Content
    'content.title' => 'Tresc',
    'content.new' => 'Nowa tresc',
    'content.edit' => 'Edytuj tresc',
    'content.publish' => 'Opublikuj',
    'content.unpublish' => 'Wycofaj',
    'content.draft' => 'Szkic',
    'content.published' => 'Opublikowane',
    'content.archived' => 'Zarchiwizowane',
    'content.scheduled' => 'Zaplanowane',
    'content.slug' => 'Slug',
    'content.author' => 'Autor',
    'content.category' => 'Kategoria',
    'content.tags' => 'Tagi',
    'content.featured_image' => 'Obraz wyrozniony',
    'content.excerpt' => 'Zajawka',
    'content.body' => 'Tresc glowna',
    'content.meta_title' => 'Meta tytul',
    'content.meta_description' => 'Meta opis',
    'content.revisions' => 'Wersje',
    'content.preview' => 'Podglad',
    'content.no_content' => 'Brak elementow tresci.',

    // Media
    'media.title' => 'Biblioteka mediow',
    'media.upload' => 'Przeslij pliki',
    'media.no_files' => 'Nie przeslano jeszcze zadnych plikow.',
    'media.file_name' => 'Nazwa pliku',
    'media.file_size' => 'Rozmiar pliku',
    'media.file_type' => 'Typ pliku',
    'media.dimensions' => 'Wymiary',
    'media.uploaded_at' => 'Przeslano',
    'media.alt_text' => 'Tekst alternatywny',
    'media.caption' => 'Podpis',
    'media.delete_confirm' => 'Czy na pewno chcesz usunac ten plik?',

    // Comments
    'comments.title' => 'Komentarze',
    'comments.pending' => 'Oczekujace',
    'comments.approved' => 'Zatwierdzone',
    'comments.spam' => 'Spam',
    'comments.approve' => 'Zatwierdz',
    'comments.reject' => 'Odrzuc',
    'comments.mark_spam' => 'Oznacz jako spam',
    'comments.reply' => 'Odpowiedz',
    'comments.no_comments' => 'Brak komentarzy.',
    'comments.queue' => 'Kolejka moderacji',

    // Users
    'users.title' => 'Uzytkownicy',
    'users.new' => 'Nowy uzytkownik',
    'users.edit' => 'Edytuj uzytkownika',
    'users.role' => 'Rola',
    'users.last_login' => 'Ostatnie logowanie',
    'users.no_users' => 'Nie znaleziono uzytkownikow.',

    // Settings
    'settings.title' => 'Ustawienia',
    'settings.general' => 'Ogolne',
    'settings.site_name' => 'Nazwa witryny',
    'settings.site_url' => 'Adres URL witryny',
    'settings.site_description' => 'Opis witryny',
    'settings.timezone' => 'Strefa czasowa',
    'settings.date_format' => 'Format daty',
    'settings.saved' => 'Ustawienia zapisane pomyslnie.',

    // Plugins
    'plugins.title' => 'Wtyczki',
    'plugins.installed' => 'Zainstalowane',
    'plugins.available' => 'Dostepne',
    'plugins.install' => 'Zainstaluj',
    'plugins.activate' => 'Aktywuj',
    'plugins.deactivate' => 'Dezaktywuj',
    'plugins.settings' => 'Ustawienia wtyczki',

    // Themes
    'themes.title' => 'Motywy',
    'themes.active' => 'Aktywny motyw',
    'themes.activate' => 'Aktywuj',
    'themes.preview' => 'Podglad',
    'themes.customize' => 'Dostosuj',

    // Tools
    'tools.title' => 'Narzedzia',
    'tools.backups' => 'Kopie zapasowe',
    'tools.import' => 'Importuj',
    'tools.export' => 'Eksportuj',
    'tools.create_backup' => 'Utworz kopie zapasowa',
    'tools.restore_backup' => 'Przywroc kopie zapasowa',

    // SEO
    'seo.title' => 'SEO',
    'seo.robots' => 'Robots',
    'seo.sitemap' => 'Mapa witryny',
    'seo.redirects' => 'Przekierowania',
    'seo.link_health' => 'Stan linkow',

    // Commerce
    'products.title' => 'Produkty',
    'products.new' => 'Nowy produkt',
    'products.price' => 'Cena',
    'products.stock' => 'Stan magazynowy',
    'products.sku' => 'SKU',

    'orders.title' => 'Zamowienia',
    'orders.order_number' => 'Numer zamowienia',
    'orders.customer' => 'Klient',
    'orders.total' => 'Suma',
    'orders.status' => 'Status',
    'orders.pending' => 'Oczekujace',
    'orders.processing' => 'W realizacji',
    'orders.shipped' => 'Wyslane',
    'orders.delivered' => 'Dostarczone',
    'orders.cancelled' => 'Anulowane',
    'orders.refunded' => 'Zwrocone',

    'promotions.title' => 'Promocje',
    'promotions.new' => 'Nowa promocja',
    'promotions.code' => 'Kod promocyjny',
    'promotions.discount' => 'Rabat',
    'promotions.valid_from' => 'Wazne od',
    'promotions.valid_until' => 'Wazne do',

    // Newsletter
    'newsletter.title' => 'Newsletter',
    'newsletter.campaigns' => 'Kampanie',
    'newsletter.subscribers' => 'Subskrybenci',
    'newsletter.analytics' => 'Statystyki',
    'newsletter.new_campaign' => 'Nowa kampania',

    // Two-factor authentication
    '2fa.title' => 'Uwierzytelnianie dwuskladnikowe',
    '2fa.enable' => 'Wlacz',
    '2fa.disable' => 'Wylacz',
    '2fa.scan_qr' => 'Prosze zeskanowac ten kod QR za pomoca aplikacji uwierzytelniania',
    '2fa.enter_code' => 'Prosze wprowadzic kod weryfikacyjny',
    '2fa.recovery_codes' => 'Kody odzyskiwania',
    '2fa.recovery_warning' => 'Prosze przechowywac te kody w bezpiecznym miejscu. Kazdy kod moze byc uzyty tylko raz.',

    // Safe mode
    'safe_mode.title' => 'Tryb awaryjny',
    'safe_mode.message' => 'System CMS dziala w trybie awaryjnym. Niektore funkcje moga byc niedostepne.',

    // Pagination
    'pagination.showing' => 'Wyswietlanie {from} do {to} z {total}',
    'pagination.previous' => 'Poprzednia',
    'pagination.next' => 'Nastepna',

    // General
    'confirm_destructive' => 'Tej operacji nie mozna cofnac. Czy kontynuowac?',
    'skip_to_content' => 'Przejdz do tresci glownej',
];
