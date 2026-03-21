<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Domain;

use Pulsar\Api\Api;

/**
 * Types of messages that can be sent in a conversation.
 * @api
 */
#[Api(since: '1.0.0')]
enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case File = 'file';
    case System = 'system';
}
