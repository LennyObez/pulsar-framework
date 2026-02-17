<?php

declare(strict_types=1);

namespace Pulsar\Security\Escaper;

use Pulsar\Api\Api;

/**
 * Output contexts for context-aware escaping.
 */
#[Api(since: '1.0.0')]
enum EscapeContext: string
{
    /** Between HTML tags: <p>{value}</p> */
    case Html = 'html';

    /** Inside an HTML attribute: <div title="{value}"> */
    case Attribute = 'attr';

    /** Inside a JavaScript string: var x = "{value}"; */
    case JavaScript = 'js';

    /** Inside a CSS value: style="color: {value}" */
    case Css = 'css';

    /** URL component (path/query): href="/page/{value}" */
    case Url = 'url';

    /** Full URL with scheme validation: href="{value}" */
    case UrlFull = 'url_full';
}
