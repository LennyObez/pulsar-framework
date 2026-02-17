<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Domain;

use Pulsar\Api\Api;

/**
 * Types of conversations supported by the messaging system.
 */
#[Api(since: '1.0.0')]
enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
    case Channel = 'channel';
}
