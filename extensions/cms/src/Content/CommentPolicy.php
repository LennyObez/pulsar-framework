<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Comment policy for a content item.
 */
#[Api(since: '1.0.0')]
enum CommentPolicy: string
{
    case Open = 'open';
    case Moderated = 'moderated';
    case Closed = 'closed';
    case Inherit = 'inherit';
}
