<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Domain;

use Pulsar\Api\Api;

/**
 * Actions that can be taken when linking a social identity to a local account.
 */
#[Api(since: '1.0.0')]
enum LinkAction: string
{
    case Linked = 'linked';
    case Created = 'created';
    case Unlinked = 'unlinked';
    case Rejected = 'rejected';
}
