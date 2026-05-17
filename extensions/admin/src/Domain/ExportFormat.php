<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use Pulsar\Api\Api;

/**
 * Supported export formats.
 * @api
 */
#[Api(since: '1.0.0')]
enum ExportFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
}
