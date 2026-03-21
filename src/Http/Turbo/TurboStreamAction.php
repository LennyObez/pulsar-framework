<?php

declare(strict_types=1);

namespace Pulsar\Http\Turbo;

use Pulsar\Api\Api;

/**
 * Turbo Stream actions for real-time HTML updates.
 *
 * Each action describes how to modify the DOM when a stream
 * message is received by the client.
 * @api
 */
#[Api(since: '1.0.0')]
enum TurboStreamAction: string
{
    case Append = 'append';
    case Prepend = 'prepend';
    case Replace = 'replace';
    case Update = 'update';
    case Remove = 'remove';
    case Before = 'before';
    case After = 'after';
}
