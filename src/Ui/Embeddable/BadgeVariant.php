<?php

declare(strict_types=1);

namespace Pulsar\Ui\Embeddable;

use Pulsar\Api\Api;

/**
 * Visual variants for the StatusBadgeComponent.
 * @api
 */
#[Api(since: '1.0.0')]
enum BadgeVariant: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case Info = 'info';
    case Neutral = 'neutral';
}
