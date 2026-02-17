<?php

declare(strict_types=1);

return [
    'go_back' => 'Zurück',
    'go_home' => 'Zurück zur Startseite',
    'contact_support' => 'Support kontaktieren',
    'refresh_page' => 'Seite aktualisieren',
    'try_again' => 'Erneut versuchen',
    'sign_in' => 'Anmelden',
    'search' => 'Suchen',
    'search_placeholder' => 'Suchen...',
    'popular_links' => 'Beliebte Links',
    'retry_in' => 'In {seconds} Sekunden erneut versuchen',

    '400.title' => 'Ungültige Anfrage',
    '400.heading' => 'Mit Ihrer Anfrage ist ein Fehler aufgetreten',
    '400.description' => 'Der Server konnte die Anfrage aufgrund fehlerhafter Syntax oder ungültiger Parameter nicht verstehen. Bitte überprüfen Sie Ihre Eingabe und versuchen Sie es erneut.',

    '401.title' => 'Nicht autorisiert',
    '401.heading' => 'Authentifizierung erforderlich',
    '401.description' => 'Sie müssen sich anmelden, um auf diese Ressource zuzugreifen. Wenn Sie glauben, dass dies ein Fehler ist, wenden Sie sich bitte an den Support.',

    '403.title' => 'Verboten',
    '403.heading' => 'Zugriff verweigert',
    '403.description' => 'Sie haben keine Berechtigung, auf diese Ressource zuzugreifen. Wenn Sie glauben, dass Sie Zugriff haben sollten, wenden Sie sich bitte an Ihren Administrator.',

    '404.title' => 'Seite nicht gefunden',
    '404.heading' => 'Wir konnten diese Seite nicht finden',
    '404.description' => 'Die gesuchte Seite wurde möglicherweise entfernt, umbenannt oder ist vorübergehend nicht verfügbar.',

    '405.title' => 'Methode nicht erlaubt',
    '405.heading' => 'Diese Aktion wird nicht unterstützt',
    '405.description' => 'Die verwendete Anfragemethode wird für diese Ressource nicht unterstützt. Bitte versuchen Sie einen anderen Ansatz oder gehen Sie zur vorherigen Seite zurück.',

    '408.title' => 'Zeitüberschreitung der Anfrage',
    '408.heading' => 'Die Anfrage hat zu lange gedauert',
    '408.description' => 'Der Server hat beim Warten auf die Anfrage eine Zeitüberschreitung erreicht. Dies kann bei langsamen Verbindungen oder großen Uploads passieren. Bitte versuchen Sie es erneut.',

    '413.title' => 'Nutzlast zu groß',
    '413.heading' => 'Die Datei oder die Daten sind zu groß',
    '413.description' => 'Die Anfrage war größer als der Server konfiguriert ist zu akzeptieren. Bitte reduzieren Sie die Größe Ihres Uploads und versuchen Sie es erneut.',

    '419.title' => 'Sitzung abgelaufen',
    '419.heading' => 'Ihre Sitzung ist abgelaufen',
    '419.description' => 'Ihr Sitzungstoken ist abgelaufen oder ungültig. Dies passiert häufig, wenn eine Seite zu lange geöffnet war. Bitte aktualisieren Sie die Seite, um fortzufahren.',

    '422.title' => 'Validierungsfehler',
    '422.heading' => 'Die übermittelten Daten konnten nicht verarbeitet werden',
    '422.description' => 'Der Server hat Ihre Anfrage verstanden, konnte die enthaltenen Daten aber nicht verarbeiten. Bitte überprüfen Sie Ihre Eingabe und korrigieren Sie eventuelle Fehler.',

    '429.title' => 'Zu viele Anfragen',
    '429.heading' => 'Bitte verlangsamen',
    '429.description' => 'Sie haben in einem bestimmten Zeitraum zu viele Anfragen gesendet. Bitte warten Sie, bevor Sie es erneut versuchen.',

    '500.title' => 'Serverfehler',
    '500.heading' => 'Auf unserer Seite ist ein Fehler aufgetreten',
    '500.description' => 'Bei der Verarbeitung Ihrer Anfrage ist ein unerwarteter Fehler aufgetreten. Unser Team wurde benachrichtigt. Bitte versuchen Sie es in einigen Augenblicken erneut.',

    '502.title' => 'Ungültiges Gateway',
    '502.heading' => 'Fehler beim vorgelagerten Dienst',
    '502.description' => 'Der Server hat eine ungültige Antwort von einem vorgelagerten Server erhalten. Dies ist in der Regel vorübergehend. Bitte versuchen Sie es in Kürze erneut.',

    '503.title' => 'Dienst nicht verfügbar',
    '503.heading' => 'Wir sind bald zurück',
    '503.description' => 'Der Dienst ist vorübergehend für Wartungsarbeiten nicht verfügbar. Wir arbeiten daran, ihn so schnell wie möglich wiederherzustellen.',
    '503.maintenance' => 'Geplante Wartung im Gange',
    '503.estimated_return' => 'Voraussichtliche Rückkehr: {time}',

    '504.title' => 'Gateway-Zeitüberschreitung',
    '504.heading' => 'Der vorgelagerte Server hat nicht geantwortet',
    '504.description' => 'Der Server hat keine rechtzeitige Antwort von einem vorgelagerten Server erhalten. Bitte versuchen Sie es in einigen Augenblicken erneut.',

    '4xx.title' => 'Clientfehler',
    '4xx.heading' => 'Anfragefehler',
    '4xx.description' => 'Die Anfrage konnte nicht abgeschlossen werden. Bitte überprüfen Sie Ihre Eingabe und versuchen Sie es erneut.',
    '5xx.title' => 'Serverfehler',
    '5xx.heading' => 'Ein Fehler ist aufgetreten',
    '5xx.description' => 'Auf dem Server ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.',
];
