<?php

declare(strict_types=1);

return [
    'go_back' => 'Retour',
    'go_home' => 'Retour à l\'accueil',
    'contact_support' => 'Contacter le support',
    'refresh_page' => 'Actualiser la page',
    'try_again' => 'Réessayer',
    'sign_in' => 'Se connecter',
    'search' => 'Rechercher',
    'search_placeholder' => 'Rechercher...',
    'popular_links' => 'Liens populaires',
    'retry_in' => 'Réessayer dans {seconds} secondes',

    '400.title' => 'Requête incorrecte',
    '400.heading' => 'Un problème est survenu avec votre requête',
    '400.description' => 'Le serveur n\'a pas pu comprendre la requête en raison d\'une syntaxe incorrecte ou de paramètres invalides. Veuillez vérifier votre saisie et réessayer.',

    '401.title' => 'Non autorisé',
    '401.heading' => 'Authentification requise',
    '401.description' => 'Vous devez vous connecter pour accéder à cette ressource. Si vous pensez qu\'il s\'agit d\'une erreur, veuillez contacter le support.',

    '403.title' => 'Interdit',
    '403.heading' => 'Accès refusé',
    '403.description' => 'Vous n\'avez pas la permission d\'accéder à cette ressource. Si vous pensez devoir y avoir accès, veuillez contacter votre administrateur.',

    '404.title' => 'Page introuvable',
    '404.heading' => 'Nous n\'avons pas trouvé cette page',
    '404.description' => 'La page que vous recherchez a peut-être été supprimée, renommée ou est temporairement indisponible.',

    '405.title' => 'Méthode non autorisée',
    '405.heading' => 'Cette action n\'est pas prise en charge',
    '405.description' => 'La méthode de requête utilisée n\'est pas prise en charge pour cette ressource. Veuillez essayer une autre approche ou revenir à la page précédente.',

    '408.title' => 'Délai d\'attente dépassé',
    '408.heading' => 'La requête a pris trop de temps',
    '408.description' => 'Le serveur a expiré en attendant la requête. Cela peut se produire avec des connexions lentes ou des téléchargements volumineux. Veuillez réessayer.',

    '413.title' => 'Contenu trop volumineux',
    '413.heading' => 'Le fichier ou les données sont trop volumineux',
    '413.description' => 'La requête était plus volumineuse que ce que le serveur est configuré pour accepter. Veuillez réduire la taille de votre téléchargement et réessayer.',

    '419.title' => 'Session expirée',
    '419.heading' => 'Votre session a expiré',
    '419.description' => 'Votre jeton de session a expiré ou est invalide. Cela se produit souvent lorsqu\'une page est restée ouverte trop longtemps. Veuillez actualiser la page pour continuer.',

    '422.title' => 'Erreur de validation',
    '422.heading' => 'Les données soumises n\'ont pas pu être traitées',
    '422.description' => 'Le serveur a compris votre requête mais n\'a pas pu traiter les données contenues. Veuillez vérifier votre saisie et corriger les erreurs.',

    '429.title' => 'Trop de requêtes',
    '429.heading' => 'Ralentissez, s\'il vous plaît',
    '429.description' => 'Vous avez envoyé trop de requêtes en un temps donné. Veuillez patienter avant de réessayer.',

    '500.title' => 'Erreur serveur',
    '500.heading' => 'Un problème est survenu de notre côté',
    '500.description' => 'Une erreur inattendue s\'est produite lors du traitement de votre requête. Notre équipe a été notifiée. Veuillez réessayer dans quelques instants.',

    '502.title' => 'Passerelle incorrecte',
    '502.heading' => 'Erreur de service en amont',
    '502.description' => 'Le serveur a reçu une réponse invalide d\'un serveur en amont. Cela est généralement temporaire. Veuillez réessayer sous peu.',

    '503.title' => 'Service indisponible',
    '503.heading' => 'Nous serons bientôt de retour',
    '503.description' => 'Le service est temporairement indisponible pour maintenance. Nous travaillons à le rétablir le plus rapidement possible.',
    '503.maintenance' => 'Maintenance programmée en cours',
    '503.estimated_return' => 'Retour estimé : {time}',

    '504.title' => 'Délai de passerelle dépassé',
    '504.heading' => 'Le serveur en amont n\'a pas répondu',
    '504.description' => 'Le serveur n\'a pas reçu de réponse en temps voulu d\'un serveur en amont. Veuillez réessayer dans quelques instants.',

    '4xx.title' => 'Erreur client',
    '4xx.heading' => 'Erreur de requête',
    '4xx.description' => 'La requête n\'a pas pu être complétée. Veuillez vérifier votre saisie et réessayer.',
    '5xx.title' => 'Erreur serveur',
    '5xx.heading' => 'Un problème est survenu',
    '5xx.description' => 'Une erreur est survenue sur le serveur. Veuillez réessayer plus tard.',
];
