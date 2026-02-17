<?php

declare(strict_types=1);

return [
    'go_back' => 'Volver',
    'go_home' => 'Volver al inicio',
    'contact_support' => 'Contactar con soporte',
    'refresh_page' => 'Actualizar la página',
    'try_again' => 'Intentar de nuevo',
    'sign_in' => 'Iniciar sesión',
    'search' => 'Buscar',
    'search_placeholder' => 'Buscar...',
    'popular_links' => 'Enlaces populares',
    'retry_in' => 'Reintentar en {seconds} segundos',

    '400.title' => 'Solicitud incorrecta',
    '400.heading' => 'Algo salió mal con su solicitud',
    '400.description' => 'El servidor no pudo entender la solicitud debido a una sintaxis incorrecta o parámetros no válidos. Por favor, compruebe su entrada e inténtelo de nuevo.',

    '401.title' => 'No autorizado',
    '401.heading' => 'Se requiere autenticación',
    '401.description' => 'Necesita iniciar sesión para acceder a este recurso. Si cree que esto es un error, por favor contacte con soporte.',

    '403.title' => 'Prohibido',
    '403.heading' => 'Acceso denegado',
    '403.description' => 'No tiene permiso para acceder a este recurso. Si cree que debería tener acceso, por favor contacte con su administrador.',

    '404.title' => 'Página no encontrada',
    '404.heading' => 'No pudimos encontrar esa página',
    '404.description' => 'La página que busca puede haber sido eliminada, renombrada o no está disponible temporalmente.',

    '405.title' => 'Método no permitido',
    '405.heading' => 'Esta acción no es compatible',
    '405.description' => 'El método de solicitud utilizado no es compatible con este recurso. Por favor, pruebe un enfoque diferente o vuelva a la página anterior.',

    '408.title' => 'Tiempo de espera agotado',
    '408.heading' => 'La solicitud tardó demasiado',
    '408.description' => 'El servidor agotó el tiempo de espera. Esto puede ocurrir con conexiones lentas o subidas grandes. Por favor, inténtelo de nuevo.',

    '413.title' => 'Contenido demasiado grande',
    '413.heading' => 'El archivo o los datos son demasiado grandes',
    '413.description' => 'La solicitud era mayor de lo que el servidor está configurado para aceptar. Por favor, reduzca el tamaño de su subida e inténtelo de nuevo.',

    '419.title' => 'Sesión expirada',
    '419.heading' => 'Su sesión ha expirado',
    '419.description' => 'Su token de sesión ha expirado o no es válido. Esto suele ocurrir cuando una página ha estado abierta demasiado tiempo. Por favor, actualice la página para continuar.',

    '422.title' => 'Error de validación',
    '422.heading' => 'Los datos enviados no pudieron ser procesados',
    '422.description' => 'El servidor entendió su solicitud pero no pudo procesar los datos contenidos. Por favor, revise su entrada y corrija los errores.',

    '429.title' => 'Demasiadas solicitudes',
    '429.heading' => 'Por favor, vaya más despacio',
    '429.description' => 'Ha enviado demasiadas solicitudes en un período de tiempo determinado. Por favor, espere antes de intentarlo de nuevo.',

    '500.title' => 'Error del servidor',
    '500.heading' => 'Algo salió mal de nuestro lado',
    '500.description' => 'Se produjo un error inesperado al procesar su solicitud. Nuestro equipo ha sido notificado. Por favor, inténtelo de nuevo en unos momentos.',

    '502.title' => 'Puerta de enlace incorrecta',
    '502.heading' => 'Error del servicio en sentido ascendente',
    '502.description' => 'El servidor recibió una respuesta no válida de un servidor en sentido ascendente. Esto suele ser temporal. Por favor, inténtelo de nuevo en breve.',

    '503.title' => 'Servicio no disponible',
    '503.heading' => 'Volveremos pronto',
    '503.description' => 'El servicio no está disponible temporalmente por mantenimiento. Estamos trabajando para restaurarlo lo antes posible.',
    '503.maintenance' => 'Mantenimiento programado en curso',
    '503.estimated_return' => 'Retorno estimado: {time}',

    '504.title' => 'Tiempo de espera de la puerta de enlace',
    '504.heading' => 'El servidor en sentido ascendente no respondió',
    '504.description' => 'El servidor no recibió una respuesta oportuna de un servidor en sentido ascendente. Por favor, inténtelo de nuevo en unos momentos.',

    '4xx.title' => 'Error del cliente',
    '4xx.heading' => 'Error de solicitud',
    '4xx.description' => 'La solicitud no pudo completarse. Por favor, compruebe su entrada e inténtelo de nuevo.',
    '5xx.title' => 'Error del servidor',
    '5xx.heading' => 'Algo salió mal',
    '5xx.description' => 'Se produjo un error en el servidor. Por favor, inténtelo más tarde.',
];
