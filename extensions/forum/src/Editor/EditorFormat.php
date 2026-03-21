<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Editor;

use Pulsar\Api\Api;

/**
 * Supported editor input/output formats.
 * @api
 */
#[Api(since: '1.0.0')]
enum EditorFormat: string
{
    case Markdown = 'markdown';
    case Html = 'html';
    case PlainText = 'plaintext';
}
