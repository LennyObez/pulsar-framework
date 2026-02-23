<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Auth;

use Pulsar\Api\Internal;

/**
 * All CMS-specific permissions used for authorization checks.
 *
 * @psalm-api Cases are referenced by string value through the gate
 *            policies, never by `CmsPermission::Foo` from framework code.
 */
#[Internal(reason: 'CMS authorization; implementation detail')]
enum CmsPermission: string
{
    case ContentView = 'content.view';
    case ContentCreate = 'content.create';
    case ContentUpdate = 'content.update';
    case ContentDelete = 'content.delete';
    case ContentPublish = 'content.publish';
    case ContentManage = 'content.manage';

    case MediaView = 'media.view';
    case MediaUpload = 'media.upload';
    case MediaDelete = 'media.delete';
    case MediaManage = 'media.manage';

    case OrderView = 'order.view';
    case OrderManage = 'order.manage';

    case ProductManage = 'product.manage';

    case SettingsManage = 'settings.manage';

    case CollaborationJoin = 'collaboration.join';
    case CollaborationManage = 'collaboration.manage';

    case AiUse = 'ai.use';

    case ToolsExport = 'tools.export';
    case ToolsGdprErase = 'tools.gdpr.erase';
}
