<?php

declare(strict_types=1);

namespace Pulsar\Event;

use Pulsar\Api\Api;

/**
 * Scope of an event dispatch: whether listeners are within the same module or across modules.
 * @api
 */
#[Api(since: '1.0.0')]
enum EventScope: string
{
    case Internal = 'internal';
    case CrossModule = 'cross_module';
}
