<?php

declare(strict_types=1);

return [
    // Navigation
    'nav.dashboard' => 'Panel de control',
    'nav.content' => 'Contenido',
    'nav.media' => 'Medios',
    'nav.comments' => 'Comentarios',
    'nav.forms' => 'Formularios',
    'nav.users' => 'Usuarios',
    'nav.menus' => 'Menus',
    'nav.settings' => 'Ajustes',
    'nav.plugins' => 'Complementos',
    'nav.themes' => 'Temas',
    'nav.tools' => 'Herramientas',
    'nav.seo' => 'SEO',
    'nav.products' => 'Productos',
    'nav.orders' => 'Pedidos',
    'nav.promotions' => 'Promociones',
    'nav.newsletter' => 'Boletin',

    // Dashboard
    'dashboard.title' => 'Panel de control',
    'dashboard.welcome' => 'Bienvenido a su CMS',
    'dashboard.quick_actions' => 'Acciones rapidas',
    'dashboard.recent_content' => 'Contenido reciente',
    'dashboard.recent_comments' => 'Comentarios recientes',

    // Content
    'content.title' => 'Contenido',
    'content.new' => 'Nuevo contenido',
    'content.edit' => 'Editar contenido',
    'content.publish' => 'Publicar',
    'content.unpublish' => 'Retirar',
    'content.draft' => 'Borrador',
    'content.published' => 'Publicado',
    'content.archived' => 'Archivado',
    'content.scheduled' => 'Programado',
    'content.slug' => 'Slug',
    'content.author' => 'Autor',
    'content.category' => 'Categoria',
    'content.tags' => 'Etiquetas',
    'content.featured_image' => 'Imagen destacada',
    'content.excerpt' => 'Extracto',
    'content.body' => 'Cuerpo',
    'content.meta_title' => 'Meta titulo',
    'content.meta_description' => 'Meta descripcion',
    'content.revisions' => 'Revisiones',
    'content.preview' => 'Vista previa',
    'content.no_content' => 'Todavia no hay elementos de contenido.',

    // Media
    'media.title' => 'Biblioteca de medios',
    'media.upload' => 'Subir archivos',
    'media.no_files' => 'Todavia no se han subido archivos.',
    'media.file_name' => 'Nombre del archivo',
    'media.file_size' => 'Tamano del archivo',
    'media.file_type' => 'Tipo de archivo',
    'media.dimensions' => 'Dimensiones',
    'media.uploaded_at' => 'Subido',
    'media.alt_text' => 'Texto alternativo',
    'media.caption' => 'Pie de foto',
    'media.delete_confirm' => 'Esta seguro de que desea eliminar este archivo?',

    // Comments
    'comments.title' => 'Comentarios',
    'comments.pending' => 'Pendientes',
    'comments.approved' => 'Aprobados',
    'comments.spam' => 'Spam',
    'comments.approve' => 'Aprobar',
    'comments.reject' => 'Rechazar',
    'comments.mark_spam' => 'Marcar como spam',
    'comments.reply' => 'Responder',
    'comments.no_comments' => 'Todavia no hay comentarios.',
    'comments.queue' => 'Cola de moderacion',

    // Users
    'users.title' => 'Usuarios',
    'users.new' => 'Nuevo usuario',
    'users.edit' => 'Editar usuario',
    'users.role' => 'Rol',
    'users.last_login' => 'Ultimo acceso',
    'users.no_users' => 'No se encontraron usuarios.',

    // Settings
    'settings.title' => 'Ajustes',
    'settings.general' => 'General',
    'settings.site_name' => 'Nombre del sitio',
    'settings.site_url' => 'URL del sitio',
    'settings.site_description' => 'Descripcion del sitio',
    'settings.timezone' => 'Zona horaria',
    'settings.date_format' => 'Formato de fecha',
    'settings.saved' => 'Ajustes guardados correctamente.',

    // Plugins
    'plugins.title' => 'Complementos',
    'plugins.installed' => 'Instalados',
    'plugins.available' => 'Disponibles',
    'plugins.install' => 'Instalar',
    'plugins.activate' => 'Activar',
    'plugins.deactivate' => 'Desactivar',
    'plugins.settings' => 'Ajustes del complemento',

    // Themes
    'themes.title' => 'Temas',
    'themes.active' => 'Tema activo',
    'themes.activate' => 'Activar',
    'themes.preview' => 'Vista previa',
    'themes.customize' => 'Personalizar',

    // Tools
    'tools.title' => 'Herramientas',
    'tools.backups' => 'Copias de seguridad',
    'tools.import' => 'Importar',
    'tools.export' => 'Exportar',
    'tools.create_backup' => 'Crear copia de seguridad',
    'tools.restore_backup' => 'Restaurar copia de seguridad',

    // SEO
    'seo.title' => 'SEO',
    'seo.robots' => 'Robots',
    'seo.sitemap' => 'Mapa del sitio',
    'seo.redirects' => 'Redirecciones',
    'seo.link_health' => 'Estado de los enlaces',

    // Commerce
    'products.title' => 'Productos',
    'products.new' => 'Nuevo producto',
    'products.price' => 'Precio',
    'products.stock' => 'Existencias',
    'products.sku' => 'SKU',

    'orders.title' => 'Pedidos',
    'orders.order_number' => 'Numero de pedido',
    'orders.customer' => 'Cliente',
    'orders.total' => 'Total',
    'orders.status' => 'Estado',
    'orders.pending' => 'Pendiente',
    'orders.processing' => 'En proceso',
    'orders.shipped' => 'Enviado',
    'orders.delivered' => 'Entregado',
    'orders.cancelled' => 'Cancelado',
    'orders.refunded' => 'Reembolsado',

    'promotions.title' => 'Promociones',
    'promotions.new' => 'Nueva promocion',
    'promotions.code' => 'Codigo de promocion',
    'promotions.discount' => 'Descuento',
    'promotions.valid_from' => 'Valido desde',
    'promotions.valid_until' => 'Valido hasta',

    // Newsletter
    'newsletter.title' => 'Boletin',
    'newsletter.campaigns' => 'Campanas',
    'newsletter.subscribers' => 'Suscriptores',
    'newsletter.analytics' => 'Estadisticas',
    'newsletter.new_campaign' => 'Nueva campana',

    // Two-factor authentication
    '2fa.title' => 'Autenticacion en dos pasos',
    '2fa.enable' => 'Activar',
    '2fa.disable' => 'Desactivar',
    '2fa.scan_qr' => 'Escanee este codigo QR con su aplicacion de autenticacion',
    '2fa.enter_code' => 'Introduzca el codigo de verificacion',
    '2fa.recovery_codes' => 'Codigos de recuperacion',
    '2fa.recovery_warning' => 'Guarde estos codigos en un lugar seguro. Cada codigo solo puede utilizarse una vez.',

    // Safe mode
    'safe_mode.title' => 'Modo seguro',
    'safe_mode.message' => 'El CMS se encuentra en modo seguro. Algunas funciones pueden estar desactivadas.',

    // Pagination
    'pagination.showing' => 'Mostrando {from} a {to} de {total}',
    'pagination.previous' => 'Anterior',
    'pagination.next' => 'Siguiente',

    // General
    'confirm_destructive' => 'Esta accion no se puede deshacer. Esta seguro?',
    'skip_to_content' => 'Ir al contenido principal',
];
