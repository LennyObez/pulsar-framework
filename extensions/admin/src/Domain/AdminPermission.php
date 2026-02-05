<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Admin-level permissions.
 */
#[Api(since: '1.0.0')]
enum AdminPermission: string
{
    case AccessPanel = 'admin.access';
    case ViewDashboard = 'admin.dashboard';
    case ManageResources = 'admin.resources.manage';
    case ExportData = 'admin.export';
    case ViewAuditLog = 'admin.audit.view';
    case ManageSettings = 'admin.settings';
    case SchemaView = 'admin.schema.view';
    case SchemaCreate = 'admin.schema.create';
    case SchemaAlter = 'admin.schema.alter';
    case SchemaDrop = 'admin.schema.drop';
    case SchemaRename = 'admin.schema.rename';
}
