<?php

declare(strict_types=1);

return [
    'go_back' => 'Terug',
    'go_home' => 'Terug naar home',
    'contact_support' => 'Neem contact op met ondersteuning',
    'refresh_page' => 'Pagina vernieuwen',
    'try_again' => 'Opnieuw proberen',
    'sign_in' => 'Inloggen',
    'search' => 'Zoeken',
    'search_placeholder' => 'Zoeken...',
    'popular_links' => 'Populaire links',
    'retry_in' => 'Opnieuw proberen over {seconds} seconden',

    '400.title' => 'Ongeldig verzoek',
    '400.heading' => 'Er is iets misgegaan met uw verzoek',
    '400.description' => 'De server kon het verzoek niet begrijpen vanwege onjuiste syntaxis of ongeldige parameters. Controleer uw invoer en probeer het opnieuw.',

    '401.title' => 'Niet geautoriseerd',
    '401.heading' => 'Authenticatie vereist',
    '401.description' => 'U moet inloggen om toegang te krijgen tot deze bron. Als u denkt dat dit een fout is, neem dan contact op met ondersteuning.',

    '403.title' => 'Verboden',
    '403.heading' => 'Toegang geweigerd',
    '403.description' => 'U heeft geen toestemming om deze bron te openen. Als u denkt dat u toegang zou moeten hebben, neem dan contact op met uw beheerder.',

    '404.title' => 'Pagina niet gevonden',
    '404.heading' => 'We konden die pagina niet vinden',
    '404.description' => 'De pagina die u zoekt is mogelijk verwijderd, hernoemd of tijdelijk niet beschikbaar.',

    '405.title' => 'Methode niet toegestaan',
    '405.heading' => 'Deze actie wordt niet ondersteund',
    '405.description' => 'De gebruikte verzoekmethode wordt niet ondersteund voor deze bron. Probeer een andere aanpak of ga terug naar de vorige pagina.',

    '408.title' => 'Time-out van verzoek',
    '408.heading' => 'Het verzoek duurde te lang',
    '408.description' => 'De server heeft een time-out gehad bij het wachten op het verzoek. Dit kan gebeuren bij trage verbindingen of grote uploads. Probeer het opnieuw.',

    '413.title' => 'Inhoud te groot',
    '413.heading' => 'Het bestand of de gegevens zijn te groot',
    '413.description' => 'Het verzoek was groter dan de server is geconfigureerd om te accepteren. Verklein uw upload en probeer het opnieuw.',

    '419.title' => 'Sessie verlopen',
    '419.heading' => 'Uw sessie is verlopen',
    '419.description' => 'Uw sessietoken is verlopen of ongeldig. Dit gebeurt vaak wanneer een pagina te lang open heeft gestaan. Vernieuw de pagina om door te gaan.',

    '422.title' => 'Validatiefout',
    '422.heading' => 'De ingediende gegevens konden niet worden verwerkt',
    '422.description' => 'De server begreep uw verzoek maar kon de ingesloten gegevens niet verwerken. Controleer uw invoer en corrigeer eventuele fouten.',

    '429.title' => 'Te veel verzoeken',
    '429.heading' => 'Gelieve te vertragen',
    '429.description' => 'U heeft te veel verzoeken verzonden in een bepaalde periode. Wacht even voordat u het opnieuw probeert.',

    '500.title' => 'Serverfout',
    '500.heading' => 'Er is iets misgegaan aan onze kant',
    '500.description' => 'Er is een onverwachte fout opgetreden bij het verwerken van uw verzoek. Ons team is op de hoogte gebracht. Probeer het over enkele ogenblikken opnieuw.',

    '502.title' => 'Ongeldige gateway',
    '502.heading' => 'Fout in bovenliggende dienst',
    '502.description' => 'De server heeft een ongeldig antwoord ontvangen van een bovenliggende server. Dit is meestal tijdelijk. Probeer het binnenkort opnieuw.',

    '503.title' => 'Dienst niet beschikbaar',
    '503.heading' => 'We zijn zo terug',
    '503.description' => 'De dienst is tijdelijk niet beschikbaar wegens onderhoud. We werken eraan om deze zo snel mogelijk te herstellen.',
    '503.maintenance' => 'Gepland onderhoud bezig',
    '503.estimated_return' => 'Verwachte terugkeer: {time}',

    '504.title' => 'Gateway-time-out',
    '504.heading' => 'De bovenliggende server heeft niet gereageerd',
    '504.description' => 'De server heeft geen tijdig antwoord ontvangen van een bovenliggende server. Probeer het over enkele ogenblikken opnieuw.',

    '4xx.title' => 'Clientfout',
    '4xx.heading' => 'Verzoekfout',
    '4xx.description' => 'Het verzoek kon niet worden voltooid. Controleer uw invoer en probeer het opnieuw.',
    '5xx.title' => 'Serverfout',
    '5xx.heading' => 'Er is iets misgegaan',
    '5xx.description' => 'Er is een fout opgetreden op de server. Probeer het later opnieuw.',
];
